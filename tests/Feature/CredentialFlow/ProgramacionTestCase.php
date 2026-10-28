<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\EmisionProgramada;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Programacion\EjecutorProgramado;
use App\Support\CredentialFlow\Programacion\EstimacionMemoria;
use App\Support\CredentialFlow\Programacion\ProgramacionException;
use App\Support\CredentialFlow\Programacion\ServicioProgramacion;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Base de las pruebas de la emisión programada (P1 y P2). Reloj simulado: «ahora» es el martes 27 de octubre de 2026, 10:00 de Colombia. Datos 100 % ficticios;
 * todo ocurre en la base SQLite en memoria y en discos falsos. Interruptor general encendido (cada prueba que lo necesite lo apaga).
 */
abstract class ProgramacionTestCase extends EmisionesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Las mismas tablas que usan las pruebas de la emisión con QA (Fase3FechasQaTest): eventos, correos, históricos y el catálogo QA.
        $this->artisan('migrate', ['--path' => [
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
            'database/migrations/2026_10_21_100000_cf_contactos_qa_y_plantilla_qa.php',
        ], '--force' => true])->assertExitCode(0);
        for ($i = 1; $i <= 2; $i++) {
            $doc = (string) (72000000 + $i);
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => "QA PROGRAMADA {$i}", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc),
                'correo' => "qa.programada{$i}@example.test", 'correo_normalizado' => "qa.programada{$i}@example.test", 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        config(['credential_flow.programacion.activa' => true, 'credential_flow.qa.contactos_esperados' => 2]);
        $this->travelTo(CarbonImmutable::create(2026, 10, 27, 10, 0, 0, 'America/Bogota'));
    }

    protected function tearDown(): void
    {
        // Las simulaciones de estas pruebas son estáticas: se restauran siempre.
        ServicioProgramacion::$antesDeBloquear = null;
        EjecutorProgramado::$entreOperaciones = null;
        EstimacionMemoria::$limiteSimuladoMb = null;
        AlmacenEmisiones::$antesDeEscribir = null;
        parent::tearDown();
    }

    protected function servicio(): ServicioProgramacion
    {
        return app(ServicioProgramacion::class);
    }

    protected function variante(Plantilla $p): Plantilla
    {
        $this->artisan('credential-flow:plantilla-variante-qa', ['origen' => $p->id, '--ejecutar' => true])->assertExitCode(0);

        return Plantilla::query()->findOrFail($p->fresh()->variante_qa_id);
    }

    /** Base lista para emitir: N reales, sus 2 contactos QA y la variante de prueba de su plantilla. */
    protected function base(int $reales = 3, bool $conVariante = true, bool $conQa = true): Lote
    {
        $p = $this->plantillaLista();
        if ($conVariante) {
            $this->variante($p);
        }
        $lote = $this->loteCon($reales, $p);
        if ($conQa) {
            app(ServicioQa::class)->incorporar($lote->fresh());
        }

        return $lote->fresh();
    }

    protected function programar(Lote $lote, string $cuando = '2026-10-28 08:00', $usuario = null): EmisionProgramada
    {
        return $this->servicio()->programar($lote, $cuando, $usuario ?? $this->admin());
    }

    /** Ejecuta y devuelve el código del error esperado (ProgramacionException o EmisionException). */
    protected function codigo(\Closure $f): string
    {
        try {
            $f();
        } catch (ProgramacionException|EmisionException $e) {
            return $e->codigo;
        }
        $this->fail('Se esperaba un error y no hubo ninguno.');
    }
}
