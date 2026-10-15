<?php

namespace Tests\Feature\CredentialFlow\Migracion;

use App\Models\CredentialFlow\MigracionCorrida;
use App\Support\CredentialFlow\Migracion\DeltaRechazado;
use App\Support\CredentialFlow\Migracion\MigradorDelta;
use App\Support\CredentialFlow\Migracion\MigradorDeltaEncuestas;
use App\Support\CredentialFlow\Migracion\MigradorEncuestas;
use App\Support\CredentialFlow\Migracion\MigradorHistorico;
use App\Support\CredentialFlow\StagingEv\EscanerImagenes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\CredentialFlow\Historico\HistoricoTestCase;

/**
 * Fase 11F-C5.2: `encuestas:delta --simular` resuelve eventos que aún no existen en appfyc pero que el plan VÁLIDO de `legado:delta`
 * crearía. Solo en simulación y sin persistir nada; el modo real conserva EVENTO_NO_MIGRADO. Todo sintético.
 */
class MigracionDeltaEncuestasPlanificadoTest extends HistoricoTestCase
{
    private const TABLAS = ['cf_descargas', 'cf_correos', 'cf_certificados_legado', 'cf_plantillas_legado', 'cf_plantillas_legado_contenidos', 'cf_eventos', 'cf_migraciones_map',
        'cf_encuestas_respuestas', 'cf_encuestas_respuestas_detalle', 'cf_encuestas_versiones', 'cf_encuestas_preguntas', 'cf_encuestas_opciones', 'cf_conciliaciones', 'cf_migraciones_corridas'];

    private string $png = '';

    // ── Utilidades ────────────────────────────────────────────────────────────

    /** @param array<string,string> $extra nombre → contenido */
    private function imagenes(array $extra = []): array
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'delta_'.bin2hex(random_bytes(6));
        mkdir($dir);
        if ($this->png === '') {
            $im = imagecreatetruecolor(2, 2);
            ob_start();
            imagepng($im);
            $this->png = (string) ob_get_clean();
        }
        file_put_contents($dir.'/ALFA 2024.png', $this->png);
        file_put_contents($dir.'/DELTA 2023', $this->png);
        file_put_contents($dir.'/ORFANA.png', $this->png.'o');
        file_put_contents($dir.'/GAMMA_.png', $this->png.'g');
        foreach ($extra as $nombre => $contenido) {
            file_put_contents($dir.'/'.$nombre, $contenido);
        }
        $r = (new EscanerImagenes)->escanear($dir);
        File::deleteDirectory($dir);

        return $r;
    }

    private function participante(int $id, int $evento, ?string $tipo, ?string $doc, ?string $nombre, ?string $correo, ?int $verif = null): array
    {
        return ['id' => $id, 'tipo_documento' => $tipo, 'documento' => $doc, 'nombre' => $nombre, 'correo' => $correo, 'id_evento' => $evento, 'num_verificacion' => $verif];
    }

    private function base(): void
    {
        $this->cargar('s1', $this->datos(), $this->imagenes());
        (new MigradorHistorico)->ejecutar();
        (new MigradorEncuestas)->ejecutar();
    }

    /** @return array<string,array<int,array<string,mixed>>> evento 7 nuevo + participantes 16–19 + encuesta id 4 del evento 7 */
    private function datosDelta(): array
    {
        $d = $this->datos();
        $d['evento'][] = ['id' => 7, 'nombre' => 'Curso Eta 2026', 'imagen_certificado' => 'ETA 2026.png'];
        $d['participante'][] = $this->participante(16, 7, 'CC', '3000001', 'MARTA DIECISEIS', 'marta@example.test', 777);
        $d['participante'][] = $this->participante(17, 7, 'CC', '3000002', 'NICO DIECISIETE', 'nico1@example.test; nico2@example.test');
        $d['participante'][] = $this->participante(18, 7, 'CC', '3000003', 'OLGA DIECIOCHO', null);
        $d['participante'][] = $this->participante(19, 7, 'CC', 'ABC', 'PEDRO DIECINUEVE', 'pedro@example.test');
        $d['encuesta'][] = [
            'id' => 4, 'pregunta1' => 'Excelente', 'justificacion_pregunta1' => null, 'pregunta2' => 'Bueno', 'pregunta3' => 'Bueno', 'pregunta4' => 'Bueno',
            'pregunta5' => 'Muy bien', 'pregunta6' => '', 'pregunta7' => '', 'pregunta8' => '', 'pregunta9' => '',
            'id_evento' => 7, 'documento_participante' => '3000001', 'fecha' => '2026-10-14 12:00:00',
        ];

        return $d;
    }

    private function snapshotDelta(?array $datos = null): void
    {
        $im = imagecreatetruecolor(3, 3);
        ob_start();
        imagepng($im);
        $this->cargar('s2', $datos ?? $this->datosDelta(), $this->imagenes(['ETA 2026.png' => (string) ob_get_clean()]));
    }

    /** @return array<string,int> */
    private function conteos(): array
    {
        $r = [];
        foreach (self::TABLAS as $t) {
            $r[$t] = DB::table($t)->count();
        }

        return $r;
    }

    /** Encuesta nueva sobre un evento YA migrado (evento 1, participante 1). */
    private function datosConEncuestaDeEventoMigrado(): array
    {
        $d = $this->datos();
        $d['encuesta'][] = [
            'id' => 4, 'pregunta1' => 'Excelente', 'justificacion_pregunta1' => null, 'pregunta2' => 'Bueno', 'pregunta3' => 'Bueno', 'pregunta4' => 'Bueno',
            'pregunta5' => 'Muy bien', 'pregunta6' => '', 'pregunta7' => '', 'pregunta8' => '', 'pregunta9' => '',
            'id_evento' => 1, 'documento_participante' => $d['participante'][0]['documento'], 'fecha' => '2026-10-14 12:00:00',
        ];

        return $d;
    }

    // ── Casos A–I ─────────────────────────────────────────────────────────────

    public function test_a_encuesta_nueva_sobre_evento_ya_migrado_simula_ok(): void
    {
        $this->base();
        $this->snapshotDelta($this->datosConEncuestaDeEventoMigrado());
        $antes = $this->conteos();

        $r = (new MigradorDeltaEncuestas)->ejecutar(true);

        $this->assertTrue($r->noOp);
        $this->assertSame(1, $r->totales['respuestas_nuevas']);
        $this->assertSame($antes, $this->conteos());
    }

    public function test_b_g_h_encuesta_de_evento_nuevo_planificado_simula_ok_sin_escribir_ni_persistir_mapeo(): void
    {
        $this->base();
        $this->snapshotDelta();
        $antes = $this->conteos();
        $mapa = DB::table('cf_migraciones_map')->orderBy('id')->get()->map(fn ($m) => (array) $m)->all();

        $legado = (new MigradorDelta)->ejecutar(true);
        $r = (new MigradorDeltaEncuestas)->ejecutar(true);

        $this->assertSame(1, $legado->totales['eventos_nuevos']);
        $this->assertTrue($r->noOp);
        $this->assertTrue($r->totales['simulacion']);
        $this->assertSame(1, $r->totales['respuestas_nuevas']);
        $this->assertSame($antes, $this->conteos());
        $this->assertSame($mapa, DB::table('cf_migraciones_map')->orderBy('id')->get()->map(fn ($m) => (array) $m)->all());
        $this->assertSame(0, DB::table('cf_migraciones_map')->where('origen_tabla', 'evento')->where('origen_id', '7')->count());
        $this->assertSame(0, DB::table('cf_encuestas_respuestas')->where('old_evento_id', 7)->count());
    }

    public function test_c_modo_real_sin_delta_de_certificados_sigue_con_evento_no_migrado(): void
    {
        $this->base();
        $this->snapshotDelta();
        $antes = $this->conteos();

        try {
            (new MigradorDeltaEncuestas)->ejecutar();
            $this->fail('El modo real no usa el plan: debía rechazar');
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::EVENTO_NO_MIGRADO, $e->codigo);
        }
        $this->assertSame($antes, $this->conteos());
    }

    public function test_d_i_tras_el_delta_real_de_certificados_el_real_de_encuestas_funciona_e_idempotente(): void
    {
        $this->base();
        $this->snapshotDelta();
        $this->assertTrue((new MigradorDeltaEncuestas)->ejecutar(true)->noOp);

        (new MigradorDelta)->ejecutar();
        $this->assertTrue((new MigradorDeltaEncuestas)->ejecutar(true)->noOp);
        $r = (new MigradorDeltaEncuestas)->ejecutar();

        $this->assertFalse($r->noOp);
        $this->assertSame(1, DB::table('cf_encuestas_respuestas')->where('corrida_id', $r->corridaId)->count());
        $this->assertSame(MigracionCorrida::TIPO_ENCUESTAS_DELTA, MigracionCorrida::findOrFail($r->corridaId)->tipo);
        $antes = $this->conteos();
        $this->assertTrue((new MigradorDeltaEncuestas)->ejecutar()->noOp);
        $this->assertTrue((new MigradorDeltaEncuestas)->ejecutar(true)->noOp);
        $this->assertSame($antes, $this->conteos());
    }

    public function test_e_plan_de_certificados_bloqueado_por_participante_sin_evento_bloquea_la_simulacion(): void
    {
        $this->base();
        $datos = $this->datosDelta();
        $datos['participante'][] = $this->participante(23, 99, 'CC', '6000001', 'SIN EVENTO', 'x@example.test');
        $this->snapshotDelta($datos);
        $antes = $this->conteos();

        try {
            (new MigradorDeltaEncuestas)->ejecutar(true);
            $this->fail('El plan de certificados está bloqueado');
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::EVENTO_NO_MIGRADO, $e->codigo);
            $this->assertStringContainsString('bloqueado', $e->getMessage());
        }
        $this->assertSame($antes, $this->conteos());
    }

    public function test_f_fila_cambiada_en_certificados_bloquea_la_simulacion_de_encuestas(): void
    {
        $this->base();
        $datos = $this->conCambio($this->datosDelta(), 'participante', 1, ['nombre' => 'ANA UNO CAMBIADA']);
        $this->snapshotDelta($datos);

        try {
            (new MigradorDeltaEncuestas)->ejecutar(true);
            $this->fail('Debía bloquear');
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::EVENTO_NO_MIGRADO, $e->codigo);
        }
    }

    public function test_estructura_incompatible_sigue_bloqueando_con_evento_planificado(): void
    {
        $this->base();
        $datos = $this->datosDelta();
        $datos['encuesta'][3]['pregunta1'] = 'Opinión inventada';
        $this->snapshotDelta($datos);

        try {
            (new MigradorDeltaEncuestas)->ejecutar(true);
            $this->fail('Debía rechazar la opción nueva');
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::ESTRUCTURA_ENCUESTA_NUEVA, $e->codigo);
        }
    }
}
