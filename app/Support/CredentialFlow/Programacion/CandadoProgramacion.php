<?php

namespace App\Support\CredentialFlow\Programacion;

use App\Models\CredentialFlow\EmisionProgramada;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use Illuminate\Support\Facades\Schema;

/**
 * Candado de la emisión manual: mientras una base tenga una programación ACTIVA (pendiente, ejecutándose o esperando confirmación), ninguna PRIMERA emisión sale a mano
 * (botón «Emitir certificados», «Emitir pruebas internas», emisión individual ni la primera emisión de un reemplazo histórico). Lo llaman los propios emisores,
 * no la interfaz: ninguna pantalla ni servicio lo puede esquivar. La única excepción es el ejecutor automático con el BOLETO (EjecucionAutorizada) de la
 * programación que reservó y que está «ejecutando»: el boleto solo existe en la memoria de ese proceso, así que una petición web no lo puede presentar.
 *
 * Para ser definitivo debe llamarse DENTRO de la transacción de la emisión y con la base bloqueada (lockForUpdate): programar también bloquea la base, así que
 * las dos operaciones se serializan y nunca quedan una programación pendiente y una emisión manual hecha «a la vez». La reemisión y la revocación de certificados
 * YA emitidos no pasan por aquí: no forman parte de la emisión programada (que solo emite a quienes no tienen ninguna emisión).
 */
final class CandadoProgramacion
{
    /** @throws EmisionException */
    public static function exigirLibre(int $loteId, ?EjecucionAutorizada $autorizada = null): void
    {
        // Antes de migrar (despliegue en curso) no hay programaciones posibles. Sin caché: es una consulta mínima y nunca queda un valor viejo.
        if (! Schema::hasTable('cf_emisiones_programadas')) {
            return;
        }

        $activa = EmisionProgramada::query()->where('lote_activa', $loteId)->first();
        if ($activa === null) {
            return;
        }
        if ($autorizada !== null && $activa->id === $autorizada->programacionId && $activa->estado === EmisionProgramada::EJECUTANDO
            && $autorizada->corresponde($activa->reclamo_hash)) {
            return;
        }

        $cuando = $activa->programada_para?->format('d/m/Y H:i');
        $mensaje = match ($activa->estado) {
            EmisionProgramada::EJECUTANDO => 'La emisión programada de esta base se está ejecutando en este momento. Espera a que termine.',
            EmisionProgramada::REQUIERE_CONFIRMACION => 'La emisión programada de esta base espera confirmación (algo cambió después de programarla). Reprográmala o cancélala antes de emitir manualmente.',
            default => "Esta base tiene una emisión programada para el {$cuando} (hora de Colombia). Cancélala o reprográmala antes de emitir manualmente.",
        };

        throw new EmisionException(EmisionException::EMISION_PROGRAMADA_PENDIENTE, $mensaje, 409);
    }

    /** Id de la programación activa de la base, o null (para dejar constancia en la auditoría de las operaciones sobre certificados ya emitidos). */
    public static function activaId(int $loteId): ?int
    {
        if (! Schema::hasTable('cf_emisiones_programadas')) {
            return null;
        }
        $id = EmisionProgramada::query()->where('lote_activa', $loteId)->value('id');

        return $id === null ? null : (int) $id;
    }
}
