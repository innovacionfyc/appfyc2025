<?php

namespace App\Support\CredentialFlow\Importacion;

use App\Models\CredentialFlow\EventoCertificacion;
use App\Support\CredentialFlow\Eventos\ServicioEventos;
use App\Support\CredentialFlow\Portal\NombreConservador;

/**
 * Propone a qué evento pertenece un Excel, SIN escribir nada y sin decidir: el administrador siempre confirma.
 *
 * Compara el nombre del Excel con los eventos NATIVOS activos (mismo año o sin año). Un nombre que solo se diferencia por texto adicional (p. ej. un
 * «(Ley …)» entre paréntesis) NO es otro evento: se propone asociarlo al existente y se muestra la diferencia palabra por palabra.
 *
 * Coincidencias: `exacta` (mismo nombre normalizado), `contenido` (uno incluye al otro) o `similar` (muy parecidos).
 */
final class ProponedorEvento
{
    /** Eventos nativos que se comparan como máximo (el catálogo real tiene decenas). */
    private const LIMITE = 500;

    private const MIN_CONTENIDO = 20;

    private const RAZON_CONTENIDO = 0.6;

    private const SIMILITUD = 85.0;

    /**
     * @return array{
     *     nombre_excel: ?string, fecha_excel: ?string, anio: ?int, accion: string, evento_propuesto_id: ?int, requiere_confirmacion: bool,
     *     candidatos: list<array<string,mixed>>, homonimo_historico: bool, advertencias: list<string>
     * }
     */
    public function proponer(?string $nombreExcel, ?string $fechaExcel): array
    {
        $nombre = trim((string) $nombreExcel);
        $fecha = trim((string) $fechaExcel);
        $anio = $fecha === '' ? null : ServicioEventos::anioDe($fecha);
        $base = [
            'nombre_excel' => $nombre === '' ? null : $nombre, 'fecha_excel' => $fecha === '' ? null : $fecha, 'anio' => $anio,
            'accion' => 'sin_nombre', 'evento_propuesto_id' => null, 'requiere_confirmacion' => true, 'candidatos' => [], 'homonimo_historico' => false, 'advertencias' => [],
        ];
        if ($nombre === '') {
            $base['advertencias'][] = 'El Excel no trae el nombre del evento: el administrador debe escribirlo o elegir un evento existente.';

            return $base;
        }

        $norm = NombreConservador::normalizar($nombre);
        $candidatos = [];
        $eventos = EventoCertificacion::query()
            ->where('origen', EventoCertificacion::ORIGEN_CREDENTIAL_FLOW)
            ->where('estado', EventoCertificacion::ESTADO_ACTIVO)
            ->when($anio !== null, fn ($q) => $q->where(fn ($w) => $w->where('anio', $anio)->orWhereNull('anio')))
            ->orderBy('id')->limit(self::LIMITE)->get(['id', 'nombre', 'nombre_normalizado', 'fecha_texto', 'anio']);

        foreach ($eventos as $e) {
            $nivel = $this->coincidencia($norm, (string) $e->nombre_normalizado);
            if ($nivel === null) {
                continue;
            }
            $candidatos[] = [
                'id' => $e->id, 'nombre' => $e->nombre, 'fecha' => $e->fecha_texto, 'anio' => $e->anio,
                'coincidencia' => $nivel, 'diferencia' => $this->diferencia($nombre, (string) $e->nombre),
            ];
        }
        $orden = ['exacta' => 0, 'contenido' => 1, 'similar' => 2];
        usort($candidatos, fn ($a, $b) => [$orden[$a['coincidencia']], $a['id']] <=> [$orden[$b['coincidencia']], $b['id']]);

        $base['candidatos'] = $candidatos;
        $base['homonimo_historico'] = $anio !== null && EventoCertificacion::query()->where('origen', EventoCertificacion::ORIGEN_LEGADO)->where('nombre_normalizado', $norm)->where('anio', $anio)->exists();
        if ($candidatos === []) {
            $base['accion'] = 'crear_nuevo';
            $base['advertencias'][] = 'No hay un evento parecido: si es un evento nuevo, el administrador debe confirmarlo antes de crearlo.';
            if ($base['homonimo_historico']) {
                $base['advertencias'][] = 'Existe un evento histórico con el mismo nombre y año: confirma que este es un evento nuevo y distinto.';
            }

            return $base;
        }

        $primero = $candidatos[0];
        $base['accion'] = 'asociar_existente';
        $base['evento_propuesto_id'] = $primero['id'];
        $base['advertencias'][] = $primero['coincidencia'] === 'exacta'
            ? 'Ya existe un evento con este nombre: se propone asociar la base a él. Requiere confirmación.'
            : 'El nombre del Excel difiere del nombre del evento registrado'.($primero['diferencia']['adicional_en_excel'] ? ' (el Excel agrega «'.$primero['diferencia']['adicional_en_excel'].'»)' : '').': se propone asociar la base al evento existente y NO crear otro. Requiere confirmación.';
        if (count($candidatos) > 1) {
            $base['advertencias'][] = 'Hay varios eventos parecidos: elige a cuál pertenece la base.';
        }

        return $base;
    }

    private function coincidencia(string $a, string $b): ?string
    {
        if ($a === '' || $b === '') {
            return null;
        }
        if ($a === $b) {
            return 'exacta';
        }
        [$corto, $largo] = strlen($a) <= strlen($b) ? [$a, $b] : [$b, $a];
        if (strlen($corto) >= self::MIN_CONTENIDO && str_contains($largo, $corto) && strlen($corto) / strlen($largo) >= self::RAZON_CONTENIDO) {
            return 'contenido';
        }
        if (abs(strlen($a) - strlen($b)) <= 40) {
            similar_text($a, $b, $porcentaje);
            if ($porcentaje >= self::SIMILITUD) {
                return 'similar';
            }
        }

        return null;
    }

    /**
     * Palabras que el Excel trae de más o de menos frente al nombre registrado (se muestran tal como están escritas).
     *
     * @return array{iguales: bool, adicional_en_excel: ?string, faltante_en_excel: ?string}
     */
    private function diferencia(string $excel, string $evento): array
    {
        $palabras = function (string $texto): array {
            $salida = [];
            foreach (preg_split('/\s+/u', trim($texto)) ?: [] as $palabra) {
                $n = NombreConservador::normalizar($palabra);
                if ($n !== '') {
                    $salida[] = [$n, $palabra];
                }
            }

            return $salida;
        };
        $pe = $palabras($excel);
        $pv = $palabras($evento);
        $normE = array_column($pe, 0);
        $normV = array_column($pv, 0);
        $extra = $this->fuera($pe, $normV);
        $falta = $this->fuera($pv, $normE);

        return ['iguales' => $extra === null && $falta === null, 'adicional_en_excel' => $extra, 'faltante_en_excel' => $falta];
    }

    /**
     * Palabras de `$propias` que no están en la otra lista. Si la otra lista aparece completa y seguida dentro de `$propias`, solo cuentan las palabras de los
     * extremos (así «(Ley» no se descarta por existir también en el resto del nombre).
     *
     * @param  list<array{0:string,1:string}>  $propias  [normalizada, original]
     * @param  list<string>  $otras  palabras normalizadas de la otra lista
     */
    private function fuera(array $propias, array $otras): ?string
    {
        $n = count($otras);
        $normales = array_column($propias, 0);
        if ($n > 0) {
            for ($i = 0; $i + $n <= count($normales); $i++) {
                if (array_slice($normales, $i, $n) === $otras) {
                    $resto = [...array_slice($propias, 0, $i), ...array_slice($propias, $i + $n)];

                    return $resto === [] ? null : implode(' ', array_column($resto, 1));
                }
            }
        }
        $resto = array_filter($propias, fn ($p) => ! in_array($p[0], $otras, true));

        return $resto === [] ? null : implode(' ', array_column($resto, 1));
    }
}
