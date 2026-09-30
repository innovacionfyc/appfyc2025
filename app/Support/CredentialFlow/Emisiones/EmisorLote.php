<?php

namespace App\Support\CredentialFlow\Emisiones;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use App\Support\CredentialFlow\Generacion\PlanificadorTexto;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Emisión masiva de los participantes pendientes de un lote (síncrona, TODO O NADA, máximo 200 por operación).
 *
 * «Pendiente» = participante SIN ninguna emisión. Los participantes con historial (por ejemplo, revocados) no
 * se tocan en masa: una nueva versión solo sale de una acción individual (emitir/reemitir).
 *
 * Orden (nunca hay una transacción abierta durante el render):
 *   1. tomar hasta 200 pendientes y capturar su snapshot y su huella;
 *   2. prevalidar a TODOS (planificador: datos, fuente, cobertura, NO_CABE…): si uno falla, no se emite ninguno;
 *   3. comprobar espacio libre y generar los PDFs en una carpeta de staging privada;
 *   4. transacción: bloquear el lote y los participantes, volver a calcular las huellas contra la base y comprobar
 *      que siguen sin emisión; si CUALQUIERA cambió → rollback, staging borrado, cero emisiones;
 *   5. solo entonces mover los PDFs (rename), crear las emisiones y un único Movimiento.
 */
final class EmisorLote
{
    public const LIMITE = 200;

    /** @return array<string,int> */
    public function resumen(Lote $lote): array
    {
        $pendientes = $lote->participantes()->whereDoesntHave('emisiones')->count();

        return [
            'participantes' => $lote->participantes()->count(),
            'vigentes' => Emision::where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)->count(),
            'revocados' => $lote->participantes()->whereHas('emisiones')->whereDoesntHave('emisionVigente')->count(),
            'pendientes' => $pendientes,
            'a_emitir' => min($pendientes, self::LIMITE),
            'limite' => self::LIMITE,
        ];
    }

    /**
     * @return array{operacion:string, total:int, emisiones:array<int,int>, restantes:int}
     *
     * @throws EmisionException|GeneracionCredencialException
     */
    public function emitirPendientes(Lote $lote, ?int $usuarioId): array
    {
        $plantilla = $lote->plantilla;
        if (! $plantilla) {
            throw new EmisionException(EmisionException::PLANTILLA_NO_DISPONIBLE, 'La plantilla de este lote ya no está disponible.', 409);
        }

        $pendientes = $lote->participantes()->whereDoesntHave('emisiones')->orderBy('id')->limit(self::LIMITE)->get();
        if ($pendientes->isEmpty()) {
            throw new EmisionException(EmisionException::SIN_PENDIENTES, 'No hay participantes pendientes de emitir en este lote.', 409);
        }

        // 1–2: snapshots + huellas y prevalidación total (todo o nada).
        [$base, $hashPlantilla] = SnapshotCredencial::cargarPlantilla($plantilla);
        $pagina = GeneradorCredencialPdf::tamanoPagina($base, ['plantilla' => $plantilla->id]);

        /** @var array<int,SnapshotCredencial> $snapshots por id de participante */
        $snapshots = [];
        $fallos = [];
        foreach ($pendientes as $participante) {
            try {
                $snapshot = SnapshotCredencial::capturar($participante, $lote, $plantilla, $hashPlantilla);
                PlanificadorTexto::planificar($snapshot->diseno, $snapshot->datosCredencial(), $pagina, $snapshot->schemaVersion);
                $snapshots[$participante->id] = $snapshot;
            } catch (GeneracionCredencialException $e) {
                $fallos[] = ['participante_id' => $participante->id, 'nombre' => $participante->nombre_completo, 'codigo' => $e->codigo, 'mensaje' => $e->getMessage()];
            }
        }
        if ($fallos !== []) {
            throw new EmisionException(
                EmisionException::PREVALIDACION_FALLIDA,
                'Hay '.count($fallos).' participante(s) que no se pueden emitir. No se emitió ninguno: corrige los problemas y vuelve a intentarlo.',
                422,
                $fallos
            );
        }

        // 3: espacio y generación en staging (fuera de cualquier transacción).
        EspacioDisco::exigir(EspacioDisco::estimarEmisiones($pendientes->count(), strlen($base)), AlmacenEmisiones::disco()->path(''));

        $operacion = (string) Str::uuid();
        $staging = AlmacenEmisiones::directorioStaging($operacion);
        $generados = []; // participante_id => [staging, hash, bytes, final]
        $movidos = [];

        try {
            foreach ($pendientes as $i => $participante) {
                $snapshot = $snapshots[$participante->id];
                $bytes = GeneradorCredencialPdf::generarDesde($snapshot->diseno, $base, $snapshot->datosCredencial(), $snapshot->schemaVersion, ['participante' => $participante->id]);

                $rutaStaging = AlmacenEmisiones::rutaStaging($operacion, $i);
                AlmacenEmisiones::guardarStaging($rutaStaging, $bytes);
                $generados[$participante->id] = [
                    'staging' => $rutaStaging,
                    'hash' => hash('sha256', $bytes),
                    'bytes' => strlen($bytes),
                    'final' => AlmacenEmisiones::rutaNueva(),
                ];
                unset($bytes); // los PDFs viven en disco, no en memoria
                // Cada render de FPDI/TCPDF deja un grafo de objetos con referencias circulares (≈ el peso del PDF
                // base) que el GC solo recoge tras miles de raíces: sin esto la memoria crece linealmente con N.
                gc_collect_cycles();
            }

            // 4–5: fase crítica.
            $emisiones = DB::transaction(function () use ($lote, $plantilla, $pendientes, $snapshots, $generados, $operacion, $usuarioId, &$movidos) {
                $loteActual = Lote::lockForUpdate()->find($lote->id);
                $plantillaActual = Plantilla::find($plantilla->id);
                $ids = $pendientes->pluck('id')->all();
                $bloqueados = Participante::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

                if (! $loteActual || ! $plantillaActual || $bloqueados->count() !== count($ids)) {
                    throw $this->cambiaron();
                }
                if (Emision::whereIn('participante_id', $ids)->exists()) {
                    throw new EmisionException(EmisionException::EMISION_YA_VIGENTE, 'Alguno de los participantes ya fue emitido por otra operación. No se emitió nada: vuelve a intentarlo.', 409);
                }
                foreach ($ids as $id) {
                    if (! hash_equals($snapshots[$id]->huella, SnapshotCredencial::huella($bloqueados[$id], $loteActual, $plantillaActual))) {
                        throw $this->cambiaron();
                    }
                }

                $creadas = [];
                foreach ($ids as $id) {
                    $g = $generados[$id];
                    $s = $snapshots[$id];

                    AlmacenEmisiones::mover($g['staging'], $g['final']);
                    $movidos[] = $g['final'];

                    $creadas[] = Emision::create([
                        'codigo' => $s->codigo,
                        'participante_id' => $id,
                        'lote_id' => $loteActual->id,
                        'plantilla_id' => $plantillaActual->id,
                        'version' => 1,
                        'reemplaza_id' => null,
                        'estado' => Emision::EMITIDA,
                        'participante_vigente' => $id,
                        'datos_snapshot' => $s->datos,
                        'diseno_snapshot' => $s->diseno,
                        'schema_version' => $s->schemaVersion,
                        'plantilla_pdf_hash' => $s->plantillaPdfHash,
                        'generador_snapshot' => $s->generador,
                        'pdf_archivo' => $g['final'],
                        'pdf_hash' => $g['hash'],
                        'pdf_bytes' => $g['bytes'],
                        'emitido_at' => now(),
                        'emitido_por' => $usuarioId,
                        'operacion' => $operacion,
                    ]);
                }

                // Un solo Movimiento por acción masiva, sin datos personales.
                Movimiento::registrar(
                    tipo: 'registro',
                    modulo: 'credential-flow',
                    descripcion: 'Se emitieron credenciales de un lote de Credential Flow',
                    extra: ['lote_id' => $loteActual->id, 'operacion' => $operacion, 'total' => count($creadas)],
                );

                return $creadas;
            });
        } catch (UniqueConstraintViolationException) {
            $this->limpiar($movidos);
            throw new EmisionException(EmisionException::EMISION_YA_VIGENTE, 'Alguno de los participantes ya fue emitido por otra operación. No se emitió nada: vuelve a intentarlo.', 409);
        } catch (Throwable $e) {
            $this->limpiar($movidos);
            if (! $e instanceof EmisionException && ! $e instanceof GeneracionCredencialException) {
                Log::error('Credential Flow: error en la emisión masiva', ['lote' => $lote->id, 'error' => $e::class.': '.$e->getMessage()]);
            }

            throw $e;
        } finally {
            AlmacenEmisiones::borrarDirectorio($staging);
        }

        return [
            'operacion' => $operacion,
            'total' => count($emisiones),
            'emisiones' => array_map(fn (Emision $e) => $e->id, $emisiones),
            'restantes' => $lote->participantes()->whereDoesntHave('emisiones')->count(),
        ];
    }

    /** @param  array<int,string>  $movidos */
    private function limpiar(array $movidos): void
    {
        foreach ($movidos as $ruta) {
            AlmacenEmisiones::borrar($ruta);
        }
    }

    private function cambiaron(): EmisionException
    {
        return new EmisionException(EmisionException::DATOS_CAMBIARON_DURANTE_EMISION, 'Los datos cambiaron mientras se emitía el lote. No se emitió nada: vuelve a intentarlo.', 409);
    }
}
