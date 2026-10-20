<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\CamposDinamicos;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Emisiones\DatosRequeridos;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Emisiones\SnapshotCredencial;
use App\Support\CredentialFlow\Generacion\DatosCredencial;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException as E;
use App\Support\CredentialFlow\Generacion\PlanificadorTexto;
use App\Support\CredentialFlow\Participantes\DatosDeParticipante;
use App\Support\CredentialFlow\Participantes\ValidadorDatosComunes;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;

/**
 * F2 de la importación inteligente: una sola plantilla institucional reutilizable. La modalidad, la ciudad y los textos configurables viven en los datos
 * comunes de la base (sin migración), se imprimen LITERALES y solo se exigen cuando el diseño los usa. Todo lo anterior (cinco campos, snapshots, huellas,
 * plantillas QA, diseños sin los campos nuevos) debe seguir exactamente igual.
 */
class DatosDinamicosF2Test extends EmisionesTestCase
{
    private const CINCO = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'];

    private const MODALIDAD = 'Virtual-Sincrónico y Remoto';

    private const TEXTO = "Se certifica la asistencia al módulo.\nLa intensidad horaria fue de cinco horas.";

    /** Diseño con los cinco campos y, además, los campos nuevos indicados (uno por fila, debajo). */
    private function plantillaCon(array $nuevos = [], array $cambios = []): Plantilla
    {
        $els = array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 20 + $i * 40, 'height' => 30]), self::CINCO, array_keys(self::CINCO));
        foreach ($nuevos as $i => $campo) {
            $els[] = $this->elemento(array_merge(['field' => $campo, 'y' => 230 + $i * 70, 'height' => 60, 'fontSize' => 14], $cambios));
        }

        return $this->plantillaLista($els);
    }

    private function loteDe(Plantilla $plantilla, array $extra = [], int $participantes = 1): Lote
    {
        $lote = $this->loteCon($participantes, $plantilla);
        $lote->update(['datos_comunes' => array_merge($lote->datos_comunes, $extra)]);

        return $lote->fresh();
    }

    private function emitir(Lote $lote): array
    {
        return app(EmisorLote::class)->emitirPendientes($lote, null);
    }

    private function textosDelPdf(Emision $emision): array
    {
        return array_column((new InspectorPdf(Storage::disk('local')->get($emision->pdf_archivo)))->textos(), 'texto');
    }

    // ── Catálogo y datos comunes ──────────────────────────────────────────────

    public function test_los_campos_nuevos_son_opcionales_y_no_se_guardan_si_no_se_escriben(): void
    {
        $r = ValidadorDatosComunes::validar(['evento' => 'Evento X', 'fecha' => '16 de octubre de 2026', 'intensidad_horaria' => '5 horas']);

        $this->assertSame([], $r['errores']);
        $this->assertSame(['evento', 'fecha', 'intensidad_horaria'], array_keys($r['datos']), 'ninguna clave nueva, ni vacía');

        $vacios = ValidadorDatosComunes::validar(['evento' => 'Evento X', 'fecha' => 'F', 'intensidad_horaria' => '5 horas', 'modalidad' => '   ', 'ciudad' => null, 'texto_certificacion' => "\n \n", 'texto_complementario' => '']);
        $this->assertSame([], $vacios['errores']);
        $this->assertSame(['evento', 'fecha', 'intensidad_horaria'], array_keys($vacios['datos']));
    }

    public function test_la_modalidad_se_guarda_literal_solo_con_los_espacios_normalizados(): void
    {
        foreach ([self::MODALIDAD, 'presencial', 'Virtual - Sincrónico y Remoto', 'HÍBRIDA (presencial + virtual)'] as $m) {
            $r = ValidadorDatosComunes::validar(['evento' => 'E', 'fecha' => 'F', 'intensidad_horaria' => '1 hora', 'modalidad' => $m]);
            $this->assertSame($m, $r['datos']['modalidad'], 'ni mayúsculas ni traducción a un catálogo');
        }

        $espacios = ValidadorDatosComunes::validar(['evento' => 'E', 'fecha' => 'F', 'intensidad_horaria' => '1 hora', 'modalidad' => "  Virtual-Sincrónico \n y   Remoto "]);
        $this->assertSame(self::MODALIDAD, $espacios['datos']['modalidad']);
    }

    public function test_textos_configurables_cortos_y_de_varias_lineas(): void
    {
        $r = ValidadorDatosComunes::validar([
            'evento' => 'E', 'fecha' => 'F', 'intensidad_horaria' => '1 hora',
            'texto_certificacion' => "  Primera línea  \r\n\r\n   Segunda   línea \n",
            'texto_complementario' => 'Una sola línea',
        ]);

        $this->assertSame([], $r['errores']);
        $this->assertSame("Primera línea\nSegunda línea", $r['datos']['texto_certificacion']);
        $this->assertSame('Una sola línea', $r['datos']['texto_complementario']);
    }

    public function test_los_datos_opcionales_invalidos_se_rechazan_con_un_mensaje_claro(): void
    {
        $base = ['evento' => 'E', 'fecha' => 'F', 'intensidad_horaria' => '1 hora'];

        $largo = ValidadorDatosComunes::validar($base + ['texto_certificacion' => str_repeat('a', 601)]);
        $this->assertSame('Texto de certificación no puede superar los 600 caracteres.', $largo['errores']['texto_certificacion']);

        $modalidad = ValidadorDatosComunes::validar($base + ['modalidad' => str_repeat('a', 121)]);
        $this->assertSame('Modalidad no puede superar los 120 caracteres.', $modalidad['errores']['modalidad']);

        $lineas = ValidadorDatosComunes::validar($base + ['texto_complementario' => implode("\n", array_fill(0, 9, 'x'))]);
        $this->assertSame('Texto complementario no puede tener más de 8 líneas.', $lineas['errores']['texto_complementario']);

        $control = ValidadorDatosComunes::validar($base + ['ciudad' => "Bogot\x07á"]);
        $this->assertSame('Ciudad tiene caracteres de control no permitidos.', $control['errores']['ciudad']);

        $fuente = ValidadorDatosComunes::validar($base + ['modalidad' => 'Virtual 日本']);
        $this->assertStringContainsString('la fuente Outfit no puede imprimir', $fuente['errores']['modalidad']);

        $this->assertArrayNotHasKey('modalidad', $fuente['datos']);
    }

    public function test_los_cinco_campos_originales_siguen_siendo_obligatorios(): void
    {
        $r = ValidadorDatosComunes::validar(['modalidad' => self::MODALIDAD]);

        $this->assertSame(['evento', 'fecha', 'intensidad_horaria'], array_keys($r['errores']));
    }

    // ── Datos de generación y snapshots ───────────────────────────────────────

    public function test_una_base_sin_datos_nuevos_entrega_exactamente_los_cinco_valores_de_siempre(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();

        $datos = DatosDeParticipante::para($p, $lote)->todos();

        $this->assertSame(self::CINCO, array_keys($datos));
    }

    public function test_una_base_con_datos_nuevos_los_entrega_sin_modificarlos(): void
    {
        $lote = $this->loteDe($this->plantillaLista(), ['modalidad' => self::MODALIDAD, 'texto_certificacion' => self::TEXTO, 'ciudad' => '']);
        $p = $lote->participantes()->first();

        $datos = DatosDeParticipante::para($p, $lote)->todos();

        $this->assertSame(self::MODALIDAD, $datos['modalidad']);
        $this->assertSame(self::TEXTO, $datos['texto_certificacion']);
        $this->assertArrayNotHasKey('ciudad', $datos, 'un dato vacío no se entrega');
        $this->assertArrayNotHasKey('texto_complementario', $datos);
    }

    public function test_solo_los_textos_largos_admiten_saltos_de_linea_en_el_dato(): void
    {
        $this->assertSame(self::TEXTO, DatosCredencial::fromArray(['texto_certificacion' => self::TEXTO])->valor('texto_certificacion'));
        $this->assertSame("a\nb", DatosCredencial::fromArray(['texto_complementario' => "a\r\nb"])->valor('texto_complementario'), 'CRLF se unifica');

        foreach (['modalidad', 'ciudad', 'evento', 'nombre_completo'] as $campo) {
            try {
                DatosCredencial::fromArray([$campo => "a\nb"]);
                $this->fail("$campo no debe admitir saltos de línea");
            } catch (E $e) {
                $this->assertSame(E::DATO_INVALIDO, $e->codigo);
            }
        }
        $this->expectException(E::class);
        DatosCredencial::fromArray(['texto_certificacion' => "a\x07b"]);   // otros caracteres de control siguen prohibidos
    }

    public function test_el_snapshot_de_un_diseno_anterior_no_cambia_aunque_la_base_tenga_datos_nuevos(): void
    {
        $anterior = $this->plantillaLista();                       // diseño de siempre: solo los cinco campos
        $sin = $this->loteCon(1, $anterior);
        $con = $this->loteDe($anterior, ['modalidad' => self::MODALIDAD, 'texto_certificacion' => self::TEXTO]);

        [, $hash] = SnapshotCredencial::cargarPlantilla($anterior);
        $a = SnapshotCredencial::capturar($sin->participantes()->first(), $sin, $anterior, $hash);
        $b = SnapshotCredencial::capturar($con->participantes()->first(), $con, $anterior, $hash);

        $cinco = self::CINCO;
        sort($cinco);   // el snapshot ordena los valores impresos y luego agrega los dos nombres administrativos
        $esperadas = [...$cinco, 'lote_nombre', 'plantilla_nombre'];
        $this->assertSame($esperadas, array_keys($a->datos));
        $this->assertSame($esperadas, array_keys($b->datos), 'ningún campo nuevo, ni vacío, en el snapshot de un diseño anterior');
    }

    public function test_el_snapshot_de_un_diseno_nuevo_incluye_los_datos_que_imprime(): void
    {
        $nueva = $this->plantillaCon(['modalidad', 'texto_certificacion']);
        $lote = $this->loteDe($nueva, ['modalidad' => self::MODALIDAD, 'texto_certificacion' => self::TEXTO, 'ciudad' => 'Bogotá']);

        [, $hash] = SnapshotCredencial::cargarPlantilla($nueva);
        $s = SnapshotCredencial::capturar($lote->participantes()->first(), $lote, $nueva, $hash);

        $this->assertSame(self::MODALIDAD, $s->datos['modalidad']);
        $this->assertSame(self::TEXTO, $s->datos['texto_certificacion']);
        $this->assertArrayNotHasKey('ciudad', $s->datos, 'la base tiene ciudad pero este diseño no la imprime');
        $this->assertArrayNotHasKey('texto_complementario', $s->datos);
    }

    public function test_la_huella_cambia_solo_cuando_cambian_los_datos_de_la_base(): void
    {
        $plantilla = $this->plantillaLista();
        $lote = $this->loteCon(1, $plantilla);
        $p = $lote->participantes()->first();

        $antes = SnapshotCredencial::huella($p, $lote, $plantilla);
        $this->assertSame($antes, SnapshotCredencial::huella($p, $lote->fresh(), $plantilla->fresh()), 'determinista');

        $lote->update(['datos_comunes' => $lote->datos_comunes + ['modalidad' => self::MODALIDAD]]);
        $this->assertNotSame($antes, SnapshotCredencial::huella($p, $lote->fresh(), $plantilla));
    }

    public function test_los_certificados_ya_emitidos_no_cambian_si_despues_se_editan_los_datos_de_la_base(): void
    {
        $lote = $this->loteCon(1);
        $this->emitir($lote);
        $emision = Emision::where('lote_id', $lote->id)->firstOrFail();
        $snapshot = $emision->datos_snapshot;
        $pdf = Storage::disk('local')->get($emision->pdf_archivo);

        $this->actingAs($this->admin())->put(route('credential-flow.lotes.update', $lote), [
            'nombre' => $lote->nombre, 'evento' => 'CONGRESO DE FINANZAS', 'fecha' => '17 de septiembre de 2026', 'intensidad_horaria' => '30 horas', 'modalidad' => self::MODALIDAD,
        ])->assertSessionHasNoErrors();

        $emision->refresh();
        $this->assertSame($snapshot, $emision->datos_snapshot);
        $this->assertSame($pdf, Storage::disk('local')->get($emision->pdf_archivo));
        $this->assertSame(hash('sha256', $pdf), $emision->pdf_hash);
    }

    // ── Emisión y vista previa ────────────────────────────────────────────────

    public function test_si_el_diseno_imprime_un_dato_que_la_base_no_tiene_la_emision_se_bloquea_con_un_mensaje_claro(): void
    {
        $lote = $this->loteDe($this->plantillaCon(['modalidad', 'texto_certificacion']), [], 3);

        try {
            $this->emitir($lote);
            $this->fail('Debió bloquearse.');
        } catch (EmisionException $e) {
            $this->assertSame(EmisionException::DATOS_DEL_DISENO_INCOMPLETOS, $e->codigo);
            $this->assertSame(422, $e->estadoHttp);
            $this->assertSame('La plantilla imprime «Modalidad», «Texto de certificación» y esta base no tiene esos datos. Complétalo en «Editar base» antes de emitir. No se emitió ningún certificado.', $e->getMessage());
            $this->assertSame([['campo' => 'Modalidad'], ['campo' => 'Texto de certificación']], $e->detalles);
        }
        $this->assertSame(0, Emision::count());
        $this->assertSame([], $this->archivosDeEmisiones());

        // Por HTTP: respuesta entendible, sin emitir nada.
        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(422);
        $this->assertStringContainsString('Complétalo en «Editar base»', $r->json('error.message'));
        $this->assertSame(0, Emision::count());
    }

    public function test_el_flujo_de_la_base_avisa_que_falta_completar_lo_que_imprime_la_plantilla(): void
    {
        $lote = $this->loteDe($this->plantillaCon(['modalidad']));

        $faltan = $this->actingAs($this->admin())->get(route('credential-flow.lotes.show', $lote))->assertOk()->viewData('page')['props']['lote']['flujo']['faltan'];
        $this->assertContains('Completar en «Editar base» lo que imprime la plantilla: Modalidad.', $faltan);

        $lote->update(['datos_comunes' => $lote->datos_comunes + ['modalidad' => self::MODALIDAD]]);
        $faltan = $this->actingAs($this->admin())->get(route('credential-flow.lotes.show', $lote))->assertOk()->viewData('page')['props']['lote']['flujo']['faltan'];
        $this->assertNotContains('Completar en «Editar base» lo que imprime la plantilla: Modalidad.', $faltan);
    }

    public function test_con_los_datos_completos_el_pdf_imprime_la_modalidad_y_los_textos_exactos(): void
    {
        $plantilla = $this->plantillaCon(['modalidad', 'texto_certificacion'], ['multiline' => true]);
        $lote = $this->loteDe($plantilla, ['modalidad' => self::MODALIDAD, 'texto_certificacion' => self::TEXTO]);

        $this->emitir($lote);

        $emision = Emision::where('lote_id', $lote->id)->firstOrFail();
        $textos = $this->textosDelPdf($emision);
        $this->assertContains(self::MODALIDAD, $textos, 'la modalidad confirmada se imprime tal cual');
        $this->assertContains('Se certifica la asistencia al módulo.', $textos);
        $this->assertContains('La intensidad horaria fue de cinco horas.', $textos);
        $this->assertSame(self::MODALIDAD, $emision->datos_snapshot['modalidad']);
    }

    public function test_la_vista_previa_del_participante_usa_los_datos_de_la_base(): void
    {
        $plantilla = $this->plantillaCon(['modalidad']);
        $lote = $this->loteDe($plantilla, ['modalidad' => 'Presencial']);
        $participante = $lote->participantes()->first();

        $r = $this->actingAs($this->admin())->get(route('credential-flow.participantes.pdf', [$lote, $participante]))->assertOk();
        $textos = array_column((new InspectorPdf($r->getContent()))->textos(), 'texto');
        $this->assertContains('Presencial', $textos);
        $this->assertNotContains(self::MODALIDAD, $textos, 'no se sustituye por una modalidad genérica');
    }

    public function test_la_vista_previa_sin_el_dato_que_el_diseno_imprime_no_inventa_nada(): void
    {
        $lote = $this->loteDe($this->plantillaCon(['modalidad']));
        $participante = $lote->participantes()->first();

        $r = $this->actingAs($this->admin())->getJson(route('credential-flow.participantes.pdf', [$lote, $participante]));

        $this->assertNotSame(200, $r->getStatusCode());
        $this->assertSame('CAMPO_SIN_VALOR', $r->json('error.code'));
        $this->assertSame('Falta el dato «Modalidad» para generar el certificado.', $r->json('error.message'));
    }

    public function test_un_diseno_anterior_no_exige_ningun_dato_nuevo(): void
    {
        $plantilla = $this->plantillaLista();
        $lote = $this->loteCon(2, $plantilla);

        $this->assertSame([], DatosRequeridos::faltantes($plantilla->diseno, $lote->datos_comunes));
        $this->assertSame(2, $this->emitir($lote)['total']);
    }

    // ── Editor ────────────────────────────────────────────────────────────────

    public function test_el_editor_ofrece_los_campos_nuevos_con_su_ejemplo_y_su_limite(): void
    {
        $plantilla = $this->plantillaLista();

        $this->actingAs($this->admin())->get(route('credential-flow.plantillas.editor', $plantilla))->assertInertia(fn ($page) => $page
            ->has('schema.campos', 9)
            ->where('schema.campos.5.key', 'modalidad')
            ->where('schema.campos.5.etiqueta', 'Modalidad')
            ->where('schema.campos.5.maxLongitud', 120)
            ->where('schema.campos.6.key', 'texto_certificacion')
            ->where('schema.campos.7.key', 'texto_complementario')
            ->where('schema.campos.8.key', 'ciudad'));
    }

    public function test_el_editor_guarda_los_campos_nuevos_con_prefijo_sufijo_y_varias_lineas(): void
    {
        $plantilla = $this->plantillaLista();
        $diseno = ['page' => ['width' => 792, 'height' => 612], 'elements' => [
            $this->elemento(['field' => 'modalidad', 'prefix' => 'Modalidad: ', 'suffix' => '.', 'height' => 40]),
            $this->elemento(['field' => 'texto_certificacion', 'multiline' => true, 'y' => 200, 'height' => 120]),
            $this->elemento(['field' => 'ciudad', 'y' => 400]),
        ]];

        $this->actingAs($this->admin())->putJson(route('credential-flow.plantillas.diseno.update', $plantilla), ['diseno' => $diseno])->assertRedirect();

        $guardada = Plantilla::findOrFail($plantilla->id);
        $this->assertSame(DisenoSchema::VERSION_TEXTO_AVANZADO, (int) $guardada->schema_version);
        $this->assertSame(['modalidad', 'texto_certificacion', 'ciudad'], DisenoSchema::camposUsados($guardada->diseno));
        $this->assertStringNotContainsString(self::MODALIDAD, json_encode($guardada->diseno, JSON_UNESCAPED_UNICODE), 'el ejemplo del editor nunca se guarda en el diseño');
    }

    public function test_el_editor_sigue_rechazando_campos_que_no_existen(): void
    {
        $plantilla = $this->plantillaLista();
        $diseno = ['page' => ['width' => 792, 'height' => 612], 'elements' => [$this->elemento(['field' => 'texto_libre'])]];

        $this->actingAs($this->admin())->putJson(route('credential-flow.plantillas.diseno.update', $plantilla), ['diseno' => $diseno])->assertStatus(422);
    }

    public function test_el_pdf_de_prueba_del_editor_dibuja_los_campos_nuevos_con_ejemplos(): void
    {
        $plantilla = $this->plantillaCon(['modalidad', 'texto_certificacion', 'texto_complementario', 'ciudad'], ['multiline' => true]);

        $r = $this->actingAs($this->admin())->get(route('credential-flow.plantillas.pdf-prueba', $plantilla))->assertOk();
        $textos = implode(' | ', array_column((new InspectorPdf($r->getContent()))->textos(), 'texto'));

        $this->assertStringContainsString('Virtual-Sincrónico y Remoto', $textos);
        $this->assertStringContainsString('BOGOTÁ D.C.', $textos);
    }

    // ── Textos largos ─────────────────────────────────────────────────────────

    private function plan(string $campo, string $valor, array $cambios = []): array
    {
        $el = $this->elemento(array_merge(['field' => $campo, 'x' => 96, 'y' => 100, 'width' => 600, 'height' => 40, 'fontSize' => 14], $cambios));

        return PlanificadorTexto::planificar(['page' => ['width' => 792, 'height' => 612], 'elements' => [$el]], DatosCredencial::fromArray([$campo => $valor]), ['width' => 792.0, 'height' => 612.0], 3);
    }

    public function test_un_texto_largo_se_reparte_en_varias_lineas_si_el_elemento_lo_permite(): void
    {
        $texto = str_repeat('Se certifica la asistencia y aprobación del módulo. ', 4);
        $planes = $this->plan('texto_certificacion', trim($texto), ['multiline' => true, 'height' => 100]);

        $this->assertGreaterThan(1, count($planes[0]->lineas));
    }

    public function test_un_texto_con_saltos_de_linea_respeta_cada_linea(): void
    {
        $planes = $this->plan('texto_certificacion', self::TEXTO, ['multiline' => true, 'height' => 100]);

        $lineas = array_column($planes[0]->lineas, 'texto');
        $this->assertContains('Se certifica la asistencia al módulo.', $lineas);
        $this->assertContains('La intensidad horaria fue de cinco horas.', $lineas);
    }

    public function test_un_texto_demasiado_largo_para_una_sola_linea_avisa_que_hay_que_activar_varias_lineas(): void
    {
        try {
            $this->plan('texto_certificacion', str_repeat('Texto muy largo de certificación. ', 12));
            $this->fail('Debió fallar.');
        } catch (E $e) {
            $this->assertSame(E::NO_CABE, $e->codigo);
            $this->assertStringContainsString('activa «varias líneas»', $e->getMessage());
        }

        // En un campo que no admite saltos de línea el mensaje de siempre no cambia.
        try {
            $this->plan('modalidad', str_repeat('Modalidad extensa ', 6), ['width' => 150]);
            $this->fail('Debió fallar.');
        } catch (E $e) {
            $this->assertSame('El valor del campo no cabe en su caja aun reduciendo el tamaño al 70 %. Ensancha la caja o baja el tamaño.', $e->getMessage());
        }
    }

    public function test_un_texto_que_no_cabe_ni_en_varias_lineas_es_un_error_claro_y_no_se_trunca(): void
    {
        try {
            $this->plan('texto_certificacion', str_repeat('Texto largo de certificación para una caja pequeña. ', 10), ['multiline' => true, 'height' => 30, 'width' => 200]);
            $this->fail('Debió fallar.');
        } catch (E $e) {
            $this->assertSame(E::NO_CABE, $e->codigo);
            $this->assertStringContainsString('ni repartido en varias líneas', $e->getMessage());
        }
    }

    // ── Edición de la base ────────────────────────────────────────────────────

    public function test_editar_la_base_guarda_conserva_o_quita_los_datos_opcionales(): void
    {
        $lote = $this->loteCon(1);
        $base = ['nombre' => $lote->nombre, 'evento' => 'CONGRESO DE FINANZAS', 'fecha' => '17 de septiembre de 2026', 'intensidad_horaria' => '30 horas'];
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('credential-flow.lotes.update', $lote), $base + ['modalidad' => self::MODALIDAD, 'texto_certificacion' => self::TEXTO])->assertSessionHasNoErrors();
        $this->assertSame(self::MODALIDAD, $lote->fresh()->datos_comunes['modalidad']);
        $this->assertSame(self::TEXTO, $lote->fresh()->datos_comunes['texto_certificacion']);

        // Un cliente que no menciona los opcionales no los borra.
        $this->actingAs($admin)->put(route('credential-flow.lotes.update', $lote), $base)->assertSessionHasNoErrors();
        $this->assertSame(self::MODALIDAD, $lote->fresh()->datos_comunes['modalidad']);

        // Vaciarlos en el formulario sí los quita (la clave desaparece, no queda vacía).
        $this->actingAs($admin)->put(route('credential-flow.lotes.update', $lote), $base + ['modalidad' => '', 'texto_certificacion' => ''])->assertSessionHasNoErrors();
        $this->assertSame(['evento', 'fecha', 'intensidad_horaria'], array_keys($lote->fresh()->datos_comunes));
    }

    public function test_editar_la_base_rechaza_un_dato_opcional_invalido(): void
    {
        $lote = $this->loteCon(1);

        $this->actingAs($this->admin())->put(route('credential-flow.lotes.update', $lote), [
            'nombre' => 'X', 'evento' => 'E', 'fecha' => 'F', 'intensidad_horaria' => '1 hora', 'modalidad' => str_repeat('a', 121),
        ])->assertSessionHasErrors('modalidad');
        $this->assertArrayNotHasKey('modalidad', $lote->fresh()->datos_comunes);
    }

    // ── Plantillas QA y bases anteriores ──────────────────────────────────────

    public function test_las_plantillas_qa_no_cambian_y_nunca_exigen_datos_nuevos(): void
    {
        $this->artisan('migrate', ['--path' => ['database/migrations/2026_10_21_100000_cf_contactos_qa_y_plantilla_qa.php'], '--force' => true])->assertExitCode(0);
        $campos = self::CINCO;
        $els = array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 60 + $i * 60]), $campos, array_keys($campos));
        $els[] = $this->elemento(['type' => 'text', 'text' => 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA', 'y' => 380, 'fontSize' => 18]);
        $qa = $this->plantillaLista($els);
        $qa->update(['nombre' => 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA', 'es_plantilla_qa' => true]);
        $qa = $qa->fresh();

        $lote = $this->loteDe($this->plantillaLista(), ['modalidad' => self::MODALIDAD, 'texto_certificacion' => self::TEXTO]);
        $participante = $lote->participantes()->first();

        $this->assertSame([], DatosRequeridos::faltantes($qa->diseno, []));
        [, $hash] = SnapshotCredencial::cargarPlantilla($qa);
        $s = SnapshotCredencial::capturar($participante, $lote, $qa, $hash);
        $this->assertSame(['documento', 'evento', 'fecha', 'intensidad_horaria', 'nombre_completo', 'lote_nombre', 'plantilla_nombre'], array_keys($s->datos));
        $this->assertTrue($qa->es_plantilla_qa);
        $this->assertSame($els, $qa->diseno['elements'], 'el diseño de la plantilla QA queda intacto');
    }

    public function test_una_base_anterior_sin_datos_nuevos_se_edita_sin_ganar_claves(): void
    {
        $lote = $this->loteCon(1);
        $antes = $lote->datos_comunes;

        $this->actingAs($this->admin())->put(route('credential-flow.lotes.update', $lote), [
            'nombre' => 'Nuevo nombre', 'evento' => $antes['evento'], 'fecha' => $antes['fecha'], 'intensidad_horaria' => $antes['intensidad_horaria'],
        ])->assertSessionHasNoErrors();

        $this->assertSame($antes, $lote->fresh()->datos_comunes, 'ninguna clave nueva, ni vacía');
    }

    public function test_los_campos_originales_conservan_su_comportamiento_en_el_catalogo(): void
    {
        $todos = CamposDinamicos::todos();

        $this->assertSame(['mayusculas', 'literal', 'mayusculas', 'literal', 'literal'], array_map(fn ($k) => $todos[$k]['formato'], self::CINCO));
        $this->assertSame([100, 40, 255, 80, 255], array_map(fn ($k) => $todos[$k]['maxLongitud'], self::CINCO));
        foreach (self::CINCO as $k) {
            $this->assertFalse($todos[$k]['multilinea'], $k);
        }
    }
}
