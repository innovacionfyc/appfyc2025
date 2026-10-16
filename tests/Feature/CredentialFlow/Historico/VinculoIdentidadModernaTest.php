<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\Lote;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Portal\VinculosIdentidadModerna;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Persona cuyo nombre histórico tiene una grafía distinta de su nombre correcto en la base nueva (caso real: «ERICK» histórico vs «ERIK» correcto) y que
 * comparte el MISMO correo en ambos. Sin vínculo explícito el portal NO la autentica (alcance ambiguo, protección de homónimos intacta). Con un vínculo
 * verificable creado por un administrador, ve sus certificados históricos y el moderno en el mismo panel, sin tocar ningún histórico. Datos sintéticos.
 *
 * El histórico sintético de PortalTestCase: documento 1000001, «ANA UNO PRUEBA», correos ana.uno@ / ana.dos@example.test.
 */
class VinculoIdentidadModernaTest extends PortalTestCase
{
    private const NOMBRE_MODERNO = 'ANNA UNO PRUEBA';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_19_100000_cf_vinculos_identidad_moderna_table.php', '--force' => true])->assertExitCode(0);
    }

    private function importar(string $csv): Lote
    {
        return app(ImportadorParticipantes::class)->importar(
            UploadedFile::fake()->createWithContent('base.csv', $csv), $this->plantillaLista(), 'Base', null,
            ['evento' => 'Evento moderno sintético', 'fecha' => 'Realizado el 1 de octubre de 2026', 'intensidad_horaria' => '4 horas'],
        );
    }

    /** Emite al participante moderno «ANNA UNO PRUEBA» (documento de Ana, el mismo correo principal de Ana) y devuelve su emisión. */
    private function emitirModerno(string $nombre = self::NOMBRE_MODERNO, string $correo = self::CORREO): object
    {
        $lote = $this->importar("nombre_completo,documento,correo\n{$nombre},1.000.001,{$correo}\n");
        $p = $lote->participantes()->first();
        $this->emitirPor($lote, $p)->assertCreated();

        return DB::table('cf_emisiones')->where('participante_id', $p->id)->first();
    }

    private function vincular(): int
    {
        return app(VinculosIdentidadModerna::class)->crear('1.000.001', self::CORREO, self::NOMBRE, self::NOMBRE_MODERNO, 'Grafía histórica errónea: la persona confirmó su nombre correcto.', $this->admin()->id);
    }

    private function tarjetas(string $html): array
    {
        preg_match_all('/data-certificado="([a-z_]+)"( data-moderno)?/', $html, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => ($x[2] ?? '') !== '' ? $x[1].'*' : $x[1], $m);
    }

    private function huellaHistorica(): string
    {
        return md5(json_encode([DB::table('cf_certificados_legado')->orderBy('id')->get(), DB::table('cf_correos')->whereNotNull('certificado_legado_id')->orderBy('id')->get()]));
    }

    public function test_sin_vinculo_el_mismo_correo_en_dos_grafias_sigue_siendo_ambiguo_y_no_abre_acceso(): void
    {
        $this->emitirModerno();

        $this->assertNull((new AccesoPortal)->alcance('1000001', self::CORREO));
        $this->solicitar('1.000.001', self::CORREO);
        $this->assertNull($this->ultimoCodigo());
        // El correo que solo está en el grupo histórico sigue entrando a su grupo y NO ve el moderno.
        $this->assertSame(['grupo' => NombreConservador::grupoId(self::NOMBRE)], (new AccesoPortal)->alcance('1000001', self::CORREO2));
    }

    public function test_con_vinculo_verificable_ve_historicos_y_moderno_juntos_y_descarga_ambos(): void
    {
        $e = $this->emitirModerno();
        $antes = $this->huellaHistorica();
        $this->vincular();

        $this->assertSame(['grupo' => null], (new AccesoPortal)->alcance('1000001', self::CORREO));
        $this->entrar('1.000.001', self::CORREO)->assertRedirect(route('portal.panel'));
        $t = $this->tarjetas($this->get(route('portal.panel'))->getContent());
        $this->assertSame(1, count(array_filter($t, fn ($x) => str_ends_with($x, '*'))), 'una tarjeta moderna');
        $this->assertGreaterThan(1, count($t), 'más los históricos de la misma persona');
        $this->get(route('portal.descargar-moderno', $e->id))->assertOk();
        $this->assertSame($antes, $this->huellaHistorica(), 'ningún certificado ni correo histórico cambió');
        $this->get(route('portal.descargar', $this->idCert(1)))->assertOk();   // el histórico sigue entregándose igual
    }

    public function test_un_correo_incorrecto_y_un_homonimo_no_obtienen_acceso_adicional(): void
    {
        $e = $this->emitirModerno();
        $this->vincular();
        $otro = $this->emitirModerno('PERSONA DISTINTA DE ANA', 'distinta@example.test');

        $this->solicitar('1.000.001', 'otro.correo@example.test');
        $this->assertNull($this->ultimoCodigo());                                   // correo incorrecto
        $this->assertSame(['grupo' => NombreConservador::grupoId('PERSONA DISTINTA DE ANA')], (new AccesoPortal)->alcance('1000001', 'distinta@example.test'));

        $this->entrar('1.000.001', 'distinta@example.test');                      // el homónimo ve SOLO lo suyo
        $this->assertSame(['disponible*'], $this->tarjetas($this->get(route('portal.panel'))->getContent()));
        $this->get(route('portal.descargar-moderno', $e->id))->assertNotFound();   // ni el moderno de Ana
        $this->get(route('portal.descargar', $this->idCert(1)))->assertNotFound(); // ni sus históricos
        $this->get(route('portal.descargar-moderno', $otro->id))->assertOk();
    }

    public function test_una_sesion_abierta_antes_del_vinculo_no_lo_hereda(): void
    {
        $e = $this->emitirModerno();
        $this->entrar('1.000.001', self::CORREO2)->assertRedirect(route('portal.panel'));   // entra por el correo del grupo histórico
        $antes = $this->tarjetas($this->get(route('portal.panel'))->getContent());

        $this->travel(5)->seconds();
        $this->vincular();

        $this->assertSame($antes, $this->tarjetas($this->get(route('portal.panel'))->getContent()), 'la sesión vieja no ve nada nuevo');
        $this->get(route('portal.descargar-moderno', $e->id))->assertNotFound();
        $this->post(route('portal.salir'));

        $this->travel(10)->minutes();                                                 // pasa la ventana de reenvío del OTP
        $this->entrar('1.000.001', self::CORREO2);                                   // una sesión NUEVA sí lo ve
        $this->assertContains('disponible*', $this->tarjetas($this->get(route('portal.panel'))->getContent()));
        $this->get(route('portal.descargar-moderno', $e->id))->assertOk();
    }

    public function test_el_vinculo_exige_evidencia_y_no_se_crea_a_ciegas(): void
    {
        $this->emitirModerno();
        $s = app(VinculosIdentidadModerna::class);
        $intentos = [
            ['correo ajeno a ambos grupos', ['1.000.001', 'nadie@example.test', self::NOMBRE, self::NOMBRE_MODERNO, 'Motivo suficientemente largo para el caso.']],
            ['correo solo en el histórico', ['1.000.001', self::CORREO2, self::NOMBRE, self::NOMBRE_MODERNO, 'Motivo suficientemente largo para el caso.']],
            ['mismo grupo', ['1.000.001', self::CORREO, self::NOMBRE, self::NOMBRE, 'Motivo suficientemente largo para el caso.']],
            ['motivo corto', ['1.000.001', self::CORREO, self::NOMBRE, self::NOMBRE_MODERNO, 'corto']],
            ['grupo histórico inexistente', ['1.000.001', self::CORREO, 'OTRA PERSONA CUALQUIERA', self::NOMBRE_MODERNO, 'Motivo suficientemente largo para el caso.']],
            ['documento sin certificados modernos', ['1.000.002', self::CORREO, self::NOMBRE, self::NOMBRE_MODERNO, 'Motivo suficientemente largo para el caso.']],
        ];
        foreach ($intentos as [$caso, $a]) {
            try {
                $s->crear(...$a, usuarioId: $this->admin()->id);
                $this->fail("Debía rechazar: {$caso}");
            } catch (InvalidArgumentException) {
                $this->assertSame(0, DB::table('cf_vinculos_identidad')->count(), $caso);
            }
        }
        $this->assertNull((new AccesoPortal)->alcance('1000001', self::CORREO));
    }

    public function test_revocar_el_vinculo_devuelve_cada_grupo_a_su_sitio_y_la_revocacion_de_la_emision_sigue_bloqueando(): void
    {
        $e = $this->emitirModerno();
        $id = $this->vincular();
        $this->assertSame(['grupo' => null], (new AccesoPortal)->alcance('1000001', self::CORREO));

        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e->id), ['motivo' => 'Prueba de revocación del certificado moderno'])->assertOk();
        $this->entrar('1.000.001', self::CORREO);
        $this->assertContains('revocado*', $this->tarjetas($this->get(route('portal.panel'))->getContent()));
        $this->get(route('portal.descargar-moderno', $e->id))->assertRedirect(route('portal.panel'));
        $this->assertSame(0, DB::table('cf_descargas')->where('emision_id', $e->id)->count());
        $this->post(route('portal.salir'));

        app(VinculosIdentidadModerna::class)->revocar($id, 'Vínculo revocado en la prueba de reversión.', $this->admin()->id);
        $this->assertNull((new AccesoPortal)->alcance('1000001', self::CORREO));   // vuelve a ser ambiguo
        $this->assertSame(1, DB::table('cf_vinculos_identidad')->where('estado', 'revocado')->count());
    }

    public function test_el_vinculo_no_guarda_datos_personales(): void
    {
        $this->emitirModerno();
        $this->vincular();
        $fila = json_encode(DB::table('cf_vinculos_identidad')->first());
        foreach (['1000001', '1.000.001', self::CORREO, self::NOMBRE, self::NOMBRE_MODERNO] as $dato) {
            $this->assertStringNotContainsString($dato, $fila);
        }
    }

    public function test_el_comando_valida_sin_escribir_y_solo_crea_con_ejecutar_y_usuario_valido(): void
    {
        $this->emitirModerno();
        $base = ['documento' => '1.000.001', 'correo' => self::CORREO, '--historico' => self::NOMBRE, '--moderno' => self::NOMBRE_MODERNO, '--motivo' => 'Grafía histórica errónea confirmada por la persona.'];

        $this->artisan('credential-flow:identidad-vincular', $base)->assertExitCode(0);
        $this->assertSame(0, DB::table('cf_vinculos_identidad')->count(), 'sin --ejecutar no se escribe');
        $this->artisan('credential-flow:identidad-vincular', $base + ['--ejecutar' => true, '--usuario' => 999999])->assertExitCode(1);
        $this->assertSame(0, DB::table('cf_vinculos_identidad')->count());
        $this->artisan('credential-flow:identidad-vincular', ['correo' => 'nadie@example.test'] + $base + ['--ejecutar' => true, '--usuario' => $this->admin()->id])->assertExitCode(1);
        $this->assertSame(0, DB::table('cf_vinculos_identidad')->count(), 'sin evidencia no se crea');

        $this->artisan('credential-flow:identidad-vincular', $base + ['--ejecutar' => true, '--usuario' => $this->admin()->id])->assertExitCode(0);
        $this->assertSame(1, DB::table('cf_vinculos_identidad')->where('estado', 'vigente')->count());
    }
}
