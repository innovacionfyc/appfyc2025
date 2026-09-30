<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\CamposDinamicos;
use App\Support\CredentialFlow\DisenoSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

/** F&C Credential Flow · Fase 3: campos dinámicos (catálogo, validación, normalización y persistencia). */
class CamposDinamicosTest extends CredentialFlowTestCase
{
    private const CLAVES_V1 = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'];

    /** Claves exactas de un elemento del diseño: esta fase no agrega ninguna. */
    private const CLAVES_ELEMENTO = [
        'align', 'color', 'field', 'fontFamily', 'fontSize', 'fontWeight',
        'height', 'id', 'text', 'type', 'width', 'x', 'y',
    ];

    // ── Utilidades ────────────────────────────────────────────────────────────

    private function elemento(array $cambios = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'type' => 'text',
            'field' => null,
            'text' => 'Texto fijo',
            'x' => 96,
            'y' => 223.38,
            'width' => 600,
            'height' => 40,
            'fontFamily' => 'Figtree',
            'fontSize' => 22,
            'fontWeight' => 700,
            'color' => '#000000',
            'align' => 'center',
        ], $cambios);
    }

    private function diseno(array $elementos): array
    {
        return ['page' => ['width' => 792, 'height' => 612], 'elements' => $elementos];
    }

    private function guardar(Plantilla $p, array $diseno)
    {
        return $this->actingAs($this->admin())->putJson(route('credential-flow.plantillas.diseno.update', $p), ['diseno' => $diseno]);
    }

    private function assertGuardado($respuesta, Plantilla $p): void
    {
        $respuesta->assertRedirect(route('credential-flow.plantillas.editor', $p));
    }

    private function guardado(Plantilla $p): array
    {
        return Plantilla::findOrFail($p->id)->diseno['elements'];
    }

    // ── Catálogo ──────────────────────────────────────────────────────────────

    public function test_el_catalogo_contiene_exactamente_los_cinco_campos_v1(): void
    {
        $this->assertSame(self::CLAVES_V1, CamposDinamicos::claves());
        $this->assertSame(self::CLAVES_V1, array_keys(CamposDinamicos::todos()));
    }

    public function test_las_claves_son_unicas_y_estables_en_snake_case(): void
    {
        $claves = CamposDinamicos::claves();

        $this->assertSame($claves, array_values(array_unique($claves)));
        foreach ($claves as $clave) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $clave);
        }
    }

    public function test_cada_campo_trae_la_metadata_necesaria(): void
    {
        foreach (CamposDinamicos::todos() as $key => $campo) {
            $this->assertSame($key, $campo['key']);
            $this->assertNotSame('', trim($campo['etiqueta']), "$key sin etiqueta");
            $this->assertNotSame('', trim($campo['preview']), "$key sin preview");
            $this->assertIsInt($campo['maxLongitud']);
            $this->assertGreaterThan(0, $campo['maxLongitud']);
            $this->assertSame('string', $campo['tipo']);
            $this->assertFalse($campo['multilinea'], "$key no debe admitir varias líneas en V1");
            $this->assertContains($campo['formato'], [CamposDinamicos::FORMATO_MAYUSCULAS, CamposDinamicos::FORMATO_LITERAL]);
            $this->assertLessThanOrEqual($campo['maxLongitud'], mb_strlen($campo['preview']), "preview de $key excede su longitud máxima");
        }
    }

    public function test_los_previews_y_etiquetas_son_los_aprobados(): void
    {
        $todos = CamposDinamicos::todos();

        $this->assertSame('JUAN CARLOS PÉREZ GÓMEZ', $todos['nombre_completo']['preview']);
        $this->assertSame('C.C. 1.023.456.789', $todos['documento']['preview']);
        $this->assertSame('GESTIÓN INTEGRAL DE PROPIEDAD HORIZONTAL', $todos['evento']['preview']);
        $this->assertSame('29 DE SEPTIEMBRE DE 2026', $todos['fecha']['preview']);
        $this->assertSame('16 HORAS', $todos['intensidad_horaria']['preview']);

        $this->assertSame(
            ['Nombre completo', 'Documento', 'Evento', 'Fecha', 'Intensidad horaria'],
            array_column($todos, 'etiqueta')
        );
    }

    public function test_existe_solo_acepta_claves_exactas(): void
    {
        foreach (self::CLAVES_V1 as $clave) {
            $this->assertTrue(CamposDinamicos::existe($clave));
        }
        foreach (['NOMBRE_COMPLETO', 'Nombre_Completo', 'nombre completo', '', 'x', 'nombre', null, 42, true, ['nombre_completo']] as $invalida) {
            $this->assertFalse(CamposDinamicos::existe($invalida), 'no debería existir: '.json_encode($invalida));
        }
    }

    public function test_para_editor_expone_los_campos_sin_metadatos_internos(): void
    {
        $campos = CamposDinamicos::paraEditor();

        $this->assertSame(self::CLAVES_V1, array_column($campos, 'key'));
        foreach ($campos as $campo) {
            $this->assertEqualsCanonicalizing(['key', 'etiqueta', 'preview', 'maxLongitud', 'tipo'], array_keys($campo));
        }
    }

    public function test_el_esquema_del_editor_incluye_campos_y_constantes_de_autoajuste(): void
    {
        $schema = DisenoSchema::paraEditor();

        $this->assertSame(CamposDinamicos::paraEditor(), $schema['campos']);
        $this->assertSame(0.70, $schema['escalaMinima']);
        $this->assertSame(0.25, $schema['pasoAjuste']);
        $this->assertSame(0.01, $schema['toleranciaAjuste']);
        $this->assertSame(1, $schema['version']);
    }

    public function test_las_fuentes_permitidas_no_cambian_en_esta_fase(): void
    {
        $this->assertSame(['Figtree', 'Arial', 'sans-serif'], DisenoSchema::FUENTES);
    }

    // ── Guardar campos dinámicos ──────────────────────────────────────────────

    public static function camposValidos(): array
    {
        return array_map(fn (string $c) => [$c], self::CLAVES_V1);
    }

    #[DataProvider('camposValidos')]
    public function test_cada_campo_valido_se_guarda(string $campo): void
    {
        $p = $this->crearPlantilla();
        $e = $this->elemento(['field' => $campo, 'text' => '']);

        $this->assertGuardado($this->guardar($p, $this->diseno([$e])), $p);

        $this->assertSame($campo, $this->guardado($p)[0]['field']);
    }

    public function test_el_mismo_campo_puede_repetirse_en_varios_elementos(): void
    {
        $p = $this->crearPlantilla();

        $this->assertGuardado($this->guardar($p, $this->diseno([
            $this->elemento(['field' => 'nombre_completo', 'text' => '']),
            $this->elemento(['field' => 'nombre_completo', 'text' => '', 'y' => 500]),
        ])), $p);

        $guardado = $this->guardado($p);
        $this->assertCount(2, $guardado);
        $this->assertSame(['nombre_completo', 'nombre_completo'], array_column($guardado, 'field'));
    }

    public function test_mezcla_de_texto_fijo_y_dinamico(): void
    {
        $p = $this->crearPlantilla();

        $this->assertGuardado($this->guardar($p, $this->diseno([
            $this->elemento(['field' => null, 'text' => 'Concede este certificado a:']),
            $this->elemento(['field' => 'nombre_completo', 'text' => '', 'y' => 260]),
            $this->elemento(['field' => 'documento', 'text' => '', 'y' => 300]),
            $this->elemento(['field' => null, 'text' => 'por su asistencia al evento', 'y' => 340]),
        ])), $p);

        $guardado = $this->guardado($p);
        $this->assertSame([null, 'nombre_completo', 'documento', null], array_column($guardado, 'field'));
        $this->assertSame(['Concede este certificado a:', '', '', 'por su asistencia al evento'], array_column($guardado, 'text'));
    }

    public function test_field_null_es_texto_fijo_y_conserva_su_texto(): void
    {
        $p = $this->crearPlantilla();

        $this->assertGuardado($this->guardar($p, $this->diseno([$this->elemento(['field' => null, 'text' => 'Se conserva'])])), $p);

        $e = $this->guardado($p)[0];
        $this->assertNull($e['field']);
        $this->assertSame('Se conserva', $e['text']);
    }

    public function test_field_como_cadena_vacia_se_trata_como_texto_fijo(): void
    {
        $p = $this->crearPlantilla();

        // ConvertEmptyStringsToNull (middleware) convierte '' en null antes de validar.
        $this->assertGuardado($this->guardar($p, $this->diseno([$this->elemento(['field' => '', 'text' => 'Fijo'])])), $p);

        $e = $this->guardado($p)[0];
        $this->assertNull($e['field']);
        $this->assertSame('Fijo', $e['text']);
    }

    public function test_un_campo_dinamico_persiste_con_text_vacio_aunque_llegue_texto(): void
    {
        $p = $this->crearPlantilla();

        $this->assertGuardado($this->guardar($p, $this->diseno([
            $this->elemento(['field' => 'evento', 'text' => 'Esto no debe guardarse']),
        ])), $p);

        $e = $this->guardado($p)[0];
        $this->assertSame('evento', $e['field']);
        $this->assertSame('', $e['text']);
    }

    public function test_cambiar_entre_fijo_y_dinamico_en_guardados_sucesivos(): void
    {
        $p = $this->crearPlantilla();
        $id = (string) Str::uuid();

        $this->assertGuardado($this->guardar($p, $this->diseno([$this->elemento(['id' => $id, 'field' => 'nombre_completo', 'text' => ''])])), $p);
        $this->assertSame(['nombre_completo', ''], [$this->guardado($p)[0]['field'], $this->guardado($p)[0]['text']]);

        $this->assertGuardado($this->guardar($p, $this->diseno([$this->elemento(['id' => $id, 'field' => null, 'text' => 'Ahora fijo'])])), $p);
        $this->assertSame([null, 'Ahora fijo'], [$this->guardado($p)[0]['field'], $this->guardado($p)[0]['text']]);

        $this->assertGuardado($this->guardar($p, $this->diseno([$this->elemento(['id' => $id, 'field' => 'documento', 'text' => 'Ignorado'])])), $p);
        $this->assertSame(['documento', ''], [$this->guardado($p)[0]['field'], $this->guardado($p)[0]['text']]);
    }

    public function test_pasar_de_un_campo_dinamico_a_otro_conserva_la_posicion_y_los_estilos(): void
    {
        $p = $this->crearPlantilla();
        $base = ['id' => (string) Str::uuid(), 'x' => 123.45, 'y' => 67.89, 'width' => 321, 'height' => 44, 'fontSize' => 30, 'fontWeight' => 500, 'color' => '#932833', 'align' => 'right'];

        $this->assertGuardado($this->guardar($p, $this->diseno([$this->elemento($base + ['field' => 'nombre_completo', 'text' => ''])])), $p);
        $this->assertGuardado($this->guardar($p, $this->diseno([$this->elemento($base + ['field' => 'evento', 'text' => ''])])), $p);

        $e = $this->guardado($p)[0];
        $this->assertSame('evento', $e['field']);
        foreach (['x' => 123.45, 'y' => 67.89, 'width' => 321, 'height' => 44, 'fontSize' => 30, 'fontWeight' => 500, 'color' => '#932833', 'align' => 'right'] as $clave => $valor) {
            $this->assertEquals($valor, $e[$clave], "cambió $clave");
        }
    }

    // ── Rechazos ──────────────────────────────────────────────────────────────

    public static function camposInvalidos(): array
    {
        return [
            'mayúsculas' => ['NOMBRE_COMPLETO'],
            'variante de mayúsculas' => ['Nombre_Completo'],
            'con espacio' => ['nombre completo'],
            'clave desconocida' => ['participante'],
            'prefijo de una clave válida' => ['nombre'],
            'clave válida con sufijo' => ['nombre_completo_2'],
            'array' => [['nombre_completo']],
            'objeto' => [['key' => 'nombre_completo']],
            'número' => [42],
            'número cero' => [0],
            'booleano' => [true],
            'cadena maliciosa (SQL)' => ["nombre_completo'; DROP TABLE cf_plantillas; --"],
            'cadena maliciosa (script)' => ['<script>alert(1)</script>'],
            'ruta' => ['../../etc/passwd'],
            'cadena larguísima' => [str_repeat('a', 5000)],
        ];
    }

    #[DataProvider('camposInvalidos')]
    public function test_rechaza_campos_invalidos_sin_tocar_lo_guardado(mixed $invalido): void
    {
        $p = $this->crearPlantilla();
        $this->assertGuardado($this->guardar($p, $this->diseno([$this->elemento(['text' => 'BUENO'])])), $p);

        $this->guardar($p, $this->diseno([$this->elemento(['field' => $invalido, 'text' => 'MALO'])]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('diseno.elements.0.field');

        $this->assertSame('BUENO', $this->guardado($p)[0]['text']);
    }

    public function test_un_campo_desconocido_ya_guardado_no_se_puede_reguardar_sin_corregirlo(): void
    {
        $p = $this->crearPlantilla();
        $obsoleto = $this->elemento(['field' => 'campo_que_ya_no_existe', 'text' => '']);

        // Simula un catálogo que cambió después de guardar el diseño.
        DB::table('cf_plantillas')->where('id', $p->id)->update(['diseno' => json_encode($this->diseno([$obsoleto]))]);

        $this->actingAs($this->admin())
            ->get(route('credential-flow.plantillas.editor', $p))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('diseno.elements.0.field', 'campo_que_ya_no_existe'));

        $this->guardar($p, $this->diseno([$obsoleto]))->assertStatus(422)->assertJsonValidationErrors('diseno.elements.0.field');

        // Al elegir una opción válida se puede guardar; nada se sustituyó por su cuenta.
        $this->assertSame('campo_que_ya_no_existe', $this->guardado($p)[0]['field']);
        $this->assertGuardado($this->guardar($p, $this->diseno([array_merge($obsoleto, ['field' => 'fecha'])])), $p);
        $this->assertSame('fecha', $this->guardado($p)[0]['field']);
    }

    // ── Persistencia, versión y compatibilidad ────────────────────────────────

    public function test_el_campo_persiste_tras_recargar_el_editor(): void
    {
        $p = $this->crearPlantilla();
        $e = $this->elemento(['field' => 'intensidad_horaria', 'text' => 'x']);
        $this->assertGuardado($this->guardar($p, $this->diseno([$e])), $p);

        $this->actingAs($this->admin())
            ->get(route('credential-flow.plantillas.editor', $p))
            ->assertInertia(fn (Assert $page) => $page
                ->has('diseno.elements', 1)
                ->where('diseno.elements.0.id', $e['id'])
                ->where('diseno.elements.0.field', 'intensidad_horaria')
                ->where('diseno.elements.0.text', ''));
    }

    public function test_schema_version_sigue_en_1_y_el_elemento_no_gana_claves(): void
    {
        $p = $this->crearPlantilla();

        $this->assertGuardado($this->guardar($p, $this->diseno([
            $this->elemento(['field' => 'nombre_completo', 'text' => '']),
            $this->elemento(['field' => null]),
        ])), $p);

        $this->assertSame(1, Plantilla::findOrFail($p->id)->schema_version);
        foreach ($this->guardado($p) as $e) {
            $claves = array_keys($e);
            sort($claves);
            $this->assertSame(self::CLAVES_ELEMENTO, $claves);
        }
    }

    public function test_el_preview_no_se_guarda_en_el_diseno(): void
    {
        $p = $this->crearPlantilla();
        $this->assertGuardado($this->guardar($p, $this->diseno([$this->elemento(['field' => 'nombre_completo', 'text' => ''])])), $p);

        $json = json_encode(Plantilla::findOrFail($p->id)->diseno, JSON_UNESCAPED_UNICODE);
        foreach (CamposDinamicos::todos() as $campo) {
            $this->assertStringNotContainsString($campo['preview'], $json);
        }
    }

    public function test_un_diseno_v1_anterior_con_field_null_sigue_siendo_valido(): void
    {
        $p = $this->crearPlantilla();
        $anterior = $this->diseno([
            $this->elemento(['field' => null, 'text' => 'JUAN PÉREZ']),
            $this->elemento(['field' => null, 'text' => 'C.C. 1.234', 'y' => 260]),
        ]);
        DB::table('cf_plantillas')->where('id', $p->id)->update(['diseno' => json_encode($anterior), 'schema_version' => 1]);

        // Se abre y se vuelve a guardar tal cual, sin cambios.
        $this->actingAs($this->admin())
            ->get(route('credential-flow.plantillas.editor', $p))
            ->assertInertia(fn (Assert $page) => $page->where('diseno.elements.0.field', null)->where('diseno.elements.0.text', 'JUAN PÉREZ'));

        $this->assertGuardado($this->guardar($p, $anterior), $p);
        $this->assertSame(['JUAN PÉREZ', 'C.C. 1.234'], array_column($this->guardado($p), 'text'));
        $this->assertSame(1, Plantilla::findOrFail($p->id)->schema_version);
    }

    public function test_los_campos_llegan_al_editor_en_el_prop_schema(): void
    {
        $p = $this->crearPlantilla();

        $this->actingAs($this->admin())
            ->get(route('credential-flow.plantillas.editor', $p))
            ->assertInertia(fn (Assert $page) => $page
                ->component('CredentialFlow/Editor')
                ->has('schema.campos', 5)
                ->where('schema.campos.0.key', 'nombre_completo')
                ->where('schema.campos.0.etiqueta', 'Nombre completo')
                ->where('schema.campos.0.preview', 'JUAN CARLOS PÉREZ GÓMEZ')
                ->where('schema.campos.1.preview', 'C.C. 1.023.456.789')
                ->where('schema.campos.4.key', 'intensidad_horaria')
                ->where('schema.escalaMinima', 0.7)
                ->where('schema.pasoAjuste', 0.25)
                ->where('schema.version', 1)
                ->where('schema.fuentes.porDefecto', ['familia' => 'outfit', 'peso' => 700]));
    }

    // ── Aislamiento ───────────────────────────────────────────────────────────

    public function test_academia_certificados_sigue_intacto_y_no_hay_rutas_publicas_nuevas(): void
    {
        $this->assertSame('/admin/certificadosWeb', route('eventos.certificados', [], false));

        $this->actingAs($this->admin())
            ->get('/admin/certificadosWeb')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Certificados/CertificadosWeb'));

        $rutas = collect(app('router')->getRoutes()->getRoutes())->map(fn ($r) => $r->uri());
        $delModulo = $rutas->filter(fn ($u) => str_contains($u, 'credential-flow'));

        // Las 7 de las fases 1 y 2, el PDF de prueba de la fase 5 y las 12 de lotes/participantes de la fase 6 y las 8 de emisiones de la fase 7, todas administrativas.
        $this->assertCount(28, $delModulo);
        $this->assertCount(0, $delModulo->reject(fn ($u) => str_starts_with($u, 'admin/credential-flow')));
    }
}
