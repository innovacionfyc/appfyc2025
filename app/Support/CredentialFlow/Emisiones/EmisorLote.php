<?php

namespace App\Support\CredentialFlow\Emisiones;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use App\Support\CredentialFlow\Generacion\PlanificadorQr;
use App\Support\CredentialFlow\Generacion\PlanificadorTexto;
use App\Support\CredentialFlow\LogSeguro;
use App\Support\CredentialFlow\Qa\ServicioQa;
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
        // Las cifras oficiales son de los participantes REALES; los de control (QA) van aparte y solo aparecen si la base los tiene.
        $reales = fn () => $lote->participantes()->where('es_control_qa', false);
        // «Pendiente de emitir» = elegible y sin emisión. Los pendientes de validación y los no elegibles NO entran a la emisión oficial.
        $pendientes = $reales()->where('elegibilidad', Participante::ELEGIBLE)->where('tipo_documento_confirmado', true)->whereDoesntHave('emisiones')->count();
        $tipoPendiente = $reales()->where('tipo_documento_confirmado', false)->whereDoesntHave('emisiones')->count();

        $r = [
            'participantes' => $reales()->count(),
            'vigentes' => Emision::where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)->whereIn('participante_id', $reales()->select('id'))->count(),
            'revocados' => $reales()->whereHas('emisiones')->whereDoesntHave('emisionVigente')->count(),
            'pendientes' => $pendientes,
            'a_emitir' => min($pendientes, self::LIMITE),
            'limite' => self::LIMITE,
        ];

        $enEspera = $reales()->where('elegibilidad', Participante::PENDIENTE)->whereDoesntHave('emisiones')->count();
        $noElegibles = $reales()->where('elegibilidad', Participante::NO_ELEGIBLE)->whereDoesntHave('emisiones')->count();
        if ($tipoPendiente > 0) {
            $r['tipo_pendiente'] = $tipoPendiente;
        }
        if ($enEspera > 0 || $noElegibles > 0) {
            $r['en_validacion'] = $enEspera;
            $r['no_elegibles'] = $noElegibles;
        }

        $qa = $lote->participantes()->where('es_control_qa', true);
        if ((clone $qa)->exists()) {
            $qaPendientes = (clone $qa)->whereDoesntHave('emisiones')->count();
            $r['qa'] = [
                // `participantes` = pruebas internas (filas QA: una por contacto y modalidad); `contactos` = personas distintas (los contactos oficiales).
                'participantes' => (clone $qa)->count(),
                'contactos' => (clone $qa)->distinct()->count('documento_clave'),
                'vigentes' => Emision::where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)->whereIn('participante_id', (clone $qa)->select('id'))->count(),
                'pendientes' => $qaPendientes,
                'a_emitir' => min($qaPendientes, self::LIMITE),
                // La plantilla con la que se emitirían las pruebas de ESTA base (su variante de prueba o la designada por omisión).
                'plantilla_lista' => app(ServicioQa::class)->plantillaPara($lote) !== null,
            ];
        }

        return $r;
    }

    /**
     * «Emitir certificados» en UN solo flujo: primero los OFICIALES pendientes (con la plantilla de la base) y después las PRUEBAS INTERNAS (QA) pendientes
     * (con la plantilla de prueba). Cada grupo es su propia operación todo-o-nada: nunca se mezclan.
     *
     *  - Si fallan los oficiales, se lanza el error y no se intenta QA (no se emitió nada).
     *  - Si fallan las pruebas QA, los oficiales ya emitidos se conservan y el resultado lo dice (`qa_error`): nunca se informa un éxito completo. Las pruebas
     *    se reintentan solas con «Emitir pruebas internas», sin tocar los oficiales.
     *  - Un grupo sin pendientes se omite (`null`): un segundo clic no duplica nada; una base ya emitida (oficiales completos) solo completa sus QA.
     *
     * @return array{oficiales: ?array<string,mixed>, qa: ?array<string,mixed>, qa_error: ?array{code:string, message:string}}
     *
     * @throws EmisionException|GeneracionCredencialException
     */
    public function emitirTodo(Lote $lote, ?int $usuarioId): array
    {
        $pendientes = fn (bool $qa) => $lote->participantes()->where('es_control_qa', $qa)->where('elegibilidad', Participante::ELEGIBLE)
            ->where('tipo_documento_confirmado', true)->whereDoesntHave('emisiones')->exists();
        $hayOficiales = $pendientes(false);
        $hayQa = $pendientes(true);
        if (! $hayOficiales && ! $hayQa) {
            throw new EmisionException(EmisionException::SIN_PENDIENTES, 'No hay certificados pendientes de emitir en esta base (ni oficiales ni pruebas internas).', 409);
        }

        $r = ['oficiales' => null, 'qa' => null, 'qa_error' => null];
        if ($hayOficiales) {
            $r['oficiales'] = $this->emitirPendientes($lote, $usuarioId);
        }
        if ($hayQa) {
            try {
                $r['qa'] = $this->emitirPendientes($lote->fresh(), $usuarioId, true);
            } catch (EmisionException|GeneracionCredencialException $e) {
                if ($r['oficiales'] === null) {
                    throw $e;   // no se emitió nada: es un error, no un resultado parcial
                }
                $r['qa_error'] = ['code' => $e->codigo, 'message' => $e->getMessage()];
            } catch (Throwable $e) {
                if ($r['oficiales'] === null) {
                    throw $e;
                }
                Log::error('Credential Flow: las pruebas internas (QA) no se emitieron tras la emisión oficial', ['lote' => $lote->id, 'error' => LogSeguro::resumen($e)]);
                $r['qa_error'] = ['code' => 'ERROR_INESPERADO', 'message' => 'Las pruebas internas no se pudieron emitir. Reinténtalo con «Emitir pruebas internas».'];
            }
        }

        return $r;
    }

    /**
     * @return array{operacion:string, total:int, emisiones:array<int,int>, restantes:int}
     *
     * Por defecto emite SOLO a los participantes REALES con la plantilla de la base. Con `$pruebasInternas` emite SOLO a los de control (QA) y SOLO con la
     * plantilla de pruebas internas («PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA»): nunca se mezclan ni se emite un certificado académico a un contacto QA.
     *
     * @throws EmisionException|GeneracionCredencialException
     */
    public function emitirPendientes(Lote $lote, ?int $usuarioId, bool $pruebasInternas = false): array
    {
        $plantilla = $pruebasInternas ? app(ServicioQa::class)->plantillaPara($lote) : $lote->plantilla;
        if (! $plantilla) {
            throw new EmisionException(
                EmisionException::PLANTILLA_NO_DISPONIBLE,
                $pruebasInternas ? 'No hay una plantilla de pruebas internas (QA) designada. Márcala en Plantillas antes de emitir las pruebas.' : 'La plantilla de esta base ya no está disponible.',
                409
            );
        }

        $pendientes = $lote->participantes()->where('es_control_qa', $pruebasInternas)->where('elegibilidad', Participante::ELEGIBLE)->where('tipo_documento_confirmado', true)->whereDoesntHave('emisiones')->orderBy('id')->limit(self::LIMITE)->get();

        // Datos opcionales que el diseño imprime (modalidad, ciudad, textos): si faltan, se avisa qué completar antes de cualquier otro control.
        // La modalidad se mira por persona: la de la base solo hace falta para quien no tenga la suya.
        DatosRequeridos::exigir($lote, $plantilla, $pendientes);

        if (! $pruebasInternas) {
            app(ServicioVistaPrevia::class)->exigir($lote);
        }

        if ($pendientes->isEmpty()) {
            throw new EmisionException(EmisionException::SIN_PENDIENTES, $pruebasInternas ? 'No hay contactos de control (QA) pendientes de emitir en esta base.' : 'No hay participantes pendientes de emitir en esta base.', 409);
        }

        // Nadie se certifica dos veces en el mismo evento desde otra base (los QA nunca cuentan).
        if (! $pruebasInternas) {
            DuplicadosEnEvento::exigir($lote, $pendientes);
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
                PlanificadorQr::planificar($snapshot->diseno, $pagina, $snapshot->schemaVersion);
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
                $bytes = GeneradorCredencialPdf::generarDesde($snapshot->diseno, $base, $snapshot->datosCredencial(), $snapshot->schemaVersion, ['participante' => $participante->id], $snapshot->urlVerificacion());

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
                if ($bloqueados->contains(fn (Participante $p) => ! $p->es_control_qa)) {
                    DuplicadosEnEvento::exigir($loteActual, $bloqueados->values());   // otra base del evento pudo emitir mientras se generaban los PDF
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
                Log::error('Credential Flow: error en la emisión masiva', ['lote' => $lote->id, 'error' => LogSeguro::resumen($e)]);
            }

            throw $e;
        } finally {
            AlmacenEmisiones::borrarDirectorio($staging);
        }

        return [
            'operacion' => $operacion,
            'total' => count($emisiones),
            'emisiones' => array_map(fn (Emision $e) => $e->id, $emisiones),
            'restantes' => $lote->participantes()->where('es_control_qa', $pruebasInternas)->where('elegibilidad', Participante::ELEGIBLE)->whereDoesntHave('emisiones')->count(),
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
        return new EmisionException(EmisionException::DATOS_CAMBIARON_DURANTE_EMISION, 'Los datos cambiaron mientras se emitían los certificados. No se emitió nada: vuelve a intentarlo.', 409);
    }
}
