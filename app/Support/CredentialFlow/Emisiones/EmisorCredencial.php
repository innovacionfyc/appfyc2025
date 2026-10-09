<?php

namespace App\Support\CredentialFlow\Emisiones;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emisión oficial individual, reemisión y revocación.
 *
 * Orden de una emisión (nunca se mantiene abierta una transacción durante el render):
 *   1. validar y capturar el snapshot (con su huella y su código);
 *   2. renderizar el PDF EN MEMORIA desde ese snapshot, y calcular hash y bytes;
 *   3. transacción: bloquear al participante, comprobar que no haya una emisión vigente y que las entradas
 *      no hayan cambiado (misma huella); escribir el PDF (temporal + rename) e insertar la fila y el Movimiento;
 *   4. si algo falla, rollback y borrado del archivo definitivo.
 * El índice único participante_vigente es la barrera final ante dos emisiones simultáneas.
 */
final class EmisorCredencial
{
    public const MOTIVO_MIN = 5;

    public const MOTIVO_MAX = 500;

    public function emitir(Participante $participante, Lote $lote, ?int $usuarioId): Emision
    {
        $this->comprobarPertenencia($participante, $lote);

        if (Emision::where('participante_id', $participante->id)->where('estado', Emision::EMITIDA)->exists()) {
            throw $this->yaVigente();
        }

        return $this->crear($participante, $lote, null, null, $usuarioId);
    }

    /**
     * Primera emisión de un participante creado para reemplazar un certificado histórico (Fase 10B-2B-2A). Es EXACTAMENTE la misma maquinaria
     * que `emitir` (snapshot, render, código, archivo, Movimiento), con dos añadidos: la `operacion` determinista (idempotencia) y un gancho que
     * se ejecuta DENTRO de la transacción de la emisión, con la fila ya creada, para enlazar el histórico y cerrar el caso de forma atómica. Si
     * el gancho lanza, se revierte todo y se borra el archivo.
     *
     * @param  \Closure(Emision):void  $alCrear
     */
    public function emitirParaReemplazo(Participante $participante, Lote $lote, ?int $usuarioId, string $operacion, \Closure $alCrear): Emision
    {
        $this->comprobarPertenencia($participante, $lote);

        return $this->crear($participante, $lote, null, null, $usuarioId, $operacion, $alCrear);
    }

    /** Sustituye la emisión vigente por una nueva versión con los datos ACTUALES. */
    public function reemitir(Emision $vigente, string $motivo, ?int $usuarioId): Emision
    {
        $this->comprobarMotivo($motivo);
        if (! $vigente->vigente()) {
            throw $this->yaRevocada();
        }

        $participante = Participante::find($vigente->participante_id);
        $lote = Lote::find($vigente->lote_id);
        if (! $participante || ! $lote) {
            throw new EmisionException(EmisionException::PARTICIPANTE_NO_DISPONIBLE, 'El participante o la base de esta emisión ya no están disponibles.', 409);
        }

        return $this->crear($participante, $lote, $vigente, $motivo, $usuarioId);
    }

    public function revocar(Emision $emision, string $motivo, ?int $usuarioId): Emision
    {
        $this->comprobarMotivo($motivo);

        return DB::transaction(function () use ($emision, $motivo, $usuarioId) {
            // Se bloquea al participante (misma clave de serialización que emitir/reemitir) y luego la emisión.
            Participante::withTrashed()->lockForUpdate()->find($emision->participante_id);
            $actual = Emision::lockForUpdate()->findOrFail($emision->id);

            if (! $actual->vigente()) {
                throw $this->yaRevocada();
            }

            $this->marcarRevocada($actual, $motivo, $usuarioId);

            Movimiento::registrar(
                tipo: 'actualizacion',
                modulo: 'credential-flow',
                descripcion: 'Se revocó una emisión de Credential Flow',
                extra: ['emision_id' => $actual->id, 'lote_id' => $actual->lote_id],
            );

            return $actual->refresh();
        });
    }

    // ── Internos ──────────────────────────────────────────────────────────────

    private function crear(Participante $participante, Lote $lote, ?Emision $reemplaza, ?string $motivo, ?int $usuarioId, ?string $operacion = null, ?\Closure $alCrear = null): Emision
    {
        $plantilla = $lote->plantilla;
        if (! $plantilla) {
            throw new EmisionException(EmisionException::PLANTILLA_NO_DISPONIBLE, 'La plantilla de esta base ya no está disponible.', 409);
        }

        // 1–2: snapshot y render en memoria (sin tocar la base ni el disco).
        [$base, $hashPlantilla] = SnapshotCredencial::cargarPlantilla($plantilla);
        $snapshot = SnapshotCredencial::capturar($participante, $lote, $plantilla, $hashPlantilla);
        $bytes = GeneradorCredencialPdf::generarDesde($snapshot->diseno, $base, $snapshot->datosCredencial(), $snapshot->schemaVersion, ['participante' => $participante->id], $snapshot->urlVerificacion());
        $hash = hash('sha256', $bytes);
        $tamano = strlen($bytes);

        EspacioDisco::exigir($tamano, AlmacenEmisiones::disco()->path(''));

        $rutaFinal = AlmacenEmisiones::rutaNueva();
        $escrito = false;

        try {
            return DB::transaction(function () use ($participante, $lote, $plantilla, $reemplaza, $motivo, $usuarioId, $operacion, $alCrear, $snapshot, $bytes, $hash, $tamano, $rutaFinal, &$escrito) {
                // 3: bloqueo y comprobaciones con la base ya bloqueada.
                $bloqueado = Participante::lockForUpdate()->find($participante->id);
                $loteActual = Lote::find($lote->id);
                $plantillaActual = Plantilla::find($plantilla->id);
                if (! $bloqueado || ! $loteActual || ! $plantillaActual) {
                    throw new EmisionException(EmisionException::DATOS_CAMBIARON_DURANTE_EMISION, 'Los datos cambiaron mientras se emitía el certificado. No se emitió nada: inténtalo de nuevo.', 409);
                }

                $anterior = null;
                if ($reemplaza) {
                    $anterior = Emision::lockForUpdate()->find($reemplaza->id);
                    if (! $anterior || ! $anterior->vigente()) {
                        throw $this->yaRevocada();
                    }
                } elseif (Emision::where('participante_id', $bloqueado->id)->where('estado', Emision::EMITIDA)->exists()) {
                    throw $this->yaVigente();
                }

                if (! hash_equals($snapshot->huella, SnapshotCredencial::huella($bloqueado, $loteActual, $plantillaActual))) {
                    throw new EmisionException(EmisionException::DATOS_CAMBIARON_DURANTE_EMISION, 'Los datos cambiaron mientras se emitía el certificado. No se emitió nada: inténtalo de nuevo.', 409);
                }

                $version = (int) Emision::where('participante_id', $bloqueado->id)->max('version') + 1;

                // La vigente anterior se libera ANTES de insertar (el índice único participante_vigente).
                if ($anterior) {
                    $this->marcarRevocada($anterior, "Reemplazada por la versión {$version}. Motivo: {$motivo}", $usuarioId);
                }

                AlmacenEmisiones::escribir($bytes, $rutaFinal);
                $escrito = true;

                $emision = Emision::create([
                    'codigo' => $snapshot->codigo,
                    'participante_id' => $bloqueado->id,
                    'lote_id' => $loteActual->id,
                    'plantilla_id' => $plantillaActual->id,
                    'version' => $version,
                    'reemplaza_id' => $anterior?->id,
                    'estado' => Emision::EMITIDA,
                    'participante_vigente' => $bloqueado->id,
                    'datos_snapshot' => $snapshot->datos,
                    'diseno_snapshot' => $snapshot->diseno,
                    'schema_version' => $snapshot->schemaVersion,
                    'plantilla_pdf_hash' => $snapshot->plantillaPdfHash,
                    'generador_snapshot' => $snapshot->generador,
                    'pdf_archivo' => $rutaFinal,
                    'pdf_hash' => $hash,
                    'pdf_bytes' => $tamano,
                    'emitido_at' => now(),
                    'emitido_por' => $usuarioId,
                    'operacion' => $operacion,
                ]);

                if ($anterior) {
                    Movimiento::registrar(
                        tipo: 'registro',
                        modulo: 'credential-flow',
                        descripcion: 'Se reemitió una credencial de Credential Flow',
                        extra: ['nueva_emision_id' => $emision->id, 'reemplaza_emision_id' => $anterior->id, 'lote_id' => $loteActual->id],
                    );
                } else {
                    Movimiento::registrar(
                        tipo: 'registro',
                        modulo: 'credential-flow',
                        descripcion: 'Se emitió una credencial de Credential Flow',
                        extra: ['emision_id' => $emision->id, 'lote_id' => $loteActual->id, 'participante_id' => $bloqueado->id, 'version' => $version],
                    );
                }

                if ($alCrear !== null) {
                    $alCrear($emision);
                }

                return $emision;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($escrito) {
                AlmacenEmisiones::borrar($rutaFinal);
            }
            Log::warning('Credential Flow: conflicto de unicidad al emitir', ['participante' => $participante->id]);

            throw $this->yaVigente();
        } catch (Throwable $e) {
            if ($escrito) {
                AlmacenEmisiones::borrar($rutaFinal);
            }

            throw $e;
        }
    }

    private function marcarRevocada(Emision $emision, string $motivo, ?int $usuarioId): void
    {
        $emision->update([
            'estado' => Emision::REVOCADA,
            'participante_vigente' => null,
            'revocado_at' => now(),
            'revocado_por' => $usuarioId,
            'motivo_revocacion' => $motivo,
        ]);
    }

    private function comprobarPertenencia(Participante $participante, Lote $lote): void
    {
        if ($participante->lote_id !== $lote->id) {
            throw new EmisionException(EmisionException::PARTICIPANTE_NO_DISPONIBLE, 'El participante no pertenece a esta base.', 404);
        }
    }

    private function comprobarMotivo(string $motivo): void
    {
        $largo = mb_strlen(trim($motivo));
        if ($largo < self::MOTIVO_MIN || $largo > self::MOTIVO_MAX) {
            throw new EmisionException('MOTIVO_INVALIDO', 'El motivo debe tener entre '.self::MOTIVO_MIN.' y '.self::MOTIVO_MAX.' caracteres.', 422);
        }
    }

    private function yaVigente(): EmisionException
    {
        return new EmisionException(EmisionException::EMISION_YA_VIGENTE, 'Este participante ya tiene una emisión vigente. Revócala o reemítela.', 409);
    }

    private function yaRevocada(): EmisionException
    {
        return new EmisionException(EmisionException::EMISION_YA_REVOCADA, 'Esta emisión ya no está vigente (está revocada o fue reemplazada).', 409);
    }
}
