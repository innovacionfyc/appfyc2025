<?php

namespace App\Services\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Eliminacion\EliminacionException;
use App\Support\CredentialFlow\Eliminacion\Papelera;
use App\Support\CredentialFlow\Eliminacion\RutasSeguras;
use App\Support\CredentialFlow\LogSeguro;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Eliminación DEFINITIVA (sin vuelta atrás) de una base de participantes SIN historial o de una plantilla sin relaciones.
 *
 * Una base NO se elimina si tiene historial: cualquier emisión (vigente, revocada o versión), descargas, certificados
 * históricos de su evento o certificados que reemplazan a uno histórico. Se detecta ANTES de borrar nada y se explica al
 * usuario con un mensaje humano (nunca se depende de un error de clave foránea). Para retirar certificados se revocan.
 * Los certificados históricos (cf_certificados_legado) no tienen ningún camino de eliminación administrativa.
 *
 * Estrategia (la base de datos puede revertirse, el disco no):
 *   1. Dentro de una transacción se bloquean las filas (base y participantes / plantilla) y se inspecciona todo lo
 *      que se va a borrar con la base ya bloqueada: ninguna emisión ni correo nuevo puede aparecer a mitad de camino.
 *   2. Se valida CADA ruta (forma exacta, dentro de credential-flow, sin enlaces simbólicos, sin `..`) y que ningún
 *      archivo pertenezca a otra base. Si algo no cuadra no se mueve ni se borra nada.
 *   3. Los archivos se MUEVEN a una papelera privada (renombrado atómico en el mismo disco). Si falla un
 *      movimiento, se devuelven los ya movidos y la base de datos no se toca.
 *   4. Se borran los registros EN ESTE ORDEN: correos de los participantes (cf_correos, explícito: la FK es RESTRICT y no
 *      hay CASCADE silencioso) → participantes → base, y se anota la auditoría en la MISMA transacción (si algo falla,
 *      todo se revierte, correos incluidos).
 *   5. Con el commit hecho se vacía la papelera. Si la transacción falla, los archivos vuelven a su sitio.
 *
 * Los registros se borran con consultas directas a propósito. `Emision` es inmutable e indeleble por modelo y esta clase
 * ya NO borra emisiones: una base con emisiones queda bloqueada.
 * NUNCA toca backups, builds, otros módulos ni temporales que no sean inequívocamente de la base.
 */
class EliminacionDefinitivaService
{
    /** Solo para tests: se ejecuta dentro de la transacción tras mover los archivos (puede lanzar o modificar datos). */
    public static ?Closure $despuesDeMover = null;

    /** Solo para tests: se ejecuta dentro de la transacción tras borrar los registros y antes del commit. */
    public static ?Closure $antesDeConfirmar = null;

    /** Solo para tests: se ejecuta dentro de la transacción tras borrar los correos y antes de borrar los participantes. */
    public static ?Closure $despuesDeBorrarCorreos = null;

    /** Solo para tests: se ejecuta con la carpeta de la plantilla justo antes de su limpieza FINAL (ya con el commit hecho). Puede lanzar. */
    public static ?Closure $antesDeLimpiarCarpeta = null;

    // ── Base de participantes ────────────────────────────────────────────────────────────────────────────────

    /**
     * Lo que se borraría, para mostrarlo ANTES de confirmar. Solo lectura. Si hay algo que impediría la
     * eliminación, `bloqueada` trae el mensaje para el usuario.
     *
     * @return array<string,mixed>
     */
    public function resumenLote(Lote $lote): array
    {
        try {
            $inspeccion = $this->inspeccionarLote($lote->id, estricto: true);
            $bloqueada = null;
        } catch (EliminacionException $e) {
            $inspeccion = $this->inspeccionarLote($lote->id, estricto: false);
            $bloqueada = $e->getMessage();
        }

        return [
            'nombre' => $lote->nombre,
            'participantes' => $inspeccion['participantes'],
            'vigentes' => $inspeccion['vigentes'],
            'historicos' => $inspeccion['historicos'],
            'correos' => $inspeccion['correos'],
            'archivos' => $inspeccion['archivos'],
            'bytes' => $inspeccion['bytes'],
            'bloqueada' => $bloqueada,
        ];
    }

    /**
     * @return array{nombre:string,participantes:int,emisiones:int,correos:int,archivos:int,bytes:int,residuos:bool}
     *
     * @throws EliminacionException
     */
    public function eliminarLote(int $loteId): array
    {
        $papelera = new Papelera;
        $nombre = '';

        try {
            $resultado = DB::transaction(function () use ($loteId, $papelera, &$nombre) {
                $lote = Lote::withTrashed()->lockForUpdate()->find($loteId) ?? throw EliminacionException::noExiste('base');
                Participante::withTrashed()->where('lote_id', $lote->id)->lockForUpdate()->pluck('id');
                $nombre = (string) $lote->nombre;

                $i = $this->inspeccionarLote($lote->id, estricto: true);

                // Ningún dato de OTRA base puede depender de lo que se va a borrar (las claves foráneas lo impedirían).
                $dependenciasAjenas = DB::table('cf_emisiones')->where('lote_id', '!=', $lote->id)->where(function ($q) use ($lote) {
                    $q->whereIn('participante_id', fn ($s) => $s->select('id')->from('cf_participantes')->where('lote_id', $lote->id))
                        ->orWhereIn('reemplaza_id', fn ($s) => $s->select('id')->from('cf_emisiones')->where('lote_id', $lote->id));
                })->exists();
                if ($dependenciasAjenas) {
                    throw EliminacionException::datosRelacionados();
                }

                foreach ($i['rutas'] as $ruta => $bytes) {
                    if ($bytes !== null) {
                        $papelera->mover($ruta);
                    }
                }
                foreach ($i['temporales'] as $ruta) {
                    $papelera->mover($ruta);
                }

                if (self::$despuesDeMover !== null) {
                    (self::$despuesDeMover)($lote, $papelera);
                }

                // Última comprobación con todo bloqueado: lo que se movió es TODO lo que hay.
                $ahora = DB::table('cf_emisiones')->where('lote_id', $lote->id);
                if ((clone $ahora)->count() !== $i['emisiones'] || (int) (clone $ahora)->max('id') !== $i['ultima_emision']
                    || Participante::withTrashed()->where('lote_id', $lote->id)->count() !== $i['participantes']) {
                    throw EliminacionException::cambioConcurrente();
                }

                // Orden: correos → participantes → base. Los correos se borran de forma explícita (la FK es RESTRICT, no
                // CASCADE) y solo los de los participantes de ESTA base; los de certificados históricos nunca se tocan.
                $borradosCorreos = DB::table('cf_correos')
                    ->whereIn('participante_id', fn ($s) => $s->select('id')->from('cf_participantes')->where('lote_id', $lote->id))
                    ->delete();
                if ($borradosCorreos !== $i['correos']) {
                    throw EliminacionException::cambioConcurrente();
                }

                if (self::$despuesDeBorrarCorreos !== null) {
                    (self::$despuesDeBorrarCorreos)($lote);
                }

                $borradosParticipantes = DB::table('cf_participantes')->where('lote_id', $lote->id)->delete();
                $borradoLote = DB::table('cf_lotes')->where('id', $lote->id)->delete();

                if ($borradosParticipantes !== $i['participantes'] || $borradoLote !== 1) {
                    throw EliminacionException::cambioConcurrente();
                }

                // Solo metadatos útiles: sin nombres, documentos ni el nombre de la base.
                Movimiento::registrar(
                    tipo: 'eliminacion',
                    modulo: 'credential-flow',
                    descripcion: 'Se eliminó definitivamente la base de participantes de Credential Flow',
                    extra: [
                        'lote_id' => $lote->id,
                        'participantes' => $i['participantes'],
                        'emisiones' => $i['emisiones'],
                        'correos' => $borradosCorreos,
                        'archivos' => $papelera->cantidad(),
                        'bytes_liberados' => $i['bytes'],
                    ],
                );

                if (self::$antesDeConfirmar !== null) {
                    (self::$antesDeConfirmar)($lote, $papelera);
                }

                return [
                    'participantes' => $i['participantes'],
                    'emisiones' => $i['emisiones'],
                    'correos' => $borradosCorreos,
                    'archivos' => $papelera->cantidad(),
                    'bytes' => $i['bytes'],
                ];
            });
        } catch (Throwable $e) {
            $this->devolverArchivos($papelera);

            if (! $e instanceof EliminacionException) {
                Log::error('Credential Flow: falló la eliminación definitiva de una base', ['lote' => $loteId, 'error' => LogSeguro::resumen($e)]);

                throw EliminacionException::errorGeneral();
            }

            throw $e;
        }

        return ['nombre' => $nombre] + $resultado + ['residuos' => ! $papelera->vaciar()];
    }

    // ── Plantilla ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Qué relaciones tiene una plantilla y si se puede eliminar. Cuenta TAMBIÉN las bases ya eliminadas
     * (siguen existiendo en la base de datos) y todas las emisiones, vigentes o no.
     *
     * @return array<string,mixed>
     */
    public function resumenPlantilla(Plantilla $plantilla): array
    {
        $bases = Lote::withTrashed()->where('plantilla_id', $plantilla->id)->count();
        $certificados = Emision::where('plantilla_id', $plantilla->id)->count();

        $bytes = 0;
        $problema = null;
        try {
            $bytes = $this->archivosDePlantilla($plantilla->id)['bytes'];
        } catch (EliminacionException $e) {
            $problema = $e->getMessage();
        }

        return [
            'nombre' => $plantilla->nombre,
            'bases' => $bases,
            'certificados' => $certificados,
            'bytes' => $bytes,
            'bloqueada' => ($bases > 0 || $certificados > 0) ? EliminacionException::plantillaRelacionada()->getMessage() : $problema,
        ];
    }

    /**
     * @return array{nombre:string,archivos:int,bytes:int,residuos:bool}
     *
     * @throws EliminacionException
     */
    public function eliminarPlantilla(int $plantillaId): array
    {
        $papelera = new Papelera;
        $nombre = '';

        try {
            $resultado = DB::transaction(function () use ($plantillaId, $papelera, &$nombre) {
                $plantilla = Plantilla::withTrashed()->lockForUpdate()->find($plantillaId) ?? throw EliminacionException::noExiste('plantilla');
                $nombre = (string) $plantilla->nombre;

                // Con la plantilla bloqueada: nadie puede crear una base ni una emisión que la use a mitad de camino.
                if (Lote::withTrashed()->where('plantilla_id', $plantilla->id)->exists() || Emision::where('plantilla_id', $plantilla->id)->exists()) {
                    throw EliminacionException::plantillaRelacionada();
                }

                $archivos = $this->archivosDePlantilla($plantilla->id);
                foreach ($archivos['rutas'] as $ruta) {
                    $papelera->mover($ruta);
                }

                if (self::$despuesDeMover !== null) {
                    (self::$despuesDeMover)($plantilla, $papelera);
                }

                if (! $plantilla->forceDelete()) {
                    throw EliminacionException::cambioConcurrente();
                }

                Movimiento::registrar(
                    tipo: 'eliminacion',
                    modulo: 'credential-flow',
                    descripcion: 'Se eliminó definitivamente una plantilla de Credential Flow',
                    extra: ['plantilla_id' => $plantilla->id, 'archivos' => $papelera->cantidad(), 'bytes_liberados' => $archivos['bytes']],
                );

                if (self::$antesDeConfirmar !== null) {
                    (self::$antesDeConfirmar)($plantilla, $papelera);
                }

                return ['archivos' => $papelera->cantidad(), 'bytes' => $archivos['bytes']];
            });
        } catch (Throwable $e) {
            $this->devolverArchivos($papelera);

            if (! $e instanceof EliminacionException) {
                Log::error('Credential Flow: falló la eliminación definitiva de una plantilla', ['plantilla' => $plantillaId, 'error' => LogSeguro::resumen($e)]);

                throw EliminacionException::errorGeneral();
            }

            throw $e;
        }

        // Con el commit hecho la plantilla YA no existe: pase lo que pase en la limpieza final, no se revierte nada ni
        // se responde con un error genérico. Un fallo aquí deja un residuo que se registra y que la auditoría detecta.
        $residuos = ! $this->limpiezaFinalDePlantilla($plantillaId, $papelera);

        return ['nombre' => $nombre] + $resultado + ['residuos' => $residuos];
    }

    /**
     * Limpieza posterior al commit: carpeta de la plantilla (calculada solo desde su id) y papelera de ESTA operación.
     * Nunca lanza. Devuelve false (y lo deja en el log con contexto mínimo, sin datos personales) si algo quedó pendiente.
     */
    private function limpiezaFinalDePlantilla(int $plantillaId, Papelera $papelera): bool
    {
        $disco = Storage::disk(Plantilla::DISCO);
        $carpeta = RutasSeguras::carpetaPlantilla($plantillaId);
        $completa = true;

        try {
            if (self::$antesDeLimpiarCarpeta !== null) {
                (self::$antesDeLimpiarCarpeta)($carpeta);
            }
            $disco->deleteDirectory($carpeta);
            if ($disco->allFiles($carpeta) !== []) {
                throw new RuntimeException('la carpeta de la plantilla todavía contiene archivos');
            }
        } catch (Throwable $e) {
            $completa = false;
            $this->registrarLimpiezaPendiente($plantillaId, $papelera, $carpeta, LogSeguro::resumen($e));
        }

        if (! $papelera->vaciar()) {
            $completa = false;
            $this->registrarLimpiezaPendiente($plantillaId, $papelera, $papelera->raiz, 'no se pudo vaciar la papelera');
        }

        return $completa;
    }

    private function registrarLimpiezaPendiente(int $plantillaId, Papelera $papelera, string $ruta, string $error): void
    {
        Log::error('Credential Flow: plantilla eliminada, pero quedó una limpieza pendiente', [
            'plantilla_id' => $plantillaId,
            'operacion' => basename($papelera->raiz),
            'ruta' => $ruta,
            'error' => $error,
        ]);
    }

    // ── Inspección ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Todo lo que pertenece a la base: contadores, archivos de emisión (rutas únicas y validadas) y carpetas de
     * staging de SUS emisiones masivas (identificadas por el UUID de operación que aparece en sus emisiones).
     * Con `$estricto` cualquier ruta dudosa o archivo de otra base lanza; sin él, se omite (solo para el resumen).
     *
     * También exige (solo en modo estricto) que la base NO tenga historial: ver exigirSinHistorial().
     *
     * @return array{participantes:int,vigentes:int,historicos:int,emisiones:int,ultima_emision:int,correos:int,archivos:int,bytes:int,rutas:array<string,int|null>,temporales:list<string>}
     *
     * @throws EliminacionException
     */
    private function inspeccionarLote(int $loteId, bool $estricto): array
    {
        if ($estricto) {
            $this->exigirSinHistorial($loteId);
        }
        $disco = Storage::disk(Plantilla::DISCO);
        $emisiones = DB::table('cf_emisiones')->where('lote_id', $loteId)->orderBy('id')->get(['id', 'estado', 'pdf_archivo', 'operacion']);

        $rutas = [];
        $operaciones = [];
        $vigentes = 0;
        foreach ($emisiones as $e) {
            if ($e->estado === Emision::EMITIDA) {
                $vigentes++;
            }
            if ($e->operacion !== null) {
                $operaciones[$e->operacion] = true;
            }

            try {
                $ruta = RutasSeguras::emision((string) $e->pdf_archivo);
                $fisica = RutasSeguras::fisica($ruta, RutasSeguras::EMISIONES);
            } catch (EliminacionException $ex) {
                if ($estricto) {
                    throw $ex;
                }

                continue;
            }
            $rutas[$ruta] ??= $fisica !== null ? (int) filesize($fisica) : null;
        }

        // Un archivo que también aparece en otra base NUNCA se toca.
        foreach (array_chunk(array_keys($rutas), 500) as $grupo) {
            if (DB::table('cf_emisiones')->whereIn('pdf_archivo', $grupo)->where('lote_id', '!=', $loteId)->exists()) {
                if ($estricto) {
                    throw EliminacionException::archivoAjeno();
                }
                $rutas = [];
                break;
            }
        }

        $temporales = [];
        $bytesTemporales = 0;
        foreach (array_keys($operaciones) as $operacion) {
            try {
                $dir = RutasSeguras::stagingOperacion((string) $operacion);
                if (! $disco->exists($dir)) {
                    continue;
                }
                foreach ($disco->allFiles($dir) as $archivo) {
                    $fisica = RutasSeguras::fisica($archivo, $dir);
                    $temporales[] = $archivo;
                    $bytesTemporales += $fisica !== null ? (int) filesize($fisica) : 0;
                }
            } catch (EliminacionException $ex) {
                if ($estricto) {
                    throw $ex;
                }
            }
        }

        $existentes = array_filter($rutas, fn ($b) => $b !== null);

        return [
            'participantes' => Participante::withTrashed()->where('lote_id', $loteId)->count(),
            'vigentes' => $vigentes,
            'historicos' => $emisiones->count() - $vigentes,
            'emisiones' => $emisiones->count(),
            'ultima_emision' => (int) $emisiones->max('id'),
            'correos' => DB::table('cf_correos')->whereIn('participante_id', fn ($s) => $s->select('id')->from('cf_participantes')->where('lote_id', $loteId))->count(),
            'archivos' => count($existentes) + count($temporales),
            'bytes' => (int) array_sum($existentes) + $bytesTemporales,
            'rutas' => $rutas,
            'temporales' => $temporales,
        ];
    }

    /**
     * Una base con historial NO se elimina. Se comprueba ANTES de mover o borrar nada y con mensajes pensados para el
     * usuario (sin tablas, claves ni errores técnicos). Los correos (cf_correos) por sí solos NO bloquean: se borran con
     * los participantes cuando la base no tiene historial.
     *
     * Bloquean, por este orden de explicación: certificados históricos del evento de la base; emisiones de la base que
     * reemplazan a un certificado histórico; descargas (de sus emisiones o de sus participantes); cualquier emisión
     * (vigente, revocada o versión).
     *
     * @throws EliminacionException
     */
    private function exigirSinHistorial(int $loteId): void
    {
        $eventoId = DB::table('cf_lotes')->where('id', $loteId)->value('evento_id');
        if ($eventoId !== null && DB::table('cf_certificados_legado')->where('evento_id', $eventoId)->exists()) {
            throw EliminacionException::certificadosHistoricos();
        }

        $emisiones = fn () => DB::table('cf_emisiones')->where('lote_id', $loteId);

        if (DB::table('cf_certificados_legado')->whereIn('reemplazado_por_emision_id', $emisiones()->select('id'))->exists()) {
            throw EliminacionException::reemplazaHistoricos();
        }

        $descargas = DB::table('cf_descargas')->where(function ($q) use ($loteId, $emisiones) {
            $q->whereIn('emision_id', $emisiones()->select('id'))
                ->orWhereIn('participante_id', fn ($s) => $s->select('id')->from('cf_participantes')->where('lote_id', $loteId));
        });
        if ($descargas->exists()) {
            throw EliminacionException::conDescargas();
        }

        if ($emisiones()->exists()) {
            throw EliminacionException::conHistorial();
        }
    }

    /**
     * Archivos de la carpeta de una plantilla (calculada solo desde su id). Cada uno se valida como archivo real
     * dentro de esa carpeta, sin enlaces simbólicos.
     *
     * @return array{rutas:list<string>,bytes:int}
     *
     * @throws EliminacionException
     */
    private function archivosDePlantilla(int $id): array
    {
        $carpeta = RutasSeguras::carpetaPlantilla($id);
        $disco = Storage::disk(Plantilla::DISCO);
        if (! $disco->exists($carpeta)) {
            return ['rutas' => [], 'bytes' => 0];
        }

        $rutas = [];
        $bytes = 0;
        foreach ($disco->allFiles($carpeta) as $archivo) {
            $fisica = RutasSeguras::fisica($archivo, $carpeta);
            if ($fisica !== null) {
                $rutas[] = $archivo;
                $bytes += (int) filesize($fisica);
            }
        }

        return ['rutas' => $rutas, 'bytes' => $bytes];
    }

    /** Tras un fallo: los archivos vuelven a su sitio. Si alguno no puede volver, se conserva en la papelera y se avisa en el registro. */
    private function devolverArchivos(Papelera $papelera): void
    {
        if ($papelera->cantidad() > 0) {
            $papelera->restaurar();
        } else {
            $papelera->vaciar();
        }
    }
}
