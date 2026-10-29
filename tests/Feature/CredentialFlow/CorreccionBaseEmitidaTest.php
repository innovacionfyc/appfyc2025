<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Emisiones\EmisorCredencial;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Emisiones\FlujoDeBase;
use App\Support\CredentialFlow\Emisiones\ReemisorBase;
use App\Support\CredentialFlow\Emisiones\ServicioVistaPrevia;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Portal\EmisionesModernas;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;

/**
 * Corrección de certificados YA emitidos: cambio AUTORIZADO de plantilla (credential-flow:base-cambiar-plantilla) y reemisión MASIVA controlada
 * (credential-flow:base-reemitir). Simulación obligatoria con huella, administrador y motivo; certificados intactos al cambiar la plantilla; vista previa
 * exigida de nuevo; oficiales y QA por separado; PDF anteriores conservados; repetir sin duplicar; fallos sin efectos parciales; candado del programador;
 * otras bases que comparten la plantilla intactas; auditoría. Datos 100 % sintéticos, SQLite en memoria y discos falsos.
 */
class CorreccionBaseEmitidaTest extends EmisionesTestCase
{
    private const FIJO_ANTERIOR = 'por su asistencia al';

    private const FIJO_NUEVO = 'por su asistencia a la';

    private const MOTIVO = 'Corrección de redacción: jornada de un día';

    private Plantilla $institucional;

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
        ], '--force' => true])->assertExitCode(0);
        config(['credential_flow.qa.contactos_esperados' => 8]);

        // Como la #15 de producción: campos del certificado + un texto FIJO «por su asistencia al» (el que hay que corregir).
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'];
        $elementos = array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 80 + $i * 60, 'fontSize' => 16]), $campos, array_keys($campos));
        $elementos[] = $this->elemento(['text' => self::FIJO_ANTERIOR, 'y' => 30, 'fontSize' => 14]);
        $this->institucional = $this->plantillaLista($elementos);
        $this->artisan('credential-flow:plantilla-variante-qa', ['origen' => $this->institucional->id, '--ejecutar' => true])->assertExitCode(0);
        for ($i = 1; $i <= 8; $i++) {
            $doc = (string) (70000000 + $i);
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => "QA SINTÉTICO {$i}", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc),
                'correo' => "qa{$i}@example.test", 'correo_normalizado' => "qa{$i}@example.test", 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    /** Base con N participantes reales + los 8 QA, ya emitida con la plantilla institucional. */
    private function baseEmitida(int $reales = 3, ?Plantilla $plantilla = null): Lote
    {
        $lote = $this->loteCon(0, $plantilla ?? $this->institucional);
        for ($i = 1; $i <= $reales; $i++) {
            $this->participante($lote, "Persona Real {$lote->id}-{$i}", 'C.C. '.(40343230 + $lote->id * 100 + $i));
        }
        app(ServicioQa::class)->incorporar($lote);
        app(EmisorLote::class)->emitirTodo($lote->fresh(), $this->admin()->id);

        return $lote->fresh();
    }

    /** Copia de la institucional con el texto corregido y su variante QA (lo que se hizo en producción en la F1). */
    private function plantillaCorregida(string $texto = self::FIJO_NUEVO): Plantilla
    {
        [$codigo, $salida] = $this->comando('credential-flow:plantilla-duplicar', ['origen' => $this->institucional->id, '--nombre' => 'Asistencia (femenino, un día) '.uniqid(), '--ejecutar' => true]);
        $this->assertSame(0, $codigo, $salida);
        $copia = Plantilla::query()->latest('id')->firstOrFail();
        $diseno = $copia->diseno;
        foreach ($diseno['elements'] as &$e) {
            if (($e['text'] ?? '') === self::FIJO_ANTERIOR) {
                $e['text'] = $texto;
            }
        }
        unset($e);
        $copia->update(['diseno' => $diseno]);
        $this->artisan('credential-flow:plantilla-variante-qa', ['origen' => $copia->id, '--ejecutar' => true])->assertExitCode(0);

        return $copia->fresh();
    }

    /** @return array{0:int, 1:string} código de salida y salida del comando */
    private function comando(string $nombre, array $args): array
    {
        $codigo = Artisan::call($nombre, $args);

        return [$codigo, Artisan::output()];
    }

    private function huella(string $salida): string
    {
        $this->assertSame(1, preg_match('/Huella del plan: ([0-9a-f]{64})/', $salida, $m), $salida);

        return $m[1];
    }

    private function cambiar(Lote $lote, Plantilla $nueva, array $extra = []): array
    {
        [, $sim] = $this->comando('credential-flow:base-cambiar-plantilla', ['base' => $lote->id, 'plantilla' => $nueva->id]);

        return $this->comando('credential-flow:base-cambiar-plantilla', array_merge(['base' => $lote->id, 'plantilla' => $nueva->id, '--usuario' => $this->admin()->id,
            '--motivo' => self::MOTIVO, '--plan' => $this->huella($sim), '--ejecutar' => true], $extra));
    }

    /** Lo que confirmaría una persona en la pantalla de la base: abrir la vista previa de un participante real y confirmar la plantilla. */
    private function confirmarVistaPrevia(Lote $lote): void
    {
        $p = $lote->participantes()->where('es_control_qa', false)->orderBy('id')->firstOrFail();
        $this->actingAs($this->admin())->get(route('credential-flow.participantes.pdf', [$lote, $p]))->assertOk();
        $this->actingAs($this->admin())->post(route('credential-flow.lotes.vista-previa.confirmar', $lote))->assertSessionHasNoErrors();
    }

    private function reemitir(Lote $lote, array $extra = []): array
    {
        [, $sim] = $this->comando('credential-flow:base-reemitir', ['base' => $lote->id] + array_intersect_key($extra, ['--grupo' => 1]));

        return $this->comando('credential-flow:base-reemitir', array_merge(['base' => $lote->id, '--usuario' => $this->admin()->id, '--motivo' => self::MOTIVO,
            '--plan' => $this->huella($sim), '--ejecutar' => true], $extra));
    }

    /** Huella de TODAS las emisiones (fila completa) y de todos los archivos: para probar que algo no cambió en absoluto. */
    private function foto(): array
    {
        return [
            DB::table('cf_emisiones')->orderBy('id')->get()->map(fn ($e) => (array) $e)->all(),
            collect($this->archivosDeEmisiones())->mapWithKeys(fn ($f) => [$f => hash('sha256', Storage::disk('local')->get($f))])->sort()->all(),
            DB::table('cf_lotes')->orderBy('id')->get(['id', 'plantilla_id', 'datos_comunes', 'vista_previa_confirmada_at'])->map(fn ($l) => (array) $l)->all(),
        ];
    }

    private function textos(Emision $e): array
    {
        return array_column((new InspectorPdf($this->actingAs($this->admin())->get(route('credential-flow.emisiones.descargar', $e))->assertOk()->getContent()))->textos(), 'texto');
    }

    private function vigentes(Lote $lote, bool $qa): Collection
    {
        return Emision::query()->where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)
            ->whereIn('participante_id', $lote->participantes()->where('es_control_qa', $qa)->select('id'))->orderBy('id')->get();
    }

    // ── Cambio autorizado de plantilla ────────────────────────────────────────

    public function test_simular_el_cambio_no_escribe_nada_y_explica_cada_bloqueo(): void
    {
        $lote = $this->baseEmitida();
        $nueva = $this->plantillaCorregida();
        $variante = Plantilla::query()->findOrFail($nueva->variante_qa_id);
        $sinVariante = $this->plantillaLista();
        $sinEmitir = $this->loteCon(1);
        $foto = $this->foto();

        [$codigo, $salida] = $this->comando('credential-flow:base-cambiar-plantilla', ['base' => $lote->id, 'plantilla' => $nueva->id]);
        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('SIMULACIÓN (no se escribió nada)', $salida);
        $this->assertStringContainsString('3 oficiales + 8 QA', $salida);
        $this->assertSame($foto, $this->foto(), 'simular no escribe');

        $casos = [
            [$lote->id, $variante->id, 'de pruebas internas (QA)'],
            [$lote->id, $sinVariante->id, 'no tiene su variante de pruebas internas'],
            [$lote->id, $this->institucional->id, 'ya usa esa plantilla'],
            [$sinEmitir->id, $nueva->id, 'todavía no emitió certificados'],
        ];
        foreach ($casos as [$base, $plantilla, $texto]) {
            [$c, $s] = $this->comando('credential-flow:base-cambiar-plantilla', ['base' => $base, 'plantilla' => $plantilla]);
            $this->assertSame(1, $c, $s);
            $this->assertStringContainsString($texto, $s);
        }
        $this->assertSame($foto, $this->foto());
    }

    public function test_el_cambio_autorizado_no_toca_ningun_certificado_exige_nueva_vista_previa_y_queda_auditado(): void
    {
        $lote = $this->baseEmitida();
        $nueva = $this->plantillaCorregida();
        config(['credential_flow.emision.exige_vista_previa' => true]);
        [$emisiones, $archivos] = $this->foto();
        $this->assertTrue(app(ServicioVistaPrevia::class)->exentaPorEmisiones($lote), 'antes: exenta por haber emitido con su plantilla');

        [$codigo, $salida] = $this->cambiar($lote, $nueva);

        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('Ningún certificado cambió', $salida);
        $lote = $lote->fresh();
        $this->assertSame($nueva->id, $lote->plantilla_id);
        $this->assertNull($lote->vista_previa_confirmada_at);
        $this->assertSame($this->admin()->id, (int) $lote->update_by, 'queda quién cambió la base');
        [$emisionesDespues, $archivosDespues] = $this->foto();
        $this->assertSame($emisiones, $emisionesDespues, 'ninguna emisión cambió (ni estado, ni PDF, ni snapshot)');
        $this->assertSame($archivos, $archivosDespues);

        // La plantilla nueva exige su propia vista previa: ni la reemisión masiva ni la individual de un oficial salen sin confirmarla.
        $this->assertFalse(app(ServicioVistaPrevia::class)->exentaPorEmisiones($lote));
        $flujo = app(FlujoDeBase::class)->para($lote, app(EmisorLote::class)->resumen($lote));
        $this->assertSame('pendiente', collect($flujo['pasos'])->firstWhere('clave', 'vista_previa')['estado'], 'la pantalla vuelve a pedir la vista previa');
        $this->assertContains('Abrir y revisar la vista previa de un participante con la plantilla actual.', $flujo['faltan']);
        $this->assertFalse($flujo['vista_previa']['exenta_por_emisiones']);
        $plan = app(ReemisorBase::class)->planear($lote, ReemisorBase::OFICIALES);
        $this->assertSame('VISTA_PREVIA_PENDIENTE', $plan['bloqueo']['code']);
        $vigente = $this->vigentes($lote, false)->first();
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $vigente), ['motivo' => 'Prueba individual'])
            ->assertStatus(409)->assertJsonPath('error.code', 'VISTA_PREVIA_PENDIENTE');
        // Los QA no dependen de la vista previa: su grupo ya se puede reemitir con la variante nueva.
        $this->assertNull(app(ReemisorBase::class)->planear($lote, ReemisorBase::QA)['bloqueo']);

        $this->confirmarVistaPrevia($lote);
        $this->assertNull(app(ReemisorBase::class)->planear($lote->fresh(), ReemisorBase::OFICIALES)['bloqueo']);

        $mov = Movimiento::query()->where('descripcion', 'like', 'Se cambió la plantilla de una base con certificados emitidos%')->sole();
        $this->assertSame($this->admin()->id, (int) $mov->user_id);
        $this->assertSame([$lote->id, $this->institucional->id, $nueva->id, self::MOTIVO, $this->admin()->id, 3, 8],
            [$mov->metadata['lote_id'], $mov->metadata['plantilla_anterior_id'], $mov->metadata['plantilla_nueva_id'], $mov->metadata['motivo'], $mov->metadata['autorizado_por'],
                $mov->metadata['vigentes_oficiales'], $mov->metadata['vigentes_qa']]);
    }

    public function test_el_cambio_exige_simulacion_huella_vigente_administrador_y_motivo(): void
    {
        $lote = $this->baseEmitida(2);
        $nueva = $this->plantillaCorregida();
        $foto = $this->foto();
        [, $sim] = $this->comando('credential-flow:base-cambiar-plantilla', ['base' => $lote->id, 'plantilla' => $nueva->id]);
        $plan = $this->huella($sim);
        $base = ['base' => $lote->id, 'plantilla' => $nueva->id, '--motivo' => self::MOTIVO, '--plan' => $plan, '--ejecutar' => true];

        $casos = [
            [array_diff_key($base, ['--plan' => 1]) + ['--usuario' => $this->admin()->id], 'La simulación es obligatoria'],
            [$base + ['--usuario' => $this->comercial()->id], 'NO_AUTORIZADO'],
            [$base + ['--usuario' => 999999], 'NO_AUTORIZADO'],
            [$base, 'NO_AUTORIZADO'],
            [['--motivo' => 'corto'] + $base + ['--usuario' => $this->admin()->id], 'MOTIVO_INVALIDO'],
            [['--plan' => str_repeat('a', 64)] + $base + ['--usuario' => $this->admin()->id], 'PLAN_DESACTUALIZADO'],
        ];
        foreach ($casos as [$args, $texto]) {
            [$c, $s] = $this->comando('credential-flow:base-cambiar-plantilla', $args);
            $this->assertSame(1, $c, $s);
            $this->assertStringContainsString($texto, $s);
        }
        $this->assertSame($foto, $this->foto(), 'ningún intento rechazado escribió algo');

        // Algo cambia entre la simulación y la ejecución (otra emisión revocada): la huella ya no vale y no se cambia nada.
        app(EmisorCredencial::class)->revocar($this->vigentes($lote, false)->first(), 'Revocada por otra persona', $this->superAdmin()->id);
        [$c, $s] = $this->comando('credential-flow:base-cambiar-plantilla', $base + ['--usuario' => $this->admin()->id]);
        $this->assertSame(1, $c);
        $this->assertStringContainsString('PLAN_DESACTUALIZADO', $s);
        $this->assertSame($this->institucional->id, $lote->fresh()->plantilla_id);
        $this->assertSame(0, Movimiento::query()->where('descripcion', 'like', 'Se cambió la plantilla de una base con certificados%')->count());
    }

    public function test_dos_administradores_cambiando_la_misma_base_solo_uno_lo_logra(): void
    {
        $lote = $this->baseEmitida(1);
        $a = $this->plantillaCorregida();
        $b = $this->plantillaCorregida('por su participación en la');
        [, $simA] = $this->comando('credential-flow:base-cambiar-plantilla', ['base' => $lote->id, 'plantilla' => $a->id]);
        [, $simB] = $this->comando('credential-flow:base-cambiar-plantilla', ['base' => $lote->id, 'plantilla' => $b->id]);

        [$ca] = $this->comando('credential-flow:base-cambiar-plantilla', ['base' => $lote->id, 'plantilla' => $a->id, '--usuario' => $this->admin()->id, '--motivo' => self::MOTIVO, '--plan' => $this->huella($simA), '--ejecutar' => true]);
        [$cb, $sb] = $this->comando('credential-flow:base-cambiar-plantilla', ['base' => $lote->id, 'plantilla' => $b->id, '--usuario' => $this->superAdmin()->id, '--motivo' => self::MOTIVO, '--plan' => $this->huella($simB), '--ejecutar' => true]);

        $this->assertSame([0, 1], [$ca, $cb]);
        $this->assertStringContainsString('PLAN_DESACTUALIZADO', $sb, 'el segundo simuló sobre la plantilla anterior');
        $this->assertSame($a->id, $lote->fresh()->plantilla_id);

        // Mientras una corrección de la base está en curso (cerrojo tomado), la otra ni empieza.
        $cerrojo = Cache::lock('credential-flow:correccion-base:'.$lote->id, 60);
        $this->assertTrue($cerrojo->get());
        [, $simB2] = $this->comando('credential-flow:base-cambiar-plantilla', ['base' => $lote->id, 'plantilla' => $b->id]);
        [$c, $s] = $this->comando('credential-flow:base-cambiar-plantilla', ['base' => $lote->id, 'plantilla' => $b->id, '--usuario' => $this->admin()->id, '--motivo' => self::MOTIVO, '--plan' => $this->huella($simB2), '--ejecutar' => true]);
        $this->assertSame(1, $c);
        $this->assertStringContainsString('OPERACION_EN_CURSO', $s);
        $cerrojo->release();
        $this->assertSame($a->id, $lote->fresh()->plantilla_id);
    }

    // ── Reemisión masiva ──────────────────────────────────────────────────────

    public function test_reemite_oficiales_y_qa_conserva_los_pdf_anteriores_reemplaza_la_vigente_y_el_portal_solo_ve_la_nueva(): void
    {
        $lote = $this->baseEmitida(3);
        $nueva = $this->plantillaCorregida();
        $antes = Emision::query()->where('lote_id', $lote->id)->orderBy('id')->get();
        $hashes = $antes->mapWithKeys(fn (Emision $e) => [$e->pdf_archivo => hash('sha256', Storage::disk('local')->get($e->pdf_archivo))])->all();
        $this->assertSame(11, $antes->count());
        $this->cambiar($lote, $nueva);

        [, $sim] = $this->comando('credential-flow:base-reemitir', ['base' => $lote->id]);
        $this->assertMatchesRegularExpression('/OFICIALES .*vigentes 3 · desactualizados 3 \(plantilla 3, diseno 3, pdf_base 0, datos 0\) · a reemitir ahora 3/', $sim);
        $this->assertMatchesRegularExpression('/QA .*vigentes 8 · desactualizados 8 .*a reemitir ahora 8/', $sim);
        $this->assertSame(11, Emision::query()->count(), 'simular no emite');

        [$codigo, $salida] = $this->reemitir($lote);

        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('Grupo oficiales: 3 certificado(s) reemitido(s)', $salida);
        $this->assertStringContainsString('Grupo qa: 8 certificado(s) reemitido(s)', $salida);
        $this->assertStringContainsString('No se envió ningún correo', $salida);
        $variante = Plantilla::query()->findOrFail($nueva->variante_qa_id);
        foreach ($antes as $anterior) {
            $anterior->refresh();
            $nuevaE = Emision::query()->where('reemplaza_id', $anterior->id)->sole();
            $qa = (bool) $anterior->participante->es_control_qa;
            $this->assertSame([Emision::REVOCADA, null, 'Reemplazada por la versión 2. Motivo: '.self::MOTIVO, $this->admin()->id],
                [$anterior->estado, $anterior->participante_vigente, $anterior->motivo_revocacion, (int) $anterior->revocado_por]);
            $this->assertSame([Emision::EMITIDA, 2, $anterior->participante_id, $qa ? $variante->id : $nueva->id, $this->admin()->id],
                [$nuevaE->estado, $nuevaE->version, (int) $nuevaE->participante_vigente, $nuevaE->plantilla_id, (int) $nuevaE->emitido_por]);
            $this->assertNotSame($anterior->codigo, $nuevaE->codigo);
            $this->assertContains(self::FIJO_NUEVO, $this->textos($nuevaE));
            $this->assertSame($qa, in_array('PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA', $this->textos($nuevaE), true), 'QA solo con su variante');
        }
        // Los PDF anteriores siguen en su sitio y byte a byte iguales.
        foreach ($hashes as $ruta => $hash) {
            $this->assertSame($hash, hash('sha256', Storage::disk('local')->get($ruta)));
        }
        $this->assertSame(0, Artisan::call('credential-flow:verificar-emisiones'), Artisan::output());

        // Portal: una sola tarjeta por participante, la versión vigente.
        $p = $lote->participantes()->where('es_control_qa', false)->orderBy('id')->firstOrFail();
        $filas = EmisionesModernas::filas($p->documento_clave);
        $this->assertCount(1, $filas);
        $this->assertSame([Emision::EMITIDA, $p->emisionVigente->id], [$filas[0]->estado, $filas[0]->emision_id]);

        $movs = Movimiento::query()->where('descripcion', 'like', '%reemisión masiva controlada%')->orderBy('id')->get();
        $this->assertSame(['oficiales', 'qa'], $movs->pluck('metadata.grupo')->all());
        $this->assertSame([3, 8], $movs->pluck('metadata.total')->all());
        $this->assertSame([$this->admin()->id, $this->admin()->id], $movs->pluck('user_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_repetir_la_reemision_no_duplica_versiones(): void
    {
        $lote = $this->baseEmitida(2);
        $this->cambiar($lote, $this->plantillaCorregida());
        $this->assertSame(0, $this->reemitir($lote)[0]);
        $foto = $this->foto();

        [$codigo, $sim] = $this->comando('credential-flow:base-reemitir', ['base' => $lote->id]);
        $this->assertSame(0, $codigo);
        $this->assertMatchesRegularExpression('/OFICIALES .*desactualizados 0/', $sim);
        $this->assertMatchesRegularExpression('/QA .*desactualizados 0/', $sim);
        [$codigo, $salida] = $this->reemitir($lote);

        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('Nada que reemitir', $salida);
        $this->assertSame($foto, $this->foto());
        $this->assertSame(10, Emision::query()->where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)->count());
        $this->assertSame(2, (int) Emision::query()->max('version'));
    }

    public function test_un_dato_impreso_corregido_en_la_base_tambien_se_detecta_pero_el_nombre_administrativo_no(): void
    {
        $lote = $this->baseEmitida(2);
        $lote->update(['nombre' => 'Nombre interno corregido']);
        $plan = app(ReemisorBase::class)->planear($lote->fresh(), ReemisorBase::OFICIALES);
        $this->assertSame(0, $plan['desactualizadas'], 'el nombre interno de la base no se imprime');

        // Como la base #17 de producción: se corrige el nombre del evento sin cambiar de plantilla.
        $lote->update(['datos_comunes' => ['evento' => 'CONGRESO DE FINANZAS PÚBLICAS'] + $lote->datos_comunes]);
        $plan = app(ReemisorBase::class)->planear($lote->fresh(), ReemisorBase::OFICIALES);
        $this->assertSame([2, ['plantilla' => 0, 'diseno' => 0, 'pdf_base' => 0, 'datos' => 2]], [$plan['desactualizadas'], $plan['motivos']]);
    }

    public function test_falla_al_generar_un_pdf_no_reemite_ni_revoca_nada(): void
    {
        $lote = $this->baseEmitida(3);
        $this->cambiar($lote, $this->plantillaCorregida());
        $foto = $this->foto();
        $n = 0;
        AlmacenEmisiones::$antesDeEscribir = function () use (&$n) {
            if (++$n === 2) {
                throw new \RuntimeException('disco lleno simulado');
            }
        };

        [$codigo, $salida] = $this->reemitir($lote);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('ERROR_ESCRITURA', $salida);
        $this->assertSame($foto, $this->foto(), 'ningún certificado cambió y no quedó ningún archivo (ni staging)');
    }

    public function test_falla_en_la_fase_critica_devuelve_todo_y_los_pdf_anteriores_siguen_vigentes(): void
    {
        $lote = $this->baseEmitida(3);
        $this->cambiar($lote, $this->plantillaCorregida());
        $foto = $this->foto();
        $creadas = 0;
        Emision::creating(function () use (&$creadas) {
            if (++$creadas === 3) {
                throw new \RuntimeException('fallo simulado en la inserción');
            }
        });

        [$codigo] = $this->reemitir($lote, ['--grupo' => 'oficiales']);

        $this->assertSame(1, $codigo);
        $this->assertSame($foto, $this->foto(), 'rollback completo: nada revocado, ningún archivo nuevo');
    }

    public function test_si_falla_qa_despues_de_reemitir_los_oficiales_lo_informa_y_reintentar_completa_sin_duplicar(): void
    {
        $lote = $this->baseEmitida(3);
        $this->cambiar($lote, $this->plantillaCorregida());
        // Los 3 oficiales se escriben bien; el segundo PDF de QA falla.
        $n = 0;
        AlmacenEmisiones::$antesDeEscribir = function () use (&$n) {
            if (++$n === 5) {
                throw new \RuntimeException('fallo simulado en QA');
            }
        };

        [$codigo, $salida] = $this->reemitir($lote);

        $this->assertSame(2, $codigo, 'código de salida propio del resultado parcial');
        $this->assertStringContainsString('ESTADO FINAL: PARCIAL — oficiales: REEMITIDOS 3 de 3 · qa: SIN CAMBIOS (0 de 8; error ERROR_ESCRITURA)', $salida);
        $this->assertStringNotContainsString('ESTADO FINAL: COMPLETO', $salida);
        $this->assertCount(3, $this->vigentes($lote, false)->where('version', 2));
        $this->assertCount(8, $this->vigentes($lote, true)->where('version', 1), 'los QA quedaron intactos');

        AlmacenEmisiones::$antesDeEscribir = null;
        [, $sim] = $this->comando('credential-flow:base-reemitir', ['base' => $lote->id]);
        $this->assertMatchesRegularExpression('/OFICIALES .*desactualizados 0/', $sim);
        $this->assertMatchesRegularExpression('/QA .*desactualizados 8/', $sim);
        $this->assertSame(0, $this->reemitir($lote)[0]);
        $this->assertSame(22, Emision::query()->where('lote_id', $lote->id)->count(), '11 originales + 11 nuevas: ninguna duplicada');
        $this->assertSame(11, Emision::query()->where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)->where('version', 2)->count());
        $this->assertSame(0, Artisan::call('credential-flow:verificar-emisiones'), Artisan::output());
    }

    public function test_otro_administrador_reemite_uno_entre_la_simulacion_y_la_ejecucion(): void
    {
        $lote = $this->baseEmitida(3);
        $this->cambiar($lote, $this->plantillaCorregida());
        [, $sim] = $this->comando('credential-flow:base-reemitir', ['base' => $lote->id, '--grupo' => 'oficiales']);
        app(EmisorCredencial::class)->reemitir($this->vigentes($lote, false)->first(), 'Reemisión individual de otra persona', $this->superAdmin()->id);
        $foto = $this->foto();

        [$codigo, $salida] = $this->comando('credential-flow:base-reemitir', ['base' => $lote->id, '--grupo' => 'oficiales', '--usuario' => $this->admin()->id,
            '--motivo' => self::MOTIVO, '--plan' => $this->huella($sim), '--ejecutar' => true]);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('PLAN_DESACTUALIZADO', $salida);
        $this->assertSame($foto, $this->foto());
    }

    public function test_un_cambio_durante_la_generacion_de_los_pdf_cancela_toda_la_reemision(): void
    {
        $lote = $this->baseEmitida(3);
        $this->cambiar($lote, $this->plantillaCorregida());
        $objetivo = $this->vigentes($lote, false)->last();
        // Mientras se generan los PDF (sin transacción abierta), otra persona revoca uno de los certificados del plan.
        $n = 0;
        AlmacenEmisiones::$antesDeEscribir = function () use (&$n, $objetivo) {
            if (++$n === 1) {
                app(EmisorCredencial::class)->revocar($objetivo, 'Revocada mientras tanto', $this->superAdmin()->id);
            }
        };

        [$codigo, $salida] = $this->reemitir($lote, ['--grupo' => 'oficiales']);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('DATOS_CAMBIARON_DURANTE_EMISION', $salida);
        $this->assertSame(0, Emision::query()->where('version', 2)->count(), 'no salió ninguna versión nueva');
        $this->assertSame(2, $this->vigentes($lote, false)->count(), 'solo la revocación de la otra persona');
        $this->assertSame(0, Artisan::call('credential-flow:verificar-emisiones'), Artisan::output());
    }

    public function test_una_emision_programada_activa_bloquea_el_cambio_y_la_reemision(): void
    {
        $lote = $this->baseEmitida(2);
        $nueva = $this->plantillaCorregida();
        DB::table('cf_emisiones_programadas')->insert(['lote_id' => $lote->id, 'lote_activa' => $lote->id, 'estado' => 'pendiente', 'programada_para' => now()->addDay(),
            'huella' => str_repeat('a', 64), 'huella_detalle' => '{}', 'programada_por' => $this->admin()->id, 'created_at' => now(), 'updated_at' => now()]);
        $foto = $this->foto();

        [$c, $s] = $this->comando('credential-flow:base-cambiar-plantilla', ['base' => $lote->id, 'plantilla' => $nueva->id]);
        $this->assertSame(1, $c);
        $this->assertStringContainsString('emisión programada activa', $s);

        $lote->update(['datos_comunes' => ['evento' => 'EVENTO CORREGIDO'] + $lote->datos_comunes]);
        $foto = $this->foto();
        [$c, $s] = $this->comando('credential-flow:base-reemitir', ['base' => $lote->id]);
        $this->assertSame(1, $c);
        $this->assertStringContainsString('EMISION_PROGRAMADA_PENDIENTE', $s);
        [$c] = $this->comando('credential-flow:base-reemitir', ['base' => $lote->id, '--usuario' => $this->admin()->id, '--motivo' => self::MOTIVO, '--plan' => $this->huella($s), '--ejecutar' => true]);
        $this->assertSame(1, $c);
        $this->assertSame($foto, $this->foto());
    }

    public function test_reemitir_exige_simulacion_administrador_y_motivo(): void
    {
        $lote = $this->baseEmitida(1);
        $this->cambiar($lote, $this->plantillaCorregida());
        [, $sim] = $this->comando('credential-flow:base-reemitir', ['base' => $lote->id]);
        $plan = $this->huella($sim);
        $foto = $this->foto();
        foreach ([
            [['--usuario' => $this->admin()->id, '--motivo' => self::MOTIVO], 'La simulación es obligatoria'],
            [['--usuario' => $this->comercial()->id, '--motivo' => self::MOTIVO, '--plan' => $plan], 'NO_AUTORIZADO'],
            [['--usuario' => $this->admin()->id, '--motivo' => 'corto', '--plan' => $plan], 'MOTIVO_INVALIDO'],
            [['--usuario' => $this->admin()->id, '--motivo' => self::MOTIVO, '--plan' => $plan, '--grupo' => 'qa'], 'PLAN_DESACTUALIZADO'],
        ] as [$args, $texto]) {
            [$c, $s] = $this->comando('credential-flow:base-reemitir', ['base' => $lote->id, '--ejecutar' => true] + $args);
            $this->assertSame(1, $c, $s);
            $this->assertStringContainsString($texto, $s);
        }
        $this->assertSame($foto, $this->foto());
    }

    public function test_un_pdf_anterior_danado_impide_reemitir_para_no_perder_evidencia(): void
    {
        $lote = $this->baseEmitida(2);
        $this->cambiar($lote, $this->plantillaCorregida());
        $danada = $this->vigentes($lote, false)->first();
        Storage::disk('local')->put($danada->pdf_archivo, 'alterado');

        $plan = app(ReemisorBase::class)->planear($lote->fresh(), ReemisorBase::OFICIALES);

        $this->assertSame('INTEGRIDAD_EMISION_INVALIDA', $plan['bloqueo']['code']);
    }

    public function test_recorrido_real_completo_de_punta_a_punta_con_vista_previa_exigida(): void
    {
        // 1. Base con certificados ya emitidos (3 oficiales + 8 QA, versión 1).
        $lote = $this->baseEmitida(3);
        $originales = Emision::query()->where('lote_id', $lote->id)->orderBy('id')->get();
        $pdf = $originales->mapWithKeys(fn (Emision $e) => [$e->pdf_archivo => hash('sha256', Storage::disk('local')->get($e->pdf_archivo))])->all();
        $nueva = $this->plantillaCorregida();
        config(['credential_flow.emision.exige_vista_previa' => true]);

        // 2. Cambio de plantilla.
        $this->assertSame(0, $this->cambiar($lote, $nueva)[0]);

        // 3. Vista previa invalidada: los oficiales no se pueden reemitir (los QA no dependen de ella); el plan lo dice y la ejecución no pasa.
        [$c, $sim] = $this->comando('credential-flow:base-reemitir', ['base' => $lote->id]);
        $this->assertSame(1, $c);
        $this->assertStringContainsString('Bloqueo VISTA_PREVIA_PENDIENTE', $sim);
        [$c, $s] = $this->comando('credential-flow:base-reemitir', ['base' => $lote->id, '--usuario' => $this->admin()->id, '--motivo' => self::MOTIVO, '--plan' => $this->huella($sim), '--ejecutar' => true]);
        $this->assertSame([1, 11], [$c, Emision::query()->count()], $s);
        $this->assertStringContainsString('ESTADO FINAL: SIN CAMBIOS', $s);

        // 4. Confirmación de la nueva vista previa (como en la pantalla de la base).
        $this->confirmarVistaPrevia($lote);

        // 5. Simulación: no escribe nada.
        $foto = $this->foto();
        [$c, $sim] = $this->comando('credential-flow:base-reemitir', ['base' => $lote->id]);
        $this->assertSame(0, $c, $sim);
        $this->assertMatchesRegularExpression('/OFICIALES .*desactualizados 3 .*a reemitir ahora 3/', $sim);
        $this->assertMatchesRegularExpression('/QA .*desactualizados 8 .*a reemitir ahora 8/', $sim);
        $this->assertSame($foto, $this->foto(), 'la simulación no escribe');

        // 6. Reemisión masiva.
        [$c, $salida] = $this->comando('credential-flow:base-reemitir', ['base' => $lote->id, '--usuario' => $this->admin()->id, '--motivo' => self::MOTIVO, '--plan' => $this->huella($sim), '--ejecutar' => true]);
        $this->assertSame(0, $c, $salida);
        $this->assertStringContainsString('ESTADO FINAL: COMPLETO — oficiales: REEMITIDOS 3 de 3 · qa: REEMITIDOS 8 de 8.', $salida);

        // 7. Versiones anteriores (reemplazadas, con su PDF intacto) y nuevas (vigentes, versión 2, código propio).
        foreach ($originales as $o) {
            $o->refresh();
            $n = Emision::query()->where('reemplaza_id', $o->id)->sole();
            $this->assertSame([Emision::REVOCADA, 'Reemplazada por la versión 2. Motivo: '.self::MOTIVO], [$o->estado, $o->motivo_revocacion]);
            $this->assertSame([Emision::EMITIDA, 2, $o->participante_id], [$n->estado, $n->version, $n->participante_id]);
        }
        $this->assertSame(22, Emision::query()->distinct()->count('codigo'), 'cada versión con su propio código');
        foreach ($pdf as $ruta => $hash) {
            $this->assertSame($hash, hash('sha256', Storage::disk('local')->get($ruta)), 'PDF original intacto');
        }

        // 8. Segunda ejecución: nada que reemitir, nada cambia.
        $foto = $this->foto();
        [$c, $sim] = $this->comando('credential-flow:base-reemitir', ['base' => $lote->id]);
        [$c2, $s2] = $this->comando('credential-flow:base-reemitir', ['base' => $lote->id, '--usuario' => $this->admin()->id, '--motivo' => self::MOTIVO, '--plan' => $this->huella($sim), '--ejecutar' => true]);
        $this->assertSame([0, 0], [$c, $c2]);
        $this->assertStringContainsString('ESTADO FINAL: SIN CAMBIOS — todo está al día.', $s2);
        $this->assertSame($foto, $this->foto());

        // 9. Portal: una sola tarjeta por persona y es la versión vigente (oficiales y QA).
        foreach ($lote->participantes()->get() as $p) {
            $filas = EmisionesModernas::filas($p->documento_clave)->where('participante_id', $p->id)->values();
            $this->assertCount(1, $filas);
            $this->assertSame([Emision::EMITIDA, $p->emisionVigente->id, 2], [$filas[0]->estado, $filas[0]->emision_id, $p->emisionVigente->version]);
        }
        $this->assertSame(0, Artisan::call('credential-flow:verificar-emisiones'), Artisan::output());
    }

    public function test_interrumpido_entre_el_cambio_y_la_reemision_es_recuperable_continuando_o_revirtiendo(): void
    {
        $lote = $this->baseEmitida(2);
        [$emisiones, $archivos] = $this->foto();
        $nueva = $this->plantillaCorregida();
        config(['credential_flow.emision.exige_vista_previa' => true]);
        $this->assertSame(0, $this->cambiar($lote, $nueva)[0]);

        // «Se cortó» aquí: los certificados siguen exactamente como estaban y vigentes (el portal sigue entregando los mismos PDF); la base ya usa la plantilla nueva.
        [$e2, $a2] = $this->foto();
        $this->assertSame([$emisiones, $archivos], [$e2, $a2]);
        $this->assertSame($nueva->id, $lote->fresh()->plantilla_id);
        // Repetir el cambio no hace nada (ya está hecho) y la simulación de la reemisión muestra exactamente lo pendiente.
        [$c, $s] = $this->comando('credential-flow:base-cambiar-plantilla', ['base' => $lote->id, 'plantilla' => $nueva->id]);
        $this->assertSame(1, $c);
        $this->assertStringContainsString('La base ya usa esa plantilla', $s);
        $this->assertSame(10, array_sum(array_map(fn ($g) => app(ReemisorBase::class)->planear($lote->fresh(), $g)['desactualizadas'], [ReemisorBase::OFICIALES, ReemisorBase::QA])));

        // Opción «revertir»: volver a la plantilla anterior con el mismo comando. Todo queda como antes: sin nada desactualizado y otra vez exenta de vista previa.
        $this->assertSame(0, $this->cambiar($lote->fresh(), $this->institucional)[0]);
        $lote = $lote->fresh();
        $this->assertSame($this->institucional->id, $lote->plantilla_id);
        $this->assertTrue(app(ServicioVistaPrevia::class)->exentaPorEmisiones($lote));
        foreach ([ReemisorBase::OFICIALES, ReemisorBase::QA] as $g) {
            $this->assertSame(0, app(ReemisorBase::class)->planear($lote, $g)['desactualizadas']);
        }
        [$e3, $a3] = $this->foto();
        $this->assertSame([$emisiones, $archivos], [$e3, $a3], 'ningún certificado cambió en todo el recorrido');
        $this->assertSame(2, Movimiento::query()->where('descripcion', 'like', 'Se cambió la plantilla de una base con certificados emitidos%')->count(), 'ida y vuelta auditadas');
    }

    public function test_las_otras_bases_que_comparten_la_plantilla_no_cambian(): void
    {
        $corregir = $this->baseEmitida(2);
        $otra = $this->baseEmitida(2);   // misma plantilla institucional (como la #15 y la #16 de producción)
        $otraAntes = [Emision::query()->where('lote_id', $otra->id)->orderBy('id')->get()->map->toArray()->all(), $otra->fresh()->toArray()];
        $institucionalAntes = $this->institucional->fresh()->toArray();

        $this->cambiar($corregir, $this->plantillaCorregida());
        $this->assertSame(0, $this->reemitir($corregir)[0]);

        $this->assertSame($otraAntes, [Emision::query()->where('lote_id', $otra->id)->orderBy('id')->get()->map->toArray()->all(), $otra->fresh()->toArray()]);
        $this->assertSame($institucionalAntes, $this->institucional->fresh()->toArray(), 'la plantilla compartida no se tocó');
        $this->assertTrue(app(ServicioVistaPrevia::class)->exentaPorEmisiones($otra->fresh()), 'la otra base sigue como antes');
        foreach ([ReemisorBase::OFICIALES, ReemisorBase::QA] as $g) {
            $this->assertSame(0, app(ReemisorBase::class)->planear($otra->fresh(), $g)['desactualizadas']);
        }
    }
}
