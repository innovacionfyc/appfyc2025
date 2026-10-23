<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Importacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Participantes\CeldaCruda;
use App\Support\CredentialFlow\Participantes\ResultadoFila;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Participantes\ValidadorParticipante;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\CredentialFlow\Support\ExcelSolicitud as X;

/**
 * Hotfix: con C.C. o T.I. se valida el NÚMERO del documento, sin contar las letras del prefijo. Antes «C.C. 12» y «C.C. ABC-12» pasaban porque «CC»
 * sumaba caracteres al mínimo. Los demás tipos (C.E., pasaporte, PPT…) no cambian. Datos 100 % sintéticos.
 */
class DocumentoCedulaTest extends EmisionesTestCase
{
    private const CONTACTOS = [['QA UNO PRUEBA', 'C.C. 8.000.001', 'qa.uno@example.test'], ['QA DOS PRUEBA', 'C.C. 8.000.002', 'qa.dos@example.test']];

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
        foreach (self::CONTACTOS as [$n, $d, $c]) {
            \DB::table('cf_contactos_qa')->insert(['nombre_completo' => $n, 'documento' => $d, 'documento_clave' => Texto::claveDocumento($d), 'correo' => $c, 'correo_normalizado' => $c, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function validar(string $documento): ResultadoFila
    {
        return ValidadorParticipante::validar(CeldaCruda::texto('PERSONA PRUEBA'), CeldaCruda::texto($documento));
    }

    private function corregir(int $id, array $cuerpo): TestResponse
    {
        return $this->actingAs($this->admin())->putJson(route('credential-flow.importaciones.correcciones', $id), ['version' => Importacion::findOrFail($id)->version] + $cuerpo);
    }

    private function aplicarTipo(int $id, string $tipo, array $filas): void
    {
        $version = Importacion::findOrFail($id)->version;
        $previa = $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.masivas.vista-previa', $id), ['version' => $version, 'campo' => 'tipo_documento', 'valor' => $tipo, 'filas' => $filas])
            ->assertOk()->json('vista_previa');
        $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.masivas.aplicar', $id), [
            'version' => $version, 'operacion_id' => (string) Str::uuid(), 'huella' => $previa['huella'], 'campo' => 'tipo_documento', 'valor' => $tipo, 'filas' => $filas,
            // Cambiar un tipo ya asignado es un reemplazo: la pantalla pide confirmarlo aparte.
            'confirmar_sobrescritura' => $previa['requiere_confirmar_sobrescritura'],
        ])->assertOk();
    }

    /** @return array<int,string> fila del Excel => mensaje de cada documento inválido que impide crear la base */
    private function documentosBloqueados(int $id): array
    {
        return collect($this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.validar', $id))->assertOk()->json('bloqueos'))
            ->where('codigo', 'fila_invalida')->where('campo', 'documento')->mapWithKeys(fn ($b) => [$b['fila'] => $b['mensaje']])->sortKeys()->all();
    }

    // ── El validador (importación, edición manual, contactos QA) ─────────────

    public function test_con_cedula_o_tarjeta_de_identidad_el_prefijo_no_cuenta_como_parte_del_numero(): void
    {
        $negativos = [
            'C.C. 12' => ['DOCUMENTO_MUY_CORTO', 'al menos 4 dígitos (sin contar «C.C.»)'],
            'C.C. ABC-12' => ['DOCUMENTO_INVALIDO', 'solo puede tener dígitos'],
            'C.C. =1+2' => ['DOCUMENTO_INVALIDO', 'solo puede tener dígitos'],
            'T.I. ABC-12' => ['DOCUMENTO_INVALIDO', 'El número de la T.I.'],
            'T.I. 123' => ['DOCUMENTO_MUY_CORTO', 'sin contar «T.I.»'],
            'C.C.' => ['DOCUMENTO_MUY_CORTO', 'al menos 4 letras o dígitos'],   // prefijo sin número: ya se rechazaba, con su mensaje de siempre
            'CC' => ['DOCUMENTO_MUY_CORTO', 'al menos 4 letras o dígitos'],
            'T.I. -' => ['DOCUMENTO_MUY_CORTO', 'al menos 4 letras o dígitos'],
            'C.C. 4034323A' => ['DOCUMENTO_INVALIDO', '«4034323A»'],
            'cc: 1.2E+09' => ['DOCUMENTO_INVALIDO', 'solo puede tener dígitos'],
            'C.C. 40.343.23' => ['DOCUMENTO_INVALIDO', 'puntos de miles'],
            '' => ['DOCUMENTO_VACIO', 'vacío'],
        ];
        foreach ($negativos as $documento => [$codigo, $mensaje]) {
            $r = $this->validar($documento);
            $this->assertNull($r->documento, $documento);
            $this->assertSame([$codigo], array_map(fn ($e) => $e->codigo, $r->errores), $documento);
            $this->assertStringContainsString($mensaje, $r->errores[0]->mensaje, $documento);
        }
    }

    public function test_las_cedulas_legitimas_y_los_otros_tipos_siguen_valiendo_sin_cambiar_el_dato(): void
    {
        $positivos = [
            'C.C. 40343234', 'C.C. 1.023.456.789', 'C.C. 1052312957', 'C.C. 0012345678', 'C.C. 0123', 'C.C.40343234', 'CC 1052312957', 'cc:1052312957',
            'C.C. 1 023 456 789', 'T.I. 1001234567', 'T.I. 98.031.212.345',
            // Otros tipos: pueden llevar letras o guiones; la regla de cédula no se les aplica.
            'C.E. AB12345', 'PASAPORTE AB123456', 'PPT 1234567', 'PEP 123456789012345', 'NIT 900.123.456-7',
            // Sin tipo (históricos, tipo por confirmar): misma regla general de siempre.
            '40343234', '79.131.000', 'ABC-12',
        ];
        foreach ($positivos as $documento) {
            $r = $this->validar($documento);
            $this->assertSame([], $r->errores, $documento);
            $this->assertSame($documento, $r->documento, 'el dato se guarda tal cual: '.$documento);
            $this->assertSame(Texto::claveDocumento($documento), $r->clave, 'la clave del portal no cambia: '.$documento);
        }
    }

    // ── Importación: el número se valida después de asignar el tipo en bloque ──

    public function test_al_asignar_el_tipo_en_bloque_los_numeros_invalidos_bloquean_la_base_y_los_validos_se_importan(): void
    {
        // Filas del Excel: 15 «12», 16 «ABC-12», 17 «1.023.456.789», 18 «0012345678», 19 «40343234», 20 número de Excel. Sin columna de tipo.
        $bytes = X::bytes(6, ['documentos' => [1 => '12', 2 => 'ABC-12', 3 => '1.023.456.789', 4 => '0012345678', 5 => '40343234']]);
        $id = $this->actingAs($this->admin())->post(route('credential-flow.importaciones.analizar'), ['archivo' => UploadedFile::fake()->createWithContent('solicitud.xlsx', $bytes)], ['Accept' => 'application/json'])
            ->assertSuccessful()->json('borrador.id');
        $this->corregir($id, [
            'plantilla_id' => $this->plantilla->id, 'evento' => ['decision' => 'asociar', 'evento_id' => 631],
            'confirmados' => ['datos_comunes' => ['fecha', 'intensidad_horaria', 'modalidad'], 'valores_desactualizados' => true],
        ])->assertOk();

        // Sin tipo, «ABC-12» puede ser un pasaporte: solo se rechaza el demasiado corto, como siempre.
        $this->assertSame([15], array_keys($this->documentosBloqueados($id)));

        $this->aplicarTipo($id, 'C.C.', [15, 16, 17, 18, 19, 20]);
        $bloqueados = $this->documentosBloqueados($id);
        $this->assertSame([15, 16], array_keys($bloqueados), 'con C.C. se rechazan «12» y «ABC-12»; los demás pasan');
        $this->assertStringContainsString('al menos 4 dígitos (sin contar «C.C.»)', $bloqueados[15]);
        $this->assertStringContainsString('solo puede tener dígitos', $bloqueados[16]);

        $this->aplicarTipo($id, 'T.I.', [16]);
        $this->assertStringContainsString('El número de la T.I.', $this->documentosBloqueados($id)[16]);

        // Con otro tipo que admite letras, el mismo número pasa.
        $this->aplicarTipo($id, 'PASAPORTE', [16]);
        $this->assertSame([15], array_keys($this->documentosBloqueados($id)));

        // Corregido el número (o excluida la persona), la base se crea con los válidos intactos.
        $this->corregir($id, ['participantes' => ['15' => ['documento' => '0040343235']]])->assertOk();
        $this->assertSame([], $this->documentosBloqueados($id));
        $this->assertTrue($this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.validar', $id))->assertOk()->json('listo'));

        $lote = Lote::findOrFail($this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.confirmar', $id), ['version' => Importacion::findOrFail($id)->version, 'confirmar' => true])
            ->assertCreated()->json('lote_id'));
        $documentos = $lote->participantes()->where('es_control_qa', false)->orderBy('id')->pluck('documento')->all();
        $this->assertContains('C.C. 0040343235', $documentos);
        $this->assertContains('C.C. 0012345678', $documentos, 'los ceros iniciales se conservan');
        $this->assertContains('C.C. 40343234', $documentos);
        $this->assertContains('PASAPORTE ABC-12', $documentos);
        $this->assertCount(6, $documentos);
        $this->assertSame(0, Emision::count());
    }

    // ── Edición individual (alta y corrección manual de un participante) ─────

    public function test_la_edicion_manual_rechaza_el_numero_invalido_con_un_mensaje_claro(): void
    {
        $lote = $this->loteCon(0);
        $p = $this->participante($lote, 'PERSONA SINTETICA', 'C.C. 40343234');

        foreach (['C.C. 12' => 'al menos 4 dígitos', 'C.C. ABC-12' => 'solo puede tener dígitos', 'T.I. ABC-12' => 'El número de la T.I.', 'C.C.' => 'al menos 4 letras o dígitos', 'CC 4444 😀' => 'La fuente Outfit no tiene'] as $documento => $mensaje) {
            $this->actingAs($this->admin())->put(route('credential-flow.participantes.update', [$lote->id, $p->id]), ['nombre_completo' => 'PERSONA SINTETICA', 'documento' => $documento])
                ->assertSessionHasErrors('documento');
            $this->assertStringContainsString($mensaje, session('errors')->first('documento'), $documento);
            $this->assertSame('C.C. 40343234', $p->fresh()->documento, 'no se guardó nada: '.$documento);
        }

        $this->actingAs($this->admin())->put(route('credential-flow.participantes.update', [$lote->id, $p->id]), ['nombre_completo' => 'PERSONA SINTETICA', 'documento' => 'C.C. 1.023.456.789'])
            ->assertSessionHasNoErrors();
        $this->assertSame('C.C. 1.023.456.789', $p->fresh()->documento, 'se guarda tal como se escribió');

        $this->actingAs($this->admin())->post(route('credential-flow.participantes.store', $lote->id), ['nombre_completo' => 'OTRA SINTETICA', 'documento' => 'PASAPORTE AB123456'])
            ->assertSessionHasNoErrors();
    }
}
