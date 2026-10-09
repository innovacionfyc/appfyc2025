<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Correo;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fase 1.1: cf_correos (varios correos por participante o certificado histórico). Datos 100 % sintéticos (example.test).
 * El CHECK real, las FK y los UNIQUE contra MySQL/MariaDB se prueban en tests/Integracion/CorreosMysqlTest.php.
 */
class CorreosTest extends CredentialFlowTestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => self::FASE_1, '--force' => true])->assertExitCode(0);
    }

    private function participante(string $documento = '1001'): Participante
    {
        $lote = Lote::create(['plantilla_id' => $this->crearPlantilla()->id, 'nombre' => 'Lote '.$documento, 'datos_comunes' => []]);

        return Participante::create(['lote_id' => $lote->id, 'nombre_completo' => 'ANA PRUEBA', 'documento' => $documento, 'documento_clave' => $documento]);
    }

    private function certificado(string $documento = '2002'): CertificadoLegado
    {
        $evento = EventoCertificacion::create(['nombre' => 'Evento', 'nombre_normalizado' => 'evento', 'origen' => 'legado']);

        return CertificadoLegado::create(['evento_id' => $evento->id, 'documento_clave' => $documento, 'nombre_completo' => 'LUIS PRUEBA', 'snapshot_legado' => []]);
    }

    private function correoDe(Participante|CertificadoLegado $dueno, string $correo, array $extra = []): Correo
    {
        return Correo::create($extra + [
            $dueno instanceof Participante ? 'participante_id' : 'certificado_legado_id' => $dueno->id,
            'correo' => $correo,
            'origen' => $dueno instanceof Participante ? Correo::ORIGEN_CREDENTIAL_FLOW : Correo::ORIGEN_LEGADO,
        ]);
    }

    // ── Propietarios con uno o varios correos ─────────────────────────────────

    public function test_participante_con_un_correo(): void
    {
        $p = $this->participante();
        $c = $this->correoDe($p, 'Ana@Example.test', ['es_principal' => true]);

        $this->assertSame('Ana@Example.test', $c->correo, 'La dirección original no se modifica');
        $this->assertSame('ana@example.test', $c->correo_normalizado);
        $this->assertSame(Correo::ESTADO_VALIDO, $c->estado);
        $this->assertSame(Correo::ORIGEN_CREDENTIAL_FLOW, $c->origen);

        $p = $p->fresh();
        $this->assertCount(1, $p->correos);
        $this->assertSame($c->id, $p->correoUnicoUtilizable()?->id);
        $this->assertSame([$c->id], $p->correosValidos()->pluck('id')->all());
    }

    public function test_participante_con_dos_correos_conserva_ambos_y_no_hay_unico_utilizable(): void
    {
        $p = $this->participante();
        $a = $this->correoDe($p, 'uno@example.test', ['orden' => 1]);
        $b = $this->correoDe($p, 'dos@example.test', ['orden' => 2]);

        $p = $p->fresh();
        $this->assertSame([$a->id, $b->id], $p->correos->pluck('id')->all());
        $this->assertCount(2, $p->correosValidos());
        $this->assertNull($p->correoUnicoUtilizable(), 'Con varios válidos no se elige ninguno');
        $this->assertFalse($p->correos->contains('es_principal', true), 'Nadie queda como principal automáticamente');
    }

    public function test_certificado_legado_con_un_correo(): void
    {
        $cert = $this->certificado();
        $c = $this->correoDe($cert, 'luis@example.test');

        $this->assertSame(Correo::ORIGEN_LEGADO, $c->origen);
        $this->assertSame($c->id, $cert->fresh()->correoUnicoUtilizable()?->id);
        $this->assertSame($cert->id, $c->certificadoLegado->id);
        $this->assertNull($c->participante_id);
    }

    public function test_certificado_legado_con_dos_correos_los_conserva_todos_sin_elegir_principal(): void
    {
        $cert = $this->certificado();
        $this->correoDe($cert, 'a@example.test', ['orden' => 1]);
        $this->correoDe($cert, 'b@example.test', ['orden' => 2]);

        $cert = $cert->fresh();
        $this->assertCount(2, $cert->correos);
        $this->assertCount(2, $cert->correosValidos());
        $this->assertNull($cert->correoUnicoUtilizable());
        $this->assertSame(0, $cert->correos->where('es_principal', true)->count());
    }

    public function test_sin_correo_no_hay_filas(): void
    {
        $p = $this->participante();

        $this->assertCount(0, $p->correos);
        $this->assertCount(0, $p->correosValidos());
        $this->assertNull($p->correoUnicoUtilizable());
    }

    // ── Estados ───────────────────────────────────────────────────────────────

    public function test_correo_invalido_se_guarda_como_invalido_y_no_cuenta_como_utilizable(): void
    {
        $p = $this->participante();
        $malo = $this->correoDe($p, 'no-es-un-correo');
        $this->correoDe($p, 'bien@example.test');

        $this->assertSame(Correo::ESTADO_INVALIDO, $malo->estado);
        $this->assertSame('no-es-un-correo', $malo->correo);

        $p = $p->fresh();
        $this->assertCount(2, $p->correos);
        $this->assertSame(['bien@example.test'], $p->correosValidos()->pluck('correo')->all());
        $this->assertNotNull($p->correoUnicoUtilizable(), 'Un válido + un inválido = un único utilizable');
    }

    #[DataProvider('direcciones')]
    public function test_la_regla_de_validez(string $direccion, string $esperado): void
    {
        $this->assertSame($esperado, Correo::estadoDe(Correo::normalizar($direccion)));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function direcciones(): array
    {
        return [
            'normal' => ['ana@example.test', 'valido'],
            'mayúsculas y bordes' => ["  ANA@Example.TEST \t", 'valido'],
            'espacio duro en los bordes' => ["\u{00A0}ana@example.test\u{00A0}", 'valido'],
            'sin arroba' => ['ana.example.test', 'invalido'],
            'dominio sin punto' => ['ana@localhost', 'invalido'],
            'espacio interno' => ['ana perez@example.test', 'invalido'],
            'vacío' => ['   ', 'invalido'],
            'doble arroba' => ['a@@example.test', 'invalido'],
        ];
    }

    public function test_no_se_puede_marcar_valido_un_formato_invalido_ni_usar_estados_u_origenes_ajenos(): void
    {
        $p = $this->participante();

        foreach ([['estado' => 'valido', 'correo' => 'roto'], ['estado' => 'multiple', 'correo' => 'a@example.test'], ['origen' => 'otro', 'correo' => 'a@example.test']] as $extra) {
            try {
                $this->correoDe($p, $extra['correo'], array_diff_key($extra, ['correo' => 1]));
                $this->fail('Debía rechazarse: '.json_encode($extra));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, Correo::count());
    }

    public function test_un_correo_vacio_no_se_guarda_sin_correo_es_sin_filas(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->correoDe($this->participante(), '   ');
    }

    public function test_el_normalizado_se_recalcula_al_cambiar_la_direccion(): void
    {
        $c = $this->correoDe($this->participante(), 'viejo@example.test');
        $c->update(['correo' => 'NUEVO@Example.test']);

        $this->assertSame('nuevo@example.test', $c->fresh()->correo_normalizado);
    }

    // ── Duplicados ────────────────────────────────────────────────────────────

    public function test_el_mismo_correo_repetido_en_el_mismo_propietario_se_rechaza_aunque_cambie_la_forma(): void
    {
        $p = $this->participante();
        $this->correoDe($p, 'ana@example.test');

        $this->expectException(QueryException::class);
        $this->correoDe($p, ' ANA@example.test ');
    }

    public function test_el_mismo_correo_repetido_en_el_mismo_certificado_se_rechaza(): void
    {
        $cert = $this->certificado();
        $this->correoDe($cert, 'ana@example.test');

        $this->expectException(QueryException::class);
        $this->correoDe($cert, 'ana@example.test');
    }

    public function test_el_mismo_correo_en_propietarios_distintos_se_permite(): void
    {
        $p1 = $this->participante('1');
        $p2 = $this->participante('2');
        $cert = $this->certificado('3');

        foreach ([$p1, $p2, $cert] as $dueno) {
            $this->correoDe($dueno, 'familia@example.test');
        }

        $this->assertSame(3, Correo::where('correo_normalizado', 'familia@example.test')->count());
    }

    // ── Exactamente un propietario ────────────────────────────────────────────

    /** @return array<string,array{0:bool,1:bool,2:bool}> participante, certificado, aceptado */
    public static function propietarios(): array
    {
        return [
            'NULL / NULL: rechazado' => [false, false, false],
            'participante / NULL: aceptado' => [true, false, true],
            'NULL / certificado: aceptado' => [false, true, true],
            'participante / certificado: rechazado' => [true, true, false],
        ];
    }

    #[DataProvider('propietarios')]
    public function test_exactamente_un_propietario(bool $conParticipante, bool $conCertificado, bool $aceptado): void
    {
        $datos = ['correo' => 'a@example.test', 'origen' => Correo::ORIGEN_LEGADO]
            + ($conParticipante ? ['participante_id' => $this->participante()->id] : [])
            + ($conCertificado ? ['certificado_legado_id' => $this->certificado()->id] : []);

        if ($aceptado) {
            $this->assertNotNull(Correo::create($datos)->id);
            $this->assertSame(1, Correo::count());
        } else {
            $this->expectException(InvalidArgumentException::class);
            Correo::create($datos);
        }
    }

    // ── Principal ─────────────────────────────────────────────────────────────

    public function test_solo_un_valido_puede_ser_principal_y_solo_uno_por_propietario(): void
    {
        $p = $this->participante();
        $this->correoDe($p, 'uno@example.test', ['es_principal' => true]);

        $this->expectException(InvalidArgumentException::class);
        $this->correoDe($p, 'dos@example.test', ['es_principal' => true]);
    }

    public function test_un_invalido_no_puede_ser_principal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->correoDe($this->participante(), 'roto', ['es_principal' => true]);
    }

    public function test_el_principal_de_un_propietario_no_bloquea_al_de_otro_y_se_puede_actualizar_a_si_mismo(): void
    {
        $a = $this->correoDe($this->participante('1'), 'a@example.test', ['es_principal' => true]);
        $this->correoDe($this->participante('2'), 'a@example.test', ['es_principal' => true]);

        $a->update(['orden' => 3]);

        $this->assertSame(3, $a->fresh()->orden);
        $this->assertSame(2, Correo::where('es_principal', true)->count());
    }

    // ── multiple y campos simples ─────────────────────────────────────────────

    public function test_multiple_es_un_estado_admitido_de_los_campos_simples(): void
    {
        $this->assertSame(['valido', 'multiple', 'invalido', 'sin_correo'], Participante::CORREO_ESTADOS);
        $this->assertSame(Participante::CORREO_ESTADOS, CertificadoLegado::CORREO_ESTADOS);

        $p = $this->participante();
        $p->update(['correo_estado' => Participante::CORREO_MULTIPLE, 'correo_normalizado' => null]);
        $cert = $this->certificado();
        $cert->update(['correo_estado' => CertificadoLegado::CORREO_ESTADOS[1], 'correo_normalizado' => null]);

        $this->assertSame('multiple', $p->fresh()->correo_estado);
        $this->assertNull($p->fresh()->correo_normalizado);
        $this->assertSame('multiple', $cert->fresh()->correo_estado);
    }

    // ── Relaciones, FK y rollback ─────────────────────────────────────────────

    public function test_la_relacion_inversa_apunta_al_propietario_correcto(): void
    {
        $p = $this->participante();
        $c = $this->correoDe($p, 'a@example.test');

        $this->assertSame($p->id, $c->participante->id);
        $this->assertNull($c->certificadoLegado);
    }

    public function test_las_fk_son_restrict_no_se_borra_un_propietario_con_correos(): void
    {
        // SQLite solo aplica FK con PRAGMA foreign_keys; Laravel lo activa en pruebas. Se comprueba la definición y el efecto.
        foreach (['participante_id' => 'cf_participantes.id', 'certificado_legado_id' => 'cf_certificados_legado.id'] as $columna => $destino) {
            $llave = collect(Schema::getForeignKeys('cf_correos'))->first(fn ($f) => $f['columns'] === [$columna]);
            $this->assertNotNull($llave, "Falta la FK de $columna");
            $this->assertSame($destino, $llave['foreign_table'].'.'.implode(',', $llave['foreign_columns']));
            $this->assertSame('restrict', strtolower((string) $llave['on_delete']));
        }

        $cert = $this->certificado();
        $this->correoDe($cert, 'a@example.test');
        $this->expectException(QueryException::class);
        DB::table('cf_certificados_legado')->where('id', $cert->id)->delete();
    }

    public function test_indices_y_unicos_previstos(): void
    {
        $indices = [];
        foreach (Schema::getIndexes('cf_correos') as $i) {
            if (! $i['primary']) {
                $indices[$i['name']] = implode(',', $i['columns']).($i['unique'] ? '!' : '');
            }
        }

        $this->assertSame('participante_id,correo_normalizado!', $indices['cf_correos_participante_correo_uq']);
        $this->assertSame('certificado_legado_id,correo_normalizado!', $indices['cf_correos_certificado_correo_uq']);
        $this->assertSame('correo_normalizado', $indices['cf_correos_correo_idx']);
    }

    public function test_el_rollback_elimina_cf_correos_y_deja_lo_demas_como_estaba(): void
    {
        $this->assertTrue(Schema::hasTable('cf_correos'));
        $antes = array_map(fn ($t) => Schema::getColumnListing($t), ['cf_participantes', 'cf_certificados_legado']);

        $this->artisan('migrate:rollback', ['--path' => [self::FASE_1[6]], '--force' => true])->assertExitCode(0);

        $this->assertFalse(Schema::hasTable('cf_correos'));
        $this->assertTrue(Schema::hasTable('cf_certificados_legado'));
        $this->assertSame($antes, array_map(fn ($t) => Schema::getColumnListing($t), ['cf_participantes', 'cf_certificados_legado']));

        // Y se puede volver a subir.
        $this->artisan('migrate', ['--path' => [self::FASE_1[6]], '--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('cf_correos'));
    }
}
