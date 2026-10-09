<?php

namespace Tests\Feature\CredentialFlow\StagingEv;

use App\Support\CredentialFlow\StagingEv\FuenteArreglos;
use App\Support\CredentialFlow\StagingEv\FuenteConexion;
use App\Support\CredentialFlow\StagingEv\ImportadorSnapshot;
use App\Support\CredentialFlow\StagingEv\Normalizador;
use App\Support\CredentialFlow\StagingEv\ReporteConciliacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ImportadorSnapshotTest extends StagingEvTestCase
{
    public function test_primera_carga_inserta_todo_conserva_originales_y_marca_los_snapshots(): void
    {
        $r = $this->cargar('s1');

        $this->assertFalse($r->yaCargado);
        $this->assertSame(['nuevas' => 6, 'iguales' => 0, 'cambiadas' => 0, 'ausentes' => 0, 'origen' => 6], $r->entidades['evento']);
        $this->assertSame(15, $r->entidades['participante']['nuevas']);
        $this->assertSame(3, $r->entidades['token']['nuevas']);
        $this->assertSame(5, $r->entidades['descargas']['nuevas']);
        $this->assertSame(3, $r->entidades['encuesta']['nuevas']);

        $p = DB::table('stg_ev_participante')->where('old_id', 1)->first();
        $this->assertSame('nueva', $p->estado_fila);
        $this->assertSame($r->snapshotId, (int) $p->primera_snapshot_id);
        $this->assertSame($r->snapshotId, (int) $p->ultima_snapshot_id);
        $this->assertSame(64, strlen($p->hash_fila));
        // Valor ORIGINAL y valor normalizado conviven.
        $this->assertSame(self::CORREO, $p->correo_original);
        $this->assertSame(self::CORREO, $p->correo_normalizado);
        $this->assertSame(self::NOMBRE, $p->nombre_original);

        $s = DB::table('stg_ev_snapshots')->first();
        $this->assertSame('prueba.sql.gz', $s->dump_archivo);
        $this->assertSame(hash('sha256', 's1'), $s->dump_sha256);
        $this->assertSame('2026-10-05 13:48:00', $s->tomado_at);
        $this->assertSame('MariaDB 10.6.23', $s->servidor_origen);
        $this->assertSame(Normalizador::VERSION, (int) $s->norm_version);
        $this->assertSame(15, json_decode($s->conteos, true)['origen']['participante']);
        $this->assertSame(15, json_decode($s->conteos, true)['staging']['participante']);
    }

    public function test_el_original_se_conserva_aunque_la_normalizacion_lo_cambie(): void
    {
        $datos = $this->conCambio($this->datos(), 'participante', 2, ['nombre' => '  jose   Muñoz ', 'correo' => '  CORREO@Example.TEST ', 'tipo_documento' => ' c.c. ', 'documento' => ' 55.5 ']);
        $this->cargar('s1', $datos);

        $p = DB::table('stg_ev_participante')->where('old_id', 2)->first();
        $this->assertSame('  jose   Muñoz ', $p->nombre_original);
        $this->assertSame('JOSE MUÑOZ', $p->nombre_normalizado);
        $this->assertSame('  CORREO@Example.TEST ', $p->correo_original);
        $this->assertSame('correo@example.test', $p->correo_normalizado);
        $this->assertSame(' c.c. ', $p->tipo_documento_original);
        $this->assertSame('CC', $p->tipo_documento);
        $this->assertSame(' 55.5 ', $p->documento_original);
        $this->assertSame('555', $p->documento_clave);
    }

    public function test_segunda_carga_identica_marca_igual_y_no_duplica(): void
    {
        $a = $this->cargar('s1');
        $b = $this->cargar('s2');

        foreach (['evento', 'participante', 'token', 'descargas', 'encuesta', 'preguntas', 'opciones'] as $e) {
            $this->assertSame(0, $b->entidades[$e]['nuevas'], $e);
            $this->assertSame(0, $b->entidades[$e]['cambiadas'], $e);
            $this->assertSame($b->entidades[$e]['origen'], $b->entidades[$e]['iguales'], $e);
        }
        $this->assertSame(15, DB::table('stg_ev_participante')->count());
        $this->assertSame(0, DB::table('stg_ev_historial')->count());
        $p = DB::table('stg_ev_participante')->where('old_id', 1)->first();
        $this->assertSame('igual', $p->estado_fila);
        $this->assertSame($a->snapshotId, (int) $p->primera_snapshot_id);
        $this->assertSame($b->snapshotId, (int) $p->ultima_snapshot_id);
    }

    public function test_fila_cambiada_guarda_la_version_anterior_en_el_historial(): void
    {
        $a = $this->cargar('s1');
        $hashAntes = DB::table('stg_ev_participante')->where('old_id', 3)->value('hash_fila');
        $datos = $this->conCambio($this->datos(), 'participante', 3, ['correo' => 'carla@example.test']);
        $b = $this->cargar('s2', $datos);

        $this->assertSame(1, $b->entidades['participante']['cambiadas']);
        $p = DB::table('stg_ev_participante')->where('old_id', 3)->first();
        $this->assertSame('cambiada', $p->estado_fila);
        $this->assertSame('carla@example.test', $p->correo_original);
        $this->assertSame('valido', $p->correo_estado);
        $this->assertNotSame($hashAntes, $p->hash_fila);
        $this->assertSame($a->snapshotId, (int) $p->primera_snapshot_id);
        $this->assertSame($b->snapshotId, (int) $p->ultima_snapshot_id);

        $h = DB::table('stg_ev_historial')->first();
        $this->assertSame('participante', $h->entidad);
        $this->assertSame(3, (int) $h->old_id);
        $this->assertSame($hashAntes, $h->hash_anterior);
        $this->assertSame($p->hash_fila, $h->hash_nuevo);
        $this->assertSame($a->snapshotId, (int) $h->snapshot_anterior_id);
        $this->assertSame($b->snapshotId, (int) $h->snapshot_id);
        $anterior = json_decode($h->datos_anteriores, true);
        $this->assertNull($anterior['correo'], 'El historial conserva el valor anterior (aquí, sin correo)');
        $this->assertSame('CARLA TRES', $anterior['nombre']);

        // Una tercera carga igual a la segunda ya no la marca «cambiada» ni añade historial.
        $c = $this->cargar('s3', $datos);
        $this->assertSame(0, $c->entidades['participante']['cambiadas']);
        $this->assertSame('igual', DB::table('stg_ev_participante')->where('old_id', 3)->value('estado_fila'));
        $this->assertSame(1, DB::table('stg_ev_historial')->count());
    }

    public function test_fila_ausente_no_se_borra_y_puede_reaparecer(): void
    {
        $this->cargar('s1');
        $this->cargar('s2', $this->sinFila($this->datos(), 'participante', 5));

        $p = DB::table('stg_ev_participante')->where('old_id', 5)->first();
        $this->assertNotNull($p, 'Una fila ausente NO se borra');
        $this->assertSame('ausente_en_origen', $p->estado_fila);
        $this->assertSame(15, DB::table('stg_ev_participante')->count());
        $this->assertSame(14, json_decode(DB::table('stg_ev_snapshots')->orderByDesc('id')->value('conteos'), true)['staging']['participante'], 'Los conteos vigentes excluyen las ausentes');

        $r = $this->cargar('s3');
        $this->assertSame(0, $r->entidades['participante']['nuevas']);
        $this->assertSame('igual', DB::table('stg_ev_participante')->where('old_id', 5)->value('estado_fila'), 'Reaparece con el mismo hash → igual');
        $this->assertSame(0, $r->entidades['participante']['ausentes'], 'En la tercera carga ya no falta ninguna');
    }

    public function test_fila_nueva_en_una_carga_posterior(): void
    {
        $a = $this->cargar('s1');
        $datos = $this->datos();
        $datos['participante'][] = ['id' => 99, 'tipo_documento' => 'CC', 'documento' => '3000001', 'nombre' => 'LUIS NUEVO', 'correo' => null, 'id_evento' => 1, 'num_verificacion' => null];
        $b = $this->cargar('s2', $datos);

        $this->assertSame(1, $b->entidades['participante']['nuevas']);
        $p = DB::table('stg_ev_participante')->where('old_id', 99)->first();
        $this->assertSame('nueva', $p->estado_fila);
        $this->assertSame($b->snapshotId, (int) $p->primera_snapshot_id);
        $this->assertNotSame($a->snapshotId, (int) $p->primera_snapshot_id);
    }

    public function test_cargar_dos_veces_el_mismo_dump_no_hace_nada(): void
    {
        $a = $this->cargar('s1', null, null, 'dump-sha');
        $filas = DB::table('stg_ev_participante')->count();
        $b = $this->cargar('otra-etiqueta', null, null, 'dump-sha');

        $this->assertTrue($b->yaCargado);
        $this->assertSame($a->snapshotId, $b->snapshotId);
        $this->assertSame(1, DB::table('stg_ev_snapshots')->count());
        $this->assertSame($filas, DB::table('stg_ev_participante')->count());
        $this->assertSame(0, DB::table('stg_ev_participante')->where('estado_fila', 'igual')->count());
    }

    public function test_la_etiqueta_del_snapshot_es_unica(): void
    {
        $this->cargar('s1');

        $this->expectException(RuntimeException::class);
        $this->cargar('s1', null, null, 'otro-sha');
    }

    public function test_una_clave_repetida_en_el_origen_se_rechaza_y_no_deja_nada(): void
    {
        $datos = $this->datos();
        $datos['participante'][] = $datos['participante'][0];

        try {
            $this->cargar('s1', $datos);
            $this->fail('Debió rechazar el id repetido');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('repetida', $e->getMessage());
        }
        $this->assertSame(0, DB::table('stg_ev_snapshots')->count(), 'La carga es atómica');
        $this->assertSame(0, DB::table('stg_ev_participante')->count());
    }

    public function test_el_token_nunca_se_guarda_en_claro(): void
    {
        $this->cargar('s1');

        $t = DB::table('stg_ev_token')->where('old_id', 1)->first();
        $this->assertSame(hash('sha256', self::TOKEN), $t->codigo_sha256);
        $this->assertSame(strlen(self::TOKEN), (int) $t->codigo_longitud);
        $this->assertSame('NO_MIGRAR', $t->destino_futuro);
        foreach (['stg_ev_token', 'stg_ev_historial', 'stg_ev_snapshots'] as $tabla) {
            $this->assertStringNotContainsString('tokensecreto', json_encode(DB::table($tabla)->get()), "$tabla no debe contener el token");
        }
    }

    public function test_una_fecha_cero_de_mysql_se_guarda_como_nula_pero_el_hash_es_del_origen(): void
    {
        $this->cargar('s1');

        $t = DB::table('stg_ev_token')->where('old_id', 3)->first();
        $this->assertNull($t->fecha_creacion);
        $this->assertSame(Normalizador::hashFila(['id' => 3, 'codigo' => 'tokensecreto-bbb', 'fecha_creacion' => '0000-00-00 00:00:00', 'id_participante' => 999]), $t->hash_fila);
    }

    public function test_el_hash_de_fila_es_estable_entre_cargas_desde_cero(): void
    {
        $this->cargar('s1');
        $antes = DB::table('stg_ev_participante')->orderBy('old_id')->pluck('hash_fila', 'old_id')->all();

        $this->artisan('credential-flow:staging-ev:limpiar', ['--confirmar' => true])->assertExitCode(0);
        $this->assertSame(0, DB::table('stg_ev_participante')->count());
        $this->cargar('s1-recarga');

        $this->assertSame($antes, DB::table('stg_ev_participante')->orderBy('old_id')->pluck('hash_fila', 'old_id')->all());
    }

    public function test_limpiar_exige_confirmacion_y_la_recarga_da_el_mismo_reporte(): void
    {
        $this->cargar('s1');
        $reporte1 = $this->reporteSinSnapshot();

        $this->artisan('credential-flow:staging-ev:limpiar')->assertExitCode(1);
        $this->assertSame(15, DB::table('stg_ev_participante')->count(), 'Sin --confirmar no se borra nada');

        $this->artisan('credential-flow:staging-ev:limpiar', ['--confirmar' => true])->assertExitCode(0);
        foreach (['stg_ev_snapshots', 'stg_ev_evento', 'stg_ev_participante', 'stg_ev_duplicados', 'stg_ev_historial', 'stg_ev_imagenes'] as $t) {
            $this->assertSame(0, DB::table($t)->count(), $t);
        }
        $this->cargar('s1-otra-vez');

        $this->assertSame($reporte1, $this->reporteSinSnapshot());
    }

    public function test_una_referencia_de_imagen_larga_sin_extension_real_cabe_en_el_staging(): void
    {
        // Caso real: el nombre quedó cortado y «lo que sigue al último punto» es casi todo el nombre (FPDF lo leería como extensión).
        $referencia = 'ABR 2025 (VI) JOR. ATENCIÓN AL ESTUDIANTE Y SERVICIO AL CIUDADANO '.str_repeat('X', 160);
        $datos = $this->conCambio($this->datos(), 'evento', 1, ['imagen_certificado' => $referencia]);
        $this->cargar('s1', $datos);

        $e = DB::table('stg_ev_evento')->where('old_id', 1)->first();
        $this->assertSame($referencia, $e->imagen_original);
        $this->assertSame(strtolower(substr($referencia, (int) strrpos($referencia, '.') + 1)), $e->imagen_extension);
        $this->assertGreaterThan(100, strlen($e->imagen_extension));
    }

    public function test_el_rollback_elimina_el_staging_y_se_puede_volver_a_crear_y_cargar(): void
    {
        $this->cargar('s1');
        $reporte1 = $this->reporteSinSnapshot();

        $this->artisan('migrate:rollback', ['--path' => 'database/staging/ev', '--force' => true])->assertExitCode(0);
        foreach (['stg_ev_snapshots', 'stg_ev_evento', 'stg_ev_participante', 'stg_ev_token', 'stg_ev_descargas', 'stg_ev_encuesta', 'stg_ev_preguntas', 'stg_ev_opciones', 'stg_ev_imagenes', 'stg_ev_historial', 'stg_ev_duplicados'] as $tabla) {
            $this->assertFalse(Schema::hasTable($tabla), "$tabla sigue existiendo");
        }

        $this->artisan('migrate', ['--path' => 'database/staging/ev', '--force' => true])->assertExitCode(0);
        $this->cargar('s1');

        $this->assertSame($reporte1, $this->reporteSinSnapshot());
    }

    /** @return array<string,mixed> el reporte sin lo que identifica al snapshot (etiqueta/ids): lo demás debe ser idéntico */
    private function reporteSinSnapshot(): array
    {
        $r = (new ReporteConciliacion)->generar();
        unset($r['snapshot']);

        return $r;
    }

    public function test_lee_desde_una_conexion_de_base_de_datos_igual_que_desde_arreglos(): void
    {
        config(['database.connections.origen_prueba' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        $origen = DB::connection('origen_prueba');
        $schema = $origen->getSchemaBuilder();
        $schema->create('evento', fn ($t) => [$t->integer('id')->primary(), $t->string('nombre', 250), $t->string('imagen_certificado', 250)]);
        $schema->create('participante', function ($t) {
            $t->integer('id')->primary();
            $t->string('tipo_documento', 20)->nullable();
            $t->string('documento', 45)->nullable();
            $t->string('nombre', 250)->nullable();
            $t->string('correo', 200)->nullable();
            $t->integer('id_evento');
            $t->integer('num_verificacion')->nullable();
        });
        $schema->create('token', function ($t) {
            $t->integer('id')->primary();
            $t->text('codigo')->nullable();
            $t->dateTime('fecha_creacion')->nullable();
            $t->integer('id_participante')->nullable();
        });
        $schema->create('descargas', function ($t) {
            $t->integer('id')->primary();
            $t->dateTime('fecha')->nullable();
            $t->integer('id_evento')->nullable();
            $t->integer('id_participante')->nullable();
        });
        $schema->create('encuesta', function ($t) {
            $t->integer('id')->primary();
            foreach (range(1, 9) as $i) {
                $t->text("pregunta{$i}")->nullable();
            }
            $t->text('justificacion_pregunta1')->nullable();
            $t->integer('id_evento')->nullable();
            $t->string('documento_participante', 45)->nullable();
            $t->dateTime('fecha')->nullable();
        });
        $schema->create('preguntas_encuesta', fn ($t) => [$t->integer('id')->primary(), $t->text('texto')->nullable(), $t->string('tipo_respuesta', 45)->nullable(), $t->integer('num_opciones')->nullable()]);
        $schema->create('opciones_respuesta', fn ($t) => [$t->integer('id')->primary(), $t->string('opcion', 250)->nullable(), $t->integer('id_pregunta')->nullable()]);
        foreach ($this->datos() as $tabla => $filas) {
            foreach ($filas as $fila) {
                $origen->table($tabla)->insert($fila);
            }
        }

        $fuente = new FuenteConexion($origen);
        $this->assertSame(64, strlen((string) $fuente->esquemaSha256()));
        $this->assertSame(15, $fuente->conteos()['participante']);

        $r = (new ImportadorSnapshot)->cargar($this->info('desde-conexion'), $fuente);
        $this->assertSame(15, $r->entidades['participante']['nuevas']);
        $conArreglos = DB::table('stg_ev_participante')->orderBy('old_id')->pluck('hash_fila', 'old_id')->all();

        $this->artisan('credential-flow:staging-ev:limpiar', ['--confirmar' => true])->assertExitCode(0);
        (new ImportadorSnapshot)->cargar($this->info('desde-arreglos'), new FuenteArreglos($this->datos()));
        $this->assertSame($conArreglos, DB::table('stg_ev_participante')->orderBy('old_id')->pluck('hash_fila', 'old_id')->all(), 'Mismo hash leyendo de una base o de arreglos');
    }

    public function test_las_tablas_finales_no_se_tocan(): void
    {
        $antes = collect(Schema::getTableListing())->reject(fn ($t) => str_starts_with($t, 'stg_ev_') || $t === 'migrations')->values()->all();
        $this->cargar('s1');

        $despues = collect(Schema::getTableListing())->reject(fn ($t) => str_starts_with($t, 'stg_ev_') || $t === 'migrations')->values()->all();
        $this->assertSame($antes, $despues);
        $this->assertFalse(Schema::hasTable('cf_eventos'), 'El staging no crea ni usa cf_*');
    }
}
