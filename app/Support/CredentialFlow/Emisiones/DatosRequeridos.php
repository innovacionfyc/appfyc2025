<?php

namespace App\Support\CredentialFlow\Emisiones;

use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\CamposDinamicos;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Participantes\DatosDeParticipante;

/**
 * Datos OPCIONALES de la base (modalidad, ciudad, textos configurables) que el diseño de la plantilla imprime. Si faltan, la emisión se bloquea ANTES de generar
 * nada y con un mensaje que dice qué completar y dónde. Nunca se imprime un valor vacío ni se inventa uno.
 *
 * La modalidad también puede venir POR PERSONA (`cf_participantes.modalidad`): entonces la de la base solo hace falta para quien no tenga la suya.
 *
 * Solo mira los campos agregados después de la primera versión: el comportamiento de los cinco campos originales (evento, fecha, intensidad…) no cambia.
 */
final class DatosRequeridos
{
    /** Lo que ve el administrador cuando la plantilla usa «Modalidad y ciudad» y la base no tiene ciudad (panel, asistente, emisión y programación). */
    public const MENSAJE_CIUDAD = 'Esta plantilla utiliza «Modalidad y ciudad». Debes indicar la ciudad de la base antes de confirmar o emitir certificados.';

    /**
     * ¿El diseño exige la ciudad de la base? Solo cuando imprime el campo derivado «modalidad_ciudad» (se mira el DISEÑO, nunca el id de la plantilla; una
     * variante QA comparte el diseño de su oficial). El campo «ciudad» impreso por separado conserva su regla de siempre.
     *
     * @param  array<string,mixed>  $diseno
     */
    public static function exigeCiudad(array $diseno): bool
    {
        return in_array('modalidad_ciudad', DisenoSchema::camposUsados($diseno), true);
    }

    /**
     * ¿Falta la ciudad que este diseño exige? Ausente, vacía o solo espacios cuenta como falta.
     *
     * @param  array<string,mixed>  $diseno
     * @param  array<string,mixed>  $comunes  `cf_lotes.datos_comunes` (o los datos del borrador)
     */
    public static function faltaCiudad(array $diseno, array $comunes): bool
    {
        return self::exigeCiudad($diseno) && trim((string) ($comunes['ciudad'] ?? '')) === '';
    }

    /**
     * Bloquea ANTES de generar nada si el diseño usa «Modalidad y ciudad» y la base no tiene ciudad. La usan todas las emisiones (masiva, individual, QA,
     * reemisiones, reemplazo y programación).
     *
     * @throws EmisionException
     */
    public static function exigirCiudad(Lote $lote, Plantilla $plantilla): void
    {
        if (is_array($plantilla->diseno) && self::faltaCiudad($plantilla->diseno, $lote->datos_comunes ?? [])) {
            throw new EmisionException(EmisionException::DATOS_DEL_DISENO_INCOMPLETOS, self::MENSAJE_CIUDAD.' No se emitió ningún certificado.', 422,
                [['campo' => CamposDinamicos::todos()['ciudad']['etiqueta']]]);
        }
    }

    /**
     * Etiquetas de los datos opcionales que el diseño imprime y la base no tiene. Con `$modalidadDeTodos` = true (todas las personas consideradas tienen su
     * propia modalidad) la falta de la modalidad general no cuenta.
     *
     * @param  array<string,mixed>  $diseno
     * @param  array<string,mixed>  $comunes  `cf_lotes.datos_comunes`
     * @return list<string>
     */
    public static function faltantes(array $diseno, array $comunes, bool $modalidadDeTodos = false): array
    {
        $catalogo = CamposDinamicos::todos();
        $faltan = [];
        foreach (self::requeridos($diseno) as $campo) {
            if ($campo === 'modalidad' && $modalidadDeTodos) {
                continue;
            }
            if (in_array($campo, CamposDinamicos::COMUNES_OPCIONALES, true) && trim((string) ($comunes[$campo] ?? '')) === '') {
                $faltan[] = $catalogo[$campo]['etiqueta'];
            }
        }

        return array_values(array_unique($faltan));
    }

    /**
     * Datos de la base que el diseño necesita: los que imprime y, por cada campo derivado, los datos de los que depende. «Modalidad y ciudad» exige la
     * modalidad (de la persona o la general) y la ciudad de la base.
     *
     * @return list<string>
     */
    private static function requeridos(array $diseno): array
    {
        $usados = DisenoSchema::camposUsados($diseno);
        if (in_array('modalidad_ciudad', $usados, true)) {
            $usados[] = 'modalidad';
            $usados[] = 'ciudad';
        }

        return array_values(array_unique($usados));
    }

    /**
     * Personas (de la lista) que no tienen modalidad propia, cuando el diseño la imprime y la base no tiene una general. Vacío si nadie la necesita.
     *
     * @param  iterable<Participante>  $participantes
     * @return list<Participante>
     */
    public static function sinModalidad(array $diseno, Lote $lote, iterable $participantes): array
    {
        if (! in_array('modalidad', self::requeridos($diseno), true) || trim((string) (($lote->datos_comunes ?? [])['modalidad'] ?? '')) !== '') {
            return [];
        }
        $sin = [];
        foreach ($participantes as $p) {
            if (DatosDeParticipante::modalidadPropia($p) === null) {
                $sin[] = $p;
            }
        }

        return $sin;
    }

    /**
     * @param  iterable<Participante>|null  $participantes  las personas que se van a emitir (null = solo se mira la base, como antes)
     *
     * @throws EmisionException
     */
    public static function exigir(Lote $lote, Plantilla $plantilla, ?iterable $participantes = null): void
    {
        $diseno = $plantilla->diseno;
        if (! is_array($diseno)) {
            return; // el resto de controles ya informan una plantilla sin diseño
        }
        self::exigirCiudad($lote, $plantilla);
        $lista = $participantes === null ? [] : (is_array($participantes) ? $participantes : iterator_to_array($participantes, false));
        $sin = self::sinModalidad($diseno, $lote, $lista);
        $conPropia = $lista !== [] && count($sin) < count($lista);

        // Si alguna persona trae su propia modalidad, la general de la base ya no es obligatoria: solo se exige para quien no tenga la suya.
        $faltan = self::faltantes($diseno, $lote->datos_comunes ?? [], $lista !== [] && $sin === []);
        if ($conPropia && $sin !== []) {
            $faltan = array_values(array_diff($faltan, [CamposDinamicos::todos()['modalidad']['etiqueta']]));
            $n = count($sin);
            throw new EmisionException(
                EmisionException::DATOS_DEL_DISENO_INCOMPLETOS,
                "La plantilla imprime «Modalidad» y {$n} ".($n === 1 ? 'persona no tiene la suya' : 'personas no tienen la suya').' (y la base no tiene una general). Complétala en cada participante o como modalidad general en «Editar base». No se emitió ningún certificado.',
                422,
                array_map(fn (Participante $p) => ['participante_id' => $p->id, 'nombre' => $p->nombre_completo, 'mensaje' => 'Falta su modalidad.'], array_slice($sin, 0, 50))
            );
        }
        if ($faltan === []) {
            return;
        }

        $texto = count($faltan) === 1 ? '«'.$faltan[0].'»' : implode(', ', array_map(fn (string $e) => "«{$e}»", $faltan));
        throw new EmisionException(
            EmisionException::DATOS_DEL_DISENO_INCOMPLETOS,
            "La plantilla imprime {$texto} y esta base no tiene ".(count($faltan) === 1 ? 'ese dato' : 'esos datos').'. Complétalo en «Editar base» antes de emitir. No se emitió ningún certificado.',
            422,
            array_map(fn (string $e) => ['campo' => $e], $faltan)
        );
    }
}
