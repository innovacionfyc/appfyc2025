<?php

namespace Tests\Feature\CredentialFlow\Migracion;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Correo;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\MigracionCorrida;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Migracion\GuardiaMigracion;
use App\Support\CredentialFlow\Migracion\MigradorHistorico;
use App\Support\CredentialFlow\Migracion\ResultadoMigracion;
use App\Support\CredentialFlow\Migracion\RollbackCorrida;
use App\Support\CredentialFlow\Migracion\RollbackNoPermitido;
use App\Support\CredentialFlow\StagingEv\EscanerImagenes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\CredentialFlow\CredentialFlowTestCase;
use Tests\Feature\CredentialFlow\StagingEv\FixturesStagingEv;

/**
 * Migración histórica (Fase 4) con el staging SINTÉTICO de las pruebas (nada pertenece a una persona real):
 * 6 eventos, 15 participantes (1 grupo idéntico, 3 conflictivos, 4 documentos en revisión), 12 candidatos de correo, 5 descargas
 * (una sin participante), 3 tokens, 3 encuestas y 4 imágenes (ok, extensión inválida, huérfana y candidata).
 */
class MigracionHistoricaTest extends CredentialFlowTestCase
{
    use FixturesStagingEv;

    private const FASE = [
        'database/migrations/2026_10_06_100000_cf_eventos_table.php',
        'database/migrations/2026_10_06_100100_add_evento_id_to_cf_lotes_table.php',
        'database/migrations/2026_10_06_100200_add_correo_columns_to_cf_participantes_table.php',
        'database/migrations/2026_10_06_100300_cf_plantillas_legado_table.php',
        'database/migrations/2026_10_06_100400_cf_certificados_legado_table.php',
        'database/migrations/2026_10_06_100500_cf_descargas_table.php',
        'database/migrations/2026_10_06_100600_cf_correos_table.php',
        'database/migrations/2026_10_06_100700_add_plantilla_legado_to_cf_eventos_table.php',
        'database/migrations/2026_10_08_100000_cf_migraciones_corridas_table.php',
        'database/migrations/2026_10_08_100100_add_corrida_id_to_tablas_historicas.php',
    ];

    private const TABLAS = ['cf_descargas', 'cf_correos', 'cf_certificados_legado', 'cf_plantillas_legado', 'cf_plantillas_legado_contenidos', 'cf_eventos', 'cf_migraciones_map'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => self::FASE, '--force' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--path' => 'database/staging/ev', '--force' => true])->assertExitCode(0);
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    /** @param array<string,array<int,array<string,mixed>>>|null $datos */
    private function staging(?array $datos = null, string $etiqueta = 's1'): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mig_'.bin2hex(random_bytes(6));
        mkdir($dir);
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        file_put_contents($dir.'/ALFA 2024.png', $png);
        file_put_contents($dir.'/DELTA 2023', $png);
        file_put_contents($dir.'/ORFANA.png', $png.'o');
        file_put_contents($dir.'/GAMMA_.png', $png.'g');
        $this->cargar($etiqueta, $datos, (new EscanerImagenes)->escanear($dir));
        File::deleteDirectory($dir);
    }

    private function migrar(): ResultadoMigracion
    {
        return (new MigradorHistorico)->ejecutar();
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

    private function cert(int $oldId): object
    {
        $id = DB::table('cf_migraciones_map')->where('origen_tabla', 'participante')->where('origen_id', (string) $oldId)->value('destino_id');

        return DB::table('cf_certificados_legado')->where('id', $id)->first();
    }

    // ── Migración completa ────────────────────────────────────────────────────

    public function test_migra_todo_con_los_conteos_del_staging_y_valida_contra_el_origen(): void
    {
        $this->staging();
        $r = $this->migrar();
        $t = $r->totales;

        $this->assertFalse($r->noOp);
        $this->assertSame(6, $t['eventos']);
        $this->assertSame(3, $t['contenidos']);   // ALFA y DELTA tienen el mismo contenido: 4 archivos, 3 SHA
        $this->assertSame(7, $t['plantillas']);   // 4 imágenes + 3 nombres faltantes
        $this->assertSame(['ok' => 1, 'faltante' => 3, 'huerfana' => 1, 'candidata_revision' => 1, 'extension_invalida' => 1], $t['plantillas_por_estado']);
        $this->assertSame(15, $t['certificados']);
        $this->assertSame(['total' => 12, 'valido' => 11, 'invalido' => 1, 'normalizado_distinto' => 0], $t['correos']);
        $this->assertSame(4, $t['descargas']['migradas']);
        $this->assertSame(1, $t['descargas']['sin_certificado']);
        $this->assertSame(['TIPO_DOCUMENTO_VACIO' => 1], $t['advertencias']);

        // Tablas finales.
        $this->assertSame([6, 3, 7, 15, 12, 4], [
            DB::table('cf_eventos')->count(), DB::table('cf_plantillas_legado_contenidos')->count(), DB::table('cf_plantillas_legado')->count(),
            DB::table('cf_certificados_legado')->count(), DB::table('cf_correos')->count(), DB::table('cf_descargas')->count(),
        ]);
        // Todo lo creado lleva la corrida; nada es visible, nada tiene PDF, y el mapa recoge cada fila de origen.
        foreach (['cf_eventos', 'cf_plantillas_legado_contenidos', 'cf_plantillas_legado', 'cf_certificados_legado', 'cf_correos', 'cf_descargas'] as $tabla) {
            $this->assertSame(0, DB::table($tabla)->whereNull('corrida_id')->count(), "$tabla sin corrida_id");
        }
        $this->assertSame(0, DB::table('cf_certificados_legado')->where('visible_portal', true)->count());
        $this->assertSame(0, DB::table('cf_certificados_legado')->whereNotNull('pdf_archivo')->count());
        $this->assertSame(0, DB::table('cf_certificados_legado')->where('intentos_generacion', '>', 0)->count());
        $this->assertSame(15, DB::table('cf_migraciones_map')->where('origen_tabla', 'participante')->count());
        $this->assertSame(6, DB::table('cf_migraciones_map')->where('origen_tabla', 'evento')->count());

        // Validaciones contra el origen: todas OK.
        $this->assertNotEmpty($t['validaciones']);
        foreach ($t['validaciones'] as $v) {
            $this->assertTrue($v['ok'], 'No cuadra: '.$v['clave']);
        }

        // La corrida registra snapshot, huellas, estado y totales.
        $c = MigracionCorrida::findOrFail($r->corridaId);
        $this->assertSame(MigracionCorrida::ESTADO_COMPLETADA, $c->estado);
        $this->assertSame(hash('sha256', 's1'), $c->snapshot_sha256);
        $this->assertSame([64, 64], [strlen($c->huella_global), strlen($c->huella_derivada)]);
        $this->assertNotNull($c->iniciado_at);
        $this->assertNotNull($c->finalizado_at);
        $this->assertNull($c->rollback_at);
        $this->assertSame($t['certificados'], $c->totales['certificados']);
    }

    public function test_los_totales_y_la_corrida_no_contienen_datos_personales(): void
    {
        $this->staging();
        $r = $this->migrar();
        $texto = json_encode($r->totales).json_encode(MigracionCorrida::find($r->corridaId)->toArray());

        foreach ([self::NOMBRE, self::DOCUMENTO, self::CORREO, self::TOKEN, self::TEXTO_ENCUESTA, 'GINA', 'HUGO'] as $dato) {
            $this->assertStringNotContainsString($dato, $texto);
        }
    }

    public function test_el_certificado_preserva_el_snapshot_original_y_el_codigo(): void
    {
        $this->staging();
        $this->migrar();

        $c = $this->cert(1);
        $this->assertSame(self::NOMBRE, $c->nombre_completo);
        $this->assertSame('CC', $c->tipo_documento);
        $this->assertSame(self::DOCUMENTO, $c->documento);
        $this->assertSame(self::DOCUMENTO, $c->documento_clave);
        $this->assertSame(self::CORREO, $c->correo);
        $this->assertSame('valido', $c->correo_estado);
        $this->assertSame('100', $c->codigo_legado);
        $this->assertSame('vigente', $c->estado);
        $this->assertSame('ok', $c->conciliacion_estado);
        $this->assertSame(0, (int) $c->visible_portal);
        $this->assertNotNull($c->plantilla_legado_id, 'El evento 1 tiene plantilla usable');

        $s = json_decode($c->snapshot_legado, true);
        $this->assertSame(1, $s['migracion']['old_id']);
        $this->assertSame(1, $s['migracion']['old_evento_id']);
        $this->assertSame('1.000.001', $s['documento_impreso']);
        $this->assertSame([], $s['advertencias']);
        $this->assertNull($s['duplicado']);
    }

    // ── Eventos y plantillas ──────────────────────────────────────────────────

    public function test_eventos_con_anio_no_deducible_y_ambiguo_quedan_en_null_con_marca_y_nunca_se_inventan(): void
    {
        $this->staging();
        $this->migrar();

        $porNombre = DB::table('cf_eventos')->get()->keyBy('nombre');
        $gamma = $porNombre['Curso Gamma'];
        $zeta = $porNombre['Curso Zeta 2024 y 2025'];

        $this->assertNull($gamma->anio);
        $this->assertContains('ANIO_NO_DEDUCIBLE', json_decode($gamma->notas, true)['marcas']);
        $this->assertNull($zeta->anio);
        $this->assertContains('ANIO_AMBIGUO', json_decode($zeta->notas, true)['marcas']);
        $this->assertSame(2024, (int) $porNombre['Curso Alfa 2024']->anio);
        foreach (DB::table('cf_eventos')->get() as $e) {
            $this->assertSame('legado', $e->origen);
            $this->assertSame('cerrado', $e->estado);
            $this->assertNull($e->fecha_inicio);
            $this->assertNull($e->fecha_fin);
        }
    }

    public function test_evento_con_imagen_faltante_queda_pendiente_sin_plantilla_inventada_y_con_la_evidencia_de_la_candidata(): void
    {
        $this->staging();
        $this->migrar();

        $gamma = DB::table('cf_eventos')->where('nombre', 'Curso Gamma')->first();
        $entrada = DB::table('cf_plantillas_legado')->where('id', $gamma->plantilla_legado_id)->first();

        $this->assertSame('faltante', $entrada->estado);
        $this->assertNull($entrada->contenido_id, 'Sin contenido: no se inventa plantilla');
        $this->assertFalse((bool) $entrada->renderizable);
        $this->assertContains('PENDIENTE_PLANTILLA:archivo_faltante', json_decode($gamma->notas, true)['marcas']);
        // La candidata queda como evidencia en las notas, SIN enlazar nada.
        $notas = json_decode($entrada->notas, true);
        $this->assertSame(hash('sha256', $this->pngGamma()), $notas['evidencia_candidata']['candidata_sha256']);
        $this->assertStringStartsWith('NINGUNA', $notas['evidencia_candidata']['decision']);
        // Y los certificados de ese evento quedan pendientes de plantilla (si no hay otro motivo).
        $this->assertSame('pendiente_plantilla', $this->cert(3)->conciliacion_estado);
        $this->assertNull($this->cert(3)->plantilla_legado_id);
    }

    private function pngGamma(): string
    {
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($im);

        return ob_get_clean().'g';
    }

    public function test_la_candidata_no_se_enlaza_a_ningun_evento_y_las_huerfanas_se_conservan(): void
    {
        $this->staging();
        $this->migrar();

        $candidata = DB::table('cf_plantillas_legado')->where('estado', 'candidata_revision')->first();
        $huerfana = DB::table('cf_plantillas_legado')->where('estado', 'huerfana')->first();

        $this->assertNotNull($candidata->contenido_id, 'La candidata conserva su contenido catalogado');
        $this->assertSame(0, DB::table('cf_eventos')->where('plantilla_legado_id', $candidata->id)->count(), 'NO se enlaza al evento');
        $this->assertSame(0, DB::table('cf_certificados_legado')->where('plantilla_legado_id', $candidata->id)->count());
        $this->assertNotNull($huerfana, 'Las huérfanas se conservan catalogadas');
        $this->assertSame(0, DB::table('cf_eventos')->where('plantilla_legado_id', $huerfana->id)->count());
        $this->assertStringContainsString('NO enlazada', (string) $candidata->notas);
    }

    public function test_evento_con_extension_invalida_queda_no_renderizable_aunque_el_contenido_exista(): void
    {
        $this->staging();
        $this->migrar();

        $delta = DB::table('cf_eventos')->where('nombre', 'Curso Delta 2023')->first();
        $entrada = DB::table('cf_plantillas_legado')->where('id', $delta->plantilla_legado_id)->first();

        $this->assertSame('extension_invalida', $entrada->estado);
        $this->assertNotNull($entrada->contenido_id, 'El archivo existe');
        $this->assertFalse((bool) $entrada->renderizable);
        $this->assertContains('PENDIENTE_PLANTILLA:extension_invalida', json_decode($delta->notas, true)['marcas']);
    }

    public function test_un_evento_con_extension_invalida_y_sin_archivo_tambien_queda_con_entrada_sin_contenido(): void
    {
        $this->staging();
        DB::table('stg_ev_evento')->where('old_id', 4)->update(['imagen_ref_id' => null]);   // el archivo de DELTA no está
        DB::table('stg_ev_imagenes')->where('nombre_original', 'DELTA 2023')->delete();

        $r = $this->migrar();

        $entrada = DB::table('cf_plantillas_legado')->where('ruta_original', 'document/certImages/DELTA 2023')->first();
        $this->assertSame('extension_invalida', $entrada->estado);
        $this->assertNull($entrada->contenido_id);
        $this->assertSame(1, $r->totales['plantillas_por_estado']['extension_invalida']);
    }

    public function test_un_contenido_compartido_por_dos_nombres_se_registra_una_sola_vez(): void
    {
        $this->staging();
        // Un segundo archivo con el mismo contenido que ALFA: dos entradas, un solo contenido.
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mig_'.bin2hex(random_bytes(6));
        mkdir($dir);
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        file_put_contents($dir.'/ALFA 2024.png', $png);
        file_put_contents($dir.'/ALFA copia.png', $png);
        DB::table('stg_ev_imagenes')->delete();
        $this->cargar('s2', null, (new EscanerImagenes)->escanear($dir), hash('sha256', 's2-img'));
        File::deleteDirectory($dir);

        $r = $this->migrar();

        $this->assertSame(1, $r->totales['contenidos']);
        $this->assertSame(2, DB::table('cf_plantillas_legado')->whereNotNull('contenido_id')->count());
    }

    // ── Duplicados y conflictivos ─────────────────────────────────────────────

    public function test_los_duplicados_identicos_se_conservan_todos_con_un_canonico_y_todos_los_old_id_en_el_mapa(): void
    {
        $this->staging();
        $this->migrar();

        $canonico = $this->cert(7);
        $copia = $this->cert(8);
        $this->assertNotSame($canonico->id, $copia->id, 'No se consolida: hay una fila por old_id');
        $this->assertSame('ok', $canonico->conciliacion_estado);
        $this->assertSame('duplicado_consolidado', $copia->conciliacion_estado);
        $this->assertSame($canonico->grupo_duplicado, $copia->grupo_duplicado);
        $this->assertNotNull($canonico->grupo_duplicado);
        $this->assertSame(0, (int) $copia->visible_portal);
        $this->assertSame('101', $canonico->codigo_legado);
        $this->assertSame('101', $copia->codigo_legado, 'El código repetido se permite (sin UNIQUE)');

        $mapa = DB::table('cf_migraciones_map')->where('origen_tabla', 'participante')->whereIn('origen_id', ['7', '8'])->orderBy('origen_id')->get();
        $this->assertSame(['canonico', 'duplicado_identico'], $mapa->pluck('relacion')->all());
        $this->assertSame(7, json_decode($mapa[1]->detalle, true)['canonico_old_id']);
        $this->assertSame([7, 8], json_decode($this->cert(8)->snapshot_legado, true)['duplicado']['old_ids']);
    }

    public function test_los_conflictivos_conservan_todas_las_variantes_pendientes_de_conciliacion_y_no_visibles(): void
    {
        $this->staging();
        $r = $this->migrar();

        foreach ([9, 10, 11, 12, 13, 14] as $old) {
            $c = $this->cert($old);
            $this->assertSame('pendiente_conciliacion', $c->conciliacion_estado, "old $old");
            $this->assertSame(0, (int) $c->visible_portal);
            $this->assertNotNull($c->grupo_duplicado);
        }
        $this->assertSame(6, DB::table('cf_migraciones_map')->where('relacion', 'variante_conflictiva')->count());
        $this->assertSame(3, $r->totales['duplicados']['grupos_conflictivos']);
        $this->assertSame(6, $r->totales['duplicados']['filas_conflictivas']);
        $this->assertSame(1, $r->totales['duplicados']['grupos_identicos']);
        $this->assertSame(['HUGO NUEVE', 'HUGO NUEVE X'], [$this->cert(9)->nombre_completo, $this->cert(10)->nombre_completo], 'Ninguna variante se corrige ni descarta');
    }

    public function test_los_documentos_en_revision_se_migran_marcados_sin_normalizar_y_no_visibles(): void
    {
        $this->staging();
        $r = $this->migrar();

        foreach ([4, 5, 6, 15] as $old) {
            $c = $this->cert($old);
            $this->assertSame('revision_documento', $c->conciliacion_estado, "old $old");
            $this->assertSame(0, (int) $c->visible_portal);
            $this->assertSame(0, (int) $c->intentos_generacion);
        }
        $this->assertSame('ABC123', $this->cert(4)->documento, 'El documento original no se corrige');
        $this->assertSame('73.156.827', $this->cert(6)->documento);
        $this->assertSame('', (string) $this->cert(5)->documento);
        $this->assertNull($this->cert(15)->documento);
        $this->assertSame(4, $r->totales['certificados_por_conciliacion']['revision_documento']);
        $s = json_decode($this->cert(4)->snapshot_legado, true);
        $this->assertContains('REVISION_DOCUMENTO', $s['motivos']);
        $this->assertContains('TIPO_DOCUMENTO_VACIO', $s['advertencias'], 'El tipo vacío se migra tal cual y queda la advertencia');
        $this->assertNull($this->cert(4)->tipo_documento);
    }

    // ── Correos ───────────────────────────────────────────────────────────────

    public function test_correos_multiples_se_conservan_todos_sin_principal_y_el_simple_queda_multiple(): void
    {
        $datos = $this->conCambio($this->datos(), 'participante', 3, ['correo' => 'carla1@example.test; Carla2@example.test']);
        $this->staging($datos);
        $this->migrar();

        $c = $this->cert(3);
        $this->assertSame('multiple', $c->correo_estado);
        $this->assertNull($c->correo_normalizado, 'Con varios válidos no se elige ninguno');
        $this->assertSame('carla1@example.test; Carla2@example.test', $c->correo, 'El original se conserva');

        $correos = DB::table('cf_correos')->where('certificado_legado_id', $c->id)->orderBy('orden')->get();
        $this->assertSame(['carla1@example.test', 'carla2@example.test'], $correos->pluck('correo_normalizado')->all());
        $this->assertSame([1, 2], $correos->pluck('orden')->map(fn ($o) => (int) $o)->all());
        $this->assertSame(['valido', 'valido'], $correos->pluck('estado')->all());
        $this->assertSame([0, 0], $correos->pluck('es_principal')->map(fn ($p) => (int) $p)->all(), 'Sin principal automático');
        $this->assertSame(['legado', 'legado'], $correos->pluck('origen')->all());
        $this->assertNull($correos[0]->participante_id);
    }

    public function test_correo_unico_valido_se_refleja_en_los_campos_simples_y_el_invalido_se_conserva_como_invalido(): void
    {
        $this->staging();
        $this->migrar();

        $uno = $this->cert(1);
        $this->assertSame(self::CORREO, $uno->correo_normalizado);
        $this->assertSame(1, DB::table('cf_correos')->where('certificado_legado_id', $uno->id)->where('estado', 'valido')->count());

        $malo = $this->cert(2);
        $this->assertSame('invalido', $malo->correo_estado);
        $this->assertNull($malo->correo_normalizado);
        $this->assertSame('correo-invalido', $malo->correo, 'Se conserva el original');
        $this->assertSame(1, DB::table('cf_correos')->where('certificado_legado_id', $malo->id)->where('estado', 'invalido')->count());
        // Sin correo: ninguna fila.
        $this->assertSame(0, DB::table('cf_correos')->where('certificado_legado_id', $this->cert(3)->id)->count());
        $this->assertSame('sin_correo', $this->cert(3)->correo_estado);
    }

    public function test_el_modelo_correo_ve_lo_migrado_y_cada_certificado_conoce_sus_correos(): void
    {
        $this->staging();
        $this->migrar();

        $cert = CertificadoLegado::find($this->cert(1)->id);
        $this->assertSame(self::CORREO, $cert->correoUnicoUtilizable()?->correo_normalizado);
        $this->assertSame(1, Correo::where('certificado_legado_id', $cert->id)->count());
    }

    // ── Descargas, tokens y encuestas ─────────────────────────────────────────

    public function test_descargas_se_migran_con_su_fecha_historica_y_la_huerfana_no_se_inventa(): void
    {
        $this->staging();
        $r = $this->migrar();

        $uno = $this->cert(1);
        $fechas = DB::table('cf_descargas')->where('certificado_legado_id', $uno->id)->orderBy('descargado_at')->pluck('descargado_at')->all();
        $this->assertSame(['2026-01-01 10:00:00', '2026-01-05 10:00:00'], $fechas);
        $this->assertSame(4, DB::table('cf_descargas')->count());
        $this->assertSame(0, DB::table('cf_descargas')->whereNotNull('participante_id')->count());
        $this->assertSame(0, DB::table('cf_descargas')->whereNotNull('emision_id')->count());
        $this->assertSame([4], [$r->totales['descargas']['old_ids_sin_certificado'][0]], 'La descarga 4 (participante inexistente) queda en staging');
        $this->assertSame(1, DB::table('stg_ev_descargas')->where('participante_existe', false)->count(), 'El staging conserva la descarga');
    }

    public function test_tokens_no_se_migran_y_encuestas_quedan_pendientes_en_staging(): void
    {
        $this->staging();
        $antes = [DB::table('stg_ev_token')->count(), DB::table('stg_ev_encuesta')->count()];
        $r = $this->migrar();

        $this->assertSame(3, $r->totales['tokens']['en_staging']);
        $this->assertSame(0, $r->totales['tokens']['migrados']);
        $this->assertStringContainsString('NO_MIGRAR', $r->totales['tokens']['destino']);
        $this->assertSame(3, $r->totales['encuestas']['en_staging']);
        $this->assertSame(0, $r->totales['encuestas']['migradas']);
        $this->assertSame($antes, [DB::table('stg_ev_token')->count(), DB::table('stg_ev_encuesta')->count()], 'El staging no se toca');
    }

    // ── Idempotencia ──────────────────────────────────────────────────────────

    public function test_la_misma_migracion_dos_veces_es_un_no_op_y_no_duplica_nada(): void
    {
        $this->staging();
        $primera = $this->migrar();
        $antes = $this->conteos();
        $corridas = MigracionCorrida::count();

        $segunda = $this->migrar();

        $this->assertTrue($segunda->noOp);
        $this->assertSame($primera->corridaId, $segunda->corridaId);
        $this->assertStringContainsString('ya está migrado', $segunda->mensaje);
        $this->assertSame($antes, $this->conteos());
        $this->assertSame($corridas, MigracionCorrida::count(), 'No se crea otra corrida');
    }

    public function test_otro_snapshot_se_niega_mientras_haya_una_corrida_completada_y_se_puede_tras_revertirla(): void
    {
        $this->staging();
        $primera = $this->migrar();
        $antes = $this->conteos();
        $this->cargar('s2', $this->conCambio($this->datos(), 'participante', 3, ['nombre' => 'CARLA TRES CAMBIADA']), null, hash('sha256', 's2'));

        try {
            $this->migrar();
            $this->fail('Debía negarse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('OTRO snapshot', $e->getMessage());
        }
        $this->assertSame($antes, $this->conteos(), 'No se toca lo migrado');
        $this->assertSame(1, MigracionCorrida::count(), 'Ni siquiera se crea una corrida');

        // Tras revertir la primera, las huellas nuevas se migran como una corrida nueva.
        (new RollbackCorrida)->revertir($primera->corridaId);
        $segunda = $this->migrar();

        $this->assertFalse($segunda->noOp);
        $this->assertNotSame($primera->corridaId, $segunda->corridaId);
        $this->assertSame('CARLA TRES CAMBIADA', $this->cert(3)->nombre_completo);
    }

    // ── Atomicidad ────────────────────────────────────────────────────────────

    public function test_si_una_cifra_no_cuadra_se_revierte_toda_la_corrida_y_no_queda_nada_a_medias(): void
    {
        // Un código compartido por dos personas distintas: la migración debe negarse (el código no es único, pero no se comparte).
        $datos = $this->conCambio($this->datos(), 'participante', 11, ['num_verificacion' => 100]);
        $this->staging($datos);

        try {
            $this->migrar();
            $this->fail('Debía fallar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('codigos_compartidos_entre_pares_distintos', $e->getMessage());
        }

        $this->assertSame(array_fill_keys(self::TABLAS, 0), $this->conteos(), 'Nada a medias');
        $c = MigracionCorrida::sole();
        $this->assertSame(MigracionCorrida::ESTADO_FALLIDA, $c->estado);
        $this->assertSame(RuntimeException::class, $c->error_codigo);
        $this->assertFalse(collect($c->totales['validaciones'])->firstWhere('clave', 'codigos_compartidos_entre_pares_distintos')['ok']);
        // Una corrida fallida no impide reintentar cuando se corrige el origen.
        $this->assertSame(0, MigracionCorrida::where('estado', 'completada')->count());
    }

    public function test_un_evento_inexistente_en_un_participante_aborta_sin_dejar_nada(): void
    {
        $this->staging();
        DB::table('stg_ev_participante')->where('old_id', 1)->update(['old_evento_id' => 999]);

        try {
            $this->migrar();
            $this->fail('Debía fallar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no existe', $e->getMessage());
        }
        $this->assertSame(array_fill_keys(self::TABLAS, 0), $this->conteos());
        $this->assertSame('fallida', MigracionCorrida::sole()->estado);
    }

    // ── Rollback técnico ──────────────────────────────────────────────────────

    public function test_el_rollback_devuelve_todo_a_cero_marca_la_corrida_revertida_y_no_toca_el_staging(): void
    {
        $this->staging();
        $r = $this->migrar();
        $stg = [DB::table('stg_ev_participante')->count(), DB::table('stg_ev_evento')->count(), DB::table('stg_ev_imagenes')->count(), DB::table('stg_ev_token')->count()];

        $borrado = (new RollbackCorrida)->revertir($r->corridaId);

        $this->assertSame(array_fill_keys(self::TABLAS, 0), $this->conteos());
        // Mapa: 6 eventos + 3 contenidos + 4 imágenes + 3 nombres faltantes + 15 participantes + 4 descargas.
        $this->assertSame(['descargas' => 4, 'correos' => 12, 'certificados' => 15, 'plantillas' => 7, 'contenidos' => 3, 'eventos' => 6, 'mapas' => 35, 'contenidos_conservados' => 0], $borrado);
        $c = MigracionCorrida::findOrFail($r->corridaId);
        $this->assertSame('revertida', $c->estado);
        $this->assertNotNull($c->rollback_at);
        $this->assertSame(15, $c->totales['rollback']['certificados']);
        $this->assertSame($stg, [DB::table('stg_ev_participante')->count(), DB::table('stg_ev_evento')->count(), DB::table('stg_ev_imagenes')->count(), DB::table('stg_ev_token')->count()], 'El staging NO se borra');
        // Auditoría: solo conteos.
        $m = Movimiento::where('tipo', 'rollback')->sole();
        $this->assertSame($r->corridaId, $m->metadata['corrida_id']);
        $this->assertStringNotContainsString(self::NOMBRE, json_encode($m->toArray()));
    }

    public function test_tras_el_rollback_una_corrida_nueva_reproduce_exactamente_los_mismos_conteos(): void
    {
        $this->staging();
        $primera = $this->migrar();
        $conteos = $this->conteos();
        $totales = $primera->totales;
        (new RollbackCorrida)->revertir($primera->corridaId);

        $segunda = $this->migrar();

        $this->assertFalse($segunda->noOp, 'Tras revertir, esas huellas se pueden migrar otra vez');
        $this->assertNotSame($primera->corridaId, $segunda->corridaId);
        $this->assertSame($conteos, $this->conteos());
        foreach (['eventos', 'contenidos', 'plantillas', 'certificados', 'plantillas_por_estado', 'certificados_por_conciliacion', 'duplicados', 'correos', 'descargas'] as $k) {
            $this->assertSame($totales[$k], $segunda->totales[$k], $k);
        }
        $this->assertSame(['revertida', 'completada'], MigracionCorrida::orderBy('id')->pluck('estado')->all());
    }

    public function test_el_rollback_se_niega_si_la_corrida_no_esta_completada_o_no_existe(): void
    {
        $this->assertRollbackNegado(99999, RollbackNoPermitido::NO_EXISTE);

        $this->staging();
        $r = $this->migrar();
        (new RollbackCorrida)->revertir($r->corridaId);
        $this->assertRollbackNegado($r->corridaId, RollbackNoPermitido::NO_COMPLETADA);   // ya revertida
    }

    private function assertRollbackNegado(int $id, string $codigo): void
    {
        try {
            (new RollbackCorrida)->revertir($id);
            $this->fail("Debía negarse con $codigo");
        } catch (RollbackNoPermitido $e) {
            $this->assertSame($codigo, $e->codigo);
        }
    }

    /** @return array<string,array{0:callable,1:string}> */
    public static function actividadPosterior(): array
    {
        return [
            'pdf congelado' => [fn () => DB::table('cf_certificados_legado')->limit(1)->update(['pdf_archivo' => 'credential-flow/legado/certificados/1/certificado.pdf', 'pdf_hash' => str_repeat('a', 64), 'materializado_at' => now()]), RollbackNoPermitido::PDF_CONGELADO],
            'certificado visible' => [fn () => DB::table('cf_certificados_legado')->limit(1)->update(['visible_portal' => true]), RollbackNoPermitido::CERTIFICADOS_MODIFICADOS],
            'certificado revocado' => [fn () => DB::table('cf_certificados_legado')->limit(1)->update(['estado' => 'revocado']), RollbackNoPermitido::CERTIFICADOS_MODIFICADOS],
            'certificado con intento de generación' => [fn () => DB::table('cf_certificados_legado')->limit(1)->update(['intentos_generacion' => 1]), RollbackNoPermitido::CERTIFICADOS_MODIFICADOS],
            'certificado editado por un usuario' => [fn () => DB::table('cf_certificados_legado')->limit(1)->update(['update_by' => 1]), RollbackNoPermitido::CERTIFICADOS_MODIFICADOS],
            'descarga nueva sin corrida' => [fn () => DB::table('cf_descargas')->insert(['certificado_legado_id' => DB::table('cf_certificados_legado')->value('id'), 'via' => 'portal', 'created_at' => now(), 'updated_at' => now()]), RollbackNoPermitido::DESCARGAS_NUEVAS],
            'correo nuevo sin corrida' => [fn () => DB::table('cf_correos')->insert(['certificado_legado_id' => DB::table('cf_certificados_legado')->value('id'), 'correo' => 'nuevo@example.test', 'correo_normalizado' => 'nuevo@example.test', 'estado' => 'valido', 'orden' => 9, 'es_principal' => false, 'origen' => 'legado', 'created_at' => now(), 'updated_at' => now()]), RollbackNoPermitido::CORREOS_NUEVOS],
            'correo marcado como principal' => [fn () => DB::table('cf_correos')->where('estado', 'valido')->limit(1)->update(['es_principal' => true]), RollbackNoPermitido::CORREOS_NUEVOS],
            'evento usado por una base moderna' => [function () {
                $p = DB::table('cf_plantillas')->insertGetId(['nombre' => 'P', 'archivo_pdf' => 'x', 'nombre_archivo_original' => 'x.pdf', 'hash_sha256' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('cf_lotes')->insert(['plantilla_id' => $p, 'evento_id' => DB::table('cf_eventos')->value('id'), 'nombre' => 'L', 'datos_comunes' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            }, RollbackNoPermitido::EVENTOS_EN_USO],
            'evento editado' => [fn () => DB::table('cf_eventos')->limit(1)->update(['update_by' => 1]), RollbackNoPermitido::EVENTOS_EN_USO],
            'certificado de otro origen en un evento de la corrida' => [fn () => DB::table('cf_certificados_legado')->insert([
                'evento_id' => DB::table('cf_eventos')->value('id'), 'documento_clave' => 'X', 'nombre_completo' => 'N', 'snapshot_legado' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]), RollbackNoPermitido::EVENTOS_EN_USO],
            'plantilla editada' => [fn () => DB::table('cf_plantillas_legado')->limit(1)->update(['update_by' => 1]), RollbackNoPermitido::PLANTILLAS_COMPARTIDAS],
        ];
    }

    #[DataProvider('actividadPosterior')]
    public function test_el_rollback_se_niega_ante_actividad_posterior_y_no_toca_nada(callable $actividad, string $codigo): void
    {
        $this->staging();
        $r = $this->migrar();
        $actividad();
        $antes = $this->conteos();

        $this->assertRollbackNegado($r->corridaId, $codigo);

        $this->assertSame($antes, $this->conteos(), 'No se tocó nada');
        $this->assertSame('completada', MigracionCorrida::find($r->corridaId)->estado);
    }

    public function test_el_rollback_se_niega_si_hay_una_corrida_posterior_completada(): void
    {
        $this->staging();
        $primera = $this->migrar();
        $this->cargar('s2', $this->conCambio($this->datos(), 'participante', 3, ['nombre' => 'CARLA TRES CAMBIADA']), null, hash('sha256', 's2'));
        // Una segunda corrida (otras huellas) se apoya sobre la primera.
        $segunda = MigracionCorrida::create(['tipo' => MigracionCorrida::TIPO_LEGADO, 'snapshot_sha256' => str_repeat('b', 64), 'huella_global' => str_repeat('c', 64), 'huella_derivada' => str_repeat('d', 64), 'estado' => 'completada']);

        $this->assertRollbackNegado($primera->corridaId, RollbackNoPermitido::CORRIDA_POSTERIOR);
        $this->assertNotNull($segunda->id);
    }

    public function test_un_contenido_que_sigue_en_uso_por_otra_entrada_se_conserva_en_el_rollback(): void
    {
        $this->staging();
        $r = $this->migrar();
        // Otra corrida/origen creó una entrada que usa un contenido de esta corrida.
        $contenido = DB::table('cf_plantillas_legado_contenidos')->value('id');
        DB::table('cf_plantillas_legado')->insert([
            'contenido_id' => $contenido, 'ruta_original' => 'otra/ruta.png', 'nombre_original' => 'otra.png', 'nombre_normalizado' => 'otra', 'estado' => 'ok',
            'renderizable' => true, 'corrida_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $borrado = (new RollbackCorrida)->revertir($r->corridaId);

        $this->assertSame(1, $borrado['contenidos_conservados']);
        $this->assertSame(1, DB::table('cf_plantillas_legado_contenidos')->count());
        $this->assertSame(2, $borrado['contenidos']);
    }

    // ── Guardias ──────────────────────────────────────────────────────────────

    public function test_la_guardia_solo_admite_bases_locales_de_pruebas_como_destino(): void
    {
        $base = config('database.connections.mysql');
        $probar = function (string $nombre, array $extra = []) use ($base) {
            config(['database.connections.guardia' => array_merge($base, ['database' => $nombre], $extra)]);
            DB::purge('guardia');
            GuardiaMigracion::exigirDestinoSeguro('guardia');
        };

        $probar('appfyc2025_migracion_legado_test');
        $probar('appfyc2025_fase1_test');
        foreach (['appfyc', 'appfyc2025', 'appfyc2025_staging_ev', 'produccion'] as $mala) {
            try {
                $probar($mala);
                $this->fail("Debía rechazar $mala");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('no es una base local de pruebas', $e->getMessage());
            }
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no es local');
        $probar('appfyc2025_migracion_legado_test', ['host' => 'db.ejemplo.com']);
    }

    public function test_la_guardia_exige_un_origen_de_staging(): void
    {
        $base = config('database.connections.mysql');
        config(['database.connections.guardia' => array_merge($base, ['database' => 'appfyc2025_staging_ev'])]);
        DB::purge('guardia');
        GuardiaMigracion::exigirOrigenStaging('guardia');

        config(['database.connections.guardia' => array_merge($base, ['database' => 'appfyc'])]);
        DB::purge('guardia');
        $this->expectException(RuntimeException::class);
        GuardiaMigracion::exigirOrigenStaging('guardia');
    }

    public function test_no_hay_ruta_web_ni_servicio_administrativo_para_el_rollback_y_las_emisiones_no_llevan_corrida(): void
    {
        foreach (app('router')->getRoutes() as $ruta) {
            $this->assertDoesNotMatchRegularExpression('/rollback|corrida|migrac/i', $ruta->uri().' '.$ruta->getActionName(), 'El rollback no es accesible por web');
        }
        $this->assertFalse(\Schema::hasColumn('cf_emisiones', 'corrida_id'));
        $this->assertSame(0, Lote::count());
    }
}
