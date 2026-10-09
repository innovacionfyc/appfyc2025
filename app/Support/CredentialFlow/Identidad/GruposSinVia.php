<?php

namespace App\Support\CredentialFlow\Identidad;

use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Support\Facades\DB;

/**
 * Análisis y READ-MODEL (SOLO LECTURA) de los grupos de documentos multi-grupo accesibles que HOY no tienen ninguna vía propia al portal (Fase 10B-3C-1). Usa el
 * gate REAL (`AccesoPortal::alcance`) y no modifica nada. Es la única fuente de la clasificación: el detector de casos, la validación de las decisiones y la
 * pantalla la reutilizan.
 *
 * Un grupo es accesible si algún correo del documento produce un alcance que lo incluye. Causas de un grupo SIN vía (cada una con su resolución, no se mezclan):
 *  - `correo_compartido`   solo tiene correos que también están en otro grupo del documento. Recuperable con UNA decisión administrativa: `correo_autorizado`
 *                          del correo compartido a ESE grupo (scope de un grupo; no es «misma persona» ni toca al hermano). Dependencia: administrativa.
 *  - `evidencia_externa`   igual que la anterior, pero el nombre frente a quien comparte el correo es realmente distinto (o comparten evento): no se resuelve sin
 *                          evidencia externa (10B-3C-4). Dependencia: externa.
 *  - `sin_correo`          ningún correo válido propio. No se copia el correo del hermano. Dependencia: externa (10B-3C-4).
 *  - `sin_fila_habilitante` tiene correo propio pero ninguna fila conciliada (p. ej. plantilla inválida): se recupera solo al resolver su plantilla (10B-1).
 *                          Dependencia: técnica; NO recibe caso de identidad.
 */
final class GruposSinVia
{
    public const MOTIVO_COMPARTIDO = 'GRUPO_SIN_VIA_CORREO_COMPARTIDO';

    public const MOTIVO_EVIDENCIA = 'GRUPO_SIN_VIA_EVIDENCIA_EXTERNA';

    public const MOTIVO_SIN_CORREO = 'GRUPO_SIN_VIA_SIN_CORREO';

    /** causa → motivo del caso de identidad que la gestiona (la técnica no tiene caso de identidad). */
    public const MOTIVO_POR_CAUSA = ['correo_compartido' => self::MOTIVO_COMPARTIDO, 'evidencia_externa' => self::MOTIVO_EVIDENCIA, 'sin_correo' => self::MOTIVO_SIN_CORREO];

    public static function esMotivo(?string $motivo): bool
    {
        return $motivo !== null && str_starts_with($motivo, 'GRUPO_SIN_VIA_');
    }

    /**
     * Clasifica TODOS los grupos de un documento. Solo lectura.
     *
     * @return array<string,array{certs:int,ids:list<int>,correos:list<string>,accesible:bool,causa:?string,comparte_con:list<string>}>
     */
    public function analizarDocumento(string $clave, bool $conDecisiones = false): array
    {
        $acceso = new AccesoPortal;
        $d = $acceso->cargar($clave);
        $grupos = [];
        foreach ($d['filas'] as $f) {
            $g = $d['grupos'][$f->id];
            $grupos[$g]['filas'][] = $f;
            foreach (array_keys($d['correos'][$f->id] ?? []) as $c) {
                $grupos[$g]['correos'][$c] = true;
            }
        }
        $gruposDeCorreo = [];
        foreach ($grupos as $g => $x) {
            foreach (array_keys($x['correos'] ?? []) as $c) {
                $gruposDeCorreo[$c][$g] = true;
            }
        }
        $accesibles = [];
        foreach (array_keys($gruposDeCorreo) as $c) {
            $al = $acceso->alcance($clave, $c);
            if ($al !== null) {
                foreach ($al['grupo'] === null ? array_keys($grupos) : [$al['grupo']] as $g) {
                    $accesibles[$g] = true;
                }
            }
        }

        // 10B-3C-4: un grupo CUBIERTO por un scope aprobado y aplicable (decisión de identidad vigente) también tiene vía; el que queda fuera pasa a ser un residual.
        // Solo a pedido (`$conDecisiones`): el análisis base de 3C-1 (y el cierre de sus casos) sigue siendo el del gate histórico, sin cambios.
        $autorizados = [];
        if ($conDecisiones) {
            foreach ($this->gruposCubiertosPorDecisiones($clave, array_keys($gruposDeCorreo)) as $g) {
                $accesibles[$g] = true;
            }
            $autorizados = DB::table('cf_decisiones_identidad_correos')->where('documento_hash', EvidenciaIdentidad::hashDocumento($clave))->where('vigente', 1)->pluck('grupo_hash', 'correo_hmac')->all();
        }

        $salida = [];
        foreach ($grupos as $g => $x) {
            $correos = array_keys($x['correos'] ?? []);
            $comparte = [];
            foreach ($correos as $c) {
                foreach (array_keys($gruposDeCorreo[$c]) as $o) {
                    if ($o !== $g) {
                        $comparte[$o] = true;
                    }
                }
            }
            $causa = null;
            $motivo = null;
            $subcausa = null;
            if (! isset($accesibles[$g])) {
                $habilitante = array_filter($x['filas'], fn ($f) => AccesoPortal::habilitaAcceso($f)) !== [];
                $conExclusivo = array_filter($correos, fn ($c) => count($gruposDeCorreo[$c]) === 1) !== [];
                // `motivo`: clasificación técnica ORIGINAL (30 compartido · 6 sin correo · 1 sin fila habilitante), estable para el read-model.
                $motivo = $correos === [] ? 'sin_correo' : ($conExclusivo ? 'sin_fila_habilitante' : 'correo_compartido');
                if ($correos === []) {
                    $causa = 'sin_correo';
                } elseif ($conExclusivo || ! $habilitante) {
                    // Depende de resolver su plantilla / conciliación (flujo 10B-1): sin caso de identidad. Cuando su fila sea habilitante volverá a aparecer como objetivo.
                    $causa = 'sin_fila_habilitante';
                } else {
                    // Si TODOS sus correos ya están autorizados a OTRO grupo, ese correo tiene dueño: este grupo es un RESIDUAL (p. ej. la fila suelta del documento masivo).
                    $yaAutorizado = $correos !== [] && array_filter($correos, fn ($c) => ($autorizados[EvidenciaIdentidad::hashCorreo($c)] ?? $g) === $g) === [];
                    if ($yaAutorizado) {
                        $causa = 'evidencia_externa';
                        $subcausa = 'correo_autorizado_a_otro_grupo';
                    } elseif ($this->riesgoFrenteA($g, array_keys($comparte), $grupos)) {
                        $causa = 'evidencia_externa';
                        $subcausa = 'nombre_distinto_o_mismo_evento';
                    } else {
                        $causa = 'correo_compartido';
                    }
                }
            }
            $salida[$g] = ['certs' => count($x['filas']), 'ids' => array_map(fn ($f) => (int) $f->id, $x['filas']), 'correos' => $correos, 'accesible' => isset($accesibles[$g]), 'causa' => $causa, 'subcausa' => $subcausa, 'motivo' => $motivo, 'comparte_con' => array_keys($comparte)];
        }

        // DISPUTA de correo: una `correo_autorizado` asigna un correo a UN solo grupo. Si todos los correos de un grupo objetivo también son de otro grupo objetivo, no se
        // pueden recuperar ambos con autorizaciones (habría que decidir quién es el titular o si son la misma persona): pasan a evidencia externa.
        $objetivos = array_keys(array_filter($salida, fn ($x) => $x['causa'] === 'correo_compartido'));
        foreach ($objetivos as $g) {
            $disputado = array_filter($salida[$g]['correos'], fn ($c) => count(array_filter($objetivos, fn ($o) => $o !== $g && in_array($c, $salida[$o]['correos'], true))) === 0) === [];
            if ($disputado) {
                $disputados[] = $g;
            }
        }
        foreach ($disputados ?? [] as $g) {
            $salida[$g]['causa'] = 'evidencia_externa';
            $salida[$g]['subcausa'] = 'disputa_mismo_unico_correo';
        }

        return $salida;
    }

    /**
     * Grupos que un scope aprobado y APLICABLE (decisión vigente + interruptores) cubre hoy en este documento. Sin decisiones vigentes o con los interruptores
     * apagados no cuesta ninguna consulta de resolver.
     *
     * @param  list<string>  $correos
     * @return list<string>
     */
    private function gruposCubiertosPorDecisiones(string $clave, array $correos): array
    {
        if (! IdentidadFlags::aplicacionHabilitada() || ! DB::table('cf_decisiones_identidad')->where('documento_hash', EvidenciaIdentidad::hashDocumento($clave))->where('estado', 'vigente')->exists()) {
            return [];
        }
        $cubiertos = [];
        foreach ($correos as $c) {
            $r = app(ScopeIdentidadAprobado::class)->resolver($clave, (string) $c);
            if ($r->esAprobado() && $r->aplicable()) {
                $cubiertos = array_merge($cubiertos, $r->grupos);
            }
        }

        return array_values(array_unique($cubiertos));
    }

    /** Nombre realmente distinto, o evento en común, frente a algún grupo con el que comparte correo (solo descriptivo: aquí únicamente DIFIERE la resolución). */
    private function riesgoFrenteA(string $g, array $otros, array $grupos): bool
    {
        $nombre = NombreConservador::normalizar((string) $grupos[$g]['filas'][0]->nombre_completo);
        $eventos = collect($grupos[$g]['filas'])->pluck('evento_id')->unique()->all();
        foreach ($otros as $o) {
            if (EvidenciaIdentidad::clase($nombre, NombreConservador::normalizar((string) $grupos[$o]['filas'][0]->nombre_completo)) === 'distinto'
                || array_intersect($eventos, collect($grupos[$o]['filas'])->pluck('evento_id')->unique()->all()) !== []) {
                return true;
            }
        }

        return false;
    }

    /** Grupos «objetivo» de un caso `GRUPO_SIN_VIA_*` hoy (según la causa que ese caso gestiona). @return list<string> */
    public function objetivosDe(Conciliacion $caso): array
    {
        $causa = array_search($caso->motivo_origen, self::MOTIVO_POR_CAUSA, true);
        if ($causa === false) {
            return [];
        }
        $clave = (string) DB::table('cf_certificados_legado as k')->join('cf_conciliaciones_certificados as p', 'p.certificado_legado_id', '=', 'k.id')->where('p.conciliacion_id', $caso->id)->orderBy('k.id')->value('k.documento_clave');
        if ($clave === '') {
            return [];
        }

        return array_keys(array_filter($this->analizarDocumento($clave), fn ($x) => $x['causa'] === $causa));
    }

    /** Documentos multi-grupo SIN caso de identidad «base» (los bloqueados por completo ya tienen el suyo). @return array<string,list<string>> clave → [grupo,...] */
    public function documentosParciales(): array
    {
        $base = DB::table('cf_conciliaciones')->where('tipo', Conciliacion::TIPO_IDENTIDAD_AMBIGUA)->where(fn ($q) => $q->whereNull('motivo_origen')->orWhere('motivo_origen', 'not like', 'GRUPO_SIN_VIA_%'))->pluck('referencia_clave')->flip();
        $porDoc = [];
        DB::table('cf_certificados_legado')->select('id', 'documento_clave', 'nombre_completo')->orderBy('id')->chunkById(5000, function ($filas) use (&$porDoc) {
            foreach ($filas as $f) {
                $porDoc[$f->documento_clave][NombreConservador::grupoId((string) $f->nombre_completo)] = true;
            }
        });
        $salida = [];
        foreach ($porDoc as $doc => $grupos) {
            if (count($grupos) >= 2 && ! isset($base[EvidenciaIdentidad::hashDocumento((string) $doc)])) {
                $salida[(string) $doc] = array_keys($grupos);
            }
        }

        return $salida;
    }

    /**
     * @return array{documentos_multigrupo:int,con_caso_identidad:int,accesibles_sin_caso:int,grupos_en_accesibles:int,grupos_accesibles:int,grupos_sin_via:int,por_motivo:array<string,int>,por_causa:array<string,int>,items:list<array<string,mixed>>}
     */
    public function reporte(): array
    {
        $base = DB::table('cf_conciliaciones')->where('tipo', Conciliacion::TIPO_IDENTIDAD_AMBIGUA)->where(fn ($q) => $q->whereNull('motivo_origen')->orWhere('motivo_origen', 'not like', 'GRUPO_SIN_VIA_%'))->count();
        $multi = 0;
        $parciales = $this->documentosParciales();
        $todos = [];
        DB::table('cf_certificados_legado')->select('id', 'documento_clave', 'nombre_completo')->orderBy('id')->chunkById(5000, function ($filas) use (&$todos) {
            foreach ($filas as $f) {
                $todos[$f->documento_clave][NombreConservador::grupoId((string) $f->nombre_completo)] = true;
            }
        });
        $multi = count(array_filter($todos, fn ($g) => count($g) >= 2));
        // Casos de este tipo por (documento, motivo) para enlazar caso_id.
        $casos = DB::table('cf_conciliaciones')->where('tipo', Conciliacion::TIPO_IDENTIDAD_AMBIGUA)->where('motivo_origen', 'like', 'GRUPO_SIN_VIA_%')->get(['id', 'referencia_clave', 'motivo_origen'])->mapWithKeys(fn ($c) => [$c->referencia_clave.'|'.$c->motivo_origen => (int) $c->id]);

        $r = ['documentos_multigrupo' => $multi, 'con_caso_identidad' => $base, 'accesibles_sin_caso' => 0, 'grupos_en_accesibles' => 0, 'grupos_accesibles' => 0, 'grupos_sin_via' => 0, 'por_motivo' => [], 'por_causa' => [], 'items' => []];
        foreach ($parciales as $clave => $_) {
            $a = $this->analizarDocumento($clave);
            if (array_filter($a, fn ($x) => $x['accesible']) === []) {
                continue;   // bloqueado sin caso: no debería existir
            }
            $hashDoc = EvidenciaIdentidad::hashDocumento($clave);
            $r['accesibles_sin_caso']++;
            $r['grupos_en_accesibles'] += count($a);
            foreach ($a as $g => $x) {
                if ($x['accesible']) {
                    $r['grupos_accesibles']++;

                    continue;
                }
                // `motivo` conserva la clasificación técnica original (compartido / sin_correo / sin_fila_habilitante); `causa` es la resolución refinada.
                $motivo = $x['motivo'];
                $r['grupos_sin_via']++;
                $r['por_motivo'][$motivo] = ($r['por_motivo'][$motivo] ?? 0) + 1;
                $r['por_causa'][$x['causa']] = ($r['por_causa'][$x['causa']] ?? 0) + 1;
                $motivoCaso = self::MOTIVO_POR_CAUSA[$x['causa']] ?? null;
                $r['items'][] = [
                    'documento_hash' => $hashDoc, 'grupo_hash' => $g, 'certificados' => $x['certs'], 'motivo' => $motivo, 'causa' => $x['causa'],
                    'caso_id' => $motivoCaso === null ? ($x['causa'] === 'sin_fila_habilitante' ? $this->casoDePlantilla($x['ids']) : null) : ($casos[$hashDoc.'|'.$motivoCaso] ?? null),
                    'resolucion' => ['correo_compartido' => 'correo_autorizado', 'evidencia_externa' => 'evidencia_externa', 'sin_correo' => 'evidencia_externa', 'sin_fila_habilitante' => 'resolver_plantilla'][$x['causa']],
                    'dependencia' => ['correo_compartido' => 'administrativa', 'evidencia_externa' => 'externa', 'sin_correo' => 'externa', 'sin_fila_habilitante' => 'tecnica'][$x['causa']],
                    'mensaje' => $x['causa'] === 'sin_fila_habilitante' ? 'Este grupo recuperará acceso cuando se resuelva su plantilla.' : null,
                ];
            }
        }

        return $r;
    }

    /** Caso de plantilla (10B-1) que cubre las filas no habilitantes del grupo, si existe. @param list<int> $ids */
    private function casoDePlantilla(array $ids): ?int
    {
        $id = DB::table('cf_conciliaciones_certificados as p')->join('cf_conciliaciones as c', 'c.id', '=', 'p.conciliacion_id')->whereIn('p.certificado_legado_id', $ids)->where('c.tipo', 'like', 'plantilla_%')->orderBy('c.id')->value('c.id');

        return $id === null ? null : (int) $id;
    }
}
