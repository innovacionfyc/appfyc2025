<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\ConciliacionEvento;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;

/** Esquema, modelos, relaciones y garantías (UNIQUE, FK RESTRICT, solo-añadir) de las tres tablas de conciliación (Fase 10A). */
class ConciliacionesModelosTest extends ConciliacionesTestCase
{
    private function nuevoCaso(array $extra = []): Conciliacion
    {
        return Conciliacion::create($extra + ['tipo' => Conciliacion::TIPO_REVISION_DOCUMENTO, 'referencia_tipo' => 'certificado', 'referencia_clave' => 'x1', 'clave_idempotencia' => 'revision_documento:certificado:x1']);
    }

    // ── Migración ───────────────────────────────────────────────────────────────────────────────────────

    public function test_la_migracion_crea_las_tres_tablas_con_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasColumns('cf_conciliaciones', ['id', 'tipo', 'estado', 'evento_id', 'referencia_tipo', 'referencia_clave', 'motivo_origen', 'resolucion', 'resuelto_por', 'resuelto_at', 'clave_idempotencia', 'created_at', 'updated_at']));
        $this->assertTrue(Schema::hasColumns('cf_conciliaciones_certificados', ['id', 'conciliacion_id', 'certificado_legado_id', 'rol', 'created_at', 'updated_at']));
        $this->assertTrue(Schema::hasColumns('cf_conciliaciones_eventos', ['id', 'conciliacion_id', 'accion', 'estado_anterior', 'estado_nuevo', 'motivo', 'evidencia', 'actor_id', 'created_at']));
        // La bitácora no tiene updated_at: un evento nunca se modifica.
        $this->assertFalse(Schema::hasColumn('cf_conciliaciones_eventos', 'updated_at'));
    }

    public function test_la_migracion_no_altera_las_tablas_historicas(): void
    {
        foreach (['cf_certificados_legado', 'cf_correos', 'cf_descargas', 'cf_plantillas_legado', 'cf_eventos'] as $tabla) {
            $this->assertFalse(Schema::hasColumn($tabla, 'conciliacion_id'), $tabla);
        }
    }

    public function test_la_migracion_se_puede_revertir_y_volver_a_aplicar(): void
    {
        $ruta = 'database/migrations/2026_10_12_100000_cf_conciliaciones_tables.php';
        (require base_path($ruta))->down();
        $this->assertFalse(Schema::hasTable('cf_conciliaciones'));
        $this->assertFalse(Schema::hasTable('cf_conciliaciones_certificados'));
        $this->assertFalse(Schema::hasTable('cf_conciliaciones_eventos'));
        $this->assertTrue(Schema::hasTable('cf_certificados_legado'));

        (require base_path($ruta))->up();
        $this->assertTrue(Schema::hasTable('cf_conciliaciones_eventos'));
    }

    // ── Modelos y relaciones ────────────────────────────────────────────────────────────────────────────

    public function test_el_estado_por_defecto_es_abierto_y_las_relaciones_funcionan(): void
    {
        $this->detectar();
        $caso = $this->caso(Conciliacion::TIPO_CONFLICTO_VARIANTES);

        $this->assertSame(Conciliacion::ABIERTO, $caso->estado);
        $this->assertCount(2, $caso->certificados);
        $this->assertInstanceOf(CertificadoLegado::class, $caso->certificados->first());
        $this->assertSame('variante', $caso->certificados->first()->pivot->rol);
        $this->assertCount(1, $caso->eventos);
        $this->assertSame(ConciliacionEvento::DETECTADO, $caso->eventos->first()->accion);
        $this->assertNotNull($caso->evento);
        $this->assertSame(Conciliacion::ABIERTO, $this->nuevoCaso()->estado);
    }

    public function test_un_tipo_o_estado_invalido_se_rechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->nuevoCaso(['tipo' => 'inventado']);
    }

    public function test_un_estado_invalido_se_rechaza(): void
    {
        $caso = $this->nuevoCaso();
        $this->expectException(InvalidArgumentException::class);
        $caso->update(['estado' => 'inventado']);
    }

    public function test_los_estados_validos_se_pueden_asignar(): void
    {
        $caso = $this->nuevoCaso();
        foreach (Conciliacion::ESTADOS as $estado) {
            $caso->update(['estado' => $estado]);
            $this->assertSame($estado, $caso->fresh()->estado);
        }
    }

    // ── Append-only y borrado ───────────────────────────────────────────────────────────────────────────

    public function test_un_caso_no_se_puede_eliminar_desde_la_aplicacion(): void
    {
        $caso = $this->nuevoCaso();
        foreach ([fn () => $caso->delete(), fn () => Conciliacion::query()->delete(), fn () => Conciliacion::where('id', $caso->id)->delete()] as $borrar) {
            try {
                $borrar();
                $this->fail('Debió negarse.');
            } catch (LogicException) {
            }
        }
        $this->assertNotNull(Conciliacion::find($caso->id));
    }

    public function test_los_eventos_son_de_solo_anadir(): void
    {
        $caso = $this->nuevoCaso();
        $evento = ConciliacionEvento::create(['conciliacion_id' => $caso->id, 'accion' => 'nota', 'motivo' => 'original']);
        $this->assertNotNull($evento->fresh()->created_at);

        try {
            $evento->update(['motivo' => 'editado']);
            $this->fail('Una actualización debió negarse.');
        } catch (LogicException) {
        }
        try {
            $evento->delete();
            $this->fail('Un borrado debió negarse.');
        } catch (LogicException) {
        }
        foreach ([fn () => $caso->eventos()->delete(), fn () => ConciliacionEvento::query()->delete(), fn () => $caso->eventos()->update(['motivo' => 'x']), fn () => ConciliacionEvento::where('id', $evento->id)->update(['motivo' => 'x'])] as $lote) {
            try {
                $lote();
                $this->fail('Una operación en lote debió negarse.');
            } catch (LogicException) {
            }
        }

        $this->assertSame('original', DB::table('cf_conciliaciones_eventos')->where('id', $evento->id)->value('motivo'));
    }

    public function test_el_evento_guarda_la_evidencia_como_json(): void
    {
        $caso = $this->nuevoCaso();
        $evento = ConciliacionEvento::create(['conciliacion_id' => $caso->id, 'accion' => 'detectado', 'evidencia' => ['certificados' => 3]]);

        $this->assertSame(['certificados' => 3], $evento->fresh()->evidencia);
    }

    // ── UNIQUE y FK RESTRICT ────────────────────────────────────────────────────────────────────────────

    public function test_la_clave_de_idempotencia_es_unica(): void
    {
        $this->nuevoCaso();
        $this->expectException(QueryException::class);
        $this->nuevoCaso();
    }

    public function test_el_pivote_no_admite_el_mismo_par_dos_veces(): void
    {
        $caso = $this->nuevoCaso();
        $cert = (int) DB::table('cf_certificados_legado')->value('id');
        DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso->id, 'certificado_legado_id' => $cert, 'rol' => 'a']);

        $this->expectException(QueryException::class);
        DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso->id, 'certificado_legado_id' => $cert, 'rol' => 'b']);
    }

    public function test_no_se_puede_borrar_un_certificado_que_pertenece_a_un_caso(): void
    {
        $this->detectar();
        $cert = (int) DB::table('cf_conciliaciones_certificados')->value('certificado_legado_id');

        $this->expectException(QueryException::class);
        DB::table('cf_certificados_legado')->where('id', $cert)->delete();
    }

    public function test_no_se_puede_borrar_un_caso_con_certificados_ni_con_eventos(): void
    {
        $this->detectar();
        $id = (int) DB::table('cf_conciliaciones')->value('id');

        $this->expectException(QueryException::class);
        DB::table('cf_conciliaciones')->where('id', $id)->delete();
    }

    public function test_no_se_puede_borrar_un_evento_historico_con_casos(): void
    {
        $this->detectar();
        $evento = (int) DB::table('cf_conciliaciones')->whereNotNull('evento_id')->value('evento_id');

        $this->expectException(QueryException::class);
        DB::table('cf_eventos')->where('id', $evento)->delete();
    }
}
