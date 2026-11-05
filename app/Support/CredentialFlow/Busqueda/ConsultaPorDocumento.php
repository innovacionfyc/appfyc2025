<?php

namespace App\Support\CredentialFlow\Busqueda;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Emision;
use App\Support\CredentialFlow\Historico\PresentadorHistorico;
use App\Support\CredentialFlow\Legado\CodigoHistorico;
use App\Support\CredentialFlow\Legado\ElegibilidadLegado;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\FamiliaDocumento;
use App\Support\CredentialFlow\Portal\Hmac;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Portal\VinculosIdentidadModerna;
use App\Support\CredentialFlow\Verificacion\ClasificacionEmision;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consulta ADMINISTRATIVA de todos los certificados de un número de documento (históricos y modernos), para el buscador del panel. SOLO LEE: nunca genera ni
 * congela un PDF histórico, nunca asigna códigos, nunca registra descargas ni crea vínculos. La autorización (rol, interruptor, límites) y la auditoría las pone
 * quien la llama; aquí solo se decide QUÉ registros pertenecen al número y cómo se agrupan.
 *
 * Reglas de identidad (las mismas del portal, sin relajarlas ni fusionar nada):
 *  - El número se busca por su FAMILIA (FamiliaDocumento: con o sin prefijo de tipo). Los ceros a la izquierda distinguen documentos.
 *  - Una IDENTIDAD = un grupo de nombre conservador (NombreConservador) del número, leído con los vínculos de identidad VIGENTES (VinculosIdentidadModerna).
 *    Nombres distintos con el mismo número son identidades distintas aunque compartan correo: nunca se unen por número, parecido ni correo.
 *  - Los registros que FamiliaDocumento::excluidos deja fuera (tipos explícitos distintos con el mismo número y nombre) van en `excluidos`, sin descarga.
 *  - Cada participación moderna es un registro propio (la doble modalidad de un evento son dos certificados) con TODAS sus versiones.
 *  - Con `tipo`, solo se listan los registros de ese tipo o sin tipo registrado; los demás solo se cuentan (`otros_tipos`).
 *
 * `descarga.permitida` es la regla del panel (E3): modernos solo la versión vigente OFICIAL; históricos solo el canónico vigente, conciliado, elegible y con PDF
 * YA congelado. Revocados, reemplazados, duplicados, excluidos, pruebas internas (QA) y certificados sin clasificación se muestran, pero no se descargan.
 *
 * QA: se decide por el DOCUMENTO EMITIDO (cf_emisiones.diseno_snapshot, ClasificacionEmision), igual que la verificación pública; nunca por la marca viva
 * del participante, que puede cambiar después de emitir.
 */
final class ConsultaPorDocumento
{
    public const DOCUMENTO_INVALIDO = 'DOCUMENTO_INVALIDO';

    public const TIPO_INVALIDO = 'TIPO_INVALIDO';

    /** Motivos de `descarga.motivo` propios del buscador (los históricos usan además los de ElegibilidadLegado). */
    public const NO_CONGELADO = 'NO_CONGELADO';

    public const DUPLICADO = 'DUPLICADO';

    public const IDENTIDAD_AMBIGUA = 'IDENTIDAD_AMBIGUA';

    public const VERSION_REEMPLAZADA = 'VERSION_REEMPLAZADA';

    public const REVOCADA = 'REVOCADA';

    public const PRUEBA_INTERNA = 'PRUEBA_INTERNA';

    public const CLASIFICACION_NO_DISPONIBLE = 'CLASIFICACION_NO_DISPONIBLE';

    public const VIGENTE = 'vigente';

    public const REEMPLAZADO = 'reemplazado';

    public const REVOCADO = 'revocado';

    /** Huella del número para la auditoría (nunca la cédula en claro). Misma raíz para «C.C. 1.234.567», «CC1234567» y «1234567». */
    public static function huella(string $documento): ?string
    {
        $clave = AccesoPortal::claveDocumento($documento);

        return $clave === null ? null : Hmac::de('busqueda_documento', FamiliaDocumento::raiz($clave));
    }

    /**
     * @return array{valida:bool, motivo:?string, documento_huella:?string, tipo:?string, resumen:array<string,int|bool>,
     *     identidades:list<array<string,mixed>>, excluidos:list<array<string,mixed>>}
     */
    public function consultar(string $documento, ?string $tipo = null): array
    {
        $clave = AccesoPortal::claveDocumento($documento);
        if ($clave === null) {
            return $this->vacio(self::DOCUMENTO_INVALIDO, null, null);
        }
        $filtro = trim((string) $tipo) === '' ? null : FamiliaDocumento::tipoLegado($tipo);
        if ($filtro !== null && ! in_array($filtro, FamiliaDocumento::TIPOS, true)) {
            return $this->vacio(self::TIPO_INVALIDO, null, null);
        }
        $raiz = FamiliaDocumento::raiz($clave);
        $claves = FamiliaDocumento::claves($raiz);
        $huella = Hmac::de('busqueda_documento', $raiz);

        $vinculos = VinculosIdentidadModerna::mapa($raiz);
        $excluidos = FamiliaDocumento::excluidos($raiz);
        // El mismo grupo con el que el portal lee cada fila (AccesoPortal::cargarConVinculos / EmisionesModernas): el vínculo vigente lo lleva al grupo histórico.
        $grupoDe = function (string $nombre) use ($vinculos): string {
            $g = NombreConservador::grupoId($nombre);

            return $vinculos[$g] ?? $g;
        };

        $historicos = CertificadoLegado::query()->whereIn('documento_clave', $claves)->orderBy('id')->get();
        $participantes = DB::table('cf_participantes')->whereIn('documento_clave', $claves)->whereNull('deleted_at')->orderBy('id')->get();
        $emisiones = $participantes->isEmpty() ? collect() : Emision::query()->whereIn('participante_id', $participantes->pluck('id'))->orderBy('id')->get();
        $lotes = DB::table('cf_lotes')->whereIn('id', $participantes->pluck('lote_id')->unique())->get(['id', 'nombre', 'evento_id', 'deleted_at'])->keyBy('id');
        $eventos = DB::table('cf_eventos')->whereIn('id', $historicos->pluck('evento_id')->merge($lotes->pluck('evento_id'))->filter()->unique())
            ->get(['id', 'nombre', 'anio', 'fecha_texto'])->keyBy('id');

        $registros = collect();
        $codigos = $historicos->isEmpty() ? [] : CodigoHistorico::paraFilas($historicos);
        foreach ($historicos as $c) {
            $registros->push($this->historico($c, $codigos[$c->id] ?? null, $eventos, isset($excluidos['historicos'][$c->id]))
                + ['_grupo' => $grupoDe((string) $c->nombre_completo), '_tipo' => FamiliaDocumento::tipoDeClave(Texto::claveDocumento((string) $c->documento_clave)) ?? FamiliaDocumento::tipoLegado($c->tipo_documento)]);
        }
        $sucesoras = $emisiones->whereNotNull('reemplaza_id')->pluck('id', 'reemplaza_id');
        $reemplazos = $emisiones->isEmpty() ? collect() : DB::table('cf_certificados_legado')->whereIn('reemplazado_por_emision_id', $emisiones->pluck('id'))->pluck('reemplazado_por_emision_id')->flip();
        $porParticipante = $participantes->keyBy('id');
        foreach ($emisiones as $e) {
            $p = $porParticipante[$e->participante_id];
            $registros->push($this->moderno($e, $p, $lotes->get($p->lote_id), $eventos, $sucesoras[$e->id] ?? null, isset($reemplazos[$e->id]), isset($excluidos['participantes'][$p->id]))
                + ['_grupo' => $grupoDe((string) $p->nombre_completo), '_tipo' => FamiliaDocumento::tipoDeClave(Texto::claveDocumento((string) $p->documento_clave))]);
        }

        $otrosTipos = $filtro === null ? 0 : $registros->filter(fn ($r) => $r['_tipo'] !== null && $r['_tipo'] !== $filtro)->count();
        if ($filtro !== null) {
            $registros = $registros->filter(fn ($r) => $r['_tipo'] === null || $r['_tipo'] === $filtro)->values();
        }
        [$fuera, $dentro] = $registros->partition(fn ($r) => $r['excluido']);

        $identidades = $this->identidades($dentro, $vinculos);
        $limpiar = fn (array $r) => array_diff_key($r, ['_grupo' => 1, '_tipo' => 1]) + ['tipo' => $r['_tipo']];

        return [
            'valida' => true,
            'motivo' => null,
            'documento_huella' => $huella,
            'tipo' => $filtro,
            'resumen' => [
                'certificados' => $registros->count(),
                'historicos' => $registros->where('origen', 'historico')->count(),
                'modernos' => $registros->where('origen', 'moderno')->count(),
                'vigentes' => $registros->where('estado', self::VIGENTE)->count(),
                'qa' => $registros->where('qa', true)->count(),
                'eventos' => $registros->pluck('evento.id')->filter()->unique()->count(),
                'identidades' => count($identidades),
                'excluidos' => $fuera->count(),
                'otros_tipos' => $otrosTipos,
                'participaciones_sin_emision' => $participantes->whereNotIn('id', $emisiones->pluck('participante_id'))->count(),
                'conflicto' => count($identidades) > 1 || $fuera->isNotEmpty() || collect($identidades)->contains(fn ($i) => $i['tipos_distintos']),
            ],
            'identidades' => array_map(fn ($i) => ['certificados' => array_map($limpiar, $i['certificados'])] + $i, $identidades),
            'excluidos' => $fuera->map($limpiar)->values()->all(),
        ];
    }

    /**
     * Registros agrupados por identidad (grupo de nombre con vínculos). Las identidades se rotulan A, B… por orden de aparición y nunca exponen el hash del grupo.
     *
     * @param  Collection<int,array<string,mixed>>  $registros
     * @param  array<string,string>  $vinculos
     * @return list<array<string,mixed>>
     */
    private function identidades(Collection $registros, array $vinculos): array
    {
        $grupos = $registros->groupBy('_grupo');
        $vinculadas = array_flip(array_values($vinculos));
        $r = [];
        $i = 0;
        foreach ($grupos as $grupo => $items) {
            $tipos = $items->pluck('_tipo')->filter()->unique()->sort()->values()->all();
            $ordenados = $items->sortBy([fn ($a, $b) => ($b['anio'] ?? 0) <=> ($a['anio'] ?? 0), fn ($a, $b) => [$a['origen'], $a['id']] <=> [$b['origen'], $b['id']]])->values();
            $r[] = [
                'etiqueta' => chr(65 + $i++),
                'nombres' => $items->pluck('nombre')->unique()->values()->all(),
                'tipos' => $tipos,
                // Dos tipos explícitos distintos dentro de la misma identidad (registros guardados sin prefijo): el portal no los excluye, pero el admin debe verlo.
                'tipos_distintos' => count($tipos) > 1,
                'con_vinculo' => isset($vinculadas[$grupo]),
                'certificados' => $ordenados->all(),
            ];
        }

        return $r;
    }

    /** @param  Collection<int,object>  $eventos  @return array<string,mixed> */
    private function historico(CertificadoLegado $c, ?string $codigo, Collection $eventos, bool $excluido): array
    {
        $e = $eventos->get($c->evento_id);
        $estado = match (true) {
            $c->estado === CertificadoLegado::ESTADO_REVOCADO => self::REVOCADO,
            $c->estado === CertificadoLegado::ESTADO_REEMPLAZADO || $c->reemplazado_por_emision_id !== null => self::REEMPLAZADO,
            default => self::VIGENTE,
        };
        $duplicado = $c->conciliacion_estado === CertificadoLegado::CONCILIACION_DUPLICADO_CONSOLIDADO;
        $canonico = $duplicado ? ElegibilidadLegado::canonico($c) : $c;
        $motivo = match (true) {
            $excluido => self::IDENTIDAD_AMBIGUA,
            $duplicado => self::DUPLICADO,
            ($m = ElegibilidadLegado::motivo($c)) !== null => $m,
            $c->pdf_archivo === null => self::NO_CONGELADO,
            default => null,
        };

        return [
            'origen' => 'historico',
            'id' => (int) $c->id,
            'nombre' => (string) $c->nombre_completo,
            'documento' => (string) $c->documento,
            'evento' => $e === null ? null : ['id' => (int) $e->id, 'nombre' => (string) $e->nombre, 'fecha' => $e->fecha_texto ?: null],
            'anio' => $e?->anio === null ? null : (int) $e->anio,
            'codigo' => $codigo,
            'version' => null,
            'modalidad' => null,
            'estado' => $estado,
            'estado_etiqueta' => PresentadorHistorico::estadoCertificado($estado),
            'conciliacion' => ['estado' => (string) $c->conciliacion_estado] + PresentadorHistorico::conciliacion((string) $c->conciliacion_estado),
            'duplicado_de' => $duplicado && $canonico !== null && $canonico->id !== $c->id ? (int) $canonico->id : null,
            'pdf_congelado' => $c->pdf_archivo !== null,
            'reemplazado_por_emision_id' => $c->reemplazado_por_emision_id === null ? null : (int) $c->reemplazado_por_emision_id,
            'qa' => false,
            'sin_validez_academica' => false,
            'clasificacion' => 'historico',
            'excluido' => $excluido,
            'descarga' => ['permitida' => $motivo === null, 'motivo' => $motivo],
        ];
    }

    /** @param  Collection<int,object>  $eventos  @return array<string,mixed> */
    private function moderno(Emision $e, object $p, ?object $lote, Collection $eventos, ?int $sucesora, bool $reemplazaHistorico, bool $excluido): array
    {
        $ev = $lote?->evento_id === null ? null : $eventos->get($lote->evento_id);
        $datos = (array) $e->datos_snapshot;
        $estado = match (true) {
            $sucesora !== null => self::REEMPLAZADO,
            $e->estado === Emision::REVOCADA => self::REVOCADO,
            default => self::VIGENTE,
        };
        // Naturaleza del documento emitido (no la marca viva del participante). La revocación y el reemplazo prevalecen como motivo.
        $clasificacion = ClasificacionEmision::deDiseno($e->getRawOriginal('diseno_snapshot'));
        $qa = $clasificacion === ClasificacionEmision::PRUEBA_INTERNA;
        $motivo = match (true) {
            $excluido => self::IDENTIDAD_AMBIGUA,
            $estado === self::REEMPLAZADO => self::VERSION_REEMPLAZADA,
            $estado === self::REVOCADO => self::REVOCADA,
            $clasificacion === ClasificacionEmision::INDETERMINADA => self::CLASIFICACION_NO_DISPONIBLE,
            $qa => self::PRUEBA_INTERNA,
            default => null,
        };

        return [
            'origen' => 'moderno',
            'id' => (int) $e->id,
            'participante_id' => (int) $p->id,
            'lote' => $lote === null ? null : ['id' => (int) $lote->id, 'nombre' => (string) $lote->nombre, 'eliminada' => $lote->deleted_at !== null],
            'nombre' => (string) $p->nombre_completo,
            'documento' => (string) $p->documento,
            'evento' => ['id' => $ev === null ? null : (int) $ev->id, 'nombre' => (string) ($datos['evento'] ?? $ev?->nombre ?? ''), 'fecha' => ($datos['fecha'] ?? null) ?: ($ev->fecha_texto ?? null)],
            'anio' => $ev?->anio !== null ? (int) $ev->anio : ($e->emitido_at === null ? null : (int) $e->emitido_at->format('Y')),
            'codigo' => (string) $e->codigo,
            'version' => (int) $e->version,
            'modalidad' => ($datos['modalidad'] ?? null) ?: ($p->modalidad ?? null),
            'estado' => $estado,
            'estado_etiqueta' => ['vigente' => 'Vigente', 'reemplazado' => 'Reemplazado', 'revocado' => 'Revocado'][$estado],
            'emitido_at' => $e->emitido_at?->toDateTimeString(),
            'revocado_at' => $e->revocado_at?->toDateTimeString(),
            'reemplaza_a' => $e->reemplaza_id === null ? null : (int) $e->reemplaza_id,
            'reemplazada_por' => $sucesora,
            'reemplaza_historico' => $reemplazaHistorico,
            'qa' => $qa,
            'sin_validez_academica' => $qa,
            'clasificacion' => $clasificacion,
            'excluido' => $excluido,
            'descarga' => ['permitida' => $motivo === null, 'motivo' => $motivo],
        ];
    }

    /** @return array<string,mixed> */
    private function vacio(string $motivo, ?string $huella, ?string $tipo): array
    {
        return ['valida' => false, 'motivo' => $motivo, 'documento_huella' => $huella, 'tipo' => $tipo,
            'resumen' => ['certificados' => 0, 'historicos' => 0, 'modernos' => 0, 'vigentes' => 0, 'qa' => 0, 'eventos' => 0, 'identidades' => 0, 'excluidos' => 0,
                'otros_tipos' => 0, 'participaciones_sin_emision' => 0, 'conflicto' => false],
            'identidades' => [], 'excluidos' => []];
    }
}
