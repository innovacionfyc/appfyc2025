<?php

namespace App\Support\CredentialFlow\Programacion;

use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Emisiones\ServicioVistaPrevia;
use App\Support\CredentialFlow\Qa\ServicioQa;

/**
 * Huella de lo que el administrador aprobó al programar: si cambia CUALQUIER parte, la emisión no sale sola y hay que reconfirmar. Son cinco partes (para decir
 * QUÉ cambió) y una huella total; todo son hashes SHA-256: nunca se guardan nombres, documentos ni correos.
 *
 *  - base: nombre, plantilla, evento, datos comunes (evento, fecha, intensidad, modalidad…) y estado operativo.
 *  - participantes: TODOS los que aún no tienen emisión (reales y QA, de cualquier elegibilidad), con lo que se imprime o decide la emisión. Los ya emitidos no
 *    cuentan: no forman parte de esta emisión.
 *  - plantilla: la plantilla oficial (diseño, versión de esquema y hash del PDF base).
 *  - variante_qa: la variante de pruebas internas que se usaría (o su ausencia).
 *  - vista_previa: la confirmación de la vista previa (quién la ató a qué plantilla y diseño, y cuándo).
 */
final class HuellaProgramacion
{
    public const PARTES = ['base', 'participantes', 'plantilla', 'variante_qa', 'vista_previa'];

    /** @return array{huella:string, detalle:array<string,string>} */
    public static function calcular(Lote $lote): array
    {
        $plantilla = $lote->plantilla_id === null ? null : Plantilla::query()->find($lote->plantilla_id);
        $variante = app(ServicioQa::class)->plantillaPara($lote);

        $participantes = Participante::query()->where('lote_id', $lote->id)->whereDoesntHave('emisiones')->orderBy('id')
            ->get(['id', 'nombre_completo', 'documento', 'documento_clave', 'tipo_documento_confirmado', 'elegibilidad', 'es_control_qa', 'intensidad_horaria', 'modalidad'])
            ->map(fn (Participante $p) => [
                'id' => $p->id, 'nombre_completo' => $p->nombre_completo, 'documento' => $p->documento, 'documento_clave' => $p->documento_clave,
                'tipo_documento_confirmado' => (bool) $p->tipo_documento_confirmado, 'elegibilidad' => $p->elegibilidad, 'es_control_qa' => (bool) $p->es_control_qa,
                'intensidad_horaria' => $p->intensidad_horaria, 'modalidad' => $p->modalidad,
            ])->all();

        $detalle = [
            'base' => self::hash(['id' => $lote->id, 'nombre' => $lote->nombre, 'plantilla_id' => $lote->plantilla_id, 'evento_id' => $lote->evento_id,
                'datos_comunes' => $lote->datos_comunes, 'estado_operativo' => $lote->estado_operativo]),
            'participantes' => self::hash($participantes),
            'plantilla' => self::hash(self::plantilla($plantilla)),
            'variante_qa' => self::hash(self::plantilla($variante)),
            'vista_previa' => self::hash(['exigida' => ServicioVistaPrevia::exigida(), 'confirmada_at' => $lote->vista_previa_confirmada_at?->format('Y-m-d H:i:s'),
                'plantilla_id' => $lote->vista_previa_plantilla_id, 'huella' => $lote->vista_previa_huella]),
        ];

        return ['huella' => self::hash($detalle), 'detalle' => $detalle];
    }

    /**
     * Partes que cambiaron entre dos detalles (vacío = nada cambió).
     *
     * @param  array<string,string>  $antes
     * @param  array<string,string>  $ahora
     * @return list<string>
     */
    public static function diferencias(array $antes, array $ahora): array
    {
        return array_values(array_filter(self::PARTES, fn (string $p) => ($antes[$p] ?? null) !== ($ahora[$p] ?? null)));
    }

    /** @return array<string,mixed>|null */
    private static function plantilla(?Plantilla $p): ?array
    {
        return $p === null ? null : ['id' => $p->id, 'nombre' => $p->nombre, 'diseno' => $p->diseno, 'schema_version' => (int) $p->schema_version,
            'hash' => $p->hash_sha256, 'es_plantilla_qa' => (bool) $p->es_plantilla_qa, 'variante_qa_id' => $p->variante_qa_id];
    }

    private static function hash(mixed $datos): string
    {
        return hash('sha256', json_encode(self::ordenar($datos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function ordenar(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }
        if (! array_is_list($valor)) {
            ksort($valor);
        }

        return array_map(self::ordenar(...), $valor);
    }
}
