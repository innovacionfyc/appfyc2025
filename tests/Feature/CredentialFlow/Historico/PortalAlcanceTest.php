<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/** Política de privacidad multi-identidad: documento + correo autenticado + GRUPO DE NOMBRE CONSERVADOR. Datos sintéticos. */
class PortalAlcanceTest extends PortalTestCase
{
    private function eventos(string $html): array
    {
        preg_match_all('/<h2>(Curso [^<]+)<\/h2>/', $html, $m);
        sort($m[1]);

        return $m[1];
    }

    private function panel(string $doc, string $correo): string
    {
        $this->entrar($doc, $correo)->assertRedirect(route('portal.panel'));

        return $this->get(route('portal.panel'))->assertOk()->getContent();
    }

    // ── Normalizador conservador ──────────────────────────────────────────────

    /** @return array<string,array{0:string,1:string}> */
    public static function normalizaciones(): array
    {
        return [
            'espacios y mayúsculas' => ["  MARÍA   Pérez  \t", 'maria perez'],
            'tildes y diéresis' => ['José Ñandú Güell', 'jose nandu guell'],
            'puntuación superficial' => ["O'Brien-Smith, Jr.", 'o brien smith jr'],
            'puntos y comas' => ['PEREZ, MARIA.', 'perez maria'],
            'ya normal' => ['maria perez', 'maria perez'],
            'vacío' => ['   ', ''],
        ];
    }

    #[DataProvider('normalizaciones')]
    public function test_normalizador_conservador(string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, NombreConservador::normalizar($entrada));
    }

    public function test_el_normalizador_no_hace_nada_aproximado(): void
    {
        $distintos = [['maria perez', 'maria perez gomez'], ['perez maria', 'maria perez'], ['maria perez', 'mario perez'], ['ana ruiz', 'ana ruiz x'], ['maria  perez', 'maria p'], ['jose', 'josé maría']];
        foreach ($distintos as [$a, $b]) {
            $this->assertNotSame(NombreConservador::grupoId($a), NombreConservador::grupoId($b), "«{$a}» y «{$b}» NO deben ser el mismo grupo");
        }
        $this->assertSame(NombreConservador::grupoId('MARÍA  PÉREZ.'), NombreConservador::grupoId('maria perez'));
        // El identificador es un HMAC opaco: 64 hex, sin el nombre.
        $id = NombreConservador::grupoId('maria perez');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $id);
        $this->assertStringNotContainsString('maria', $id);
    }

    public function test_ningun_codigo_del_portal_usa_similitud_aproximada(): void
    {
        $archivos = array_merge(glob(app_path('Support/CredentialFlow/Portal/*.php')), [app_path('Http/Controllers/CredentialFlow/PortalPublicoController.php')]);
        foreach ($archivos as $archivo) {
            $this->assertDoesNotMatchRegularExpression('/\b(levenshtein|similar_text|soundex|metaphone)\s*\(/i', file_get_contents($archivo), basename($archivo));
        }
    }

    // ── Documento de grupo único: comportamiento intacto ──────────────────────

    public function test_documento_de_un_solo_grupo_tiene_alcance_de_documento_completo(): void
    {
        $this->entrar('1000001', self::CORREO2)->assertRedirect(route('portal.panel'));

        $this->assertNull(session('cf_portal.contexto.grupo'));
        $this->assertCount(5, $this->eventos($this->get(route('portal.panel'))->getContent()), 'Todos los certificados del documento');
        $this->assertNull(DB::table('cf_accesos_otp')->value('grupo_hash'));
    }

    public function test_los_nombres_que_solo_difieren_en_formato_son_un_grupo_y_conservan_el_comportamiento(): void
    {
        // En el fixture, 'María  Pérez' y 'MARIA PEREZ.' son el MISMO grupo conservador que 'MARIA PEREZ'.
        $a = $this->app->make(AccesoPortal::class);
        $d = $a->cargar('3000001');

        $this->assertSame(2, collect($d['grupos'])->unique()->count(), 'María y Mario: dos grupos');
        $this->assertSame(['m1@example.test'], array_keys($d['correos'][$this->idCert(30)]));
    }

    // ── Multi-grupo resoluble ─────────────────────────────────────────────────

    public function test_un_correo_que_identifica_un_grupo_da_acceso_a_todas_las_filas_de_ese_grupo_aunque_tengan_otro_correo_o_ninguno(): void
    {
        $html = $this->panel('3.000.001', 'M1@Example.test');

        // María: Alfa (m1@), Beta (sin correo), Gamma (otro correo m2@). Mario (Delta, Epsilon) NO.
        $this->assertSame(['Curso Alfa 2024', 'Curso Beta 2025', 'Curso Gamma'], $this->eventos($html));
        $this->assertSame(hash_hmac('sha256', "grupo\0maria perez", config('app.key')), session('cf_portal.contexto.grupo'));
    }

    public function test_el_otro_grupo_del_mismo_documento_ve_solo_lo_suyo(): void
    {
        $html = $this->panel('3000001', 'l2@example.test');

        $this->assertSame(['Curso Delta 2023', 'Curso Epsilon 2026'], $this->eventos($html));
        $this->assertStringNotContainsString('Curso Alfa', $html);
    }

    public function test_la_sesion_guarda_solo_el_hash_del_grupo_nunca_el_nombre(): void
    {
        $this->panel('3000001', 'm1@example.test');

        $contexto = session('cf_portal.contexto');
        $this->assertEqualsCanonicalizing(['documento', 'grupo', 'authenticated_at', 'last_activity', 'absolute_expires_at'], array_keys($contexto));
        $volcado = json_encode(session()->all());
        foreach (['maria', 'perez', 'mario', 'lopez'] as $nombre) {
            $this->assertStringNotContainsStringIgnoringCase($nombre, $volcado);
        }
        $this->assertSame($contexto['authenticated_at'] + 120 * 60, $contexto['absolute_expires_at']);
    }

    public function test_el_alcance_queda_ligado_al_desafio_y_no_se_recalcula_al_validar(): void
    {
        $this->solicitar('3000001', 'm1@example.test');
        $codigo = $this->ultimoCodigo();
        $grupoMaria = NombreConservador::grupoId('maria perez');
        $this->assertSame($grupoMaria, DB::table('cf_accesos_otp')->value('grupo_hash'));

        // Entre la solicitud y la validación los datos cambian: el correo pasaría a identificar a Mario.
        DB::table('cf_correos')->where('correo_normalizado', 'm1@example.test')->delete();
        DB::table('cf_correos')->insert(['certificado_legado_id' => $this->idCert(33), 'correo' => 'm1@example.test', 'correo_normalizado' => 'm1@example.test', 'estado' => 'valido', 'orden' => 2, 'es_principal' => false, 'origen' => 'legado', 'created_at' => now(), 'updated_at' => now()]);
        $this->validarCodigo($codigo)->assertRedirect(route('portal.panel'));

        $this->assertSame($grupoMaria, session('cf_portal.contexto.grupo'), 'La sesión usa el alcance del desafío');
    }

    // ── Descarga con alcance de grupo ─────────────────────────────────────────

    public function test_descarga_dentro_del_grupo_funciona_y_fuera_del_grupo_es_el_mismo_404_que_uno_ajeno(): void
    {
        $this->panel('3000001', 'm1@example.test');

        $this->get(route('portal.descargar', $this->idCert(30)))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $otroGrupo = $this->get(route('portal.descargar', $this->idCert(33)));
        $otroDocumento = $this->get(route('portal.descargar', $this->idCert(1)));
        $inexistente = $this->get(route('portal.descargar', 99999999));

        $otroGrupo->assertNotFound();
        $this->assertSame($this->normalizar($otroGrupo)[0], $this->normalizar($otroDocumento)[0]);
        $this->assertSame($this->normalizar($otroGrupo)[0], $this->normalizar($inexistente)[0]);
        $this->assertSame(1, DB::table('cf_descargas')->where('origen', 'credential_flow')->count());
    }

    // ── Bloqueos: respuesta idéntica y sin OTP ────────────────────────────────

    public function test_un_correo_que_cruza_grupos_no_genera_otp_y_la_respuesta_es_identica(): void
    {
        $r = $this->solicitar('3000002', 'x@example.test');

        $r->assertRedirect(route('portal.codigo'))->assertSessionHas('aviso', 'Si los datos coinciden con nuestros registros, te enviaremos un código al correo indicado.');
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('cf_accesos_otp')->count());
    }

    public function test_un_grupo_sin_correo_no_entra_pero_el_grupo_con_correo_si(): void
    {
        $html = $this->panel('3000003', 'ar@example.test');

        // Solo el grupo de Ana (Alfa); el grupo «Ana Ruiz X» (Beta, sin correo) no aparece ni se menciona.
        $this->assertSame(['Curso Alfa 2024'], $this->eventos($html));
        $this->assertStringNotContainsString('Curso Beta', $html);
    }

    public function test_documento_sin_ningun_correo_que_identifique_no_recibe_otp(): void
    {
        DB::table('cf_correos')->whereIn('certificado_legado_id', [$this->idCert(30), $this->idCert(31), $this->idCert(32), $this->idCert(33), $this->idCert(34)])->delete();

        $this->solicitar('3000001', 'm1@example.test');

        Mail::assertNothingSent();
    }

    public function test_las_pantallas_siguientes_son_identicas_en_todos_los_casos_bloqueados_y_validos(): void
    {
        config(['credential_flow.portal.limite_solicitud_por_minuto' => 100]);
        $paginas = [];
        foreach ([['3000002', 'x@example.test'], ['3000001', 'm1@example.test'], ['9999999', 'nadie@example.test'], ['3000003', 'ar@example.test'], ['1000001', 'otra@example.test']] as [$doc, $correo]) {
            $this->post(route('portal.salir'));
            $r = $this->solicitar($doc, $correo);
            $paginas[] = [$r->getStatusCode(), $r->headers->get('Location'), $this->normalizar($this->get(route('portal.codigo')))[0]];
        }
        $this->assertCount(1, array_unique(array_map('serialize', $paginas)));
    }

    // ── Duplicados que cruzan grupos ──────────────────────────────────────────

    public function test_un_grupo_de_duplicados_conflictivos_solo_muestra_las_variantes_del_grupo_autorizado(): void
    {
        $this->assertSame(
            DB::table('cf_certificados_legado')->where('id', $this->idCert(60))->value('grupo_duplicado'),
            DB::table('cf_certificados_legado')->where('id', $this->idCert(61))->value('grupo_duplicado'),
            'Fixture: 60 y 61 son variantes de un mismo grupo de duplicados',
        );

        $html = $this->panel('3000004', 'ld@example.test');

        // Alcance «luz diaz»: la variante conflictiva (Alfa) + Beta. La variante «luz diaz x» no existe en el panel.
        $this->assertSame(['Curso Alfa 2024', 'Curso Beta 2025'], $this->eventos($html));
        $this->assertSame(1, substr_count($html, 'Estamos revisando este certificado'));
        $this->get(route('portal.descargar', $this->idCert(61)))->assertNotFound();
    }

    public function test_la_variante_del_otro_grupo_sin_certificado_habilitante_no_obtiene_otp(): void
    {
        $this->solicitar('3000004', 'ldx@example.test');

        Mail::assertNothingSent();
    }

    public function test_una_copia_consolidada_cuyo_canonico_cae_fuera_del_grupo_no_resuelve_en_silencio(): void
    {
        $this->assertSame('duplicado_consolidado', DB::table('cf_certificados_legado')->where('id', $this->idCert(71))->value('conciliacion_estado'));

        $html = $this->panel('3000005', 'os@example.test');

        // Alcance «otro sol»: solo la copia; su canónico (otro grupo) NO se usa: tarjeta en revisión, sin descarga ni PDF.
        $this->assertSame(['Curso Alfa 2024'], $this->eventos($html));
        $this->assertStringContainsString('Este certificado necesita revisión', $html);
        $this->assertStringNotContainsString('Descargar PDF', $html);
        $this->get(route('portal.descargar', $this->idCert(71)))->assertRedirect(route('portal.panel'))->assertSessionHas('error');
        $this->get(route('portal.descargar', $this->idCert(70)))->assertNotFound();
        $this->assertSame([], Storage::disk('local')->allFiles('credential-flow/legado'));
        $this->assertSame(0, DB::table('cf_descargas')->where('origen', 'credential_flow')->count());
    }

    public function test_el_grupo_del_canonico_tampoco_alcanza_a_la_copia_del_otro_grupo(): void
    {
        $html = $this->panel('3000005', 'ps@example.test');

        $this->assertSame(['Curso Alfa 2024'], $this->eventos($html));
        $this->assertStringContainsString('Descargar PDF', $html);
        $this->get(route('portal.descargar', $this->idCert(71)))->assertNotFound();
        $this->get(route('portal.descargar', $this->idCert(70)))->assertOk();
    }

    // ── Rendimiento ───────────────────────────────────────────────────────────

    public function test_el_panel_no_hace_n_mas_1(): void
    {
        $this->entrar('3000001', 'm1@example.test');
        $consultas = $this->sentencias(fn () => $this->get(route('portal.panel')));
        $dependen = array_filter($consultas, fn ($s) => str_contains($s, 'cf_certificados_legado') || str_contains($s, 'cf_correos') || str_contains($s, 'cf_eventos'));

        $this->assertLessThanOrEqual(6, count($dependen), implode("\n", $dependen));
    }
}
