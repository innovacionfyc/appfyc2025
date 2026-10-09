<?php

namespace App\Support\CredentialFlow\Identidad;

use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\PresentadorConciliaciones;
use App\Support\CredentialFlow\Portal\Hmac;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Support\Facades\DB;

/**
 * Evidencia de un caso `identidad_ambigua` para decidir (Fase 10B-3A). SOLO LECTURA. Agrupa los certificados del caso por el `grupo_hash` técnico
 * EXISTENTE (`NombreConservador::grupoId`; no se recalcula nada) y resume, por grupo, lo que sirve de CONTEXTO: certificados, estados, eventos, años,
 * descargas, códigos y encuestas. Nada de esto es identidad por sí solo (ni el parecido de nombres, ni una descarga, ni un código, ni un correo compartido).
 *
 * No devuelve nombres, documentos ni correos en claro: nombre enmascarado, correo como HMAC + máscara. Los tokens históricos NO existen en las tablas
 * `cf_*` (no se migraron: solo viven en el staging), por eso no forman parte de la evidencia de la aplicación.
 */
final class EvidenciaIdentidad
{
    /** A partir de este número de certificados afectados una decisión exige confirmación de alcance masivo. */
    public const UMBRAL_MASIVO = 100;

    private const SEVERIDAD = ['espaciado', 'orden', 'contenido', 'una_letra', 'parecido', 'distinto'];

    public static function hashDocumento(string $documentoClave): string
    {
        return Hmac::de('conciliacion_documento', $documentoClave);
    }

    public static function hashCorreo(string $correoNormalizado): string
    {
        return Hmac::de('identidad_correo', $correoNormalizado);
    }

    /**
     * @return array<string,mixed>
     */
    public static function de(Conciliacion $caso): array
    {
        $ids = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->orderBy('certificado_legado_id')->pluck('certificado_legado_id')->map(fn ($i) => (int) $i)->all();
        $certs = DB::table('cf_certificados_legado')->whereIn('id', $ids)->orderBy('id')->get(['id', 'documento_clave', 'nombre_completo', 'evento_id', 'conciliacion_estado', 'codigo_legado']);
        $clave = (string) ($certs->first()->documento_clave ?? '');
        $eventos = DB::table('cf_eventos')->whereIn('id', $certs->pluck('evento_id')->unique())->pluck('anio', 'id');
        $descargas = DB::table('cf_descargas')->whereIn('certificado_legado_id', $ids)->selectRaw('certificado_legado_id, count(*) n')->groupBy('certificado_legado_id')->pluck('n', 'certificado_legado_id');
        $encuestas = DB::table('cf_encuestas_respuestas')->whereIn('certificado_legado_id', $ids)->selectRaw('certificado_legado_id, count(*) n')->groupBy('certificado_legado_id')->pluck('n', 'certificado_legado_id');
        $correos = DB::table('cf_correos')->whereIn('certificado_legado_id', $ids)->where('estado', 'valido')->get(['certificado_legado_id', 'correo_normalizado'])->groupBy('certificado_legado_id');

        $grupos = [];
        foreach ($certs as $c) {
            $grupos[NombreConservador::grupoId((string) $c->nombre_completo)][] = $c;
        }
        // correo (normalizado) → grupos que lo tienen. Solo en memoria: no sale de este método.
        $gruposDeCorreo = [];
        foreach ($grupos as $h => $fs) {
            foreach ($fs as $c) {
                foreach ($correos[$c->id] ?? [] as $x) {
                    $gruposDeCorreo[$x->correo_normalizado][$h] = true;
                }
            }
        }

        $salida = [];
        $n = 0;
        foreach ($grupos as $h => $fs) {
            $n++;
            $mails = collect($fs)->flatMap(fn ($c) => ($correos[$c->id] ?? collect())->pluck('correo_normalizado'))->unique()->values();
            $anios = collect($fs)->map(fn ($c) => $eventos[$c->evento_id] ?? null)->filter()->unique()->sort()->values();
            $salida[$h] = [
                'etiqueta' => 'Grupo '.$n, 'grupo_hash' => $h, 'ref' => substr($h, 0, 10),
                'nombre' => PresentadorConciliaciones::nombreEnmascarado($fs[0]->nombre_completo),
                'certificados' => count($fs), 'estados' => collect($fs)->countBy('conciliacion_estado')->all(),
                'eventos' => collect($fs)->pluck('evento_id')->unique()->count(), 'anios' => ['desde' => $anios->first(), 'hasta' => $anios->last()],
                'descargas' => (int) collect($fs)->sum(fn ($c) => $descargas[$c->id] ?? 0), 'codigos' => collect($fs)->whereNotNull('codigo_legado')->count(),
                'encuestas' => (int) collect($fs)->sum(fn ($c) => $encuestas[$c->id] ?? 0),
                'correos' => $mails->map(fn ($m) => ['hmac' => self::hashCorreo($m), 'mascara' => PresentadorConciliaciones::correoEnmascarado($m), 'compartido' => count($gruposDeCorreo[$m] ?? []) > 1])->all(),
                'via_individual' => $mails->contains(fn ($m) => count($gruposDeCorreo[$m] ?? []) === 1),
                'sin_correo' => $mails->isEmpty(),
                '_eventos' => collect($fs)->pluck('evento_id')->unique()->all(), '_nombre' => NombreConservador::normalizar((string) $fs[0]->nombre_completo),
            ];
        }

        // Riesgos por PAR de grupos (solo descriptivo; no autoriza nada).
        $pares = [];
        $hs = array_keys($salida);
        for ($i = 0; $i < count($hs); $i++) {
            for ($j = $i + 1; $j < count($hs); $j++) {
                $a = $salida[$hs[$i]];
                $b = $salida[$hs[$j]];
                $pares[] = ['a' => $hs[$i], 'b' => $hs[$j], 'mismo_evento' => array_intersect($a['_eventos'], $b['_eventos']) !== [], 'nombres' => self::clase($a['_nombre'], $b['_nombre'])];
            }
        }
        foreach ($salida as &$g) {
            unset($g['_eventos'], $g['_nombre']);
        }
        unset($g);

        return [
            'documento_hash' => $clave === '' ? null : self::hashDocumento($clave),
            'documento_clave_vacia' => $clave === '', 'invalido' => (string) $caso->motivo_origen === 'DOCUMENTO_INVALIDO',
            'certificados' => $certs->count(), 'grupos' => array_values($salida), 'por_hash' => $salida, 'pares' => $pares,
            'riesgos' => [
                'masivo' => $certs->count() >= self::UMBRAL_MASIVO, 'mismo_evento' => (bool) array_filter($pares, fn ($p) => $p['mismo_evento']),
                'nombres_distintos' => (bool) array_filter($pares, fn ($p) => $p['nombres'] === 'distinto'),
            ],
            'correos_compartidos' => count(array_filter($gruposDeCorreo, fn ($g) => count($g) > 1)),
            'sin_via_individual' => count(array_filter($salida, fn ($g) => ! $g['via_individual'])),
        ];
    }

    /** ¿Algún par entre `$hashes` comparte evento o tiene nombres realmente distintos? (para exigir evidencia externa). @return array{mismo_evento:bool,nombres_distintos:bool} */
    public static function riesgoEntre(array $evidencia, array $hashes): array
    {
        $r = ['mismo_evento' => false, 'nombres_distintos' => false];
        foreach ($evidencia['pares'] as $p) {
            if (in_array($p['a'], $hashes, true) && in_array($p['b'], $hashes, true)) {
                $r['mismo_evento'] = $r['mismo_evento'] || $p['mismo_evento'];
                $r['nombres_distintos'] = $r['nombres_distintos'] || $p['nombres'] === 'distinto';
            }
        }

        return $r;
    }

    /** Clase descriptiva de la diferencia entre dos nombres normalizados. NO decide ni autoriza nada. */
    public static function clase(string $a, string $b): string
    {
        if (str_replace(' ', '', $a) === str_replace(' ', '', $b)) {
            return 'espaciado';
        }
        $ta = explode(' ', $a);
        $tb = explode(' ', $b);
        $sa = $ta;
        $sb = $tb;
        sort($sa);
        sort($sb);
        if ($sa === $sb) {
            return 'orden';
        }
        if (array_diff($ta, $tb) === [] || array_diff($tb, $ta) === []) {
            return 'contenido';
        }
        $l = strlen($a) <= 250 && strlen($b) <= 250 ? levenshtein($a, $b) : 99;
        if ($l <= 1) {
            return 'una_letra';
        }
        similar_text($a, $b, $pct);

        return $l <= 3 || $pct >= 85 ? 'parecido' : 'distinto';
    }
}
