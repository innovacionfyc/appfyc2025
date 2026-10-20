<?php

namespace App\Support\CredentialFlow\Plantillas;

use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Services\CredentialFlow\PlantillaService;
use App\Support\CredentialFlow\DisenoSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copia SEGURA de una plantilla (por ejemplo, la institucional «Certificado de asistencia — Virtual») para trabajar sobre la copia sin tocar el original.
 *
 * Qué se copia: el PDF base (byte a byte) y el diseño (JSON y versión del esquema). La copia es una plantilla NUEVA e independiente: id, carpeta y archivo propios,
 * no es de pruebas internas (QA) ni variante de nada, y no hereda marcas del original (origen histórico, QA).
 *
 * Garantías:
 *  - `planear` no escribe nada (es lo que muestra la simulación).
 *  - Nunca se modifica el original: se compara su huella (PDF, diseño, versión y fila) antes y después; si cambió, la copia se deshace.
 *  - Todo o nada: el alta usa el mismo servicio de siempre (registro + archivo en una transacción); después la copia se VERIFICA (PDF, hash y diseño idénticos al origen)
 *    y, si algo no cuadra, se elimina por completo (no queda registro ni carpeta).
 *  - Sin duplicados: nombre único entre las plantillas vigentes y rechazo de una copia idéntica ya existente; un cerrojo evita dos ejecuciones simultáneas.
 */
final class DuplicadorPlantilla
{
    private const CERROJO = 'cf-plantilla-duplicar';

    public function __construct(private readonly PlantillaService $plantillas) {}

    /**
     * Valida todo y describe qué se copiaría. No escribe nada.
     *
     * @return array<string,mixed>
     *
     * @throws DuplicacionException
     */
    public function planear(int $origenId, string $nombre): array
    {
        $nombre = $this->nombreValido($nombre);
        $origen = $this->origen($origenId);
        $bytes = $this->pdfDelOrigen($origen);
        $this->exigirNombreLibre($nombre);

        $diseno = (array) $origen->diseno;
        $hash = hash('sha256', $bytes);
        foreach (Plantilla::query()->whereKeyNot($origen->id)->where('hash_sha256', $hash)->get() as $otra) {
            if ($this->igual($otra->diseno, $diseno) && (int) $otra->schema_version === (int) $origen->schema_version) {
                throw new DuplicacionException(DuplicacionException::COPIA_IDENTICA_EXISTE, "Ya existe una copia idéntica de esta plantilla (#{$otra->id}). Úsala o edítala en lugar de crear otra.");
            }
        }

        $fijos = array_values(array_filter(array_map(
            fn ($e) => ($e['type'] ?? null) === DisenoSchema::TIPO_TEXTO && (($e['field'] ?? null) === null || ($e['field'] ?? '') === '') && trim((string) ($e['text'] ?? '')) !== '' ? mb_substr(trim((string) $e['text']), 0, 80) : null,
            (array) ($diseno['elements'] ?? [])
        )));
        $campos = DisenoSchema::camposUsados($diseno);
        $advertencias = [];
        if (in_array('modalidad', $campos, true)) {
            foreach ($fijos as $texto) {
                if (stripos($texto, 'modalidad') !== false) {
                    $advertencias[] = "El diseño ya imprime el campo «modalidad» y además trae el texto fijo «{$texto}»: se verían repetidos.";
                }
            }
        }

        return [
            'origen' => [
                'id' => $origen->id, 'nombre' => $origen->nombre, 'bytes' => strlen($bytes), 'hash' => $hash, 'schema_version' => (int) $origen->schema_version,
                'elementos' => count((array) ($diseno['elements'] ?? [])), 'campos' => $campos, 'textos_fijos' => $fijos,
                'bases' => $origen->lotes()->count(), 'emisiones' => $origen->emisiones()->count(),
            ],
            'copia' => ['nombre' => $nombre, 'es_plantilla_qa' => false, 'variante_qa_id' => null],
            'se_escribiria' => ['una fila nueva en cf_plantillas', 'un archivo nuevo credential-flow/plantillas/{id nuevo}/base.pdf'],
            'advertencias' => $advertencias,
        ];
    }

    /**
     * Duplica de verdad. Todo o nada.
     *
     * @return array<string,mixed> informe con `copia_id`
     *
     * @throws DuplicacionException
     */
    public function duplicar(int $origenId, string $nombre): array
    {
        $cerrojo = Cache::lock(self::CERROJO, 120);
        if (! $cerrojo->get()) {
            throw new DuplicacionException(DuplicacionException::OCUPADO, 'Ya hay una duplicación en curso. Espera a que termine y vuelve a intentarlo.');
        }

        try {
            $informe = $this->planear($origenId, $nombre);   // se valida de nuevo, ya con el cerrojo
            $origen = $this->origen($origenId);
            $antes = $this->huella($origen);
            $bytes = $this->pdfDelOrigen($origen);

            $copia = $this->plantillas->crearDesdePdf(
                $informe['copia']['nombre'],
                "Copia de la plantilla #{$origen->id} «{$origen->nombre}».",
                (string) $origen->nombre_archivo_original,
                $bytes,
                ['diseno' => $origen->diseno, 'schema_version' => $origen->schema_version],
            );

            try {
                $this->verificarCopia($copia, $origen, $bytes);
                if ($this->huella($this->origen($origenId)) !== $antes) {
                    throw new DuplicacionException(DuplicacionException::ORIGEN_CAMBIO, 'La plantilla original cambió mientras se copiaba. No se dejó ninguna copia; vuelve a intentarlo.');
                }
            } catch (DuplicacionException $e) {
                $this->deshacer($copia);

                throw $e;
            }

            Movimiento::registrar(tipo: 'registro', modulo: 'credential-flow', descripcion: "Se duplicó la plantilla #{$origen->id} como #{$copia->id} (copia independiente, sin tocar el original)", extra: [
                'origen_id' => $origen->id, 'copia_id' => $copia->id, 'hash' => substr($informe['origen']['hash'], 0, 12),
            ]);

            return $informe + ['copia_id' => $copia->id];
        } finally {
            $cerrojo->release();
        }
    }

    // ── Validaciones ──────────────────────────────────────────────────────────

    private function nombreValido(string $nombre): string
    {
        $limpio = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]/u', '', $nombre) ?? '') ?? '');
        if ($limpio === '' || mb_strlen($limpio) > 200) {
            throw new DuplicacionException(DuplicacionException::NOMBRE_INVALIDO, 'Indica el nombre de la copia (entre 1 y 200 caracteres).');
        }

        return $limpio;
    }

    private function exigirNombreLibre(string $nombre): void
    {
        $clave = $this->clave($nombre);
        foreach (Plantilla::query()->get(['id', 'nombre']) as $p) {
            if ($this->clave((string) $p->nombre) === $clave) {
                throw new DuplicacionException(DuplicacionException::NOMBRE_DUPLICADO, "Ya existe una plantilla con ese nombre (#{$p->id}). Elige otro nombre.");
            }
        }
    }

    private function clave(string $nombre): string
    {
        return (string) Str::of($nombre)->ascii()->lower()->replaceMatches('/\s+/', ' ')->trim();
    }

    private function origen(int $id): Plantilla
    {
        $origen = Plantilla::query()->find($id);
        if ($origen === null) {
            throw new DuplicacionException(Plantilla::onlyTrashed()->whereKey($id)->exists() ? DuplicacionException::ORIGEN_ELIMINADA : DuplicacionException::ORIGEN_NO_EXISTE, Plantilla::onlyTrashed()->whereKey($id)->exists() ? 'La plantilla original está eliminada: no se puede duplicar.' : 'No existe esa plantilla.');
        }
        if ((bool) ($origen->es_plantilla_qa ?? false)) {
            throw new DuplicacionException(DuplicacionException::ORIGEN_ES_QA, 'Esa es una plantilla de pruebas internas (QA): no se duplica como plantilla institucional.');
        }
        $diseno = $origen->diseno;
        if (! is_array($diseno) || (array) ($diseno['elements'] ?? []) === [] || ! isset($diseno['page']['width'], $diseno['page']['height'])) {
            throw new DuplicacionException(DuplicacionException::ORIGEN_SIN_DISENO, 'La plantilla original todavía no tiene un diseño guardado: no hay nada que copiar.');
        }
        if (! DisenoSchema::soportada((int) $origen->schema_version)) {
            throw new DuplicacionException(DuplicacionException::ORIGEN_SIN_DISENO, 'El diseño de la plantilla original usa una versión que este sistema no reconoce.');
        }

        return $origen;
    }

    /** Lee el PDF base del original y comprueba que sigue siendo el que la plantilla dice tener. */
    private function pdfDelOrigen(Plantilla $origen): string
    {
        $disco = Storage::disk(Plantilla::DISCO);
        if ($origen->archivo_pdf !== $origen->rutaPdfEsperada() || ! $disco->exists($origen->archivo_pdf)) {
            throw new DuplicacionException(DuplicacionException::ORIGEN_SIN_PDF, 'No se encontró el PDF base de la plantilla original.');
        }
        $bytes = $disco->get($origen->archivo_pdf);
        if (! is_string($bytes) || preg_match('/%PDF-[12]\.\d/', substr($bytes, 0, 1024)) !== 1) {
            throw new DuplicacionException(DuplicacionException::ORIGEN_SIN_PDF, 'El archivo base de la plantilla original no es un PDF válido.');
        }
        if (! hash_equals((string) $origen->hash_sha256, hash('sha256', $bytes))) {
            throw new DuplicacionException(DuplicacionException::ORIGEN_PDF_ALTERADO, 'El PDF base de la plantilla original no coincide con su huella guardada: no se copia algo que no se puede verificar.');
        }

        return $bytes;
    }

    // ── Verificación y deshacer ───────────────────────────────────────────────

    private function verificarCopia(Plantilla $copia, Plantilla $origen, string $bytes): void
    {
        $copia = Plantilla::query()->findOrFail($copia->id);
        $disco = Storage::disk(Plantilla::DISCO);
        $archivo = $disco->exists($copia->rutaPdfEsperada()) ? $disco->get($copia->rutaPdfEsperada()) : null;

        $fiel = $copia->id !== $origen->id
            && $copia->archivo_pdf === $copia->rutaPdfEsperada() && $copia->archivo_pdf !== $origen->archivo_pdf
            && is_string($archivo) && hash_equals(hash('sha256', $bytes), hash('sha256', $archivo))
            && hash_equals((string) $origen->hash_sha256, (string) $copia->hash_sha256)
            && $this->igual($copia->diseno, $origen->diseno) && (int) $copia->schema_version === (int) $origen->schema_version
            && ! (bool) $copia->es_plantilla_qa && $copia->variante_qa_id === null
            && $copia->origen_legado_sha256 === null && $copia->origen_legado_meta === null;

        if (! $fiel) {
            throw new DuplicacionException(DuplicacionException::COPIA_NO_FIEL, 'La copia no quedó idéntica al original (PDF o diseño). Se deshizo: no queda ninguna copia.');
        }
    }

    /** Elimina por completo una copia recién creada (nunca tuvo bases ni emisiones). */
    private function deshacer(Plantilla $copia): void
    {
        $carpeta = $copia->carpeta();
        Plantilla::withTrashed()->whereKey($copia->id)->forceDelete();
        Storage::disk(Plantilla::DISCO)->deleteDirectory($carpeta);
    }

    /** Huella del original: si cambia algo entre el antes y el después, la copia no vale. */
    private function huella(Plantilla $p): string
    {
        $disco = Storage::disk(Plantilla::DISCO);
        $archivo = $disco->exists($p->archivo_pdf) ? hash('sha256', (string) $disco->get($p->archivo_pdf)) : 'sin-archivo';
        $fila = $p->getRawOriginal();
        ksort($fila);

        return hash('sha256', json_encode([$archivo, $fila], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function igual(mixed $a, mixed $b): bool
    {
        return json_encode($a, JSON_UNESCAPED_UNICODE) === json_encode($b, JSON_UNESCAPED_UNICODE);
    }
}
