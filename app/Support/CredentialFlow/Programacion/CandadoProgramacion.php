<?php

namespace App\Support\CredentialFlow\Programacion;

use App\Models\CredentialFlow\EmisionProgramada;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use Illuminate\Support\Facades\Schema;

/**
 * Candado de la emisión manual: mientras una base tenga una programación ACTIVA (pendiente o ejecutándose), ninguna PRIMERA emisión de esa base sale a mano
 * (botón «Emitir certificados», «Emitir pruebas internas», emisión individual ni la primera emisión de un reemplazo histórico). Lo llaman los propios emisores,
 * no la interfaz: ninguna pantalla ni servicio lo puede esquivar. La única excepción es la programación que el ejecutor automático está corriendo.
 *
 * Para ser definitivo debe llamarse DENTRO de la transacción de la emisión y con la base bloqueada (lockForUpdate): programar también bloquea la base, así que
 * las dos operaciones se serializan y nunca quedan una programación pendiente y una emisión manual hecha «a la vez». La reemisión y la revocación de certificados
 * YA emitidos no pasan por aquí: no forman parte de la emisión programada (que solo emite a quienes no tienen ninguna emisión).
 */
final class CandadoProgramacion
{
    /** @throws EmisionException */
    public static function exigirLibre(int $loteId, ?EmisionProgramada $autorizada = null): void
    {
        // Antes de migrar (despliegue en curso) no hay programaciones posibles. Sin caché: es una consulta mínima y nunca queda un valor viejo.
        if (! Schema::hasTable('cf_emisiones_programadas')) {
            return;
        }

        $activa = EmisionProgramada::query()->where('lote_activa', $loteId)->first();
        if ($activa === null) {
            return;
        }
        if ($autorizada !== null && $activa->id === $autorizada->id && $activa->estado === EmisionProgramada::EJECUTANDO) {
            return;
        }

        $cuando = $activa->programada_para?->format('d/m/Y H:i');
        $mensaje = $activa->estado === EmisionProgramada::EJECUTANDO
            ? 'La emisión programada de esta base se está ejecutando en este momento. Espera a que termine.'
            : "Esta base tiene una emisión programada para el {$cuando} (hora de Colombia). Cancélala o reprográmala antes de emitir manualmente.";

        throw new EmisionException(EmisionException::EMISION_PROGRAMADA_PENDIENTE, $mensaje, 409);
    }
}
