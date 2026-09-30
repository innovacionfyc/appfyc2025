<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader as LectorXlsx;
use Tests\Feature\CredentialFlow\Support\ArchivosParticipantes as A;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;
use Tests\Feature\CredentialFlow\Support\PdfBase;

/** F&C Credential Flow · Fase 6: lotes, importación atómica, participantes y PDF individual. */
class LotesTest extends CredentialFlowTestCase
{
    private const COMUNES = ['evento' => 'Congreso nacional de finanzas', 'fecha' => '17, 18 y 19 de septiembre de 2026', 'intensidad_horaria' => '30 horas'];

    private int $n = 0;

    // ── Utilidades ────────────────────────────────────────────────────────────

    private function el(array $c = []): array
    {
        $this->n++;

        return array_merge([
            'id' => sprintf('00000000-0000-4000-8000-%012d', $this->n), 'type' => 'text', 'field' => null, 'text' => '',
            'x' => 96, 'y' => 100, 'width' => 600, 'height' => 40, 'fontFamily' => 'outfit', 'fontSize' => 22,
            'fontWeight' => 700, 'color' => '#000000', 'align' => 'center',
        ], $c);
    }

    /** Plantilla real (PDF FPDI legible) con un diseño que usa los cinco campos dinámicos. */
    private function plantilla(?array $elementos = null): Plantilla
    {
        $p = $this->crearPlantilla();
        Storage::disk('local')->put($p->rutaPdfEsperada(), PdfBase::crear());
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'];
        $elementos ??= array_map(fn (string $c, int $i) => $this->el(['field' => $c, 'y' => 60 + $i * 60]), $campos, array_keys($campos));
        $p->update(['diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => $elementos]]);

        return $p->fresh();
    }

    private function cabecera(): array
    {
        return ['nombre_completo', 'documento'];
    }

    private function campos(Plantilla $p, ?UploadedFile $archivo, array $extra = []): array
    {
        return array_merge(['plantilla_id' => $p->id, 'nombre' => 'Lote de prueba', 'descripcion' => 'Descripción'], self::COMUNES, ['archivo' => $archivo], $extra);
    }

    private function peticion(string $metodo, string $ruta, array $datos = [], $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->admin())->json($metodo, $ruta, $datos);
    }

    private function archivoValido(int $filas = 2): UploadedFile
    {
        $f = [$this->cabecera()];
        for ($i = 1; $i <= $filas; $i++) {
            $f[] = ["Persona $i", 'C.C. '.number_format(10_000_000 + $i, 0, ',', '.')];
        }

        return A::xlsx($f);
    }

    private function importar(Plantilla $p, ?UploadedFile $archivo = null): Lote
    {
        $r = $this->post(route('credential-flow.lotes.store'), $this->campos($p, $archivo ?? $this->archivoValido()), ['Accept' => 'application/json']);
        $r->assertOk();

        return Lote::findOrFail(basename($r->json('redirect')));
    }

    private function loteConParticipantes(int $cuantos = 3, ?Plantilla $p = null): Lote
    {
        $this->actingAs($this->admin());

        return $this->importar($p ?? $this->plantilla(), $this->archivoValido($cuantos));
    }

    // ── Modelos y esquema ─────────────────────────────────────────────────────

    public function test_modelos_relaciones_y_soft_delete(): void
    {
        $p = $this->plantilla();
        $lote = Lote::create(['plantilla_id' => $p->id, 'nombre' => 'L', 'datos_comunes' => self::COMUNES]);
        $part = $lote->participantes()->create(['nombre_completo' => 'ANA RUIZ', 'documento' => 'CC 1234', 'documento_clave' => 'CC1234']);

        $this->assertTrue($lote->plantilla->is($p));
        $this->assertTrue($part->lote->is($lote));
        $this->assertSame(self::COMUNES, $lote->fresh()->datos_comunes);
        $this->assertSame([], array_diff(['id', 'plantilla_id', 'nombre', 'descripcion', 'datos_comunes', 'archivo_nombre', 'archivo_hash', 'created_by', 'update_by', 'created_at', 'updated_at', 'deleted_at'], \Schema::getColumnListing('cf_lotes')));
        $this->assertSame([], array_diff(['id', 'lote_id', 'nombre_completo', 'documento', 'documento_clave', 'fila_origen', 'created_by', 'update_by', 'created_at', 'updated_at', 'deleted_at'], \Schema::getColumnListing('cf_participantes')));

        $part->delete();
        $this->assertNull(Participante::find($part->id));
        $this->assertNotNull(Participante::withTrashed()->find($part->id));
        $lote->delete();
        $this->assertNull(Lote::find($lote->id));
        $this->assertCount(0, $p->lotes()->get());
    }

    // ── Validar (preview): no toca la base de datos ───────────────────────────

    public function test_validar_devuelve_preview_y_no_toca_la_base_de_datos(): void
    {
        $p = $this->plantilla();

        $r = $this->peticion('POST', route('credential-flow.lotes.validar'), $this->campos($p, $this->archivoValido(3)));

        $r->assertOk()->assertJsonPath('resultado.valido', true)->assertJsonPath('resultado.filas_validas', 3);
        $this->assertCount(3, $r->json('resultado.preview'));
        $this->assertSame(0, Lote::count());
        $this->assertSame(0, Participante::count());
    }

    public function test_validar_informa_todos_los_errores_sin_guardar(): void
    {
        $p = $this->plantilla();
        $archivo = A::xlsx([$this->cabecera(), [null, 'CC 1234'], ['Ana Ruiz', '=1+1'], ['Luis Soto', 'CC 55556666'], ['Eva Díaz', 'CC 55556666']]);

        $r = $this->peticion('POST', route('credential-flow.lotes.validar'), $this->campos($p, $archivo));

        $r->assertOk()->assertJsonPath('resultado.valido', false)->assertJsonPath('resultado.errores_total', 3);
        $this->assertSame(['NOMBRE_VACIO', 'FORMULA_NO_PERMITIDA', 'DOCUMENTO_DUPLICADO'], array_column($r->json('resultado.errores'), 'codigo'));
        $this->assertSame(0, Lote::count());
    }

    public function test_aviso_no_cabe_probable_no_bloquea(): void
    {
        $p = $this->plantilla([$this->el(['field' => 'nombre_completo', 'width' => 120])]);
        $archivo = A::xlsx([$this->cabecera(), ['Maria Fernanda de los Angeles Rodriguez Pena', 'CC 22223333'], ['Ana Ruiz', 'CC 44445555']]);

        $r = $this->peticion('POST', route('credential-flow.lotes.validar'), $this->campos($p, $archivo));

        $r->assertOk()->assertJsonPath('resultado.valido', true)->assertJsonPath('resultado.avisos_total', 1);
        $this->assertSame('NO_CABE_PROBABLE', $r->json('resultado.avisos.0.codigo'));
        $this->assertSame(2, $r->json('resultado.avisos.0.fila'));
    }

    public function test_csv_vacio_de_0_bytes_no_crea_lote_ni_en_validar_ni_en_confirmar(): void
    {
        $p = $this->plantilla();
        $movimientos = Movimiento::count();

        $r = $this->peticion('POST', route('credential-flow.lotes.validar'), $this->campos($p, A::subir('', 'vacio.csv')));
        $r->assertOk()->assertJsonPath('resultado.valido', false)->assertJsonPath('resultado.filas_detectadas', 0);
        $this->assertSame(['SIN_FILAS'], array_column($r->json('resultado.errores'), 'codigo'));

        $r = $this->post(route('credential-flow.lotes.store'), $this->campos($p, A::subir('', 'vacio.csv')), ['Accept' => 'application/json']);
        $r->assertStatus(422)->assertJsonPath('error.code', 'IMPORTACION_INVALIDA')->assertJsonPath('resultado.valido', false);
        $this->assertSame(['SIN_FILAS'], array_column($r->json('resultado.errores'), 'codigo'));

        $this->assertSame(0, Lote::count());
        $this->assertSame(0, Lote::withTrashed()->count());
        $this->assertSame(0, Participante::withTrashed()->count());
        $this->assertSame($movimientos, Movimiento::count(), 'Sin lote no hay Movimiento de importación');
    }

    // ── Confirmar importación ─────────────────────────────────────────────────

    public function test_confirmar_crea_lote_y_participantes_normalizados(): void
    {
        $p = $this->plantilla();
        $archivo = A::xlsx([$this->cabecera(), ['  ana  maría ruiz ', 'C.C. 1.023.456.789'], ['Luis Núñez', 'PASAPORTE AB1234567']], 'Mi lista (final).xlsx');
        $bytes = file_get_contents($archivo->getRealPath());

        $lote = $this->importar($p, $archivo);

        $this->assertSame($p->id, $lote->plantilla_id);
        $this->assertSame('Lote de prueba', $lote->nombre);
        // datos comunes: evento en MAYÚSCULAS (formato del catálogo), fecha e intensidad literales
        $this->assertSame(['evento' => 'CONGRESO NACIONAL DE FINANZAS', 'fecha' => '17, 18 y 19 de septiembre de 2026', 'intensidad_horaria' => '30 horas'], $lote->datos_comunes);
        $this->assertSame('Mi lista (final).xlsx', $lote->archivo_nombre);
        $this->assertSame(hash('sha256', $bytes), $lote->archivo_hash);
        $this->assertSame($this->admin()->id, $lote->created_by);

        $partes = $lote->participantes()->orderBy('id')->get();
        $this->assertSame(['ANA MARÍA RUIZ', 'LUIS NÚÑEZ'], $partes->pluck('nombre_completo')->all());
        $this->assertSame(['C.C. 1.023.456.789', 'PASAPORTE AB1234567'], $partes->pluck('documento')->all());
        $this->assertSame(['CC1023456789', 'PASAPORTEAB1234567'], $partes->pluck('documento_clave')->all());
        $this->assertSame([2, 3], $partes->pluck('fila_origen')->all());
        $this->assertSame([$this->admin()->id, $this->admin()->id], $partes->pluck('created_by')->all());
    }

    public function test_la_confirmacion_vuelve_a_validar_y_no_crea_nada_si_hay_errores(): void
    {
        $p = $this->plantilla();
        $archivo = A::xlsx([$this->cabecera(), ['Ana Ruiz', 'CC 1234'], [null, 'CC 5678']]);

        $r = $this->post(route('credential-flow.lotes.store'), $this->campos($p, $archivo), ['Accept' => 'application/json']);

        $r->assertStatus(422)->assertJsonPath('error.code', 'IMPORTACION_INVALIDA')->assertJsonPath('resultado.valido', false);
        $this->assertSame(0, Lote::count());
        $this->assertSame(0, Participante::count());
    }

    public function test_rollback_total_si_falla_algo_dentro_de_la_transaccion(): void
    {
        $p = $this->plantilla();
        Movimiento::creating(function () {
            throw new \RuntimeException('fallo simulado del registro de auditoría');
        });

        $r = $this->post(route('credential-flow.lotes.store'), $this->campos($p, $this->archivoValido(5)), ['Accept' => 'application/json']);

        $r->assertStatus(500)->assertJsonPath('error.code', 'ERROR_INESPERADO');
        $this->assertStringNotContainsString('simulado', $r->getContent());
        $this->assertSame(0, Lote::count());
        $this->assertSame(0, Participante::count());
    }

    public function test_los_participantes_se_insertan_en_bloques_de_200(): void
    {
        $p = $this->plantilla();
        $archivo = $this->archivoValido(450);
        $inserts = 0;
        DB::listen(function ($q) use (&$inserts) {
            if (str_starts_with($q->sql, 'insert into "cf_participantes"')) {
                $inserts++;
            }
        });

        $lote = $this->importar($p, $archivo);

        $this->assertSame(450, $lote->participantes()->count());
        $this->assertSame(3, $inserts); // 200 + 200 + 50
    }

    public function test_la_auditoria_registra_un_solo_movimiento_sin_datos_personales(): void
    {
        $p = $this->plantilla();
        $archivo = A::xlsx([$this->cabecera(), ['Ana Secreta Ruiz', 'C.C. 1.023.456.789'], ['Luis Soto', 'CC 55556666']]);

        $lote = $this->importar($p, $archivo);

        $nuevos = Movimiento::where('modulo', 'credential-flow')->where('descripcion', 'like', '%importó%')->get();
        $this->assertCount(1, $nuevos);
        $m = $nuevos->first();
        $this->assertSame('registro', $m->tipo);
        $this->assertSame($lote->id, $m->metadata['lote_id']);
        $this->assertSame($p->id, $m->metadata['plantilla_id']);
        $this->assertSame(2, $m->metadata['participantes_total']);
        $todo = json_encode($m->toArray());
        foreach (['Secreta', 'SECRETA', '1.023.456.789', '1023456789', '55556666'] as $pii) {
            $this->assertStringNotContainsString($pii, $todo);
        }
    }

    public function test_el_archivo_original_no_se_conserva(): void
    {
        $p = $this->plantilla();
        $antes = Storage::disk('local')->allFiles();

        $this->importar($p);

        $this->assertSame($antes, Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_validacion_del_formulario_del_lote(): void
    {
        $p = $this->plantilla();
        $sinDiseno = $this->crearPlantilla(['pdf' => $this->pdf('otra.pdf', '%otro')]);

        $r = $this->peticion('POST', route('credential-flow.lotes.store'), $this->campos($sinDiseno, $this->archivoValido(), ['nombre' => '   ', 'evento' => '']));
        $r->assertStatus(422)->assertJsonValidationErrors(['nombre', 'evento']);

        $r = $this->peticion('POST', route('credential-flow.lotes.store'), $this->campos($p, $this->archivoValido(), ['fecha' => str_repeat('x', 81)]));
        $r->assertStatus(422)->assertJsonValidationErrors(['fecha']);

        $r = $this->peticion('POST', route('credential-flow.lotes.store'), $this->campos($sinDiseno, $this->archivoValido()));
        $r->assertStatus(422)->assertJsonValidationErrors(['plantilla_id']);

        $r = $this->peticion('POST', route('credential-flow.lotes.store'), $this->campos($p, $this->archivoValido(), ['plantilla_id' => 99999, 'intensidad_horaria' => 'Ω 日']));
        $r->assertStatus(422)->assertJsonValidationErrors(['plantilla_id']);

        $r = $this->peticion('POST', route('credential-flow.lotes.store'), $this->campos($p, null));
        $r->assertStatus(422)->assertJsonValidationErrors(['archivo']);

        $this->assertSame(0, Lote::count());
    }

    // ── Participantes: alta, edición y eliminación manual ─────────────────────

    public function test_alta_manual_usa_las_mismas_reglas_que_el_archivo(): void
    {
        $lote = $this->loteConParticipantes(2);

        $this->actingAs($this->admin())->post(route('credential-flow.participantes.store', $lote), ['nombre_completo' => '  ñandú   pérez ', 'documento' => ' C.C.  99.888.777 '])->assertSessionHasNoErrors();

        $p = $lote->participantes()->latest('id')->first();
        $this->assertSame('ÑANDÚ PÉREZ', $p->nombre_completo);
        $this->assertSame('C.C. 99.888.777', $p->documento);
        $this->assertSame('CC99888777', $p->documento_clave);
        $this->assertNull($p->fila_origen);

        foreach ([
            [['nombre_completo' => 'Ana 日本', 'documento' => 'CC 1234'], 'nombre_completo'],
            [['nombre_completo' => 'A', 'documento' => 'CC 1234'], 'nombre_completo'],
            [['nombre_completo' => 'Ana Ruiz', 'documento' => '=1+1'], 'documento'],
            [['nombre_completo' => 'Ana Ruiz', 'documento' => '1.02E+9'], 'documento'],
            [['nombre_completo' => 'Ana Ruiz', 'documento' => 'C.C. 99.888.777'], 'documento'], // duplicado
            [['nombre_completo' => '=cmd', 'documento' => 'CC 1234'], 'nombre_completo'],
        ] as [$datos, $campo]) {
            $this->actingAs($this->admin())->post(route('credential-flow.participantes.store', $lote), $datos)->assertSessionHasErrors($campo);
        }
        $this->assertSame(3, $lote->participantes()->count());
    }

    public function test_editar_y_eliminar_participante_con_soft_delete_y_duplicados(): void
    {
        $lote = $this->loteConParticipantes(3);
        [$a, $b] = $lote->participantes()->orderBy('id')->get()->all();

        // conservar su propio documento no es duplicado
        $this->actingAs($this->admin())->put(route('credential-flow.participantes.update', [$lote, $a]), ['nombre_completo' => 'nuevo nombre', 'documento' => $a->documento])->assertSessionHasNoErrors();
        $this->assertSame('NUEVO NOMBRE', $a->fresh()->nombre_completo);
        // el documento de otro participante sí lo es
        $this->actingAs($this->admin())->put(route('credential-flow.participantes.update', [$lote, $a]), ['nombre_completo' => 'x y', 'documento' => $b->documento])->assertSessionHasErrors('documento');

        $this->actingAs($this->admin())->delete(route('credential-flow.participantes.destroy', [$lote, $b]))->assertSessionHasNoErrors();
        $this->assertNull(Participante::find($b->id));
        $this->assertNotNull(Participante::withTrashed()->find($b->id)->deleted_at);

        // los eliminados no cuentan para duplicados
        $this->actingAs($this->admin())->post(route('credential-flow.participantes.store', $lote), ['nombre_completo' => 'Reingreso', 'documento' => $b->documento])->assertSessionHasNoErrors();
    }

    public function test_un_participante_de_otro_lote_no_se_puede_tocar_por_la_url(): void
    {
        $lote = $this->loteConParticipantes(1);
        $otro = $this->importar($this->plantilla(), $this->archivoValido(1));
        $ajeno = $otro->participantes()->first();

        $this->actingAs($this->admin())->put(route('credential-flow.participantes.update', [$lote, $ajeno]), ['nombre_completo' => 'Hack Hack', 'documento' => 'CC 99990000'])->assertNotFound();
        $this->actingAs($this->admin())->delete(route('credential-flow.participantes.destroy', [$lote, $ajeno]))->assertNotFound();
        $this->assertNotNull(Participante::find($ajeno->id));
    }

    // ── Lote: editar, eliminar, listar ────────────────────────────────────────

    public function test_editar_lote_no_cambia_la_plantilla(): void
    {
        $lote = $this->loteConParticipantes(1);
        $otra = $this->plantilla();

        $this->actingAs($this->admin())->put(route('credential-flow.lotes.update', $lote), [
            'plantilla_id' => $otra->id, 'nombre' => ' Lote renombrado ', 'descripcion' => '', 'evento' => 'nuevo evento', 'fecha' => '1 de octubre', 'intensidad_horaria' => '8 horas',
        ])->assertSessionHasNoErrors();

        $lote->refresh();
        $this->assertSame('Lote renombrado', $lote->nombre);
        $this->assertNull($lote->descripcion);
        $this->assertSame('NUEVO EVENTO', $lote->datos_comunes['evento']);
        $this->assertNotSame($otra->id, $lote->plantilla_id);

        $this->actingAs($this->admin())->put(route('credential-flow.lotes.update', $lote), ['nombre' => 'X', 'evento' => '', 'fecha' => 'f', 'intensidad_horaria' => 'i'])->assertSessionHasErrors('evento');
    }

    public function test_eliminar_lote_es_soft_delete_y_oculta_a_sus_participantes(): void
    {
        $lote = $this->loteConParticipantes(2);
        $ids = $lote->participantes()->pluck('id')->all();

        $this->actingAs($this->admin())->delete(route('credential-flow.lotes.destroy', $lote))->assertRedirect(route('credential-flow.lotes.index'));

        $this->assertNull(Lote::find($lote->id));
        $this->assertNotNull(Lote::withTrashed()->find($lote->id)->deleted_at);
        $this->assertSame(2, Participante::whereIn('id', $ids)->count()); // no se borran físicamente
        $this->actingAs($this->admin())->get(route('credential-flow.lotes.show', $lote->id))->assertNotFound();
        $this->assertTrue(Storage::disk('local')->exists($lote->plantilla->rutaPdfEsperada()), 'El PDF base no se toca');
    }

    public function test_listado_y_pagina_del_lote_con_busqueda_y_paginacion(): void
    {
        $lote = $this->loteConParticipantes(30);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('credential-flow.lotes.index'))->assertInertia(fn (Assert $page) => $page
            ->component('CredentialFlow/Lotes/Index')
            ->where('lotes.data.0.nombre', 'Lote de prueba')
            ->where('lotes.data.0.participantes', 30)
            ->where('lotes.data.0.evento', 'CONGRESO NACIONAL DE FINANZAS'));

        $this->actingAs($admin)->get(route('credential-flow.lotes.show', $lote))->assertInertia(fn (Assert $page) => $page
            ->component('CredentialFlow/Lotes/Show')
            ->where('lote.total', 30)
            ->has('participantes.data', 25)
            ->where('participantes.last_page', 2));

        $this->actingAs($admin)->get(route('credential-flow.lotes.show', ['lote' => $lote, 'page' => 2]))->assertInertia(fn (Assert $page) => $page->has('participantes.data', 5));

        $this->actingAs($admin)->get(route('credential-flow.lotes.show', ['lote' => $lote, 'q' => 'persona 7']))->assertInertia(fn (Assert $page) => $page
            ->has('participantes.data', 1)
            ->where('participantes.data.0.nombre_completo', 'PERSONA 7')
            ->where('filtros.q', 'persona 7'));

        $this->actingAs($admin)->get(route('credential-flow.lotes.show', ['lote' => $lote, 'q' => '10.000.02']))->assertInertia(fn (Assert $page) => $page->has('participantes.data', 10));
    }

    public function test_formulario_nuevo_solo_ofrece_plantillas_con_diseno(): void
    {
        $con = $this->plantilla();
        $this->crearPlantilla(['pdf' => $this->pdf('sin.pdf', '%sin')]);

        $this->actingAs($this->admin())->get(route('credential-flow.lotes.nuevo'))->assertInertia(fn (Assert $page) => $page
            ->component('CredentialFlow/Lotes/Nuevo')
            ->has('plantillas', 1)
            ->where('plantillas.0.id', $con->id)
            ->where('limites.filas', 1000));
    }

    // ── Plantilla con lotes ───────────────────────────────────────────────────

    public function test_no_se_puede_eliminar_una_plantilla_con_lotes_pero_si_sin_ellos(): void
    {
        $lote = $this->loteConParticipantes(1);
        $p = $lote->plantilla;

        $this->actingAs($this->admin())->delete(route('credential-flow.plantillas.destroy', $p))->assertRedirect(route('credential-flow.plantillas.index'))->assertSessionHas('error');
        $this->assertNotNull(Plantilla::find($p->id));
        $this->assertTrue(Storage::disk('local')->exists($p->rutaPdfEsperada()), 'El PDF base no se borra');

        // con el lote eliminado, la plantilla vuelve a poder eliminarse
        $lote->delete();
        $this->actingAs($this->admin())->delete(route('credential-flow.plantillas.destroy', $p))->assertSessionHas('success');
        $this->assertNull(Plantilla::find($p->id));

        // una plantilla que nunca tuvo lotes se elimina como siempre
        $libre = $this->crearPlantilla(['pdf' => $this->pdf('libre.pdf', '%libre')]);
        $this->actingAs($this->admin())->delete(route('credential-flow.plantillas.destroy', $libre))->assertSessionHas('success');
        $this->assertNull(Plantilla::find($libre->id));
    }

    // ── Plantilla Excel descargable ───────────────────────────────────────────

    public function test_plantilla_excel_de_participantes(): void
    {
        $r = $this->actingAs($this->admin())->get(route('credential-flow.lotes.plantilla-excel'));

        $r->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('plantilla-participantes.xlsx', $r->headers->get('Content-Disposition'));

        $lector = new LectorXlsx;
        $lector->open($r->baseResponse->getFile()->getPathname());
        $hojas = [];
        foreach ($lector->getSheetIterator() as $hoja) {
            $filas = [];
            foreach ($hoja->getRowIterator() as $row) {
                $filas[] = array_map(fn ($c) => $c->getValue(), $row->cells);
            }
            $hojas[$hoja->getName()] = $filas;
        }
        $lector->close();

        $this->assertSame(['Participantes', 'Instrucciones'], array_keys($hojas));
        $this->assertSame([['nombre_completo', 'documento']], $hojas['Participantes']); // solo encabezado, sin filas de ejemplo
        $this->assertGreaterThan(5, count($hojas['Instrucciones']));
    }

    public function test_la_plantilla_excel_descargada_es_importable_una_vez_llena(): void
    {
        $r = $this->actingAs($this->admin())->get(route('credential-flow.lotes.plantilla-excel'));
        $bytes = file_get_contents($r->baseResponse->getFile()->getPathname());

        // solo con el encabezado no hay participantes
        $res = app(ImportadorParticipantes::class)->validar(A::subir($bytes, 'plantilla.xlsx'));
        $this->assertSame(['SIN_FILAS'], array_map(fn ($e) => $e->codigo, $res->errores));
    }

    // ── PDF individual real ───────────────────────────────────────────────────

    public function test_pdf_individual_con_datos_reales_del_participante_y_del_lote(): void
    {
        $lote = $this->loteConParticipantes(0 + 2);
        $participante = $lote->participantes()->orderBy('id')->first();
        $movimientos = Movimiento::count();
        $archivosAntes = Storage::disk('local')->allFiles();

        $r = $this->actingAs($this->admin())->get(route('credential-flow.participantes.pdf', [$lote, $participante]));

        $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $r->assertHeader('Content-Disposition', 'attachment; filename="credencial-'.$participante->id.'.pdf"');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringNotContainsString('PERSONA', $r->headers->get('Content-Disposition'));

        $textos = array_column((new InspectorPdf($r->getContent()))->textos(), 'texto');
        $this->assertSame(['PERSONA 1', 'C.C. 10.000.001', 'CONGRESO NACIONAL DE FINANZAS', '17, 18 y 19 de septiembre de 2026', '30 horas'], $textos);
        $this->assertSame($movimientos, Movimiento::count(), 'El PDF individual no registra Movimiento');
        $this->assertSame($archivosAntes, Storage::disk('local')->allFiles(), 'No se persiste el PDF');
    }

    public function test_pdf_de_un_participante_de_otro_lote_se_rechaza(): void
    {
        $lote = $this->loteConParticipantes(1);
        $otro = $this->importar($this->plantilla(), $this->archivoValido(1));

        $this->actingAs($this->admin())->get(route('credential-flow.participantes.pdf', [$lote, $otro->participantes()->first()]))->assertNotFound();
        $this->actingAs($this->admin())->get('/admin/credential-flow/lotes/'.$lote->id.'/participantes/999999/pdf')->assertNotFound();
    }

    public function test_pdf_individual_sigue_fallando_con_fuente_heredada_y_con_no_cabe(): void
    {
        $heredada = $this->loteConParticipantes(1, $this->plantilla([$this->el(['field' => 'nombre_completo', 'fontFamily' => 'Figtree'])]));
        $r = $this->actingAs($this->admin())->get(route('credential-flow.participantes.pdf', [$heredada, $heredada->participantes()->first()]));
        $r->assertStatus(422)->assertJsonPath('error.code', 'FUENTE_NO_REPRODUCIBLE');

        $angosta = $this->loteConParticipantes(1, $this->plantilla([$this->el(['field' => 'nombre_completo', 'width' => 40])]));
        $r = $this->actingAs($this->admin())->get(route('credential-flow.participantes.pdf', [$angosta, $angosta->participantes()->first()]));
        $r->assertStatus(422)->assertJsonPath('error.code', 'NO_CABE');
        $this->assertStringNotContainsString(base_path(), $r->getContent());
    }

    // ── Permisos ──────────────────────────────────────────────────────────────

    public function test_permisos_de_todas_las_rutas_de_lotes(): void
    {
        $lote = $this->loteConParticipantes(1);
        $part = $lote->participantes()->first();
        $rutas = [
            ['GET', route('credential-flow.lotes.index')],
            ['GET', route('credential-flow.lotes.nuevo')],
            ['GET', route('credential-flow.lotes.plantilla-excel')],
            ['POST', route('credential-flow.lotes.validar')],
            ['POST', route('credential-flow.lotes.store')],
            ['GET', route('credential-flow.lotes.show', $lote)],
            ['PUT', route('credential-flow.lotes.update', $lote)],
            ['DELETE', route('credential-flow.lotes.destroy', $lote)],
            ['POST', route('credential-flow.participantes.store', $lote)],
            ['PUT', route('credential-flow.participantes.update', [$lote, $part])],
            ['DELETE', route('credential-flow.participantes.destroy', [$lote, $part])],
            ['GET', route('credential-flow.participantes.pdf', [$lote, $part])],
        ];

        foreach ($rutas as [$metodo, $url]) {
            $this->actingAs($this->comercial())->call($metodo, $url)->assertForbidden();
        }
        $this->assertNotNull(Lote::find($lote->id));

        $this->app['auth']->forgetGuards();
        foreach ($rutas as [$metodo, $url]) {
            $this->call($metodo, $url)->assertRedirect();
        }
        foreach ([$this->admin(), $this->superAdmin()] as $usuario) {
            $this->actingAs($usuario)->get(route('credential-flow.lotes.index'))->assertOk();
        }
    }

    public function test_todas_las_rutas_de_lotes_son_administrativas_con_auth_y_rol(): void
    {
        $nombres = ['lotes.index', 'lotes.nuevo', 'lotes.plantilla-excel', 'lotes.validar', 'lotes.store', 'lotes.show', 'lotes.update', 'lotes.destroy', 'participantes.store', 'participantes.update', 'participantes.destroy', 'participantes.pdf'];
        foreach ($nombres as $nombre) {
            $ruta = app('router')->getRoutes()->getByName('credential-flow.'.$nombre);
            $this->assertNotNull($ruta, $nombre);
            $this->assertStringStartsWith('admin/credential-flow/lotes', $ruta->uri());
            $this->assertContains('auth', $ruta->gatherMiddleware());
            $this->assertContains('rol:super-admin,admin', $ruta->gatherMiddleware());
        }
        $this->assertContains('throttle:20,1', app('router')->getRoutes()->getByName('credential-flow.participantes.pdf')->gatherMiddleware());
    }
}
