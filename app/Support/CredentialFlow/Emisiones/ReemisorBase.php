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
use App\Support\CredentialFlow\Programacion\CandadoProgramacion;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reemisión MASIVA y controlada de los certificados VIGENTES de una base que quedaron DESACTUALIZADOS (su PDF ya no corresponde a la plantilla o a los datos
 * actuales: por ejemplo, tras corregir el nombre del evento o tras un cambio autorizado de plantilla).
 *
 * Por qué no reutiliza EmisorLote: ese servicio emite solo a quien NO tiene ninguna emisión (versión 1, sin reemplazo) y exige que nadie esté ya certificado en
 * el evento; aquí cada certificado nuevo REEMPLAZA al vigente. De EmisorCredencial::reemitir conserva exactamente las reglas de la reemisión individual
 * (versión siguiente, `reemplaza_id`, la anterior queda revocada con «Reemplazada por la versión N. Motivo: …», elegibilidad y tipo de documento para los
 * oficiales, vista previa, QA solo con su variante); de EmisorLote, la mecánica masiva segura (prevalidación total, staging, una transacción, todo o nada).
 *
 *  - Grupos separados: OFICIALES (plantilla de la base) y QA (variante de pruebas internas). Cada grupo es su propia operación todo-o-nada, de hasta 200.
 *  - «Desactualizado» = el diseño, el PDF base, la plantilla o los datos IMPRESOS que saldrían hoy difieren del snapshot de la emisión vigente. Lo ya
 *    reemitido deja de estarlo: repetir la operación (o reintentarla tras un fallo) nunca duplica versiones.
 *  - Más estricta que la reemisión individual: se bloquea con una emisión programada activa (candado del programador) y exige que los PDF anteriores estén
 *    íntegros antes de empezar. Los PDF anteriores NUNCA se borran ni se modifican; se verifican otra vez al terminar, junto con los nuevos.
 *  - No envía correos. El portal solo muestra la emisión vigente (la nueva); la anterior queda como versión reemplazada en el historial.
 */
final class ReemisorBase
{
    public const OFICIALES = 'oficiales';

    public const QA = 'qa';

    public const LIMITE = EmisorLote::LIMITE;

    public const MOTIVO_MIN = 10;

    public const MOTIVO_MAX = 500;

    public function __construct(private readonly ServicioVistaPrevia $vistaPrevia, private readonly ServicioQa $qa) {}

    /**
     * Describe, SIN escribir nada, qué se reemitiría de un grupo y por qué. `bloqueo` dice por qué no se podría ejecutar ahora (programación activa, vista previa
     * pendiente, variante QA faltante, datos o PDF que no se pueden generar, PDF anteriores dañados…).
     *
     * @return array{grupo:string, plantilla:?array{id:int, nombre:string}, vigentes:int, desactualizadas:int, a_reemitir:int, motivos:array<string,int>,
     *               emisiones:array<int,int>, bloqueo:?array{code:string, message:string, detalles:array<int,array<string,mixed>>}, huella:string}
     */
    public function planear(Lote $lote, string $grupo): array
    {
        $this->exigirGrupo($grupo);
        $plantilla = $this->plantillaDe($lote, $grupo);
        $vigentes = $this->vigentes($lote, $grupo);

        $motivos = ['plantilla' => 0, 'diseno' => 0, 'pdf_base' => 0, 'datos' => 0];
        $desactualizadas = [];
        $bloqueo = null;
        if ($plantilla !== null) {
            try {
                [, $hashBase] = SnapshotCredencial::cargarPlantilla($plantilla);
                foreach ($vigentes as $emision) {
                    $razones = $this->razones($emision, $lote, $plantilla, $hashBase);
                    foreach ($razones as $r) {
                        $motivos[$r]++;
                    }
                    if ($razones !== []) {
                        $desactualizadas[] = $emision;
                    }
                }
            } catch (GeneracionCredencialException $e) {
                $bloqueo = ['code' => $e->codigo, 'message' => $e->getMessage(), 'detalles' => []];
            }
        }
        $aReemitir = array_slice($desactualizadas, 0, self::LIMITE);

        if ($bloqueo === null && $aReemitir !== []) {
            try {
                $this->exigirEjecutable($lote, $grupo, $plantilla, new Collection($aReemitir));
            } catch (EmisionException|GeneracionCredencialException $e) {
                $bloqueo = ['code' => $e->codigo, 'message' => $e->getMessage(), 'detalles' => $e instanceof EmisionException ? $e->detalles : []];
            }
        }

        $ids = array_map(fn (Emision $e) => $e->id, $aReemitir);

        return [
            'grupo' => $grupo,
            'plantilla' => $plantilla === null ? null : ['id' => $plantilla->id, 'nombre' => (string) $plantilla->nombre],
            'vigentes' => $vigentes->count(),
            'desactualizadas' => count($desactualizadas),
            'a_reemitir' => count($aReemitir),
            'motivos' => $motivos,
            'emisiones' => $ids,
            'bloqueo' => $bloqueo,
            // Lo que el administrador revisó: base y su plantilla, plantilla del grupo (diseño + PDF base) y CADA certificado a reemplazar (id + huella de su PDF).
            'huella' => hash('sha256', json_encode([
                $lote->id, (int) $lote->plantilla_id, $grupo, $plantilla?->id, $plantilla && ServicioVistaPrevia::plantillaValida($plantilla) ? ServicioVistaPrevia::huella($plantilla) : null,
                array_map(fn (Emision $e) => [$e->id, $e->pdf_hash], $aReemitir),
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Reemite el grupo SOLO si el plan recalculado coincide con la huella simulada. Todo o nada: si algo falla no queda ninguna versión nueva, ningún certificado
     * revocado ni ningún archivo nuevo, y los PDF anteriores siguen intactos.
     *
     * @return array{grupo:string, operacion:string, total:int, reemplazos:array<int,int>, restantes:int, integridad:array{nuevas:int, anteriores:int, fallidas:array<int,int>}}
     *
     * @throws EmisionException|GeneracionCredencialException
     */
    public function reemitir(Lote $lote, string $grupo, string $huellaSimulada, string $motivo, ?int $usuarioId): array
    {
        $motivo = $this->exigirMotivo($motivo);
        $plan = $this->planear($lote, $grupo);
        if (! hash_equals($plan['huella'], $huellaSimulada)) {
            throw $this->planCambio();
        }
        if ($plan['bloqueo'] !== null) {
            throw new EmisionException($plan['bloqueo']['code'], $plan['bloqueo']['message'], 409, $plan['bloqueo']['detalles']);
        }
        if ($plan['emisiones'] === []) {
            throw new EmisionException(EmisionException::SIN_DESACTUALIZADAS, 'No hay certificados vigentes desactualizados en este grupo: no hay nada que reemitir.', 409);
        }

        $plantilla = $this->plantillaDe($lote, $grupo);
        $anteriores = Emision::query()->whereIn('id', $plan['emisiones'])->orderBy('id')->get();
        $participantes = Participante::query()->whereIn('id', $anteriores->pluck('participante_id'))->get()->keyBy('id');

        // 1–2: snapshots + huellas y prevalidación total (todo o nada). Nada se escribe todavía.
        [$base, $snapshots] = $this->prevalidar($lote, $plantilla, $anteriores->map(fn (Emision $e) => $participantes[$e->participante_id]));

        // 3: espacio y generación en staging, fuera de cualquier transacción.
        EspacioDisco::exigir(EspacioDisco::estimarEmisiones($anteriores->count(), strlen($base)), AlmacenEmisiones::disco()->path(''));
        $operacion = (string) Str::uuid();
        $staging = AlmacenEmisiones::directorioStaging($operacion);
        $generados = [];
        $movidos = [];

        try {
            foreach ($anteriores as $i => $anterior) {
                $snapshot = $snapshots[$anterior->participante_id];
                $bytes = GeneradorCredencialPdf::generarDesde($snapshot->diseno, $base, $snapshot->datosCredencial(), $snapshot->schemaVersion, ['participante' => $anterior->participante_id], $snapshot->urlVerificacion());
                $rutaStaging = AlmacenEmisiones::rutaStaging($operacion, $i);
                AlmacenEmisiones::guardarStaging($rutaStaging, $bytes);
                $generados[$anterior->id] = ['staging' => $rutaStaging, 'hash' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'final' => AlmacenEmisiones::rutaNueva()];
                unset($bytes);
                gc_collect_cycles(); // como en EmisorLote: sin esto la memoria crece con cada render de FPDI/TCPDF
            }

            // 4–5: fase crítica. Orden de bloqueo: base → participantes → emisiones (el mismo de emitir, reemitir y revocar).
            $reemplazos = DB::transaction(function () use ($lote, $grupo, $plantilla, $anteriores, $snapshots, $generados, $operacion, $motivo, $usuarioId, $plan, &$movidos) {
                $loteActual = Lote::lockForUpdate()->find($lote->id);
                $plantillaActual = Plantilla::find($plantilla->id);
                $ids = $anteriores->pluck('participante_id')->all();
                $bloqueados = Participante::query()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
                $vigentes = Emision::query()->whereIn('id', $anteriores->pluck('id'))->lockForUpdate()->get()->keyBy('id');
                if (! $loteActual || ! $plantillaActual || $bloqueados->count() !== count($ids) || $vigentes->count() !== $anteriores->count()) {
                    throw $this->cambiaron();
                }
                CandadoProgramacion::exigirLibre($loteActual->id);
                if ($grupo === self::OFICIALES) {
                    $this->vistaPrevia->exigir($loteActual);
                }
                foreach ($anteriores as $a) {
                    $actual = $vigentes[$a->id];
                    // Otro administrador pudo reemitirla o revocarla mientras se generaban los PDF: entonces no se reemite nada.
                    if (! $actual->vigente() || (int) $actual->lote_id !== $loteActual->id || ! hash_equals((string) $actual->pdf_hash, (string) $a->pdf_hash)
                        || ! hash_equals($snapshots[$a->participante_id]->huella, SnapshotCredencial::huella($bloqueados[$a->participante_id], $loteActual, $plantillaActual))) {
                        throw $this->cambiaron();
                    }
                }
                $versiones = Emision::query()->whereIn('participante_id', $ids)->groupBy('participante_id')->selectRaw('participante_id, MAX(version) AS maxima')->pluck('maxima', 'participante_id');

                $reemplazos = [];
                foreach ($anteriores as $a) {
                    $anterior = $vigentes[$a->id];
                    $g = $generados[$a->id];
                    $s = $snapshots[$a->participante_id];
                    $version = (int) $versiones[$a->participante_id] + 1;

                    // La vigente anterior se libera ANTES de insertar (índice único participante_vigente), con el mismo texto que la reemisión individual.
                    $anterior->update(['estado' => Emision::REVOCADA, 'participante_vigente' => null, 'revocado_at' => now(), 'revocado_por' => $usuarioId,
                        'motivo_revocacion' => "Reemplazada por la versión {$version}. Motivo: {$motivo}"]);

                    AlmacenEmisiones::mover($g['staging'], $g['final']);
                    $movidos[] = $g['final'];

                    $nueva = Emision::create([
                        'codigo' => $s->codigo,
                        'participante_id' => $a->participante_id,
                        'lote_id' => $loteActual->id,
                        'plantilla_id' => $plantillaActual->id,
                        'version' => $version,
                        'reemplaza_id' => $anterior->id,
                        'estado' => Emision::EMITIDA,
                        'participante_vigente' => $a->participante_id,
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
                    $reemplazos[$anterior->id] = $nueva->id;
                }

                // Un solo Movimiento por operación, sin datos personales: qué base, qué grupo, con qué plantilla, por qué y qué versión reemplazó a cuál.
                Movimiento::registrar(
                    tipo: 'registro',
                    modulo: 'credential-flow',
                    descripcion: 'Se reemitieron credenciales de una base de Credential Flow (reemisión masiva controlada)',
                    extra: ['lote_id' => $loteActual->id, 'grupo' => $grupo, 'operacion' => $operacion, 'total' => count($reemplazos), 'plantilla_id' => $plantillaActual->id,
                        'motivo' => $motivo, 'autorizado_por' => $usuarioId, 'huella_plan' => $plan['huella'], 'reemplazos' => $reemplazos],
                );

                return $reemplazos;
            });
        } catch (UniqueConstraintViolationException) {
            $this->limpiar($movidos);
            throw $this->cambiaron();
        } catch (Throwable $e) {
            $this->limpiar($movidos);
            if (! $e instanceof EmisionException && ! $e instanceof GeneracionCredencialException) {
                Log::error('Credential Flow: error en la reemisión masiva', ['lote' => $lote->id, 'grupo' => $grupo, 'error' => LogSeguro::resumen($e)]);
            }

            throw $e;
        } finally {
            AlmacenEmisiones::borrarDirectorio($staging);
        }

        // 6: integridad DESPUÉS: cada PDF nuevo y cada PDF reemplazado, contra su huella registrada (sin cargarlos en memoria). La reemisión ya quedó guardada:
        // un fallo aquí no se oculta ni corta el informe, se reporta para investigarlo (credential-flow:verificar-emisiones).
        $integridad = ['nuevas' => 0, 'anteriores' => 0, 'fallidas' => []];
        foreach (Emision::query()->whereIn('id', array_merge(array_keys($reemplazos), array_values($reemplazos)))->get() as $e) {
            try {
                AlmacenEmisiones::rutaFisicaVerificada($e);
                $integridad[isset($reemplazos[$e->id]) ? 'anteriores' : 'nuevas']++;
            } catch (EmisionException) {
                $integridad['fallidas'][] = $e->id;
            }
        }

        return [
            'grupo' => $grupo,
            'operacion' => $operacion,
            'total' => count($reemplazos),
            'reemplazos' => $reemplazos,
            'restantes' => $this->planear($lote->fresh(), $grupo)['desactualizadas'],
            'integridad' => $integridad,
        ];
    }

    // ── Internos ──────────────────────────────────────────────────────────────

    /** Plantilla con la que se reemite el grupo: la de la base para los oficiales; SOLO la variante de pruebas internas para QA. */
    private function plantillaDe(Lote $lote, string $grupo): ?Plantilla
    {
        return $grupo === self::QA ? $this->qa->plantillaPara($lote) : $lote->plantilla;
    }

    /** @return Collection<int,Emision> vigentes del grupo, en orden */
    private function vigentes(Lote $lote, string $grupo): Collection
    {
        return Emision::query()->where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)
            ->whereIn('participante_id', Participante::withTrashed()->where('lote_id', $lote->id)->where('es_control_qa', $grupo === self::QA)->select('id'))
            ->orderBy('id')->get();
    }

    /**
     * Por qué el PDF vigente ya no corresponde a lo que saldría hoy (vacío = está al día). Solo cuenta lo que se IMPRIME: el nombre administrativo de la base o de
     * la plantilla no hace reemitir nada.
     *
     * @return array<int,string>
     */
    private function razones(Emision $emision, Lote $lote, Plantilla $plantilla, string $hashBase): array
    {
        $razones = [];
        if ((int) $emision->plantilla_id !== $plantilla->id) {
            $razones[] = 'plantilla';
        }
        if (self::canonico($emision->diseno_snapshot) !== self::canonico($plantilla->diseno) || (int) $emision->schema_version !== (int) $plantilla->schema_version) {
            $razones[] = 'diseno';
        }
        if (! hash_equals((string) $emision->plantilla_pdf_hash, $hashBase)) {
            $razones[] = 'pdf_base';
        }
        $participante = Participante::withTrashed()->find($emision->participante_id);
        $impresos = fn (array $d) => array_diff_key($d, ['lote_nombre' => 1, 'plantilla_nombre' => 1]);
        if ($participante !== null && self::canonico($impresos(SnapshotCredencial::capturar($participante, $lote, $plantilla, $hashBase)->datos)) !== self::canonico($impresos((array) $emision->datos_snapshot))) {
            $razones[] = 'datos';
        }

        return $razones;
    }

    /**
     * Todo lo que haría fallar la ejecución, comprobado antes de generar nada: candado del programador, vista previa (oficiales), participantes disponibles y
     * certificables, datos que imprime el diseño, plan de cada PDF y PDF anteriores íntegros.
     *
     * @param  Collection<int,Emision>  $emisiones
     *
     * @throws EmisionException|GeneracionCredencialException
     */
    private function exigirEjecutable(Lote $lote, string $grupo, ?Plantilla $plantilla, Collection $emisiones): void
    {
        CandadoProgramacion::exigirLibre($lote->id);
        if ($plantilla === null) {
            throw $grupo === self::QA
                ? new EmisionException(EmisionException::VARIANTE_QA_FALTANTE, 'La plantilla de esta base no tiene su variante de pruebas internas (QA): las pruebas no se reemiten con el diseño de otra capacitación.', 409)
                : new EmisionException(EmisionException::PLANTILLA_NO_DISPONIBLE, 'La plantilla de esta base ya no está disponible.', 409);
        }
        if ($grupo === self::OFICIALES) {
            $this->vistaPrevia->exigir($lote);
        }

        $participantes = Participante::withTrashed()->whereIn('id', $emisiones->pluck('participante_id'))->get();
        $fallos = [];
        foreach ($participantes as $p) {
            $codigo = match (true) {
                $p->trashed() || (int) $p->lote_id !== $lote->id => EmisionException::PARTICIPANTE_NO_DISPONIBLE,
                ($p->elegibilidad ?? Participante::ELEGIBLE) !== Participante::ELEGIBLE => EmisionException::NO_ELEGIBLE,
                $grupo === self::OFICIALES && ! ($p->tipo_documento_confirmado ?? true) => EmisionException::TIPO_DOCUMENTO_PENDIENTE,
                default => null,
            };
            if ($codigo !== null) {
                $fallos[] = ['participante_id' => $p->id, 'nombre' => $p->nombre_completo, 'codigo' => $codigo, 'mensaje' => 'Este participante no se puede reemitir en masa (revísalo y decide individualmente).'];
            }
        }
        if ($fallos !== []) {
            throw new EmisionException(EmisionException::PREVALIDACION_FALLIDA, 'Hay '.count($fallos).' certificado(s) que no se pueden reemitir. No se reemitió ninguno.', 422, $fallos);
        }

        DatosRequeridos::exigir($lote, $plantilla, $participantes);
        $this->prevalidar($lote, $plantilla, $participantes);

        // Los PDF que se van a reemplazar deben estar íntegros: se conservan como versión anterior y nunca se reemplaza un archivo dañado sin investigarlo.
        foreach ($emisiones as $e) {
            AlmacenEmisiones::rutaFisicaVerificada($e);
        }
    }

    /**
     * Snapshot y plan de texto/QR de cada participante, sin generar el PDF (la misma prevalidación que EmisorLote::prevalidar). Si uno falla, no sale ninguno.
     *
     * @param  iterable<int,Participante>  $participantes
     * @return array{0:string, 1:array<int,SnapshotCredencial>}
     *
     * @throws EmisionException|GeneracionCredencialException
     */
    private function prevalidar(Lote $lote, Plantilla $plantilla, iterable $participantes): array
    {
        [$base, $hashPlantilla] = SnapshotCredencial::cargarPlantilla($plantilla);
        $pagina = GeneradorCredencialPdf::tamanoPagina($base, ['plantilla' => $plantilla->id]);

        $snapshots = [];
        $fallos = [];
        foreach ($participantes as $participante) {
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
            throw new EmisionException(EmisionException::PREVALIDACION_FALLIDA, 'Hay '.count($fallos).' certificado(s) que no se pueden generar. No se reemitió ninguno.', 422, $fallos);
        }

        return [$base, $snapshots];
    }

    private function exigirGrupo(string $grupo): void
    {
        if (! in_array($grupo, [self::OFICIALES, self::QA], true)) {
            throw new EmisionException('GRUPO_INVALIDO', 'El grupo debe ser «oficiales» o «qa».', 422);
        }
    }

    private function exigirMotivo(string $motivo): string
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < self::MOTIVO_MIN || mb_strlen($motivo) > self::MOTIVO_MAX) {
            throw new EmisionException('MOTIVO_INVALIDO', 'El motivo debe tener entre '.self::MOTIVO_MIN.' y '.self::MOTIVO_MAX.' caracteres.', 422);
        }

        return $motivo;
    }

    /** JSON canónico (claves ordenadas en todos los niveles) para comparar snapshots sin depender del orden en que se guardaron. */
    private static function canonico(mixed $valor): string
    {
        $ordenar = function (mixed $v) use (&$ordenar): mixed {
            if (! is_array($v)) {
                return $v;
            }
            $o = array_map($ordenar, $v);
            if (! array_is_list($o)) {
                ksort($o);
            }

            return $o;
        };

        return json_encode($ordenar($valor), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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
        return new EmisionException(EmisionException::DATOS_CAMBIARON_DURANTE_EMISION, 'Los certificados o sus datos cambiaron mientras se reemitían (otra operación o un administrador). No se reemitió nada: vuelve a simular.', 409);
    }

    private function planCambio(): EmisionException
    {
        return new EmisionException(EmisionException::PLAN_DESACTUALIZADO, 'Los certificados, la base o la plantilla cambiaron desde la simulación. No se reemitió nada: vuelve a simular.', 409);
    }
}
