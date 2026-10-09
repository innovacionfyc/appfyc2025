<?php

namespace App\Support\CredentialFlow\Encuestas;

use App\Models\CredentialFlow\Encuesta;
use App\Models\CredentialFlow\EncuestaPregunta;
use App\Models\CredentialFlow\EncuestaRespuesta;
use App\Models\CredentialFlow\EncuestaVersion;
use App\Support\CredentialFlow\Portal\Hmac;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Motor de encuestas configurable (Fase 8): obtener la encuesta activa, renderizar su definición, VALIDAR y GUARDAR una respuesta.
 *
 *  - La encuesta es OPCIONAL por defecto: ni este servicio ni `obligatoria=false` bloquean la descarga de un certificado. Una futura
 *    configuración explícita (`obligatoria=true` en la versión) es lo único que podría exigirla; aquí solo se expone.
 *  - Una respuesta queda ligada a la VERSIÓN exacta que se contestó (debe estar publicada y vigente en ese momento) y el detalle guarda
 *    el snapshot de la pregunta y de la opción vistas: cambiar después la encuesta no altera lo ya respondido.
 *  - Se rechaza todo lo que pueda corromper datos: claves desconocidas, opciones que no son de la pregunta, escalas fuera de rango,
 *    textos demasiado largos, obligatorias sin responder, un origen de certificado doble y la respuesta duplicada al mismo certificado.
 */
final class ServicioEncuestas
{
    public const MAX_TEXTO = 2000;

    /** Versión vigente de la encuesta activa (publicada, activa y dentro de su ventana) o null. */
    public function activa(?Carbon $en = null): ?EncuestaVersion
    {
        $en ??= Carbon::now();
        $encuesta = Encuesta::query()->where('estado', Encuesta::ESTADO_PUBLICADA)->where('activa', true)->orderByDesc('id')->first();

        return $encuesta === null ? null : $this->vigente($encuesta, $en);
    }

    public function vigente(Encuesta $encuesta, Carbon $en): ?EncuestaVersion
    {
        return EncuestaVersion::query()->where('encuesta_id', $encuesta->id)->whereNotNull('publicada_at')
            ->where('activa_desde', '<=', $en)->where(fn ($q) => $q->whereNull('activa_hasta')->orWhere('activa_hasta', '>', $en))
            ->orderByDesc('numero')->first();
    }

    /**
     * Definición lista para renderizar (sin snapshot interno, sin respuestas): solo preguntas y opciones ACTIVAS, en orden.
     *
     * @return array<string,mixed>
     */
    public function definicion(EncuestaVersion $v): array
    {
        $preguntas = EncuestaPregunta::query()->where('version_id', $v->id)->where('activa', true)->orderBy('orden')->with(['opciones' => fn ($q) => $q->where('activa', true)])->get();

        return [
            'version_id' => $v->id, 'numero' => $v->numero, 'titulo' => $v->titulo, 'introduccion' => $v->introduccion, 'obligatoria' => (bool) $v->obligatoria,
            'preguntas' => $preguntas->map(fn (EncuestaPregunta $p) => [
                'clave' => $p->clave, 'orden' => (int) $p->orden, 'texto' => $p->texto, 'tipo' => $p->tipo, 'obligatoria' => (bool) $p->obligatoria, 'ayuda' => $p->ayuda,
                'configuracion' => in_array($p->tipo, [EncuestaPregunta::ESCALA, EncuestaPregunta::TEXTO], true) ? ($p->configuracion ?? new \stdClass) : null,
                'opciones' => $p->esDeOpciones() ? $p->opciones->map(fn ($o) => ['valor' => $o->valor, 'etiqueta' => $o->etiqueta])->values()->all() : [],
            ])->values()->all(),
        ];
    }

    /**
     * Valida las respuestas (clave de pregunta → valor) contra la versión y devuelve el detalle normalizado.
     *
     * @param  array<string,mixed>  $respuestas
     * @return list<array<string,mixed>> filas de detalle: pregunta, valor_texto, valor_numero, opcion
     *
     * @throws RespuestaInvalida
     */
    public function validar(EncuestaVersion $v, array $respuestas): array
    {
        $preguntas = EncuestaPregunta::query()->where('version_id', $v->id)->where('activa', true)->with('opciones')->get()->keyBy('clave');
        $errores = [];
        $detalle = [];

        foreach (array_keys($respuestas) as $clave) {
            if (! $preguntas->has((string) $clave)) {
                $errores[(string) $clave] = 'Esta pregunta no existe en la encuesta.';
            }
        }
        foreach ($preguntas as $clave => $p) {
            $vacia = ! array_key_exists($clave, $respuestas) || $respuestas[$clave] === null || $respuestas[$clave] === '' || $respuestas[$clave] === [];
            if ($vacia) {
                if ($p->obligatoria) {
                    $errores[$clave] = 'Esta pregunta es obligatoria.';
                }

                continue;
            }
            try {
                array_push($detalle, ...$this->validarPregunta($p, $respuestas[$clave]));
            } catch (RespuestaInvalida $e) {
                $errores[$clave] = $e->errores[$clave] ?? $e->getMessage();
            }
        }
        if ($errores !== []) {
            throw new RespuestaInvalida(RespuestaInvalida::CAMPOS, $errores);
        }

        return $detalle;
    }

    /** @return list<array<string,mixed>> */
    private function validarPregunta(EncuestaPregunta $p, mixed $valor): array
    {
        $falla = fn (string $m) => throw new RespuestaInvalida(RespuestaInvalida::CAMPOS, [$p->clave => $m]);

        switch ($p->tipo) {
            case EncuestaPregunta::OPCION_UNICA:
                if (! is_string($valor) && ! is_int($valor)) {
                    $falla('Elige una sola opción.');
                }
                $o = $p->opciones->first(fn ($x) => $x->activa && (string) $x->valor === (string) $valor);

                return $o === null ? $falla('Elige una de las opciones disponibles.') : [['pregunta' => $p, 'valor_texto' => $o->valor, 'valor_numero' => null, 'opcion' => $o]];

            case EncuestaPregunta::OPCION_MULTIPLE:
                if (! is_array($valor) || ! array_is_list($valor)) {
                    $falla('Elige una o varias opciones.');
                }
                $textos = array_map(fn ($x) => is_scalar($x) ? (string) $x : null, $valor);
                if (in_array(null, $textos, true) || count($textos) !== count(array_unique($textos))) {
                    $falla('Las opciones elegidas no son válidas.');
                }
                $filas = [];
                foreach ($textos as $t) {
                    $o = $p->opciones->first(fn ($x) => $x->activa && (string) $x->valor === $t);
                    $o === null ? $falla('Elige solo opciones disponibles.') : $filas[] = ['pregunta' => $p, 'valor_texto' => $o->valor, 'valor_numero' => null, 'opcion' => $o];
                }

                return $filas;

            case EncuestaPregunta::TEXTO:
                if (! is_string($valor)) {
                    $falla('Escribe un texto.');
                }
                $max = min(self::MAX_TEXTO, (int) ($p->configuracion['max_caracteres'] ?? self::MAX_TEXTO));
                if (mb_strlen($valor) > $max) {
                    $falla("El texto es demasiado largo (máximo {$max} caracteres).");
                }

                return [['pregunta' => $p, 'valor_texto' => $valor, 'valor_numero' => null, 'opcion' => null]];

            case EncuestaPregunta::ESCALA:
                $cfg = $p->configuracion ?? [];
                [$min, $max] = [(int) ($cfg['min'] ?? 1), (int) ($cfg['max'] ?? 5)];
                if (! (is_int($valor) || (is_string($valor) && preg_match('/^-?\d+$/', $valor) === 1))) {
                    $falla('Elige un valor de la escala.');
                }
                if ((int) $valor < $min || (int) $valor > $max) {
                    $falla("El valor debe estar entre {$min} y {$max}.");
                }

                return [['pregunta' => $p, 'valor_texto' => (string) (int) $valor, 'valor_numero' => (int) $valor, 'opcion' => null]];
        }

        return $falla('Tipo de pregunta no soportado.');
    }

    /**
     * Guarda una respuesta completa (cabecera + detalle con snapshots) en UNA transacción.
     *
     * @param  array<string,mixed>  $respuestas  clave de pregunta → valor
     * @param  array{evento_id?:?int,certificado_legado_id?:?int,emision_id?:?int,documento?:?string}  $contexto
     *
     * @throws RespuestaInvalida
     */
    public function guardar(EncuestaVersion $v, array $respuestas, array $contexto = [], ?Carbon $en = null): EncuestaRespuesta
    {
        $en ??= Carbon::now();
        $encuesta = Encuesta::query()->find($v->encuesta_id);
        if ($encuesta === null || $encuesta->estado !== Encuesta::ESTADO_PUBLICADA || $v->publicada_at === null || $v->activa_desde > $en || ($v->activa_hasta !== null && $v->activa_hasta <= $en)) {
            throw new RespuestaInvalida(RespuestaInvalida::VERSION_NO_VIGENTE, ['_' => 'Esta encuesta ya no está disponible.']);
        }
        $legado = $contexto['certificado_legado_id'] ?? null;
        $emision = $contexto['emision_id'] ?? null;
        if ($legado !== null && $emision !== null) {
            throw new RespuestaInvalida(RespuestaInvalida::CONTEXTO, ['_' => 'La respuesta pertenece a un solo certificado.']);
        }

        $detalle = $this->validar($v, $respuestas);

        return DB::transaction(function () use ($v, $detalle, $contexto, $legado, $emision, $en) {
            // Una persona (certificado) responde una vez cada versión: se comprueba junto con la inserción.
            foreach (['certificado_legado_id' => $legado, 'emision_id' => $emision] as $col => $id) {
                if ($id !== null && EncuestaRespuesta::query()->where('version_id', $v->id)->where($col, $id)->lockForUpdate()->exists()) {
                    throw new RespuestaInvalida(RespuestaInvalida::DUPLICADA, ['_' => 'Ya respondiste esta encuesta.']);
                }
            }
            $r = EncuestaRespuesta::create([
                'version_id' => $v->id, 'evento_id' => $contexto['evento_id'] ?? null, 'certificado_legado_id' => $legado, 'emision_id' => $emision,
                'documento_hash' => isset($contexto['documento']) ? Hmac::de('documento', (string) $contexto['documento']) : null,
                'completada_at' => $en, 'origen' => 'credential_flow',
            ]);
            $ahora = Carbon::now();
            DB::table('cf_encuestas_respuestas_detalle')->insert(array_map(fn ($d) => [
                'respuesta_id' => $r->id, 'pregunta_id' => $d['pregunta']->id, 'clave_historica' => null, 'valor_texto' => $d['valor_texto'], 'valor_numero' => $d['valor_numero'],
                'opcion_id' => $d['opcion']?->id,
                'snapshot_pregunta' => json_encode(['clave' => $d['pregunta']->clave, 'tipo' => $d['pregunta']->tipo, 'texto' => $d['pregunta']->texto], JSON_UNESCAPED_UNICODE),
                'snapshot_opcion' => $d['opcion']?->etiqueta, 'created_at' => $ahora, 'updated_at' => $ahora,
            ], $detalle));

            return $r;
        });
    }
}
