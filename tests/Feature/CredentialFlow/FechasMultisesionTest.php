<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Importacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Generacion\RedaccionFecha;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;
use Tests\Feature\CredentialFlow\Support\XlsxSintetico;

/**
 * Fechas de varias sesiones (no consecutivas, de meses distintos, con el mes delante o detrás): se reconocen para decidir «el día» / «los días»,
 * el asistente no las rechaza solo por no ser un día o un rango, la fecha escrita se imprime tal cual y la vista previa dice lo mismo que el PDF emitido.
 * Los textos vacíos, sin año, con días inexistentes o repetidos siguen sin aceptarse en silencio. Datos 100 % sintéticos.
 */
class FechasMultisesionTest extends EmisionesTestCase
{
    /** Fecha exacta del borrador #32 (cinco sesiones en tres meses, el mes delante del día). */
    private const FECHA_32 = 'Septiembre 2, 9, 16, Octubre 14 y 11 de noviembre de 2026';

    private const EVENTO = 'CURSO SINTÉTICO DE PREPARACIÓN INTEGRAL PARA CONCURSOS';

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
        config(['credential_flow.importacion_inteligente.activa' => true, 'credential_flow.qa.contactos_esperados' => 2, 'credential_flow.emision.exige_vista_previa' => false]);
        for ($i = 1; $i <= 2; $i++) {
            $doc = (string) (72000000 + $i);
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => "QA SESIONES {$i}", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc),
                'correo' => "qa.sesiones{$i}@example.test", 'correo_normalizado' => "qa.sesiones{$i}@example.test", 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    // ── Reconocer cuántos días nombra la fecha ───────────────────────────────

    public function test_cuenta_los_dias_de_una_o_varias_sesiones_en_cualquier_orden(): void
    {
        $casos = [
            '16 de octubre de 2026' => 1,                                                         // un solo día
            '22 y 23 de octubre de 2026' => 2,                                                    // dos días consecutivos
            '16 al 18 de octubre de 2026' => 3,                                                   // rango
            'del 16 al 18 de octubre de 2026' => 3,
            '2, 9 y 16 de septiembre de 2026' => 3,                                               // varios días del mismo mes
            '30 de septiembre de 2026 y 1 de octubre de 2026' => 2,                               // meses distintos (ya admitido)
            '30 de septiembre y 1 de octubre de 2026' => 2,
            '2, 9 y 16 de septiembre, 14 de octubre y 11 de noviembre de 2026' => 5,              // varias sesiones en tres meses
            self::FECHA_32 => 5,                                                                  // el caso exacto del borrador #32
            'SEPTIEMBRE 2, 9, 16, OCTUBRE 14 Y 11 DE NOVIEMBRE DE 2026.' => 5,                    // mayúsculas y punto final
            'septiembre 2, 9 y 16 de 2026' => 3,                                                  // el mes delante, un solo mes
            'Septiembre 2 y 9, Octubre 14 de 2026' => 3,
            '30 y 31 de diciembre de 2026 y 2 de enero de 2027' => 3,                             // cambio de año
        ];
        foreach ($casos as $fecha => $dias) {
            $this->assertSame($dias, RedaccionFecha::dias($fecha), $fecha);
        }
    }

    public function test_no_acepta_en_silencio_fechas_vacias_invalidas_o_ambiguas(): void
    {
        foreach ([
            '', '   ', 'Octubre de 2026', '16/10/2026', '18 al 16 de octubre de 2026',            // ya rechazadas
            'Septiembre 2, 9, 16, Octubre 14 y 11 de noviembre',                                  // sin año
            '31 de septiembre de 2026', '30 de febrero de 2026', 'Septiembre 31, Octubre 1 de 2026', // días que no existen
            '2, 2 de septiembre de 2026', '2 de septiembre de 2026 y 2 de septiembre de 2026',    // sesiones repetidas
            'Septiembre y octubre de 2026', 'Septiembre 2, 9, 16, Octubre 14 y 11 de noviembre de 2026 (por confirmar)',
            'Septiembre 2, 9, 16, 2026',
            // Ambiguas entre meses: más de una lectura razonable de qué días son de cada mes → se pide revisar, nunca se elige una.
            'Octubre 14, 15 y 16 de noviembre de 2026',                                           // ¿14 y 15 de octubre, o solo el 14?
            'Septiembre 2, 9, 16 de octubre de 2026',                                             // ¿el 16 es de septiembre o de octubre?
            'Octubre 14 de noviembre de 2026',                                                    // octubre sin ningún día
            'Septiembre 2, 9, 16, Octubre 14, 21 y 11 de noviembre de 2026',
        ] as $fecha) {
            $this->assertNull(RedaccionFecha::dias($fecha), "«{$fecha}» no debe contarse");
        }
        $conDia = ['elements' => [['field' => 'fecha', 'prefix' => 'realizado el día ']]];
        $this->assertTrue(RedaccionFecha::requiereRevision($conDia, 'Septiembre 2, 9, 16, Octubre 14 y 11 de noviembre'), 'sin año: se pide revisar');
        $this->assertTrue(RedaccionFecha::requiereRevision($conDia, '31 de septiembre de 2026'), 'día inexistente: se pide revisar');
        $this->assertFalse(RedaccionFecha::requiereRevision($conDia, self::FECHA_32));
    }

    public function test_la_fecha_escrita_se_imprime_tal_cual_con_la_redaccion_que_corresponde(): void
    {
        foreach (['realizado el día ', 'realizado los días ', 'Realizado el dia '] as $prefijo) {
            $esperado = ($prefijo === 'Realizado el dia ' ? 'Realizado los dias ' : 'realizado los días ').self::FECHA_32;
            $this->assertSame($esperado, RedaccionFecha::prefijo($prefijo, self::FECHA_32).self::FECHA_32, $prefijo);
        }
        $this->assertSame('Fecha: '.self::FECHA_32, RedaccionFecha::prefijo('Fecha: ', self::FECHA_32).self::FECHA_32, 'otra redacción se respeta');
        $this->assertSame('realizado el día 16 de octubre de 2026', RedaccionFecha::prefijo('realizado los días ', '16 de octubre de 2026').'16 de octubre de 2026');
    }

    // ── El asistente de importación y el PDF ──────────────────────────────────

    public function test_el_asistente_propone_las_cinco_sesiones_reales_y_acepta_la_fecha_escrita_del_borrador_32(): void
    {
        $p = $this->institucional('realizado el día ');
        $evento = EventoCertificacion::create(['nombre' => self::EVENTO, 'nombre_normalizado' => NombreConservador::normalizar(self::EVENTO), 'anio' => 2026,
            'fecha_texto' => self::FECHA_32, 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW]);
        $id = $this->analizar(self::FECHA_32);
        $this->decidir($id, $p, $evento);

        // Propuesta del Excel: las cinco sesiones reales, agrupadas por mes (nunca «11 y 14 de noviembre», que inventaba una fecha).
        $this->assertSame('2, 9 y 16 de septiembre, 14 de octubre y 11 de noviembre de 2026', $this->fechaVista($id)['valor']);
        $this->assertSame('realizado los días 2, 9 y 16 de septiembre, 14 de octubre y 11 de noviembre de 2026', $this->fechaVista($id)['se_lee']);

        // El administrador conserva la fecha EXACTA del Excel: se acepta, no bloquea y se imprime tal cual.
        $this->corregir($id, ['datos_comunes' => ['fecha' => self::FECHA_32]]);
        $vista = $this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.vista-previa', $id))->assertOk()->json('vista_previa');
        $this->assertSame([], $vista['problemas'], json_encode($vista['problemas']));
        $fecha = collect($vista['campos'])->firstWhere('clave', 'fecha');
        $this->assertSame([self::FECHA_32, 'realizado los días '.self::FECHA_32], [$fecha['valor'], $fecha['se_lee']]);
        $estado = $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.validar', $id))->assertOk()->json();
        $this->assertNotContains('fecha_sin_dias_claros', array_column($estado['bloqueos'], 'codigo'));
        $this->assertTrue($estado['listo'], json_encode($estado['bloqueos']));
        $pdf = $this->actingAs($this->admin())->get(route('credential-flow.importaciones.vista-previa.pdf', $id))->assertOk()->getContent();
        $this->assertContains('realizado los días '.self::FECHA_32, array_column((new InspectorPdf($pdf))->textos(), 'texto'));

        // Sin año sigue bloqueando, con un mensaje que explica cómo escribir varias sesiones.
        $this->corregir($id, ['datos_comunes' => ['fecha' => 'Septiembre 2, 9, 16, Octubre 14 y 11 de noviembre']]);
        $bloqueo = collect($this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.validar', $id))->assertOk()->json('bloqueos'))->firstWhere('codigo', 'fecha_sin_dias_claros');
        $this->assertNotNull($bloqueo);
        $this->assertStringContainsString('14 de octubre y 11 de noviembre de 2026', $bloqueo['mensaje']);
    }

    public function test_el_asistente_no_inventa_fechas_cuando_no_puede_leer_varias_sesiones(): void
    {
        $p = $this->institucional('realizado el día ');
        $evento = EventoCertificacion::create(['nombre' => self::EVENTO, 'nombre_normalizado' => NombreConservador::normalizar(self::EVENTO), 'anio' => 2026,
            'fecha_texto' => '30 de septiembre de 2026', 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW]);
        // Sin año: antes se leía «14 y 11 de noviembre» sin año → nada; ahora tampoco se propone ninguna fecha y se pide escribirla.
        $id = $this->analizar('Septiembre 2, 9, 16, Octubre 14 y 11 de noviembre');
        $this->decidir($id, $p, $evento);
        $this->assertNull($this->fechaVista($id)['valor']);
        // Un día por mes: la propuesta conserva el formato de siempre; con el año solo al final, ya no se pierde el primer día.
        $dos = $this->analizar('30 de septiembre y 1 de octubre de 2026');
        $this->decidir($dos, $p, $evento);
        $this->assertSame('30 de septiembre de 2026 y 1 de octubre de 2026', $this->fechaVista($dos)['valor']);
    }

    public function test_la_vista_previa_y_el_pdf_emitido_dicen_exactamente_lo_mismo(): void
    {
        $p = $this->institucional('realizado el día ');
        $this->variante($p);
        $evento = EventoCertificacion::create(['nombre' => self::EVENTO, 'nombre_normalizado' => NombreConservador::normalizar(self::EVENTO), 'anio' => 2026,
            'fecha_texto' => self::FECHA_32, 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW]);
        $id = $this->analizar(self::FECHA_32);
        $this->decidir($id, $p, $evento);
        $this->corregir($id, ['datos_comunes' => ['fecha' => self::FECHA_32]]);
        $vista = array_column((new InspectorPdf($this->actingAs($this->admin())->get(route('credential-flow.importaciones.vista-previa.pdf', $id))->assertOk()->getContent()))->textos(), 'texto');

        $lote = $this->base($p, self::FECHA_32);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();
        $linea = 'realizado los días '.self::FECHA_32;
        $this->assertContains($linea, $vista);
        foreach ([['Presencial', false], ['Virtual', false], ['Presencial', true], ['Virtual', true]] as [$m, $qa]) {
            $emitido = $this->textos($this->emisionDe($lote, $m, $qa));
            $this->assertContains($linea, $emitido, "{$m} ".($qa ? 'QA' : 'oficial').': la misma frase que la vista previa');
            $this->assertSame(1, count(array_filter($emitido, fn ($t) => str_contains($t, 'Octubre 14'))), 'la fecha va en una sola línea, completa');
        }
        $this->assertSame(self::FECHA_32, $lote->fresh()->datos_comunes['fecha'], 'la fecha guardada no se modifica');
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    private function institucional(string $prefijoFecha): Plantilla
    {
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'modalidad', 'intensidad_horaria'];

        return $this->plantillaLista(array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 40 + $i * 55, 'height' => 30, 'fontSize' => 12]
            + ($c === 'fecha' ? ['prefix' => $prefijoFecha] : [])), $campos, array_keys($campos)));
    }

    private function variante(Plantilla $p): Plantilla
    {
        $this->artisan('credential-flow:plantilla-variante-qa', ['origen' => $p->id, '--ejecutar' => true])->assertExitCode(0);

        return Plantilla::query()->findOrFail($p->fresh()->variante_qa_id);
    }

    private function base(Plantilla $p, string $fecha): Lote
    {
        $lote = $this->loteCon(0, $p);
        $lote->update(['datos_comunes' => ['evento' => self::EVENTO, 'fecha' => $fecha, 'intensidad_horaria' => '40 horas', 'modalidad' => 'Presencial']]);
        $this->participante($lote, 'Persona Presencial', 'C.C. 40343241')->update(['modalidad' => 'Presencial']);
        $this->participante($lote, 'Persona Virtual', 'C.C. 40343242')->update(['modalidad' => 'Virtual']);
        app(ServicioQa::class)->incorporar($lote->fresh());

        return $lote->fresh();
    }

    private function analizar(string $fecha): int
    {
        $c = ['B1' => 'Nombre del Evento', 'D1' => self::EVENTO, 'F2' => 'Modalidad', 'G2' => 'Presencial', 'B3' => 'Fecha del Evento', 'D3' => $fecha, 'F3' => 'Intensidad de horas', 'G3' => '40 Horas',
            'B7' => 'Identificaciòn', 'C7' => 'Nombres y Apellidos', 'D7' => 'Correo Electronico', 'E7' => 'Observaciones o comentarios adicionales'];
        foreach (['PRESENCIAL', 'VIRTUAL'] as $i => $m) {
            $c['B'.(8 + $i)] = (string) (54000001 + $i);
            $c['C'.(8 + $i)] = 'PERSONA SESIONES '.($i + 1);
            $c['D'.(8 + $i)] = 'sesiones'.($i + 1).'@example.test';
            $c['E'.(8 + $i)] = $m;
        }
        $excel = XlsxSintetico::libro([['nombre' => 'Hoja1', 'celdas' => $c]]);

        return $this->actingAs($this->admin())->post(route('credential-flow.importaciones.analizar'), ['archivo' => UploadedFile::fake()->createWithContent('s.xlsx', $excel)], ['Accept' => 'application/json'])
            ->assertSuccessful()->json('borrador.id');
    }

    private function corregir(int $id, array $c): void
    {
        $this->actingAs($this->admin())->putJson(route('credential-flow.importaciones.correcciones', $id), ['version' => Importacion::findOrFail($id)->version] + $c)->assertOk();
    }

    private function decidir(int $id, Plantilla $p, EventoCertificacion $evento): void
    {
        $this->corregir($id, ['plantilla_id' => $p->id, 'evento' => ['decision' => 'asociar', 'evento_id' => $evento->id],
            'confirmados' => ['datos_comunes' => ['fecha', 'intensidad_horaria', 'modalidad'], 'valores_desactualizados' => true], 'participantes' => ['8' => ['tipo_documento' => 'C.C.'], '9' => ['tipo_documento' => 'C.C.']]]);
    }

    private function fechaVista(int $id): array
    {
        return collect($this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.vista-previa', $id))->assertOk()->json('vista_previa.campos'))->firstWhere('clave', 'fecha');
    }

    /** @return list<string> */
    private function textos(Emision $e): array
    {
        return array_column((new InspectorPdf($this->actingAs($this->admin())->get(route('credential-flow.emisiones.descargar', $e))->assertOk()->getContent()))->textos(), 'texto');
    }

    private function emisionDe(Lote $lote, string $modalidad, bool $qa): Emision
    {
        $p = $lote->participantes()->where('es_control_qa', $qa)->where('modalidad', $modalidad)->orderBy('id')->firstOrFail();

        return Emision::where('participante_id', $p->id)->firstOrFail();
    }
}
