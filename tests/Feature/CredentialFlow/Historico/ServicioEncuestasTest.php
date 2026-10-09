<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\Encuesta;
use App\Models\CredentialFlow\EncuestaOpcion;
use App\Models\CredentialFlow\EncuestaPregunta;
use App\Models\CredentialFlow\EncuestaRespuesta;
use App\Models\CredentialFlow\EncuestaVersion;
use App\Support\CredentialFlow\Encuestas\RespuestaInvalida;
use App\Support\CredentialFlow\Encuestas\ServicioEncuestas;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** Motor configurable de encuestas (Fase 8): activa, definición, validación, guardado, versionado y snapshots. Fixtures sintéticos. */
class ServicioEncuestasTest extends HistoricoTestCase
{
    private ServicioEncuestas $motor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->motor = new ServicioEncuestas;
    }

    /** Encuesta publicada y activa con una versión de las cuatro clases de pregunta. */
    private function encuesta(array $versionExtra = [], bool $publicada = true): EncuestaVersion
    {
        $e = Encuesta::create(['nombre' => 'Satisfacción', 'estado' => 'publicada', 'activa' => true, 'origen' => 'credential_flow']);
        $v = EncuestaVersion::create($versionExtra + ['encuesta_id' => $e->id, 'numero' => 1, 'titulo' => 'Versión 1', 'introduccion' => 'Gracias', 'publicada_at' => $publicada ? now()->subDay() : null, 'activa_desde' => now()->subDay(), 'activa_hasta' => null]);
        $mk = fn (int $orden, string $clave, string $tipo, array $extra = []) => EncuestaPregunta::create($extra + ['version_id' => $v->id, 'orden' => $orden, 'clave' => $clave, 'texto' => "Pregunta {$clave}", 'tipo' => $tipo]);
        $unica = $mk(1, 'calidad', 'opcion_unica');
        foreach (['Excelente', 'Bueno', 'Malo'] as $i => $o) {
            EncuestaOpcion::create(['pregunta_id' => $unica->id, 'orden' => $i + 1, 'valor' => $o, 'etiqueta' => "Etiqueta {$o}"]);
        }
        $multi = $mk(2, 'temas', 'opcion_multiple');
        foreach (['A', 'B', 'C'] as $i => $o) {
            EncuestaOpcion::create(['pregunta_id' => $multi->id, 'orden' => $i + 1, 'valor' => $o, 'etiqueta' => "Tema {$o}"]);
        }
        $mk(3, 'comentario', 'texto', ['configuracion' => ['max_caracteres' => 50]]);
        $mk(4, 'nps', 'escala', ['configuracion' => ['min' => 0, 'max' => 10]]);

        return $v->fresh();
    }

    // ── Encuesta activa y definición ──────────────────────────────────────────

    public function test_obtiene_la_encuesta_activa_vigente(): void
    {
        $v = $this->encuesta();

        $this->assertSame($v->id, $this->motor->activa()->id);
    }

    public function test_sin_encuesta_publicada_o_con_la_ventana_cerrada_no_hay_activa(): void
    {
        $this->assertNull($this->motor->activa());
        $v = $this->encuesta(publicada: false);
        $this->assertNull($this->motor->activa(), 'Un borrador no es activa');

        $v->update(['publicada_at' => now()->subDay(), 'activa_hasta' => now()->subHour()]);
        $this->assertNull($this->motor->activa(), 'Ventana cerrada');
        $this->assertNull($this->motor->activa(Carbon::now()->subDays(3)), 'Todavía no había empezado');
        $this->assertSame($v->id, $this->motor->activa(Carbon::now()->subHours(5))->id);
    }

    public function test_la_encuesta_archivada_o_inactiva_no_se_ofrece(): void
    {
        $v = $this->encuesta();
        Encuesta::query()->update(['activa' => false]);

        $this->assertNull($this->motor->activa());
    }

    public function test_la_definicion_trae_solo_lo_activo_y_ordenado_sin_datos_internos(): void
    {
        $v = $this->encuesta();
        EncuestaPregunta::where('clave', 'comentario')->update(['activa' => false]);
        EncuestaOpcion::where('valor', 'Malo')->update(['activa' => false]);

        $d = $this->motor->definicion($v);

        $this->assertSame(['calidad', 'temas', 'nps'], array_column($d['preguntas'], 'clave'));
        $this->assertSame(['Excelente', 'Bueno'], array_column($d['preguntas'][0]['opciones'], 'valor'));
        $this->assertSame([], $d['preguntas'][2]['opciones']);
        $this->assertSame(['min' => 0, 'max' => 10], $d['preguntas'][2]['configuracion']);
        $this->assertArrayNotHasKey('snapshot', $d);
    }

    public function test_es_opcional_por_defecto_y_no_bloquea_nada(): void
    {
        $v = $this->encuesta();

        $this->assertFalse($v->obligatoria);
        $this->assertFalse($this->motor->definicion($v)['obligatoria']);
        // Una encuesta opcional se puede enviar totalmente vacía y no exige ninguna pregunta.
        $this->assertSame([], $this->motor->validar($v, []));
        $this->assertSame(0, DB::table('cf_encuestas_respuestas_detalle')->count());
    }

    // ── Validación ────────────────────────────────────────────────────────────

    public function test_guarda_las_cuatro_clases_de_pregunta_con_snapshots(): void
    {
        $v = $this->encuesta();

        $r = $this->motor->guardar($v, ['calidad' => 'Excelente', 'temas' => ['A', 'C'], 'comentario' => 'Muy bien', 'nps' => '9'], ['documento' => '1000001']);

        $d = DB::table('cf_encuestas_respuestas_detalle')->where('respuesta_id', $r->id)->orderBy('id')->get();
        $this->assertCount(5, $d, 'La múltiple genera una fila por opción');
        $this->assertSame(['Excelente', 'A', 'C', 'Muy bien', '9'], $d->pluck('valor_texto')->all());
        $this->assertSame(9.0, (float) $d->last()->valor_numero);
        $this->assertSame(['Etiqueta Excelente', 'Tema A', 'Tema C', null, null], $d->pluck('snapshot_opcion')->all());
        $this->assertSame('Pregunta calidad', json_decode($d[0]->snapshot_pregunta, true)['texto']);
        $this->assertSame($v->id, $r->version_id);
        $this->assertSame('credential_flow', $r->origen);
        $this->assertSame(64, strlen($r->documento_hash));
        $this->assertStringNotContainsString('1000001', json_encode($r->toArray()));
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function invalidas(): array
    {
        return [
            'pregunta desconocida' => [['otra' => 'x'], 'otra'],
            'opción que no existe' => [['calidad' => 'Pésimo'], 'calidad'],
            'única con varias' => [['calidad' => ['Excelente', 'Bueno']], 'calidad'],
            'múltiple con una opción ajena' => [['temas' => ['A', 'Z']], 'temas'],
            'múltiple repetida' => [['temas' => ['A', 'A']], 'temas'],
            'múltiple que no es lista' => [['temas' => 'A'], 'temas'],
            'texto demasiado largo' => [['comentario' => str_repeat('x', 51)], 'comentario'],
            'texto no es cadena' => [['comentario' => ['x']], 'comentario'],
            'escala fuera de rango' => [['nps' => 11], 'nps'],
            'escala negativa' => [['nps' => -1], 'nps'],
            'escala no entera' => [['nps' => '7.5'], 'nps'],
            'escala texto' => [['nps' => 'alto'], 'nps'],
        ];
    }

    #[DataProvider('invalidas')]
    public function test_rechaza_respuestas_invalidas_sin_guardar_nada(array $respuestas, string $clave): void
    {
        $v = $this->encuesta();

        try {
            $this->motor->guardar($v, $respuestas);
            $this->fail('Debía rechazarse');
        } catch (RespuestaInvalida $e) {
            $this->assertSame(RespuestaInvalida::CAMPOS, $e->codigo);
            $this->assertArrayHasKey($clave, $e->errores);
        }
        $this->assertSame(0, EncuestaRespuesta::count());
        $this->assertSame(0, DB::table('cf_encuestas_respuestas_detalle')->count());
    }

    public function test_los_limites_de_valores_validos_se_aceptan(): void
    {
        $v = $this->encuesta();

        $r = $this->motor->guardar($v, ['nps' => 0, 'comentario' => str_repeat('x', 50)]);
        $this->motor->guardar($v, ['nps' => '10']);

        $this->assertSame(2, EncuestaRespuesta::count());
        $this->assertNotNull($r);
    }

    public function test_una_pregunta_obligatoria_sin_respuesta_se_rechaza(): void
    {
        $v = $this->encuesta();
        EncuestaPregunta::where('clave', 'calidad')->update(['obligatoria' => true]);

        $this->expectException(RespuestaInvalida::class);
        try {
            $this->motor->guardar($v, ['nps' => 5]);
        } catch (RespuestaInvalida $e) {
            $this->assertSame(['calidad' => 'Esta pregunta es obligatoria.'], $e->errores);

            throw $e;
        }
    }

    public function test_una_version_no_publicada_o_vencida_no_acepta_respuestas(): void
    {
        $borrador = $this->encuesta(publicada: false);
        try {
            $this->motor->guardar($borrador, ['nps' => 5]);
            $this->fail('Debía rechazarse');
        } catch (RespuestaInvalida $e) {
            $this->assertSame(RespuestaInvalida::VERSION_NO_VIGENTE, $e->codigo);
        }
        $borrador->update(['publicada_at' => now()->subDay(), 'activa_hasta' => now()->subMinute()]);
        $this->expectException(RespuestaInvalida::class);
        $this->motor->guardar($borrador->fresh(), ['nps' => 5]);
    }

    public function test_un_origen_doble_y_la_respuesta_duplicada_se_rechazan(): void
    {
        $v = $this->encuesta();
        $cert = $this->migrarCert();

        try {
            $this->motor->guardar($v, ['nps' => 5], ['certificado_legado_id' => $cert, 'emision_id' => 1]);
            $this->fail('Origen doble');
        } catch (RespuestaInvalida $e) {
            $this->assertSame(RespuestaInvalida::CONTEXTO, $e->codigo);
        }
        $this->motor->guardar($v, ['nps' => 5], ['certificado_legado_id' => $cert]);
        try {
            $this->motor->guardar($v, ['nps' => 6], ['certificado_legado_id' => $cert]);
            $this->fail('Duplicada');
        } catch (RespuestaInvalida $e) {
            $this->assertSame(RespuestaInvalida::DUPLICADA, $e->codigo);
        }
        $this->assertSame(1, EncuestaRespuesta::count());
    }

    /** Un certificado histórico real (migrado) para el contexto. */
    private function migrarCert(): int
    {
        $this->migrarSintetico($this->datos());

        return $this->idCert(1);
    }

    // ── Versionado y snapshots ────────────────────────────────────────────────

    public function test_cambiar_la_encuesta_despues_no_altera_lo_respondido(): void
    {
        $v1 = $this->encuesta();
        $r = $this->motor->guardar($v1, ['calidad' => 'Bueno', 'comentario' => 'Original']);

        // Se cambia el texto de la pregunta, la etiqueta de la opción y se publica una versión 2 con otras preguntas y opciones.
        EncuestaPregunta::where('version_id', $v1->id)->where('clave', 'calidad')->update(['texto' => 'Pregunta MODIFICADA']);
        EncuestaOpcion::where('valor', 'Bueno')->update(['etiqueta' => 'Etiqueta MODIFICADA']);
        $v1->update(['activa_hasta' => now()->subMinute()]);
        $v2 = EncuestaVersion::create(['encuesta_id' => $v1->encuesta_id, 'numero' => 2, 'titulo' => 'Versión 2', 'publicada_at' => now(), 'activa_desde' => now()->subSeconds(30)]);
        $p = EncuestaPregunta::create(['version_id' => $v2->id, 'orden' => 1, 'clave' => 'calidad', 'texto' => 'Nueva redacción', 'tipo' => 'opcion_unica']);
        EncuestaOpcion::create(['pregunta_id' => $p->id, 'orden' => 1, 'valor' => 'Sí', 'etiqueta' => 'Sí']);

        $d = DB::table('cf_encuestas_respuestas_detalle')->where('respuesta_id', $r->id)->orderBy('id')->first();
        $this->assertSame('Pregunta calidad', json_decode($d->snapshot_pregunta, true)['texto'], 'La pregunta que vio la persona');
        $this->assertSame('Etiqueta Bueno', $d->snapshot_opcion, 'La opción que vio la persona');
        $this->assertSame('Bueno', $d->valor_texto);
        $this->assertSame($v1->id, (int) DB::table('cf_encuestas_respuestas')->where('id', $r->id)->value('version_id'), 'Sigue ligada a la versión 1');

        // La encuesta activa ahora es la versión 2, y una respuesta nueva queda bajo ella.
        $this->assertSame($v2->id, $this->motor->activa()->id);
        $r2 = $this->motor->guardar($v2->fresh(), ['calidad' => 'Sí']);
        $this->assertSame($v2->id, $r2->version_id);
        // La versión 1 ya no acepta respuestas.
        $this->expectException(RespuestaInvalida::class);
        $this->motor->guardar($v1->fresh(), ['calidad' => 'Bueno']);
    }

    public function test_la_misma_clave_puede_existir_en_versiones_distintas_pero_no_repetirse_en_una(): void
    {
        $v = $this->encuesta();

        $this->expectException(QueryException::class);
        EncuestaPregunta::create(['version_id' => $v->id, 'orden' => 9, 'clave' => 'calidad', 'texto' => 'x', 'tipo' => 'texto']);
    }

    public function test_el_modelo_rechaza_una_respuesta_con_dos_origenes(): void
    {
        $v = $this->encuesta();

        $this->expectException(\InvalidArgumentException::class);
        EncuestaRespuesta::create(['version_id' => $v->id, 'certificado_legado_id' => 1, 'emision_id' => 1, 'completada_at' => now(), 'origen' => 'credential_flow']);
    }

    public function test_solo_hay_cuatro_tipos_de_pregunta(): void
    {
        $this->assertSame(['opcion_unica', 'opcion_multiple', 'texto', 'escala'], EncuestaPregunta::TIPOS);
    }
}
