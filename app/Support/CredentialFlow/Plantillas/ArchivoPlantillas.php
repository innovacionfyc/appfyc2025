<?php

namespace App\Support\CredentialFlow\Plantillas;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EmisionProgramada;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Models\Usuario;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\ServicioVistaPrevia;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archivo seguro de plantillas (C2). Una plantilla ARCHIVADA:
 *  - se conserva ENTERA: diseño, PDF base, bases asociadas, emisiones (de cualquier estado), sus PDF y la auditoría. Nada se borra ni se reescribe;
 *  - deja de ofrecerse en todos los selectores de bases nuevas, cambio de plantilla, importación y reemplazo histórico (`Plantilla::vigentes()`);
 *  - no genera NINGUNA emisión nueva ni reemisión (oficial o QA): el bloqueo está en los servicios de emisión (`exigirVigente`), no solo en la interfaz;
 *  - se puede restaurar (mismo permiso y motivo), que es la reversión del archivo.
 *
 * Solo un SUPER-ADMIN archiva o restaura, siempre con motivo; queda en Movimientos con fecha, usuario, plantillas afectadas y sucesora opcional.
 * Variantes QA: se archivan JUNTO con su plantilla oficial (en la misma operación y con el mismo instante). Una variante que una plantilla oficial VIGENTE sigue
 * usando no se archiva por separado: dejaría a esas bases sin pruebas internas. Una variante compartida por otra oficial vigente no se archiva con la primera.
 */
final class ArchivoPlantillas
{
    public const ROLES = ['super-admin'];

    public const MOTIVO_MIN = 10;

    public const MOTIVO_MAX = 500;

    /** Lo que se archiva o restaura a la vez, con el mismo instante: así la restauración sabe qué variante se archivó junto con su oficial. */
    private const PREFIJO_VARIANTE = 'Archivada junto con su plantilla oficial #';

    public static function disponible(): bool
    {
        return Plantilla::archivoDisponible();
    }

    public static function puedeArchivar(?Usuario $usuario): bool
    {
        return $usuario !== null && in_array($usuario->rol, self::ROLES, true);
    }

    /**
     * Guarda de EMISIÓN: ninguna emisión, reemisión o emisión programada sale con una plantilla archivada (ni la oficial ni la variante QA).
     *
     * @throws EmisionException
     */
    public static function exigirVigente(?Plantilla $plantilla, bool $esVarianteQa = false): void
    {
        if ($plantilla === null || ! $plantilla->estaArchivada()) {
            return;
        }

        throw new EmisionException(EmisionException::PLANTILLA_ARCHIVADA, $esVarianteQa
            ? 'La variante de pruebas internas (QA) de esta base está archivada: no se emiten pruebas con ella. Cambia la base a una plantilla vigente con el procedimiento oficial antes de emitir o reemitir.'
            : 'La plantilla de esta base está archivada: no genera certificados nuevos. Cámbiala por una plantilla vigente con el procedimiento oficial antes de emitir o reemitir.',
            409);
    }

    /** Aviso para la pantalla de una base cuya plantilla está archivada (null si está vigente). @return array{plantilla_id:int, nombre:string, sucesora:?array{id:int, nombre:string}, mensaje:string}|null */
    public static function avisoBase(Lote $lote): ?array
    {
        $p = $lote->plantilla;
        if ($p === null || ! $p->estaArchivada()) {
            return null;
        }
        $s = $p->sucesora;

        return [
            'plantilla_id' => $p->id,
            'nombre' => (string) $p->nombre,
            'sucesora' => $s && ! $s->trashed() && ! $s->estaArchivada() ? ['id' => $s->id, 'nombre' => (string) $s->nombre] : null,
            'mensaje' => 'La plantilla de esta base está archivada. El historial y los certificados ya emitidos se conservan, pero no se emiten ni reemiten certificados con ella: '
                .'primero cambia la base a una plantilla vigente con el procedimiento oficial (cambio de plantilla).',
        ];
    }

    /**
     * Qué pasaría al archivar, SIN escribir nada: dependencias, variante QA, programaciones y bloqueos. Sirve para la pantalla y para la simulación del comando.
     *
     * @return array<string,mixed>
     */
    public function resumen(Plantilla $plantilla): array
    {
        $bloqueos = [];
        if (! self::disponible()) {
            return ['plantilla' => ['id' => $plantilla->id, 'nombre' => (string) $plantilla->nombre], 'bloqueos' => [self::bloqueo(ArchivoException::NO_DISPONIBLE, 'El archivo de plantillas todavía no está instalado (falta la migración).')], 'avisos' => []];
        }

        $esQa = (bool) ($plantilla->getAttribute('es_plantilla_qa') ?? false);
        $variante = ! $esQa && $plantilla->getAttribute('variante_qa_id') ? Plantilla::withTrashed()->find($plantilla->getAttribute('variante_qa_id')) : null;
        $comparten = $variante === null ? collect() : $this->oficialesQueUsan($variante)->reject(fn (Plantilla $o) => $o->id === $plantilla->id);
        $varianteSeArchiva = $variante !== null && ! $variante->estaArchivada() && ! $variante->trashed() && $comparten->isEmpty();
        $usanEstaVariante = $esQa ? $this->oficialesQueUsan($plantilla) : collect();
        $afectadas = array_values(array_filter([$plantilla->id, $varianteSeArchiva ? $variante->id : null]));

        if ($plantilla->trashed()) {
            $bloqueos[] = self::bloqueo(ArchivoException::ELIMINADA, 'La plantilla está eliminada: no se archiva.');
        }
        if ($plantilla->estaArchivada()) {
            $bloqueos[] = self::bloqueo(ArchivoException::YA_ARCHIVADA, 'La plantilla ya está archivada.');
        }
        if ($usanEstaVariante->isNotEmpty()) {
            $bloqueos[] = self::bloqueo(ArchivoException::VARIANTE_EN_USO, 'Es la variante de pruebas internas (QA) de '.$usanEstaVariante->count().' plantilla(s) vigente(s) (#'.$usanEstaVariante->pluck('id')->implode(', #')
                .'): no se archiva por separado. Archiva la plantilla oficial y su variante se archivará con ella.');
        }
        $programaciones = $this->programacionesActivas($afectadas);
        if ($programaciones !== []) {
            $bloqueos[] = self::bloqueo(ArchivoException::PROGRAMACION_ACTIVA, 'Hay emisiones programadas activas que usarían esta plantilla (#'.implode(', #', $programaciones).'): cancélalas o cambia la plantilla de esas bases antes de archivar.');
        }

        $lotes = Lote::query()->whereIn('plantilla_id', $afectadas)->get(['id', 'estado_operativo']);
        $emisiones = Emision::query()->whereIn('plantilla_id', $afectadas);
        $avisos = [];
        if ($lotes->isNotEmpty()) {
            $avisos[] = $lotes->count().' base(s) usan esta plantilla. Conservan su historial y sus certificados, pero no podrán emitir ni reemitir hasta cambiar a una plantilla vigente.';
        }
        if ($variante !== null && ! $varianteSeArchiva && ! $variante->estaArchivada() && $comparten->isNotEmpty()) {
            $avisos[] = 'Su variante de pruebas internas (#'.$variante->id.') también la usan otras plantillas vigentes (#'.$comparten->pluck('id')->implode(', #').'): la variante NO se archiva.';
        }
        $avisos[] = 'Los borradores de importación que tengan elegida esta plantilla tendrán que elegir otra antes de confirmar.';

        return [
            'plantilla' => self::ficha($plantilla) + ['es_qa' => $esQa],
            'variante' => $variante === null ? null : self::ficha($variante) + ['se_archiva_con_ella' => $varianteSeArchiva, 'compartida_con' => $comparten->pluck('id')->values()->all()],
            'oficiales_que_la_usan_como_variante' => $usanEstaVariante->map(fn (Plantilla $o) => ['id' => $o->id, 'nombre' => (string) $o->nombre])->values()->all(),
            'afectadas' => $afectadas,
            'bases' => ['total' => $lotes->count(), 'ids' => $lotes->pluck('id')->take(50)->values()->all()],
            'emisiones' => ['total' => (clone $emisiones)->count(), 'vigentes' => (clone $emisiones)->where('estado', Emision::EMITIDA)->count()],
            'programaciones_activas' => $programaciones,
            'es_sucesora_de' => Plantilla::query()->where('sucesora_id', $plantilla->id)->pluck('id')->all(),
            'sucesoras_posibles' => $this->sucesorasPosibles($plantilla)->map(fn (Plantilla $s) => ['id' => $s->id, 'nombre' => (string) $s->nombre])->values()->all(),
            'bloqueos' => $bloqueos,
            'avisos' => $avisos,
        ];
    }

    /**
     * Archiva la plantilla (y su variante QA cuando corresponde) en una sola transacción. Nunca borra ni modifica diseño, PDF, bases ni emisiones.
     *
     * @return array{archivadas:array<int,int>, sucesora_id:?int, movimiento_id:int}
     *
     * @throws ArchivoException
     */
    public function archivar(Plantilla $plantilla, ?Usuario $usuario, string $motivo, ?int $sucesoraId = null): array
    {
        $motivo = $this->exigirPrevios($usuario, $motivo);

        return $this->como($usuario, fn () => DB::transaction(function () use ($plantilla, $usuario, $motivo, $sucesoraId) {
            $p = Plantilla::withTrashed()->lockForUpdate()->find($plantilla->id);
            if ($p === null) {
                throw new ArchivoException(ArchivoException::CAMBIO_DURANTE_ARCHIVO, 'La plantilla ya no existe.');
            }
            $r = $this->resumen($p);
            if ($r['bloqueos'] !== []) {
                throw new ArchivoException($r['bloqueos'][0]['code'], $r['bloqueos'][0]['message'], $r['bloqueos']);
            }
            $sucesora = $sucesoraId === null ? null : $this->exigirSucesora($p, $sucesoraId);

            $ahora = now();
            $p->forceFill(['archivada_at' => $ahora, 'archivada_por' => $usuario->id, 'motivo_archivo' => $motivo, 'sucesora_id' => $sucesora?->id])->save();
            $archivadas = [$p->id];
            if (($r['variante']['se_archiva_con_ella'] ?? false) === true) {
                $v = Plantilla::withTrashed()->lockForUpdate()->find($r['variante']['id']);
                $sucesoraVariante = $sucesora?->getAttribute('variante_qa_id');
                $v->forceFill(['archivada_at' => $ahora, 'archivada_por' => $usuario->id, 'motivo_archivo' => mb_substr(self::PREFIJO_VARIANTE.$p->id.'. Motivo: '.$motivo, 0, self::MOTIVO_MAX),
                    'sucesora_id' => $sucesoraVariante !== null && Plantilla::query()->vigentes()->whereKey($sucesoraVariante)->exists() ? (int) $sucesoraVariante : null])->save();
                $archivadas[] = $v->id;
            }

            $mov = Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: "Se archivó la plantilla de Credential Flow: {$p->nombre}", extra: [
                'accion' => 'archivar_plantilla', 'plantilla_id' => $p->id, 'archivadas' => $archivadas, 'sucesora_id' => $sucesora?->id, 'motivo' => $motivo,
                'bases' => $r['bases']['total'], 'emisiones' => $r['emisiones']['total'], 'archivada_at' => $ahora->toDateTimeString(),
            ]);

            return ['archivadas' => $archivadas, 'sucesora_id' => $sucesora?->id, 'movimiento_id' => (int) $mov->id];
        }));
    }

    /**
     * Restaura (des-archiva) una plantilla: vuelve a estar disponible. Una variante archivada junto con su oficial se restaura con ella; una variante cuya oficial
     * sigue archivada no se restaura sola.
     *
     * @return array{restauradas:array<int,int>, movimiento_id:int}
     *
     * @throws ArchivoException
     */
    public function restaurar(Plantilla $plantilla, ?Usuario $usuario, string $motivo): array
    {
        $motivo = $this->exigirPrevios($usuario, $motivo);

        return $this->como($usuario, fn () => DB::transaction(function () use ($plantilla, $motivo) {
            $p = Plantilla::withTrashed()->lockForUpdate()->find($plantilla->id);
            if ($p === null || ! $p->estaArchivada()) {
                throw new ArchivoException(ArchivoException::NO_ARCHIVADA, 'La plantilla no está archivada.');
            }
            if ($p->trashed()) {
                throw new ArchivoException(ArchivoException::ELIMINADA, 'La plantilla está eliminada: no se restaura desde el archivo.');
            }
            if ((bool) ($p->getAttribute('es_plantilla_qa') ?? false)) {
                $oficial = Plantilla::query()->where('variante_qa_id', $p->id)->whereNotNull('archivada_at')->first();
                if ($oficial !== null) {
                    throw new ArchivoException(ArchivoException::OFICIAL_ARCHIVADA, "Es la variante de pruebas internas de la plantilla #{$oficial->id}, que sigue archivada: restaura la oficial y su variante se restaurará con ella.");
                }
            }

            $restauradas = [$p->id];
            $instante = $p->getAttribute('archivada_at');
            $v = $p->getAttribute('variante_qa_id') ? Plantilla::withTrashed()->lockForUpdate()->find($p->getAttribute('variante_qa_id')) : null;
            $limpiar = ['archivada_at' => null, 'archivada_por' => null, 'motivo_archivo' => null, 'sucesora_id' => null];
            $p->forceFill($limpiar)->save();
            if ($v !== null && $v->estaArchivada() && $v->archivada_at?->equalTo($instante) && str_starts_with((string) $v->motivo_archivo, self::PREFIJO_VARIANTE.$p->id.'.')) {
                $v->forceFill($limpiar)->save();
                $restauradas[] = $v->id;
            }

            $mov = Movimiento::registrar(tipo: 'actualizacion', modulo: 'credential-flow', descripcion: "Se restauró del archivo la plantilla de Credential Flow: {$p->nombre}", extra: [
                'accion' => 'restaurar_plantilla', 'plantilla_id' => $p->id, 'restauradas' => $restauradas, 'motivo' => $motivo,
            ]);

            return ['restauradas' => $restauradas, 'movimiento_id' => (int) $mov->id];
        }));
    }

    // ── Internos ──────────────────────────────────────────────────────────────

    /** @throws ArchivoException */
    private function exigirPrevios(?Usuario $usuario, string $motivo): string
    {
        if (! self::disponible()) {
            throw new ArchivoException(ArchivoException::NO_DISPONIBLE, 'El archivo de plantillas todavía no está instalado (falta la migración).');
        }
        if (! self::puedeArchivar($usuario)) {
            throw new ArchivoException(ArchivoException::NO_AUTORIZADO, 'Solo un super-admin puede archivar o restaurar plantillas.');
        }
        $motivo = trim(preg_replace('/\s+/u', ' ', $motivo) ?? '');
        $largo = mb_strlen($motivo);
        if ($largo < self::MOTIVO_MIN || $largo > self::MOTIVO_MAX) {
            throw new ArchivoException(ArchivoException::MOTIVO_INVALIDO, 'Escribe el motivo (entre '.self::MOTIVO_MIN.' y '.self::MOTIVO_MAX.' caracteres).');
        }

        return $motivo;
    }

    /** @throws ArchivoException */
    private function exigirSucesora(Plantilla $p, int $sucesoraId): Plantilla
    {
        $s = $this->sucesorasPosibles($p)->firstWhere('id', $sucesoraId);
        if ($s === null) {
            throw new ArchivoException(ArchivoException::SUCESORA_INVALIDA, 'La sucesora debe ser otra plantilla vigente, del mismo tipo (oficial o de pruebas internas) y con diseño guardado.');
        }

        return $s;
    }

    /** Plantillas que pueden figurar como sucesora: vigentes, no eliminadas, distintas, del mismo tipo (oficial/QA) y con diseño. */
    private function sucesorasPosibles(Plantilla $p)
    {
        $esQa = (bool) ($p->getAttribute('es_plantilla_qa') ?? false);

        return Plantilla::query()->vigentes()->whereKeyNot($p->id)
            ->when(Schema::hasColumn('cf_plantillas', 'es_plantilla_qa'), fn ($q) => $q->where('es_plantilla_qa', $esQa))
            ->orderByDesc('id')->get()->filter(fn (Plantilla $s) => ServicioVistaPrevia::plantillaValida($s))->values();
    }

    /** Plantillas oficiales VIGENTES (no eliminadas, no archivadas) que usan esta variante QA. */
    private function oficialesQueUsan(Plantilla $variante)
    {
        if (! Schema::hasColumn('cf_plantillas', 'variante_qa_id')) {
            return collect();
        }

        return Plantilla::query()->vigentes()->where('variante_qa_id', $variante->id)->orderBy('id')->get(['id', 'nombre']);
    }

    /** @param  array<int,int>  $plantillas  @return array<int,int> programaciones activas de bases que usan estas plantillas o que guardaron una de ellas */
    private function programacionesActivas(array $plantillas): array
    {
        if ($plantillas === [] || ! Schema::hasTable('cf_emisiones_programadas')) {
            return [];
        }

        return EmisionProgramada::query()->whereIn('estado', EmisionProgramada::ACTIVOS)
            ->where(fn ($q) => $q->whereIn('plantilla_id', $plantillas)->orWhereIn('variante_qa_id', $plantillas)
                ->orWhereIn('lote_id', Lote::query()->whereIn('plantilla_id', $plantillas)->select('id')))
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<string,mixed> */
    private static function ficha(Plantilla $p): array
    {
        return ['id' => $p->id, 'nombre' => (string) $p->nombre, 'archivada' => $p->estaArchivada(), 'archivada_at' => $p->getAttribute('archivada_at')?->toIso8601String(),
            'motivo' => $p->getAttribute('motivo_archivo'), 'sucesora_id' => $p->getAttribute('sucesora_id'), 'eliminada' => $p->trashed()];
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
