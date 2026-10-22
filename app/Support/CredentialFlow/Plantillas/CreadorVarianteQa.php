<?php

namespace App\Support\CredentialFlow\Plantillas;

use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Variante de PRUEBA INTERNA de una plantilla institucional, para emitir los certificados de los contactos de control (QA) con el MISMO diseño:
 *
 *  - Copia la plantilla con el duplicador de siempre (PDF base y diseño idénticos, el original no se toca).
 *  - Agrega, visible y arriba, «PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA».
 *  - Si el elemento «documento» no tiene «Texto antes», le pone «C.C. »: los contactos QA guardan solo el número (su dato y su clave OTP no cambian).
 *  - La designa como plantilla de pruebas internas y la asigna como variante QA del original (mecanismo QA existente).
 *  - Por defecto SOLO planea; `crear` es todo o nada: si algo falla después de copiar, la copia se elimina por completo.
 */
final class CreadorVarianteQa
{
    /** Franja libre sobre los logotipos del diseño institucional (puntos PDF desde arriba). */
    private const ADVERTENCIA_Y = 16;

    public function __construct(private readonly DuplicadorPlantilla $duplicador, private readonly ServicioQa $qa) {}

    /** @throws DuplicacionException */
    public function planear(int $origenId, ?string $nombre = null): array
    {
        $origen = $this->origen($origenId);
        $nombre = $nombre ?? self::nombrePorOmision($origen);
        $informe = $this->duplicador->planear($origenId, $nombre);
        $cambios = self::cambios((array) $origen->diseno);

        return $informe + [
            'variante' => [
                'nombre' => $nombre,
                'agrega_advertencia' => $cambios['agrega_advertencia'],
                'prefijo_documento' => $cambios['prefijo_documento'],
                'designada_qa' => true,
                'asignada_a' => $origen->id,
            ],
        ];
    }

    /**
     * @return array<string,mixed> el plan + `variante_id`
     *
     * @throws DuplicacionException
     */
    public function crear(int $origenId, ?string $nombre = null): array
    {
        $plan = $this->planear($origenId, $nombre);
        $informe = $this->duplicador->duplicar($origenId, $plan['variante']['nombre']);
        $copia = Plantilla::query()->findOrFail($informe['copia_id']);

        try {
            DB::transaction(function () use ($copia, $origenId) {
                $diseno = self::cambios((array) $copia->diseno)['diseno'];
                $copia->update(['diseno' => $diseno, 'schema_version' => max((int) $copia->schema_version, DisenoSchema::versionPara($diseno)),
                    'descripcion' => "Variante de PRUEBA INTERNA de la plantilla #{$origenId}: solo para los contactos de control (QA)."]);
                $this->qa->designarPlantilla($copia->fresh(), true);
                $this->qa->asignarVariante($this->origen($origenId), $copia->fresh());
            });
        } catch (Throwable $e) {
            $carpeta = $copia->carpeta();
            Plantilla::withTrashed()->whereKey($copia->id)->forceDelete();
            Storage::disk(Plantilla::DISCO)->deleteDirectory($carpeta);

            throw new DuplicacionException(DuplicacionException::COPIA_NO_FIEL, 'No se pudo preparar la variante de prueba ('.class_basename($e).'): no quedó ninguna copia. '.$e->getMessage());
        }

        Movimiento::registrar(tipo: 'registro', modulo: 'credential-flow', descripcion: "Se creó la variante de PRUEBA INTERNA #{$copia->id} de la plantilla #{$origenId}", extra: ['origen_id' => $origenId, 'variante_id' => $copia->id]);

        return $plan + ['variante_id' => $copia->id];
    }

    public static function nombrePorOmision(Plantilla $origen): string
    {
        return mb_substr('PRUEBA INTERNA — '.$origen->nombre, 0, 200);
    }

    /**
     * El diseño de la variante: el original + la advertencia visible (si no la trae) + «C.C. » antes del documento (si no tiene prefijo).
     *
     * @param  array<string,mixed>  $diseno
     * @return array{diseno: array<string,mixed>, agrega_advertencia: bool, prefijo_documento: bool}
     */
    public static function cambios(array $diseno): array
    {
        $elementos = (array) ($diseno['elements'] ?? []);
        $conAdvertencia = false;
        $prefijo = false;
        foreach ($elementos as $i => $el) {
            if (($el['type'] ?? null) === DisenoSchema::TIPO_TEXTO && str_contains(mb_strtoupper((string) ($el['text'] ?? '')), 'PRUEBA INTERNA')) {
                $conAdvertencia = true;
            }
            if (($el['field'] ?? null) === 'documento' && trim((string) ($el['prefix'] ?? '')) === '') {
                $elementos[$i]['prefix'] = 'C.C. ';
                $prefijo = true;
            }
        }
        if (! $conAdvertencia) {
            $ancho = (float) ($diseno['page']['width'] ?? 792);
            $elementos[] = [
                'id' => (string) Str::uuid(), 'type' => DisenoSchema::TIPO_TEXTO, 'field' => null, 'text' => ServicioQa::ADVERTENCIA,
                'x' => 40, 'y' => self::ADVERTENCIA_Y, 'width' => max(100, $ancho - 80), 'height' => 22,
                'fontFamily' => 'outfit', 'fontSize' => 14, 'fontWeight' => 700, 'color' => '#B91C1C', 'align' => 'center',
            ];
        }
        $diseno['elements'] = array_values($elementos);

        return ['diseno' => $diseno, 'agrega_advertencia' => ! $conAdvertencia, 'prefijo_documento' => $prefijo];
    }

    /** @throws DuplicacionException */
    private function origen(int $id): Plantilla
    {
        $p = Plantilla::query()->find($id);
        if ($p === null) {
            throw new DuplicacionException(DuplicacionException::ORIGEN_NO_EXISTE, "La plantilla #{$id} no existe.");
        }
        if ($p->variante_qa_id !== null && Plantilla::query()->whereKey($p->variante_qa_id)->exists()) {
            throw new DuplicacionException(DuplicacionException::COPIA_IDENTICA_EXISTE, "La plantilla #{$id} ya tiene su variante de prueba (#{$p->variante_qa_id}): no se crea otra.");
        }

        return $p;
    }
}
