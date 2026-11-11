<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Descarga;
use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Support\CredentialFlow\Descargas\SeguimientoDescargasEvento;
use App\Support\CredentialFlow\Eventos\ServicioEventos;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Seguimiento de descargas por evento (E1 servicio + E2 pantalla). Datos 100 % sintéticos: las «descargas» son filas de prueba en la base SQLite en
 * memoria; no se descarga ningún certificado real.
 */
class SeguimientoDescargasEventoTest extends EmisionesTestCase
{
    private const MOTIVO = 'Corrección sintética de los datos del participante';

    protected function setUp(): void
    {
        parent::setUp();
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
            'database/migrations/2026_10_09_100100_add_origen_to_cf_descargas_table.php',
            'database/migrations/2026_10_15_100000_cf_reemplazo_historico_infraestructura.php',
            'database/migrations/2026_10_21_100000_cf_contactos_qa_y_plantilla_qa.php',
        ], '--force' => true])->assertExitCode(0);
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    private function evento(string $nombre, string $origen = EventoCertificacion::ORIGEN_CREDENTIAL_FLOW): EventoCertificacion
    {
        return EventoCertificacion::create(['nombre' => $nombre, 'nombre_normalizado' => NombreConservador::normalizar($nombre), 'anio' => 2026,
            'fecha_texto' => '10 DE NOVIEMBRE DE 2026', 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => $origen]);
    }

    private function base(EventoCertificacion $evento, string $nombre = 'Base sintética', ?string $modalidad = null): Lote
    {
        return Lote::create(['plantilla_id' => $this->plantillaLista()->id, 'nombre' => $nombre, 'evento_id' => $evento->id,
            'datos_comunes' => array_filter(['evento' => 'Seminario sintético', 'fecha' => '10 de noviembre de 2026', 'intensidad_horaria' => '8 horas', 'modalidad' => $modalidad])]);
    }

    private function persona(Lote $lote, string $nombre, string $doc, ?string $modalidad = null, bool $qa = false): Participante
    {
        $p = $this->participante($lote, $nombre, $doc);
        $p->update(['modalidad' => $modalidad, 'es_control_qa' => $qa]);

        return $p->fresh();
    }

    private function emitir(Lote $lote, Participante $p): Emision
    {
        $this->emitirPor($lote, $p)->assertCreated();

        return Emision::where('participante_id', $p->id)->where('estado', Emision::EMITIDA)->sole();
    }

    private function reemitir(Emision $e): Emision
    {
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $e), ['motivo' => self::MOTIVO])->assertCreated();

        return Emision::where('participante_id', $e->participante_id)->where('estado', Emision::EMITIDA)->sole();
    }

    /** Fila de descarga SINTÉTICA (no se descarga nada). */
    private function descarga(array $destino, string $fecha, string $ip = 'ip-a', string $origen = Descarga::ORIGEN_CREDENTIAL_FLOW): void
    {
        Descarga::create($destino + ['via' => Descarga::VIA_PORTAL, 'origen' => $origen, 'descargado_at' => $fecha,
            'ip_hash' => $origen === Descarga::ORIGEN_CREDENTIAL_FLOW ? hash('sha256', $ip) : null]);
    }

    /** Convierte el documento emitido en una PRUEBA INTERNA (lleva el marcador institucional QA en su diseño congelado). */
    private function comoQa(Emision $e): void
    {
        $d = json_decode((string) DB::table('cf_emisiones')->where('id', $e->id)->value('diseno_snapshot'), true);
        $d['elements'][] = $this->elemento(['type' => 'text', 'field' => null, 'text' => ServicioQa::ADVERTENCIA, 'y' => 500]);
        DB::table('cf_emisiones')->where('id', $e->id)->update(['diseno_snapshot' => json_encode($d)]);
    }

    private function legado(EventoCertificacion $evento, string $nombre, string $doc, array $extra = []): int
    {
        return (int) DB::table('cf_certificados_legado')->insertGetId($extra + ['evento_id' => $evento->id, 'documento' => $doc, 'documento_clave' => preg_replace('/\D/', '', $doc),
            'nombre_completo' => $nombre, 'estado' => 'vigente', 'conciliacion_estado' => 'ok', 'snapshot_legado' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function servicio(): SeguimientoDescargasEvento
    {
        return app(SeguimientoDescargasEvento::class);
    }

    /** @return array{0:list<array<string,mixed>>,1:array<string,mixed>} */
    private function datos(EventoCertificacion $e): array
    {
        $r = $this->servicio()->registros($e);

        return [$r, $this->servicio()->resumen($r)];
    }

    private function props(EventoCertificacion $e, array $q = [], $usuario = null): array
    {
        $out = null;
        $this->actingAs($usuario ?? $this->admin())->get(route('credential-flow.eventos.descargas', ['evento' => $e->id] + $q))->assertOk()
            ->assertInertia(function (Assert $p) use (&$out) {
                $out = $p->component('CredentialFlow/Eventos/Descargas')->toArray()['props'];
            });

        return $out;
    }

    // ── Métricas modernas ─────────────────────────────────────────────────────

    public function test_emitidos_descargados_pendientes_solicitudes_primera_y_ultima(): void
    {
        $e = $this->evento('Seminario sintético de métricas');
        $l = $this->base($e);
        [$a, $b, $c] = [$this->persona($l, 'ANA PRUEBA', 'C.C. 7200001'), $this->persona($l, 'BETO PRUEBA', 'C.C. 7200002'), $this->persona($l, 'CARO PRUEBA', 'C.C. 7200003')];
        [$ea, , $ec] = [$this->emitir($l, $a), $this->emitir($l, $b), $this->emitir($l, $c)];
        $this->descarga(['emision_id' => $ea->id], '2026-11-01 10:00:00');
        $this->descarga(['emision_id' => $ea->id], '2026-11-03 09:00:00', 'ip-b');
        $this->descarga(['emision_id' => $ec->id], '2026-11-02 08:00:00');

        [$reg, $res] = $this->datos($e);
        $o = $res['modernos']['oficial'];
        $this->assertSame([3, 2, 1, 3, 0], [$o['vigentes'], $o['con_descarga'], $o['pendientes'], $o['solicitudes_vigentes'], $o['solicitudes_anteriores']]);
        $this->assertSame(['2026-11-01 10:00:00', '2026-11-03 09:00:00'], [$o['primera'], $o['ultima']]);
        $fa = collect($reg)->firstWhere('participacion_id', $a->id);
        $this->assertSame([true, 2, '2026-11-01 10:00:00', '2026-11-03 09:00:00'], [$fa['descargado'], $fa['descargas']['vigente']['solicitudes'], $fa['descargas']['vigente']['primera'], $fa['descargas']['vigente']['ultima']]);
        $this->assertFalse(collect($reg)->firstWhere('participacion_id', $b->id)['descargado']);
    }

    public function test_las_pruebas_internas_qa_no_inflan_los_indicadores_oficiales(): void
    {
        $e = $this->evento('Seminario sintético con QA');
        $l = $this->base($e);
        $real = $this->emitir($l, $this->persona($l, 'ANA REAL', 'C.C. 7300001'));
        $qa = $this->emitir($l, $this->persona($l, 'QA CONTROL', 'C.C. 8000001'));
        Participante::whereKey($qa->participante_id)->update(['es_control_qa' => true]);   // contacto de control…
        $this->comoQa($qa);                                                                // …con su documento de PRUEBA INTERNA
        $this->descarga(['emision_id' => $qa->id], '2026-11-01 10:00:00');
        $this->descarga(['emision_id' => $qa->id], '2026-11-01 11:00:00');

        [$reg, $res] = $this->datos($e);
        $this->assertSame([1, 0, 0], [$res['modernos']['oficial']['vigentes'], $res['modernos']['oficial']['con_descarga'], $res['modernos']['oficial']['solicitudes_vigentes']]);
        $this->assertSame([1, 1, 2], [$res['modernos']['prueba_interna']['vigentes'], $res['modernos']['prueba_interna']['con_descarga'], $res['modernos']['prueba_interna']['solicitudes_vigentes']]);
        $this->assertSame(0, $res['clasificacion_distinta_a_marca']);
        // Por defecto el listado solo muestra oficiales; la prueba interna aparece al pedirla.
        $this->assertSame([$real->participante_id], array_column($this->servicio()->listado($reg, [], false)['items'], 'participacion_id'));
        $this->assertSame([$qa->participante_id], array_column($this->servicio()->listado($reg, ['clase' => 'prueba_interna'], false)['items'], 'participacion_id'));

        // Si la marca viva del participante no coincide con el documento emitido, se avisa (manda el documento emitido).
        Participante::whereKey($real->participante_id)->update(['es_control_qa' => true]);
        $this->assertSame(1, $this->datos($e)[1]['clasificacion_distinta_a_marca']);
        $this->assertSame(1, $this->datos($e)[1]['modernos']['oficial']['vigentes']);
    }

    public function test_una_reemision_no_hereda_las_descargas_de_la_version_anterior(): void
    {
        $e = $this->evento('Seminario sintético de reemisión');
        $l = $this->base($e);
        $p = $this->persona($l, 'ANA REEMITIDA', 'C.C. 7400001');
        $v1 = $this->emitir($l, $p);
        $this->descarga(['emision_id' => $v1->id], '2026-11-01 10:00:00');
        $this->descarga(['emision_id' => $v1->id], '2026-11-02 10:00:00');
        $v2 = $this->reemitir($v1);

        [$reg, $res] = $this->datos($e);
        $f = collect($reg)->sole();
        $this->assertSame(['vigente', $v2->id, 2, false, 0, 2], [$f['estado'], $f['emision_id'], $f['versiones'], $f['descargado'], $f['descargas']['vigente']['solicitudes'], $f['descargas']['anteriores']['solicitudes']]);
        $this->assertSame([1, 0, 1, 0, 2, 1], [$res['modernos']['oficial']['vigentes'], $res['modernos']['oficial']['con_descarga'], $res['modernos']['oficial']['pendientes'],
            $res['modernos']['oficial']['solicitudes_vigentes'], $res['modernos']['oficial']['solicitudes_anteriores'], $res['modernos']['oficial']['con_descarga_anterior']]);

        $this->descarga(['emision_id' => $v2->id], '2026-11-05 10:00:00');
        $f = collect($this->datos($e)[0])->sole();
        $this->assertSame([true, 1, '2026-11-05 10:00:00', 2], [$f['descargado'], $f['descargas']['vigente']['solicitudes'], $f['descargas']['vigente']['primera'], $f['descargas']['anteriores']['solicitudes']]);
    }

    public function test_una_participacion_revocada_no_cuenta_como_vigente_ni_pendiente(): void
    {
        $e = $this->evento('Seminario sintético de revocación');
        $l = $this->base($e);
        $em = $this->emitir($l, $this->persona($l, 'ANA REVOCADA', 'C.C. 7500001'));
        $this->descarga(['emision_id' => $em->id], '2026-11-01 10:00:00');
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $em), ['motivo' => self::MOTIVO])->assertOk();

        [$reg, $res] = $this->datos($e);
        $this->assertSame([0, 0, 1, 1], [$res['modernos']['oficial']['vigentes'], $res['modernos']['oficial']['pendientes'], $res['modernos']['oficial']['revocadas'], $res['modernos']['oficial']['solicitudes_anteriores']]);
        $this->assertSame('revocado', collect($reg)->sole()['estado']);
        $this->assertCount(1, $this->servicio()->listado($reg, ['estado' => 'revocado'], false)['items']);
        $this->assertCount(0, $this->servicio()->listado($reg, ['estado' => 'pendiente'], false)['items']);
    }

    public function test_doble_modalidad_son_dos_certificados_con_su_propio_seguimiento(): void
    {
        $e = $this->evento('Seminario sintético de doble modalidad');
        $l = $this->base($e);
        $pres = $this->persona($l, 'ANA DOBLE', 'C.C. 7600001', 'Presencial');
        $virt = $this->persona($l, 'ANA DOBLE', 'C.C. 7600001', 'Virtual');
        $ep = $this->emitir($l, $pres);
        $this->emitir($l, $virt);
        $this->descarga(['emision_id' => $ep->id], '2026-11-01 10:00:00');

        [$reg, $res] = $this->datos($e);
        $this->assertCount(2, $reg, 'una fila por participación, nunca agrupadas por documento');
        $this->assertSame([2, 1, 1], [$res['modernos']['oficial']['vigentes'], $res['modernos']['oficial']['con_descarga'], $res['modernos']['oficial']['pendientes']]);
        $mods = collect($res['por_modalidad'])->keyBy('clave');
        $this->assertSame([1, 1, 1, 0], [$mods['presencial']['vigentes'], $mods['presencial']['con_descarga'], $mods['virtual']['vigentes'], $mods['virtual']['con_descarga']]);
        $this->assertSame([[$pres->id, true], [$virt->id, false]], collect($this->servicio()->listado($reg, ['documento' => '7600001'], true)['items'])->sortBy('participacion_id')->map(fn ($r) => [$r['participacion_id'], $r['descargado']])->values()->all());
        $this->assertSame(['Presencial', 'Virtual'], array_column($this->servicio()->modalidades($reg), 'etiqueta'));
    }

    // ── Históricos ────────────────────────────────────────────────────────────

    public function test_historicos_separan_importadas_suman_duplicados_y_no_cuentan_dos_veces_un_reemplazo(): void
    {
        $e = $this->evento('Evento histórico sintético', EventoCertificacion::ORIGEN_LEGADO);
        $a = $this->legado($e, 'ANA HISTORICA', '7700001');
        $b1 = $this->legado($e, 'BETO HISTORICO', '7700002', ['grupo_duplicado' => 'g-1']);
        $b2 = $this->legado($e, 'BETO HISTORICO', '7700002', ['grupo_duplicado' => 'g-1', 'conciliacion_estado' => 'duplicado_consolidado']);
        $c = $this->legado($e, 'CARO HISTORICA', '7700003');
        $d = $this->legado($e, 'DANI SIN DESCARGA', '7700004');
        $this->descarga(['certificado_legado_id' => $a], '2024-03-01 10:00:00', 'x', Descarga::ORIGEN_LEGADO_IMPORTADO);
        $this->descarga(['certificado_legado_id' => $a], '2024-03-02 10:00:00', 'x', Descarga::ORIGEN_LEGADO_IMPORTADO);
        $this->descarga(['certificado_legado_id' => $a], '2026-10-20 10:00:00');
        $this->descarga(['certificado_legado_id' => $b2], '2023-05-01 10:00:00', 'x', Descarga::ORIGEN_LEGADO_IMPORTADO);
        $this->descarga(['certificado_legado_id' => $b1], '2026-10-21 10:00:00');
        $this->descarga(['certificado_legado_id' => $c], '2026-10-16 10:00:00');   // antes del reemplazo
        // Reemplazo: la emisión moderna vive en la base «Reemplazos históricos» del MISMO evento.
        $lr = $this->base($e, 'Reemplazos históricos · Evento histórico sintético');
        $em = $this->emitir($lr, $this->persona($lr, 'CARO HISTORICA', 'C.C. 7700003'));
        DB::table('cf_certificados_legado')->where('id', $c)->update(['estado' => 'reemplazado', 'reemplazado_por_emision_id' => $em->id]);
        $this->descarga(['emision_id' => $em->id], '2026-10-25 10:00:00');   // después del reemplazo

        [$reg, $res] = $this->datos($e);
        $h = $res['historicos'];
        $this->assertSame([4, 3, 1, 3, 3, 2, 2, 2, 1], [$h['certificados'], $h['vigentes'], $h['reemplazados'], $h['solicitudes_importadas'], $h['solicitudes_credential_flow'],
            $h['con_descarga'], $h['con_descarga_credential_flow'], $h['con_descarga_importada'], $h['pendientes']], 'importadas y CF por separado; el duplicado es un solo certificado');
        $fb = collect($reg)->firstWhere('certificado_id', $b1);
        $this->assertSame([2, 1, 1], [$fb['filas'], $fb['descargas']['importadas']['solicitudes'], $fb['descargas']['credential_flow']['solicitudes']]);
        $fc = collect($reg)->firstWhere('certificado_id', $c);
        $mod = collect($reg)->firstWhere('tipo', 'moderno');
        $this->assertSame(['reemplazado', 1, $em->id, $mod['participacion_id']], [$fc['estado'], $fc['descargas']['credential_flow']['solicitudes'], $fc['reemplazado_por']['emision_id'], $fc['reemplazado_por']['participacion_id']]);
        $this->assertSame([$c, 1, true], [$mod['reemplaza_historico'], $mod['descargas']['vigente']['solicitudes'], $mod['descargado']]);
        // Ninguna descarga se cuenta dos veces: la suma de todos los registros es exactamente el total de filas del evento.
        $suma = collect($reg)->sum(fn ($r) => $r['tipo'] === 'moderno' ? $r['descargas']['vigente']['solicitudes'] + $r['descargas']['anteriores']['solicitudes']
            : $r['descargas']['credential_flow']['solicitudes'] + $r['descargas']['importadas']['solicitudes']);
        $this->assertSame(Descarga::count(), $suma);
        $this->assertFalse(collect($reg)->firstWhere('certificado_id', $d)['descargado']);
    }

    // ── Reintentos (indicador secundario) ─────────────────────────────────────

    public function test_los_posibles_reintentos_se_informan_aparte_sin_cambiar_los_registros_brutos(): void
    {
        $e = $this->evento('Seminario sintético de reintentos');
        $l = $this->base($e);
        $em = $this->emitir($l, $this->persona($l, 'ANA REINTENTO', 'C.C. 7800001'));
        $this->descarga(['emision_id' => $em->id], '2026-11-01 10:00:00', 'ip-a');
        $this->descarga(['emision_id' => $em->id], '2026-11-01 10:00:30', 'ip-a');   // reintento (misma IP, 30 s)
        $this->descarga(['emision_id' => $em->id], '2026-11-01 10:01:00', 'ip-b');   // otra IP: no
        $this->descarga(['emision_id' => $em->id], '2026-11-01 10:20:00', 'ip-b');   // 19 min: no

        [$reg, $res] = $this->datos($e);
        $this->assertSame([4, 1], [$res['modernos']['oficial']['solicitudes_vigentes'], $res['modernos']['oficial']['reintentos']]);
        $this->assertSame(4, Descarga::count(), 'nada se borra ni se reescribe');
    }

    // ── Pantalla, permisos, filtros y paginación ──────────────────────────────

    public function test_administrador_ve_documento_enmascarado_y_no_puede_buscar_por_documento(): void
    {
        $e = $this->evento('Seminario sintético de permisos');
        $l = $this->base($e);
        $this->emitir($l, $this->persona($l, 'ANA PERMISOS', 'C.C. 79.000.123'));

        $p = $this->props($e);
        $this->assertFalse($p['permisos']['documento_completo']);
        $this->assertStringNotContainsString('79000123', json_encode($p['listado']['items']));
        $this->assertStringEndsWith('123', $p['listado']['items'][0]['documento']);
        $this->assertNotSame('C.C. 79.000.123', $p['listado']['items'][0]['documento']);
        $this->assertStringContainsStringIgnoringCase('no prueba que el titular', $p['aviso']);
        $this->actingAs($this->admin())->get(route('credential-flow.eventos.descargas', ['evento' => $e->id, 'documento' => '79000123']))->assertForbidden();

        $s = $this->props($e, ['documento' => '79000123'], $this->superAdmin());
        $this->assertTrue($s['permisos']['documento_completo']);
        $this->assertSame('C.C. 79.000.123', $s['listado']['items'][0]['documento']);
        $this->assertSame(0, $this->props($e, ['documento' => '11111111'], $this->superAdmin())['listado']['total']);

        $this->actingAs($this->comercial())->get(route('credential-flow.eventos.descargas', $e))->assertForbidden();
        auth()->logout();
        $this->get(route('credential-flow.eventos.descargas', $e))->assertRedirect(route('login'));
    }

    public function test_filtros_paginacion_y_solo_lectura(): void
    {
        $e = $this->evento('Seminario sintético de filtros');
        $l1 = $this->base($e, 'Base presencial', 'Presencial');
        $l2 = $this->base($e, 'Base virtual', 'Virtual');
        $primera = null;
        for ($i = 1; $i <= 27; $i++) {
            $l = $i <= 20 ? $l1 : $l2;
            $em = $this->emitir($l, $this->persona($l, "PERSONA FILTRO $i", 'C.C. '.(7_900_000 + $i)));
            $primera ??= $em;
        }
        $this->descarga(['emision_id' => $primera->id], '2026-11-04 10:00:00');
        $antes = [Descarga::count(), DB::table('movimientos')->count(), Emision::count()];

        $p = $this->props($e);
        $this->assertSame([27, 25, 2], [$p['listado']['total'], count($p['listado']['items']), $p['listado']['ultima']]);
        $this->assertCount(2, $this->props($e, ['page' => 2])['listado']['items']);
        $this->assertSame(7, $this->props($e, ['base' => $l2->id])['listado']['total']);
        $this->assertSame(7, $this->props($e, ['modalidad' => 'virtual'])['listado']['total']);
        $this->assertSame(7, $this->props($e, ['modalidad' => ' Virtual '])['listado']['total'], 'la modalidad escrita a mano se normaliza igual que la efectiva');
        $this->assertSame(1, $this->props($e, ['estado' => 'descargado'])['listado']['total']);
        $this->assertSame(26, $this->props($e, ['estado' => 'pendiente'])['listado']['total']);
        $this->assertSame(1, $this->props($e, ['nombre' => 'persona filtro 1', 'estado' => 'descargado'])['listado']['total']);
        $this->assertSame(1, $this->props($e, ['desde' => '2026-11-04', 'hasta' => '2026-11-04'])['listado']['total']);
        $this->assertSame(0, $this->props($e, ['desde' => '2026-11-05'])['listado']['total']);
        $this->actingAs($this->admin())->get(route('credential-flow.eventos.descargas', ['evento' => $e->id, 'estado' => 'inventado']))->assertSessionHasErrors('estado');
        $this->assertCount(2, $p['opciones']['bases']);
        $this->assertSame([['Base presencial', 20, 1], ['Base virtual', 7, 0]], array_map(fn ($b) => [$b['etiqueta'], $b['vigentes'], $b['con_descarga']], $p['resumen']['por_base']));

        $this->assertSame($antes, [Descarga::count(), DB::table('movimientos')->count(), Emision::count()], 'la pantalla no escribe nada');
    }

    public function test_el_numero_de_consultas_no_crece_con_los_participantes(): void
    {
        $contar = function (int $n): int {
            $e = $this->evento("Seminario sintético de rendimiento $n");
            $l = $this->base($e);
            for ($i = 1; $i <= $n; $i++) {
                $em = $this->emitir($l, $this->persona($l, "PERSONA $n $i", 'C.C. '.(6_000_000 + $n * 100 + $i)));
                $this->descarga(['emision_id' => $em->id], '2026-11-01 10:00:0'.($i % 10));
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $r = $this->servicio()->registros($e);
            $this->servicio()->resumen($r);
            $this->servicio()->listado($r, [], false);
            $q = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $q;
        };
        $this->assertSame($contar(3), $contar(12), 'mismas consultas con 3 o con 12 participantes (sin N+1)');
    }

    public function test_evento_historico_tiene_su_pestana_y_el_listado_muestra_cuantos_vigentes_se_descargaron(): void
    {
        $h = $this->evento('Evento histórico de pantalla', EventoCertificacion::ORIGEN_LEGADO);
        $this->legado($h, 'ANA HISTORICA', '7100001');
        $p = $this->props($h);
        $this->assertTrue($p['evento']['historico']);
        $this->assertSame(1, $p['resumen']['historicos']['vigentes']);

        $e = $this->evento('Seminario sintético del listado');
        $l = $this->base($e);
        $em = $this->emitir($l, $this->persona($l, 'ANA LISTADO', 'C.C. 7100002'));
        $this->emitir($l, $this->persona($l, 'BETO LISTADO', 'C.C. 7100003'));
        $this->descarga(['emision_id' => $em->id], '2026-11-01 10:00:00');
        $this->descarga(['emision_id' => $em->id], '2026-11-02 10:00:00');
        $this->actingAs($this->admin())->get(route('credential-flow.eventos.index'))->assertOk()->assertInertia(fn (Assert $a) => $a
            ->where('eventos.0.id', $e->id)->where('eventos.0.descargas.con_descarga', 1)->where('eventos.0.descargas.vigentes', 2)->where('eventos.0.reales.vigentes', 2));
    }

    // ── Coherencia listado ↔ pestaña (criterio QA único: el documento emitido) ──

    /** @return array<string,mixed> fila del evento en el listado de eventos */
    private function filaListado(EventoCertificacion $e): array
    {
        $out = null;
        $this->actingAs($this->admin())->get(route('credential-flow.eventos.index'))->assertOk()->assertInertia(function (Assert $p) use (&$out, $e) {
            $out = collect($p->toArray()['props']['eventos'])->firstWhere('id', $e->id);
        });

        return $out;
    }

    /** @return array{0:int,1:int} [vigentes oficiales, con descarga] según la pestaña */
    private function segunPestana(EventoCertificacion $e): array
    {
        $o = $this->props($e)['resumen']['modernos']['oficial'];

        return [$o['vigentes'], $o['con_descarga']];
    }

    public function test_listado_y_pestana_dan_el_mismo_porcentaje_cuando_marca_y_documento_coinciden(): void
    {
        $e = $this->evento('Seminario sintético coherente');
        $l = $this->base($e);
        $reales = collect([1, 2, 3])->map(fn ($i) => $this->emitir($l, $this->persona($l, "REAL COHERENTE $i", 'C.C. 74000'.$i)));
        $qa = $this->emitir($l, $this->persona($l, 'QA COHERENTE', 'C.C. 8400001'));
        Participante::whereKey($qa->participante_id)->update(['es_control_qa' => true]);
        $this->comoQa($qa);
        $this->descarga(['emision_id' => $reales[0]->id], '2026-11-01 10:00:00');
        $this->descarga(['emision_id' => $reales[1]->id], '2026-11-01 11:00:00');
        $this->descarga(['emision_id' => $qa->id], '2026-11-01 12:00:00');

        $f = $this->filaListado($e);
        $this->assertSame([3, 2], [$f['descargas']['vigentes'], $f['descargas']['con_descarga']]);
        $this->assertSame($this->segunPestana($e), [$f['descargas']['vigentes'], $f['descargas']['con_descarga']]);
        $this->assertSame(3, $f['reales']['vigentes'], 'mismo denominador que la columna de vigentes');
        $this->assertSame(0, $f['descargas']['distintas']);
    }

    public function test_si_la_marca_no_coincide_con_el_documento_el_listado_usa_el_documento_igual_que_la_pestana(): void
    {
        $e = $this->evento('Seminario sintético discrepante');
        $l = $this->base($e);
        // A: certificado OFICIAL emitido y descargado; después se marcó al participante como control (QA). Sigue siendo oficial.
        $a = $this->emitir($l, $this->persona($l, 'ANA OFICIAL MARCADA', 'C.C. 7500001'));
        $this->descarga(['emision_id' => $a->id], '2026-11-01 10:00:00');
        $this->emitir($l, $this->persona($l, 'BETO OFICIAL', 'C.C. 7500002'));
        $this->emitir($l, $this->persona($l, 'CARO OFICIAL', 'C.C. 7500003'));
        app(ServicioEventos::class)->marcarParticipantesQa($l, [$a->participante_id], true);

        $f = $this->filaListado($e);
        $this->assertSame([3, 1, 1], [$f['descargas']['vigentes'], $f['descargas']['con_descarga'], $f['descargas']['distintas']]);
        $this->assertSame($this->segunPestana($e), [$f['descargas']['vigentes'], $f['descargas']['con_descarga']]);
        $this->assertSame(2, $f['reales']['vigentes'], 'la columna de vigentes (marca del participante) no cambia: el denominador distinto se informa aparte');

        // B: documento de PRUEBA INTERNA descargado cuyo participante perdió la marca: no cuenta como oficial descargado.
        $b = $this->emitir($l, $this->persona($l, 'QA SIN MARCA', 'C.C. 8500001'));
        $this->comoQa($b);
        $this->descarga(['emision_id' => $b->id], '2026-11-02 10:00:00');

        $f = $this->filaListado($e);
        $this->assertSame([3, 1, 2], [$f['descargas']['vigentes'], $f['descargas']['con_descarga'], $f['descargas']['distintas']]);
        $this->assertSame($this->segunPestana($e), [$f['descargas']['vigentes'], $f['descargas']['con_descarga']]);
        $this->assertSame(3, $f['reales']['vigentes']);
        $this->assertSame(2, $this->props($e)['resumen']['clasificacion_distinta_a_marca'], 'la pestaña avisa de las mismas discrepancias');
        $this->assertTrue(DB::table('cf_participantes')->where('id', $a->participante_id)->value('es_control_qa') == true, 'no se reclasifica a nadie');
    }

    public function test_el_rango_de_fechas_limita_las_filas_pero_no_los_contadores(): void
    {
        $e = $this->evento('Seminario sintético de rango');
        $l = $this->base($e);
        $a = $this->emitir($l, $this->persona($l, 'ANA RANGO', 'C.C. 7600001'));
        $b = $this->emitir($l, $this->persona($l, 'BETO RANGO', 'C.C. 7600002'));
        $this->descarga(['emision_id' => $a->id], '2026-10-01 10:00:00');
        $this->descarga(['emision_id' => $a->id], '2026-11-05 10:00:00');
        $this->descarga(['emision_id' => $b->id], '2026-10-02 10:00:00');

        $todo = $this->props($e);
        $rango = $this->props($e, ['desde' => '2026-11-01', 'hasta' => '2026-11-30']);
        $this->assertSame([2, 1], [$todo['listado']['total'], $rango['listado']['total']], 'el rango limita las FILAS');
        $this->assertSame($todo['resumen'], $rango['resumen'], 'los indicadores del evento no dependen del rango');
        $fila = $rango['listado']['items'][0];
        $this->assertSame([$a->participante_id, 2, '2026-10-01 10:00:00'], [$fila['participacion_id'], $fila['descargas']['vigente']['solicitudes'], $fila['descargas']['vigente']['primera']],
            'los contadores de la fila siguen siendo totales del certificado');
    }

    public function test_los_indicadores_del_listado_no_crecen_en_consultas_con_los_eventos(): void
    {
        $contar = function (int $eventos): int {
            $ids = [];
            for ($k = 1; $k <= $eventos; $k++) {
                $e = $this->evento("Seminario sintético de listado $eventos-$k");
                $l = $this->base($e);
                $em = $this->emitir($l, $this->persona($l, "PERSONA LISTADO $eventos $k", 'C.C. '.(5_000_000 + $eventos * 100 + $k)));
                $this->descarga(['emision_id' => $em->id], '2026-11-01 10:00:00');
                $ids[] = $e->id;
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $r = $this->servicio()->indicadoresPorEvento($ids);
            $q = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertSame($eventos, collect($r)->sum('con_descarga'));

            return $q;
        };
        $this->assertSame($contar(1), $contar(4), 'mismas consultas con 1 o con 4 eventos (sin N+1)');
    }
}
