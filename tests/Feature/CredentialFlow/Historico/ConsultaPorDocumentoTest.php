<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Support\CredentialFlow\Busqueda\ConsultaPorDocumento;
use App\Support\CredentialFlow\Legado\CongeladorLegado;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Portal\FamiliaDocumento;
use App\Support\CredentialFlow\Portal\VinculosIdentidadModerna;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Buscador administrativo por número de documento (E1: solo el servicio). Datos 100 % ficticios: el histórico sintético de PortalTestCase (Ana = 1000001,
 * María/Mario = 3000001, correo compartido = 3000002, Julia CC/CE = 2000004, duplicado idéntico de Gina = 2000001) más bases modernas creadas aquí.
 */
class ConsultaPorDocumentoTest extends PortalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_19_100000_cf_vinculos_identidad_moderna_table.php', '--force' => true])->assertExitCode(0);
    }

    private function consultar(string $documento, ?string $tipo = null): array
    {
        return app(ConsultaPorDocumento::class)->consultar($documento, $tipo);
    }

    /** @return list<array<string,mixed>> todos los registros de la respuesta (identidades + excluidos) */
    private function registros(array $r): array
    {
        return [...array_merge(...array_map(fn ($i) => $i['certificados'], $r['identidades']) ?: [[]]), ...$r['excluidos']];
    }

    private function registro(array $r, string $origen, int $id): array
    {
        $x = collect($this->registros($r))->first(fn ($c) => $c['origen'] === $origen && $c['id'] === $id);
        $this->assertNotNull($x, "falta {$origen} #{$id}");

        return $x;
    }

    /** Base moderna ficticia (opcionalmente asociada a un evento del histórico sintético). */
    private function lote(?string $evento = null): Lote
    {
        $lote = $this->loteCon(0);
        if ($evento !== null) {
            $lote->update(['evento_id' => $this->idEvento($evento)]);
        }

        return $lote->fresh();
    }

    /** Participante ficticio emitido; devuelve su emisión. */
    private function emitido(Lote $lote, string $nombre, string $documento, ?string $modalidad = null): Emision
    {
        $p = $this->participante($lote, $nombre, $documento);
        if ($modalidad !== null) {
            $p->update(['modalidad' => $modalidad]);
        }
        $this->emitirPor($lote, $p)->assertCreated();

        return Emision::query()->where('participante_id', $p->id)->firstOrFail();
    }

    /** Histórico ficticio adicional (copia estructural de uno existente). */
    private function historico(string $clave, string $nombre, ?string $tipo): int
    {
        $base = (array) DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->first();
        unset($base['id']);

        return (int) DB::table('cf_certificados_legado')->insertGetId(array_merge($base, [
            'documento_clave' => $clave, 'documento' => $clave, 'nombre_completo' => $nombre, 'tipo_documento' => $tipo, 'grupo_duplicado' => null,
            'reemplazado_por_emision_id' => null, 'correo' => null, 'correo_normalizado' => null, 'correo_estado' => 'sin_correo', 'conciliacion_estado' => 'ok',
        ]));
    }

    // ── Entrada ──────────────────────────────────────────────────────────────

    public function test_documento_o_tipo_invalidos_no_consultan_nada(): void
    {
        foreach (['', '   ', 'CC', '12', 'abc'] as $v) {
            $r = $this->consultar($v);
            $this->assertFalse($r['valida'], $v);
            $this->assertSame(ConsultaPorDocumento::DOCUMENTO_INVALIDO, $r['motivo']);
            $this->assertSame([], $r['identidades']);
        }
        $r = $this->consultar('1000001', 'XYZ');
        $this->assertFalse($r['valida']);
        $this->assertSame(ConsultaPorDocumento::TIPO_INVALIDO, $r['motivo']);
        $this->assertSame([], $this->registros($r));
    }

    public function test_cualquier_forma_del_numero_da_el_mismo_resultado_y_la_huella_no_contiene_la_cedula(): void
    {
        $base = $this->consultar('1000001');
        $this->assertTrue($base['valida']);
        foreach (['1.000.001', 'C.C. 1.000.001', 'CC1000001', ' cc 1000001 '] as $v) {
            $this->assertSame($base, $this->consultar($v), $v);
        }
        $this->assertSame(64, strlen($base['documento_huella']));
        $this->assertStringNotContainsString('1000001', $base['documento_huella']);
        $this->assertSame($base['documento_huella'], ConsultaPorDocumento::huella('C.C. 1.000.001'));
        $this->assertNotSame($base['documento_huella'], ConsultaPorDocumento::huella('1000002'));
        $this->assertNull(ConsultaPorDocumento::huella('12'));
    }

    // ── Históricos ───────────────────────────────────────────────────────────

    public function test_historicos_de_varios_eventos_con_estados_de_conciliacion_y_congelacion(): void
    {
        $r = $this->consultar('1000001');
        $this->assertSame(1, $r['resumen']['identidades']);
        $this->assertFalse($r['resumen']['conflicto']);
        $this->assertSame(0, $r['resumen']['modernos']);
        $this->assertSame(['ANA UNO PRUEBA'], $r['identidades'][0]['nombres']);
        $this->assertGreaterThanOrEqual(5, $r['resumen']['eventos'], 'eventos 1, 2, 4, 5 y 6');

        $ok = $this->registro($r, 'historico', $this->idCert(1));
        $this->assertSame(['vigente', 'ok', false], [$ok['estado'], $ok['conciliacion']['estado'], $ok['pdf_congelado']]);
        $this->assertSame(['permitida' => false, 'motivo' => ConsultaPorDocumento::NO_CONGELADO], $ok['descarga'], 'no se genera desde la búsqueda');
        $this->assertSame('Curso Alfa 2024', $ok['evento']['nombre']);

        $this->assertSame(['revision_documento', false], [$this->registro($r, 'historico', $this->idCert(17))['conciliacion']['estado'], $this->registro($r, 'historico', $this->idCert(17))['descarga']['permitida']]);
        $revocado = $this->registro($r, 'historico', $this->idCert(18));
        $this->assertSame(['revocado', 'Revocado', false], [$revocado['estado'], $revocado['estado_etiqueta'], $revocado['descarga']['permitida']]);
        $reemplazado = $this->registro($r, 'historico', $this->idCert(19));
        $this->assertSame(['reemplazado', false], [$reemplazado['estado'], $reemplazado['descarga']['permitida']]);
        foreach ($this->registros($r) as $c) {
            $this->assertFalse($c['qa']);
        }
    }

    public function test_un_historico_ya_congelado_se_ofrece_y_la_consulta_nunca_congela(): void
    {
        $id = $this->idCert(1);
        $this->consultar('1000001');
        $this->assertNull(DB::table('cf_certificados_legado')->where('id', $id)->value('pdf_archivo'), 'consultar no genera el PDF');
        $this->assertSame(0, (int) DB::table('cf_certificados_legado')->where('id', $id)->value('intentos_generacion'));

        app(CongeladorLegado::class)->servir(CertificadoLegado::findOrFail($id));   // lo congela el flujo normal (no la búsqueda)
        $c = $this->registro($this->consultar('1000001'), 'historico', $id);
        $this->assertTrue($c['pdf_congelado']);
        $this->assertSame(['permitida' => true, 'motivo' => null], $c['descarga']);
    }

    public function test_duplicado_consolidado_se_muestra_apuntando_a_su_canonico_sin_descarga(): void
    {
        $r = $this->consultar('2000001');
        $this->assertSame(1, $r['resumen']['identidades']);
        $filas = collect($this->registros($r));
        $this->assertSame(2, $filas->count());
        $dup = $filas->firstWhere('conciliacion.estado', 'duplicado_consolidado');
        $this->assertNotNull($dup);
        $this->assertSame(ConsultaPorDocumento::DUPLICADO, $dup['descarga']['motivo']);
        $this->assertContains($dup['duplicado_de'], $filas->pluck('id')->all());
    }

    // ── Modernos ─────────────────────────────────────────────────────────────

    public function test_modernos_de_varios_eventos_con_versiones_vigente_reemplazada_y_revocada(): void
    {
        $v1 = $this->emitido($this->lote('Curso Alfa 2024'), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        $v2id = $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $v1), ['motivo' => 'Corrección ficticia'])->assertCreated()->json('emision.id');
        $otra = $this->emitido($this->lote('Curso Beta 2025'), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $otra), ['motivo' => 'Revocación ficticia'])->assertOk();

        $r = $this->consultar('1000001');
        $this->assertSame(1, $r['resumen']['identidades'], 'mismo nombre y número: la misma identidad que sus históricos');
        $this->assertSame(3, $r['resumen']['modernos']);

        $a = $this->registro($r, 'moderno', $v1->id);
        $this->assertSame(['reemplazado', 1, (int) $v2id, false, ConsultaPorDocumento::VERSION_REEMPLAZADA], [$a['estado'], $a['version'], $a['reemplazada_por'], $a['descarga']['permitida'], $a['descarga']['motivo']]);
        $b = $this->registro($r, 'moderno', (int) $v2id);
        $this->assertSame(['vigente', 2, $v1->id, true], [$b['estado'], $b['version'], $b['reemplaza_a'], $b['descarga']['permitida']]);
        $c = $this->registro($r, 'moderno', $otra->id);
        $this->assertSame(['revocado', false, ConsultaPorDocumento::REVOCADA], [$c['estado'], $c['descarga']['permitida'], $c['descarga']['motivo']]);
        $this->assertSame([$this->idEvento('Curso Alfa 2024'), $this->idEvento('Curso Beta 2025')], [$a['evento']['id'], $c['evento']['id']]);
        $this->assertNotSame($a['codigo'], $b['codigo']);
    }

    public function test_doble_modalidad_del_mismo_evento_son_dos_certificados_independientes(): void
    {
        $lote = $this->lote('Curso Gamma');
        $p = $this->emitido($lote, 'PERSONA DOBLE FICTICIA', 'C.C. 9.300.001', 'Presencial');
        $v = $this->emitido($lote, 'PERSONA DOBLE FICTICIA', 'C.C. 9.300.001', 'Virtual');

        $r = $this->consultar('9300001');
        $this->assertSame(1, $r['resumen']['identidades']);
        $certs = collect($r['identidades'][0]['certificados']);
        $this->assertSame(2, $certs->count());
        $this->assertSame(['Presencial', 'Virtual'], $certs->sortBy('id')->pluck('modalidad')->all());
        $this->assertSame([$p->id, $v->id], $certs->sortBy('id')->pluck('id')->all());
        $this->assertCount(2, $certs->pluck('codigo')->unique());
        $this->assertCount(2, $certs->pluck('participante_id')->unique());
        $this->assertSame([true, true], $certs->pluck('descarga.permitida')->all());
        $this->assertSame([$this->idEvento('Curso Gamma')], $certs->pluck('evento.id')->unique()->values()->all());
    }

    /** El DOCUMENTO emitido pasa a llevar el marcador institucional de prueba interna (como lo imprime la variante QA). */
    private function conMarcadorQa(Emision $e): Emision
    {
        $d = $e->diseno_snapshot;
        $d['elements'][] = ['type' => 'text', 'field' => null, 'text' => 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA', 'x' => 10, 'y' => 10, 'width' => 300, 'height' => 20];
        DB::table('cf_emisiones')->where('id', $e->id)->update(['diseno_snapshot' => json_encode($d)]);

        return $e->fresh();
    }

    public function test_qa_se_decide_por_el_documento_emitido_y_no_se_descarga(): void
    {
        $e = $this->conMarcadorQa($this->emitido($this->lote(), 'CONTACTO QA FICTICIO', 'C.C. 9.400.001'));

        $r = $this->consultar('9400001');
        $c = $this->registro($r, 'moderno', $e->id);
        $this->assertSame([true, true, 'prueba_interna'], [$c['qa'], $c['sin_validez_academica'], $c['clasificacion']]);
        $this->assertSame(1, $r['resumen']['qa']);
        $this->assertSame([false, ConsultaPorDocumento::PRUEBA_INTERNA], [$c['descarga']['permitida'], $c['descarga']['motivo']]);
        // Quitarle después la marca al participante no cambia lo que se emitió.
        $this->assertSame(0, Participante::query()->whereKey($e->participante_id)->where('es_control_qa', true)->count());
    }

    public function test_un_oficial_sigue_oficial_aunque_despues_se_marque_qa_a_su_participante(): void
    {
        $e = $this->emitido($this->lote(), 'PERSONA OFICIAL FICTICIA', 'C.C. 9.400.002');
        Participante::query()->whereKey($e->participante_id)->update(['es_control_qa' => true]);

        $r = $this->consultar('9400002');
        $c = $this->registro($r, 'moderno', $e->id);
        $this->assertSame([false, false, 'oficial'], [$c['qa'], $c['sin_validez_academica'], $c['clasificacion']]);
        $this->assertSame(0, $r['resumen']['qa']);
        $this->assertSame([true, null], [$c['descarga']['permitida'], $c['descarga']['motivo']]);
    }

    public function test_un_diseno_emitido_danado_bloquea_la_descarga_sin_afirmar_nada(): void
    {
        $e = $this->emitido($this->lote(), 'PERSONA DANADA FICTICIA', 'C.C. 9.400.003');
        DB::table('cf_emisiones')->where('id', $e->id)->update(['diseno_snapshot' => '{"elements":"no-es-lista"}']);

        $c = $this->registro($this->consultar('9400003'), 'moderno', $e->id);
        $this->assertSame(['indeterminada', false], [$c['clasificacion'], $c['qa']]);
        $this->assertSame([false, ConsultaPorDocumento::CLASIFICACION_NO_DISPONIBLE], [$c['descarga']['permitida'], $c['descarga']['motivo']]);
    }

    public function test_un_qa_revocado_se_ve_revocado_y_tampoco_se_descarga(): void
    {
        $e = $this->conMarcadorQa($this->emitido($this->lote(), 'CONTACTO QA FICTICIO', 'C.C. 9.400.004'));
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e), ['motivo' => 'Revocación ficticia'])->assertOk();

        $c = $this->registro($this->consultar('9400004'), 'moderno', $e->id);
        $this->assertSame([ConsultaPorDocumento::REVOCADO, true], [$c['estado'], $c['qa']]);
        $this->assertSame([false, ConsultaPorDocumento::REVOCADA], [$c['descarga']['permitida'], $c['descarga']['motivo']]);
    }

    // ── Identidad ────────────────────────────────────────────────────────────

    public function test_grafias_distintas_son_identidades_separadas_aunque_compartan_numero_o_correo(): void
    {
        $r = $this->consultar('3000001');
        $this->assertSame(2, $r['resumen']['identidades']);
        $this->assertTrue($r['resumen']['conflicto']);
        $porNombre = collect($r['identidades'])->mapWithKeys(fn ($i) => [count($i['certificados']) => $i]);
        $this->assertCount(3, $porNombre[3]['certificados'], 'María: tildes, espacios y puntuación son la misma grafía');
        $this->assertCount(2, $porNombre[2]['certificados'], 'Mario es otra identidad');
        $this->assertSame(['A', 'B'], array_column($r['identidades'], 'etiqueta'));

        $compartido = $this->consultar('3000002');
        $this->assertSame(2, $compartido['resumen']['identidades'], 'compartir correo no une a dos personas');

        // Un moderno con otra grafía del nombre de Ana también queda aparte (sin vínculo).
        $this->emitido($this->lote(), 'ANNA UNO PRUEBA', 'C.C. 1.000.001');
        $this->assertSame(2, $this->consultar('1000001')['resumen']['identidades']);
    }

    public function test_un_vinculo_vigente_agrupa_el_moderno_con_su_identidad_historica(): void
    {
        $lote = app(ImportadorParticipantes::class)->importar(UploadedFile::fake()->createWithContent('base.csv', "nombre_completo,documento,correo\nANNA UNO PRUEBA,1.000.001,".self::CORREO."\n"),
            $this->plantillaLista(), 'Base', null, ['evento' => 'Evento moderno ficticio', 'fecha' => 'el 1 de octubre de 2026', 'intensidad_horaria' => '4 horas']);
        $this->emitirPor($lote, $lote->participantes()->first())->assertCreated();

        $antes = $this->consultar('1000001');
        $this->assertSame(2, $antes['resumen']['identidades']);
        $this->assertFalse($antes['identidades'][0]['con_vinculo']);

        app(VinculosIdentidadModerna::class)->crear('1.000.001', self::CORREO, self::NOMBRE, 'ANNA UNO PRUEBA', 'Vínculo ficticio de prueba para el buscador.', $this->admin()->id);
        $vinculos = DB::table('cf_vinculos_identidad')->count();

        $despues = $this->consultar('1000001');
        $this->assertSame(1, $despues['resumen']['identidades']);
        $this->assertTrue($despues['identidades'][0]['con_vinculo']);
        $this->assertSame(['ANA UNO PRUEBA', 'ANNA UNO PRUEBA'], collect($despues['identidades'][0]['nombres'])->sort()->values()->all());
        $this->assertSame($vinculos, DB::table('cf_vinculos_identidad')->count(), 'la consulta no crea vínculos');
    }

    public function test_tipos_distintos_con_el_mismo_numero(): void
    {
        // Julia: dos históricos SIN prefijo con tipos CC y CE (misma grafía) → una identidad marcada, nada excluido.
        $r = $this->consultar('2000004');
        $this->assertSame(1, $r['resumen']['identidades']);
        $this->assertSame(['CC', 'CE'], $r['identidades'][0]['tipos']);
        $this->assertTrue($r['identidades'][0]['tipos_distintos']);
        $this->assertTrue($r['resumen']['conflicto']);
        $this->assertSame([], $r['excluidos']);

        // Un moderno guardado con prefijo T.I. en esa unidad ambigua queda EXCLUIDO, sin descarga.
        $ti = $this->emitido($this->lote(), 'JULIA TRECE', 'T.I. 2.000.004');
        $this->assertArrayHasKey($ti->participante_id, FamiliaDocumento::excluidos('2000004')['participantes']);
        $r = $this->consultar('2000004');
        $this->assertSame(1, $r['resumen']['excluidos']);
        $this->assertSame(['moderno', $ti->id, 'TI', true, false, ConsultaPorDocumento::IDENTIDAD_AMBIGUA],
            [$r['excluidos'][0]['origen'], $r['excluidos'][0]['id'], $r['excluidos'][0]['tipo'], $r['excluidos'][0]['excluido'], $r['excluidos'][0]['descarga']['permitida'], $r['excluidos'][0]['descarga']['motivo']]);
        foreach ($r['identidades'][0]['certificados'] as $c) {
            $this->assertNotSame($ti->id, $c['id']);
        }

        // Filtro por tipo: solo ese tipo o sin tipo registrado; el resto solo se cuenta.
        $ce = $this->consultar('2000004', 'C.E.');
        $this->assertSame('CE', $ce['tipo']);
        $this->assertSame(['CE'], collect($this->registros($ce))->pluck('tipo')->unique()->values()->all());
        $this->assertSame(2, $ce['resumen']['otros_tipos'], 'el histórico CC y el moderno T.I.');
    }

    public function test_los_ceros_a_la_izquierda_son_otro_documento(): void
    {
        $cero = $this->historico('01000001', 'ANA UNO PRUEBA', 'CC');
        $this->assertNotContains($cero, collect($this->registros($this->consultar('1000001')))->pluck('id')->all());
        $r = $this->consultar('01000001');
        $this->assertSame([$cero], collect($this->registros($r))->pluck('id')->all());
    }

    public function test_nunca_aparecen_registros_de_otro_numero(): void
    {
        // Un homónimo exacto de Ana con OTRO número y el mismo correo, histórico y moderno.
        $this->historico('1000009', 'ANA UNO PRUEBA', 'CC');
        $e = $this->emitido($this->lote(), 'ANA UNO PRUEBA', 'C.C. 1.000.009');
        Participante::query()->whereKey($e->participante_id)->update(['correo' => self::CORREO, 'correo_normalizado' => self::CORREO, 'correo_estado' => Participante::CORREO_VALIDO]);

        $raices = collect($this->registros($this->consultar('1000001')))->map(fn ($c) => FamiliaDocumento::raiz($c['documento']))->unique()->values()->all();
        $this->assertSame(['1000001'], $raices);
        $this->assertSame(2, collect($this->registros($this->consultar('1000009')))->count());
    }

    // ── Solo lectura ─────────────────────────────────────────────────────────

    public function test_la_consulta_solo_ejecuta_lecturas_y_no_cambia_nada(): void
    {
        $this->emitido($this->lote('Curso Alfa 2024'), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        $this->emitido($this->lote(), 'JULIA TRECE', 'T.I. 2.000.004');
        $tablas = ['cf_certificados_legado', 'cf_emisiones', 'cf_participantes', 'cf_descargas', 'cf_codigos_historicos', 'cf_vinculos_identidad', 'cf_correos', 'movimientos'];
        $foto = fn () => array_map(fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get())), array_combine($tablas, $tablas));
        $archivos = fn () => Storage::disk('local')->allFiles();

        $antes = [$foto(), $archivos()];
        $sql = $this->sentencias(function () {
            foreach (['1000001', '2000004', '3000001', '2000001', '9999999'] as $d) {
                $this->consultar($d);
                $this->consultar($d, 'CC');
            }
        });

        $this->assertNotEmpty($sql);
        $escrituras = array_filter($sql, fn ($s) => ! preg_match('/^\s*(select|pragma)\b/i', $s));
        $this->assertSame([], array_values($escrituras), 'solo SELECT');
        $this->assertSame($antes, [$foto(), $archivos()]);
    }
}
