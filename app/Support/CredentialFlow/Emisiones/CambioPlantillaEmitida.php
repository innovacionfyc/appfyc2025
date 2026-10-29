<?php

namespace App\Support\CredentialFlow\Emisiones;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Programacion\CandadoProgramacion;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Support\Facades\DB;

/**
 * Cambio AUTORIZADO de la plantilla de una base que YA emitió certificados (la pantalla solo lo permite antes de emitir: ServicioVistaPrevia::cambiarPlantilla).
 *
 *  - No toca ningún certificado: las emisiones guardan su propio snapshot (diseño y PDF base) y siguen exactamente igual hasta que se reemitan.
 *  - La plantilla nueva debe ser institucional (no de pruebas internas), tener diseño guardado y su variante QA válida, y ser distinta de la actual.
 *  - Se bloquea con una emisión programada activa. Una emisión en curso de la misma base aborta sola al terminar (su huella incluye la plantilla de la base).
 *  - La vista previa anterior se descarta: la plantilla nueva exige revisar y confirmar su vista previa antes de emitir o reemitir oficiales
 *    (ServicioVistaPrevia::plantillaCambiadaTrasEmitir).
 *  - `planear` no escribe nada y devuelve una huella; `ejecutar` solo procede si, con la base bloqueada, el plan sigue siendo EXACTAMENTE el simulado
 *    (otro administrador o cualquier cambio intermedio obligan a simular de nuevo). Un Movimiento deja quién, cuándo, de qué plantilla a cuál y por qué.
 */
final class CambioPlantillaEmitida
{
    public const MOTIVO_MIN = 10;

    public const MOTIVO_MAX = 500;

    public function __construct(private readonly ServicioQa $qa) {}

    /**
     * Valida y describe el cambio sin escribir nada.
     *
     * @return array{lote_id:int, base:string, actual:array{id:int, nombre:?string}, nueva:array{id:int, nombre:string}, variante_qa:array{id:?int, nombre:?string},
     *               vigentes_oficiales:int, vigentes_qa:int, bloqueos:array<int,string>, huella:string}
     */
    public function planear(Lote $lote, Plantilla $nueva): array
    {
        $variante = $this->qa->varianteDe($nueva);
        $bloqueos = [];
        if (! app(ServicioVistaPrevia::class)->yaEmitio($lote)) {
            $bloqueos[] = 'La base todavía no emitió certificados: cambia la plantilla desde la pantalla de la base («Plantilla»), no con este comando.';
        }
        if ($nueva->es_plantilla_qa ?? false) {
            $bloqueos[] = 'La plantilla elegida es de pruebas internas (QA): no sirve para los certificados oficiales.';
        }
        if (! ServicioVistaPrevia::plantillaValida($nueva)) {
            $bloqueos[] = 'La plantilla elegida no tiene un diseño guardado.';
        }
        if ($variante === null) {
            $bloqueos[] = 'La plantilla elegida no tiene su variante de pruebas internas (QA) válida: créala antes (credential-flow:plantilla-variante-qa).';
        }
        if ((int) $lote->plantilla_id === $nueva->id) {
            $bloqueos[] = 'La base ya usa esa plantilla.';
        }
        if (($programacion = CandadoProgramacion::activaId($lote->id)) !== null) {
            $bloqueos[] = "La base tiene una emisión programada activa (#{$programacion}): cancélala antes de cambiar la plantilla.";
        }

        $vigentes = fn (bool $qa) => Emision::query()->where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)
            ->whereIn('participante_id', $lote->participantes()->withTrashed()->where('es_control_qa', $qa)->select('id'))->count();
        $plan = [
            'lote_id' => $lote->id,
            'base' => (string) $lote->nombre,
            'actual' => ['id' => (int) $lote->plantilla_id, 'nombre' => $lote->plantilla?->nombre],
            'nueva' => ['id' => $nueva->id, 'nombre' => (string) $nueva->nombre],
            'variante_qa' => ['id' => $variante?->id, 'nombre' => $variante?->nombre],
            'vigentes_oficiales' => $vigentes(false),
            'vigentes_qa' => $vigentes(true),
            'bloqueos' => $bloqueos,
        ];
        // La huella fija TODO lo que el administrador revisó: base y plantilla actual, plantilla nueva y su variante (diseño + PDF base) y los certificados vigentes.
        $plan['huella'] = hash('sha256', json_encode([
            $plan['lote_id'], $plan['actual']['id'], $nueva->id, ServicioVistaPrevia::plantillaValida($nueva) ? ServicioVistaPrevia::huella($nueva) : null,
            $variante?->id, $variante ? ServicioVistaPrevia::huella($variante) : null, $plan['vigentes_oficiales'], $plan['vigentes_qa'],
            Emision::query()->where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)->orderBy('id')->pluck('id')->all(),
        ], JSON_THROW_ON_ERROR));

        return $plan;
    }

    /**
     * Cambia la plantilla SOLO si el plan, recalculado con la base bloqueada, coincide con la huella simulada.
     *
     * @return array<string,mixed> el plan ejecutado
     *
     * @throws EmisionException
     */
    public function ejecutar(Lote $lote, Plantilla $nueva, string $huellaSimulada, string $motivo, int $usuarioId): array
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < self::MOTIVO_MIN || mb_strlen($motivo) > self::MOTIVO_MAX) {
            throw new EmisionException('MOTIVO_INVALIDO', 'El motivo debe tener entre '.self::MOTIVO_MIN.' y '.self::MOTIVO_MAX.' caracteres.', 422);
        }

        return DB::transaction(function () use ($lote, $nueva, $huellaSimulada, $motivo, $usuarioId) {
            // Mismo orden de bloqueo que emitir y programar (primero la base): un cambio y una emisión o programación de la misma base se serializan.
            $bloqueado = Lote::lockForUpdate()->find($lote->id);
            $plantilla = Plantilla::find($nueva->id);
            if ($bloqueado === null || $plantilla === null) {
                throw new EmisionException(EmisionException::PLAN_DESACTUALIZADO, 'La base o la plantilla ya no están disponibles. No se cambió nada.', 409);
            }
            CandadoProgramacion::exigirLibre($bloqueado->id);

            $plan = $this->planear($bloqueado, $plantilla);
            if ($plan['bloqueos'] !== []) {
                throw new EmisionException(EmisionException::CAMBIO_PLANTILLA_INVALIDO, implode(' ', $plan['bloqueos']), 409);
            }
            if (! hash_equals($plan['huella'], $huellaSimulada)) {
                throw new EmisionException(EmisionException::PLAN_DESACTUALIZADO, 'La base, la plantilla o sus certificados cambiaron desde la simulación. No se cambió nada: vuelve a simular.', 409);
            }

            $bloqueado->forceFill([
                'plantilla_id' => $plantilla->id,
                // La confirmación anterior era de OTRA plantilla: se descarta y la nueva se revisa y confirma de nuevo en la pantalla de la base.
                'vista_previa_generada_at' => null, 'vista_previa_confirmada_at' => null, 'vista_previa_plantilla_id' => null, 'vista_previa_huella' => null,
            ])->save();

            Movimiento::registrar(
                tipo: 'actualizacion',
                modulo: 'credential-flow',
                descripcion: 'Se cambió la plantilla de una base con certificados emitidos (cambio autorizado)',
                extra: [
                    'lote_id' => $bloqueado->id, 'plantilla_anterior_id' => $plan['actual']['id'], 'plantilla_nueva_id' => $plantilla->id,
                    'variante_qa_id' => $plan['variante_qa']['id'], 'vigentes_oficiales' => $plan['vigentes_oficiales'], 'vigentes_qa' => $plan['vigentes_qa'],
                    'motivo' => $motivo, 'autorizado_por' => $usuarioId, 'huella_plan' => $plan['huella'],
                ],
            );

            return $plan;
        });
    }
}
