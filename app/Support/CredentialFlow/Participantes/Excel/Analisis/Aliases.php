<?php

namespace App\Support\CredentialFlow\Participantes\Excel\Analisis;

use App\Support\CredentialFlow\Participantes\Texto;

/**
 * Catálogo de equivalencias de rótulos y encabezados (ya normalizados con `Texto::encabezado`: sin mayúsculas, tildes ni separadores).
 *
 * Reglas de uso:
 *  - una coincidencia EXACTA con un alias es una detección confiable;
 *  - una coincidencia APROXIMADA (el rótulo contiene un alias largo, o se parece a él) es solo una PROPUESTA: se marca como ambigua y nunca confirma sola un
 *    dato crítico;
 *  - nada de lo que no esté aquí se interpreta: lo desconocido se informa tal cual.
 */
final class Aliases
{
    /** Datos del evento (bloque administrativo del archivo). */
    public const EVENTO = [
        'evento' => ['nombredelevento', 'nombredelaactividad', 'nombredelacapacitacion', 'nombredelaactividadacademica', 'nombredelevento', 'actividadacademica', 'denominaciondelevento', 'evento', 'actividad', 'capacitacion', 'curso', 'programa'],
        'fecha' => ['fechadelevento', 'fechasdelevento', 'fechadeevento', 'fechadelaactividad', 'fechadelacapacitacion', 'fechasdelaactividad', 'fechas', 'fecha'],
        'modalidad' => ['modalidaddelevento', 'modalidaddelaactividad', 'modalidaddelacapacitacion', 'modalidad'],
        'intensidad_horaria' => ['intensidaddehoras', 'intensidadhoraria', 'intensidaddelevento', 'numerodehoras', 'cantidaddehoras', 'duracionenhoras', 'duraciondelevento', 'nodehoras', 'duracion', 'intensidad', 'horas'],
        'ciudad' => ['ciudaddelevento', 'ciudad', 'municipio'],
        'lugar' => ['lugardelevento', 'lugar', 'sede'],
        'gestora_comercial' => ['gestoracomercialencargada', 'gestoracomercial', 'gestorcomercial', 'asesorcomercial'],
        'entidad' => ['razonsocial', 'entidad', 'cliente', 'empresa'],
    ];

    /** Columnas de la tabla de participantes. */
    public const COLUMNAS = [
        'nombre_completo' => ['nombresyapellidos', 'apellidosynombres', 'nombrecompletodelparticipante', 'nombreyapellidos', 'nombreyapellido', 'nombrecompleto', 'participante', 'asistente', 'nombres', 'nombre'],
        'documento' => ['numerodeidentificacion', 'numerodedocumento', 'documentodeidentidad', 'nodeidentificacion', 'nrodedocumento', 'numerodocumento', 'identificacion', 'nodocumento', 'cedulaciudadania', 'documento', 'cedula', 'doc', 'cc'],
        'tipo_documento' => ['tipodedocumento', 'tipodeidentificacion', 'tipoidentificacion', 'tipodocumento', 'tipodoc', 'tipoid'],
        'correo' => ['correoelectronicoinstitucional', 'correoelectronicopersonal', 'correoelectronico', 'correoinstitucional', 'direccioncorreo', 'emailaddress', 'correo', 'email', 'mail'],
        'sesion' => ['sesionasistida', 'sesiones', 'sesion'],
        'certificado_completo' => ['certificadoconintensidadcompleta', 'certificadointensidadcompleta', 'intensidadcompleta'],
        'seleccion_cupo' => ['selecciondesesionesocupoacertificar', 'selecciondesesiones', 'cupoacertificar', 'cupos', 'cupo'],
        'diploma' => ['requierediploma', 'diploma'],
        'observaciones' => ['observacionesocomentariosadicionales', 'observaciones', 'observacion', 'comentarios', 'notas'],
        'elegibilidad' => ['estadodecertificacion', 'estadodeasistencia', 'elegibilidad', 'asistencia', 'estado'],
        'intensidad_individual' => ['intensidadhorariaindividual', 'intensidadindividual', 'horasindividuales', 'horascertificadas'],
        // Modalidad de CADA persona (un mismo Excel puede traer presenciales y virtuales). Se conserva literal; nunca se traduce ni se inventa.
        'modalidad' => ['modalidaddeparticipacion', 'modalidaddeasistencia', 'modalidadasistida', 'modalidaddelevento', 'modalidad'],
        'item' => ['consecutivo', 'item', 'numero', 'no', 'n'],
    ];

    /** Campos que, si su columna no es segura, vuelven ambiguos todos sus valores. */
    public const CRITICAS = ['nombre_completo', 'documento', 'tipo_documento', 'correo', 'elegibilidad', 'intensidad_individual'];

    /** Largo mínimo de un alias para aceptarlo como coincidencia APROXIMADA por inclusión («correo electrónico (institucional)»). */
    private const ALIAS_LARGO = 8;

    /** @var array<string,array<string,string>>|null tipo => (alias => campo) */
    private static ?array $indice = null;

    /** @return array{0: string, 1: string}|null [campo, método] con método «exacto» | «aproximado» */
    public static function campoDeColumna(string $encabezado): ?array
    {
        return self::buscar('columnas', self::COLUMNAS, Texto::encabezado($encabezado));
    }

    /** @return array{0: string, 1: string}|null */
    public static function campoDeEvento(string $rotulo): ?array
    {
        return self::buscar('evento', self::EVENTO, Texto::encabezado($rotulo));
    }

    /** ¿El texto parece un rótulo del bloque del evento? (para no tomar un rótulo como si fuera el valor de otro) */
    public static function esRotuloEvento(string $texto): bool
    {
        $n = Texto::encabezado($texto);

        return $n !== '' && (isset(self::indice('evento', self::EVENTO)[$n]));
    }

    /** @param  array<string,list<string>>  $catalogo */
    private static function buscar(string $clave, array $catalogo, string $normalizado): ?array
    {
        if ($normalizado === '') {
            return null;
        }
        $indice = self::indice($clave, $catalogo);
        if (isset($indice[$normalizado])) {
            return [$indice[$normalizado], 'exacto'];
        }

        // Aproximado, solo como propuesta: el rótulo CONTIENE un alias largo (gana el más largo) o se parece mucho a uno.
        $mejor = null;
        $largo = 0;
        foreach ($indice as $alias => $campo) {
            if (strlen($alias) >= self::ALIAS_LARGO && strlen($alias) > $largo && str_contains($normalizado, $alias)) {
                $mejor = $campo;
                $largo = strlen($alias);
            }
        }
        if ($mejor !== null) {
            return [$mejor, 'aproximado'];
        }
        if (strlen($normalizado) >= self::ALIAS_LARGO && strlen($normalizado) <= 60) {
            $mejorSim = 0.0;
            foreach ($indice as $alias => $campo) {
                if (strlen($alias) < self::ALIAS_LARGO || abs(strlen($alias) - strlen($normalizado)) > 3 || substr($alias, 0, 2) !== substr($normalizado, 0, 2)) {
                    continue;
                }
                similar_text($normalizado, $alias, $porcentaje);
                if ($porcentaje >= 88.0 && $porcentaje > $mejorSim) {
                    $mejorSim = $porcentaje;
                    $mejor = $campo;
                }
            }
        }

        return $mejor === null ? null : [$mejor, 'aproximado'];
    }

    /**
     * @param  array<string,list<string>>  $catalogo
     * @return array<string,string> alias normalizado => campo
     */
    private static function indice(string $clave, array $catalogo): array
    {
        if (self::$indice === null) {
            self::$indice = [];
        }
        if (! isset(self::$indice[$clave])) {
            $m = [];
            foreach ($catalogo as $campo => $alias) {
                foreach ($alias as $a) {
                    $m[Texto::encabezado($a)] ??= $campo;
                }
            }
            self::$indice[$clave] = $m;
        }

        return self::$indice[$clave];
    }
}
