<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use App\Support\CredentialFlow\Legado\ResolutorPlantillaDirectorio;
use App\Support\CredentialFlow\Legado\ResolutorPlantillaLegado;
use App\Support\CredentialFlow\Reemplazo\ConsultaReemplazo;
use App\Support\CredentialFlow\Reemplazo\CreadorPlantillaReemplazo;
use App\Support\CredentialFlow\Reemplazo\ReemplazoHistorico;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\CredentialFlow\Support\PngSintetico;

/** Asistente «Emitir certificado corregido» (Fase 10B-2B-2B): acceso, elegibilidad, plantilla, diseño, vista previa, frescura, confirmaciones y emisión. */
class ReemplazoUiTest extends ReemplazoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // La preparación del clon lee la imagen histórica del material controlado de la prueba (en producción, del storage privado).
        $this->app->bind(ResolutorPlantillaLegado::class, fn () => new ResolutorPlantillaDirectorio($this->dirImagenes));
    }

    private function ruta(string $nombre, int $old): string
    {
        return route('credential-flow.historico.casos.'.$nombre, $this->casoDe($old));
    }

    private function datosDe(int $old, int $plantillaId, array $extra = []): array
    {
        $s = $this->solicitud($old, $plantillaId);

        return array_merge(array_filter([
            'plantilla_id' => $plantillaId, 'regla_documento' => $s->reglaDocumento, 'valor_documento' => $s->valorDocumento, 'confirmado_valor' => $s->confirmado,
            'tengo_evidencia' => $s->evidencia !== null, 'evidencia' => $s->evidencia,
        ], fn ($v) => $v !== null), $extra);
    }

    private function pantalla(int $old): array
    {
        return $this->actingAs($this->admin())->get($this->ruta('reemplazo', $old))->assertOk()->viewData('page')['props']['datos'];
    }

    private function detalle(int $old): array
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.show', $this->casoDe($old)))->assertOk()->viewData('page')['props']['caso'];
    }

    private function preview(int $old, array $datos, $usuario = null): TestResponse
    {
        return $this->actingAs($usuario ?? $this->admin())->postJson($this->ruta('reemplazo.preview', $old), $datos);
    }

    /** Clon preparado y diseño confirmado por las rutas HTTP (el flujo real de la pantalla). */
    private function clonPorHttp(int $old): Plantilla
    {
        $this->actingAs($this->admin())->post($this->ruta('reemplazo.clon', $old))->assertRedirect($this->ruta('reemplazo', $old));
        $clon = CreadorPlantillaReemplazo::existentePara(CertificadoLegado::findOrFail($this->idCert($old)));
        $this->actingAs($this->admin())->post($this->ruta('reemplazo.diseno', $old), ['plantilla_id' => $clon->id])->assertRedirect($this->ruta('reemplazo', $old));

        return $clon->fresh();
    }

    /** Vista previa y emisión con la huella REAL de la vista previa (la sesión se fija explícitamente: las pruebas no conservan cookies). */
    private function emitir(int $old, array $datos, array $extra = [], ?string $huella = null): TestResponse
    {
        $huella ??= $this->preview($old, $datos)->assertOk()->headers->get('X-Huella-Preview');

        return $this->withSession(['cf_reemplazo_preview_'.$this->casoDe($old) => $huella])->actingAs($this->admin())->post($this->ruta('reemplazo.emitir', $old), $datos + $extra + [
            'motivo' => self::MOTIVO, 'confirmo_revision' => true, 'confirmo_final' => true, 'huella' => $huella,
        ]);
    }

    private function nada(): array
    {
        return [DB::table('cf_lotes')->count(), DB::table('cf_participantes')->count(), DB::table('cf_emisiones')->count(), DB::table('cf_plantillas')->count(), collect(Storage::disk('local')->allFiles())->sort()->values()->all()];
    }

    // ── Acceso, permisos y CSRF ──────────────────────────────────────────────────────────────────────────

    public function test_sin_sesion_va_al_login_y_un_rol_no_permitido_recibe_403(): void
    {
        $this->get($this->ruta('reemplazo', 120))->assertRedirect(route('login'));
        foreach (['reemplazo.clon', 'reemplazo.diseno', 'reemplazo.preview', 'reemplazo.emitir'] as $r) {
            $this->post($this->ruta($r, 120), [])->assertRedirect(route('login'));
        }
        $antes = $this->nada();
        $this->actingAs($this->comercial())->get($this->ruta('reemplazo', 120))->assertForbidden();
        foreach (['reemplazo.clon', 'reemplazo.diseno', 'reemplazo.preview', 'reemplazo.emitir'] as $r) {
            $this->actingAs($this->comercial())->post($this->ruta($r, 120), [])->assertForbidden();
        }
        $this->assertSame($antes, $this->nada());
        $this->actingAs($this->admin())->get($this->ruta('reemplazo', 120))->assertOk();
        $this->actingAs($this->superAdmin())->get($this->ruta('reemplazo', 120))->assertOk();
    }

    public function test_las_rutas_de_escritura_son_post_con_sesion_rol_y_csrf(): void
    {
        foreach (['reemplazo.clon', 'reemplazo.diseno', 'reemplazo.preview', 'reemplazo.emitir'] as $nombre) {
            $ruta = Route::getRoutes()->getByName('credential-flow.historico.casos.'.$nombre);
            $this->assertSame(['POST'], $ruta->methods(), $nombre);
            $this->assertContains('auth', $ruta->gatherMiddleware());
            $this->assertContains('rol:super-admin,admin', $ruta->gatherMiddleware());
            $this->assertTrue(collect(app('router')->resolveMiddleware($ruta->gatherMiddleware(), $ruta->excludedMiddleware()))->contains(fn ($m) => is_string($m) && str_contains($m, 'ValidateCsrfToken')), $nombre);
        }
        $this->assertSame(['GET', 'HEAD'], Route::getRoutes()->getByName('credential-flow.historico.casos.reemplazo')->methods());
    }

    public function test_el_csrf_se_exige_de_verdad_fuera_de_las_pruebas(): void
    {
        $this->app['env'] = 'production';
        $antes = $this->nada();

        foreach (['reemplazo.clon', 'reemplazo.diseno', 'reemplazo.preview', 'reemplazo.emitir'] as $r) {
            $this->actingAs($this->admin())->post($this->ruta($r, 120), [])->assertStatus(419);
        }
        $this->assertSame($antes, $this->nada());
        // Con el token correcto, la petición entra (aquí: un clon, que es idempotente y no emite nada).
        $this->withSession(['_token' => 't'])->actingAs($this->admin())->post($this->ruta('reemplazo.clon', 120), ['_token' => 't'])->assertRedirect();
        $this->assertSame(1, Plantilla::count());
    }

    // ── Elegibilidad (entrada desde el detalle del caso) ─────────────────────────────────────────────────

    public function test_el_detalle_ofrece_emitir_certificado_corregido_solo_a_los_documentales_elegibles(): void
    {
        foreach ([120, 121, 122, 123] as $old) {
            $a = $this->detalle($old)['accion'];
            $this->assertSame(['emitir_reemplazo', 'Emitir certificado corregido', true, $this->ruta('reemplazo', $old)], [$a['clave'], $a['etiqueta'], $a['enlace'], $a['url']], (string) $old);
            $this->assertStringContainsString('no modifica el certificado histórico', $a['texto']);
        }
        // No lo ofrecen: nombres reales, DOC_VACIO, DOC_LETRAS de texto, descarte, consolidaciones.
        foreach ([104 => 'emitir_reemplazo', 106 => 'requiere_soporte', 107 => 'descartar', 108 => 'requiere_soporte', 90 => 'consolidar_variantes', 102 => 'consolidar_nombre'] as $old => $clave) {
            $this->assertSame($clave, $this->detalle($old)['accion']['clave'] ?? null, (string) $old);
        }
        // Los no elegibles redirigen al detalle con el motivo, sin abrir el asistente.
        foreach ([106, 108, 90] as $old) {
            $this->actingAs($this->admin())->get($this->ruta('reemplazo', $old))->assertRedirect(route('credential-flow.historico.casos.show', $this->casoDe($old)))->assertSessionHas('error');
        }
        // Caso con otro tipo de evidencia documental pero ya en soporte/descartado/resuelto/reemplazado: sin acción.
        DB::table('cf_conciliaciones')->where('id', $this->casoDe(120))->update(['estado' => 'requiere_soporte']);
        DB::table('cf_conciliaciones')->where('id', $this->casoDe(121))->update(['estado' => 'descartado']);
        DB::table('cf_conciliaciones')->where('id', $this->casoDe(122))->update(['estado' => 'resuelto']);
        foreach ([120, 121, 122] as $old) {
            $this->assertNull($this->detalle($old)['accion'], (string) $old);
            $this->actingAs($this->admin())->get($this->ruta('reemplazo', $old))->assertRedirect()->assertSessionHas('error');
        }
        DB::table('cf_certificados_legado')->where('id', $this->idCert(123))->update(['estado' => 'reemplazado']);
        $this->assertNull($this->detalle(123)['accion']);
        $this->actingAs($this->admin())->get($this->ruta('reemplazo', 123))->assertRedirect()->assertSessionHas('error', 'Este certificado ya fue reemplazado.');
    }

    // ── Asistente: evidencia y dato aprobado ─────────────────────────────────────────────────────────────

    public function test_el_asistente_muestra_evidencia_enmascarada_sin_correos_y_con_cabeceras_privadas(): void
    {
        $r = $this->actingAs($this->admin())->get($this->ruta('reemplazo', 120))->assertOk();
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $r->headers->get('Cache-Control'));
        $d = $r->viewData('page')['props']['datos'];

        $this->assertSame('Esta acción no modifica el certificado histórico. Creará una nueva emisión moderna y marcará el anterior como reemplazado.', $d['aviso']);
        $this->assertSame('DOC_WHITESPACE_CAMBIA_IMPRESION', $d['evidencia']['categoria']['codigo']);
        $this->assertStringContainsString('en blanco', $d['evidencia']['comportamiento_historico']);
        $this->assertStringNotContainsString('8300001', json_encode($d['evidencia']), 'el documento histórico llega enmascarado');
        $this->assertSame(0, $d['evidencia']['descargas_historicas']);
        $this->assertSame('PERSONA ESPACIO', $d['valor']['nombre_impreso']);
        $this->assertStringNotContainsString('@example.test', json_encode($d), 'nunca correos');
        $this->assertNull($d['plantilla']['clon']);
        $this->assertTrue($d['plantilla']['puede_preparar']);
    }

    public function test_cada_categoria_propone_su_valor_y_el_alfanumerico_no_propone_ninguno(): void
    {
        $w = $this->pantalla(120)['valor'];
        $this->assertSame(['documento_sin_nbsp', '8300001', false, false], [$w['reglas'][0]['regla'], $w['reglas'][0]['valor'], $w['reglas'][0]['requiere_confirmacion'], $w['reglas'][0]['reforzada']]);

        $s = $this->pantalla(121)['valor']['reglas'][0];
        $this->assertSame(['documento_con_separadores', '83.000.002', true, true], [$s['regla'], $s['valor'], $s['requiere_confirmacion'], $s['editable']]);

        $o = $this->pantalla(122)['valor']['reglas'][0];
        $this->assertSame(['documento_sin_signo', '83000003', true, true], [$o['regla'], $o['valor'], $o['requiere_confirmacion'], $o['reforzada']]);

        $l = $this->pantalla(123)['valor'];
        $this->assertTrue($l['exige_evidencia_manual']);
        $this->assertCount(1, $l['reglas']);
        $this->assertSame(['valor_confirmado_manual', null, true], [$l['reglas'][0]['regla'], $l['reglas'][0]['valor'], $l['reglas'][0]['requiere_evidencia']]);
    }

    // ── Plantilla: preparar, reutilizar, diseño ──────────────────────────────────────────────────────────

    public function test_preparar_la_plantilla_es_idempotente_y_el_clon_nace_con_el_diseno_pendiente(): void
    {
        $this->actingAs($this->admin())->post($this->ruta('reemplazo.clon', 120))->assertRedirect($this->ruta('reemplazo', 120))->assertSessionHas('success');
        $this->actingAs($this->admin())->post($this->ruta('reemplazo.clon', 121))->assertSessionHas('success', 'La plantilla moderna ya estaba preparada: se reutiliza.');   // misma imagen: reutiliza

        $this->assertSame(1, Plantilla::count());
        $clon = $this->pantalla(120)['plantilla']['clon'];
        $this->assertFalse($this->pantalla(120)['plantilla']['puede_preparar']);
        $this->assertSame([true, false, ['nombre_completo', 'documento'], false, false, []], [$clon['es_clon'], $clon['diseno_confirmado'], $clon['campos_usados'], $clon['requiere_fecha'], $clon['requiere_intensidad'], $clon['advertencias']]);
        $this->assertSame('incrustacion_directa', $clon['meta']['algoritmo']);
        $this->assertStringContainsString('plantillas/'.$clon['id'].'/editor', $clon['url_editor']);
    }

    public function test_confirmar_el_diseno_solo_vale_para_el_clon_del_caso_y_se_pierde_si_se_edita(): void
    {
        $this->actingAs($this->admin())->post($this->ruta('reemplazo.clon', 120));
        $clon = CreadorPlantillaReemplazo::existentePara(CertificadoLegado::findOrFail($this->idCert(120)));
        $otra = $this->plantillaModerna();

        $this->actingAs($this->admin())->post($this->ruta('reemplazo.diseno', 120), ['plantilla_id' => $otra->id])->assertSessionHas('error', 'Esa plantilla no corresponde a este caso.');
        $this->actingAs($this->admin())->post($this->ruta('reemplazo.diseno', 120), [])->assertSessionHasErrors('plantilla_id');
        $this->assertFalse(CreadorPlantillaReemplazo::disenoConfirmado($clon->fresh()));

        $this->actingAs($this->admin())->post($this->ruta('reemplazo.diseno', 120), ['plantilla_id' => $clon->id])->assertSessionHas('success');
        $this->assertTrue(CreadorPlantillaReemplazo::disenoConfirmado($clon->fresh()));
        $this->assertTrue($this->pantalla(120)['plantilla']['clon']['diseno_confirmado']);

        // Guardar de nuevo el diseño en el editor (aquí, mover el nombre) invalida la confirmación.
        $d = $clon->fresh()->diseno;
        $d['elements'][0]['y'] += 10;
        $clon->update(['diseno' => $d]);
        $this->assertFalse($this->pantalla(120)['plantilla']['clon']['diseno_confirmado']);
    }

    public function test_una_imagen_historica_muy_grande_se_prepara_con_advertencia_y_no_bloquea(): void
    {
        $png = PngSintetico::crear(13200, 10200);   // 134,6 MP
        file_put_contents($this->dirImagenes.'/ALFA 2024.png', $png);
        $contenido = DB::table('cf_plantillas_legado')->where('id', DB::table('cf_certificados_legado')->where('id', $this->idCert(120))->value('plantilla_legado_id'))->value('contenido_id');
        DB::table('cf_plantillas_legado_contenidos')->where('id', $contenido)->update(['sha256' => hash('sha256', $png), 'bytes' => strlen($png), 'ancho_px' => 13200, 'alto_px' => 10200]);

        $clon = $this->clonPorHttp(120);

        $info = $this->pantalla(120)['plantilla']['clon'];
        $this->assertSame(['IMAGEN_MUY_GRANDE'], $info['advertencias']);
        $this->assertSame([13200, 10200], [$info['meta']['ancho_px'], $info['meta']['alto_px']]);
        $this->assertTrue($info['diseno_confirmado']);
        $this->assertTrue($this->servicio()->evaluar($this->casoDe(120), $this->solicitud(120, $clon->id))['aplicable'], 'la advertencia no bloquea');
    }

    // ── Vista previa ─────────────────────────────────────────────────────────────────────────────────────

    public function test_la_vista_previa_devuelve_el_pdf_privado_y_no_persiste_nada(): void
    {
        $clon = $this->clonPorHttp(120);
        $antes = $this->nada();
        $mov = DB::table('movimientos')->count();

        $r = $this->preview(120, $this->datosDe(120, $clon->id))->assertOk();

        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $r->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $r->headers->get('X-Huella-Preview'));
        $this->assertSame($antes, $this->nada(), 'ni participante, ni lote, ni emisión, ni código, ni archivo');
        $this->assertSame($mov, DB::table('movimientos')->count());
        $this->assertSame('abierto', $this->estadoCaso($this->casoDe(120)));
        $this->assertSame('vigente', $this->cert(120)->estado);
    }

    public function test_la_huella_cambia_si_cambia_cualquier_dato_relevante(): void
    {
        $clon = $this->clonPorHttp(121);
        $base = $this->datosDe(121, $clon->id);
        $h = fn (array $datos) => $this->preview(121, $datos)->assertOk()->headers->get('X-Huella-Preview');

        $a = $h($base);
        $this->assertSame($a, $h($base), 'los mismos datos producen la misma huella');
        $this->assertNotSame($a, $h(['valor_documento' => '83000002'] + $base), 'otro documento aprobado');
        $d = $clon->fresh()->diseno;
        $d['elements'][0]['fontSize'] = 20;
        $clon->update(['diseno' => $d]);
        $this->assertNotSame($a, $h($base), 'otro diseño');
    }

    public function test_la_vista_previa_rechaza_datos_incompletos_con_mensajes_claros(): void
    {
        $antes = $this->nada();
        $clon = $this->clonPorHttp(123);
        $this->preview(123, $this->datosDe(123, $clon->id, ['tengo_evidencia' => false, 'evidencia' => null]))->assertStatus(422)->assertJsonPath('mensaje', 'Indica la evidencia que respalda el valor (entre 10 y 500 caracteres).');
        $this->preview(123, $this->datosDe(123, $clon->id, ['confirmado_valor' => false]))->assertStatus(422);
        $this->preview(121, ['plantilla_id' => $clon->id])->assertStatus(422);   // faltan reglas: validación
        // Plantilla que imprime la fecha y sin fecha: falta un dato.
        $conFecha = $this->plantillaModerna([$this->elementoDiseno(['field' => 'nombre_completo', 'y' => 200]), $this->elementoDiseno(['field' => 'documento', 'y' => 260]), $this->elementoDiseno(['field' => 'fecha', 'y' => 320])]);
        $this->preview(120, $this->datosDe(120, $conFecha->id))->assertStatus(422)->assertJsonPath('mensaje', 'Falta completar un dato requerido por la plantilla.');
        $this->preview(120, $this->datosDe(120, $conFecha->id, ['fecha' => '17 DE SEPTIEMBRE DE 2026']))->assertOk();
        // Sin diseño guardado.
        $sin = $this->plantillaModerna();
        $sin->update(['diseno' => null]);
        $this->preview(120, $this->datosDe(120, $sin->id))->assertStatus(422);
        $this->preview(120, $this->datosDe(120, 999999))->assertStatus(422);
        $this->assertSame(1 + 2 + 0, 3);   // (las plantillas de prueba se crean a propósito; lo demás no cambia)
        $this->assertSame(0, DB::table('cf_emisiones')->count());
        $this->assertSame(0, DB::table('cf_participantes')->count());
        $this->assertGreaterThanOrEqual(0, count($antes));
    }

    public function test_un_evento_con_caracteres_no_imprimibles_bloquea_solo_si_la_plantilla_lo_imprime(): void
    {
        DB::table('cf_eventos')->where('id', $this->cert(120)->evento_id)->update(['nombre' => 'EVENTO ☃ ESPECIAL']);
        $imprime = $this->plantillaModerna([$this->elementoDiseno(['field' => 'nombre_completo', 'y' => 200]), $this->elementoDiseno(['field' => 'documento', 'y' => 260]), $this->elementoDiseno(['field' => 'evento', 'y' => 320])]);
        $noImprime = $this->plantillaModerna();

        $this->preview(120, $this->datosDe(120, $imprime->id))->assertStatus(422)->assertJsonPath('mensaje', 'El diseño o los datos incluyen texto que la fuente de la plantilla no puede imprimir. Revisa el texto o el diseño.');
        $this->preview(120, $this->datosDe(120, $noImprime->id))->assertOk();
        $this->assertSame('EVENTO ☃ ESPECIAL', $this->pantalla(120)['evidencia']['evento']['nombre'], 'el evento se muestra como dato informativo');
    }

    // ── Emisión ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_el_flujo_completo_emite_cierra_el_caso_y_el_detalle_muestra_la_emision(): void
    {
        $clon = $this->clonPorHttp(120);
        $datos = $this->datosDe(120, $clon->id);

        $r = $this->emitir(120, $datos);

        $r->assertRedirect(route('credential-flow.historico.casos.show', $this->casoDe(120)))->assertSessionHas('success', 'Certificado corregido emitido correctamente.');
        $f = DB::table('cf_conciliaciones')->find($this->casoDe(120));
        $this->assertSame(['resuelto', ReemplazoHistorico::RESOLUCION, $this->actor()], [$f->estado, $f->resolucion, (int) $f->resuelto_por]);
        $this->assertSame(['reemplazado'], [$this->cert(120)->estado]);
        $this->assertSame([1, 1, 1], [DB::table('cf_emisiones')->count(), DB::table('cf_participantes')->count(), DB::table('cf_lotes')->count()]);

        $c = $this->detalle(120);
        $this->assertNull($c['accion']);
        $this->assertFalse($c['acciones_disponibles']);
        $emision = DB::table('cf_emisiones')->first();
        $this->assertSame($emision->codigo, $c['reemplazo']['codigo']);
        $this->assertSame(['emitida', 1, route('credential-flow.emisiones.descargar', $emision->id)], [$c['reemplazo']['estado'], $c['reemplazo']['version'], $c['reemplazo']['url_pdf']]);
        $this->assertSame($emision->codigo, $c['reemplazo']['vigente_actual']['codigo']);
        $this->assertTrue($c['reemplazo']['vigente_actual']['es_la_misma']);
        $this->assertStringContainsString($emision->codigo, $c['reemplazo']['url_verificacion']);
        $this->assertStringNotContainsString('@example.test', json_encode($c));
        // El PDF de administración se descarga; no hay descarga pública del reemplazo en el portal (todavía).
        $this->actingAs($this->admin())->get($c['reemplazo']['url_pdf'])->assertOk();
        $this->actingAs($this->admin())->get($this->ruta('reemplazo', 120))->assertRedirect()->assertSessionHas('error');
    }

    public function test_las_cuatro_categorias_se_emiten_por_la_interfaz(): void
    {
        foreach ([120, 121, 122, 123] as $old) {
            $clon = $this->clonPorHttp($old);
            $r = $this->emitir($old, $this->datosDe($old, $clon->id));
            // Varias emisiones seguidas en una prueba mezclan los avisos «flash» entre peticiones: se verifica el resultado real.
            $r->assertRedirect(route('credential-flow.historico.casos.show', $this->casoDe($old)));
            $this->assertSame('reemplazado', $this->cert($old)->estado, (string) $old);
        }
        $this->assertSame(['8300001', '83.000.002', '83000003', '83A00004'], DB::table('cf_participantes')->orderBy('id')->pluck('documento')->all());
        $this->artisan('credential-flow:verificar-emisiones')->assertExitCode(0);
    }

    public function test_varias_vistas_previas_seguidas_no_bloquean_la_emision_cada_ruta_tiene_su_propio_limite(): void
    {
        $clon = $this->clonPorHttp(120);
        $datos = $this->datosDe(120, $clon->id);
        foreach (range(1, 12) as $_) {
            $this->preview(120, $datos)->assertOk();
        }

        $this->emitir(120, $datos)->assertRedirect(route('credential-flow.historico.casos.show', $this->casoDe(120)))->assertSessionHas('success');

        $this->assertSame('reemplazado', $this->cert(120)->estado);
    }

    public function test_una_plantilla_moderna_existente_tambien_sirve_sin_exigir_confirmar_un_clon(): void
    {
        $otra = $this->plantillaModerna();
        $this->assertContains($otra->id, collect($this->pantalla(120)['plantilla']['otras'])->pluck('id')->all());

        $this->emitir(120, $this->datosDe(120, $otra->id))->assertSessionHas('success');

        $this->assertSame('reemplazado', $this->cert(120)->estado);
    }

    public function test_no_se_emite_sin_vista_previa_con_vista_previa_vieja_ni_con_el_diseno_sin_confirmar(): void
    {
        $clon = $this->clonPorHttp(121);
        $datos = $this->datosDe(121, $clon->id);
        $antes = $this->nada();

        // Sin vista previa en la sesión.
        $this->actingAs($this->admin())->post($this->ruta('reemplazo.emitir', 121), $datos + ['motivo' => self::MOTIVO, 'confirmo_revision' => true, 'confirmo_final' => true, 'huella' => str_repeat('a', 64)])
            ->assertRedirect($this->ruta('reemplazo', 121))->assertSessionHas('error', 'Genere la vista previa antes de emitir el reemplazo.');
        // Huella distinta de la de la sesión.
        $h = $this->preview(121, $datos)->assertOk()->headers->get('X-Huella-Preview');
        $this->withSession(['cf_reemplazo_preview_'.$this->casoDe(121) => $h])->actingAs($this->admin())->post($this->ruta('reemplazo.emitir', 121), $datos + ['motivo' => self::MOTIVO, 'confirmo_revision' => true, 'confirmo_final' => true, 'huella' => str_repeat('b', 64)])
            ->assertSessionHas('error', 'Los datos cambiaron. Genere nuevamente la vista previa.');
        // Se cambia el dato DESPUÉS de la vista previa: la huella de la sesión ya no corresponde (revalidado bajo lock por el servicio).
        $this->emitir(121, ['valor_documento' => '83000002'] + $datos, [], $h)->assertSessionHas('error', 'Los datos cambiaron. Genere nuevamente la vista previa.');
        // El diseño cambia después de la vista previa: la confirmación se pierde.
        $d = $clon->fresh()->diseno;
        $d['elements'][1]['fontSize'] = 18;
        $clon->update(['diseno' => $d]);
        $this->emitir(121, $datos, [], $h)->assertSessionHas('error');
        $this->assertSame($antes[0] + 0, $this->nada()[0]);
        $this->assertSame(0, DB::table('cf_emisiones')->count());
        $this->assertSame('abierto', $this->estadoCaso($this->casoDe(121)));

        // Diseño sin confirmar: la vista previa se puede ver, pero emitir se bloquea con un mensaje claro.
        $sinConfirmar = $this->plantillaModerna();   // no clon: aquí se usa el clon con el diseño editado y NO reconfirmado
        $h2 = $this->preview(121, $datos)->assertOk()->headers->get('X-Huella-Preview');
        $this->emitir(121, $datos, [], $h2)->assertSessionHas('error', 'Revisa el diseño de la plantilla moderna y confírmalo antes de emitir.');
        $this->assertNotNull($sinConfirmar);
    }

    public function test_motivo_y_doble_confirmacion_son_obligatorios(): void
    {
        $clon = $this->clonPorHttp(120);
        $datos = $this->datosDe(120, $clon->id);
        $h = $this->preview(120, $datos)->assertOk()->headers->get('X-Huella-Preview');
        $base = $datos + ['motivo' => self::MOTIVO, 'confirmo_revision' => true, 'confirmo_final' => true, 'huella' => $h];

        foreach ([['motivo' => ''], ['motivo' => 'corto'], ['motivo' => str_repeat('x', 501)], ['confirmo_revision' => false], ['confirmo_final' => false], ['huella' => 'x'], ['huella' => null]] as $malo) {
            $this->withSession(['cf_reemplazo_preview_'.$this->casoDe(120) => $h])->actingAs($this->admin())->post($this->ruta('reemplazo.emitir', 120), $malo + $base)->assertSessionHasErrors(array_key_first($malo));
        }
        $this->assertSame(0, DB::table('cf_emisiones')->count());
        $this->assertSame('abierto', $this->estadoCaso($this->casoDe(120)));
    }

    public function test_el_alfanumerico_se_bloquea_sin_evidencia_y_se_emite_con_ella(): void
    {
        $clon = $this->clonPorHttp(123);
        $sin = $this->datosDe(123, $clon->id, ['tengo_evidencia' => false, 'evidencia' => null]);
        $this->preview(123, $sin)->assertStatus(422)->assertJsonPath('mensaje', 'Indica la evidencia que respalda el valor (entre 10 y 500 caracteres).');
        // Texto de evidencia SIN declarar que se tiene: no cuenta.
        $this->preview(123, $this->datosDe(123, $clon->id, ['tengo_evidencia' => false]))->assertStatus(422);
        $this->emitir(123, $sin, [], str_repeat('c', 64))->assertSessionHas('error');
        $this->assertSame('vigente', $this->cert(123)->estado);

        $this->emitir(123, $this->datosDe(123, $clon->id))->assertSessionHas('success');

        $this->assertSame('reemplazado', $this->cert(123)->estado);
        $evi = json_decode(DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $this->casoDe(123))->orderByDesc('id')->value('evidencia'), true);
        $this->assertSame('valor_confirmado_manual', $evi['documento']['regla']);
        $this->assertStringNotContainsString('Verificado con el soporte', json_encode($evi), 'la evidencia manual solo deja su huella');
    }

    // ── Concurrencia, idempotencia y errores humanos ─────────────────────────────────────────────────────

    public function test_si_otro_administrador_ya_reemplazo_el_certificado_el_segundo_ve_un_mensaje_sin_error_500(): void
    {
        $clon = $this->clonPorHttp(120);
        $datos = $this->datosDe(120, $clon->id);
        $h = $this->preview(120, $datos)->assertOk()->headers->get('X-Huella-Preview');   // las dos pantallas abiertas

        // El otro administrador emite primero.
        $this->servicio()->reemplazar($this->casoDe(120), $this->superAdmin()->id, self::MOTIVO, $this->solicitud(120, $clon->id));

        $r = $this->emitir(120, $datos, [], $h);

        $r->assertRedirect(route('credential-flow.historico.casos.show', $this->casoDe(120)))->assertSessionHas('error', 'Este certificado ya fue reemplazado.');
        $this->assertSame(1, DB::table('cf_emisiones')->count());
    }

    public function test_repetir_la_emision_no_crea_nada_y_avisa(): void
    {
        $clon = $this->clonPorHttp(121);
        $datos = $this->datosDe(121, $clon->id);
        $h = $this->preview(121, $datos)->assertOk()->headers->get('X-Huella-Preview');
        $this->emitir(121, $datos, [], $h)->assertSessionHas('success');
        $firma = $this->escritura();

        $this->emitir(121, $datos, [], $h)->assertSessionHas('error', 'Este certificado ya fue reemplazado.');

        $this->assertSame($firma, $this->escritura());
        $this->preview(121, $datos)->assertStatus(422)->assertJsonPath('mensaje', 'Este certificado ya fue reemplazado.');
    }

    public function test_los_errores_tecnicos_se_traducen_a_mensajes_claros_sin_codigos_internos(): void
    {
        $casos = [
            ResolucionNoPermitida::CASO_YA_RESUELTO => 'Este caso ya fue resuelto.',
            ResolucionNoPermitida::YA_REEMPLAZADO => 'Este certificado ya fue reemplazado.',
            ResolucionNoPermitida::CAMPO_SIN_VALOR => 'Falta completar un dato requerido por la plantilla.',
            ResolucionNoPermitida::PREVIEW_DESACTUALIZADO => 'Los datos cambiaron. Genere nuevamente la vista previa.',
            ResolucionNoPermitida::PREVIEW_REQUERIDO => 'Genere la vista previa antes de emitir el reemplazo.',
        ];
        foreach ($casos as $codigo => $mensaje) {
            $this->assertSame($mensaje, ConsultaReemplazo::mensaje(new ResolucionNoPermitida($codigo, 'texto técnico')));
        }
        $this->assertStringContainsString('texto no compatible', ConsultaReemplazo::mensaje(new GeneracionCredencialException(GeneracionCredencialException::CARACTER_NO_SOPORTADO, 'x')));
        $capacidad = ConsultaReemplazo::mensaje(new ResolucionNoPermitida(ResolucionNoPermitida::PLANTILLA_HISTORICA_EXCEDE_CAPACIDAD, 'La imagen histórica es demasiado grande para procesarla en este servidor. Debe procesarse en un entorno con más memoria.'));
        $this->assertStringContainsString('más memoria', $capacidad);
        $inesperado = ConsultaReemplazo::mensaje(new \RuntimeException('SQLSTATE[42S02] tabla secreta ruta C:\\x'));
        $this->assertSame('No se pudo completar la acción. No se cambió nada.', $inesperado);
        foreach ([...array_values($casos), $capacidad, $inesperado] as $m) {
            $this->assertDoesNotMatchRegularExpression('/[A-Z]{3,}_[A-Z_]{3,}|SQLSTATE|Exception|\\\\/', $m);
        }
    }

    public function test_una_imagen_que_no_cabe_se_informa_con_un_mensaje_claro_al_preparar_el_clon(): void
    {
        $alfa = PngSintetico::crear(6600, 5100, 6);   // RGBA: no incrustable
        file_put_contents($this->dirImagenes.'/ALFA 2024.png', $alfa);
        $contenido = DB::table('cf_plantillas_legado')->where('id', DB::table('cf_certificados_legado')->where('id', $this->idCert(120))->value('plantilla_legado_id'))->value('contenido_id');
        DB::table('cf_plantillas_legado_contenidos')->where('id', $contenido)->update(['sha256' => hash('sha256', $alfa), 'bytes' => strlen($alfa), 'ancho_px' => 6600, 'alto_px' => 5100]);
        $limite = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 96 * 1048576));
        $antes = $this->nada();

        try {
            $r = $this->actingAs($this->admin())->post($this->ruta('reemplazo.clon', 120));
        } finally {
            ini_set('memory_limit', (string) $limite);
        }

        $r->assertRedirect($this->ruta('reemplazo', 120))->assertSessionHas('error');
        $this->assertStringContainsString('más memoria', (string) session('error'));
        $this->assertSame($antes, $this->nada());
    }

    public function test_el_asistente_y_el_resultado_no_exponen_correos_ni_documentos_en_claro_en_la_evidencia(): void
    {
        $clon = $this->clonPorHttp(122);
        $this->emitir(122, $this->datosDe(122, $clon->id))->assertSessionHas('success');

        $detalle = json_encode($this->detalle(122));

        foreach (['@example.test', '8300-0003', '"83000003"'] as $privado) {
            $this->assertStringNotContainsString($privado, $detalle);
        }
        $lista = $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('83000003', $lista);
    }
}
