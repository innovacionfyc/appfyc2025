<?php

namespace Tests\Integracion;

use App\Models\Evento;
use App\Models\PerfilConferencista;
use App\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pruebas de la migración de SEM_DUPLA y de la validación de la URL de inscripción.
 *
 * Necesitan MySQL/MariaDB local (ENUM y SHOW CREATE TABLE no existen en SQLite). Solo se
 * ejecutan si se indica una base local de desarrollo o pruebas:
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_dev php artisan test tests/Integracion
 *
 * No alteran datos existentes: la migración se prueba sobre tablas propias que se eliminan al
 * terminar, y las peticiones HTTP se ejecutan dentro de una transacción que se revierte.
 */
class SemDuplaMysqlTest extends TestCase
{
    private const CONEXION = 'mysql_pruebas';

    private array $tablasCreadas = [];

    private bool $enTransaccion = false;

    private ?int $conferencistaId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $base = (string) getenv('TEST_MYSQL_DATABASE');
        $config = config('database.connections.mysql');

        if ($base === '' || ! preg_match('/_(dev|test|testing)$/', $base)
            || ! in_array($config['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
            $this->markTestSkipped('Indica TEST_MYSQL_DATABASE con una base local *_dev o *_test para ejecutar estas pruebas.');
        }

        config([
            'database.connections.'.self::CONEXION => [...$config, 'database' => $base],
            'database.default' => self::CONEXION,
        ]);
        DB::purge(self::CONEXION);
    }

    protected function tearDown(): void
    {
        if ($this->enTransaccion) {
            DB::rollBack();
        }

        foreach ($this->tablasCreadas as $tabla) {
            DB::statement("DROP TABLE IF EXISTS `{$tabla}`");
        }

        parent::tearDown();
    }

    // ── Migración: ampliación del ENUM ───────────────────────────────

    public static function definicionesDeColumna(): array
    {
        return [
            'NOT NULL con valor por defecto' => ["ENUM('SEMINARIO','JORNADA') NOT NULL DEFAULT 'JORNADA'"],
            'nullable con DEFAULT NULL' => ["ENUM('SEMINARIO','JORNADA') NULL DEFAULT NULL"],
            'NOT NULL sin valor por defecto' => ["ENUM('SEMINARIO','JORNADA') NOT NULL"],
            'collation distinta de la tabla y comentario' => [
                "ENUM('SEMINARIO','JORNADA') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'SEMINARIO' COMMENT 'tipo del evento'",
            ],
        ];
    }

    #[DataProvider('definicionesDeColumna')]
    public function test_la_ampliacion_conserva_la_definicion_de_la_columna(string $definicion): void
    {
        $tabla = $this->crearTablaEventos($definicion);
        DB::table($tabla)->insert([
            ['tipo_evento' => 'SEMINARIO', 'precio_seminario' => 500000],
            ['tipo_evento' => 'JORNADA', 'precio_seminario' => null],
        ]);
        $antes = $this->atributos($tabla);
        $filasAntes = DB::table($tabla)->orderBy('id')->get()->toArray();

        $this->migracion($tabla)->up();

        $this->assertSame(['SEMINARIO', 'JORNADA', 'SEM_DUPLA'], $this->valoresEnum($tabla));
        $this->assertEquals($antes, $this->atributos($tabla), 'Nulabilidad, valor por defecto, collation o comentario cambiaron.');
        $this->assertTrue(Schema::hasColumns($tabla, ['precio_seminario_virtual', 'precio_seminario_streaming']));

        $filas = DB::table($tabla)->orderBy('id')->get();
        foreach ($filasAntes as $i => $fila) {
            $this->assertSame($fila->tipo_evento, $filas[$i]->tipo_evento);
            $this->assertSame($fila->precio_seminario, $filas[$i]->precio_seminario);
            $this->assertSame('0.00', $filas[$i]->precio_seminario_virtual);
            $this->assertSame('0.00', $filas[$i]->precio_seminario_streaming);
        }
    }

    public function test_la_ampliacion_es_reejecutable_y_no_duplica_el_valor(): void
    {
        $tabla = $this->crearTablaEventos("ENUM('SEMINARIO','JORNADA') NOT NULL DEFAULT 'JORNADA'");
        $migracion = $this->migracion($tabla);

        $migracion->up();
        $this->assertNull($migracion->planAmpliacion()['sentencia']);
        $migracion->up();

        $this->assertSame(['SEMINARIO', 'JORNADA', 'SEM_DUPLA'], $this->valoresEnum($tabla));
    }

    public function test_el_plan_de_solo_lectura_muestra_la_sentencia_sin_ejecutarla(): void
    {
        $tabla = $this->crearTablaEventos("ENUM('SEMINARIO','JORNADA') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL COMMENT 'c'");

        $plan = $this->migracion($tabla)->planAmpliacion();

        $this->assertStringContainsString("enum('SEMINARIO','JORNADA','SEM_DUPLA')", $plan['sentencia']);
        $this->assertStringContainsString('utf8mb4_bin', $plan['sentencia']);
        $this->assertStringContainsString("COMMENT 'c'", $plan['sentencia']);
        $this->assertSame(['SEMINARIO', 'JORNADA'], $this->valoresEnum($tabla), 'El plan no debe modificar la tabla.');
    }

    // ── Migración: down() ────────────────────────────────────────────

    public function test_down_no_elimina_columnas_con_importes_aunque_no_haya_eventos_sem_dupla(): void
    {
        $tabla = $this->crearTablaEventos("ENUM('SEMINARIO','JORNADA') NOT NULL DEFAULT 'JORNADA'");
        $migracion = $this->migracion($tabla);
        $migracion->up();
        DB::table($tabla)->insert(['tipo_evento' => 'SEMINARIO', 'precio_seminario_streaming' => 300000]);

        try {
            $migracion->down();
            $this->fail('down() debía negarse a revertir.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('importe en precio_seminario_streaming', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumns($tabla, ['precio_seminario_virtual', 'precio_seminario_streaming']));
        $this->assertSame('300000.00', DB::table($tabla)->value('precio_seminario_streaming'));
        $this->assertContains('SEM_DUPLA', $this->valoresEnum($tabla));
    }

    public function test_down_no_revierte_si_hay_eventos_sem_dupla_en_la_papelera(): void
    {
        $tabla = $this->crearTablaEventos("ENUM('SEMINARIO','JORNADA') NOT NULL DEFAULT 'JORNADA'");
        $migracion = $this->migracion($tabla);
        $migracion->up();
        DB::table($tabla)->insert(['tipo_evento' => 'SEM_DUPLA', 'deleted_at' => now()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tipo_evento = SEM_DUPLA');

        $migracion->down();
    }

    public function test_down_revierte_sin_perder_datos_y_conserva_la_definicion(): void
    {
        $tabla = $this->crearTablaEventos("ENUM('SEMINARIO','JORNADA') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL COMMENT 'c'");
        $migracion = $this->migracion($tabla);
        $antes = $this->atributos($tabla);
        $migracion->up();
        DB::table($tabla)->insert([
            ['tipo_evento' => 'SEMINARIO', 'precio_seminario' => 500000, 'precio_seminario_virtual' => 0, 'precio_seminario_streaming' => null],
        ]);

        $migracion->down();

        $this->assertSame(['SEMINARIO', 'JORNADA'], $this->valoresEnum($tabla));
        $this->assertEquals($antes, $this->atributos($tabla));
        $this->assertFalse(Schema::hasColumn($tabla, 'precio_seminario_virtual'));
        $this->assertFalse(Schema::hasColumn($tabla, 'precio_seminario_streaming'));
        $this->assertSame('500000.00', DB::table($tabla)->value('precio_seminario'));
    }

    // ── Validación de la URL de inscripción ──────────────────────────

    public function test_editar_borrando_la_url_de_inscripcion_da_error_de_validacion(): void
    {
        $this->iniciarTransaccionHttp();
        $evento = $this->crearEventoPorHttp('SEMINARIO', ['precio_seminario' => 500000, 'modalidad' => 'Presencial', 'ubicacion' => 'Bogotá']);

        $respuesta = $this->editarPorHttp($evento, 'SEMINARIO', [
            'precio_seminario' => 500000, 'modalidad' => 'Presencial', 'ubicacion' => 'Bogotá',
            'url_formulario_inscripcion' => '',
        ]);

        $respuesta->assertRedirect('/admin/eventos/data');
        $respuesta->assertSessionHasErrors(['url_formulario_inscripcion' => 'Ingresa la URL del formulario de inscripción.']);
        $respuesta->assertSessionDoesntHaveErrors('general');
        $this->assertSame('https://example.com/inscripcion-prueba', $evento->fresh()->url_formulario_inscripcion);
    }

    public function test_editar_con_url_de_inscripcion_invalida_da_error_de_validacion(): void
    {
        $this->iniciarTransaccionHttp();
        $evento = $this->crearEventoPorHttp('JORNADA', ['precio_jornada' => 200000]);

        $this->editarPorHttp($evento, 'JORNADA', ['precio_jornada' => 200000, 'url_formulario_inscripcion' => 'no-es-una-url'])
            ->assertSessionHasErrors(['url_formulario_inscripcion' => 'La URL del formulario de inscripción no es válida.']);
    }

    public static function tiposExistentes(): array
    {
        return [
            'SEMINARIO' => ['SEMINARIO', ['precio_seminario' => 500000, 'modalidad' => 'Presencial', 'ubicacion' => 'Bogotá']],
            'JORNADA' => ['JORNADA', ['precio_jornada' => 200000]],
            'CNG_DUPLA' => ['CNG_DUPLA', ['precio_cng' => 900000, 'precio_cng_virtual' => 600000, 'modalidad' => 'Híbrido']],
            'MOD_DUPLA' => ['MOD_DUPLA', ['precio_modulo' => 300000, 'precio_modulo_virtual' => 250000]],
            'SEM_DUPLA' => ['SEM_DUPLA', ['precio_seminario_virtual' => 350000, 'precio_seminario_streaming' => 250000]],
        ];
    }

    #[DataProvider('tiposExistentes')]
    public function test_crear_y_editar_cada_tipo_sigue_funcionando(string $tipo, array $precios): void
    {
        $this->iniciarTransaccionHttp();

        $evento = $this->crearEventoPorHttp($tipo, $precios);
        $this->assertSame($tipo, $evento->tipo_evento);
        foreach ($precios as $campo => $valor) {
            if (str_starts_with($campo, 'precio_')) {
                $this->assertEquals($valor, (float) $evento->{$campo}, "{$campo} al crear");
            }
        }

        $editados = array_map(fn ($v) => is_int($v) ? $v + 1000 : $v, $precios);
        $this->editarPorHttp($evento, $tipo, [...$editados, 'url_formulario_inscripcion' => 'https://example.com/inscripcion-editada'])
            ->assertSessionHasNoErrors();

        $evento->refresh();
        $this->assertSame('https://example.com/inscripcion-editada', $evento->url_formulario_inscripcion);
        foreach ($editados as $campo => $valor) {
            if (str_starts_with($campo, 'precio_')) {
                $this->assertEquals($valor, (float) $evento->{$campo}, "{$campo} al editar");
            }
        }
    }

    // ── Utilidades ───────────────────────────────────────────────────

    private function crearTablaEventos(string $definicionTipo): string
    {
        $tabla = 'tmp_prueba_semdupla_'.Str::lower(Str::random(8));
        $this->tablasCreadas[] = $tabla;

        DB::statement("CREATE TABLE `{$tabla}` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `precio_seminario` decimal(10,2) NULL,
            `tipo_evento` {$definicionTipo},
            `deleted_at` timestamp NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        return $tabla;
    }

    private function migracion(string $tabla): object
    {
        $migracion = require database_path('migrations/2026_09_28_120000_add_sem_dupla_to_eventos_table.php');
        $migracion->tabla = $tabla;

        return $migracion;
    }

    private function valoresEnum(string $tabla): array
    {
        $tipo = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())->where('table_name', $tabla)->where('column_name', 'tipo_evento')
            ->value('column_type');
        preg_match_all("/'((?:[^']|'')*)'/", $tipo, $valores);

        return $valores[1];
    }

    private function atributos(string $tabla): array
    {
        return (array) DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())->where('table_name', $tabla)->where('column_name', 'tipo_evento')
            ->first(['IS_NULLABLE', 'COLUMN_DEFAULT', 'CHARACTER_SET_NAME', 'COLLATION_NAME', 'COLUMN_COMMENT']);
    }

    private function iniciarTransaccionHttp(): void
    {
        // routes/web.php carga los archivos con require_once: a partir de la segunda aplicación
        // creada en el mismo proceso de pruebas las rutas de eventos ya no se registran.
        if (! Route::has('eventos.store')) {
            Route::middleware('web')->group(base_path('routes/web/Eventos/evento.php'));
            Route::getRoutes()->refreshNameLookups();
        }

        Storage::fake('public');
        DB::beginTransaction();
        $this->enTransaccion = true;

        $admin = Usuario::whereHas('perfilOrganizador.rol', fn ($q) => $q->where('slug', 'super-admin'))->first();
        if ($admin === null) {
            $this->markTestSkipped('La base no tiene un usuario super-admin para las pruebas HTTP.');
        }
        $this->actingAs($admin);
    }

    private function datosEvento(string $tipo, array $cambios): array
    {
        $this->conferencistaId ??= PerfilConferencista::create([
            'primer_nombre' => 'PRUEBA', 'primer_apellido' => 'INTEGRACION', 'telefono' => '0000000000',
            'correo' => 'prueba.integracion.'.Str::random(6).'@appfyc2025.test', 'areas_encargadas' => [1], 'url_hv' => 'prueba',
        ])->id;

        $precios = array_fill_keys(['precio_jornada', 'precio_seminario', 'precio_seminario_virtual', 'precio_seminario_streaming',
            'precio_modulo', 'precio_modulo_virtual', 'precio_cng', 'precio_cng_virtual', 'precio_curso_intensivo_hibrido',
            'precio_curso_intensivo_virtual', 'precio_diplomado_hibrido', 'precio_diplomado_virtual'], 0);

        return [
            ...$precios,
            'area_formacion_id' => DB::table('areas_formacion')->value('id'),
            'organizador_id' => auth()->id(),
            'tipo_evento' => $tipo,
            'titulo' => "PRUEBA INTEGRACION {$tipo}",
            'subtitulo' => null,
            'modo_evento' => 'Seminario de actualización',
            'fecha_hora_inicio' => '2026-11-10 08:00:00',
            'fecha_hora_fin' => '2026-11-10 17:00:00',
            'modalidad' => 'Virtual',
            'ubicacion' => null,
            'tiene_oferta_valor' => false,
            'color_hex_secundario' => '#4F46E5',
            'texto_dinamico' => null,
            'url_formulario_inscripcion' => 'https://example.com/inscripcion-prueba',
            'conferencistas' => [$this->conferencistaId],
            'contenido_tematico' => [['tema' => 'Tema de prueba', 'subtemas' => ['Subtema']]],
            ...$cambios,
        ];
    }

    private function crearEventoPorHttp(string $tipo, array $precios): Evento
    {
        $datos = $this->datosEvento($tipo, $precios);
        $datos['url_folleto'] = UploadedFile::fake()->create('folleto.pdf', 10, 'application/pdf');

        $this->from('/admin/eventos/data')->post(route('eventos.store'), $datos)->assertSessionHasNoErrors();

        return Evento::where('titulo', "PRUEBA INTEGRACION {$tipo}")->latest('id')->firstOrFail();
    }

    private function editarPorHttp(Evento $evento, string $tipo, array $cambios)
    {
        return $this->from('/admin/eventos/data')
            ->put(route('eventos.update', $evento->id), $this->datosEvento($tipo, $cambios));
    }
}
