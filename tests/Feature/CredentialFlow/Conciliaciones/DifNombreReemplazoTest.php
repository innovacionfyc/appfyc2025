<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Mail\CodigoAccesoMail;
use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\Identidad\DecisionesIdentidad;
use App\Support\CredentialFlow\Identidad\EvidenciaIdentidad;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Reemplazo\CertificadoLogico;
use App\Support\CredentialFlow\Reemplazo\EmisionVigente;
use App\Support\CredentialFlow\Reemplazo\ReemplazoHistorico;
use App\Support\CredentialFlow\Reemplazo\ReglasValorAprobado;
use App\Support\CredentialFlow\Reemplazo\SolicitudReemplazo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fase 10B-3C-2 — DIF_NOMBRE: el nombre impreso se APRUEBA (variante histórica exacta o nombre externo con evidencia) y se emite UN reemplazo moderno
 * del certificado lógico. Fixture: 104 «ANA LUZ UNO» + 105 «ANA LUZ UNO DOS» (mismo documento, evento y correo; un nombre contiene al otro).
 * La identidad (quién es la persona, qué correo entra) es un eje SEPARADO: aprobar el nombre no decide nada de eso.
 */
class DifNombreReemplazoTest extends ReemplazoTestCase
{
    private const A = 'ANA LUZ UNO';

    private const B = 'ANA LUZ UNO DOS';

    private const EVIDENCIA = 'Acta de asistencia firmada por la persona, folio 12 del archivo físico.';

    private function caso(): int
    {
        return $this->casoDe(104);
    }

    private function sol(int $plantillaId, array $c = []): SolicitudReemplazo
    {
        return new SolicitudReemplazo(...array_merge([
            'plantillaId' => $plantillaId, 'reglaDocumento' => ReglasValorAprobado::DOC_SIN_CAMBIO, 'reglaNombre' => ReglasValorAprobado::NOMBRE_CONFIRMADO,
            'valorNombre' => self::B, 'confirmado' => true, 'evidencia' => self::EVIDENCIA,
        ], $c));
    }

    private function emitirDif(array $c = [], ?string $motivo = null): array
    {
        $plantilla = $this->clon(104);

        return $this->servicio()->reemplazar($this->caso(), $this->actor(), $motivo ?? self::MOTIVO, $this->sol($plantilla->id, $c));
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

    public function test_el_par_sintetico_es_un_unico_certificado_logico_y_el_caso_es_elegible(): void
    {
        $ids = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $this->caso())->pluck('certificado_legado_id')->map(fn ($i) => (int) $i)->all();
        $this->assertCount(2, $ids);
        $l = CertificadoLogico::de($ids[0]);
        $this->assertEqualsCanonicalizing($ids, $l['miembros']);
        $this->assertSame(Conciliacion::TIPO_CONFLICTO_VARIANTES, DB::table('cf_conciliaciones')->find($this->caso())->tipo);
        $this->assertSame('DIF_NOMBRE', DB::table('cf_conciliaciones')->find($this->caso())->motivo_origen);
        $this->assertTrue($this->servicio()->elegible(Conciliacion::findOrFail($this->caso()))['elegible']);
    }

    public function test_la_variacion_cosmetica_y_los_conflictos_mixtos_no_entran(): void
    {
        foreach ([102, 100, 90] as $old) {
            $e = $this->servicio()->elegible(Conciliacion::findOrFail($this->casoDe($old)));
            $this->assertFalse($e['elegible'], "el participante $old no entra");
        }
    }

    public function test_el_nombre_nunca_se_elige_por_defecto(): void
    {
        $plantilla = $this->clon(104);
        $antes = $this->escritura();
        $e = $this->rechazo(fn () => $this->servicio()->reemplazar($this->caso(), $this->actor(), self::MOTIVO, $this->sol($plantilla->id, ['reglaNombre' => ReglasValorAprobado::NOMBRE_HISTORICO, 'valorNombre' => null])));
        $this->assertSame(ResolucionNoPermitida::REGLA_NO_APLICA, $e->codigo);
        $this->assertSame($antes, $this->escritura());
    }

    public function test_exige_valor_confirmacion_y_evidencia_entre_10_y_1000(): void
    {
        $plantilla = $this->clon(104);
        $antes = $this->escritura();
        $casos = [
            [['valorNombre' => null], ResolucionNoPermitida::VALOR_NO_VALIDO],
            [['confirmado' => false], ResolucionNoPermitida::CONFIRMACION_REQUERIDA],
            [['evidencia' => null], ResolucionNoPermitida::EVIDENCIA_REQUERIDA],
            [['evidencia' => 'corta'], ResolucionNoPermitida::EVIDENCIA_REQUERIDA],
            [['evidencia' => str_repeat('x', 1001)], ResolucionNoPermitida::EVIDENCIA_REQUERIDA],
            [['valorNombre' => 'ana luz minuscula'], ResolucionNoPermitida::VALOR_NO_VALIDO],
            [['valorNombre' => "ANA \u{1F600} LUZ"], ResolucionNoPermitida::VALOR_NO_VALIDO],
        ];
        foreach ($casos as [$cambios, $codigo]) {
            $e = $this->rechazo(fn () => $this->servicio()->reemplazar($this->caso(), $this->actor(), self::MOTIVO, $this->sol($plantilla->id, $cambios)));
            $this->assertSame($codigo, $e->codigo, json_encode($cambios, JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame($antes, $this->escritura());

        // El límite superior de la evidencia (1000) sí se acepta.
        $r = $this->servicio()->reemplazar($this->caso(), $this->actor(), self::MOTIVO, $this->sol($plantilla->id, ['evidencia' => str_repeat('x', 1000)]));
        $this->assertGreaterThan(0, $r['emision_id']);
    }

    /** QA de AMBAS grafías: cada una, aprobada a propósito, queda impresa tal cual. */
    #[DataProvider('grafias')]
    public function test_emite_con_cualquiera_de_las_dos_variantes_historicas_aprobada(string $nombre, string $otra): void
    {
        $ids = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $this->caso())->pluck('certificado_legado_id')->map(fn ($i) => (int) $i)->all();
        $r = $this->emitirDif(['valorNombre' => $nombre]);

        $p = DB::table('cf_participantes')->find($r['participante_id']);
        $this->assertSame($nombre, $p->nombre_completo);
        $this->assertNotSame($otra, $p->nombre_completo);
        $this->assertSame(DB::table('cf_certificados_legado')->where('id', $ids[0])->value('documento'), $p->documento, 'DOCUMENTO_SIN_CAMBIO');

        $this->assertSame(1, DB::table('cf_emisiones')->count());
        $raiz = CertificadoLogico::de($ids[0])['raiz'];
        $rows = DB::table('cf_certificados_legado')->whereIn('id', $ids)->get()->keyBy('id');
        $this->assertSame(['reemplazado', $r['emision_id']], [$rows[$raiz]->estado, (int) $rows[$raiz]->reemplazado_por_emision_id]);
        foreach (array_diff($ids, [$raiz]) as $v) {
            $this->assertSame(['vigente', null], [$rows[$v]->estado, $rows[$v]->reemplazado_por_emision_id], 'la variante cubierta no lleva enlace');
            $this->assertSame($r['emision_id'], (int) EmisionVigente::paraCertificado($v)['vigente']->id, 'resuelve por la raíz');
        }

        $caso = DB::table('cf_conciliaciones')->find($this->caso());
        $this->assertSame([Conciliacion::RESUELTO, ReemplazoHistorico::RESOLUCION], [$caso->estado, $caso->resolucion]);
    }

    public static function grafias(): array
    {
        return ['la corta' => [self::A, self::B], 'la larga' => [self::B, self::A]];
    }

    public function test_el_historico_no_se_modifica_salvo_el_estado_de_la_raiz(): void
    {
        $antes = DB::table('cf_certificados_legado')->orderBy('id')->get()->map(fn ($c) => collect((array) $c)->except(['estado', 'reemplazado_por_emision_id', 'update_by', 'updated_at'])->all())->all();
        $correos = DB::table('cf_correos')->orderBy('id')->get()->all();
        $descargas = DB::table('cf_descargas')->orderBy('id')->get()->all();
        $this->emitirDif();
        $despues = DB::table('cf_certificados_legado')->orderBy('id')->get()->map(fn ($c) => collect((array) $c)->except(['estado', 'reemplazado_por_emision_id', 'update_by', 'updated_at'])->all())->all();
        $this->assertSame($antes, $despues);
        $this->assertEquals($correos, DB::table('cf_correos')->orderBy('id')->get()->all());
        $this->assertEquals($descargas, DB::table('cf_descargas')->orderBy('id')->get()->all());
    }

    public function test_un_nombre_externo_con_evidencia_se_emite_y_queda_como_externo(): void
    {
        $r = $this->emitirDif(['valorNombre' => 'ANA LUZ UNO TRES']);
        $this->assertSame('ANA LUZ UNO TRES', DB::table('cf_participantes')->find($r['participante_id'])->nombre_completo);
        $ev = json_decode((string) DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $this->caso())->where('accion', ReemplazoHistorico::ACCION)->value('evidencia'), true);
        $this->assertSame('externo', $ev['nombre_aprobado']['origen']);
        $this->assertNull($ev['nombre_aprobado']['variante_historica_id']);
    }

    public function test_la_auditoria_no_guarda_nombres_ni_evidencia_en_claro(): void
    {
        $this->emitirDif(['valorNombre' => self::A]);
        $e = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $this->caso())->where('accion', ReemplazoHistorico::ACCION)->first();
        $txt = $e->evidencia.$e->motivo;
        foreach (['ANA LUZ', 'UNO', 'Acta de asistencia', '8200007'] as $pii) {
            $this->assertStringNotContainsString($pii, $txt);
        }
        $ev = json_decode($e->evidencia, true);
        $this->assertSame('DIF_NOMBRE', $ev['categoria']);
        $this->assertSame(['variante_historica', hash('sha256', self::A), mb_strlen(self::A)], [$ev['nombre_aprobado']['origen'], $ev['nombre_aprobado']['sha256'], $ev['nombre_aprobado']['longitud']]);
        $this->assertSame(hash('sha256', self::EVIDENCIA), $ev['evidencia_manual']['sha256']);
        $this->assertCount(2, $ev['certificados_del_caso']);
        $this->assertCount(1, $ev['variantes_cubiertas']);
        $this->assertSame('documento_sin_cambio', $ev['documento']['regla']);
        $mov = DB::table('movimientos')->latest('id')->first();
        $this->assertStringNotContainsString('ANA', json_encode($mov));
    }

    public function test_la_segunda_emision_se_rechaza_y_no_crea_otra(): void
    {
        $plantilla = $this->clon(104);
        $this->servicio()->reemplazar($this->caso(), $this->actor(), self::MOTIVO, $this->sol($plantilla->id));
        $antes = $this->escritura();
        $e = $this->rechazo(fn () => $this->servicio()->reemplazar($this->caso(), $this->actor(), self::MOTIVO, $this->sol($plantilla->id, ['valorNombre' => self::A])));
        $this->assertContains($e->codigo, [ResolucionNoPermitida::YA_REEMPLAZADO, ResolucionNoPermitida::CASO_YA_RESUELTO, ResolucionNoPermitida::YA_REEMPLAZADO_LOGICAMENTE]);
        $this->assertSame($antes, $this->escritura());
        $this->assertSame(1, DB::table('cf_emisiones')->count());
        $this->assertFalse($this->servicio()->elegible(Conciliacion::findOrFail($this->caso()))['elegible']);
    }

    public function test_la_vista_previa_no_persiste_y_la_huella_cambia_con_el_nombre_la_evidencia_y_la_identidad(): void
    {
        $plantilla = $this->clon(104);
        $antes = $this->escritura();
        $pdf = $this->servicio()->previsualizar($this->caso(), $this->sol($plantilla->id));
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame($antes, $this->escritura());

        $h = fn (array $c = []) => $this->servicio()->huellaDe($this->caso(), $this->sol($plantilla->id, $c));
        $base = $h();
        $this->assertSame($base, $h());
        $this->assertNotSame($base, $h(['valorNombre' => self::A]));
        $this->assertNotSame($base, $h(['evidencia' => self::EVIDENCIA.' Ampliada.']));

        // Una decisión de identidad (o un cambio en su caso) invalida la vista previa.
        $ident = Conciliacion::query()->where('tipo', Conciliacion::TIPO_IDENTIDAD_AMBIGUA)->firstOrFail();
        DB::table('cf_conciliaciones')->where('id', $ident->id)->update(['referencia_clave' => EvidenciaIdentidad::hashDocumento('8200007')]);
        $conCaso = $h();
        $this->assertNotSame($base, $conCaso, 'aparece un caso de identidad del documento');
        DB::table('cf_conciliaciones')->where('id', $ident->id)->update(['estado' => Conciliacion::REQUIERE_SOPORTE]);
        $this->assertNotSame($conCaso, $h(), 'cambia el estado del caso de identidad');
    }

    public function test_una_vista_previa_vieja_no_emite(): void
    {
        $plantilla = $this->clon(104);
        $huella = $this->servicio()->huellaDe($this->caso(), $this->sol($plantilla->id));
        $e = $this->rechazo(fn () => $this->servicio()->reemplazar($this->caso(), $this->actor(), self::MOTIVO, $this->sol($plantilla->id, ['valorNombre' => self::A]), $huella));
        $this->assertSame(ResolucionNoPermitida::PREVIEW_DESACTUALIZADO, $e->codigo);
        $this->assertSame(0, DB::table('cf_emisiones')->count());
    }

    public function test_aprobar_el_nombre_no_toca_la_identidad_ni_la_autorizacion_de_correos(): void
    {
        $foto = fn () => [
            DB::table('cf_conciliaciones')->where('tipo', Conciliacion::TIPO_IDENTIDAD_AMBIGUA)->orderBy('id')->get()->all(),
            DB::table('cf_decisiones_identidad')->orderBy('id')->get()->all(), DB::table('cf_decisiones_identidad_grupos')->orderBy('id')->get()->all(),
            DB::table('cf_decisiones_identidad_correos')->orderBy('id')->get()->all(), DB::table('cf_correos')->orderBy('id')->get()->all(),
        ];
        $antes = $foto();
        $this->emitirDif();
        $this->assertEquals($antes, $foto());
    }

    public function test_los_casos_documentales_siguen_imprimiendo_el_nombre_historico_y_no_admiten_nombre_aprobado(): void
    {
        $r = $this->reemplazar(120);
        $this->assertSame('PERSONA ESPACIO', DB::table('cf_participantes')->find($r['participante_id'])->nombre_completo);
        $e = $this->rechazo(fn () => $this->servicio()->reemplazar($this->casoDe(121), $this->actor(), self::MOTIVO, $this->solicitud(121, $this->plantillaModerna()->id, ['reglaDocumento' => ReglasValorAprobado::DOC_SIN_CAMBIO])));
        $this->assertSame(ResolucionNoPermitida::REGLA_NO_APLICA, $e->codigo, 'DOCUMENTO_SIN_CAMBIO es solo de DIF_NOMBRE');
    }

    public function test_un_caso_dif_nombre_no_admite_las_reglas_de_documento(): void
    {
        $plantilla = $this->clon(104);
        $e = $this->rechazo(fn () => $this->servicio()->reemplazar($this->caso(), $this->actor(), self::MOTIVO, $this->sol($plantilla->id, ['reglaDocumento' => ReglasValorAprobado::DOC_MANUAL, 'valorDocumento' => '8200007'])));
        $this->assertSame(ResolucionNoPermitida::REGLA_NO_APLICA, $e->codigo);
    }

    public function test_solo_un_administrador_y_solo_un_caso_abierto(): void
    {
        $plantilla = $this->clon(104);
        $e = $this->rechazo(fn () => $this->servicio()->reemplazar($this->caso(), 999999, self::MOTIVO, $this->sol($plantilla->id)));
        $this->assertSame(ResolucionNoPermitida::ACTOR_NO_AUTORIZADO, $e->codigo);
        DB::table('cf_conciliaciones')->where('id', $this->caso())->update(['estado' => Conciliacion::REQUIERE_SOPORTE]);
        $e = $this->rechazo(fn () => $this->servicio()->reemplazar($this->caso(), $this->actor(), self::MOTIVO, $this->sol($plantilla->id)));
        $this->assertSame(ResolucionNoPermitida::CASO_YA_RESUELTO, $e->codigo);
    }

    public function test_una_variante_fuera_del_certificado_logico_bloquea_el_caso(): void
    {
        $plantilla = $this->clon(104);
        $otro = $this->idCert(102);
        DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $this->caso())->orderByDesc('certificado_legado_id')->limit(1)->update(['certificado_legado_id' => $otro]);
        $antes = $this->escritura();
        $e = $this->rechazo(fn () => $this->servicio()->reemplazar($this->caso(), $this->actor(), self::MOTIVO, $this->sol($plantilla->id)));
        $this->assertSame(ResolucionNoPermitida::CERTIFICADO_NO_PERTENECE, $e->codigo);
        $this->assertSame($antes, $this->escritura());
    }

    // ── HTTP (el flujo real de la pantalla) ───────────────────────────────────

    private function datosHttp(int $plantillaId, array $c = []): array
    {
        return array_merge([
            'plantilla_id' => $plantillaId, 'regla_documento' => 'documento_sin_cambio', 'regla_nombre' => 'nombre_confirmado', 'valor_nombre' => self::B,
            'confirmado_valor' => true, 'tengo_evidencia' => true, 'evidencia' => self::EVIDENCIA,
        ], $c);
    }

    public function test_la_pantalla_ofrece_las_variantes_sin_preseleccion_y_la_identidad_aparte(): void
    {
        $d = $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.reemplazo', $this->caso()))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->viewData('page')['props']['datos'];
        $this->assertSame('dif_nombre', $d['modo']);
        $this->assertCount(2, $d['dif_nombre']['variantes']);
        $this->assertEqualsCanonicalizing([self::A, self::B], array_column($d['dif_nombre']['variantes'], 'nombre'));
        $this->assertArrayNotHasKey('sugerido', $d['dif_nombre']);
        $this->assertArrayNotHasKey('preferido', $d['dif_nombre']);
        $this->assertNull($d['valor']['nombre_impreso']);
        $this->assertSame(1000, $d['valor']['evidencia_max']);
        $this->assertSame('documento_sin_cambio', $d['valor']['reglas'][0]['regla']);
        $this->assertStringContainsString('NO decide quién es la persona', $d['dif_nombre']['identidad']['aviso']);
        $this->assertStringNotContainsString('@', json_encode($d));
    }

    public function test_el_detalle_del_caso_ofrece_el_asistente_y_no_el_soporte_como_unica_via(): void
    {
        $c = $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.show', $this->caso()))->assertOk()->viewData('page')['props']['caso'];
        $this->assertSame('emitir_reemplazo', $c['accion']['clave']);
        $this->assertSame('Emitir certificado con nombre aprobado', $c['accion']['etiqueta']);
    }

    public function test_preview_y_emision_por_http_con_la_huella_real(): void
    {
        $plantilla = $this->clon(104);
        $datos = $this->datosHttp($plantilla->id, ['valor_nombre' => self::A]);
        $antes = $this->escritura();
        $resp = $this->actingAs($this->admin())->postJson(route('credential-flow.historico.casos.reemplazo.preview', $this->caso()), $datos)->assertOk();
        $this->assertStringStartsWith('%PDF', $resp->getContent());
        $this->assertSame($antes, $this->escritura());
        $huella = $resp->headers->get('X-Huella-Preview');

        $emitir = fn (array $extra = []) => $this->withSession(['cf_reemplazo_preview_'.$this->caso() => $huella])->actingAs($this->admin())
            ->post(route('credential-flow.historico.casos.reemplazo.emitir', $this->caso()), array_merge($datos, $extra, ['motivo' => self::MOTIVO, 'confirmo_revision' => true, 'confirmo_final' => true, 'huella' => $huella]));

        // Cambiar el nombre después de la vista previa invalida la emisión.
        $emitir(['valor_nombre' => self::B])->assertSessionHas('error');
        $this->assertSame(0, DB::table('cf_emisiones')->count());

        $emitir()->assertRedirect(route('credential-flow.historico.casos.show', $this->caso()))->assertSessionHas('success');
        $this->assertSame(1, DB::table('cf_emisiones')->count());
        $this->assertSame(self::A, DB::table('cf_participantes')->latest('id')->value('nombre_completo'));
    }

    public function test_por_http_exige_la_evidencia_y_la_confirmacion_del_nombre(): void
    {
        $plantilla = $this->clon(104);
        foreach ([['evidencia' => 'corta'], ['confirmado_valor' => false], ['valor_nombre' => null]] as $cambio) {
            $this->actingAs($this->admin())->postJson(route('credential-flow.historico.casos.reemplazo.preview', $this->caso()), $this->datosHttp($plantilla->id, $cambio))->assertStatus(422);
        }
        $this->actingAs($this->admin())->postJson(route('credential-flow.historico.casos.reemplazo.preview', $this->caso()), $this->datosHttp($plantilla->id, ['evidencia' => str_repeat('x', 1001)]))->assertStatus(422);
        $this->assertSame(0, DB::table('cf_emisiones')->count());
    }

    // ── Portal y verificación ─────────────────────────────────────────────────

    private function solicitarOtp(): bool
    {
        Mail::fake();
        $this->post(route('portal.solicitar'), ['documento' => '8200007', 'correo' => 'g1@example.test']);

        return Mail::sent(CodigoAccesoMail::class)->isNotEmpty();
    }

    private function casoIdentidad(): Conciliacion
    {
        $caso = Conciliacion::create(['tipo' => Conciliacion::TIPO_IDENTIDAD_AMBIGUA, 'estado' => 'abierto', 'referencia_tipo' => 'documento', 'referencia_clave' => EvidenciaIdentidad::hashDocumento('8200007'),
            'motivo_origen' => 'CORREO_CRUZA_GRUPOS', 'clave_idempotencia' => 'identidad_ambigua:documento:difnombre']);
        foreach (DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $this->caso())->orderBy('certificado_legado_id')->pluck('certificado_legado_id') as $n => $cert) {
            DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso->id, 'certificado_legado_id' => $cert, 'rol' => 'grupo_'.($n + 1), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $caso;
    }

    /** `correo_autorizado` (el correo compartido, a UN grupo; confirmación reforzada): el nombre aprobado NO es una decisión de identidad. */
    private function autorizarCorreo(Conciliacion $ident, string $nombreDelGrupo): int
    {
        return app(DecisionesIdentidad::class)->crear($ident->id, $this->actor(), 'correo_autorizado', self::MOTIVO, [
            'grupo' => NombreConservador::grupoId($nombreDelGrupo), 'correo_hmac' => EvidenciaIdentidad::hashCorreo('g1@example.test'), 'evidencia' => self::EVIDENCIA, 'reforzada' => true,
        ])['decision_id'];
    }

    private function nombreDeLaRaiz(): string
    {
        $ids = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $this->caso())->pluck('certificado_legado_id')->map(fn ($i) => (int) $i)->all();

        return (string) DB::table('cf_certificados_legado')->where('id', CertificadoLogico::de($ids[0])['raiz'])->value('nombre_completo');
    }

    public function test_el_reemplazo_no_abre_el_portal_y_con_identidad_aplicada_entrega_la_tarjeta_moderna(): void
    {
        $ids = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $this->caso())->pluck('certificado_legado_id')->map(fn ($i) => (int) $i)->all();
        $raiz = CertificadoLogico::de($ids[0])['raiz'];
        $ident = $this->casoIdentidad();
        $sinIdentidad = $this->solicitarOtp();

        $r = $this->emitirDif(['valorNombre' => self::A]);
        // El reemplazo NO autentica ni concede nada: lo que no entraba antes sigue sin entrar.
        $this->assertSame($sinIdentidad, $this->solicitarOtp(), 'el nombre aprobado no cambia el acceso');

        config(['credential_flow.identidad.decisiones_enabled' => true, 'credential_flow.identidad.multi_scope_enabled' => true]);
        $this->autorizarCorreo($ident, $this->nombreDeLaRaiz());
        $this->assertTrue($this->solicitarOtp(), 'identidad + reemplazo: entra');
        $this->post(route('portal.validar'), ['codigo' => Mail::sent(CodigoAccesoMail::class)->last()->codigo])->assertRedirect(route('portal.panel'));

        preg_match_all('/data-certificado="([a-z_]+)"/', $this->get(route('portal.panel'))->assertOk()->getContent(), $m);
        $this->assertSame(['actualizado'], $m[1], 'una sola tarjeta moderna por certificado lógico');
        $hash = DB::table('cf_emisiones')->where('id', $r['emision_id'])->value('pdf_hash');
        $d = $this->get(route('portal.descargar', $raiz))->assertOk();
        $this->assertSame($hash, hash_file('sha256', $d->baseResponse->getFile()->getPathname()), 'entrega la emisión moderna');
        // La variante del OTRO grupo sigue fuera del alcance de esta sesión: la autorización del correo no une personas ni grupos.
        $this->get(route('portal.descargar', array_values(array_diff($ids, [$raiz]))[0]))->assertNotFound();
        $this->assertSame(1, DB::table('cf_emisiones')->count());
    }

    public function test_revocar_la_identidad_no_revoca_la_emision_ni_reabre_el_caso_del_nombre(): void
    {
        $ident = $this->casoIdentidad();
        config(['credential_flow.identidad.decisiones_enabled' => true, 'credential_flow.identidad.multi_scope_enabled' => true]);
        $r = $this->emitirDif();
        $id = $this->autorizarCorreo($ident, $this->nombreDeLaRaiz());
        $this->assertTrue($this->solicitarOtp());
        app(DecisionesIdentidad::class)->revocar($id, $this->actor(), 'Se retira la decisión por la prueba.');

        $this->assertSame('emitida', DB::table('cf_emisiones')->where('id', $r['emision_id'])->value('estado'));
        $this->assertSame(Conciliacion::RESUELTO, DB::table('cf_conciliaciones')->find($this->caso())->estado, 'el caso del nombre sigue resuelto');
        $this->assertSame(1, DB::table('cf_emisiones')->count());
        $this->assertFalse($this->solicitarOtp(), 'sin identidad vigente el portal vuelve a la regla histórica');
    }

    public function test_los_codigos_historicos_de_ambas_variantes_verifican_la_misma_emision_vigente(): void
    {
        $ids = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $this->caso())->orderBy('certificado_legado_id')->pluck('certificado_legado_id')->all();
        DB::table('cf_certificados_legado')->where('id', $ids[0])->update(['codigo_legado' => '9710']);
        DB::table('cf_certificados_legado')->where('id', $ids[1])->update(['codigo_legado' => '9711']);
        $r = $this->emitirDif();
        $n = DB::table('cf_emisiones')->count();
        foreach (['9710', '9711'] as $codigo) {
            $resp = $this->get('/verificar/'.$codigo)->assertOk();
            $resp->assertSee('Este certificado histórico fue reemplazado por una versión posterior.');
            $this->assertStringContainsString($r['codigo_emision'], $resp->getContent());
            $this->assertStringNotContainsString('DIF_NOMBRE', $resp->getContent(), 'no se expone el motivo interno');
        }
        $this->assertSame($n, DB::table('cf_emisiones')->count(), 'verificar no crea códigos');
        $this->assertSame(['9710', '9711'], DB::table('cf_certificados_legado')->whereIn('id', $ids)->orderBy('id')->pluck('codigo_legado')->all(), 'codigo_legado intacto');
    }
}
