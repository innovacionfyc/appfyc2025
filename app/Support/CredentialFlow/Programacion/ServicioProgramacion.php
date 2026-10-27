<?php

namespace App\Support\CredentialFlow\Programacion;

use App\Models\CredentialFlow\EmisionProgramada;
use App\Models\CredentialFlow\Lote;
use App\Models\Movimiento;
use App\Models\Usuario;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Programar, reprogramar, reconfirmar y cancelar la emisión de una base para una hora exacta de Colombia. Este servicio NO emite: deja la programación lista y
 * aprobada; el ejecutor automático (P2) la correrá con la misma `EmisorLote::emitirTodo` de siempre.
 *
 * Reglas:
 *  - Interruptor general apagado por defecto (`credential_flow.programacion.activa`). Apagado no se programa ni reprograma; cancelar se puede siempre.
 *  - Solo super-admin y admin (los mismos roles que pueden emitir). Nada de sesiones ni tokens: se guarda solo el id de quien programa.
 *  - La base debe existir y estar «activa», su plantilla debe tener SIEMPRE su variante de pruebas internas válida, y la emisión debe pasar HOY todas sus
 *    validaciones (`EmisorLote::verificarEmitible`).
 *  - «requiere_confirmacion» (lo deja el ejecutor si algo cambió o el permiso ya no vale) se resuelve igual que una pendiente: reprogramar, reconfirmar o cancelar.
 *  - Una sola programación activa por base: base bloqueada (lockForUpdate) + índice único `lote_activa` como barrera final ante dos administradores a la vez.
 *  - Se guarda la HUELLA de lo aprobado (base, participantes, plantilla, variante QA y vista previa). Si después cambia algo, la programación lo muestra
 *    (`cambios`) y exige reconfirmar: el ejecutor nunca emitirá en silencio con datos distintos.
 *  - Cambios concurrentes: cada operación sobre una programación existente puede exigir la `version` que vio el administrador.
 *
 * Orden de bloqueo (igual que la emisión): base → programación. Las validaciones costosas (prevalidar cada PDF) van ANTES del bloqueo; dentro, se vuelve a calcular
 * la huella (barata) y, si cambió entre la validación y el bloqueo, no se guarda nada.
 *
 * DÓNDE PUEDE QUEDAR UNA EJECUCIÓN PARCIAL (lo debe manejar el ejecutor, P2; el índice único de emisiones evita DUPLICADOS, pero NO hace seguro un reintento
 * por sí solo, porque entre intentos pueden cambiar los datos):
 *  1. Oficiales emitidos y QA fallidas: `emitirTodo` conserva los oficiales y devuelve `qa_error` (resultado «con avisos», no éxito completo).
 *  2. Más de `EmisorLote::LIMITE` (200) pendientes: cada operación es todo o nada, pero la operación k puede quedar hecha y la k+1 fallar.
 *  3. Caída entre el commit de una operación y la actualización de la programación: las emisiones existen y la programación sigue «ejecutando»; se
 *     reconcilia contando las emisiones de la base posteriores a `iniciada_at`.
 *  4. Caída después de mover los PDF y antes del commit: archivos sin fila (residuos, nunca certificados); los barre una limpieza.
 * Por eso, antes de CADA operación (y de cada reintento) el ejecutor debe volver a comparar la huella aprobada: si cambió, se detiene y pide reconfirmar.
 */
final class ServicioProgramacion
{
    public const ROLES = ['super-admin', 'admin'];

    /** `estados.id` de «Activo» (EstadosSeeder). */
    public const USUARIO_ACTIVO = 1;

    public const MOTIVO_MIN = 5;

    public const MOTIVO_MAX = 255;

    /** Solo pruebas: se ejecuta justo antes de bloquear la base (para simular un cambio concurrente). */
    public static ?Closure $antesDeBloquear = null;

    public function __construct(private readonly EmisorLote $emisor) {}

    public static function habilitada(): bool
    {
        return (bool) config('credential_flow.programacion.activa', false);
    }

    /** Programación activa (pendiente o ejecutándose) de la base, o null. */
    public function activaDe(int $loteId): ?EmisionProgramada
    {
        return EmisionProgramada::query()->where('lote_activa', $loteId)->first();
    }

    /**
     * @throws ProgramacionException|EmisionException|GeneracionCredencialException
     */
    public function programar(Lote $lote, mixed $fechaHora, Usuario $usuario): EmisionProgramada
    {
        $this->exigirHabilitada();
        $this->exigirPermiso($usuario);
        $fecha = FechaProgramada::interpretar($fechaHora);
        FechaProgramada::exigirRango($fecha);
        [$verificacion, $huella] = $this->validarBase($lote->id);

        try {
            return $this->bloqueandoBase($lote->id, $huella, function () use ($lote, $fecha, $usuario, $verificacion, $huella) {
                if ($this->activaDe($lote->id) !== null) {
                    throw $this->yaExiste();
                }
                $ahora = now();
                $p = EmisionProgramada::create([
                    'lote_id' => $lote->id, 'lote_activa' => $lote->id, 'estado' => EmisionProgramada::PENDIENTE, 'version' => 1,
                    'programada_para' => FechaProgramada::paraGuardar($fecha), 'zona_horaria' => FechaProgramada::zona(),
                    'huella' => $huella['huella'], 'huella_detalle' => $huella['detalle'],
                    'oficiales_esperados' => $verificacion['oficiales'], 'qa_esperados' => $verificacion['qa'],
                    'plantilla_id' => $verificacion['plantilla_id'], 'variante_qa_id' => $verificacion['variante_qa_id'],
                    'programada_por' => $usuario->id, 'confirmada_at' => $ahora, 'confirmada_por' => $usuario->id,
                ]);
                $this->registrar('registro', 'Se programó la emisión de una base de Credential Flow', $p, $usuario);

                return $p;
            });
        } catch (UniqueConstraintViolationException) {
            // Barrera final: otro administrador programó la misma base en el mismo instante.
            throw $this->yaExiste();
        }
    }

    /**
     * Cambia la hora de una programación PENDIENTE. Es también una nueva aprobación: vuelve a validar la base y guarda su huella actual.
     *
     * @throws ProgramacionException|EmisionException|GeneracionCredencialException
     */
    public function reprogramar(EmisionProgramada $programacion, mixed $fechaHora, Usuario $usuario, ?int $versionEsperada = null): EmisionProgramada
    {
        $this->exigirHabilitada();
        $this->exigirPermiso($usuario);
        $fecha = FechaProgramada::interpretar($fechaHora);
        FechaProgramada::exigirRango($fecha);

        return $this->reaprobar($programacion, $usuario, $versionEsperada, $fecha);
    }

    /**
     * Acepta los cambios hechos en la base después de programar (misma hora): vuelve a validarla y guarda su huella actual. La hora debe seguir siendo futura.
     *
     * @throws ProgramacionException|EmisionException|GeneracionCredencialException
     */
    public function reconfirmar(EmisionProgramada $programacion, Usuario $usuario, ?int $versionEsperada = null): EmisionProgramada
    {
        $this->exigirHabilitada();
        $this->exigirPermiso($usuario);
        FechaProgramada::exigirRango(CarbonImmutable::parse($programacion->programada_para->format('Y-m-d H:i:s'), FechaProgramada::zona()));

        return $this->reaprobar($programacion, $usuario, $versionEsperada, null);
    }

    /**
     * Cancela una programación PENDIENTE y libera la base. Se puede aunque el interruptor esté apagado (nunca debe quedar una base bloqueada sin salida).
     *
     * @throws ProgramacionException
     */
    public function cancelar(EmisionProgramada $programacion, Usuario $usuario, mixed $motivo, ?int $versionEsperada = null): EmisionProgramada
    {
        $this->exigirPermiso($usuario);
        $motivo = is_string($motivo) ? trim(preg_replace('/\s+/u', ' ', $motivo) ?? '') : '';
        if (mb_strlen($motivo) < self::MOTIVO_MIN || mb_strlen($motivo) > self::MOTIVO_MAX) {
            throw new ProgramacionException(ProgramacionException::MOTIVO_INVALIDO, 'Escribe el motivo de la cancelación (entre '.self::MOTIVO_MIN.' y '.self::MOTIVO_MAX.' caracteres).', 422);
        }

        return DB::transaction(function () use ($programacion, $usuario, $motivo, $versionEsperada) {
            Lote::withTrashed()->lockForUpdate()->find($programacion->lote_id);
            $p = $this->bloquearModificable($programacion, $versionEsperada);
            $p->update([
                'estado' => EmisionProgramada::CANCELADA, 'lote_activa' => null, 'version' => $p->version + 1,
                'cancelada_at' => now(), 'cancelada_por' => $usuario->id, 'motivo_cancelacion' => $motivo,
            ]);
            $this->registrar('actualizacion', 'Se canceló la emisión programada de una base de Credential Flow', $p, $usuario);

            return $p->fresh();
        });
    }

    /**
     * Qué partes de la base cambiaron desde la última aprobación (vacío = nada). Solo lectura.
     *
     * @return list<string> partes de HuellaProgramacion::PARTES
     */
    public function cambios(EmisionProgramada $programacion): array
    {
        $lote = Lote::query()->find($programacion->lote_id);
        if ($lote === null) {
            return ['base'];
        }

        return HuellaProgramacion::diferencias((array) $programacion->huella_detalle, HuellaProgramacion::calcular($lote, HuellaProgramacion::operacionesPropias($programacion))['detalle']);
    }

    // ── Internos ──────────────────────────────────────────────────────────────

    /** @throws ProgramacionException|EmisionException|GeneracionCredencialException */
    private function reaprobar(EmisionProgramada $programacion, Usuario $usuario, ?int $versionEsperada, ?CarbonImmutable $fecha): EmisionProgramada
    {
        $actual = EmisionProgramada::query()->find($programacion->id);
        if ($actual === null || ! in_array($actual->estado, EmisionProgramada::MODIFICABLES, true)) {
            throw $this->noModificable();
        }
        [$verificacion, $huella] = $this->validarBase($actual->lote_id);

        return $this->bloqueandoBase($actual->lote_id, $huella, function () use ($programacion, $usuario, $versionEsperada, $fecha, $verificacion, $huella) {
            $p = $this->bloquearModificable($programacion, $versionEsperada);
            $cambios = [
                'huella' => $huella['huella'], 'huella_detalle' => $huella['detalle'],
                'oficiales_esperados' => $verificacion['oficiales'], 'qa_esperados' => $verificacion['qa'],
                'plantilla_id' => $verificacion['plantilla_id'], 'variante_qa_id' => $verificacion['variante_qa_id'],
                'confirmada_at' => now(), 'confirmada_por' => $usuario->id, 'version' => $p->version + 1,
                // Una nueva aprobación vuelve a empezar: si esperaba confirmación, queda pendiente y sin el error ni los reintentos anteriores.
                'estado' => EmisionProgramada::PENDIENTE, 'intentos' => 0, 'proximo_intento_at' => null, 'error_codigo' => null, 'error_mensaje' => null,
                // Una ejecución anterior (parcial) queda solo como historial: la nueva empieza limpia, con la huella de los pendientes de AHORA.
                'iniciada_at' => null, 'latido_at' => null, 'con_avisos' => false, 'resultado' => $this->historial($p),
            ];
            if ($fecha !== null) {
                $cambios += ['programada_para' => FechaProgramada::paraGuardar($fecha), 'reprogramaciones' => $p->reprogramaciones + 1];
            }
            $p->update($cambios);
            $this->registrar('actualizacion', $fecha !== null ? 'Se reprogramó la emisión de una base de Credential Flow' : 'Se reconfirmó la emisión programada de una base de Credential Flow', $p, $usuario);

            return $p->fresh();
        });
    }

    /**
     * La base existe, está «activa» y su emisión pasa hoy todas las validaciones. Devuelve lo que se emitiría y la huella de lo validado.
     *
     * @return array{0: array{oficiales:int, qa:int, plantilla_id:?int, variante_qa_id:?int}, 1: array{huella:string, detalle:array<string,string>}}
     *
     * @throws ProgramacionException|EmisionException|GeneracionCredencialException
     */
    private function validarBase(int $loteId): array
    {
        $lote = $this->exigirBaseActiva($loteId);
        $huella = HuellaProgramacion::calcular($lote);
        $verificacion = $this->emisor->verificarEmitible($lote);
        // La emisión programada exige SIEMPRE la variante de pruebas internas de la plantilla (aunque hoy no haya pruebas QA pendientes).
        if ($verificacion['variante_qa_id'] === null && app(ServicioQa::class)->plantillaPara($lote) === null) {
            throw new EmisionException(EmisionException::VARIANTE_QA_FALTANTE, 'La plantilla de esta base no tiene su variante de pruebas internas (QA) asociada: no se puede programar su emisión. Asóciala y vuelve a intentarlo.', 409);
        }

        return [$verificacion, $huella];
    }

    /**
     * Bloquea la base, comprueba que sigue activa y que su huella es la misma que se validó, y ejecuta `$accion` en la misma transacción.
     *
     * @template T
     *
     * @param  array{huella:string}  $huella
     * @param  Closure(): T  $accion
     * @return T
     */
    private function bloqueandoBase(int $loteId, array $huella, Closure $accion): mixed
    {
        if (self::$antesDeBloquear !== null) {
            (self::$antesDeBloquear)();
        }

        return DB::transaction(function () use ($loteId, $huella, $accion) {
            $lote = Lote::query()->lockForUpdate()->find($loteId);
            if ($lote === null) {
                throw $this->baseNoDisponible();
            }
            if (($lote->estado_operativo ?? Lote::ACTIVA) !== Lote::ACTIVA) {
                throw $this->baseNoActiva();
            }
            if (! hash_equals($huella['huella'], HuellaProgramacion::calcular($lote)['huella'])) {
                throw new ProgramacionException(ProgramacionException::BASE_CAMBIO, 'La base cambió mientras se programaba. No se guardó nada: revísala y vuelve a intentarlo.', 409);
            }

            return $accion();
        });
    }

    /** @return array<string,mixed>|null el resultado de las ejecuciones anteriores, guardado como historial (sin operaciones vigentes) */
    private function historial(EmisionProgramada $p): ?array
    {
        $anterior = (array) ($p->resultado ?? []);
        if ($anterior === []) {
            return null;
        }
        $previas = (array) ($anterior['ejecuciones_anteriores'] ?? []);
        unset($anterior['ejecuciones_anteriores']);

        return ['ejecuciones_anteriores' => [...$previas, $anterior]];
    }

    /** @throws ProgramacionException */
    private function bloquearModificable(EmisionProgramada $programacion, ?int $versionEsperada): EmisionProgramada
    {
        $p = EmisionProgramada::query()->lockForUpdate()->find($programacion->id);
        if ($p === null || ! in_array($p->estado, EmisionProgramada::MODIFICABLES, true)) {
            throw $this->noModificable();
        }
        if ($versionEsperada !== null && $p->version !== $versionEsperada) {
            throw new ProgramacionException(ProgramacionException::CAMBIO_CONCURRENTE, 'Otra persona cambió esta programación mientras tanto. Recarga la página y revísala.', 409);
        }

        return $p;
    }

    /** @throws ProgramacionException */
    private function exigirBaseActiva(int $loteId): Lote
    {
        $lote = Lote::query()->find($loteId);
        if ($lote === null) {
            throw $this->baseNoDisponible();
        }
        if (($lote->estado_operativo ?? Lote::ACTIVA) !== Lote::ACTIVA) {
            throw $this->baseNoActiva();
        }

        return $lote;
    }

    /** @throws ProgramacionException */
    private function exigirHabilitada(): void
    {
        if (! self::habilitada()) {
            throw new ProgramacionException(ProgramacionException::DESACTIVADA, 'La emisión programada no está habilitada.', 403);
        }
    }

    /**
     * ¿Puede este usuario aprobar (y, por tanto, que se emita en su nombre) una emisión programada? Rol super-admin o admin y cuenta ACTIVA. Lo usa también el
     * ejecutor automático, que no tiene sesión web: revisa al usuario que aprobó la programación tal como está HOY en la base de datos.
     */
    public static function autorizado(?Usuario $usuario): bool
    {
        if ($usuario === null) {
            return false;
        }
        $usuario->loadMissing('perfilOrganizador.rol');
        $activo = $usuario->estado_id === null || (int) $usuario->estado_id === self::USUARIO_ACTIVO;

        return $activo && in_array($usuario->perfilOrganizador?->rol?->slug, self::ROLES, true);
    }

    /** @throws ProgramacionException */
    private function exigirPermiso(Usuario $usuario): void
    {
        if (! self::autorizado($usuario)) {
            throw new ProgramacionException(ProgramacionException::SIN_PERMISO, 'No tienes permiso para programar emisiones.', 403);
        }
    }

    /** Un solo Movimiento por acción, sin datos personales. Quién actuó va también en `extra` (no depende de la sesión web: el ejecutor no tiene). */
    private function registrar(string $tipo, string $descripcion, EmisionProgramada $p, Usuario $usuario): void
    {
        Movimiento::registrar(tipo: $tipo, modulo: 'credential-flow', descripcion: $descripcion, extra: [
            'lote_id' => $p->lote_id, 'programacion_id' => $p->id, 'programada_para' => $p->fresh()?->programada_para?->format('Y-m-d H:i'), 'usuario_id' => $usuario->id,
        ]);
    }

    private function yaExiste(): ProgramacionException
    {
        return new ProgramacionException(ProgramacionException::YA_EXISTE_ACTIVA, 'Esta base ya tiene una emisión programada. Cancélala o reprográmala.', 409);
    }

    private function noModificable(): ProgramacionException
    {
        return new ProgramacionException(ProgramacionException::NO_MODIFICABLE, 'Esta programación ya no está pendiente (se está ejecutando o ya terminó): no se puede cambiar.', 409);
    }

    private function baseNoDisponible(): ProgramacionException
    {
        return new ProgramacionException(ProgramacionException::BASE_NO_DISPONIBLE, 'La base ya no está disponible.', 404);
    }

    private function baseNoActiva(): ProgramacionException
    {
        return new ProgramacionException(ProgramacionException::BASE_NO_ACTIVA, 'Solo se puede programar la emisión de una base en estado «activa».', 409);
    }
}
