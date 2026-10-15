<?php

namespace App\Support\CredentialFlow\Encuestas;

use App\Models\CredentialFlow\Encuesta;
use App\Models\CredentialFlow\EncuestaOpcion;
use App\Models\CredentialFlow\EncuestaPregunta;
use App\Models\CredentialFlow\EncuestaRespuesta;
use App\Models\CredentialFlow\EncuestaVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Prepara la versión de la encuesta que el portal ofrece: una COPIA FIEL (preguntas y opciones idénticas) de la versión estructural más reciente, publicada
 * y activada. No altera ninguna versión ni respuesta histórica. Se marca en su `snapshot` para poder identificarla y revertirla.
 *
 * Reversión lógica segura: solo elimina la versión creada por esta clase y SOLO si no tiene ninguna respuesta; devuelve la encuesta al estado previo.
 */
final class VersionPortal
{
    public const MARCA = 'portal_credential_flow';

    public const TITULO = 'Evaluación del evento';

    public const INTRODUCCION = 'La opinión recibida contribuye a mejorar continuamente los eventos y servicios.';

    /**
     * Plan sin escribir nada: qué se haría (o por qué no se puede).
     *
     * @return array{accion:string,encuesta_id:int,origen_version_id:int,origen_numero:int,numero:int,preguntas:int,opciones:int,respuestas_origen:int,existente:?int}
     *
     * @throws RuntimeException
     */
    public function plan(): array
    {
        $encuesta = $this->encuesta();
        $origen = $this->origen($encuesta);
        $existente = EncuestaVersion::query()->where('encuesta_id', $encuesta->id)->get()->first(fn ($v) => ($v->snapshot['marca'] ?? null) === self::MARCA);

        $preguntas = EncuestaPregunta::query()->where('version_id', $origen->id)->get();
        $opciones = EncuestaOpcion::query()->whereIn('pregunta_id', $preguntas->pluck('id'))->count();

        return [
            'accion' => $existente !== null ? 'sin_cambios' : 'crear', 'encuesta_id' => (int) $encuesta->id, 'origen_version_id' => (int) $origen->id, 'origen_numero' => (int) $origen->numero,
            'numero' => $existente !== null ? (int) $existente->numero : ((int) EncuestaVersion::query()->where('encuesta_id', $encuesta->id)->max('numero')) + 1,
            'preguntas' => $preguntas->count(), 'opciones' => $opciones,
            'respuestas_origen' => EncuestaRespuesta::query()->where('version_id', $origen->id)->count(), 'existente' => $existente?->id,
        ];
    }

    /** Crea y activa la versión (idempotente: si ya existe devuelve la existente sin tocar nada). */
    public function aplicar(): EncuestaVersion
    {
        $plan = $this->plan();
        if ($plan['existente'] !== null) {
            return EncuestaVersion::query()->findOrFail($plan['existente']);
        }

        return DB::transaction(function () use ($plan) {
            $encuesta = Encuesta::query()->lockForUpdate()->findOrFail($plan['encuesta_id']);
            $origen = EncuestaVersion::query()->findOrFail($plan['origen_version_id']);
            $previo = ['estado' => $encuesta->estado, 'activa' => (bool) $encuesta->activa];
            $ahora = now();

            $preguntasOrigen = EncuestaPregunta::query()->where('version_id', $origen->id)->orderBy('orden')->with(['opciones' => fn ($q) => $q->orderBy('orden')])->get();
            $estructura = $preguntasOrigen->map(fn ($p) => [
                'clave' => $p->clave, 'tipo' => $p->tipo, 'texto' => $p->texto,
                'opciones' => $p->opciones->map(fn ($o) => ['valor' => $o->valor, 'etiqueta' => $o->etiqueta])->values()->all(),
            ])->values()->all();

            $nueva = EncuestaVersion::query()->create([
                'encuesta_id' => $encuesta->id, 'numero' => $plan['numero'], 'titulo' => self::TITULO, 'introduccion' => self::INTRODUCCION, 'obligatoria' => false,
                'publicada_at' => $ahora, 'activa_desde' => $ahora, 'activa_hasta' => null,
                'snapshot' => ['marca' => self::MARCA, 'copia_de_version_id' => (int) $origen->id, 'copia_de_numero' => (int) $origen->numero, 'encuesta_previa' => $previo, 'preguntas' => $estructura],
            ]);

            foreach ($preguntasOrigen as $p) {
                $copia = EncuestaPregunta::query()->create([
                    'version_id' => $nueva->id, 'orden' => $p->orden, 'clave' => $p->clave, 'texto' => $p->texto, 'tipo' => $p->tipo, 'obligatoria' => $p->obligatoria,
                    'activa' => $p->activa, 'ayuda' => $p->ayuda, 'configuracion' => $p->configuracion,
                ]);
                foreach ($p->opciones as $o) {
                    EncuestaOpcion::query()->create(['pregunta_id' => $copia->id, 'orden' => $o->orden, 'valor' => $o->valor, 'etiqueta' => $o->etiqueta, 'activa' => $o->activa]);
                }
            }

            $encuesta->forceFill(['estado' => Encuesta::ESTADO_PUBLICADA, 'activa' => true])->save();

            return $nueva;
        });
    }

    /**
     * Reversión lógica. Rechazada si la versión tiene respuestas (o detalle) o si ya hay una versión posterior.
     *
     * @return array{version:int,numero:int,preguntas:int,opciones:int}
     *
     * @throws RuntimeException
     */
    public function revertir(): array
    {
        $encuesta = $this->encuesta();
        $version = EncuestaVersion::query()->where('encuesta_id', $encuesta->id)->get()->first(fn ($v) => ($v->snapshot['marca'] ?? null) === self::MARCA);
        if ($version === null) {
            throw new RuntimeException('No existe una versión creada para el portal: no hay nada que revertir.');
        }

        return DB::transaction(function () use ($encuesta, $version) {
            EncuestaVersion::query()->lockForUpdate()->find($version->id);
            $preguntasIds = EncuestaPregunta::query()->where('version_id', $version->id)->pluck('id');
            $respuestas = EncuestaRespuesta::query()->where('version_id', $version->id)->count();
            $detalle = DB::table('cf_encuestas_respuestas_detalle')->whereIn('pregunta_id', $preguntasIds)->count();
            if ($respuestas > 0 || $detalle > 0) {
                throw new RuntimeException("La versión {$version->numero} ya tiene respuestas ({$respuestas}): no se puede revertir.");
            }
            if (EncuestaVersion::query()->where('encuesta_id', $encuesta->id)->where('numero', '>', $version->numero)->exists()) {
                throw new RuntimeException("Existe una versión posterior a la {$version->numero}: no se puede revertir.");
            }

            $opciones = EncuestaOpcion::query()->whereIn('pregunta_id', $preguntasIds)->delete();
            $preguntas = EncuestaPregunta::query()->where('version_id', $version->id)->delete();
            $version->delete();

            $previo = $version->snapshot['encuesta_previa'] ?? null;
            if (is_array($previo)) {
                $encuesta->forceFill(['estado' => $previo['estado'] ?? $encuesta->estado, 'activa' => (bool) ($previo['activa'] ?? false)])->save();
            }

            return ['version' => (int) $version->id, 'numero' => (int) $version->numero, 'preguntas' => $preguntas, 'opciones' => $opciones];
        });
    }

    private function encuesta(): Encuesta
    {
        $e = Encuesta::query()->where('origen', Encuesta::ORIGEN_LEGADO)->orderBy('id')->get();
        if ($e->count() !== 1) {
            throw new RuntimeException('Se esperaba exactamente una encuesta histórica; hay '.$e->count().'.');
        }
        if (Encuesta::query()->where('activa', true)->where('id', '!=', $e->first()->id)->exists()) {
            throw new RuntimeException('Hay otra encuesta activa distinta de la histórica: no se activa una segunda.');
        }

        return $e->first();
    }

    /** Versión estructural más reciente que no sea ya la del portal. */
    private function origen(Encuesta $encuesta): EncuestaVersion
    {
        $v = EncuestaVersion::query()->where('encuesta_id', $encuesta->id)->whereNotNull('publicada_at')->orderByDesc('numero')->get()
            ->first(fn ($x) => ($x->snapshot['marca'] ?? null) !== self::MARCA);
        if ($v === null) {
            throw new RuntimeException('La encuesta histórica no tiene ninguna versión publicada que copiar.');
        }
        if (! EncuestaPregunta::query()->where('version_id', $v->id)->where('activa', true)->exists()) {
            throw new RuntimeException("La versión {$v->numero} no tiene preguntas activas.");
        }

        return $v;
    }
}
