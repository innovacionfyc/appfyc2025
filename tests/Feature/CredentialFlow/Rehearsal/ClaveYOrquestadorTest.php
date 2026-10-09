<?php

namespace Tests\Feature\CredentialFlow\Rehearsal;

use App\Support\CredentialFlow\LogSeguro;
use App\Support\CredentialFlow\Portal\Hmac;
use App\Support\CredentialFlow\Rehearsal\GuardiaClave;
use App\Support\CredentialFlow\Rehearsal\HuellaClave;
use App\Support\CredentialFlow\Rehearsal\ReconstructorRehearsal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\CredentialFlow\Historico\HistoricoTestCase;

/** Fase 11A: huella y guardia de la APP_KEY, comando de preflight, guardas del orquestador y registro seguro de excepciones. */
class ClaveYOrquestadorTest extends HistoricoTestCase
{
    private function corrida(): int
    {
        return (int) DB::table('cf_migraciones_corridas')->where('tipo', 'legado_evaluaciones')->where('estado', 'completada')->orderByDesc('id')->value('id');
    }

    private function registrar(?string $huella): void
    {
        $id = $this->corrida();
        $t = json_decode((string) DB::table('cf_migraciones_corridas')->where('id', $id)->value('totales'), true) ?: [];
        unset($t['huella_clave']);
        if ($huella !== null) {
            $t['huella_clave'] = $huella;
        }
        DB::table('cf_migraciones_corridas')->where('id', $id)->update(['totales' => json_encode($t)]);
    }

    public function test_la_huella_es_estable_no_contiene_la_clave_y_cambia_con_otra_clave(): void
    {
        $a = HuellaClave::actual();
        $this->assertSame($a, HuellaClave::actual());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        $this->assertStringNotContainsString((string) config('app.key'), $a);

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->assertNotSame($a, HuellaClave::actual());
    }

    public function test_los_hmac_dependen_de_la_clave_y_por_eso_una_bd_derivada_no_es_portable(): void
    {
        $antes = Hmac::de('documento', '123');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);
        $this->assertNotSame($antes, Hmac::de('documento', '123'));
    }

    public function test_sin_datos_la_guardia_no_bloquea(): void
    {
        $e = GuardiaClave::estado();   // BD vacía: ninguna migración histórica
        $this->assertSame('sin_datos', $e['estado']);
        GuardiaClave::exigirCoincidencia();
        $this->artisan('credential-flow:clave:verificar')->assertExitCode(0);
    }

    public function test_estados_coincide_no_coincide_y_sin_registro(): void
    {
        $this->prepararHistorico();
        $this->migrarSintetico($this->datos());

        $this->assertSame('coincide', GuardiaClave::estado()['estado'], 'la migración registra la huella');
        GuardiaClave::exigirCoincidencia();
        $this->artisan('credential-flow:clave:verificar')->assertExitCode(0);
        $this->artisan('credential-flow:clave:verificar', ['--estricto' => true])->assertExitCode(0);

        $this->registrar(str_repeat('0', 64));
        $this->assertSame('no_coincide', GuardiaClave::estado()['estado']);
        $this->artisan('credential-flow:clave:verificar')->assertExitCode(1);
        try {
            GuardiaClave::exigirCoincidencia();
            $this->fail('Debía negarse.');
        } catch (RuntimeException $e) {
            $this->assertSame(GuardiaClave::CODIGO, $e->getMessage());
        }

        $this->registrar(null);
        $this->assertSame('sin_registro', GuardiaClave::estado()['estado']);
        GuardiaClave::exigirCoincidencia();   // sin registro no bloquea…
        $this->artisan('credential-flow:clave:verificar')->assertExitCode(0);
        $this->artisan('credential-flow:clave:verificar', ['--estricto' => true])->assertExitCode(1);   // …salvo en modo estricto
    }

    public function test_el_comando_nunca_imprime_la_clave_ni_la_huella_completa(): void
    {
        $this->prepararHistorico();
        $this->migrarSintetico($this->datos());

        Artisan::call('credential-flow:clave:verificar', ['--json' => true]);
        $salida = Artisan::output();

        $this->assertStringNotContainsString((string) config('app.key'), $salida);
        $this->assertStringNotContainsString(HuellaClave::actual(), $salida);
        $this->assertSame(12, strlen(json_decode($salida, true)['huella_actual']));
    }

    // ── Guardas del orquestador ───────────────────────────────────────────────────────────────────────────

    private function validar(array $o, ?string $clave = 'base64:'.'QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU='): ?string
    {
        putenv('REHEARSAL_APP_KEY_TEST'.($clave === null ? '' : '='.$clave));
        try {
            (new ReconstructorRehearsal($o + ['app_key_env' => 'REHEARSAL_APP_KEY_TEST']))->validar();

            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        } finally {
            putenv('REHEARSAL_APP_KEY_TEST');
        }
    }

    private function validas(): array
    {
        return ['bd' => 'appfyc_rehearsal_x', 'staging_bd' => 'appfyc_staging_x', 'raiz_storage' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'cf_rehearsal_storage_t'];
    }

    public function test_el_orquestador_se_niega_en_produccion(): void
    {
        $this->app['env'] = 'production';
        $this->assertSame('ENTORNO_PRODUCCION_NO_PERMITIDO', $this->validar($this->validas()));
    }

    public function test_el_orquestador_rechaza_nombres_de_bd_que_no_son_de_rehearsal_o_pruebas(): void
    {
        foreach (['appfyc', 'appfyc2025', 'produccion', 'x;drop', 'rehearsal bd', ''] as $bd) {
            $this->assertSame('BD_DESTINO_NO_RECONOCIDA', $this->validar(['bd' => $bd] + $this->validas()), "bd={$bd}");
        }
    }

    public function test_el_orquestador_exige_staging_distinto_y_con_nombre_de_staging(): void
    {
        $this->assertSame('BD_STAGING_INVALIDA', $this->validar(['staging_bd' => 'appfyc_rehearsal_x'] + $this->validas()));
        $this->assertSame('BD_STAGING_INVALIDA', $this->validar(['staging_bd' => 'appfyc'] + $this->validas()));
        $this->assertSame('BD_STAGING_INVALIDA', $this->validar(['staging_bd' => ''] + $this->validas()));
    }

    public function test_el_orquestador_exige_una_app_key_de_rehearsal_explicita(): void
    {
        $this->assertSame('APP_KEY_DE_REHEARSAL_REQUERIDA', $this->validar($this->validas(), null));
        $this->assertSame('APP_KEY_DE_REHEARSAL_REQUERIDA', $this->validar($this->validas(), 'corta'));
    }

    public function test_el_orquestador_exige_un_storage_dedicado_y_no_el_privado_de_la_app(): void
    {
        $this->assertSame('STORAGE_DEDICADO_REQUERIDO', $this->validar(['raiz_storage' => ''] + $this->validas()));
        $this->assertSame('STORAGE_DEDICADO_REQUERIDO', $this->validar(['raiz_storage' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'otra_carpeta'] + $this->validas()));
        $this->assertSame('STORAGE_DEDICADO_REQUERIDO', $this->validar(['raiz_storage' => storage_path('app/private')] + $this->validas()));

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cf_rehearsal_storage_ajeno_'.bin2hex(random_bytes(3));
        mkdir($dir);
        file_put_contents($dir.'/importante.txt', 'x');
        $this->assertSame('STORAGE_NO_ES_DE_REHEARSAL', $this->validar(['raiz_storage' => $dir] + $this->validas()), 'una carpeta con contenido sin marcador nunca se usa');
        unlink($dir.'/importante.txt');
        rmdir($dir);
    }

    // ── Registro seguro de excepciones ───────────────────────────────────────────────────────────────────

    public function test_el_resumen_de_log_no_incluye_mensaje_ni_parametros(): void
    {
        $e = new RuntimeException('documento 123456789 correo secreto@example.test');
        $r = LogSeguro::resumen($e);
        $this->assertStringContainsString(RuntimeException::class, $r);
        $this->assertStringNotContainsString('123456789', $r);
        $this->assertStringNotContainsString('secreto', $r);
        $this->assertMatchesRegularExpression('/@ClaveYOrquestadorTest\.php:\d+$/', $r);

        $q = new QueryException('mysql', 'select * from x where documento = ?', ['123456789'], new \PDOException('SQLSTATE[23000]: Integrity 123456789'));
        $q->errorInfo = ['23000', 1062, 'Duplicate 123456789'];
        $rq = LogSeguro::resumen($q);
        $this->assertStringContainsString('[SQLSTATE 23000]', $rq);
        $this->assertStringNotContainsString('123456789', $rq);
    }
}
