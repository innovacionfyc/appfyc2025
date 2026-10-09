<?php

namespace App\Support\CredentialFlow\Eliminacion;

use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\LogSeguro;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Zona de espera privada de UNA eliminación definitiva: `credential-flow/papelera/{uuid}/{n}`. Los archivos se
 * MUEVEN aquí (mismo disco: renombrado atómico) antes de tocar la base de datos. Si la base falla, `restaurar()`
 * los devuelve a su sitio; si todo sale bien, `vaciar()` los borra. Solo mueve rutas que el llamador ya validó
 * con RutasSeguras, y solo borra su propia carpeta.
 */
final class Papelera
{
    /** Solo para tests: se ejecuta antes de cada movimiento (origen, destino, índice) y puede lanzar para simular un fallo. */
    public static ?Closure $alMover = null;

    /** Solo para tests: se ejecuta antes de cada restauración (origen, destino). */
    public static ?Closure $alRestaurar = null;

    /** Solo para tests: se ejecuta antes de vaciar la papelera (puede lanzar para simular un fallo de borrado). */
    public static ?Closure $alVaciar = null;

    public readonly string $raiz;

    /** @var array<string,string> original => destino en la papelera */
    private array $movidos = [];

    public function __construct()
    {
        $this->raiz = RutasSeguras::PAPELERA.'/'.Str::uuid();
    }

    /** @throws EliminacionException si el archivo no se pudo mover (lo ya movido se devuelve al llamar a restaurar()). */
    public function mover(string $origen): void
    {
        $destino = $this->raiz.'/'.count($this->movidos);
        $disco = Storage::disk(Plantilla::DISCO);

        try {
            if (self::$alMover !== null) {
                (self::$alMover)($origen, $destino, count($this->movidos));
            }
            if (! $disco->move($origen, $destino)) {
                throw new RuntimeException('move devolvió false');
            }
        } catch (Throwable $e) {
            Log::error('Credential Flow: no se pudo mover un archivo a la papelera', ['error' => LogSeguro::resumen($e)]);

            throw EliminacionException::errorArchivos();
        }

        $this->movidos[$origen] = $destino;
    }

    public function cantidad(): int
    {
        return count($this->movidos);
    }

    /** Devuelve cada archivo a su ruta original. Si alguno no se puede devolver queda registrado y se conserva en la papelera. */
    public function restaurar(): bool
    {
        $disco = Storage::disk(Plantilla::DISCO);
        $completo = true;

        foreach (array_reverse($this->movidos, true) as $origen => $destino) {
            try {
                if (self::$alRestaurar !== null) {
                    (self::$alRestaurar)($origen, $destino);
                }
                if ($disco->exists($origen) || ! $disco->move($destino, $origen)) {
                    throw new RuntimeException('no se pudo devolver el archivo');
                }
                unset($this->movidos[$origen]);
            } catch (Throwable $e) {
                $completo = false;
                Log::critical('Credential Flow: no se pudo restaurar un archivo desde la papelera; se conserva allí', [
                    'papelera' => $this->raiz,
                    'error' => LogSeguro::resumen($e),
                ]);
            }
        }

        if ($completo) {
            $this->vaciar();
        }

        return $completo;
    }

    /** Borra la carpeta de esta papelera (y solo esa). */
    public function vaciar(): bool
    {
        try {
            if (self::$alVaciar !== null) {
                (self::$alVaciar)($this->raiz);
            }
            Storage::disk(Plantilla::DISCO)->deleteDirectory($this->raiz);
            $this->movidos = [];

            return ! Storage::disk(Plantilla::DISCO)->exists($this->raiz);
        } catch (Throwable $e) {
            Log::error('Credential Flow: no se pudo vaciar la papelera', ['papelera' => $this->raiz, 'error' => LogSeguro::resumen($e)]);

            return false;
        }
    }
}
