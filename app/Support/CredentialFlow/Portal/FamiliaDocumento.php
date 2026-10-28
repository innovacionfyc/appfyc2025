<?php

namespace App\Support\CredentialFlow\Portal;

use App\Support\CredentialFlow\Participantes\Texto;
use Illuminate\Support\Facades\DB;

/**
 * Un MISMO número de documento puede estar guardado con su tipo delante o sin él: el importador, cuando la base trae la columna de tipo, guarda
 * «C.C. 1.234.567» (clave `CC1234567`); los históricos y las bases sin esa columna guardan solo el número (`1234567`). En el portal la persona escribe su
 * número, con o sin tipo. Esta clase define la FAMILIA de claves de un número (la RAÍZ y la raíz con cada prefijo de tipo) y decide qué registros de la
 * familia NO pueden unirse. No cambia ningún dato guardado: solo cómo se busca.
 *
 * Regla de identidad (las de AccesoPortal no se relajan; solo se amplía la búsqueda a la familia):
 *  - UNIDAD = mismo número + mismo grupo de nombre conservador (con los vínculos explícitos vigentes). Dentro de una unidad, un registro SIN tipo es compatible
 *    con su tipo; dos tipos explícitos DISTINTOS (C.C. y T.I., C.E. y C.C.…) no se suponen la misma persona, ni siquiera con el mismo nombre: la unidad es AMBIGUA.
 *  - En una unidad ambigua solo quedan los registros guardados con el número tal cual (lo que el portal ya mostraba antes); los guardados con prefijo se EXCLUYEN
 *    (no se muestran, no autentican, no se descargan) hasta que un administrador lo resuelva. Nunca se une por número solo.
 *  - Nombres distintos con el mismo número son unidades distintas y siguen las reglas de homónimos (el correo debe identificar exactamente una).
 *  - Los ceros a la izquierda son significativos: `01234567` y `1234567` NO son la misma familia (nada se quita del número).
 */
final class FamiliaDocumento
{
    /** Prefijos de tipo tal como quedan en la clave (`Texto::claveDocumento` de «C.C.», «C.E.», «T.I.», «PASAPORTE», «NIT», «PEP», «PPT»). */
    public const TIPOS = ['PASAPORTE', 'NIT', 'PEP', 'PPT', 'CC', 'CE', 'TI'];

    /** Mismo mínimo que AccesoPortal: un prefijo solo se separa si lo que queda es un número de documento completo. */
    private const MIN_NUMERO = 4;

    /** Número sin el prefijo de tipo (`CC1234567` → `1234567`); una clave sin prefijo reconocible se devuelve tal cual. */
    public static function raiz(string $clave): string
    {
        $c = Texto::claveDocumento($clave);
        $t = self::tipoDeClave($c);

        return $t === null ? $c : substr($c, strlen($t));
    }

    /** Tipo explícito de una clave (`CC`, `TI`…), o null si se guardó sin tipo. */
    public static function tipoDeClave(string $clave): ?string
    {
        foreach (self::TIPOS as $t) {
            if (str_starts_with($clave, $t) && self::numeroDe($t, substr($clave, strlen($t)))) {
                return $t;
            }
        }

        return null;
    }

    /** @return list<string> todas las claves guardables de ese número: la raíz y la raíz con cada prefijo de tipo que le corresponde */
    public static function claves(string $clave): array
    {
        $raiz = self::raiz($clave);
        $claves = [$raiz];
        foreach (self::TIPOS as $t) {
            if (self::numeroDe($t, $raiz)) {
                $claves[] = $t.$raiz;
            }
        }

        return $claves;
    }

    /**
     * Tipo registrado en el sistema anterior (`cf_certificados_legado.tipo_documento`), en la misma forma que el prefijo de una clave. Vacío = sin tipo. Un valor
     * desconocido cuenta como un tipo PROPIO (nunca se supone C.C.): así solo puede impedir una unión, jamás provocarla.
     */
    public static function tipoLegado(?string $tipo): ?string
    {
        $t = Texto::claveDocumento((string) $tipo);

        return match ($t) {
            '' => null,
            'CC', 'CEDULA', 'CEDULADECIUDADANIA', 'CEDULACIUDADANIA' => 'CC',
            'CE', 'CEDULADEEXTRANJERIA', 'CEDULAEXTRANJERIA' => 'CE',
            'TI', 'TARJETADEIDENTIDAD', 'TARJETAIDENTIDAD' => 'TI',
            'PA', 'PAS', 'PASAPORTE' => 'PASAPORTE',
            default => $t,
        };
    }

    /**
     * Registros de la familia que NO pueden mostrarse porque su unidad es ambigua (dos tipos explícitos distintos con el mismo número y el mismo nombre). Solo se
     * excluyen los guardados con prefijo; los guardados con el número tal cual siguen como siempre. Para decidirlo cuentan TODOS los participantes con emisión
     * (aunque luego no se muestren): contar de más solo puede excluir, nunca unir.
     *
     * @return array{historicos: array<int,true>, participantes: array<int,true>, unidades: int}
     */
    public static function excluidos(string $clave, ?int $desde = null): array
    {
        $raiz = self::raiz($clave);
        $claves = self::claves($raiz);
        $vacio = ['historicos' => [], 'participantes' => [], 'unidades' => 0];
        if (count($claves) === 1) {
            return $vacio;   // sin prefijos posibles no hay nada que unir ni excluir
        }
        $historicos = DB::table('cf_certificados_legado')->whereIn('documento_clave', $claves)->get(['id', 'documento_clave', 'tipo_documento', 'nombre_completo']);
        $participantes = DB::table('cf_participantes as p')->whereIn('p.documento_clave', $claves)->whereNull('p.deleted_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('cf_emisiones as e')->whereColumn('e.participante_id', 'p.id'))
            ->get(['p.id', 'p.documento_clave', 'p.nombre_completo']);
        // Sin ningún registro con prefijo, nada puede excluirse (el caso de siempre: cero trabajo extra).
        $conPrefijo = fn ($f) => ! hash_equals($raiz, Texto::claveDocumento((string) $f->documento_clave));
        if (! $historicos->contains($conPrefijo) && ! $participantes->contains($conPrefijo)) {
            return $vacio;
        }

        $vinculos = VinculosIdentidadModerna::mapa($raiz, $desde);
        $unidades = [];
        foreach ($historicos as $f) {
            $c = Texto::claveDocumento((string) $f->documento_clave);
            $g = NombreConservador::grupoId((string) $f->nombre_completo);
            // El mismo grupo con el que AccesoPortal::cargarConVinculos lee la fila.
            $unidades[$vinculos[$g] ?? $g][] = ['h', (int) $f->id, self::tipoDeClave($c) ?? self::tipoLegado($f->tipo_documento), $c !== $raiz];
        }
        foreach ($participantes as $p) {
            $c = Texto::claveDocumento((string) $p->documento_clave);
            $g = NombreConservador::grupoId((string) $p->nombre_completo);
            $unidades[$vinculos[$g] ?? $g][] = ['p', (int) $p->id, self::tipoDeClave($c), $c !== $raiz];
        }

        $r = $vacio;
        foreach ($unidades as $registros) {
            $tipos = array_unique(array_filter(array_column($registros, 2), fn ($t) => $t !== null));
            if (count($tipos) < 2) {
                continue;
            }
            $r['unidades']++;
            foreach ($registros as [$origen, $id, , $prefijo]) {
                if ($prefijo) {
                    $r[$origen === 'h' ? 'historicos' : 'participantes'][$id] = true;
                }
            }
        }

        return $r;
    }

    private static function numeroDe(string $tipo, string $resto): bool
    {
        if (strlen($resto) < self::MIN_NUMERO) {
            return false;
        }

        // Pasaporte: letras y números (con al menos un número). Los demás tipos: solo números.
        return $tipo === 'PASAPORTE' ? preg_match('/^[A-Z0-9]*\d[A-Z0-9]*$/', $resto) === 1 : ctype_digit($resto);
    }
}
