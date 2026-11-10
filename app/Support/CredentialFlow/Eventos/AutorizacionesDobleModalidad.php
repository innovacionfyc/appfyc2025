<?php

namespace App\Support\CredentialFlow\Eventos;

use App\Models\CredentialFlow\AutorizacionDobleModalidad;
use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EmisionProgramada;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Importacion;
use App\Models\CredentialFlow\Lote;
use App\Models\Movimiento;
use App\Models\Usuario;
use App\Support\CredentialFlow\Participantes\DobleModalidad;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Autorización de la doble certificación por modalidad POR EVENTO (el motor de la regla es Participantes\DobleModalidad y no cambia).
 *
 *  - Solo un SUPER-ADMIN autoriza o revoca, siempre con motivo (las rutas lo exigen y este servicio lo vuelve a comprobar).
 *  - Autorizar no cambia ningún dato: solo permite, desde ese momento, registrar una participación por modalidad del mismo documento en una base del evento.
 *  - Revocar tampoco toca nada, y se BLOQUEA cuando dejaría datos inconsistentes: participaciones dobles reales en alguna base del evento, programaciones
 *    activas o borradores de importación en revisión asociados al evento.
 *  - Las autorizaciones nuevas tienen siempre origen «panel». El origen «configuracion_heredada» solo existe en registros históricos (la autorización #1
 *    del evento #637, de la transición desde la antigua variable CREDENTIAL_FLOW_DOBLE_MODALIDAD_EVENTOS, que ya no se lee) y se trata igual que cualquier
 *    otra autorización vigente.
 *  - Todo queda en el historial (`cf_eventos_doble_modalidad`: nunca se borra) y en Movimientos, sin datos personales.
 *  - Concurrencia: autorizar y revocar bloquean la fila del evento (FOR UPDATE) como PRIMERA lectura de su transacción; confirmar una importación, guardar
 *    un participante y asociar una base bloquean la misma fila (compartido) y releen la autorización con bloqueo, así que nunca se cruzan.
 */
final class AutorizacionesDobleModalidad
{
    public const ROLES = ['super-admin'];

    public const MOTIVO_MIN = 10;

    public const MOTIVO_MAX = 500;

    public static function disponible(): bool
    {
        return Schema::hasTable('cf_eventos_doble_modalidad');
    }

    public static function puedeGestionar(?Usuario $usuario): bool
    {
        return $usuario !== null && in_array($usuario->rol, self::ROLES, true);
    }

    /**
     * Estado visible para cualquier administrador (sin historial ni motivos).
     *
     * @return array{autorizado:bool, origen:?string, desde:?string}
     */
    public static function estado(EventoCertificacion $evento): array
    {
        $vigente = DobleModalidad::vigente($evento->id);

        return ['autorizado' => DobleModalidad::autorizado($evento->id), 'origen' => DobleModalidad::origen($evento->id), 'desde' => $vigente?->autorizada_at?->toIso8601String()];
    }

    /**
     * Qué hay y qué pasaría, SIN escribir nada: estado, impacto, bloqueos para autorizar y para revocar e historial. El historial y los motivos solo se
     * entregan a quien puede gestionarla (super-admin); el resto ve solo el estado.
     *
     * @return array<string,mixed>
     */
    public function resumen(EventoCertificacion $evento, ?Usuario $usuario): array
    {
        $puede = self::puedeGestionar($usuario);
        $r = self::estado($evento) + ['puede_gestionar' => $puede, 'disponible' => self::disponible()];
        if (! $puede) {
            return $r;
        }

        return $r + [
            'impacto' => $this->impacto($evento),
            'bloqueos_autorizar' => $this->bloqueosAutorizar($evento),
            'bloqueos_revocar' => $this->bloqueosRevocar($evento),
            'historial' => $this->historial($evento),
            'motivo_min' => self::MOTIVO_MIN,
            'motivo_max' => self::MOTIVO_MAX,
        ];
    }

    /**
     * Diagnóstico sin datos personales ni historial: estado, impacto y bloqueos. No escribe nada.
     *
     * @return array<string,mixed>
     */
    public function diagnostico(EventoCertificacion $evento): array
    {
        return self::estado($evento) + [
            'impacto' => $this->impacto($evento),
            'bloqueos_autorizar' => $this->bloqueosAutorizar($evento),
            'bloqueos_revocar' => $this->bloqueosRevocar($evento),
        ];
    }

    /**
     * Autoriza la doble modalidad del evento desde el panel (origen «panel», el único posible para una autorización nueva).
     *
     * @return array{autorizacion_id:int, movimiento_id:int}
     *
     * @throws AutorizacionDobleModalidadException
     */
    public function autorizar(EventoCertificacion $evento, ?Usuario $usuario, string $motivo): array
    {
        $motivo = $this->exigirPrevios($usuario, $motivo);

        return $this->como($usuario, fn () => DB::transaction(function () use ($evento, $usuario, $motivo) {
            $e = $this->bloquearEvento($evento->id);   // primera lectura de la transacción
            if (($b = $this->bloqueosAutorizar($e)) !== []) {
                throw new AutorizacionDobleModalidadException($b[0]['code'], $b[0]['message'], $b);
            }

            $impacto = $this->impacto($e);
            $a = AutorizacionDobleModalidad::create([
                'evento_id' => $e->id, 'estado' => AutorizacionDobleModalidad::VIGENTE, 'origen' => AutorizacionDobleModalidad::ORIGEN_PANEL, 'motivo' => $motivo,
                'autorizada_por' => $usuario->id, 'autorizada_at' => now(), 'vigente_evento_id' => $e->id,
            ]);
            $mov = Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: 'Se autorizó la doble certificación por modalidad de un evento', extra: [
                'accion' => 'autorizar_doble_modalidad', 'evento_id' => $e->id, 'autorizacion_id' => $a->id, 'origen' => AutorizacionDobleModalidad::ORIGEN_PANEL,
                'motivo' => $motivo, 'impacto' => $impacto,
            ]);

            return ['autorizacion_id' => $a->id, 'movimiento_id' => $mov->id];
        }));
    }

    /**
     * Revoca la autorización vigente del evento. No toca participantes ni certificados.
     *
     * @return array{autorizacion_id:int, movimiento_id:int}
     *
     * @throws AutorizacionDobleModalidadException
     */
    public function revocar(EventoCertificacion $evento, ?Usuario $usuario, string $motivo): array
    {
        $motivo = $this->exigirPrevios($usuario, $motivo);

        return $this->como($usuario, fn () => DB::transaction(function () use ($evento, $usuario, $motivo) {
            $e = $this->bloquearEvento($evento->id);   // primera lectura de la transacción: lo que sigue ve lo último confirmado
            $vigente = AutorizacionDobleModalidad::query()->where('vigente_evento_id', $e->id)->lockForUpdate()->first();
            if ($vigente === null) {
                throw new AutorizacionDobleModalidadException(AutorizacionDobleModalidadException::NO_AUTORIZADO, 'Este evento no tiene una autorización vigente en el panel.');
            }
            if (($b = $this->bloqueosRevocar($e)) !== []) {
                throw new AutorizacionDobleModalidadException($b[0]['code'], $b[0]['message'], $b);
            }

            $impacto = $this->impacto($e);
            $vigente->forceFill(['estado' => AutorizacionDobleModalidad::REVOCADA, 'revocada_por' => $usuario->id, 'revocada_at' => now(), 'motivo_revocacion' => $motivo, 'vigente_evento_id' => null])->save();
            $mov = Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: 'Se revocó la doble certificación por modalidad de un evento', extra: [
                'accion' => 'revocar_doble_modalidad', 'evento_id' => $e->id, 'autorizacion_id' => $vigente->id, 'motivo' => $motivo, 'impacto' => $impacto,
            ]);

            return ['autorizacion_id' => $vigente->id, 'movimiento_id' => $mov->id];
        }));
    }

    // ── Guardas que usan otros servicios ──────────────────────────────────────

    /** Documentos con dos o más participaciones REALES vivas en una misma base viva del evento (o solo en `$loteId`). Los QA no cuentan. */
    public static function documentosDobles(?int $eventoId, ?int $loteId = null): int
    {
        if ($eventoId === null && $loteId === null) {
            return 0;
        }

        return DB::table('cf_participantes as p')->join('cf_lotes as l', 'l.id', '=', 'p.lote_id')
            ->when($eventoId !== null, fn ($q) => $q->where('l.evento_id', $eventoId))
            ->when($loteId !== null, fn ($q) => $q->where('l.id', $loteId))
            ->whereNull('l.deleted_at')->whereNull('p.deleted_at')->where('p.es_control_qa', false)->whereNotNull('p.documento_clave')
            ->groupBy('p.lote_id', 'p.documento_clave')->havingRaw('COUNT(*) > 1')->select('p.lote_id')->get()->count();
    }

    /**
     * Una base con participaciones dobles solo puede quedar en un evento AUTORIZADO: no se desasocia (`$destino` null) ni se mueve a un evento no autorizado.
     * Se usa dentro de la transacción que cambia `evento_id` (bloquea el evento destino y relee la autorización).
     *
     * @throws InvalidArgumentException
     */
    public static function exigirDestinoDeBase(Lote $lote, ?EventoCertificacion $destino): void
    {
        $n = self::documentosDobles(null, $lote->id);
        if ($n === 0) {
            return;
        }
        if ($destino !== null) {
            EventoCertificacion::query()->whereKey($destino->id)->sharedLock()->first();
            if (DobleModalidad::autorizado($destino->id, true)) {
                return;
            }
        }

        throw new InvalidArgumentException(($n === 1 ? 'Esta base tiene una persona' : "Esta base tiene {$n} personas").' con dos participaciones (una por modalidad): '
            .($destino === null ? 'no se puede quitar de su evento.' : 'solo puede pertenecer a un evento autorizado para la doble certificación por modalidad.'));
    }

    // ── Internos ──────────────────────────────────────────────────────────────

    /** @return array{bases:int, emisiones_vigentes:int, documentos_dobles:int, programaciones_activas:int, borradores_en_revision:int} */
    private function impacto(EventoCertificacion $evento): array
    {
        $bases = Lote::query()->where('evento_id', $evento->id);

        return [
            'bases' => (clone $bases)->count(),
            'emisiones_vigentes' => DB::table('cf_emisiones as e')->join('cf_participantes as p', 'p.id', '=', 'e.participante_id')->whereIn('e.lote_id', (clone $bases)->select('id'))
                ->where('e.estado', Emision::EMITIDA)->where('p.es_control_qa', false)->count(),
            'documentos_dobles' => self::documentosDobles($evento->id),
            'programaciones_activas' => count($this->programacionesActivas($evento)),
            'borradores_en_revision' => count($this->borradoresEnRevision($evento)),
        ];
    }

    /** @return list<array{code:string, message:string}> */
    private function bloqueosAutorizar(EventoCertificacion $evento): array
    {
        $b = [];
        if (! self::disponible()) {
            return [self::bloqueo(AutorizacionDobleModalidadException::NO_DISPONIBLE, 'La autorización desde el panel todavía no está instalada (falta la migración).')];
        }
        if ($evento->trashed() || $evento->origen !== EventoCertificacion::ORIGEN_CREDENTIAL_FLOW || $evento->estado === EventoCertificacion::ESTADO_ARCHIVADO) {
            $b[] = self::bloqueo(AutorizacionDobleModalidadException::EVENTO_NO_VALIDO, 'Solo se autorizan eventos creados en Credential Flow que no estén eliminados ni archivados.');
        }
        if (DobleModalidad::vigente($evento->id) !== null) {
            $b[] = self::bloqueo(AutorizacionDobleModalidadException::YA_AUTORIZADO, 'Este evento ya tiene una autorización vigente.');
        }

        return $b;
    }

    /** @return list<array{code:string, message:string}> */
    private function bloqueosRevocar(EventoCertificacion $evento): array
    {
        if (! self::disponible()) {
            return [self::bloqueo(AutorizacionDobleModalidadException::NO_DISPONIBLE, 'La autorización desde el panel todavía no está instalada (falta la migración).')];
        }
        $b = [];
        if (DobleModalidad::vigente($evento->id) === null) {
            $b[] = self::bloqueo(AutorizacionDobleModalidadException::NO_AUTORIZADO, 'Este evento no tiene una autorización vigente en el panel.');
        }
        if (($n = self::documentosDobles($evento->id)) > 0) {
            $b[] = self::bloqueo(AutorizacionDobleModalidadException::PARTICIPACIONES_DOBLES, ($n === 1 ? 'Una persona tiene' : "{$n} personas tienen")
                .' dos participaciones (una por modalidad) en una base de este evento: sin la autorización no se podrían administrar. No se revoca.');
        }
        if (($p = $this->programacionesActivas($evento)) !== []) {
            $b[] = self::bloqueo(AutorizacionDobleModalidadException::PROGRAMACION_ACTIVA, 'Hay emisiones programadas activas en bases de este evento (#'.implode(', #', $p).'): espera a que terminen o cancélalas antes de revocar.');
        }
        if (($d = $this->borradoresEnRevision($evento)) !== []) {
            $b[] = self::bloqueo(AutorizacionDobleModalidadException::BORRADOR_EN_REVISION, 'Hay borradores de importación en revisión asociados a este evento (#'.implode(', #', $d).'): confírmalos o recházalos antes de revocar.');
        }

        return $b;
    }

    /** @return list<int> */
    private function programacionesActivas(EventoCertificacion $evento): array
    {
        if (! Schema::hasTable('cf_emisiones_programadas')) {
            return [];
        }

        return EmisionProgramada::query()->whereIn('estado', EmisionProgramada::ACTIVOS)->whereIn('lote_id', Lote::query()->where('evento_id', $evento->id)->select('id'))
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> borradores en revisión cuya decisión es ASOCIAR la base a este evento */
    private function borradoresEnRevision(EventoCertificacion $evento): array
    {
        if (! Schema::hasTable('cf_importaciones')) {
            return [];
        }

        return Importacion::query()->where('estado', Importacion::EN_REVISION)->orderBy('id')->get(['id', 'correcciones'])
            ->filter(fn (Importacion $i) => ($i->correcciones['evento']['decision'] ?? null) === 'asociar' && (int) ($i->correcciones['evento']['evento_id'] ?? 0) === $evento->id)
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @return list<array<string,mixed>> autorizaciones del evento, la más reciente primero */
    private function historial(EventoCertificacion $evento): array
    {
        if (! self::disponible()) {
            return [];
        }

        return AutorizacionDobleModalidad::query()->where('evento_id', $evento->id)->with(['autor.perfilOrganizador', 'revocador.perfilOrganizador'])->orderByDesc('id')->get()
            ->map(fn (AutorizacionDobleModalidad $a) => [
                'id' => $a->id, 'estado' => $a->estado, 'origen' => $a->origen, 'motivo' => $a->motivo,
                'autorizada_por' => self::nombre($a->autor), 'autorizada_at' => $a->autorizada_at?->toIso8601String(),
                'revocada_por' => $a->revocada_por === null ? null : self::nombre($a->revocador), 'revocada_at' => $a->revocada_at?->toIso8601String(), 'motivo_revocacion' => $a->motivo_revocacion,
            ])->values()->all();
    }

    /** Nombre del administrador para el historial (solo lo ve un super-admin). */
    private static function nombre(?Usuario $u): string
    {
        $p = $u?->perfilOrganizador;
        $n = trim(implode(' ', array_filter([$p?->primer_nombre, $p?->primer_apellido])));

        return $n !== '' ? $n : ($u === null ? 'Usuario eliminado' : 'Usuario #'.$u->id);
    }

    /** @throws AutorizacionDobleModalidadException */
    private function exigirPrevios(?Usuario $usuario, string $motivo): string
    {
        if (! self::disponible()) {
            throw new AutorizacionDobleModalidadException(AutorizacionDobleModalidadException::NO_DISPONIBLE, 'La autorización desde el panel todavía no está instalada (falta la migración).');
        }
        if (! self::puedeGestionar($usuario)) {
            throw new AutorizacionDobleModalidadException(AutorizacionDobleModalidadException::SIN_PERMISO, 'Solo un super-admin puede autorizar o revocar la doble certificación por modalidad.');
        }
        $motivo = trim(preg_replace('/\s+/u', ' ', $motivo) ?? '');
        $largo = mb_strlen($motivo);
        if ($largo < self::MOTIVO_MIN || $largo > self::MOTIVO_MAX) {
            throw new AutorizacionDobleModalidadException(AutorizacionDobleModalidadException::MOTIVO_INVALIDO, 'Escribe el motivo (entre '.self::MOTIVO_MIN.' y '.self::MOTIVO_MAX.' caracteres).');
        }

        return $motivo;
    }

    /** Bloquea la fila del evento (también si está eliminado: el bloqueo informa que no es válido). */
    private function bloquearEvento(int $eventoId): EventoCertificacion
    {
        $e = EventoCertificacion::withTrashed()->whereKey($eventoId)->lockForUpdate()->first();
        if ($e === null) {
            throw new AutorizacionDobleModalidadException(AutorizacionDobleModalidadException::EVENTO_NO_VALIDO, 'El evento no existe.');
        }

        return $e;
    }

    /** @return array{code:string, message:string} */
    private static function bloqueo(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    /** Ejecuta como ese administrador (autor en Movimientos también desde la consola) y deja la sesión como estaba. */
    private function como(Usuario $usuario, Closure $accion): mixed
    {
        $anterior = Auth::user();
        if ($anterior?->getAuthIdentifier() === $usuario->id) {
            return $accion();
        }
        Auth::setUser($usuario);
        try {
            return $accion();
        } finally {
            $anterior === null ? Auth::forgetUser() : Auth::setUser($anterior);
        }
    }
}
