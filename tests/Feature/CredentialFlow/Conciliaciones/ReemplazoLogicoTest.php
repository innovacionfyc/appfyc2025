<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Mail\CodigoAccesoMail;
use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\Reemplazo\CertificadoLogico;
use App\Support\CredentialFlow\Reemplazo\CoberturaCanonica;
use App\Support\CredentialFlow\Reemplazo\EmisionVigente;
use App\Support\CredentialFlow\Reemplazo\ReemplazoHistorico;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Fase 10B-2B-2C.1: UN certificado histórico lógico (grupo de duplicados) → UN solo reemplazo moderno, aunque cada fila tenga su propio id y su caso.
 * El par se arma duplicando la fila del documental 120 (mismo grupo, mismos datos), cada una con su caso, como los 2 pares reales de DOC_WHITESPACE.
 */
class ReemplazoLogicoTest extends ReemplazoTestCase
{
    private const GRUPO = 'grupo-logico-test';

    /** @return array{raiz:int,variante:int,caso_raiz:int,caso_variante:int} */
    private function par(bool $marcarRaiz = true): array
    {
        Mail::fake();
        $raiz = $this->idCert(120);
        $fila = (array) DB::table('cf_certificados_legado')->where('id', $raiz)->first();
        unset($fila['id']);
        $fila['grupo_duplicado'] = self::GRUPO;
        $variante = DB::table('cf_certificados_legado')->insertGetId($fila);
        DB::table('cf_certificados_legado')->where('id', $raiz)->update(['grupo_duplicado' => self::GRUPO]);
        foreach (DB::table('cf_correos')->where('certificado_legado_id', $raiz)->get() as $c) {
            $copia = (array) $c;
            unset($copia['id']);
            $copia['certificado_legado_id'] = $variante;
            DB::table('cf_correos')->insert($copia);
        }

        $corrida = (int) DB::table('cf_migraciones_corridas')->value('id');
        $mapa = fn (int $dest, string $rel, string $origen) => DB::table('cf_migraciones_map')->insert([
            'corrida_id' => $corrida, 'origen_tabla' => 'participante', 'origen_id' => $origen, 'destino_tabla' => 'cf_certificados_legado', 'destino_id' => $dest,
            'relacion' => $rel, 'detalle' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($marcarRaiz) {
            $mapa($raiz, 'canonico', 'par-a');
            $mapa($variante, 'duplicado_identico', 'par-b');
        }

        $casoRaiz = $this->casoDe(120);
        $caso = (array) DB::table('cf_conciliaciones')->where('id', $casoRaiz)->first();
        unset($caso['id']);
        $caso['clave_idempotencia'] = ($caso['clave_idempotencia'] ?? 'k').'-variante';
        $casoVariante = DB::table('cf_conciliaciones')->insertGetId($caso);
        $pivote = (array) DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $casoRaiz)->first();
        unset($pivote['id']);
        $pivote['conciliacion_id'] = $casoVariante;
        $pivote['certificado_legado_id'] = $variante;
        DB::table('cf_conciliaciones_certificados')->insert($pivote);

        return ['raiz' => $raiz, 'variante' => $variante, 'caso_raiz' => $casoRaiz, 'caso_variante' => $casoVariante];
    }

    private function intentar(int $caso, ?int $plantilla = null): string
    {
        try {
            $this->servicio()->reemplazar($caso, $this->actor(), self::MOTIVO, $this->solicitud(120, $plantilla ?? $this->plantillaModerna()->id));
        } catch (ResolucionNoPermitida $e) {
            return $e->codigo;
        }

        return 'OK';
    }

    public function test_la_raiz_la_marca_el_mapa_y_sin_mapa_es_la_de_menor_id(): void
    {
        $p = $this->par();
        $this->assertSame([$p['raiz'], $p['variante']], CertificadoLogico::de($p['variante'])['miembros']);
        $this->assertSame($p['raiz'], CertificadoLogico::de($p['variante'])['raiz']);
        $this->assertFalse(CertificadoLogico::esVariante($p['raiz']));
        $this->assertTrue(CertificadoLogico::esVariante($p['variante']));

        // El mapa manda sobre el id: si marca a la fila mayor como canónica, esa es la raíz.
        DB::table('cf_migraciones_map')->where('destino_id', $p['raiz'])->update(['relacion' => 'duplicado_identico']);
        DB::table('cf_migraciones_map')->where('destino_id', $p['variante'])->update(['relacion' => 'canonico']);
        $this->assertSame($p['variante'], CertificadoLogico::de($p['raiz'])['raiz']);

        // Sin ninguna marca: la de menor id (determinista).
        DB::table('cf_migraciones_map')->where('relacion', 'canonico')->delete();
        $this->assertSame($p['raiz'], CertificadoLogico::de($p['variante'])['raiz']);
        // Un certificado sin grupo es su propio certificado lógico.
        $this->assertSame(['grupo' => null, 'miembros' => [$this->idCert(121)], 'raiz' => $this->idCert(121)], CertificadoLogico::de($this->idCert(121)));
    }

    public function test_reemplazar_desde_la_raiz_crea_una_sola_emision_y_cubre_el_caso_de_la_variante(): void
    {
        $p = $this->par();
        $r = $this->reemplazar(120);

        $this->assertSame(1, DB::table('cf_emisiones')->count());
        $this->assertSame(1, DB::table('cf_participantes')->count());
        $raiz = DB::table('cf_certificados_legado')->where('id', $p['raiz'])->first();
        $variante = DB::table('cf_certificados_legado')->where('id', $p['variante'])->first();
        $this->assertSame(['reemplazado', $r['emision_id']], [$raiz->estado, (int) $raiz->reemplazado_por_emision_id]);
        // Solo la raíz lleva el enlace; la variante queda intacta (vigente, sin enlace).
        $this->assertSame(['vigente', null], [$variante->estado, $variante->reemplazado_por_emision_id]);

        $this->assertSame([Conciliacion::RESUELTO, ReemplazoHistorico::RESOLUCION], [$this->estadoCaso($p['caso_raiz']), DB::table('cf_conciliaciones')->find($p['caso_raiz'])->resolucion]);
        $this->assertSame([Conciliacion::RESUELTO, CoberturaCanonica::RESOLUCION], [$this->estadoCaso($p['caso_variante']), DB::table('cf_conciliaciones')->find($p['caso_variante'])->resolucion]);

        $evento = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $p['caso_variante'])->where('accion', CoberturaCanonica::ACCION)->first();
        $this->assertNotNull($evento);
        $ev = json_decode($evento->evidencia, true);
        $this->assertSame([$p['caso_raiz'], $p['raiz'], $r['emision_id']], [$ev['caso_raiz_id'], $ev['certificado_raiz_id'], $ev['emision_id']]);
        $this->assertSame([$p['variante']], $ev['certificados_cubiertos']);
        $this->assertStringNotContainsString('PERSONA', $evento->evidencia.$evento->motivo, 'sin datos personales');

        // Las variantes resuelven por la raíz.
        $this->assertSame($r['emision_id'], (int) CertificadoLogico::reemplazada($p['variante'])->reemplazado_por_emision_id);
        $this->assertSame($r['emision_id'], (int) EmisionVigente::paraCertificado($p['variante'])['vigente']->id);
    }

    public function test_una_variante_no_puede_emitir_ni_antes_ni_despues_y_no_escribe_nada(): void
    {
        $p = $this->par();
        $plantilla = $this->plantillaModerna();

        $antes = $this->escritura();
        $this->assertSame(ResolucionNoPermitida::VARIANTE_NO_CANONICA, $this->intentar($p['caso_variante'], $plantilla->id));
        $this->assertSame($antes, $this->escritura(), 'el rechazo no escribe nada');
        $elegible = $this->servicio()->elegible(Conciliacion::findOrFail($p['caso_variante']));
        $this->assertSame([false, ResolucionNoPermitida::VARIANTE_NO_CANONICA], [$elegible['elegible'], $elegible['codigo']]);
        $this->assertStringContainsString('se gestiona desde su certificado principal', $elegible['motivo']);

        $this->reemplazar(120, $plantilla->id);
        $despues = $this->escritura();
        $this->assertSame(ResolucionNoPermitida::YA_REEMPLAZADO_LOGICAMENTE, $this->intentar($p['caso_variante'], $plantilla->id));
        $this->assertSame($despues, $this->escritura());
        $this->assertSame(1, DB::table('cf_emisiones')->count());
        $this->assertSame(ResolucionNoPermitida::YA_REEMPLAZADO, $this->intentar($p['caso_raiz'], $plantilla->id), 'la raíz ya reemplazada responde como siempre');
    }

    public function test_si_la_variante_difiere_en_algo_su_caso_no_se_cierra(): void
    {
        $p = $this->par();
        DB::table('cf_certificados_legado')->where('id', $p['variante'])->update(['nombre_completo' => 'PERSONA OTRA']);
        $this->reemplazar(120);

        $this->assertSame(Conciliacion::ABIERTO, $this->estadoCaso($p['caso_variante']));
        $this->assertSame(0, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $p['caso_variante'])->where('accion', CoberturaCanonica::ACCION)->count());
        // Sigue sin poder emitir: el certificado lógico ya tiene su reemplazo.
        $this->assertSame(ResolucionNoPermitida::YA_REEMPLAZADO_LOGICAMENTE, $this->intentar($p['caso_variante']));
        $this->assertSame(1, DB::table('cf_emisiones')->count());
    }

    public function test_la_cobertura_es_idempotente_y_no_toca_casos_ya_cerrados(): void
    {
        $p = $this->par();
        $this->reemplazar(120);
        $eventos = DB::table('cf_conciliaciones_eventos')->count();

        $this->assertSame([], (new CoberturaCanonica)->cubrir($p['raiz']));
        $this->assertSame([], (new CoberturaCanonica)->cubrir($p['variante'], null, false));
        $this->assertSame($eventos, DB::table('cf_conciliaciones_eventos')->count());
    }

    public function test_si_el_mapa_marca_a_la_otra_fila_como_canonica_ella_es_quien_emite(): void
    {
        $p = $this->par();
        DB::table('cf_migraciones_map')->where('destino_id', $p['raiz'])->update(['relacion' => 'duplicado_identico']);
        DB::table('cf_migraciones_map')->where('destino_id', $p['variante'])->update(['relacion' => 'canonico']);
        $plantilla = $this->plantillaModerna();

        $this->assertSame(ResolucionNoPermitida::VARIANTE_NO_CANONICA, $this->intentar($p['caso_raiz'], $plantilla->id));
        $r = $this->servicio()->reemplazar($p['caso_variante'], $this->actor(), self::MOTIVO, $this->solicitud(120, $plantilla->id));
        $this->assertSame($p['variante'], $r['certificado_id']);
        $this->assertSame(CoberturaCanonica::RESOLUCION, DB::table('cf_conciliaciones')->find($p['caso_raiz'])->resolucion);
        $this->assertSame(1, DB::table('cf_emisiones')->count());
    }

    public function test_un_certificado_sin_grupo_no_cambia(): void
    {
        Mail::fake();
        $r = $this->reemplazar(121);
        $this->assertSame(1, DB::table('cf_emisiones')->count());
        $this->assertSame(0, DB::table('cf_conciliaciones_eventos')->where('accion', CoberturaCanonica::ACCION)->count());
        $this->assertSame($r['emision_id'], (int) CertificadoLogico::reemplazada($this->idCert(121))->reemplazado_por_emision_id);
    }

    // ── Pantalla del administrador ────────────────────────────────────────────

    public function test_la_pantalla_de_una_variante_no_ofrece_emitir_y_enlaza_al_caso_principal(): void
    {
        $p = $this->par();
        $admin = $this->admin();

        $detalle = $this->actingAs($admin)->get(route('credential-flow.historico.casos.show', $p['caso_variante']))->assertOk();
        $logico = $detalle->viewData('page')['props']['caso']['certificado_logico'] ?? $detalle->viewData('page')['props']['datos']['certificado_logico'] ?? null;
        $this->assertNotNull($logico);
        $this->assertTrue($logico['es_variante']);
        $this->assertSame($p['caso_raiz'], $logico['caso_principal']['id']);
        $this->assertFalse($detalle->viewData('page')['props']['caso']['acciones_disponibles'] ?? false);

        // El asistente, la vista previa y la emisión están cerrados para la variante.
        $this->actingAs($admin)->get(route('credential-flow.historico.casos.reemplazo', $p['caso_variante']))->assertRedirect(route('credential-flow.historico.casos.show', $p['caso_variante']));
        $plantilla = $this->plantillaModerna();
        $antes = $this->escritura();
        $this->actingAs($admin)->postJson(route('credential-flow.historico.casos.reemplazo.preview', $p['caso_variante']), ['regla_documento' => 'doc_sin_nbsp', 'plantilla_id' => $plantilla->id])->assertStatus(422);
        $this->assertSame($antes, $this->escritura());

        // La raíz sí lo ofrece.
        $this->actingAs($admin)->get(route('credential-flow.historico.casos.reemplazo', $p['caso_raiz']))->assertOk();
    }

    // ── Portal y verificación ─────────────────────────────────────────────────

    private function entrar(string $documento, string $correo): void
    {
        $this->post(route('portal.solicitar'), ['documento' => $documento, 'correo' => $correo])->assertRedirect(route('portal.codigo'));
        $this->post(route('portal.validar'), ['codigo' => Mail::sent(CodigoAccesoMail::class)->last()->codigo])->assertRedirect(route('portal.panel'));
    }

    public function test_el_portal_muestra_una_tarjeta_y_cualquier_id_historico_descarga_la_misma_emision(): void
    {
        $p = $this->par();
        $r = $this->reemplazar(120);

        $this->entrar('8300001', 'r1@example.test');
        preg_match_all('/data-certificado="([a-z_]+)"/', $this->get(route('portal.panel'))->assertOk()->getContent(), $m);
        $this->assertSame(['actualizado'], $m[1], 'una sola tarjeta por certificado lógico');

        $hash = DB::table('cf_emisiones')->where('id', $r['emision_id'])->value('pdf_hash');
        foreach ([$p['raiz'], $p['variante']] as $id) {
            $d = $this->get(route('portal.descargar', $id))->assertOk();
            $this->assertSame($hash, hash_file('sha256', $d->baseResponse->getFile()->getPathname()), "el id $id entrega la misma emisión");
        }
        $this->assertSame(2, DB::table('cf_descargas')->where('emision_id', $r['emision_id'])->count());
        $this->assertSame(0, DB::table('cf_descargas')->whereIn('certificado_legado_id', [$p['raiz'], $p['variante']])->where('origen', 'credential_flow')->count());
        $this->assertSame(1, DB::table('cf_emisiones')->count());
    }

    public function test_los_codigos_de_cualquier_fila_del_grupo_verifican_la_misma_emision_vigente(): void
    {
        $p = $this->par();
        DB::table('cf_certificados_legado')->where('id', $p['raiz'])->update(['codigo_legado' => '9120']);
        DB::table('cf_certificados_legado')->where('id', $p['variante'])->update(['codigo_legado' => '9125']);
        $r = $this->reemplazar(120);
        $codigos = DB::table('cf_emisiones')->count();

        foreach (['9120', '9125'] as $codigo) {
            $resp = $this->get('/verificar/'.$codigo)->assertOk();
            $resp->assertSee('Este certificado histórico fue reemplazado por una versión posterior.');
            $this->assertStringContainsString($r['codigo_emision'], $resp->getContent());
        }
        $this->assertSame($codigos, DB::table('cf_emisiones')->count(), 'verificar no crea códigos');
    }
}
