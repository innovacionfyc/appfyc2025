<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Correo;
use App\Models\CredentialFlow\Descarga;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\MigracionCorrida;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\PlantillaLegado;
use App\Models\CredentialFlow\PlantillaLegadoContenido;
use App\Support\CredentialFlow\Participantes\ValidadorParticipante;
use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fase 1 del modelo aditivo (histórico de evaluaciones): solo estructura, sin datos ni lógica.
 *
 * Garantiza que las siete migraciones nuevas suben y bajan limpio, que NO tocan cf_plantillas ni cf_emisiones, que en
 * cf_lotes/cf_participantes solo AÑADEN columnas nullable, y que los índices, las FK y la barrera de cf_descargas son
 * los previstos. El CHECK real se prueba contra MySQL en tests/Integracion/EsquemaLegadoMysqlTest.php (aquí corre SQLite).
 */
class EsquemaLegadoTest extends CredentialFlowTestCase
{
    private const FASE_1 = [
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

    private const TABLAS_NUEVAS = ['cf_eventos', 'cf_plantillas_legado_contenidos', 'cf_plantillas_legado', 'cf_certificados_legado', 'cf_descargas', 'cf_correos', 'cf_migraciones_corridas', 'cf_migraciones_map'];

    private function subir(): void
    {
        $this->artisan('migrate', ['--path' => self::FASE_1, '--force' => true])->assertExitCode(0);
    }

    private function bajar(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::FASE_1, '--force' => true])->assertExitCode(0);
    }

    /** @return array<string,array{tipo:string,nullable:bool,default:mixed}> */
    private function columnas(string $tabla): array
    {
        $r = [];
        foreach (Schema::getColumns($tabla) as $c) {
            $r[$c['name']] = ['tipo' => $c['type'], 'nullable' => (bool) $c['nullable'], 'default' => $c['default']];
        }
        ksort($r);

        return $r;
    }

    /** @return array<int,string> "col1,col2" o "col1,col2!" (único) por índice, sin la PK */
    private function indices(string $tabla): array
    {
        $r = [];
        foreach (Schema::getIndexes($tabla) as $i) {
            if ($i['primary']) {
                continue;
            }
            $r[$i['name']] = implode(',', $i['columns']).($i['unique'] ? '!' : '');
        }
        ksort($r);

        return $r;
    }

    /** @return array<string,string> "col -> tabla.col [on delete]" */
    private function llaves(string $tabla): array
    {
        $r = [];
        foreach (Schema::getForeignKeys($tabla) as $f) {
            $r[implode(',', $f['columns'])] = $f['foreign_table'].'.'.implode(',', $f['foreign_columns']).' '.strtolower((string) $f['on_delete']);
        }
        ksort($r);

        return $r;
    }

    private function evento(array $extra = []): EventoCertificacion
    {
        return EventoCertificacion::create($extra + ['nombre' => 'Evento de prueba', 'nombre_normalizado' => 'evento de prueba', 'origen' => 'legado']);
    }

    // ── Sube y baja ───────────────────────────────────────────────────────────

    public function test_las_migraciones_suben_y_crean_lo_previsto(): void
    {
        foreach (self::TABLAS_NUEVAS as $tabla) {
            $this->assertFalse(Schema::hasTable($tabla));
        }

        $this->subir();

        foreach (self::TABLAS_NUEVAS as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), "Falta $tabla");
        }
        $this->assertTrue(Schema::hasColumn('cf_lotes', 'evento_id'));
        $this->assertTrue(Schema::hasColumns('cf_participantes', ['correo', 'correo_normalizado', 'correo_estado']));
    }

    public function test_el_rollback_deja_todo_como_estaba_y_se_puede_repetir(): void
    {
        $antes = [];
        foreach (['cf_plantillas', 'cf_lotes', 'cf_participantes', 'cf_emisiones'] as $t) {
            $antes[$t] = [$this->columnas($t), $this->indices($t), $this->llaves($t)];
        }

        $this->subir();
        $this->bajar();

        foreach (self::TABLAS_NUEVAS as $tabla) {
            $this->assertFalse(Schema::hasTable($tabla), "$tabla sigue existiendo tras el rollback");
        }
        foreach ($antes as $t => $estado) {
            $this->assertSame($estado, [$this->columnas($t), $this->indices($t), $this->llaves($t)], "$t cambió tras subir y bajar");
        }

        // Ciclo completo repetible.
        $this->subir();
        $this->bajar();
        $this->subir();
        $this->assertTrue(Schema::hasTable('cf_descargas'));
    }

    // ── cf_plantillas y cf_emisiones no cambian ───────────────────────────────

    public function test_cf_plantillas_y_cf_emisiones_quedan_intactas(): void
    {
        $antes = [];
        foreach (['cf_plantillas', 'cf_emisiones'] as $t) {
            $antes[$t] = [$this->columnas($t), $this->indices($t), $this->llaves($t)];
        }
        // La garantía de cf_emisiones sigue siendo NOT NULL en el PDF y sus datos.
        foreach (['pdf_archivo', 'pdf_hash', 'pdf_bytes', 'datos_snapshot', 'diseno_snapshot', 'plantilla_pdf_hash', 'generador_snapshot', 'codigo'] as $c) {
            $this->assertFalse($antes['cf_emisiones'][0][$c]['nullable'], "cf_emisiones.$c debe seguir siendo NOT NULL");
        }
        $this->assertFalse($antes['cf_plantillas'][0]['archivo_pdf']['nullable']);

        $this->subir();

        foreach (['cf_plantillas', 'cf_emisiones'] as $t) {
            $this->assertSame($antes[$t], [$this->columnas($t), $this->indices($t), $this->llaves($t)], "$t no debe cambiar en esta fase");
        }
    }

    // ── cf_lotes y cf_participantes: solo se añade, todo nullable ─────────────

    public function test_cf_lotes_y_cf_participantes_solo_ganan_columnas_nullable_y_los_registros_actuales_no_cambian(): void
    {
        $plantilla = $this->crearPlantilla();
        $loteId = DB::table('cf_lotes')->insertGetId([
            'plantilla_id' => $plantilla->id, 'nombre' => 'Base anterior', 'datos_comunes' => json_encode(['evento' => 'X']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $participanteId = DB::table('cf_participantes')->insertGetId([
            'lote_id' => $loteId, 'nombre_completo' => 'ANA PÉREZ', 'documento' => '123.456', 'documento_clave' => '123456',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $lotes = $this->columnas('cf_lotes');
        $participantes = $this->columnas('cf_participantes');
        $filaLote = (array) DB::table('cf_lotes')->find($loteId);
        $filaParticipante = (array) DB::table('cf_participantes')->find($participanteId);

        $this->subir();

        $nuevasLote = array_diff_key($this->columnas('cf_lotes'), $lotes);
        $nuevasParticipante = array_diff_key($this->columnas('cf_participantes'), $participantes);
        $this->assertSame(['evento_id'], array_keys($nuevasLote));
        $this->assertSame(['correo', 'correo_estado', 'correo_normalizado'], array_keys($nuevasParticipante));
        foreach ([...$nuevasLote, ...$nuevasParticipante] as $nombre => $c) {
            $this->assertTrue($c['nullable'], "$nombre debe ser nullable");
            $this->assertNull($c['default'], "$nombre no debe tener valor por defecto");
        }
        // Las columnas que ya existían conservan tipo, nulabilidad y default.
        foreach ([[$lotes, 'cf_lotes'], [$participantes, 'cf_participantes']] as [$antes, $tabla]) {
            foreach ($antes as $nombre => $c) {
                $this->assertSame($c, $this->columnas($tabla)[$nombre], "$tabla.$nombre cambió");
            }
        }

        // Los registros existentes siguen idénticos (más las columnas nuevas en NULL).
        $despuesLote = (array) DB::table('cf_lotes')->find($loteId);
        $despuesParticipante = (array) DB::table('cf_participantes')->find($participanteId);
        $this->assertSame($filaLote + ['evento_id' => null], $despuesLote);
        $this->assertSame($filaParticipante + ['correo' => null, 'correo_normalizado' => null, 'correo_estado' => null], $despuesParticipante);

        $this->assertNull(Lote::find($loteId)->evento);
        $this->assertSame('123456', Participante::find($participanteId)->documento_clave);
    }

    public function test_el_validador_de_participantes_no_cambia(): void
    {
        // La fase 1 no toca ValidadorParticipante: su firma pública sigue siendo (nombre, documento, fila).
        $metodo = new \ReflectionMethod(ValidadorParticipante::class, 'validar');
        $this->assertSame(['nombre', 'documento', 'fila'], array_map(fn ($p) => $p->getName(), $metodo->getParameters()));
    }

    // ── Índices y llaves ──────────────────────────────────────────────────────

    public function test_indices_previstos(): void
    {
        $this->subir();

        $this->assertSame([
            'cf_eventos_anio_estado_idx' => 'anio,estado',
            'cf_eventos_nombre_norm_idx' => 'nombre_normalizado',
        ], $this->indices('cf_eventos'));

        $part = $this->indices('cf_participantes');
        $this->assertSame('documento_clave', $part['cf_participantes_documento_clave_idx']);
        $this->assertSame('documento_clave,correo_normalizado', $part['cf_participantes_doc_correo_idx']);
        $this->assertContains('lote_id,documento_clave', $part, 'El índice anterior debe seguir existiendo');

        $legado = $this->indices('cf_certificados_legado');
        $this->assertSame('documento_clave', $legado['cf_cert_legado_documento_idx']);
        $this->assertSame('documento_clave,correo_normalizado', $legado['cf_cert_legado_doc_correo_idx']);
        $this->assertSame('codigo_legado', $legado['cf_cert_legado_codigo_idx'], 'codigo_legado NO es UNIQUE');
        $this->assertSame('grupo_duplicado', $legado['cf_cert_legado_grupo_idx']);
        $this->assertSame('conciliacion_estado', $legado['cf_cert_legado_concil_idx']);
        $this->assertSame('evento_id', $legado['cf_cert_legado_evento_idx']);

        $this->assertContains('sha256!', $this->indices('cf_plantillas_legado_contenidos'));
        $plantillas = $this->indices('cf_plantillas_legado');
        $this->assertSame('ruta_original!', collect($plantillas)->first(fn ($c) => str_starts_with($c, 'ruta_original')));
        $this->assertSame('estado', $plantillas['cf_plantillas_legado_estado_idx']);
        $this->assertSame('nombre_normalizado', $plantillas['cf_plantillas_legado_nombre_norm_idx']);
        $this->assertSame('contenido_id', $plantillas['cf_plantillas_legado_contenido_idx']);
    }

    public function test_llaves_foraneas_previstas(): void
    {
        $this->subir();

        $this->assertSame(['evento_id' => 'cf_eventos.id restrict', 'plantilla_id' => 'cf_plantillas.id restrict'], $this->llaves('cf_lotes'));
        // corrida_id (Fase 4) es RESTRICT en cada tabla histórica; NO existe en cf_emisiones ni en las tablas modernas.
        $corrida = 'cf_migraciones_corridas.id restrict';
        $this->assertSame(['contenido_id' => 'cf_plantillas_legado_contenidos.id restrict', 'corrida_id' => $corrida], $this->llaves('cf_plantillas_legado'));
        $this->assertSame(['corrida_id' => $corrida], $this->llaves('cf_plantillas_legado_contenidos'));
        $this->assertSame(['corrida_id' => $corrida, 'plantilla_legado_id' => 'cf_plantillas_legado.id restrict'], $this->llaves('cf_eventos'));
        $this->assertSame([
            'corrida_id' => $corrida,
            'evento_id' => 'cf_eventos.id restrict',
            'plantilla_legado_id' => 'cf_plantillas_legado.id restrict',
            'reemplazado_por_emision_id' => 'cf_emisiones.id restrict',
        ], $this->llaves('cf_certificados_legado'));
        $this->assertSame([
            'certificado_legado_id' => 'cf_certificados_legado.id restrict',
            'corrida_id' => $corrida,
            'emision_id' => 'cf_emisiones.id restrict',
            'participante_id' => 'cf_participantes.id set null',
        ], $this->llaves('cf_descargas'));
        $this->assertSame(['certificado_legado_id' => 'cf_certificados_legado.id restrict', 'corrida_id' => $corrida, 'participante_id' => 'cf_participantes.id restrict'], $this->llaves('cf_correos'));
        $this->assertSame(['corrida_id' => 'cf_migraciones_corridas.id restrict'], $this->llaves('cf_migraciones_map'));
        foreach (['cf_emisiones', 'cf_participantes', 'cf_lotes', 'cf_plantillas'] as $moderna) {
            $this->assertArrayNotHasKey('corrida_id', $this->llaves($moderna), "$moderna no lleva corrida_id");
        }
    }

    // ── Columnas y valores por defecto de las tablas nuevas ───────────────────

    public function test_cf_certificados_legado_arranca_en_el_estado_mas_seguro(): void
    {
        $this->subir();
        $c = $this->columnas('cf_certificados_legado');

        foreach (['plantilla_legado_id', 'tipo_documento', 'documento', 'correo', 'correo_normalizado', 'correo_estado', 'codigo_legado',
            'pdf_archivo', 'pdf_hash', 'pdf_bytes', 'materializado_at', 'grupo_duplicado', 'ultimo_error_codigo', 'reemplazado_por_emision_id'] as $nullable) {
            $this->assertTrue($c[$nullable]['nullable'], "$nullable debe ser nullable");
        }
        foreach (['evento_id', 'documento_clave', 'nombre_completo', 'snapshot_legado', 'estado', 'conciliacion_estado', 'visible_portal', 'intentos_generacion'] as $obligatoria) {
            $this->assertFalse($c[$obligatoria]['nullable'], "$obligatoria debe ser NOT NULL");
        }

        $certificado = CertificadoLegado::create([
            'evento_id' => $this->evento()->id, 'documento_clave' => '123', 'nombre_completo' => 'ANA PÉREZ', 'snapshot_legado' => ['v' => 1],
        ])->fresh();

        $this->assertSame('vigente', $certificado->estado);
        $this->assertSame('pendiente_conciliacion', $certificado->conciliacion_estado);
        $this->assertFalse($certificado->visible_portal, 'Una fila nueva NO es visible en el portal');
        $this->assertSame(0, $certificado->intentos_generacion);
        $this->assertFalse($certificado->materializado());
        $this->assertSame(['v' => 1], $certificado->snapshot_legado);
    }

    public function test_codigo_legado_admite_repetidos(): void
    {
        $this->subir();
        $evento = $this->evento();
        foreach ([1, 2] as $i) {
            CertificadoLegado::create(['evento_id' => $evento->id, 'documento_clave' => "D$i", 'nombre_completo' => "N$i", 'codigo_legado' => '5237', 'snapshot_legado' => []]);
        }

        $this->assertSame(2, CertificadoLegado::where('codigo_legado', '5237')->count());
    }

    private function entrada(string $nombre, array $extra = []): PlantillaLegado
    {
        return PlantillaLegado::create($extra + [
            'ruta_original' => 'document/certImages/'.$nombre, 'nombre_original' => $nombre, 'nombre_normalizado' => strtolower($nombre),
            'extension_original' => strtolower(pathinfo($nombre, PATHINFO_EXTENSION)), 'estado' => 'ok',
        ]);
    }

    public function test_cf_plantillas_legado_acepta_nombres_sin_contenido_y_varios_nombres_para_un_mismo_contenido(): void
    {
        $this->subir();

        // Una imagen faltante es un NOMBRE sin contenido (sin SHA); puede haber varias.
        $faltante = $this->entrada('evento sin imagen.png', ['estado' => 'faltante']);
        $this->entrada('otra sin imagen.png', ['estado' => 'faltante']);
        $this->assertNull($faltante->fresh()->contenido_id);
        $this->assertFalse($faltante->fresh()->renderizable, 'Por defecto no es renderizable');

        // 632 archivos → 624 contenidos: dos nombres distintos comparten UN contenido (un solo blob).
        $sha = str_repeat('a', 64);
        $contenido = PlantillaLegadoContenido::create(['sha256' => $sha, 'bytes' => 1234, 'ancho_px' => 3300, 'alto_px' => 2550, 'mime_real' => 'image/png']);
        $a = $this->entrada('a.png', ['contenido_id' => $contenido->id, 'renderizable' => true]);
        $b = $this->entrada('A (copia).png', ['contenido_id' => $contenido->id, 'renderizable' => true]);

        $this->assertSame($contenido->id, $a->contenido->id);
        $this->assertSame([$a->id, $b->id], $contenido->plantillas()->orderBy('id')->pluck('id')->all());
        $this->assertSame(1, PlantillaLegadoContenido::count());
        $this->assertSame(
            'credential-flow/legado/plantillas/'.$sha.'/original.png',
            $a->rutaBlobEsperada(),
            'Una sola ruta de blob por contenido, aunque haya varios nombres'
        );
    }

    public function test_el_sha256_del_contenido_y_la_ruta_original_son_unicos(): void
    {
        $this->subir();
        $sha = str_repeat('b', 64);
        PlantillaLegadoContenido::create(['sha256' => $sha, 'bytes' => 1]);

        try {
            PlantillaLegadoContenido::create(['sha256' => $sha, 'bytes' => 2]);
            $this->fail('El SHA-256 debía ser único');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->entrada('x.png');
        $this->expectException(QueryException::class);
        $this->entrada('x.png');
    }

    public function test_el_catalogo_representa_los_estados_del_renderer_y_se_relaciona_con_el_evento(): void
    {
        $this->subir();
        $this->assertSame(['ok', 'faltante', 'huerfana', 'candidata_revision', 'extension_invalida'], PlantillaLegado::ESTADOS);

        $entrada = $this->entrada('7 cuipo', ['estado' => 'extension_invalida', 'extension_original' => '7 cuipo', 'motivo_no_renderizable' => 'EXTENSION_INVALIDA']);
        $evento = $this->evento(['plantilla_legado_id' => $entrada->id]);

        $this->assertSame($entrada->id, $evento->fresh()->plantillaLegado->id);
        $this->assertSame([$evento->id], $entrada->eventos()->pluck('id')->all());
        $this->assertNull($entrada->rutaBlobEsperada(), 'Sin contenido no hay ruta de blob');
    }

    public function test_cf_eventos_guarda_solo_el_anio_sin_inventar_fecha(): void
    {
        $this->subir();

        $evento = $this->evento(['anio' => 2024])->fresh();

        $this->assertSame(2024, $evento->anio);
        $this->assertNull($evento->fecha_inicio);
        $this->assertNull($evento->fecha_fin);
        $this->assertNull($evento->fecha_texto);
        $this->assertSame('activo', $evento->estado);

        $evento->delete();
        $this->assertSoftDeleted('cf_eventos', ['id' => $evento->id]);
    }

    public function test_un_evento_puede_tener_varias_bases(): void
    {
        $this->subir();
        $evento = $this->evento();
        $plantilla = $this->crearPlantilla();

        foreach (['Sede Bogotá', 'Sede Cali'] as $nombre) {
            Lote::create(['plantilla_id' => $plantilla->id, 'evento_id' => $evento->id, 'nombre' => $nombre, 'datos_comunes' => ['evento' => 'X']]);
        }

        $this->assertCount(2, $evento->lotes);
        $this->assertSame($evento->id, $evento->lotes->first()->evento->id);
    }

    // ── cf_descargas: exactamente un origen ───────────────────────────────────

    public function test_una_descarga_exige_exactamente_una_emision_o_un_certificado_legado(): void
    {
        $this->subir();
        $certificado = CertificadoLegado::create(['evento_id' => $this->evento()->id, 'documento_clave' => '1', 'nombre_completo' => 'A', 'snapshot_legado' => []]);

        $d = Descarga::create(['certificado_legado_id' => $certificado->id, 'via' => 'portal'])->fresh();
        $this->assertNull($d->emision_id);
        $this->assertNotNull($d->descargado_at, 'descargado_at tiene valor por defecto');
        $this->assertCount(1, $certificado->descargas);

        foreach ([
            'ninguno' => ['via' => 'portal'],
            'ambos' => ['emision_id' => 1, 'certificado_legado_id' => $certificado->id, 'via' => 'portal'],
        ] as $caso => $datos) {
            try {
                Descarga::create($datos);
                $this->fail("Una descarga con $caso origen debería rechazarse");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, Descarga::count());
    }

    /** @return array<string,array{0:bool,1:bool,2:bool}> emision, legado, aceptado */
    public static function origenesDeDescarga(): array
    {
        return [
            'NULL / NULL: rechazado' => [false, false, false],
            'emisión / NULL: aceptado' => [true, false, true],
            'NULL / legado: aceptado' => [false, true, true],
            'emisión / legado: rechazado' => [true, true, false],
        ];
    }

    #[DataProvider('origenesDeDescarga')]
    public function test_los_cuatro_escenarios_de_origen_a_nivel_de_modelo(bool $conEmision, bool $conLegado, bool $aceptado): void
    {
        $this->subir();
        $plantilla = $this->crearPlantilla();
        $loteId = DB::table('cf_lotes')->insertGetId(['plantilla_id' => $plantilla->id, 'nombre' => 'L', 'datos_comunes' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $participanteId = DB::table('cf_participantes')->insertGetId(['lote_id' => $loteId, 'nombre_completo' => 'ANA', 'documento' => '1', 'documento_clave' => '1', 'created_at' => now(), 'updated_at' => now()]);
        $emisionId = DB::table('cf_emisiones')->insertGetId([
            'codigo' => str_repeat('A', 20), 'participante_id' => $participanteId, 'lote_id' => $loteId, 'plantilla_id' => $plantilla->id, 'version' => 1,
            'estado' => 'emitida', 'participante_vigente' => $participanteId, 'datos_snapshot' => '{}', 'diseno_snapshot' => '{}', 'schema_version' => 1,
            'plantilla_pdf_hash' => str_repeat('a', 64), 'generador_snapshot' => '{}', 'pdf_archivo' => 'credential-flow/emisiones/aa/x.pdf',
            'pdf_hash' => str_repeat('b', 64), 'pdf_bytes' => 10,
        ]);
        $certificado = CertificadoLegado::create(['evento_id' => $this->evento()->id, 'documento_clave' => '1', 'nombre_completo' => 'A', 'snapshot_legado' => []]);

        $datos = ['via' => 'portal']
            + ($conEmision ? ['emision_id' => $emisionId] : [])
            + ($conLegado ? ['certificado_legado_id' => $certificado->id] : []);

        if ($aceptado) {
            $this->assertNotNull(Descarga::create($datos)->id);
            $this->assertSame(1, Descarga::count());
        } else {
            $this->expectException(InvalidArgumentException::class);
            Descarga::create($datos);
        }
    }

    public function test_estados_y_vias_son_los_mismos_en_las_migraciones_los_modelos_y_los_tests(): void
    {
        // Cada columna de estado lleva en su migración un comentario «a | b | c» justo encima. Ese comentario debe coincidir
        // exactamente con las constantes del modelo (única lista de valores válidos).
        $esperado = [
            self::FASE_1[0] => ['estado' => EventoCertificacion::ESTADOS, 'origen' => EventoCertificacion::ORIGENES],
            self::FASE_1[2] => ['correo_estado' => Participante::CORREO_ESTADOS],
            self::FASE_1[3] => ['estado' => PlantillaLegado::ESTADOS],
            self::FASE_1[4] => [
                'correo_estado' => CertificadoLegado::CORREO_ESTADOS,
                'estado' => CertificadoLegado::ESTADOS,
                'conciliacion_estado' => CertificadoLegado::CONCILIACIONES,
            ],
            self::FASE_1[5] => ['via' => Descarga::VIAS],
            self::FASE_1[6] => ['estado' => Correo::ESTADOS, 'origen' => Correo::ORIGENES],
            self::FASE_1[8] => ['estado' => MigracionCorrida::ESTADOS, 'relacion' => ['principal', 'canonico', 'duplicado_identico', 'variante_conflictiva']],
        ];

        foreach ($esperado as $migracion => $columnas) {
            $codigo = file_get_contents(base_path($migracion));
            preg_match_all('#// ([a-z_]+(?: \| [a-z_]+)+)\R\s+\$table->string\(\'(\w+)\'#', $codigo, $m, PREG_SET_ORDER);
            $documentado = [];
            foreach ($m as $fila) {
                $documentado[$fila[2]] = explode(' | ', $fila[1]);
            }
            $this->assertSame(array_keys($columnas), array_keys($documentado), "$migracion: columnas con valores documentados");
            foreach ($columnas as $columna => $constantes) {
                $this->assertSame($constantes, $documentado[$columna], "$migracion: $columna no coincide con las constantes del modelo");
            }
        }

        // Valores por defecto de las migraciones = constantes del modelo.
        $this->subir();
        $legado = $this->columnas('cf_certificados_legado');
        $this->assertSame("'".CertificadoLegado::ESTADO_VIGENTE."'", $legado['estado']['default']);
        $this->assertSame("'".CertificadoLegado::CONCILIACION_PENDIENTE."'", $legado['conciliacion_estado']['default']);
        $this->assertSame("'".EventoCertificacion::ESTADO_ACTIVO."'", $this->columnas('cf_eventos')['estado']['default']);
        $this->assertSame("'".EventoCertificacion::ORIGEN_CREDENTIAL_FLOW."'", $this->columnas('cf_eventos')['origen']['default']);
    }

    public function test_la_auditoria_de_las_tablas_nuevas_sigue_el_patron_de_credential_flow(): void
    {
        $this->subir();

        // Patrón actual (cf_lotes, cf_participantes…): created_by y update_by unsignedBigInteger NULL, SIN llave foránea.
        foreach (['cf_lotes', 'cf_eventos', 'cf_plantillas_legado', 'cf_certificados_legado', 'cf_correos'] as $tabla) {
            $c = $this->columnas($tabla);
            $this->assertTrue($c['created_by']['nullable'], "$tabla.created_by");
            $this->assertTrue($c['update_by']['nullable'], "$tabla.update_by");
            $this->assertSame($c['created_by']['tipo'], $c['update_by']['tipo']);
            $this->assertSame($this->columnas('cf_lotes')['created_by']['tipo'], $c['created_by']['tipo'], "$tabla.created_by difiere del patrón");
            $this->assertArrayNotHasKey('created_by', $this->llaves($tabla));
            $this->assertArrayNotHasKey('update_by', $this->llaves($tabla));
        }
        // cf_descargas es solo de inserción: sin columnas de auditoría de usuario.
        $this->assertArrayNotHasKey('created_by', $this->columnas('cf_descargas'));
        // revocado_por sigue el patrón de cf_emisiones.revocado_por: bigint NULL, sin FK.
        $this->assertSame($this->columnas('cf_emisiones')['revocado_por'], $this->columnas('cf_certificados_legado')['revocado_por']);
        $this->assertArrayNotHasKey('revocado_por', $this->llaves('cf_certificados_legado'));
    }

    public function test_la_via_de_la_descarga_se_valida(): void
    {
        $this->subir();
        $certificado = CertificadoLegado::create(['evento_id' => $this->evento()->id, 'documento_clave' => '1', 'nombre_completo' => 'A', 'snapshot_legado' => []]);

        $this->expectException(InvalidArgumentException::class);
        Descarga::create(['certificado_legado_id' => $certificado->id, 'via' => 'otra']);
    }

    /** @return array<string,array{0:class-string<Connection>}> */
    public static function motores(): array
    {
        return ['MySQL' => [MySqlConnection::class], 'MariaDB' => [MariaDbConnection::class]];
    }

    /**
     * SQLite (las pruebas) no admite ADD CONSTRAINT, así que el CHECK se comprueba aquí compilando la migración con las
     * gramáticas de MySQL y MariaDB sin ejecutar nada; su efecto real se prueba en tests/Integracion contra MySQL.
     */
    #[DataProvider('motores')]
    public function test_la_migracion_de_descargas_emite_el_check_en_mysql_y_mariadb(string $conexionClase): void
    {
        $version = $conexionClase === MariaDbConnection::class ? '10.6.23-MariaDB-0ubuntu0.22.04.1' : '8.4.3';
        $pdo = new class($version) extends PDO
        {
            public function __construct(private string $version) {}

            public function getAttribute(int $attribute): mixed
            {
                return $attribute === PDO::ATTR_SERVER_VERSION ? $this->version : null;
            }
        };
        $conexion = new $conexionClase($pdo, 'appfyc', '', ['name' => 'pretend']);
        $conexion->useDefaultSchemaGrammar();
        Schema::swap($conexion->getSchemaBuilder());

        $m = require base_path(self::FASE_1[5]);
        $sentencias = array_column($conexion->pretend(fn () => $m->up()), 'query');

        $check = collect($sentencias)->first(fn ($q) => str_starts_with($q, 'ALTER TABLE cf_descargas ADD CONSTRAINT cf_descargas_un_origen_chk CHECK'));
        $this->assertNotNull($check, 'No se emitió el CHECK de cf_descargas');
        $this->assertStringContainsString('emision_id IS NOT NULL AND certificado_legado_id IS NULL', $check);
        $this->assertStringContainsString('emision_id IS NULL AND certificado_legado_id IS NOT NULL', $check);

        $create = collect($sentencias)->first(fn ($q) => str_starts_with($q, 'create table `cf_descargas`'));
        // Sin acciones referenciales CASCADE/SET NULL sobre las columnas del CHECK (MySQL 8 lo prohíbe).
        $this->assertSame(0, preg_match('/`(emision_id|certificado_legado_id)`.*on delete (cascade|set null)/i', (string) $create));
        // descargado_at declara DEFAULT explícito: evita el ON UPDATE implícito de explicit_defaults_for_timestamp = 0.
        $this->assertStringContainsString('`descargado_at` timestamp not null default CURRENT_TIMESTAMP', (string) $create);
    }

    #[DataProvider('motores')]
    public function test_la_migracion_de_correos_emite_los_check_y_la_collation_binaria_en_mysql_y_mariadb(string $conexionClase): void
    {
        $version = $conexionClase === MariaDbConnection::class ? '10.6.23-MariaDB-0ubuntu0.22.04.1' : '8.4.3';
        $pdo = new class($version) extends PDO
        {
            public function __construct(private string $version) {}

            public function getAttribute(int $attribute): mixed
            {
                return $attribute === PDO::ATTR_SERVER_VERSION ? $this->version : null;
            }
        };
        $conexion = new $conexionClase($pdo, 'appfyc', '', ['name' => 'pretend']);
        $conexion->useDefaultSchemaGrammar();
        Schema::swap($conexion->getSchemaBuilder());

        $m = require base_path(self::FASE_1[6]);
        $sentencias = array_column($conexion->pretend(fn () => $m->up()), 'query');
        $checks = collect($sentencias)->filter(fn ($q) => str_starts_with($q, 'ALTER TABLE cf_correos ADD CONSTRAINT'))->values();

        $this->assertCount(4, $checks);
        $this->assertStringContainsString('cf_correos_un_propietario_chk CHECK', $checks[0]);
        $this->assertStringContainsString('participante_id IS NOT NULL AND certificado_legado_id IS NULL', $checks[0]);
        $this->assertStringContainsString('participante_id IS NULL AND certificado_legado_id IS NOT NULL', $checks[0]);
        $this->assertStringContainsString("estado IN ('valido', 'invalido')", $checks[1]);
        $this->assertStringContainsString("origen IN ('credential_flow', 'legado')", $checks[2]);
        $this->assertStringContainsString("es_principal = 0 OR estado = 'valido'", $checks[3]);

        $create = (string) collect($sentencias)->first(fn ($q) => str_starts_with($q, 'create table `cf_correos`'));
        $this->assertStringContainsString('`correo_normalizado` varchar(254) collate \'utf8mb4_bin\' not null', $create);
        // Las FK se emiten en sentencias `alter table` aparte: ambas RESTRICT y ninguna CASCADE/SET NULL.
        $llaves = collect($sentencias)->filter(fn ($q) => str_contains($q, 'add constraint') && str_contains($q, 'foreign key'))->values();
        $this->assertCount(2, $llaves);
        foreach ($llaves as $llave) {
            $this->assertStringContainsString('on delete restrict', $llave);
            $this->assertSame(0, preg_match('/on delete (cascade|set null)/i', $llave));
        }
    }

    public function test_ninguna_migracion_nueva_deja_un_timestamp_not_null_sin_default(): void
    {
        foreach ([MySqlConnection::class, MariaDbConnection::class] as $clase) {
            $version = $clase === MariaDbConnection::class ? '10.6.23-MariaDB-0ubuntu0.22.04.1' : '8.4.3';
            $pdo = new class($version) extends PDO
            {
                public function __construct(private string $version) {}

                public function getAttribute(int $attribute): mixed
                {
                    return $attribute === PDO::ATTR_SERVER_VERSION ? $this->version : null;
                }
            };
            $conexion = new $clase($pdo, 'appfyc', '', ['name' => 'pretend']);
            $conexion->useDefaultSchemaGrammar();
            Schema::swap($conexion->getSchemaBuilder());

            foreach ([0, 3, 4, 5, 6] as $i) {
                $m = require base_path(self::FASE_1[$i]);
                foreach (array_column($conexion->pretend(fn () => $m->up()), 'query') as $sql) {
                    if (preg_match_all('/`[a-z_]+` timestamp\b[^,)]*/i', $sql, $columnas)) {
                        foreach ($columnas[0] as $columna) {
                            $this->assertTrue(
                                (str_contains($columna, ' null') && ! str_contains($columna, 'not null')) || str_contains(strtolower($columna), 'default'),
                                self::FASE_1[$i].": $columna quedaría con DEFAULT/ON UPDATE implícito"
                            );
                            $this->assertStringNotContainsStringIgnoringCase('on update', $columna);
                        }
                    }
                }
            }
        }
    }
}
