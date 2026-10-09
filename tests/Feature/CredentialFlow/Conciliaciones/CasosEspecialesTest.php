<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\CasosEspeciales;
use App\Support\CredentialFlow\Conciliaciones\EvidenciaExterna;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Reemplazo\ReemplazoHistorico;
use App\Support\CredentialFlow\Reemplazo\ReglasValorAprobado;
use App\Support\CredentialFlow\Reemplazo\SolicitudReemplazo;
use Illuminate\Support\Facades\DB;

/**
 * Fase 10B-3C-4: casos ESPECIALES que no se resuelven automáticamente. Clasificación (solo lectura), evidencia externa (log append-only, sin schema), reapertura de un
 * soporte con evidencia nueva, documento vacío con nombre (reemplazo manual administrativo, sin identidad de portal) y «portal sin cambios».
 * Fixture: 106 DOC_VACIO con nombre · 107 DOC_VACIO sin nombre · 108 DOC_LETRAS texto · 120 whitespace · 104/105 DIF_NOMBRE real · 102/103 cosmético.
 */
class CasosEspecialesTest extends ReemplazoTestCase
{
    private const FUENTE = 'registro_validado';

    private const RESUMEN = 'Certificación escrita del organizador sobre este registro histórico.';

    private function especiales(): CasosEspeciales
    {
        return app(CasosEspeciales::class);
    }

    private function ev(): EvidenciaExterna
    {
        return app(EvidenciaExterna::class);
    }

    private function caso(int $old): Conciliacion
    {
        return Conciliacion::findOrFail($this->casoDe($old));
    }

    private function rechazo(callable $f): ResolucionNoPermitida
    {
        try {
            $f();
        } catch (ResolucionNoPermitida $e) {
            return $e;
        }
        $this->fail('Debía rechazarse.');
    }

    /** Lo que el portal concede hoy a cada (documento, correo) del histórico. */
    private function alcances(): string
    {
        $acceso = new AccesoPortal;
        $r = [];
        foreach (DB::table('cf_correos as r')->join('cf_certificados_legado as k', 'k.id', '=', 'r.certificado_legado_id')->where('r.estado', 'valido')->distinct()->get(['k.documento_clave', 'r.correo_normalizado']) as $p) {
            // Solo lo que el portal puede pedir de verdad: un documento vacío o demasiado corto no produce clave (`claveDocumento` = null) y nunca llega al gate.
            if (AccesoPortal::claveDocumento((string) $p->documento_clave) !== (string) $p->documento_clave) {
                continue;
            }
            $r[$p->documento_clave.'|'.$p->correo_normalizado] = $acceso->alcance((string) $p->documento_clave, (string) $p->correo_normalizado);
        }
        ksort($r);

        return md5(json_encode($r));
    }

    // ── Clasificación ────────────────────────────────────────────────────────

    public function test_clasifica_cada_caso_con_su_categoria_y_destino(): void
    {
        $esperado = [
            106 => ['documento_vacio_con_nombre', CasosEspeciales::PEND_EXTERNA, true], 107 => ['documento_vacio_sin_evidencia', CasosEspeciales::DESCARTABLE, true],
            108 => ['documento_texto_no_identificador', CasosEspeciales::PEND_EXTERNA, true], 120 => ['documento_recuperable', CasosEspeciales::RECUP_REEMPLAZO, false],
            104 => ['dif_nombre_real', CasosEspeciales::RECUP_REEMPLAZO, false], 102 => ['variantes_consolidables', CasosEspeciales::RECUP_CONSOLIDACION, false],
        ];
        foreach ($esperado as $old => [$categoria, $destino, $especial]) {
            $c = $this->especiales()->clasificar($this->caso($old));
            $this->assertSame([$categoria, $destino, $especial], [$c['categoria'], $c['destino'], $c['especial']], (string) $old);
        }
    }

    public function test_una_plantilla_invalida_es_dependencia_tecnica_no_identidad(): void
    {
        foreach ([Conciliacion::TIPO_PLANTILLA_FALTANTE, Conciliacion::TIPO_PLANTILLA_CANDIDATA, Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO] as $tipo) {
            $caso = Conciliacion::create(['tipo' => $tipo, 'estado' => 'abierto', 'referencia_tipo' => 'plantilla_legado', 'referencia_clave' => 'x', 'motivo_origen' => 'ARCHIVO_FALTANTE', 'clave_idempotencia' => 'k:'.$tipo]);
            $c = $this->especiales()->clasificar($caso);
            $this->assertSame(['dependencia_tecnica', CasosEspeciales::DEP_TECNICA, 'tecnica', false], [$c['categoria'], $c['destino'], $c['dependencia'], $c['admite_evidencia']]);
            $this->assertStringContainsString('plantilla', mb_strtolower((string) $c['falta']));
            $this->assertSame(0, DB::table('cf_decisiones_identidad')->count(), 'no se crea ninguna decisión');
        }
    }

    public function test_el_estado_real_manda_sobre_el_destino_proyectado(): void
    {
        DB::table('cf_conciliaciones')->where('id', $this->casoDe(107))->update(['estado' => 'descartado']);
        DB::table('cf_conciliaciones')->where('id', $this->casoDe(120))->update(['estado' => 'resuelto']);
        $this->assertSame(CasosEspeciales::DESCARTADO, $this->especiales()->clasificar($this->caso(107))['destino']);
        $this->assertSame([CasosEspeciales::RESUELTO, false], [$this->especiales()->clasificar($this->caso(120))['destino'], $this->especiales()->clasificar($this->caso(120))['admite_evidencia']]);
    }

    public function test_la_foto_global_no_tiene_pii_y_cuenta_todos_los_casos(): void
    {
        $foto = $this->especiales()->foto();
        $this->assertSame(Conciliacion::count(), $foto['casos']);
        $this->assertSame($foto['casos'], array_sum($foto['por_destino']));
        $this->assertSame($foto['casos'], array_sum($foto['por_estado']));
        $json = json_encode($foto, JSON_UNESCAPED_UNICODE);
        foreach (['@', 'PERSONA', 'MARIA', 'ANA LUZ'] as $pii) {
            $this->assertStringNotContainsString($pii, $json);
        }
    }

    // ── Evidencia externa ────────────────────────────────────────────────────

    public function test_registra_evidencia_externa_sin_cambiar_estado_ni_portal(): void
    {
        $antesPortal = $this->alcances();
        $caso = $this->caso(106);
        $estado = $caso->estado;
        $r = $this->ev()->registrar($caso->id, $this->actor(), self::FUENTE, self::RESUMEN);

        $this->assertSame([hash('sha256', self::RESUMEN), mb_strlen(self::RESUMEN)], [$r['sha256'], $r['longitud']]);
        $this->assertSame($estado, $caso->fresh()->estado, 'el estado del caso no cambia');
        $this->assertSame($antesPortal, $this->alcances(), 'el portal no cambia');
        $e = DB::table('cf_conciliaciones_eventos')->find($r['evento_id']);
        $ev = json_decode($e->evidencia, true);
        $this->assertSame([EvidenciaExterna::ACCION_REGISTRADA, $this->actor(), self::FUENTE, false], [$e->accion, (int) $e->actor_id, $ev['fuente'], $ev['efecto_portal']]);
        $this->assertSame(CasosEspeciales::SOPORTE, $this->especiales()->clasificar($this->caso(106))['destino'], 'con evidencia, lo que sigue es una decisión administrativa en soporte');
        $lista = EvidenciaExterna::delCaso($caso->id);
        $this->assertSame(['vigente', self::FUENTE], [$lista[0]['estado'], $lista[0]['fuente']]);
        $mov = DB::table('movimientos')->latest('id')->first();
        $this->assertStringNotContainsString('Certificación', json_encode($mov), 'el resumen no va a movimientos');
    }

    public function test_exige_fuente_resumen_en_rango_administrador_y_caso_que_la_admita(): void
    {
        $id = $this->casoDe(106);
        $this->assertSame(ResolucionNoPermitida::FUENTE_EVIDENCIA_REQUERIDA, $this->rechazo(fn () => $this->ev()->registrar($id, $this->actor(), 'inventada', self::RESUMEN))->codigo);
        foreach (['corta', str_repeat('x', 1001)] as $mal) {
            $this->assertSame(ResolucionNoPermitida::EVIDENCIA_REQUERIDA, $this->rechazo(fn () => $this->ev()->registrar($id, $this->actor(), self::FUENTE, $mal))->codigo);
        }
        $this->ev()->registrar($id, $this->actor(), self::FUENTE, str_repeat('x', 1000));   // el límite de 1000 sí
        $this->assertSame(ResolucionNoPermitida::ACTOR_NO_AUTORIZADO, $this->rechazo(fn () => $this->ev()->registrar($id, $this->comercial()->id, self::FUENTE, self::RESUMEN))->codigo);
        // Un caso que se resuelve con las acciones normales no admite evidencia externa.
        $this->assertSame(ResolucionNoPermitida::TIPO_NO_ADMITIDO, $this->rechazo(fn () => $this->ev()->registrar($this->casoDe(120), $this->actor(), self::FUENTE, self::RESUMEN))->codigo);
        // Uno cerrado, tampoco.
        DB::table('cf_conciliaciones')->where('id', $id)->update(['estado' => 'resuelto']);
        $this->assertSame(ResolucionNoPermitida::CASO_YA_RESUELTO, $this->rechazo(fn () => $this->ev()->registrar($id, $this->actor(), self::FUENTE, self::RESUMEN))->codigo);
    }

    public function test_invalidar_conserva_la_evidencia_y_no_se_puede_dos_veces_ni_en_otro_caso(): void
    {
        $r = $this->ev()->registrar($this->casoDe(106), $this->actor(), self::FUENTE, self::RESUMEN);
        $otro = $this->ev()->registrar($this->casoDe(108), $this->actor(), self::FUENTE, self::RESUMEN);
        $this->assertSame(ResolucionNoPermitida::TIPO_NO_ADMITIDO, $this->rechazo(fn () => $this->ev()->invalidar($this->casoDe(106), $this->actor(), $otro['evento_id'], 'No es de este caso.'))->codigo);

        $this->ev()->invalidar($this->casoDe(106), $this->actor(), $r['evento_id'], 'La fuente no era confiable.');

        $lista = EvidenciaExterna::delCaso($this->casoDe(106));
        $this->assertSame(['invalidada', self::RESUMEN], [$lista[0]['estado'], $lista[0]['resumen']], 'la evidencia original se conserva');
        $this->assertFalse(EvidenciaExterna::tieneVigente($this->casoDe(106)));
        $this->assertSame(CasosEspeciales::PEND_EXTERNA, $this->especiales()->clasificar($this->caso(106))['destino'], 'sin evidencia vigente vuelve a pendiente');
        $this->assertSame(ResolucionNoPermitida::DECISION_YA_REVOCADA, $this->rechazo(fn () => $this->ev()->invalidar($this->casoDe(106), $this->actor(), $r['evento_id'], 'Otra vez por la prueba.'))->codigo);
    }

    // ── Soporte reversible ───────────────────────────────────────────────────

    public function test_un_soporte_sin_evidencia_nueva_no_se_reabre_desde_la_pantalla(): void
    {
        $id = $this->casoDe(106);
        $this->gestion()->marcarRequiereSoporte($id, $this->actor(), 'Sin documento: se manda a soporte.');
        $this->assertSame('requiere_soporte', $this->estadoCaso($id));
        $this->assertFalse($this->especiales()->puedeReabrir($this->caso(106)), 'sin evidencia nueva no se reabre');
        $this->actingAs($this->admin())->post(route('credential-flow.historico.casos.especial.reabrir', $id), ['motivo' => 'Intento sin evidencia nueva.'])->assertSessionHas('error');
        $this->assertSame('requiere_soporte', $this->estadoCaso($id));
        // La evidencia registrada ANTES del soporte tampoco cuenta como «nueva».
        $otro = $this->casoDe(108);
        $this->ev()->registrar($otro, $this->actor(), self::FUENTE, self::RESUMEN);
        $this->gestion()->marcarRequiereSoporte($otro, $this->actor(), 'No es un identificador: soporte.');
        $this->assertFalse($this->especiales()->puedeReabrir($this->caso(108)));
    }

    public function test_reabrir_tras_evidencia_nueva_agrega_la_transicion_sin_borrar_nada(): void
    {
        $id = $this->casoDe(106);
        $this->gestion()->marcarRequiereSoporte($id, $this->actor(), 'Sin documento: se manda a soporte.');
        $e = $this->ev()->registrar($id, $this->actor(), 'documento_correcto', self::RESUMEN);
        $this->assertTrue($this->especiales()->puedeReabrir($this->caso(106)));

        $this->actingAs($this->admin())->post(route('credential-flow.historico.casos.especial.reabrir', $id), ['motivo' => 'Llegó el documento correcto por fuera.'])->assertSessionHas('success');

        $this->assertSame('abierto', $this->estadoCaso($id));
        $acciones = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $id)->orderBy('id')->pluck('accion')->all();
        $this->assertSame(['detectado', 'conciliacion_requiere_soporte', EvidenciaExterna::ACCION_REGISTRADA, 'conciliacion_reabierta'], $acciones, 'el soporte anterior y la evidencia se conservan');
        // Con la evidencia invalidada, un nuevo soporte no se reabre.
        $this->gestion()->marcarRequiereSoporte($id, $this->actor(), 'Otra vez a soporte por la prueba.');
        $this->ev()->invalidar($id, $this->actor(), $e['evento_id'], 'Se invalida la evidencia anterior.');
        $this->assertFalse($this->especiales()->puedeReabrir($this->caso(106)));
        $this->actingAs($this->admin())->post(route('credential-flow.historico.casos.especial.reabrir', $id), ['motivo' => 'Intento sin evidencia vigente.'])->assertSessionHas('error');
        $this->assertSame('requiere_soporte', $this->estadoCaso($id));
    }

    // ── Documento vacío: reemplazo manual administrativo ─────────────────────

    private function solicitudVacio(int $plantillaId, array $c = []): SolicitudReemplazo
    {
        return new SolicitudReemplazo(...array_merge(['plantillaId' => $plantillaId, 'reglaDocumento' => ReglasValorAprobado::DOC_MANUAL, 'valorDocumento' => '8300777', 'confirmado' => true, 'evidencia' => 'Documento aportado por la persona y validado por soporte.'], $c));
    }

    public function test_un_documento_vacio_no_se_corrige_sin_el_documento_correcto_registrado(): void
    {
        $plantilla = $this->clon(106);
        $antes = $this->escritura();
        $this->assertFalse($this->servicio()->elegible($this->caso(106))['elegible']);
        $this->assertSame(ResolucionNoPermitida::EVIDENCIA_REQUERIDA, $this->rechazo(fn () => $this->servicio()->reemplazar($this->casoDe(106), $this->actor(), self::MOTIVO, $this->solicitudVacio($plantilla->id)))->codigo);
        // Evidencia de OTRA fuente no basta: tiene que ser el documento correcto aportado.
        $this->ev()->registrar($this->casoDe(106), $this->actor(), 'propiedad_buzon', self::RESUMEN);
        $this->assertFalse($this->servicio()->elegible($this->caso(106))['elegible']);
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $this->casoDe(106))->where('accion', EvidenciaExterna::ACCION_REGISTRADA)->count());
        $this->assertSame(0, DB::table('cf_emisiones')->count());
        $this->assertNotSame($antes, $this->escritura(), 'solo cambió el log de evidencia');
    }

    public function test_con_el_documento_correcto_registrado_se_emite_un_certificado_corregido_sin_abrir_el_portal(): void
    {
        $plantilla = $this->clon(106);
        $portal = $this->alcances();
        $this->ev()->registrar($this->casoDe(106), $this->actor(), 'documento_correcto', self::RESUMEN);
        $this->assertTrue($this->servicio()->elegible($this->caso(106))['elegible']);
        $this->assertSame(CasosEspeciales::SOPORTE, $this->especiales()->clasificar($this->caso(106))['destino']);

        $r = $this->servicio()->reemplazar($this->casoDe(106), $this->actor(), self::MOTIVO, $this->solicitudVacio($plantilla->id));

        $p = DB::table('cf_participantes')->find($r['participante_id']);
        $this->assertSame('8300777', $p->documento);
        $cert = DB::table('cf_certificados_legado')->where('id', $this->idCert(106))->first();
        $this->assertSame(['reemplazado', $r['emision_id']], [$cert->estado, (int) $cert->reemplazado_por_emision_id]);
        $this->assertSame('', trim((string) $cert->documento_clave), 'el histórico no se modifica: sigue sin documento');
        $this->assertSame('resuelto', $this->estadoCaso($this->casoDe(106)));
        $this->assertSame($portal, $this->alcances(), 'el portal no cambia: el histórico vacío sigue sin poder autenticarse');
        $this->assertNull(AccesoPortal::claveDocumento((string) $cert->documento));
        // La entrega es administrativa: la emisión se descarga desde administración (no hay enlace público).
        $this->actingAs($this->admin())->get(route('credential-flow.emisiones.descargar', $r['emision_id']))->assertOk();
        $ev = json_decode((string) DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $this->casoDe(106))->where('accion', ReemplazoHistorico::ACCION)->value('evidencia'), true);
        $this->assertStringNotContainsString('8300777', json_encode($ev));
    }

    // ── Pantalla, HTTP y privacidad ──────────────────────────────────────────

    public function test_el_detalle_muestra_el_caso_especial_con_lo_que_falta_y_sin_pii(): void
    {
        $this->ev()->registrar($this->casoDe(106), $this->actor(), self::FUENTE, self::RESUMEN);
        $r = $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.show', $this->casoDe(106)))->assertOk();
        $e = $r->viewData('page')['props']['caso']['especial'];
        $this->assertSame('Este caso no puede resolverse automáticamente.', $e['titulo']);
        $this->assertSame(['documento_vacio_con_nombre', true], [$e['categoria'], $e['puede_registrar_evidencia']]);
        $this->assertNotEmpty($e['falta']);
        $this->assertNotEmpty($e['siguiente']);
        $this->assertCount(1, $e['evidencias']);
        $this->assertArrayHasKey($e['evidencias'][0]['id'], $e['rutas']['invalidar']);
        // Un caso normal no recibe el bloque.
        $normal = $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.show', $this->casoDe(120)))->viewData('page')['props']['caso'];
        $this->assertNull($normal['especial']);
        // El listado no lleva datos personales ni el resumen de la evidencia.
        $lista = json_encode($this->actingAs($this->admin())->get(route('credential-flow.historico.casos.index'))->assertOk()->viewData('page')['props'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Certificación escrita', $lista);
        $this->assertStringNotContainsString('PERSONA', $lista);
    }

    public function test_las_rutas_exigen_rol_validan_y_no_cambian_el_portal(): void
    {
        $id = $this->casoDe(106);
        $ruta = route('credential-flow.historico.casos.especial.evidencia', $id);
        $portal = $this->alcances();
        $this->actingAs($this->comercial())->post($ruta, ['fuente' => self::FUENTE, 'resumen' => self::RESUMEN])->assertForbidden();
        $this->actingAs($this->admin())->post($ruta, ['fuente' => 'x', 'resumen' => self::RESUMEN])->assertSessionHasErrors('fuente');
        $this->actingAs($this->admin())->post($ruta, ['fuente' => self::FUENTE, 'resumen' => 'corto'])->assertSessionHasErrors('resumen');
        $this->actingAs($this->admin())->post($ruta, ['fuente' => self::FUENTE, 'resumen' => self::RESUMEN])->assertSessionHas('success');
        $evento = (int) DB::table('cf_conciliaciones_eventos')->where('accion', EvidenciaExterna::ACCION_REGISTRADA)->value('id');
        $this->actingAs($this->admin())->post(route('credential-flow.historico.casos.especial.invalidar-evidencia', [$id, $evento]), ['motivo' => 'La evidencia no era válida.'])->assertSessionHas('success');
        $this->assertSame($portal, $this->alcances());
        $this->assertSame(0, DB::table('cf_accesos_otp')->count());
    }

    public function test_la_verificacion_publica_no_cambia_por_clasificar_ni_registrar_evidencia(): void
    {
        DB::table('cf_certificados_legado')->where('id', $this->idCert(108))->update(['codigo_legado' => '9108']);
        $antes = $this->get('/verificar/9108')->getContent();
        $this->ev()->registrar($this->casoDe(108), $this->actor(), self::FUENTE, self::RESUMEN);
        $this->gestion()->marcarRequiereSoporte($this->casoDe(108), $this->actor(), 'No es un identificador: soporte.');
        $despues = $this->get('/verificar/9108')->getContent();
        $this->assertSame(preg_replace('/nonce="[^"]+"|name="_token" value="[^"]+"/', '', $antes), preg_replace('/nonce="[^"]+"|name="_token" value="[^"]+"/', '', $despues));
        $this->assertStringNotContainsString('soporte', mb_strtolower($despues));
    }
}
