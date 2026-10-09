<?php

namespace App\Support\CredentialFlow\Portal;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Correo;
use App\Support\CredentialFlow\Legado\CodigoHistorico;
use App\Support\CredentialFlow\Legado\ElegibilidadLegado;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Reemplazo\EmisionVigente;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de acceso al portal y alcance de la sesión. Solo lee; nunca escribe en el histórico.
 *
 * POLÍTICA (documento + correo autenticado + GRUPO DE NOMBRE CONSERVADOR; ver NombreConservador):
 *  - Documento con UN solo grupo de nombre: se conserva el comportamiento original (cualquier correo válido del documento autentica,
 *    alcance = documento completo, `grupo = null`).
 *  - Documento con VARIOS grupos: el correo digitado se busca en `cf_correos` de los certificados del documento. Si identifica
 *    EXACTAMENTE un grupo, ese grupo es el alcance (y se ven TODAS las filas del grupo, tengan o no ese correo). Si aparece en más de
 *    un grupo, o en ninguno, no hay OTP (respuesta pública idéntica; el caso queda para revisión manual).
 *  - Siempre debe haber al menos un certificado habilitante (`ok` / `duplicado_consolidado`) dentro del alcance.
 * La similitud difusa de nombres NO interviene jamás. Sin N+1: una consulta por documento (+ correos, eventos, canónicos y reemplazos).
 */
final class AccesoPortal
{
    /** Documento mínimo aceptado (evita claves triviales o vacías como «0»). */
    private const MIN_DOCUMENTO = 4;

    private const HABILITANTES = [CertificadoLegado::CONCILIACION_OK, CertificadoLegado::CONCILIACION_DUPLICADO_CONSOLIDADO];

    public static function claveDocumento(string $documento): ?string
    {
        $clave = Texto::claveDocumento(trim($documento));

        return $clave !== '' && mb_strlen($clave) >= self::MIN_DOCUMENTO && mb_strlen($clave) <= 40 ? $clave : null;
    }

    /** Correo comparable (trim + minúsculas) o null si no es una dirección válida. */
    public static function correoNormalizado(string $correo): ?string
    {
        $n = Correo::normalizar($correo);

        return Correo::estadoDe($n) === Correo::ESTADO_VALIDO ? $n : null;
    }

    /**
     * Alcance con el que se emitiría un OTP para este documento + correo, o null si NO debe emitirse.
     *
     * @return array{grupo: ?string}|null `grupo` null = documento completo; si no, HMAC del grupo de nombre conservador
     */
    public function alcance(string $clave, string $correoNormalizado): ?array
    {
        $d = $this->cargar($clave);
        if ($d['filas']->isEmpty()) {
            return null;
        }
        $conCorreo = $d['filas']->filter(fn ($f) => isset($d['correos'][$f->id][$correoNormalizado]));
        if ($conCorreo->isEmpty()) {
            return null;
        }

        if ($d['filas']->map(fn ($f) => $d['grupos'][$f->id])->unique()->count() <= 1) {
            $grupo = null;
            $alcance = $d['filas'];
        } else {
            $grupos = $conCorreo->map(fn ($f) => $d['grupos'][$f->id])->unique()->values();
            if ($grupos->count() !== 1) {
                return null;
            }
            $grupo = $grupos->first();
            $alcance = $d['filas']->filter(fn ($f) => $d['grupos'][$f->id] === $grupo);
        }

        return $alcance->contains(fn ($f) => self::habilitaAcceso($f)) ? ['grupo' => $grupo] : null;
    }

    /**
     * ¿Esta fila habilita el acceso al portal? Una fila CONCILIADA (`ok` / `duplicado_consolidado`) o un histórico REEMPLAZADO por una emisión
     * moderna (10B-2B-2C: lo único utilizable del alcance puede ser su reemplazo). Esto NO toca documento, correo, grupo ni las reglas multi-identidad:
     * solo decide si el alcance YA determinado tiene algo que mostrar. La emisión moderna nunca autentica ni amplía el alcance.
     */
    public static function habilitaAcceso(CertificadoLegado $f): bool
    {
        return in_array($f->conciliacion_estado, self::HABILITANTES, true)
            || ($f->estado === CertificadoLegado::ESTADO_REEMPLAZADO && $f->reemplazado_por_emision_id !== null);
    }

    /** ¿Puede este documento + correo recibir un OTP? */
    public function habilita(string $clave, string $correoNormalizado): bool
    {
        return $this->alcance($clave, $correoNormalizado) !== null;
    }

    /**
     * Tarjetas del panel dentro del alcance: UNA por certificado lógico (duplicados y variantes del grupo se colapsan DENTRO del alcance).
     *
     * @return list<array<string,mixed>>
     */
    public function tarjetas(string $clave, string|array|null $grupo = null): array
    {
        $d = $this->cargar($clave);
        $lista = self::lista($grupo);
        if ($lista === []) {
            return [];   // un scope vacío NUNCA es «documento completo»
        }
        $filas = $lista === null ? $d['filas'] : $d['filas']->filter(fn ($f) => in_array($d['grupos'][$f->id], $lista, true));
        $ids = $filas->pluck('id')->flip();

        $eventos = DB::table('cf_eventos')->whereIn('id', $filas->pluck('evento_id')->unique())->get(['id', 'nombre', 'anio'])->keyBy('id');
        // Las cadenas de reemplazo de TODOS los históricos reemplazados del documento, en lote (una consulta por nivel, nunca una por tarjeta). Se incluyen
        // los de fuera del alcance solo para resolver el canónico de una copia; la tarjeta solo usa lo que está dentro del alcance autorizado.
        $cadenas = collect(EmisionVigente::resolverLote($d['filas']->pluck('reemplazado_por_emision_id')->filter()->unique()->values()->all()));
        // Canónico de cada grupo de duplicados consolidados (misma regla que ElegibilidadLegado::canonico, en un solo viaje).
        $grupos = $filas->where('conciliacion_estado', CertificadoLegado::CONCILIACION_DUPLICADO_CONSOLIDADO)->pluck('grupo_duplicado')->filter()->unique();
        $canonicos = $grupos->isEmpty() ? collect() : DB::table('cf_certificados_legado as k')
            ->join('cf_migraciones_map as m', fn ($j) => $j->on('m.destino_id', '=', 'k.id')->where('m.destino_tabla', 'cf_certificados_legado')->where('m.relacion', 'canonico'))
            ->whereIn('k.grupo_duplicado', $grupos)->orderBy('k.id')->get(['k.id', 'k.grupo_duplicado'])->unique('grupo_duplicado')->pluck('id', 'grupo_duplicado');

        $porClave = [];
        foreach ($filas as $f) {
            $porClave[$f->grupo_duplicado ?? 'id:'.$f->id][] = $f;
        }

        $todas = $d['filas']->keyBy('id');
        // Código efectivo (legado o asignado por Credential Flow) SIN asignar nada y sin consultas por tarjeta.
        $codigos = CodigoHistorico::paraFilas($d['filas']);
        $tarjetas = [];
        foreach ($porClave as $grupoFilas) {
            // Dentro del grupo (y del alcance) manda la fila que lleva el REEMPLAZO (la raíz del certificado lógico, 10B-2B-2C.1); si no la hay, la
            // conciliada; si tampoco, la primera.
            $f = collect($grupoFilas)->first(fn ($x) => $x->reemplazado_por_emision_id !== null)
                ?? collect($grupoFilas)->first(fn ($x) => $x->conciliacion_estado === CertificadoLegado::CONCILIACION_OK) ?? $grupoFilas[0];
            $e = $eventos->get($f->evento_id);
            $base = ['id' => (int) $f->id, 'evento' => (string) ($e->nombre ?? ''), 'anio' => $e?->anio === null ? null : (int) $e->anio, 'codigo' => $codigos[$f->id] ?? null, 'descargable' => false, 'enlace_moderno' => null];
            $tarjetas[] = $this->tarjeta($f, $base, $todas, $ids, $canonicos, $cadenas);
        }
        usort($tarjetas, fn ($a, $b) => [$b['anio'] ?? 0, $a['evento']] <=> [$a['anio'] ?? 0, $b['evento']]);

        return $tarjetas;
    }

    private function tarjeta(CertificadoLegado $f, array $base, Collection $todas, Collection $enAlcance, Collection $canonicos, Collection $cadenas): array
    {
        if ($f->estado === CertificadoLegado::ESTADO_REVOCADO) {
            return $base + ['tipo' => 'revocado', 'mensaje' => 'Certificado revocado.'];
        }
        if ($f->estado === CertificadoLegado::ESTADO_REEMPLAZADO || $f->reemplazado_por_emision_id !== null) {
            return $this->tarjetaReemplazo($f, $base, $cadenas);
        }

        return match ($f->conciliacion_estado) {
            CertificadoLegado::CONCILIACION_REVISION_DOCUMENTO => $base + ['tipo' => 'revision', 'mensaje' => 'Este certificado necesita revisión. Escríbenos y te ayudamos.'],
            CertificadoLegado::CONCILIACION_PENDIENTE => $base + ['tipo' => 'revision_conciliacion', 'mensaje' => 'Estamos revisando este certificado. Escríbenos y te ayudamos.'],
            CertificadoLegado::CONCILIACION_PENDIENTE_PLANTILLA => $base + ['tipo' => 'plantilla', 'mensaje' => 'Estamos recuperando este certificado. Escríbenos y te ayudamos.'],
            default => $this->conPdf($base, $f, $todas, $enAlcance, $canonicos, $cadenas),
        };
    }

    /**
     * Tarjeta de un histórico REEMPLAZADO (10B-2B-2C): UNA sola tarjeta, con el estado de la emisión moderna VIGENTE (cadena A → B → C). Nunca el PDF
     * histórico, nunca A/B como vigentes y, ante cualquier anomalía de la cadena, ningún detalle técnico: «necesita revisión», sin descarga.
     *
     * @param  array<string,mixed>  $base
     * @return array<string,mixed>
     */
    private function tarjetaReemplazo(CertificadoLegado $f, array $base, Collection $cadenas): array
    {
        $r = $f->reemplazado_por_emision_id === null ? null : $cadenas->get((int) $f->reemplazado_por_emision_id);
        $base['codigo'] = null;   // nunca el código histórico en una tarjeta actualizada
        if ($r === null || $r['estado'] === EmisionVigente::ESTADO_ANOMALIA) {
            return $base + ['tipo' => 'revision', 'mensaje' => 'Este certificado necesita revisión.'];
        }
        if ($r['estado'] === EmisionVigente::ESTADO_REVOCADA) {
            return $base + ['tipo' => 'revocado', 'mensaje' => 'Certificado revocado.'];
        }

        return array_merge($base, ['tipo' => 'actualizado', 'mensaje' => 'Certificado actualizado. Disponible para descargar.', 'codigo' => $r['vigente']->codigo, 'estado_emision' => 'Vigente', 'descargable' => true]);
    }

    /**
     * Certificado conciliado: se ofrece la descarga solo si su CANÓNICO es elegible Y está dentro del alcance autorizado. Si resolver al
     * canónico llevaría fuera del grupo, NO se hace en silencio: la tarjeta queda como revisión.
     */
    private function conPdf(array $base, CertificadoLegado $f, Collection $todas, Collection $enAlcance, Collection $canonicos, Collection $cadenas): array
    {
        $id = $f->conciliacion_estado === CertificadoLegado::CONCILIACION_DUPLICADO_CONSOLIDADO ? $canonicos->get($f->grupo_duplicado) : $f->id;
        $canonico = $id === null || ! $enAlcance->has($id) ? null : $todas->get($id);
        // Una copia consolidada cuyo canónico (dentro del alcance) fue reemplazado muestra el reemplazo, igual que el canónico.
        if ($canonico !== null && ($canonico->estado === CertificadoLegado::ESTADO_REEMPLAZADO || $canonico->reemplazado_por_emision_id !== null)) {
            return $this->tarjetaReemplazo($canonico, $base, $cadenas);
        }

        if ($canonico !== null && ElegibilidadLegado::motivo($canonico) === null) {
            return array_merge($base, ['tipo' => 'disponible', 'mensaje' => 'Disponible para descargar.', 'descargable' => true]);
        }

        return $base + ['tipo' => 'revision', 'mensaje' => 'Este certificado necesita revisión. Escríbenos y te ayudamos.'];
    }

    /**
     * Punto ÚNICO de autorización por certificado: documento + grupos del alcance. `$grupos`: null = documento completo (solo cuando el flujo histórico lo
     * autoriza); un string = ese grupo (compatibilidad con sesiones históricas); una lista = cualquiera de esos grupos; una lista VACÍA = denegar (jamás «todo»).
     * La descarga nunca se autoriza solo por id.
     */
    public static function perteneceA(CertificadoLegado $c, string $clave, string|array|null $grupos = null): bool
    {
        if (! hash_equals($c->documento_clave, $clave)) {
            return false;
        }
        $lista = self::lista($grupos);
        if ($lista === null) {
            return true;
        }

        return $lista !== [] && in_array(NombreConservador::grupoDe($c), $lista, true);
    }

    /** ¿Está el certificado (p. ej. el canónico al que resuelve) dentro del alcance? */
    public static function enAlcance(?CertificadoLegado $c, string $clave, string|array|null $grupos): bool
    {
        return $c !== null && self::perteneceA($c, $clave, $grupos);
    }

    /** @return list<string>|null null = documento completo; lista (posiblemente vacía) = esos grupos */
    private static function lista(string|array|null $grupos): ?array
    {
        if ($grupos === null) {
            return null;
        }

        return array_values(array_unique(array_map('strval', is_array($grupos) ? $grupos : [$grupos])));
    }

    /**
     * Filas del documento + correos válidos + grupo de nombre de cada fila, en pocas consultas.
     *
     * @return array{filas: Collection<int,CertificadoLegado>, correos: array<int,array<string,bool>>, grupos: array<int,string>}
     */
    public function cargar(string $clave): array
    {
        $filas = CertificadoLegado::query()->where('documento_clave', $clave)->get();
        $correos = [];
        if ($filas->isNotEmpty()) {
            foreach (DB::table('cf_correos')->whereIn('certificado_legado_id', $filas->pluck('id'))->where('estado', Correo::ESTADO_VALIDO)->get(['certificado_legado_id', 'correo_normalizado']) as $r) {
                $correos[$r->certificado_legado_id][$r->correo_normalizado] = true;
            }
        }
        $grupos = [];
        foreach ($filas as $f) {
            $grupos[$f->id] = NombreConservador::grupoDe($f);
        }

        return ['filas' => $filas, 'correos' => $correos, 'grupos' => $grupos];
    }
}
