<?php

namespace Tests\Feature\CredentialFlow\Historico\Concerns;

use App\Support\CredentialFlow\Migracion\MigradorHistorico;
use App\Support\CredentialFlow\StagingEv\EscanerImagenes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Prepara un histórico SINTÉTICO migrado de verdad (staging → tablas finales) para las pruebas del histórico, la verificación
 * pública y el congelado de PDF. La clase que lo use debe extender CredentialFlowTestCase y usar FixturesStagingEv.
 * Las imágenes de plantilla sintéticas (PNG 2×2) quedan en `$this->dirImagenes` hasta terminar el test: son el «material
 * controlado» del que el ResolutorPlantillaDirectorio lee la imagen de fondo.
 */
trait MigraHistoricoSintetico
{
    protected string $dirImagenes = '';

    /** @return list<string> */
    protected function fasesHistorico(): array
    {
        return [
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
            'database/migrations/2026_10_09_100000_cf_accesos_otp_table.php',
            'database/migrations/2026_10_09_100100_add_origen_to_cf_descargas_table.php',
            'database/migrations/2026_10_09_100200_add_grupo_hash_to_cf_accesos_otp_table.php',
            'database/migrations/2026_10_10_100000_cf_encuestas_tables.php',
            'database/migrations/2026_10_11_100000_cf_envios_tables.php',
            'database/migrations/2026_10_11_100100_ajusta_cf_accesos_otp_para_envios.php',
            'database/migrations/2026_10_12_100000_cf_conciliaciones_tables.php',
            'database/migrations/2026_10_13_100000_add_aprobada_por_conciliacion_to_cf_plantillas_legado.php',
            'database/migrations/2026_10_13_100100_widen_accion_in_cf_conciliaciones_eventos.php',
            'database/migrations/2026_10_14_100000_cf_codigos_historicos_tables.php',
            'database/migrations/2026_10_15_100000_cf_reemplazo_historico_infraestructura.php',
            'database/migrations/2026_10_15_100100_add_origen_legado_meta_to_cf_plantillas.php',
            'database/migrations/2026_10_16_100000_cf_decisiones_identidad_tables.php',
            'database/migrations/2026_10_17_100000_add_scope_to_cf_accesos_otp.php',
            'database/migrations/2026_10_18_100000_cf_decisiones_identidad_aprobaciones.php',
        ];
    }

    protected function prepararHistorico(): void
    {
        $this->artisan('migrate', ['--path' => $this->fasesHistorico(), '--force' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--path' => 'database/staging/ev', '--force' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        if ($this->dirImagenes !== '') {
            File::deleteDirectory($this->dirImagenes);
        }
        parent::tearDown();
    }

    /** @param array<string,array<int,array<string,mixed>>>|null $datos */
    protected function migrarSintetico(?array $datos = null): void
    {
        $this->dirImagenes = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hist_'.bin2hex(random_bytes(6));
        mkdir($this->dirImagenes);
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        file_put_contents($this->dirImagenes.'/ALFA 2024.png', $png);
        file_put_contents($this->dirImagenes.'/DELTA 2023', $png);
        file_put_contents($this->dirImagenes.'/ORFANA.png', $png.'o');
        file_put_contents($this->dirImagenes.'/GAMMA_.png', $png.'g');
        $this->cargar('s1', $datos, (new EscanerImagenes)->escanear($this->dirImagenes));

        (new MigradorHistorico)->ejecutar();
    }

    protected function idEvento(string $nombre): int
    {
        return (int) DB::table('cf_eventos')->where('nombre', $nombre)->value('id');
    }

    /** Id del certificado migrado a partir del participante `$old` del staging. */
    protected function idCert(int $old): int
    {
        return (int) DB::table('cf_migraciones_map')->where('origen_tabla', 'participante')->where('origen_id', (string) $old)->value('destino_id');
    }

    /** Todas las sentencias SQL que ejecuta `$accion`. @return list<string> */
    protected function sentencias(callable $accion): array
    {
        $q = [];
        DB::listen(function ($e) use (&$q) {
            $q[] = $e->sql;
        });
        $accion();

        return $q;
    }
}
