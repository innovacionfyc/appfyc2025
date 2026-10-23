<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Importacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Http\UploadedFile;
use Tests\Feature\CredentialFlow\Support\ExcelSolicitud as X;

/**
 * Recorrido GUIADO del asistente (Subir Excel → Evento → Datos → Personas → PDF → Crear base). La pantalla es Vue, así que aquí se prueba lo que sí es verificable desde PHP:
 *  · que cada pendiente que el servidor puede devolver tenga una etapa a la que llevar al administrador («Revisar este dato»),
 *  · que el orden de las etapas y de los pendientes del Excel sintético de 17 personas sea el que la guía anuncia,
 *  · que lo que puede quedar pendiente (tipo de documento y elegibilidad) NUNCA bloquee crear la base,
 *  · y las reglas de la interfaz: nada se confirma solo, ningún botón apagado sin explicación, «Cupos» y «Completo» no se aprueban por su cuenta.
 * El comportamiento visual (390 px, teclado, foco) se comprueba en el navegador.
 */
class ImportacionGuiadaTest extends EmisionesTestCase
{
    private Plantilla $plantilla;

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
            'database/migrations/2026_10_21_100000_cf_contactos_qa_y_plantilla_qa.php',
            'database/migrations/2026_10_24_100000_cf_importaciones_table.php',
        ], '--force' => true])->assertExitCode(0);

        config(['credential_flow.importacion_inteligente.activa' => true]);
        $this->plantilla = $this->plantillaLista([$this->elemento(['field' => 'nombre_completo', 'y' => 20, 'height' => 30, 'fontSize' => 12])]);
        EventoCertificacion::unguarded(fn () => EventoCertificacion::create([
            'id' => 631, 'nombre' => X::EVENTO_631, 'nombre_normalizado' => NombreConservador::normalizar(X::EVENTO_631), 'anio' => 2026,
            'fecha_texto' => '16 DE OCTUBRE DE 2026', 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW,
        ]));
    }

    private function js(string $ruta): string
    {
        return file_get_contents(resource_path('js/'.$ruta));
    }

    private function analizar(?string $bytes = null): int
    {
        return $this->actingAs($this->admin())->post(route('credential-flow.importaciones.analizar'), ['archivo' => UploadedFile::fake()->createWithContent('solicitud.xlsx', $bytes ?? X::bytes())], ['Accept' => 'application/json'])
            ->assertSuccessful()->json('borrador.id');
    }

    private function corregir(int $id, array $cuerpo): array
    {
        return $this->actingAs($this->admin())->putJson(route('credential-flow.importaciones.correcciones', $id), ['version' => Importacion::findOrFail($id)->version] + $cuerpo)->assertOk()->json('borrador');
    }

    /** @return list<string> códigos de lo que impide crear la base, tal como los ve la pantalla */
    private function bloqueos(int $id): array
    {
        return array_column($this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.validar', $id))->assertOk()->json('bloqueos'), 'codigo');
    }

    // ── Etapas y navegación ───────────────────────────────────────────────────

    public function test_el_recorrido_tiene_las_seis_etapas_en_orden_y_la_pagina_las_muestra(): void
    {
        $store = $this->js('Composables/CredentialFlow/importacionInteligente.js');
        preg_match('/export const PASOS = \[(.*?)\];/s', $store, $m);
        preg_match_all('/id: "([a-z]+)"/', $m[1], $ids);
        $this->assertSame(['subir', 'evento', 'datos', 'personas', 'vista', 'confirmar'], $ids[1]);

        $pagina = $this->js('Pages/CredentialFlow/Importacion/Asistente.vue');
        foreach (['PasoSubir', 'EtapaEvento', 'EtapaDatos', 'EtapaPersonas', 'PasoVistaPrevia', 'PasoConfirmar', 'Guia', 'Pasos'] as $componente) {
            $this->assertStringContainsString("<$componente", $pagina, "la página no muestra $componente");
            $this->assertFileExists(resource_path("js/Components/CredentialFlow/Importacion/$componente.vue"));
        }
        $this->assertFileDoesNotExist(resource_path('js/Components/CredentialFlow/Importacion/PasoRevisar.vue'), 'la pantalla única «Revisar datos» fue reemplazada por etapas');
    }

    public function test_cada_pendiente_del_servidor_lleva_a_una_etapa_que_existe(): void
    {
        $servicio = file_get_contents(app_path('Support/CredentialFlow/Importacion/ServicioImportaciones.php'));
        preg_match_all("/\\\$bloquear\\('([a-z_]+)'/", $servicio, $codigos);
        $this->assertGreaterThanOrEqual(15, count(array_unique($codigos[1])), 'el servicio debe tener su lista de pendientes');

        $store = $this->js('Composables/CredentialFlow/importacionInteligente.js');
        preg_match('/export const PASOS = \[(.*?)\];/s', $store, $m);
        preg_match_all('/id: "([a-z]+)"/', $m[1], $etapas);
        preg_match('/export const BLOQUEOS = \{(.*?)\n\};/s', $store, $b);
        preg_match_all('/^\s{2}([a-z_]+): \{ titulo: "[^"]+", paso: "([a-z]+)"/m', $b[1], $mapa, PREG_SET_ORDER);
        $porCodigo = array_column($mapa, 2, 1);

        foreach (array_unique($codigos[1]) as $codigo) {
            $this->assertArrayHasKey($codigo, $porCodigo, "«{$codigo}» no sabe a qué etapa llevar al administrador");
            $this->assertContains($porCodigo[$codigo], $etapas[1], "«{$codigo}» apunta a una etapa que no existe");
        }
        // Lo que se resuelve en cada etapa coincide con lo que esa etapa muestra.
        $this->assertSame('evento', $porCodigo['evento_sin_decision']);
        $this->assertSame('datos', $porCodigo['dato_comun_sin_confirmar']);
        $this->assertSame('datos', $porCodigo['valores_desactualizados_sin_revisar']);
        $this->assertSame('personas', $porCodigo['fila_invalida']);
        $this->assertSame('personas', $porCodigo['valores_ambiguos_sin_revisar']);
        $this->assertSame('vista', $porCodigo['plantilla_faltante']);
    }

    // ── La prueba de aceptación con el Excel sintético de 17 personas ─────────

    public function test_con_el_excel_de_17_personas_los_pendientes_aparecen_en_el_orden_de_la_guia_y_solo_los_necesarios_bloquean(): void
    {
        $id = $this->analizar();

        // Qué falta al empezar: evento → 3 datos del certificado → los datos de las personas que vienen de otra hoja → lo que impide certificar a las personas
        // (el tipo de documento) → plantilla. Las 17 (16 «Completo» y 1 «Cupos») ya nacen elegibles: no se aprueban una por una.
        $inicio = $this->bloqueos($id);
        $esperados = ['evento_sin_decision', 'dato_comun_sin_confirmar', 'dato_comun_sin_confirmar', 'dato_comun_sin_confirmar', 'valores_desactualizados_sin_revisar', 'tipo_documento_sin_confirmar', 'plantilla_faltante'];
        sort($inicio);
        sort($esperados);
        $this->assertSame($esperados, $inicio, 'el orden lo pone la guía; el servidor solo informa qué falta');

        // Etapa 2 · evento.
        $this->corregir($id, ['evento' => ['decision' => 'asociar', 'evento_id' => 631]]);
        $this->assertNotContains('evento_sin_decision', $this->bloqueos($id));
        $this->assertCount(6, $this->bloqueos($id));

        // Etapa 3 · una tarea por vez: fecha, intensidad, modalidad, datos de otra hoja.
        $this->corregir($id, ['confirmados' => ['datos_comunes' => ['fecha']]]);
        $this->assertCount(2, array_keys($this->bloqueos($id), 'dato_comun_sin_confirmar', true));
        $this->corregir($id, ['confirmados' => ['datos_comunes' => ['fecha', 'intensidad_horaria']]]);
        $this->corregir($id, ['confirmados' => ['datos_comunes' => ['fecha', 'intensidad_horaria', 'modalidad']]]);
        $this->assertNotContains('dato_comun_sin_confirmar', $this->bloqueos($id));
        $this->assertContains('valores_desactualizados_sin_revisar', $this->bloqueos($id));
        $this->corregir($id, ['confirmados' => ['valores_desactualizados' => true]]);

        // Etapa 4 · las personas: solo el tipo de documento de las 17.
        $this->assertEqualsCanonicalizing(['tipo_documento_sin_confirmar', 'plantilla_faltante'], $this->bloqueos($id));
        $personas = [];
        foreach ($this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.show', $id))->json('borrador.participantes') as $p) {
            $personas[(string) $p['fila']] = ['tipo_documento' => 'C.C.'];
        }
        $this->corregir($id, ['participantes' => $personas]);
        $this->assertSame(['plantilla_faltante'], $this->bloqueos($id));

        // Etapa 5 · la plantilla.
        $this->corregir($id, ['plantilla_id' => $this->plantilla->id]);
        $e = $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.validar', $id))->assertOk()->json();
        $this->assertTrue($e['listo']);
        $this->assertSame([], $e['bloqueos']);
        $this->assertSame(['tipo_documento' => 0, 'elegibilidad' => 0, 'no_elegibles' => 0, 'asistencia_cupo' => 1], $e['pendientes_para_emitir'], 'nadie queda sin poder certificarse');

        // Etapa 6 · crear la base: no emite nada.
        $lote = Lote::findOrFail($this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.confirmar', $id), ['version' => Importacion::findOrFail($id)->version, 'confirmar' => true])->assertCreated()->json('lote_id'));
        $this->assertSame(17, $lote->participantes()->where('es_control_qa', false)->where('elegibilidad', 'elegible')->where('tipo_documento_confirmado', true)->count());
        $this->assertSame(0, Emision::count());
    }

    public function test_un_borrador_a_medias_se_retoma_sin_perder_lo_guardado(): void
    {
        $id = $this->analizar();
        $this->corregir($id, ['evento' => ['decision' => 'asociar', 'evento_id' => 631], 'confirmados' => ['datos_comunes' => ['fecha']]]);

        // Volver a abrir (o retroceder y avanzar) devuelve exactamente lo confirmado hasta ahí.
        $b = $this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.show', $id))->assertOk()->json('borrador');
        $this->assertSame(['decision' => 'asociar', 'evento_id' => 631, 'nombre' => null, 'confirmar_distinto' => false], $b['evento']['decision']);
        $this->assertTrue($b['datos_comunes']['fecha']['confirmado']);
        $this->assertFalse($b['datos_comunes']['intensidad_horaria']['confirmado']);
    }

    // ── Reglas de la interfaz ─────────────────────────────────────────────────

    public function test_nada_se_confirma_solo_las_confirmaciones_viven_en_manejadores_de_clic(): void
    {
        $datos = $this->js('Components/CredentialFlow/Importacion/EtapaDatos.vue');
        // Las confirmaciones de datos solo existen dentro de las funciones que dispara un botón.
        foreach (['confirmarDato(', 'marcarConfirmacion('] as $llamada) {
            $this->assertSame(1, substr_count($datos, $llamada), "$llamada debe aparecer una sola vez");
        }
        $this->assertMatchesRegularExpression('/async function confirmar\(\) \{[^}]*confirmarDato\(/s', $datos);
        $this->assertMatchesRegularExpression('/async function confirmarOtraHoja\(\) \{[^}]*marcarConfirmacion\(/s', $datos);
        foreach (['onMounted', 'watch'] as $automatico) {
            preg_match_all('/'.$automatico.'\((.*?)\n\}?\);/s', $datos, $bloques);
            foreach ($bloques[1] as $bloque) {
                $this->assertStringNotContainsString('confirmarDato(', $bloque, "no se confirma dentro de $automatico");
                $this->assertStringNotContainsString('marcarConfirmacion(', $bloque, "no se confirma dentro de $automatico");
            }
        }

        $evento = $this->js('Components/CredentialFlow/Importacion/EtapaEvento.vue');
        $this->assertStringNotContainsString('decidirEvento(', $evento, 'el evento lo elige quien revisa, no la guía');
        $this->assertStringContainsString('async function confirmar()', $evento);
        $this->assertStringContainsString('Guardar y volver a', $evento);

        $personas = $this->js('Components/CredentialFlow/Importacion/EtapaPersonas.vue');
        foreach (['marcarConfirmacion(\'valores_ambiguos\', true', 'editarFila('] as $prohibida) {
            $this->assertStringNotContainsString($prohibida, $personas, "la etapa de personas no confirma sola: $prohibida");
        }
        // Aplicar cambios masivos solo ocurre como acción del botón principal (un clic), nunca al abrir la etapa.
        $this->assertSame(1, substr_count($personas, 'aplicarMasiva('));
        $this->assertStringContainsString('accion: () => a.aplicarMasiva()', $personas);
    }

    public function test_la_revision_guiada_solo_prepara_la_seleccion_y_no_decide_ni_aprueba_nada(): void
    {
        $store = $this->js('Composables/CredentialFlow/importacionInteligente.js');
        preg_match('/function iniciarRevisionMasiva\(tipo\) \{(.*?)\n  \}/s', $store, $m);
        $cuerpo = $m[1];

        $this->assertStringContainsString('p.senal === "completo"', $cuerpo, '«Completo» solo se PROPONE como grupo a revisar');
        $this->assertStringNotContainsString('cupo', $cuerpo, '«Cupos» no entra en la revisión guiada de elegibilidad');
        $this->assertStringContainsString('abrirMasiva(tipo)', $cuerpo);
        foreach (['aplicarMasiva', 'elegirValorMasivo', 'verificado', 'valor ='] as $decide) {
            $this->assertStringNotContainsString($decide, $cuerpo, "preparar la selección no puede decidir ($decide)");
        }
        // El panel arranca sin valor elegido: lo elige la persona.
        $this->assertStringContainsString('masiva = reactive({ abierta: false, campo: null, valor: ""', $store);
    }

    public function test_ningun_boton_principal_queda_apagado_sin_decir_que_falta(): void
    {
        $razones = [
            'EtapaDatos.vue' => ['data-razon-dato'],
            'AccionesMasivas.vue' => ['data-razon-revisar', 'data-razon-aplicar'],
            'EtapaPersonas.vue' => ['data-razon-personas'],
            'EtapaEvento.vue' => ['data-falta-evento'],
            'PasoConfirmar.vue' => ['data-razones'],
            'PasoVistaPrevia.vue' => ['data-sin-plantilla-elegida'],
        ];
        foreach ($razones as $archivo => $marcas) {
            $codigo = $this->js("Components/CredentialFlow/Importacion/$archivo");
            foreach ($marcas as $marca) {
                $this->assertStringContainsString($marca, $codigo, "$archivo debe explicar por qué un botón no está disponible ($marca)");
            }
        }
        // La barra inferior muestra la ÚNICA acción principal de la etapa y, si está apagada, dice por qué (`razon`).
        $pagina = $this->js('Pages/CredentialFlow/Importacion/Asistente.vue');
        $this->assertStringContainsString('data-accion="principal"', $pagina);
        $this->assertStringContainsString('data-razon-principal', $pagina);
        $this->assertStringContainsString('principal.deshabilitado', $pagina);
        $this->assertStringContainsString('export function registrarPrincipal', $this->js('Composables/CredentialFlow/importacionInteligente.js'));
    }

    public function test_los_datos_tecnicos_quedan_plegados_hasta_que_se_piden(): void
    {
        $this->assertStringContainsString('data-detalles-dato', $this->js('Components/CredentialFlow/Importacion/EtapaDatos.vue'));
        $this->assertStringContainsString('Ver detalles', $this->js('Components/CredentialFlow/Importacion/EtapaDatos.vue'));
        $this->assertStringContainsString('data-detalles-vista', $this->js('Components/CredentialFlow/Importacion/PasoVistaPrevia.vue'));
        $this->assertStringContainsString('<details', $this->js('Components/CredentialFlow/Importacion/PasoConfirmar.vue'));
        // La lista completa de personas empieza cerrada y se muestran pocas fichas a la vez.
        $this->assertStringContainsString('const listaAbierta = ref(false)', $this->js('Composables/CredentialFlow/importacionInteligente.js'));
        $this->assertStringContainsString('const LOTE = 10;', $this->js('Components/CredentialFlow/Importacion/TablaParticipantes.vue'));
    }

    public function test_cada_etapa_resalta_el_siguiente_paso_recomendado_y_la_guia_lo_cuenta_con_tres_frases(): void
    {
        foreach (['TarjetaEvento', 'EtapaDatos', 'EtapaPersonas', 'PasoVistaPrevia'] as $etapa) {
            $this->assertStringContainsString('data-recomendado', $this->js("Components/CredentialFlow/Importacion/$etapa.vue"), "$etapa debe marcar su siguiente paso recomendado");
        }
        $guia = $this->js('Components/CredentialFlow/Importacion/Guia.vue');
        foreach (['Acabas de:', 'Ahora:', 'Sigue:'] as $frase) {
            $this->assertStringContainsString($frase, $guia);
        }
        // La guía recomienda con palabras; no confirma.
        foreach (['confirmarDato', 'marcarConfirmacion', 'decidirEvento', 'aplicarMasiva', 'guardar('] as $accion) {
            $this->assertStringNotContainsString($accion, $guia, "la guía no ejecuta $accion");
        }
        $this->assertStringContainsString('data-progreso-texto', $this->js('Components/CredentialFlow/Importacion/Pasos.vue'));
        $this->assertStringContainsString('role="progressbar"', $this->js('Components/CredentialFlow/Importacion/Pasos.vue'));
    }

    // ── Ajustes finales de experiencia ────────────────────────────────────────

    public function test_la_confirmacion_del_evento_habla_en_lenguaje_natural_sin_preseleccionar_nada(): void
    {
        $tarjeta = $this->js('Components/CredentialFlow/Importacion/TarjetaEvento.vue');

        foreach (['¡Encontramos esta capacitación!', '¿Quieres agregar estas personas a la capacitación que ya existe?', 'Sí, es la misma capacitación', 'No, es otra capacitación', 'Ver detalles'] as $frase) {
            $this->assertStringContainsString($frase, $tarjeta);
        }
        foreach (['Así lo escribe el Excel', 'Evento ya registrado', 'Tu decisión', '¿A qué evento pertenece'] as $jerga) {
            $this->assertStringNotContainsString($jerga, $tarjeta, "«{$jerga}» confundía al administrador");
        }
        // Los nombres originales quedan en «Ver detalles»; la diferencia relevante se dice a la vista; nada viene marcado de antemano.
        $this->assertMatchesRegularExpression('/<details[^>]*data-detalles-evento>.*data-nombre-excel.*data-nombre-registrado/s', $tarjeta);
        $this->assertStringContainsString('data-aviso-diferencia', $tarjeta);
        $this->assertStringNotContainsString(':checked="true"', $tarjeta);
        $this->assertStringContainsString('data-confirmar-distinto', $tarjeta, 'se conserva la confirmación explícita de «es otra capacitación»');
    }

    public function test_hay_una_sola_accion_principal_por_pantalla(): void
    {
        $prohibidos = ['continuar-datos', 'continuar-vista', 'continuar-personas', 'confirmar-evento', 'confirmar-importrange', 'data-aplicar-masivo', 'data-revisar-masivo'];
        foreach (['EtapaEvento', 'EtapaDatos', 'EtapaPersonas', 'PasoVistaPrevia', 'AccionesMasivas', 'TarjetaEvento'] as $etapa) {
            $codigo = $this->js("Components/CredentialFlow/Importacion/$etapa.vue");
            foreach ($prohibidos as $duplicado) {
                $this->assertStringNotContainsString($duplicado, $codigo, "$etapa tiene un botón que duplica la acción principal ($duplicado)");
            }
            foreach (['Continuar a las personas', 'Se ve bien, continuar', 'Guardar cambios'] as $texto) {
                $this->assertStringNotContainsString(">$texto<", $codigo);
            }
        }
        $pagina = $this->js('Pages/CredentialFlow/Importacion/Asistente.vue');
        $this->assertStringNotContainsString('Guardar cambios', $pagina, 'guardar es parte de la acción principal, no un botón aparte');
        $this->assertSame(1, substr_count($pagina, 'data-accion="principal"'));
        // Cada etapa con decisiones registra su acción principal.
        foreach (['EtapaEvento', 'EtapaDatos', 'EtapaPersonas', 'PasoVistaPrevia'] as $etapa) {
            $this->assertStringContainsString('registrarPrincipal(a', $this->js("Components/CredentialFlow/Importacion/$etapa.vue"), "$etapa debe registrar su acción principal");
        }
    }

    public function test_la_lista_de_personas_es_compacta_y_separa_revisar_de_incluir(): void
    {
        $tabla = $this->js('Components/CredentialFlow/Importacion/TablaParticipantes.vue');
        foreach (['data-nombre-fila', 'data-asistencia', 'data-estado-elegibilidad', 'Documento {{ p.documento', 'Ver o corregir', 'Incluir en la base', ':data-seleccionar="p.fila"'] as $campo) {
            $this->assertStringContainsString($campo, $tabla, "la fila compacta debe mostrar $campo");
        }
        // La ficha completa existe solo cuando se pide y no desaparece la edición individual.
        $this->assertStringContainsString('v-if="abiertas.has(p.fila)"', $tabla);
        $this->assertStringContainsString('data-campo-fila="documento"', $tabla);
        $this->assertStringContainsString(':data-incluir="p.fila"', $tabla);
        // «Seleccionar para revisar» (agrupar) no es lo mismo que «Incluir en la base» (qué personas entran).
        $masivas = $this->js('Components/CredentialFlow/Importacion/AccionesMasivas.vue');
        $this->assertStringContainsString('Seleccionar para revisar', $masivas);
        $this->assertStringContainsString('no cambia quién entra a la base', $masivas);
        // El panel de la acción masiva resume a quiénes afecta ANTES de proponer cambios.
        $this->assertStringContainsString('data-resumen-seleccion', $masivas);
        $this->assertStringContainsString('const resumenSeleccion = computed', $this->js('Composables/CredentialFlow/importacionInteligente.js'));
    }

    public function test_al_corregir_un_dato_se_regresa_directo_al_lugar_de_origen(): void
    {
        $store = $this->js('Composables/CredentialFlow/importacionInteligente.js');
        $this->assertStringContainsString('const origen = ref(null)', $store);
        $this->assertStringContainsString('async function volverAlOrigen()', $store);
        // «Revisar este dato» recuerda de dónde venías solo si el destino queda ANTES en el recorrido.
        $this->assertStringContainsString('const retrocede = PASOS.findIndex', $store);
        $this->assertStringContainsString('if (retrocede) origen.value = {', $store);
        // Lo corregido se guarda con el flujo versionado y luego se vuelve (sin pasar de nuevo por las etapas intermedias).
        $datos = $this->js('Components/CredentialFlow/Importacion/EtapaDatos.vue');
        $this->assertMatchesRegularExpression('/async function despuesDeGuardar\(\) \{\s*if \(a\.origen\.value\) return a\.volverAlOrigen\(\);/s', $datos);
        $this->assertMatchesRegularExpression('/if \(!\(await a\.guardar\(\)\)\) return;\s*a\.hecho\.value = texto;\s*await despuesDeGuardar\(\);/s', $datos);
        foreach (['EtapaPersonas', 'EtapaEvento', 'PasoVistaPrevia'] as $etapa) {
            $this->assertStringContainsString('volverAlOrigen', $this->js("Components/CredentialFlow/Importacion/$etapa.vue"), "$etapa también regresa al origen");
        }
    }

    public function test_las_advertencias_de_google_sheets_son_claras_y_las_confirmaciones_siguen_siendo_explicitas(): void
    {
        $datos = $this->js('Components/CredentialFlow/Importacion/EtapaDatos.vue');
        $tarjeta = $this->js('Components/CredentialFlow/Importacion/TarjetaDatos.vue');
        foreach ([$datos, $tarjeta] as $codigo) {
            $this->assertStringContainsString('Parte de la información se obtuvo de otra hoja', $codigo);
            $this->assertStringContainsString('¿Cómo lo compruebo?', $codigo);
            $this->assertStringContainsString('Comprueba que', $codigo);
        }
        foreach (glob(resource_path('js/Components/CredentialFlow/Importacion/*.vue')) as $f) {
            $this->assertStringNotContainsString('no están escritos en el Excel', file_get_contents($f), basename($f).': el XLSX sí puede traer valores calculados guardados');
            $this->assertStringNotContainsString('no está escrito en el Excel', file_get_contents($f), basename($f));
        }
        // El conteo sale del análisis de cada archivo (no es un número fijo) y la confirmación sigue siendo un clic de la persona.
        $this->assertStringContainsString('a.personasDesactualizadas.value', $datos);
        $this->assertStringContainsString('async function confirmarOtraHoja()', $datos);
        $this->assertStringContainsString('{{ totalDesactualizables }} datos vienen de otra hoja', $tarjeta);
    }

    public function test_la_plantilla_sugerida_solo_se_sugiere_si_es_valida_y_nunca_se_elige_sola(): void
    {
        $url = route('credential-flow.importaciones.asistente');
        $this->actingAs($this->admin())->get($url)->assertInertia(fn ($pagina) => $pagina->where('plantilla_sugerida', null)->has('plantillas', 1));

        config(['credential_flow.importacion_inteligente.plantilla_sugerida_id' => $this->plantilla->id]);
        $this->actingAs($this->admin())->get($url)->assertInertia(fn ($pagina) => $pagina->where('plantilla_sugerida', $this->plantilla->id));

        // Un número que no corresponde a una plantilla válida no sugiere nada (no se inventa una plantilla inexistente).
        config(['credential_flow.importacion_inteligente.plantilla_sugerida_id' => 987654]);
        $this->actingAs($this->admin())->get($url)->assertInertia(fn ($pagina) => $pagina->where('plantilla_sugerida', null));

        // Una plantilla de pruebas internas (QA) tampoco se sugiere.
        $qa = $this->plantillaLista([$this->elemento(['field' => 'nombre_completo', 'y' => 20, 'height' => 30, 'fontSize' => 12])]);
        $qa->update(['es_plantilla_qa' => true]);
        config(['credential_flow.importacion_inteligente.plantilla_sugerida_id' => $qa->id]);
        $this->actingAs($this->admin())->get($url)->assertInertia(fn ($pagina) => $pagina->where('plantilla_sugerida', null));

        // En el borrador la plantilla sigue sin elegir: sugerir no es elegir.
        config(['credential_flow.importacion_inteligente.plantilla_sugerida_id' => $this->plantilla->id]);
        $id = $this->analizar();
        $this->assertNull($this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.show', $id))->json('borrador.plantilla_id'));
        $vista = $this->js('Components/CredentialFlow/Importacion/PasoVistaPrevia.vue');
        $this->assertStringContainsString('No se elige sola', $vista);
        $this->assertStringContainsString('cambiarPlantilla(sugerida.value.id)', $vista, 'la sugerencia solo se aplica con un clic de la persona');
    }
}
