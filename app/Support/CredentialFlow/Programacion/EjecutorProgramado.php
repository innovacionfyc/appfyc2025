<?php

namespace App\Support\CredentialFlow\Programacion;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EmisionProgramada;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Models\Usuario;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use App\Support\CredentialFlow\LogSeguro;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ejecutor automático de las emisiones programadas (lo corre el cron cada minuto con `credential-flow:emisiones-programadas`). No tiene sesión web: emite en
 * nombre de quien APROBÓ la programación (`confirmada_por`), revisando HOY en la base de datos que ese usuario siga activo y con rol super-admin o admin.
 *
 * Cada corrida, con el interruptor general encendido:
 *  1. Latido (para que la interfaz sepa que el programador está vivo).
 *  2. Recupera las ejecuciones interrumpidas (proceso caído o servidor reiniciado: sin latido hace más de 10 minutos): vuelven a «pendiente» y se retoman SIN
 *     repetir lo ya emitido (las emisiones existentes no se tocan y solo se emite a quien no tiene ninguna). Tras 3 intentos quedan «fallida».
 *  3. Las pendientes con más de `tolerancia_horas` de atraso (servidor detenido) quedan «fallida» (VENCIDA_SIN_EJECUTAR): nunca emiten solas tan tarde.
 *  4. Reserva de forma ATÓMICA (UPDATE … WHERE estado = 'pendiente') las vencidas dentro de la tolerancia, con un boleto privado (EjecucionAutorizada): aunque
 *     corran dos procesos a la vez, cada programación la ejecuta uno solo.
 *  5. Antes de empezar y antes de CADA operación de hasta 200: permiso de quien aprobó, base, variante QA, vista previa y huella (contando como aprobado lo
 *     que emitió el propio ejecutor). Si algo cambió: «requiere_confirmacion» (no emite nada más; la base sigue reservada). Estima la memoria antes de generar.
 *  6. Emite con la MISMA EmisorLote::emitirTodo de «Emitir certificados» (oficiales + QA, todo o nada por operación, sin duplicados) hasta que no queden
 *     pendientes. Resultado: completada, completada con avisos (oficiales emitidos y pruebas QA pendientes), fallida (con `parcial` si algo alcanzó a salir) o
 *     requiere_confirmacion.
 *  7. Reintenta SOLO lo transitorio (espacio en disco, escritura, datos cambiados durante la emisión, interbloqueo de la base): hasta 3 intentos, a los 2 y 5
 *     minutos. Lo permanente (prevalidación, plantilla, duplicado en el evento, error desconocido…) falla de inmediato.
 *
 * Con `simular` no escribe NADA (ni latido, ni reservas, ni Movimientos) y no genera ningún PDF: solo dice qué haría. Nunca guarda datos personales: el resultado
 * son cifras, códigos y operaciones.
 */
final class EjecutorProgramado
{
    public const MAX_INTENTOS = 3;

    public const ESTANCADA_MINUTOS = 10;

    /** Espera antes del siguiente intento, según cuántos van. */
    public const ESPERAS_MINUTOS = [1 => 2, 2 => 5];

    public const LATIDO = 'credential-flow:programador:latido';

    /** Errores de la emisión que se reintentan (todo lo demás es permanente). */
    public const TRANSITORIOS = [EmisionException::ESPACIO_INSUFICIENTE, EmisionException::ERROR_ESCRITURA, EmisionException::DATOS_CAMBIARON_DURANTE_EMISION,
        EmisionException::EMISION_YA_VIGENTE, self::BASE_DE_DATOS_OCUPADA];

    public const VENCIDA = 'VENCIDA_SIN_EJECUTAR';

    public const INTERRUMPIDA = 'INTERRUMPIDA';

    public const CAMBIOS = 'CAMBIOS_SIN_CONFIRMAR';

    public const PERMISO = 'PERMISO_NO_VIGENTE';

    public const MEMORIA = 'MEMORIA_INSUFICIENTE';

    public const BASE_NO_DISPONIBLE = 'BASE_NO_DISPONIBLE';

    public const SIN_AVANCE = 'SIN_AVANCE';

    public const BASE_DE_DATOS_OCUPADA = 'BASE_DE_DATOS_OCUPADA';

    public const INESPERADO = 'ERROR_INESPERADO';

    /** Solo pruebas: se ejecuta entre dos operaciones de una misma ejecución (para simular un cambio o una caída a mitad de camino). */
    public static ?Closure $entreOperaciones = null;

    public function __construct(private readonly EmisorLote $emisor) {}

    /** @return list<string> informe de la corrida (sin datos personales) */
    public function ejecutar(bool $simular = false, int $maximo = 5): array
    {
        if (! ServicioProgramacion::habilitada()) {
            return ['La emisión programada está desactivada (interruptor general apagado): no se hizo nada.'];
        }
        $ahora = CarbonImmutable::now();
        $informe = [];
        if (! $simular) {
            Cache::put(self::LATIDO, $ahora->toIso8601String(), $ahora->addDay());
            $this->limpiarStagingAbandonado();
        }

        $umbral = $ahora->subMinutes(self::ESTANCADA_MINUTOS)->format('Y-m-d H:i:s');
        foreach (EmisionProgramada::query()->where('estado', EmisionProgramada::EJECUTANDO)->where(fn ($q) => $q->whereNull('latido_at')->orWhere('latido_at', '<', $umbral))->get() as $p) {
            $informe[] = $simular ? "#{$p->id}: se retomaría una ejecución interrumpida." : $this->recuperar($p, $umbral);
        }

        $limite = $ahora->subHours((int) config('credential_flow.programacion.tolerancia_horas', 6))->format('Y-m-d H:i:s');
        foreach (EmisionProgramada::query()->where('estado', EmisionProgramada::PENDIENTE)->where('programada_para', '<', $limite)->get() as $p) {
            $informe[] = $simular ? "#{$p->id}: se marcaría vencida (más de la tolerancia sin ejecutarse)." : $this->vencer($p);
        }

        $vencidas = EmisionProgramada::query()->where('estado', EmisionProgramada::PENDIENTE)
            ->where('programada_para', '<=', $ahora->format('Y-m-d H:i:s'))->where('programada_para', '>=', $limite)
            ->where(fn ($q) => $q->whereNull('proximo_intento_at')->orWhere('proximo_intento_at', '<=', $ahora->format('Y-m-d H:i:s')))
            ->orderBy('programada_para')->orderBy('id')->limit(max(1, $maximo))->get();
        foreach ($vencidas as $p) {
            if ($simular) {
                $informe[] = $this->simular($p);

                continue;
            }
            $ejecucion = $this->reservar($p);
            $informe[] = $ejecucion === null ? "#{$p->id}: la reservó otro proceso." : $this->procesar($p->fresh(), $ejecucion);
        }

        return $informe === [] ? ['Sin emisiones programadas por ejecutar.'] : $informe;
    }

    /** Reserva ATÓMICA: solo un proceso pasa el UPDATE condicionado al estado. Devuelve el boleto o null si otro la reservó. */
    public function reservar(EmisionProgramada $p): ?EjecucionAutorizada
    {
        $ejecucion = EjecucionAutorizada::nueva($p->id);
        $ahora = now();
        $filas = $this->atomica(fn () => EmisionProgramada::query()->whereKey($p->id)->where('estado', EmisionProgramada::PENDIENTE)
            ->where(fn ($q) => $q->whereNull('proximo_intento_at')->orWhere('proximo_intento_at', '<=', $ahora->format('Y-m-d H:i:s')))
            ->update(['estado' => EmisionProgramada::EJECUTANDO, 'reclamo_hash' => $ejecucion->hash(), 'latido_at' => $ahora, 'proximo_intento_at' => null,
                'intentos' => DB::raw('intentos + 1'), 'version' => DB::raw('version + 1')]));
        if ($filas !== 1) {
            return null;
        }
        EmisionProgramada::query()->whereKey($p->id)->whereNull('iniciada_at')->update(['iniciada_at' => $ahora]);

        return $ejecucion;
    }

    // ── Una ejecución ─────────────────────────────────────────────────────────

    private function procesar(EmisionProgramada $p, EjecucionAutorizada $ejecucion): string
    {
        $qaFallo = false;
        try {
            $lote = Lote::query()->find($p->lote_id);
            if ($lote === null) {
                return $this->terminar($p, $ejecucion, EmisionProgramada::FALLIDA, self::BASE_NO_DISPONIBLE, 'La base ya no existe.');
            }
            if (($motivo = $this->motivoParaConfirmar($p, $lote)) !== null) {
                return $this->terminar($p, $ejecucion, EmisionProgramada::REQUIERE_CONFIRMACION, $motivo[0], $motivo[1], extra: ['cambios' => $motivo[2]]);
            }
            if (! $this->memoriaAlcanza($lote)) {
                return $this->terminar($p, $ejecucion, EmisionProgramada::FALLIDA, self::MEMORIA, 'La plantilla es demasiado pesada para la memoria del proceso automático. Usa una plantilla más liviana o pide aumentar el límite del comando.');
            }

            $tope = (int) ceil(($p->oficiales_esperados + $p->qa_esperados) / EmisorLote::LIMITE) + 3;
            for ($i = 0; ; $i++) {
                if (! $this->latido($p, $ejecucion)) {
                    return "#{$p->id}: otro proceso tomó esta ejecución; esta se detiene.";
                }
                [$oficiales, $qa] = $this->pendientes($lote);
                if ($oficiales === 0 && ($qa === 0 || $qaFallo)) {
                    break;
                }
                if ($i >= $tope) {
                    return $this->terminar($p, $ejecucion, EmisionProgramada::FALLIDA, self::SIN_AVANCE, 'La emisión no avanzó como se esperaba. Revisa la base antes de volver a intentarlo.');
                }
                if ($i > 0) {
                    if (self::$entreOperaciones !== null) {
                        (self::$entreOperaciones)($p);
                    }
                    // Antes de CADA operación: si algo cambió (o el permiso ya no vale), se detiene sin emitir más.
                    if (($motivo = $this->motivoParaConfirmar($p, $lote->fresh() ?? $lote)) !== null) {
                        return $this->terminar($p, $ejecucion, EmisionProgramada::REQUIERE_CONFIRMACION, $motivo[0], $motivo[1], extra: ['cambios' => $motivo[2]]);
                    }
                }
                try {
                    $r = $this->emisor->emitirTodo($lote->fresh() ?? $lote, (int) $p->confirmada_por, $ejecucion);
                } catch (EmisionException $e) {
                    if ($e->codigo === EmisionException::SIN_PENDIENTES) {
                        break;
                    }
                    throw $e;
                }
                $this->anotar($p, $ejecucion, $r);
                if ($r['qa_error'] !== null) {
                    $qaFallo = true;
                    $this->anotar($p, $ejecucion, null, $r['qa_error']['code']);
                }
                if ($r['oficiales'] === null && $r['qa'] === null) {
                    break;
                }
            }

            [$oficiales, $qa] = $this->pendientes($lote);
            if ($oficiales > 0) {
                return $this->terminar($p, $ejecucion, EmisionProgramada::FALLIDA, self::SIN_AVANCE, 'Quedaron certificados oficiales sin emitir. Revisa la base.');
            }

            return $qa > 0
                ? $this->terminar($p, $ejecucion, EmisionProgramada::COMPLETADA, 'QA_PENDIENTES', 'Los certificados oficiales se emitieron; las pruebas internas (QA) no. Reinténtalas con «Emitir pruebas internas».', conAvisos: true)
                : $this->terminar($p, $ejecucion, EmisionProgramada::COMPLETADA, null, null);
        } catch (EmisionException|GeneracionCredencialException $e) {
            return $this->fallo($p, $ejecucion, $e->codigo, $e->getMessage(), in_array($e->codigo, self::TRANSITORIOS, true));
        } catch (QueryException $e) {
            // Interbloqueo o espera de bloqueo agotada: la base de datos estaba ocupada; se reintenta. Cualquier otro error SQL es permanente.
            $ocupada = in_array((string) ($e->errorInfo[1] ?? ''), ['1205', '1213'], true) || ($e->errorInfo[0] ?? '') === '40001';
            Log::error('Credential Flow: error de base de datos en una emisión programada', ['programacion' => $p->id, 'error' => LogSeguro::resumen($e)]);

            return $this->fallo($p, $ejecucion, $ocupada ? self::BASE_DE_DATOS_OCUPADA : self::INESPERADO, $ocupada ? 'La base de datos estaba ocupada.' : 'Error inesperado de la base de datos.', $ocupada);
        } catch (Throwable $e) {
            Log::error('Credential Flow: error inesperado en una emisión programada', ['programacion' => $p->id, 'error' => LogSeguro::resumen($e)]);

            return $this->fallo($p, $ejecucion, self::INESPERADO, 'No se pudo completar la emisión programada por un error inesperado. Revisa el registro del servidor.', false);
        }
    }

    /**
     * ¿Hay que pedir confirmación antes de emitir? Permiso de quien aprobó (sin sesión: tal como está hoy en la base), variante QA y huella aprobada (contando como
     * aprobado lo que emitió el propio ejecutor). La huella incluye la base (y su estado «activa»), los participantes, la plantilla y la vista previa.
     *
     * @return array{0:string, 1:string, 2:list<string>}|null
     */
    private function motivoParaConfirmar(EmisionProgramada $p, Lote $lote): ?array
    {
        if (! ServicioProgramacion::autorizado(Usuario::query()->find($p->confirmada_por))) {
            return [self::PERMISO, 'Quien aprobó esta programación ya no está activo o no tiene permiso para emitir. Otra persona autorizada debe reprogramarla.', []];
        }
        $actual = HuellaProgramacion::calcular($lote, HuellaProgramacion::operacionesPropias($p));
        $cambios = HuellaProgramacion::diferencias((array) $p->huella_detalle, $actual['detalle']);
        if ($cambios !== [] || ! hash_equals((string) $p->huella, $actual['huella'])) {
            return [self::CAMBIOS, 'Cambió '.implode(', ', $cambios === [] ? ['la base'] : $cambios).' después de programar. No se emitió nada automáticamente: revisa y reprograma.', $cambios];
        }
        if (app(ServicioQa::class)->plantillaPara($lote) === null) {
            return [self::CAMBIOS, 'La plantilla ya no tiene su variante de pruebas internas (QA). Asóciala y reprograma.', ['variante_qa']];
        }

        return null;
    }

    /** @return array{0:int, 1:int} pendientes oficiales y QA (las mismas condiciones que la emisión) */
    private function pendientes(Lote $lote): array
    {
        $q = fn (bool $qa) => Participante::query()->where('lote_id', $lote->id)->where('es_control_qa', $qa)->where('elegibilidad', Participante::ELEGIBLE)
            ->where('tipo_documento_confirmado', true)->whereDoesntHave('emisiones')->count();

        return [$q(false), $q(true)];
    }

    private function memoriaAlcanza(Lote $lote): bool
    {
        $disco = AlmacenEmisiones::disco();
        $bytes = 0;
        foreach ([$lote->plantilla, app(ServicioQa::class)->plantillaPara($lote)] as $plantilla) {
            $ruta = $plantilla instanceof Plantilla ? ($plantilla->archivo_pdf ?: $plantilla->rutaPdfEsperada()) : null;
            if ($ruta !== null && $disco->exists($ruta)) {
                $bytes = max($bytes, (int) $disco->size($ruta));
            }
        }

        return EstimacionMemoria::alcanza($bytes);
    }

    // ── Escrituras de estado (todas condicionadas al boleto: un proceso que perdió la reserva no cambia nada) ──

    /**
     * Un UPDATE de estado condicionado (reserva, cierre, reintento, recuperación o vencimiento). Si dos procesos se cruzan sobre la misma fila, MySQL/MariaDB
     * puede resolverlo con un interbloqueo (1213) o una espera agotada (1205) en uno de ellos: se reintenta poco después y, si sigue ocupada, cuenta como «0 filas»
     * (otro proceso se encargó). Visto con procesos reales en Integracion\EmisionProgramadaConcurrenciaMysqlTest.
     *
     * @param  Closure(): int  $actualizar
     */
    private function atomica(Closure $actualizar): int
    {
        for ($intento = 1; ; $intento++) {
            try {
                return $actualizar();
            } catch (QueryException $e) {
                $bloqueo = in_array((string) ($e->errorInfo[1] ?? ''), ['1205', '1213'], true) || ($e->errorInfo[0] ?? '') === '40001';
                if (! $bloqueo) {
                    throw $e;
                }
                if ($intento >= 3) {
                    return 0;
                }
                usleep(random_int(50_000, 150_000));
            }
        }
    }

    /** Renueva el latido y dice si esta ejecución sigue siendo la dueña de la reserva. */
    private function latido(EmisionProgramada $p, EjecucionAutorizada $ejecucion): bool
    {
        $propia = fn () => EmisionProgramada::query()->whereKey($p->id)->where('estado', EmisionProgramada::EJECUTANDO)->where('reclamo_hash', $ejecucion->hash());
        // MySQL cuenta como 0 filas un UPDATE que no cambia nada (mismo segundo que el latido anterior): la pertenencia se comprueba aparte, no con ese número.
        $this->atomica(fn () => $propia()->update(['latido_at' => now()]));

        return $propia()->exists();
    }

    /** @param  array{oficiales:?array<string,mixed>, qa:?array<string,mixed>, qa_error:?array<string,string>}|null  $r */
    private function anotar(EmisionProgramada $p, EjecucionAutorizada $ejecucion, ?array $r, ?string $aviso = null): void
    {
        $actual = EmisionProgramada::query()->find($p->id);
        $res = (array) ($actual?->resultado ?? []);
        foreach (['oficiales', 'qa'] as $grupo) {
            if (isset($r[$grupo]['operacion'])) {
                $res['operaciones'][] = $r[$grupo]['operacion'];
            }
        }
        if ($aviso !== null) {
            $res['avisos'][] = $aviso;
        }
        $this->atomica(fn () => EmisionProgramada::query()->whereKey($p->id)->where('reclamo_hash', $ejecucion->hash())->update(['resultado' => json_encode($res), 'latido_at' => now()]));
    }

    /** @param  array<string,mixed>  $extra */
    private function terminar(EmisionProgramada $p, EjecucionAutorizada $ejecucion, string $estado, ?string $codigo, ?string $mensaje, bool $conAvisos = false, array $extra = []): string
    {
        $resultado = $this->resultado($p, $estado) + $extra;
        $final = $estado !== EmisionProgramada::REQUIERE_CONFIRMACION;
        $filas = $this->atomica(fn () => EmisionProgramada::query()->whereKey($p->id)->where('estado', EmisionProgramada::EJECUTANDO)->where('reclamo_hash', $ejecucion->hash())->update([
            'estado' => $estado, 'lote_activa' => $final ? null : $p->lote_id, 'reclamo_hash' => null, 'latido_at' => now(), 'terminada_at' => $final ? now() : null,
            'con_avisos' => $conAvisos, 'error_codigo' => $codigo, 'error_mensaje' => $mensaje === null ? null : mb_substr($mensaje, 0, 500),
            'resultado' => json_encode($resultado), 'version' => DB::raw('version + 1'),
        ]));
        if ($filas !== 1) {
            return "#{$p->id}: otro proceso tomó esta ejecución; esta se detiene.";
        }
        $texto = match (true) {
            $estado === EmisionProgramada::COMPLETADA && $conAvisos => 'completada con avisos',
            $estado === EmisionProgramada::COMPLETADA => 'completada',
            $estado === EmisionProgramada::REQUIERE_CONFIRMACION => 'requiere confirmación',
            default => 'fallida'.($resultado['parcial'] ? ' (parcial)' : ''),
        };
        $this->registrar("La emisión programada de una base terminó: {$texto}", $p, ['estado' => $estado, 'codigo' => $codigo] + $resultado);

        return "#{$p->id}: {$texto}. Oficiales {$resultado['oficiales_emitidos']}, QA {$resultado['qa_emitidos']}".($codigo !== null ? " ({$codigo})" : '').'.';
    }

    private function fallo(EmisionProgramada $p, EjecucionAutorizada $ejecucion, string $codigo, string $mensaje, bool $transitorio): string
    {
        $intentos = (int) (EmisionProgramada::query()->whereKey($p->id)->value('intentos') ?? self::MAX_INTENTOS);
        if (! $transitorio || $intentos >= self::MAX_INTENTOS) {
            return $this->terminar($p, $ejecucion, EmisionProgramada::FALLIDA, $codigo, $transitorio ? "{$mensaje} Se agotaron los ".self::MAX_INTENTOS.' intentos.' : $mensaje);
        }
        $espera = self::ESPERAS_MINUTOS[$intentos] ?? 5;
        $filas = $this->atomica(fn () => EmisionProgramada::query()->whereKey($p->id)->where('estado', EmisionProgramada::EJECUTANDO)->where('reclamo_hash', $ejecucion->hash())->update([
            'estado' => EmisionProgramada::PENDIENTE, 'reclamo_hash' => null, 'proximo_intento_at' => now()->addMinutes($espera)->format('Y-m-d H:i:s'),
            'error_codigo' => $codigo, 'error_mensaje' => mb_substr($mensaje, 0, 500), 'version' => DB::raw('version + 1'),
        ]));
        if ($filas !== 1) {
            return "#{$p->id}: otro proceso tomó esta ejecución; esta se detiene.";
        }
        $this->registrar('Una emisión programada tuvo un error transitorio y se reintentará', $p, ['codigo' => $codigo, 'intento' => $intentos, 'espera_minutos' => $espera]);

        return "#{$p->id}: error transitorio ({$codigo}); se reintentará en {$espera} minutos (intento {$intentos} de ".self::MAX_INTENTOS.').';
    }

    private function recuperar(EmisionProgramada $p, string $umbral): string
    {
        $agotado = $p->intentos >= self::MAX_INTENTOS;
        $campos = $agotado
            ? ['estado' => EmisionProgramada::FALLIDA, 'lote_activa' => null, 'terminada_at' => now(), 'resultado' => json_encode($this->resultado($p, EmisionProgramada::FALLIDA)),
                'error_codigo' => self::INTERRUMPIDA, 'error_mensaje' => 'La ejecución se interrumpió '.self::MAX_INTENTOS.' veces. Revisa la base antes de volver a intentarlo.']
            : ['estado' => EmisionProgramada::PENDIENTE, 'proximo_intento_at' => null,
                'error_codigo' => self::INTERRUMPIDA, 'error_mensaje' => 'La ejecución anterior se interrumpió; se retoma sin repetir lo ya emitido.'];
        $filas = $this->atomica(fn () => EmisionProgramada::query()->whereKey($p->id)->where('estado', EmisionProgramada::EJECUTANDO)
            ->where(fn ($q) => $q->whereNull('latido_at')->orWhere('latido_at', '<', $umbral))
            ->update($campos + ['reclamo_hash' => null, 'version' => DB::raw('version + 1')]));
        if ($filas !== 1) {
            return "#{$p->id}: la recuperó otro proceso.";
        }
        $this->registrar($agotado ? 'Una emisión programada se interrumpió demasiadas veces y quedó fallida' : 'Una emisión programada interrumpida se retomará', $p, ['codigo' => self::INTERRUMPIDA]);

        return "#{$p->id}: ejecución interrumpida ".($agotado ? 'demasiadas veces: fallida.' : 'recuperada; se retoma.');
    }

    private function vencer(EmisionProgramada $p): string
    {
        $horas = (int) config('credential_flow.programacion.tolerancia_horas', 6);
        $filas = $this->atomica(fn () => EmisionProgramada::query()->whereKey($p->id)->where('estado', EmisionProgramada::PENDIENTE)->update([
            'estado' => EmisionProgramada::FALLIDA, 'lote_activa' => null, 'reclamo_hash' => null, 'terminada_at' => now(),
            'resultado' => json_encode($this->resultado($p, EmisionProgramada::FALLIDA)), 'error_codigo' => self::VENCIDA,
            'error_mensaje' => "La hora programada pasó hace más de {$horas} horas sin poder ejecutarse (servidor detenido). No se emitió automáticamente: reprográmala.",
            'version' => DB::raw('version + 1'),
        ]));
        if ($filas !== 1) {
            return "#{$p->id}: ya no estaba pendiente.";
        }
        $this->registrar('Una emisión programada venció sin ejecutarse', $p, ['codigo' => self::VENCIDA]);

        return "#{$p->id}: vencida sin ejecutar (más de {$horas} horas de atraso).";
    }

    /** Solo lectura: qué haría con esta programación (sin reservar, sin escribir y sin generar ningún PDF). */
    private function simular(EmisionProgramada $p): string
    {
        $lote = Lote::query()->find($p->lote_id);
        if ($lote === null) {
            return "#{$p->id}: fallaría (la base ya no existe).";
        }
        if (($motivo = $this->motivoParaConfirmar($p, $lote)) !== null) {
            return "#{$p->id}: pediría confirmación ({$motivo[0]}: ".implode(', ', $motivo[2]).').';
        }
        if (! $this->memoriaAlcanza($lote)) {
            return "#{$p->id}: fallaría (".self::MEMORIA.').';
        }
        try {
            $v = $this->emisor->verificarEmitible($lote);
        } catch (EmisionException|GeneracionCredencialException $e) {
            return "#{$p->id}: fallaría ({$e->codigo}).";
        }
        $operaciones = max((int) ceil($v['oficiales'] / EmisorLote::LIMITE), (int) ceil($v['qa'] / EmisorLote::LIMITE), 1);

        return "#{$p->id}: emitiría {$v['oficiales']} oficiales + {$v['qa']} QA en {$operaciones} ".($operaciones === 1 ? 'operación' : 'operaciones').'.';
    }

    // ── Auxiliares ────────────────────────────────────────────────────────────

    /** @return array{operaciones:list<string>, oficiales_emitidos:int, qa_emitidos:int, avisos:list<string>, parcial:bool} cifras sin datos personales */
    private function resultado(EmisionProgramada $p, string $estado): array
    {
        $actual = EmisionProgramada::query()->find($p->id) ?? $p;
        $operaciones = HuellaProgramacion::operacionesPropias($actual);
        $emitidas = $operaciones === [] ? collect() : Emision::query()->join('cf_participantes as pa', 'pa.id', '=', 'cf_emisiones.participante_id')
            ->where('cf_emisiones.lote_id', $p->lote_id)->whereIn('cf_emisiones.operacion', $operaciones)->pluck('pa.es_control_qa');
        $qa = $emitidas->filter(fn ($v) => (bool) $v)->count();
        $oficiales = $emitidas->count() - $qa;

        return ['operaciones' => $operaciones, 'oficiales_emitidos' => $oficiales, 'qa_emitidos' => $qa,
            'avisos' => array_values(array_unique((array) ($actual->resultado['avisos'] ?? []))),
            'parcial' => $estado !== EmisionProgramada::COMPLETADA && ($oficiales + $qa) > 0];
    }

    /** @param  array<string,mixed>  $extra */
    private function registrar(string $descripcion, EmisionProgramada $p, array $extra): void
    {
        Movimiento::registrar(tipo: 'registro', modulo: 'credential-flow', descripcion: $descripcion, extra: [
            'origen' => 'ejecutor-programado', 'lote_id' => $p->lote_id, 'programacion_id' => $p->id, 'usuario_id' => $p->confirmada_por,
        ] + array_intersect_key($extra, array_flip(['estado', 'codigo', 'oficiales_emitidos', 'qa_emitidos', 'parcial', 'intento', 'espera_minutos'])));
    }

    /** Carpetas de staging de operaciones caídas (más de una hora): solo PDFs temporales que nunca llegaron a ser certificados. */
    private function limpiarStagingAbandonado(): void
    {
        $disco = AlmacenEmisiones::disco();
        $raiz = AlmacenEmisiones::RAIZ.'/.staging';
        if (! $disco->exists($raiz)) {
            return;
        }
        foreach ($disco->directories($raiz) as $dir) {
            $archivos = $disco->allFiles($dir);
            $reciente = collect($archivos)->contains(fn ($f) => $disco->lastModified($f) > now()->subHour()->getTimestamp());
            if (! $reciente) {
                AlmacenEmisiones::borrarDirectorio($dir);
            }
        }
    }
}
