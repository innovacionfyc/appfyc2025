<?php

namespace App\Support\CredentialFlow\Importacion;

use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Importacion;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\CamposDinamicos;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Emisiones\ServicioVistaPrevia;
use App\Support\CredentialFlow\Generacion\DatosCredencial;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Participantes\ValidadorDatosComunes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Vista previa del certificado a partir de un BORRADOR (F4). Solo lee y dibuja: no crea emisiones, no genera códigos oficiales, no toca snapshots, no guarda el PDF
 * y no modifica ninguna plantilla. Usa el mismo motor PDF y los mismos datos dinámicos de F2 que la emisión, de modo que lo que se ve es lo que se imprimiría.
 *
 * Si todavía no se puede dibujar el PDF (falta elegir la plantilla, falta un dato que la plantilla imprime…) devuelve EXACTAMENTE qué falta y los datos que se
 * imprimirían, sin simular que existe un PDF final.
 */
final class VistaPreviaImportacion
{
    /** Orden en que se listan los datos del certificado. */
    private const CLAVES = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria', 'modalidad', 'ciudad', 'texto_certificacion', 'texto_complementario'];

    /**
     * @return array<string,mixed>
     *
     * @throws ImportacionException
     */
    public function datos(Importacion $importacion, ?int $fila): array
    {
        $p = $this->preparar($importacion, $fila);
        $incluidos = $p['incluidos'];
        $masLargo = collect($incluidos)->sortByDesc(fn ($x) => mb_strlen((string) $x['nombre_completo']))->first();

        return [
            'participante' => $p['participante'] === null ? null : ['fila' => $p['participante']['fila'], 'nombre_completo' => $p['participante']['nombre_completo'], 'tipo_documento' => $p['participante']['tipo_documento'],
                'modalidad' => $p['participante']['modalidad'] ?? null],
            'participantes' => array_map(fn ($x) => ['fila' => $x['fila'], 'nombre_completo' => $x['nombre_completo'], 'modalidad' => $x['modalidad'] ?? null], $incluidos),
            // Una persona de ejemplo por cada modalidad propia, para revisar el PDF de todas antes de crear la base.
            'una_por_modalidad' => collect($incluidos)->filter(fn ($x) => ($x['modalidad'] ?? null) !== null && $x['modalidad'] !== '')->unique('modalidad')
                ->map(fn ($x) => ['modalidad' => $x['modalidad'], 'fila' => $x['fila']])->values()->all(),
            'fila_nombre_mas_largo' => $masLargo['fila'] ?? null,
            'plantilla' => $p['plantilla'] === null ? null : ['id' => $p['plantilla']->id, 'nombre' => $p['plantilla']->nombre],
            'campos' => $p['campos'],
            'problemas' => $p['problemas'],
            'avisos' => $p['avisos'],
            'pdf_disponible' => $p['problemas'] === [],
            'es_vista_previa' => true,   // nunca es un certificado emitido
        ];
    }

    /**
     * Bytes del PDF de vista previa.
     *
     * @throws ImportacionException|GeneracionCredencialException
     */
    public function pdf(Importacion $importacion, ?int $fila): string
    {
        $p = $this->preparar($importacion, $fila);
        if ($p['plantilla'] === null) {
            throw new ImportacionException(ImportacionException::VISTA_PREVIA_SIN_PLANTILLA, $p['problemas'][0] ?? 'Elige una plantilla para ver la vista previa.', 409);
        }
        if ($p['problemas'] !== []) {
            throw new ImportacionException(ImportacionException::VISTA_PREVIA_DATOS_INCOMPLETOS, 'Todavía no se puede dibujar el PDF: '.implode(' ', $p['problemas']), 422, array_map(fn ($m) => ['mensaje' => $m], $p['problemas']));
        }

        $bytes = GeneradorCredencialPdf::generar($p['plantilla'], DatosCredencial::fromArray($p['datos']));
        $this->registrarRevision($importacion, $p['plantilla'], $p['comunes']);

        return $bytes;
    }

    /**
     * Huella de los datos del certificado que son de la BASE (evento, fecha, intensidad, modalidad general, ciudad, textos): si cambian después de ver el PDF,
     * la revisión ya no vale. Los datos de cada persona no cuentan (la vista previa muestra solo una de ejemplo).
     *
     * @param  array<string,string>  $comunes
     */
    public static function huellaDatos(array $comunes): string
    {
        ksort($comunes);

        return hash('sha256', json_encode($comunes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Deja constancia en el borrador de que el administrador VIO el PDF con esta plantilla y estos datos. Al crear la base, si nada de eso cambió, la base no
     * vuelve a pedir la misma revisión (ver ServicioImportaciones::confirmar). No cambia la versión del borrador ni lo que el administrador está editando.
     *
     * @param  array<string,string>  $comunes
     */
    private function registrarRevision(Importacion $importacion, Plantilla $plantilla, array $comunes): void
    {
        DB::transaction(function () use ($importacion, $plantilla, $comunes) {
            $actual = Importacion::query()->whereKey($importacion->id)->lockForUpdate()->first();
            if ($actual === null || ! $actual->enRevision()) {
                return;
            }
            $corr = $actual->correcciones ?? [];
            $corr['vista_previa'] = ['plantilla_id' => $plantilla->id, 'huella' => ServicioVistaPrevia::huella($plantilla), 'datos' => self::huellaDatos($comunes), 'at' => now()->toIso8601String()];
            $actual->correcciones = $corr;
            $actual->save();
        });
    }

    /**
     * @return array{plantilla: ?Plantilla, participante: ?array<string,mixed>, incluidos: list<array<string,mixed>>, datos: array<string,string>, campos: list<array<string,mixed>>, problemas: list<string>, avisos: list<string>}
     *
     * @throws ImportacionException
     */
    private function preparar(Importacion $importacion, ?int $fila): array
    {
        if (! $importacion->enRevision() || $importacion->vencido() || ! is_array($importacion->analisis)) {
            throw new ImportacionException(ImportacionException::NO_EN_REVISION, 'El borrador ya no está en revisión.', 409);
        }
        $analisis = $importacion->analisis;
        $corr = array_replace_recursive(Borrador::vacias(), $importacion->correcciones ?? []);
        $comunes = Borrador::datosComunes($analisis, $corr);
        $problemas = [];
        $avisos = [];

        // Evento: el del evento existente si se eligió; si no, el nombre que se escribió o el del Excel (provisional).
        $decision = $corr['evento'];
        $nombreEvento = match ($decision['decision'] ?? null) {
            'asociar' => EventoCertificacion::query()->find($decision['evento_id'] ?? 0)?->nombre,
            'crear' => $decision['nombre'] ?? $comunes['evento']['valor'],
            default => $comunes['evento']['valor'],
        };
        if (($decision['decision'] ?? null) === null) {
            $avisos[] = 'Todavía no elegiste el evento: la vista previa usa el nombre que trae el Excel.';
        }
        $entrada = ['evento' => $nombreEvento];
        foreach (Borrador::COMUNES as $c) {
            if ($c !== 'evento' && $comunes[$c]['valor'] !== null) {
                $entrada[$c] = $comunes[$c]['valor'];
            }
        }
        $validacion = ValidadorDatosComunes::validar($entrada);
        $datos = $validacion['datos'];
        $comunesValidados = $datos;   // los de la base, antes de poner los de la persona de ejemplo
        $erroresComunes = $validacion['errores'];

        // Persona de ejemplo.
        $incluidos = array_values(array_filter(Borrador::participantes($analisis, $corr), fn ($x) => ! $x['excluir']));
        $persona = collect($incluidos)->firstWhere('fila', $fila) ?? ($incluidos[0] ?? null);
        if ($persona === null) {
            $problemas[] = 'No queda ningún participante para mostrar.';
        } else {
            $nombre = Texto::mayusculas(Texto::limpiar((string) ($persona['nombre_completo'] ?? '')));
            $doc = Texto::limpiar((string) ($persona['documento'] ?? ''));
            $tipo = $persona['tipo_documento'];
            if ($tipo !== null && $doc !== '' && ! str_starts_with(Texto::claveDocumento($doc), Texto::claveDocumento($tipo))) {
                $doc = $tipo.' '.$doc;
            }
            if ($nombre !== '') {
                $datos['nombre_completo'] = $nombre;
            }
            if ($doc !== '') {
                $datos['documento'] = $doc;
            }
            if (($persona['intensidad_horaria'] ?? null) !== null && $persona['intensidad_horaria'] !== '') {
                $datos['intensidad_horaria'] = (string) $persona['intensidad_horaria'];
            }
            // Su propia modalidad (si la trae) reemplaza a la general, igual que en el certificado oficial.
            if (($persona['modalidad'] ?? null) !== null && $persona['modalidad'] !== '') {
                $datos['modalidad'] = (string) $persona['modalidad'];
            }
            if ($tipo === null) {
                $avisos[] = 'El tipo de documento de esta persona está por confirmar: el certificado oficial no se podrá emitir hasta confirmarlo.';
            }
        }

        // Plantilla.
        $plantilla = $corr['plantilla_id'] === null ? null : Plantilla::query()->find($corr['plantilla_id']);
        if ($plantilla === null) {
            $problemas[] = 'Elige la plantilla del certificado para ver cómo se imprimirá.';
        } elseif (($plantilla->es_plantilla_qa ?? false) || ! ServicioVistaPrevia::plantillaValida($plantilla)) {
            $problemas[] = 'La plantilla elegida no tiene un diseño válido para certificados oficiales.';
            $plantilla = null;
        } elseif (! Storage::disk(Plantilla::DISCO)->exists($plantilla->rutaPdfEsperada())) {
            $problemas[] = 'La plantilla elegida no tiene su PDF base disponible.';
            $plantilla = null;
        }

        $usados = $plantilla === null ? null : DisenoSchema::camposUsados($plantilla->diseno);
        $catalogo = CamposDinamicos::todos();
        $campos = [];
        foreach (self::CLAVES as $clave) {
            $valor = $datos[$clave] ?? null;
            $imprime = $usados === null ? null : in_array($clave, $usados, true);
            $falta = $imprime === true && $valor === null;
            $campos[] = ['clave' => $clave, 'etiqueta' => $catalogo[$clave]['etiqueta'], 'valor' => $valor, 'se_imprime' => $imprime, 'falta' => $falta, 'varias_lineas' => $catalogo[$clave]['multilinea']];
            if ($falta) {
                $problemas[] = "La plantilla imprime «{$catalogo[$clave]['etiqueta']}» y ese dato todavía no está completo.";
            }
        }
        if ($plantilla !== null) {
            foreach ($erroresComunes as $campo => $mensaje) {
                if (in_array($campo, $usados ?? [], true)) {
                    $problemas[] = $mensaje;
                }
            }
        }

        return ['plantilla' => $plantilla, 'participante' => $persona, 'incluidos' => $incluidos, 'datos' => $datos, 'comunes' => $comunesValidados, 'campos' => $campos, 'problemas' => array_values(array_unique($problemas)), 'avisos' => $avisos];
    }
}
