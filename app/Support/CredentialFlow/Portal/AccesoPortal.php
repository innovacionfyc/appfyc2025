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
use Illuminate\Support\Str;

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
        $d = $this->cargarConVinculos($clave);
        $m = EmisionesModernas::filas($clave);
        if ($d['filas']->isEmpty() && $m->isEmpty()) {
            return null;
        }
        // Filas del documento, históricas y modernas, con la MISMA forma: grupo de nombre conservador, correos válidos y si habilitan el acceso.
        // Sin filas modernas el resultado es idéntico al de siempre; con ellas, las mismas reglas (un solo grupo → documento completo; varios → el
        // correo debe identificar EXACTAMENTE uno) valen para ambos tipos y un correo nunca amplía el alcance a un grupo que no lo tiene.
        $filas = $d['filas']->map(fn ($f) => ['grupo' => $d['grupos'][$f->id], 'correo' => isset($d['correos'][$f->id][$correoNormalizado]), 'habilita' => self::habilitaAcceso($f)])
            ->concat($m->map(fn ($f) => ['grupo' => $f->grupo, 'correo' => isset($f->correos[$correoNormalizado]), 'habilita' => EmisionesModernas::habilita($f)]));
        $conCorreo = $filas->filter(fn ($f) => $f['correo']);
        if ($conCorreo->isEmpty()) {
            return null;
        }

        if ($filas->pluck('grupo')->unique()->count() <= 1) {
            $grupo = null;
            $alcance = $filas;
        } else {
            $grupos = $conCorreo->pluck('grupo')->unique()->values();
            if ($grupos->count() !== 1) {
                return null;
            }
            $grupo = $grupos->first();
            $alcance = $filas->filter(fn ($f) => $f['grupo'] === $grupo);
        }

        return $alcance->contains(fn ($f) => $f['habilita']) ? ['grupo' => $grupo] : null;
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

        $eventos = DB::table('cf_eventos')->whereIn('id', $filas->pluck('evento_id')->unique())->get(['id', 'nombre', 'anio', 'fecha_texto'])->keyBy('id');
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
            // Un histórico NO tiene fecha de publicación fiable (su `created_at` es la fecha de la migración): `publicado_at` queda null y nunca se inventa.
            $base = ['id' => (int) $f->id, 'evento' => (string) ($e->nombre ?? ''), 'anio' => $e?->anio === null ? null : (int) $e->anio, 'codigo' => $codigos[$f->id] ?? null, 'descargable' => false, 'enlace_moderno' => null,
                'publicado_at' => null, 'realizado' => ($e->fecha_texto ?? null) ?: null];
            $tarjetas[] = $this->tarjeta($f, $base, $todas, $ids, $canonicos, $cadenas);
        }
        usort($tarjetas, fn ($a, $b) => [$b['anio'] ?? 0, $a['evento']] <=> [$a['anio'] ?? 0, $b['evento']]);

        return $tarjetas;
    }

    /**
     * Tarjetas de los certificados MODERNOS dentro del alcance autorizado (mismo documento y mismos grupos de nombre que la sesión). `moderno = true`:
     * su `id` es el de la EMISIÓN y NUNCA es intercambiable con el de un certificado histórico (la descarga usa una ruta propia).
     *
     * @return list<array<string,mixed>>
     */
    public function tarjetasModernas(string $clave, string|array|null $grupo = null, ?int $desde = null): array
    {
        if (self::lista($grupo) === []) {
            return [];   // un scope vacío NUNCA es «documento completo»
        }
        $tarjetas = [];
        $filas = EmisionesModernas::filas($clave, $desde);
        if (! $this->documentoCompletoSeguro($clave, $grupo, $filas, $desde)) {
            return [];
        }
        foreach ($filas as $f) {
            if (! EmisionesModernas::pertenece($f, $grupo)) {
                continue;
            }
            $vigente = EmisionesModernas::habilita($f);
            $anio = self::anioDe($f->datos['fecha'] ?? null, $f->emitido_at);
            $tarjetas[] = [
                'id' => $f->emision_id, 'moderno' => true, 'evento' => (string) ($f->datos['evento'] ?? ''), 'anio' => $anio, 'codigo' => $f->codigo, 'descargable' => $vigente, 'enlace_moderno' => null,
                'tipo' => $vigente ? 'disponible' : 'revocado', 'mensaje' => $vigente ? 'Disponible para descargar.' : 'Certificado revocado.',
                // Fecha REAL de publicación (emisión) y fecha del evento tal como se imprimió: son cosas distintas.
                'publicado_at' => self::fechaHora($f->emitido_at ?? null), 'realizado' => is_string($f->datos['fecha'] ?? null) && $f->datos['fecha'] !== '' ? $f->datos['fecha'] : null,
            ];
        }

        return $tarjetas;
    }

    /** Fila moderna de una emisión SOLO si pertenece al documento y al alcance de la sesión (la descarga nunca se autoriza por id solo). */
    public function emisionModerna(string $clave, string|array|null $grupo, int $emisionId, ?int $desde = null): ?object
    {
        if (self::lista($grupo) === []) {
            return null;
        }
        $filas = EmisionesModernas::filas($clave, $desde);
        if (! $this->documentoCompletoSeguro($clave, $grupo, $filas, $desde)) {
            return null;
        }
        foreach ($filas as $f) {
            if ($f->emision_id === $emisionId && EmisionesModernas::pertenece($f, $grupo)) {
                return $f;
            }
        }

        return null;
    }

    /**
     * Una sesión de «documento completo» (`grupo = null`) nació cuando el documento tenía UN solo grupo de nombre. Si después aparece otro grupo (p. ej. una
     * emisión nueva a nombre de otra persona con el mismo documento), la sesión NO lo hereda: falla cerrado y no muestra ni entrega certificados modernos hasta
     * que la persona vuelva a identificarse con su correo (que entonces sí identifica su grupo).
     *
     * @param  Collection<int,object>  $modernas
     */
    private function documentoCompletoSeguro(string $clave, string|array|null $grupo, Collection $modernas, ?int $desde = null): bool
    {
        if ($grupo !== null) {
            return true;
        }
        $historicos = $this->cargarConVinculos($clave, $desde)['grupos'];

        return collect($historicos)->concat($modernas->pluck('grupo'))->unique()->count() <= 1;
    }

    private static function anioDe(mixed $fecha, mixed $emitidoAt): ?int
    {
        if (is_string($fecha) && preg_match('/(20\d{2}|19\d{2})/', $fecha, $m) === 1) {
            return (int) $m[1];
        }

        return $emitidoAt === null ? null : (int) substr((string) $emitidoAt, 0, 4);
    }

    /** Tarjetas por página del panel y mínimo de certificados a partir del cual aparecen buscador y filtros (con uno solo, la vista sigue siendo simple). */
    public const POR_PAGINA = 20;

    public const MINIMO_CONTROLES = 2;

    public const ORDENES = ['recientes', 'antiguos', 'nombre'];

    /**
     * Listado del panel con búsqueda, filtros, orden y paginación en el servidor. Usa EXACTAMENTE las mismas tarjetas que `tarjetas()` y `tarjetasModernas()`
     * (mismas reglas de alcance, duplicados, canónicos y reemplazos): solo filtra, ordena y corta DESPUÉS, de modo que nada de la autorización cambia y el HTML
     * solo lleva una página. El listado es el de UNA persona (su documento): se ordena en memoria sin consultas adicionales.
     *
     * Orden por la fecha REAL de publicación (`publicado_at`: la emisión), no por la del evento. Los históricos (sin fecha de publicación registrada) van
     * SIEMPRE en su propio bloque, después de lo publicado, con un orden estable (año del evento, nombre, id): como no se sabe cuándo se publicaron, nunca se
     * colocan antes ni después de nada por fecha.
     *  - «recientes» (predeterminado): lo publicado, del más reciente al más antiguo.
     *  - «antiguos»: lo publicado, del más antiguo al más reciente.
     *  - «nombre»: por nombre de la capacitación (dentro de cada bloque).
     *
     * Un parámetro inválido NUNCA se ignora ni amplía el resultado: devuelve un listado vacío con un aviso. Vale para un tipo inesperado (p. ej. un arreglo
     * `anio[]=…` en lugar de un valor) en cualquiera de los parámetros, y para un año, mes, día, rango, orden o página mal formados, también con un solo
     * certificado. Un valor válido que no tiene certificados devuelve un listado vacío sin aviso; una página válida más allá del final muestra la última.
     *
     * @param  array<string,mixed>  $filtros  q, anio, orden, publicado, desde, hasta y page tal como llegan (solo se aceptan textos o enteros)
     * @return array<string,mixed>
     */
    public function tarjetasPaginadas(string $clave, string|array|null $grupo, array $filtros = [], ?int $desde = null): array
    {
        $todas = [...$this->tarjetas($clave, $grupo), ...$this->tarjetasModernas($clave, $grupo, $desde)];
        $anios = array_values(array_unique(array_map('intval', array_filter(array_column($todas, 'anio'), fn ($a) => $a !== null))));
        rsort($anios);
        $haySinAnio = count(array_filter($todas, fn ($t) => $t['anio'] === null)) > 0;

        // Periodos de publicación que existen en el listado («2026» y «2026-10»): son los que se ofrecen en el filtro.
        $periodos = [];
        foreach ($todas as $t) {
            if ($t['publicado_at'] !== null) {
                $periodos[substr($t['publicado_at'], 0, 4)][substr($t['publicado_at'], 0, 7)] = true;
            }
        }
        krsort($periodos);
        $periodos = array_map(function ($meses) {
            $m = array_keys($meses);
            rsort($m);

            return $m;
        }, $periodos);

        // Validación de los filtros: un valor presente pero inválido se informa y deja el listado vacío; nunca se ignora para mostrar más.
        $invalido = null;
        // Solo se aceptan textos o enteros; cualquier otro tipo (un arreglo `anio[]=…`, un objeto…) es un filtro inválido, no un filtro ausente.
        $texto = function (string $k) use ($filtros, &$invalido): ?string {
            $v = $filtros[$k] ?? null;
            if ($v === null || is_string($v) || is_int($v)) {
                return $v === null ? null : trim((string) $v);
            }
            $invalido ??= 'Los filtros indicados no son válidos.';

            return null;
        };
        $q = mb_substr($texto('q') ?? '', 0, 80);
        $ordenRaw = $texto('orden');
        $orden = 'recientes';
        if ($ordenRaw !== null && $ordenRaw !== '') {
            if (in_array($ordenRaw, self::ORDENES, true)) {
                $orden = $ordenRaw;
            } else {
                $invalido ??= 'El orden indicado no es válido.';
            }
        }
        $paginaRaw = $texto('page');
        $paginaPedida = 1;
        if ($paginaRaw !== null && $paginaRaw !== '') {
            if (preg_match('/^[1-9]\d{0,5}$/', $paginaRaw) === 1) {
                $paginaPedida = (int) $paginaRaw;
            } else {
                $invalido ??= 'La página indicada no es válida.';
            }
        }
        $anioRaw = $texto('anio');
        $anio = null;   // null = todos · 'sin' = sin año · 'AAAA' (año del EVENTO)
        if ($anioRaw !== null && $anioRaw !== '') {
            if ($anioRaw === 'sin' || preg_match('/^(19|20)\d{2}$/', $anioRaw) === 1) {
                $anio = $anioRaw;
            } else {
                $invalido = 'El año del evento no es válido.';
            }
        }
        $publicadoRaw = $texto('publicado');
        $publicado = null;
        if ($publicadoRaw !== null && $publicadoRaw !== '') {
            if (preg_match('/^(19|20)\d{2}(-(0[1-9]|1[0-2]))?$/', $publicadoRaw) === 1) {
                $publicado = $publicadoRaw;
            } else {
                $invalido ??= 'El año o mes de publicación no es válido.';
            }
        }
        $diaDesde = null;
        $diaHasta = null;
        foreach (['desde', 'hasta'] as $k) {
            $v = $texto($k);
            if ($v === null || $v === '') {
                continue;
            }
            $d = self::dia($v);
            if ($d === null) {
                $invalido ??= 'La fecha «'.($k === 'desde' ? 'Desde' : 'Hasta').'» no es válida.';
            } elseif ($k === 'desde') {
                $diaDesde = $d;
            } else {
                $diaHasta = $d;
            }
        }
        if ($diaDesde !== null && $diaHasta !== null && $diaDesde > $diaHasta) {
            $invalido ??= 'La fecha «Desde» es posterior a la fecha «Hasta».';
        }

        $totalSin = count($todas);
        // Con un solo certificado no se ofrecen controles (vista simple), pero un parámetro escrito a mano se aplica igual: nunca se ignora en silencio.
        $conControles = $totalSin >= self::MINIMO_CONTROLES;
        $porFecha = $publicado !== null || $diaDesde !== null || $diaHasta !== null;

        $norm = fn (string $s) => mb_strtolower(Str::ascii($s));
        $buscar = $norm($q);
        $sinFechaOcultos = 0;
        $lista = $invalido !== null ? [] : array_values(array_filter($todas, function ($t) use ($buscar, $anio, $norm, $publicado, $diaDesde, $diaHasta, $porFecha, &$sinFechaOcultos) {
            if ($anio === 'sin' && $t['anio'] !== null) {
                return false;
            }
            if ($anio !== null && $anio !== 'sin' && (string) $t['anio'] !== $anio) {
                return false;
            }
            if ($buscar !== '' && ! str_contains($norm((string) $t['evento']), $buscar)) {
                return false;
            }
            if ($porFecha) {
                if ($t['publicado_at'] === null) {
                    $sinFechaOcultos++;

                    return false;
                }
                $dia = substr($t['publicado_at'], 0, 10);
                if (($publicado !== null && ! str_starts_with($t['publicado_at'], $publicado)) || ($diaDesde !== null && $dia < $diaDesde) || ($diaHasta !== null && $dia > $diaHasta)) {
                    return false;
                }
            }

            return true;
        }));
        usort($lista, self::comparador($orden));

        $total = count($lista);
        $porPagina = self::POR_PAGINA;
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min($paginas, $paginaPedida);
        $items = array_slice($lista, ($pagina - 1) * $porPagina, $porPagina);

        return [
            'items' => $items, 'total' => $total, 'total_sin_filtros' => $totalSin, 'descargables' => count(array_filter($todas, fn ($t) => $t['descargable'])),
            'anios' => $anios, 'hay_sin_anio' => $haySinAnio, 'q' => $q, 'anio' => $anio, 'orden' => $orden, 'pagina' => $pagina, 'paginas' => $paginas,
            'por_pagina' => $porPagina, 'desde' => $total === 0 ? 0 : ($pagina - 1) * $porPagina + 1, 'hasta' => ($pagina - 1) * $porPagina + count($items),
            'con_controles' => $conControles, 'filtrado' => $buscar !== '' || $anio !== null || $orden !== 'recientes' || $porFecha || $invalido !== null,
            'periodos' => $periodos, 'publicado' => $publicado, 'dia_desde' => $diaDesde, 'dia_hasta' => $diaHasta, 'sin_fecha_ocultos' => $sinFechaOcultos,
            'hay_sin_fecha' => count(array_filter($todas, fn ($t) => $t['publicado_at'] === null)) > 0, 'filtro_invalido' => $invalido,
        ];
    }

    /** Orden estable de las tarjetas (nunca depende del orden en que llegaron). Los históricos sin fecha de publicación van SIEMPRE en su bloque, al final. */
    private static function comparador(string $orden): \Closure
    {
        $sinFecha = fn (array $t) => $t['publicado_at'] === null ? 1 : 0;
        // Con fecha: la publicación más reciente primero (a igual hora, el id mayor).
        $recientes = fn (array $a, array $b) => [$b['publicado_at'], $b['id']] <=> [$a['publicado_at'], $a['id']];
        // Históricos: año del evento (más reciente primero; sin año al final), nombre e id. No depende del orden elegido: no hay fecha que ordenar.
        $historicos = fn (array $a, array $b) => [$a['anio'] === null ? 1 : 0, -($a['anio'] ?? 0), $a['evento'], $a['id']] <=> [$b['anio'] === null ? 1 : 0, -($b['anio'] ?? 0), $b['evento'], $b['id']];
        $nombre = fn (array $t) => mb_strtolower(Str::ascii($t['evento']));

        return match ($orden) {
            'antiguos' => fn (array $a, array $b) => ($sinFecha($a) <=> $sinFecha($b)) ?: ($sinFecha($a) ? $historicos($a, $b) : $recientes($b, $a)),
            'nombre' => fn (array $a, array $b) => ($sinFecha($a) <=> $sinFecha($b)) ?: (($nombre($a) <=> $nombre($b)) ?: ($sinFecha($a) ? $historicos($a, $b) : $recientes($a, $b))),
            default => fn (array $a, array $b) => ($sinFecha($a) <=> $sinFecha($b)) ?: ($sinFecha($a) ? $historicos($a, $b) : $recientes($a, $b)),
        };
    }

    /** «AAAA-MM-DD HH:MM:SS» de una fecha y hora guardada (en la zona horaria de la aplicación), o null. */
    private static function fechaHora(mixed $valor): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }

        return is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}/', $valor) === 1 ? substr($valor, 0, 19) : null;
    }

    /** Día «AAAA-MM-DD» válido de un filtro, o null. */
    private static function dia(mixed $valor): ?string
    {
        if (! is_string($valor) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $valor;
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
            CertificadoLegado::CONCILIACION_REVISION_DOCUMENTO => $base + ['tipo' => 'revision', 'mensaje' => 'Este certificado necesita revisión. Contactar a soporte para recibir ayuda.'],
            CertificadoLegado::CONCILIACION_PENDIENTE => $base + ['tipo' => 'revision_conciliacion', 'mensaje' => 'Certificado en revisión. Contactar a soporte para recibir ayuda.'],
            CertificadoLegado::CONCILIACION_PENDIENTE_PLANTILLA => $base + ['tipo' => 'plantilla', 'mensaje' => 'Certificado en proceso de recuperación. Contactar a soporte para recibir ayuda.'],
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

        // La versión actualizada SÍ tiene una fecha real: cuándo se publicó su emisión vigente.
        return array_merge($base, ['tipo' => 'actualizado', 'mensaje' => 'Certificado actualizado. Disponible para descargar.', 'codigo' => $r['vigente']->codigo, 'estado_emision' => 'Vigente', 'descargable' => true,
            'publicado_at' => self::fechaHora($r['vigente']->emitido_at ?? null)]);
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

        return $base + ['tipo' => 'revision', 'mensaje' => 'Este certificado necesita revisión. Contactar a soporte para recibir ayuda.'];
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
     * Igual que `cargar()`, pero con los VÍNCULOS EXPLÍCITOS de identidad aplicados al GRUPO de cada fila histórica: si un administrador vinculó (con evidencia) un
     * grupo de nombre con el de la misma persona, ambos cuentan como UNO para decidir el acceso. Sin vínculos vigentes es idéntico a `cargar()`. Con `$desde`,
     * solo los vínculos creados antes de ese instante (una sesión anterior no hereda permisos nuevos). Nunca cambia ningún dato histórico.
     *
     * @return array{filas: Collection<int,CertificadoLegado>, correos: array<int,array<string,bool>>, grupos: array<int,string>}
     */
    public function cargarConVinculos(string $clave, ?int $desde = null): array
    {
        $d = $this->cargar($clave);
        $mapa = VinculosIdentidadModerna::mapa($clave, $desde);
        if ($mapa !== []) {
            foreach ($d['grupos'] as $id => $g) {
                $d['grupos'][$id] = $mapa[$g] ?? $g;
            }
        }

        return $d;
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
