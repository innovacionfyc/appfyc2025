<?php

namespace App\Support\CredentialFlow\Emisiones;

use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use InvalidArgumentException;

/**
 * Plantilla y vista previa de una base, antes de emitir.
 *
 *  - Una base solo emite oficialmente con una plantilla VÁLIDA (existe y tiene diseño guardado) y con la VISTA PREVIA revisada y confirmada por una persona.
 *  - La confirmación queda atada a la plantilla y a una huella de su diseño: si se cambia la plantilla o se edita su diseño después, hay que revisar de nuevo.
 *  - La plantilla de una base solo se puede cambiar mientras la base no tenga ninguna emisión (nada emitido se reescribe). Las plantillas de pruebas internas (QA) no sirven para esto.
 *  - Compatibilidad: una base que YA tiene emisiones sigue emitiendo como antes (ya pasó por este control en el flujo anterior).
 * Las emisiones de pruebas internas (QA) usan su propia plantilla marcada y no dependen de esta confirmación.
 */
final class ServicioVistaPrevia
{
    public static function exigida(): bool
    {
        return (bool) config('credential_flow.emision.exige_vista_previa', true);
    }

    public static function plantillaValida(?Plantilla $plantilla): bool
    {
        return $plantilla !== null && ! empty($plantilla->diseno['elements'] ?? null);
    }

    /** Huella del contenido de la plantilla (diseño + PDF base): si cambia, la vista previa confirmada deja de valer. */
    public static function huella(Plantilla $plantilla): string
    {
        return hash('sha256', json_encode($plantilla->diseno).'|'.$plantilla->hash_sha256);
    }

    public function yaEmitio(Lote $lote): bool
    {
        return $lote->emisiones()->exists();
    }

    /** ¿La vista previa confirmada sigue vigente para la plantilla actual de la base? */
    public function confirmada(Lote $lote): bool
    {
        $p = $lote->plantilla;
        if (! self::plantillaValida($p) || $lote->vista_previa_confirmada_at === null) {
            return false;
        }

        return (int) $lote->vista_previa_plantilla_id === $p->id && hash_equals((string) $lote->vista_previa_huella, self::huella($p));
    }

    /** Se generó una vista previa con la plantilla actual (paso previo a poder confirmarla). */
    public function generada(Lote $lote): bool
    {
        $p = $lote->plantilla;

        return self::plantillaValida($p) && $lote->vista_previa_generada_at !== null && (int) $lote->vista_previa_plantilla_id === $p->id
            && hash_equals((string) $lote->vista_previa_huella, self::huella($p));
    }

    /** Registra que se abrió una vista previa con la plantilla actual. Si cambió la plantilla (o su diseño), la confirmación anterior se descarta. */
    public function registrarGenerada(Lote $lote): void
    {
        $p = $lote->plantilla;
        if (! self::plantillaValida($p)) {
            return;
        }
        $huella = self::huella($p);
        $mismo = (int) $lote->vista_previa_plantilla_id === $p->id && hash_equals((string) $lote->vista_previa_huella, $huella);
        $lote->forceFill([
            'vista_previa_generada_at' => now(),
            'vista_previa_plantilla_id' => $p->id,
            'vista_previa_huella' => $huella,
            'vista_previa_confirmada_at' => $mismo ? $lote->vista_previa_confirmada_at : null,
        ])->save();
    }

    /** @throws InvalidArgumentException */
    public function confirmar(Lote $lote): void
    {
        if (! self::plantillaValida($lote->plantilla)) {
            throw new InvalidArgumentException('La plantilla de esta base no tiene un diseño válido.');
        }
        if (! $this->generada($lote)) {
            throw new InvalidArgumentException('Primero abre la vista previa de un participante con la plantilla actual y revísala.');
        }
        $lote->forceFill(['vista_previa_confirmada_at' => now()])->save();
        Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: 'Se confirmó la plantilla y la vista previa de una base', extra: ['lote_id' => $lote->id, 'plantilla_id' => $lote->plantilla_id]);
    }

    /** @throws InvalidArgumentException */
    public function cambiarPlantilla(Lote $lote, Plantilla $plantilla): void
    {
        if ($this->yaEmitio($lote)) {
            throw new InvalidArgumentException('Esta base ya tiene certificados emitidos: su plantilla no se puede cambiar.');
        }
        if (! self::plantillaValida($plantilla)) {
            throw new InvalidArgumentException('La plantilla elegida no tiene un diseño guardado.');
        }
        if ($plantilla->es_plantilla_qa ?? false) {
            throw new InvalidArgumentException('Una plantilla de pruebas internas no puede usarse para los certificados oficiales.');
        }
        if ($lote->plantilla_id === $plantilla->id) {
            return;
        }
        $lote->forceFill([
            'plantilla_id' => $plantilla->id,
            'vista_previa_generada_at' => null, 'vista_previa_confirmada_at' => null, 'vista_previa_plantilla_id' => null, 'vista_previa_huella' => null,
        ])->save();
        Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: 'Se cambió la plantilla de una base (sin emisiones)', extra: ['lote_id' => $lote->id, 'plantilla_id' => $plantilla->id]);
    }

    /**
     * Control previo a una emisión OFICIAL de la base.
     *
     * @throws EmisionException
     */
    public function exigir(Lote $lote): void
    {
        $p = $lote->plantilla;
        if (! self::plantillaValida($p)) {
            throw new EmisionException(EmisionException::PLANTILLA_NO_DISPONIBLE, 'La base no tiene una plantilla válida con diseño guardado. Elige una plantilla antes de emitir.', 409);
        }
        if (self::exigida() && ! $this->yaEmitio($lote) && ! $this->confirmada($lote)) {
            throw new EmisionException(EmisionException::VISTA_PREVIA_PENDIENTE, 'Antes de emitir, revisa la vista previa y confirma la plantilla de esta base.', 409);
        }
    }
}
