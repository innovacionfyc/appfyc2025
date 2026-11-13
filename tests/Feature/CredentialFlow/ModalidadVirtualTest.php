<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Emisiones\ReemisorBase;
use App\Support\CredentialFlow\Generacion\DatosCredencial;
use App\Support\CredentialFlow\Generacion\PlanificadorTexto;
use App\Support\CredentialFlow\Participantes\DatosDeParticipante;
use App\Support\CredentialFlow\Participantes\ModalidadImpresa;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;

/**
 * Regla única de la MODALIDAD VIRTUAL: toda participación cuya modalidad efectiva es virtual imprime exactamente «Virtual -Sincrónico y Remoto», sin ciudad,
 * sin prefijo ni sufijo de la plantilla, en «modalidad» y en «modalidad_ciudad». Presenciales e híbridas no cambian. Los certificados ya emitidos conservan
 * su PDF y sus datos congelados; solo una reemisión aprobada (que puede limitarse a los virtuales) produce versiones nuevas. Datos 100 % ficticios.
 */
class ModalidadVirtualTest extends EmisionesTestCase
{
    private const MARCADOR = 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA';

    private const MOTIVO = 'Prueba sintética: redacción oficial de la modalidad virtual';

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
            'database/migrations/2026_11_10_100000_cf_eventos_doble_modalidad_table.php',
        ], '--force' => true])->assertExitCode(0);
        config(['credential_flow.qa.contactos_esperados' => 2]);
        for ($i = 1; $i <= 2; $i++) {
            $doc = (string) (72000000 + $i);
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => "QA VIRTUAL {$i}", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc),
                'correo' => "qa.virtual{$i}@example.test", 'correo_normalizado' => "qa.virtual{$i}@example.test", 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    /** Diseño como los de producción; la línea de modalidad con el campo y el sufijo indicados (las #23–#26 llevan el sufijo fijo «, Bogotá»). */
    private function plantilla(string $campo = 'modalidad', string $sufijo = '', string $prefijo = ''): Plantilla
    {
        $el = fn (array $c) => $this->elemento($c + ['x' => 86, 'width' => 620, 'fontSize' => 14, 'height' => 20]);
        $modalidad = array_filter(['field' => $campo, 'y' => 346, 'suffix' => $sufijo, 'prefix' => $prefijo], fn ($v) => $v !== '');
        $p = $this->plantillaLista([
            $el(['field' => 'nombre_completo', 'y' => 205, 'fontSize' => 22, 'height' => 28]),
            $el(['field' => 'documento', 'y' => 232, 'fontSize' => 18, 'height' => 24]),
            $el(['field' => null, 'text' => 'por su asistencia al', 'y' => 262]),
            $el(['field' => 'evento', 'y' => 282, 'height' => 40, 'multiline' => true]),
            $el(['field' => 'fecha', 'y' => 326, 'prefix' => 'realizado los días ']),
            $el($modalidad),
            $el(['field' => 'intensidad_horaria', 'y' => 368, 'prefix' => 'con una intensidad académica de: ', 'suffix' => '.']),
        ]);
        $p->update(['schema_version' => DisenoSchema::versionPara($p->diseno)]);
        $this->artisan('credential-flow:plantilla-variante-qa', ['origen' => $p->id, '--ejecutar' => true])->assertExitCode(0);

        return $p->fresh();
    }

    /** @param array<string,int> $modalidades  modalidad propia => cuántas personas ('' = usa la general de la base) */
    private function base(Plantilla $p, array $comunes, array $modalidades, bool $conQa = true, ?EventoCertificacion $evento = null): Lote
    {
        $lote = $this->loteCon(0, $p);
        $lote->update(['evento_id' => $evento?->id, 'datos_comunes' => ['evento' => 'SEMINARIO VIRTUAL DE PRUEBA', 'fecha' => '5 y 6 de noviembre de 2026', 'intensidad_horaria' => '18 horas'] + $comunes]);
        $i = 0;
        foreach ($modalidades as $mod => $n) {
            for ($k = 0; $k < $n; $k++) {
                $i++;
                $this->participante($lote, "Persona Virtual {$lote->id} {$i}", 'C.C. '.(60_000_000 + $lote->id * 100 + $i))->update(['modalidad' => $mod === '' ? null : $mod]);
            }
        }
        if ($conQa) {
            app(ServicioQa::class)->incorporar($lote->fresh());
        }

        return $lote->fresh();
    }

    private function textos(Emision $e): array
    {
        return array_column((new InspectorPdf(Storage::disk('local')->get($e->pdf_archivo)))->textos(), 'texto');
    }

    private function emisiones(Lote $lote, bool $qa, string $estado = Emision::EMITIDA): Collection
    {
        return Emision::query()->where('lote_id', $lote->id)->where('estado', $estado)
            ->whereIn('participante_id', $lote->participantes()->withTrashed()->where('es_control_qa', $qa)->select('id'))->orderBy('id')->get();
    }

    private function modalidadDe(Emision $e): ?string
    {
        return $e->participante()->withTrashed()->first()?->modalidad;
    }

    /**
     * Deja las emisiones VIRTUALES como las de producción emitidas antes de la regla: datos congelados con el texto que se escribió («Virtual») y la composición
     * anterior en modalidad_ciudad. Solo toca el registro (no el PDF ni el diseño), igual que una emisión real anterior.
     */
    private function comoAntes(Lote $lote, string $texto = 'Virtual', ?string $ciudad = 'Bogotá'): void
    {
        foreach (Emision::query()->where('lote_id', $lote->id)->get() as $e) {
            $d = (array) $e->datos_snapshot;
            if (($d['modalidad'] ?? null) === ModalidadImpresa::VIRTUAL) {
                $d['modalidad'] = $texto;
                if (array_key_exists('modalidad_ciudad', $d)) {
                    $d['modalidad_ciudad'] = $ciudad === null ? $texto : "{$texto}, {$ciudad}";
                }
                DB::table('cf_emisiones')->where('id', $e->id)->update(['datos_snapshot' => json_encode($d)]);
            }
        }
    }

    private function simulacion(Lote $lote, array $extra = []): array
    {
        Artisan::call('credential-flow:base-reemitir', ['base' => $lote->id] + $extra);
        $salida = Artisan::output();
        preg_match('/Huella del plan: ([0-9a-f]{64})/', $salida, $m);

        return [$m[1] ?? null, $salida];
    }

    // ── Clasificación ─────────────────────────────────────────────────────────

    public static function grafias(): array
    {
        return [
            'Virtual' => ['Virtual', true],
            'virtual minúscula' => ['virtual', true],
            'VIRTUAL' => ['VIRTUAL', true],
            'espacios' => ['  Virtual  ', true],
            'con ciudad' => ['Virtual, Bogotá', true],
            'con ciudad y guion' => ['Virtual - bogota dc', true],
            'sincrónico' => ['Virtual sincrónico', true],
            'oficial exacta' => ['Virtual -Sincrónico y Remoto', true],
            'sin espacios' => ['Virtual-Sincrónico y Remoto', true],
            'espacios a ambos lados' => ['Virtual - Sincrónico y Remoto', true],
            'entre paréntesis' => ['Virtual (sincrónica)', true],
            'Presencial' => ['Presencial', false],
            'Presencial con ciudad' => ['Presencial, Bogotá', false],
            'Híbrido' => ['Híbrido', false],
            'Híbrido con ciudad' => ['Híbrido, Bogotá', false],
            'Hibrido sin tilde' => ['hibrido', false],
            'Presencial y virtual' => ['Presencial y virtual', false],
            'Virtual y presencial' => ['Virtual y presencial', false],
            'Semipresencial' => ['Semipresencial', false],
            'Virtualidad (otra palabra)' => ['Virtualidad', false],
            'Remoto solo' => ['Remoto', false],
            'vacía' => ['', false],
            'nula' => [null, false],
        ];
    }

    #[DataProvider('grafias')]
    public function test_clasifica_las_grafias_de_la_modalidad(?string $modalidad, bool $virtual): void
    {
        $this->assertSame($virtual, ModalidadImpresa::esVirtual($modalidad));
    }

    public function test_el_texto_oficial_es_exacto(): void
    {
        $this->assertSame('Virtual -Sincrónico y Remoto', ModalidadImpresa::VIRTUAL);
        $this->assertSame('5669727475616c202d53696e6372c3b36e69636f20792052656d6f746f', bin2hex(ModalidadImpresa::VIRTUAL), 'espacio antes del guion, ninguno después, ó compuesta');
    }

    // ── Datos del certificado ─────────────────────────────────────────────────

    public function test_los_datos_de_una_participacion_virtual_usan_el_texto_oficial_sin_ciudad(): void
    {
        $lote = $this->base($this->plantilla('modalidad_ciudad'), ['modalidad' => 'Híbrido', 'ciudad' => 'Bogotá'],
            ['Virtual' => 1, 'Virtual, Bogotá' => 1, 'Virtual-Sincrónico y Remoto' => 1, 'Presencial' => 1, 'Presencial, Cali' => 1, '' => 1], false);
        $por = $lote->participantes()->orderBy('id')->get()->map(fn ($p) => array_intersect_key(DatosDeParticipante::para($p, $lote)->todos(), ['modalidad' => 1, 'modalidad_ciudad' => 1]))->all();

        $v = ['modalidad' => ModalidadImpresa::VIRTUAL, 'modalidad_ciudad' => ModalidadImpresa::VIRTUAL];
        $this->assertSame([$v, $v, $v,
            ['modalidad' => 'Presencial', 'modalidad_ciudad' => 'Presencial, Bogotá'],
            ['modalidad' => 'Presencial, Cali', 'modalidad_ciudad' => 'Presencial, Cali, Bogotá'],
            ['modalidad' => 'Híbrido', 'modalidad_ciudad' => 'Híbrido, Bogotá']], $por, 'la general «Híbrido» no es virtual; las presenciales se componen como antes');
    }

    public function test_la_modalidad_general_virtual_tambien_aplica(): void
    {
        $lote = $this->base($this->plantilla(), ['modalidad' => 'Virtual, Bogotá'], ['' => 1, 'Presencial' => 1], false);
        [$a, $b] = $lote->participantes()->orderBy('id')->get()->all();
        $this->assertSame(ModalidadImpresa::VIRTUAL, DatosDeParticipante::para($a, $lote)->todos()['modalidad']);
        $this->assertSame('Presencial', DatosDeParticipante::para($b, $lote)->todos()['modalidad'], 'la propia presencial manda');
    }

    // ── PDF ───────────────────────────────────────────────────────────────────

    public static function disenos(): array
    {
        return [
            'modalidad sin sufijo (#19–#22)' => ['modalidad', '', 'Presencial'],
            'modalidad con sufijo fijo «, Bogotá» (#23–#26)' => ['modalidad', ', Bogotá', 'Presencial, Bogotá'],
            'modalidad_ciudad (#27–#30)' => ['modalidad_ciudad', '', 'Presencial, Bogotá'],
        ];
    }

    #[DataProvider('disenos')]
    public function test_oficiales_y_qa_imprimen_el_texto_oficial_y_las_presenciales_no_cambian(string $campo, string $sufijo, string $presencial): void
    {
        $lote = $this->base($this->plantilla($campo, $sufijo), ['modalidad' => 'Híbrido', 'ciudad' => 'Bogotá'], ['Presencial' => 2, 'Virtual' => 1, 'Virtual, Bogotá' => 1]);
        $r = app(EmisorLote::class)->emitirTodo($lote, $this->admin()->id);
        $this->assertSame([4, 6], [$r['oficiales']['total'] ?? null, $r['qa']['total'] ?? null], json_encode($r));

        foreach ([false, true] as $qa) {
            foreach ($this->emisiones($lote, $qa) as $e) {
                $t = $this->textos($e);
                $virtual = ModalidadImpresa::esVirtual($this->modalidadDe($e));
                $this->assertContains($virtual ? ModalidadImpresa::VIRTUAL : $presencial, $t, json_encode($t, JSON_UNESCAPED_UNICODE));
                $this->assertEmpty(array_filter($t, fn ($x) => str_contains($x, 'Virtual') && $x !== ModalidadImpresa::VIRTUAL), 'ningún otro texto con «Virtual» (ni ciudad, ni sufijo)');
                $this->assertContains('realizado los días 5 y 6 de noviembre de 2026', $t);
                $this->assertContains('con una intensidad académica de: 18 horas.', $t);
                $qa ? $this->assertContains(self::MARCADOR, $t) : $this->assertNotContains(self::MARCADOR, $t);
                $this->assertSame(hash('sha256', Storage::disk('local')->get($e->pdf_archivo)), $e->pdf_hash);
            }
        }
    }

    public function test_conserva_fuente_tamano_posicion_alineacion_y_color_del_elemento(): void
    {
        $p = $this->plantilla('modalidad', ', Bogotá', 'Modalidad: ');
        $plan = fn (string $m) => collect(PlanificadorTexto::planificar($p->diseno, DatosCredencial::fromArray(array_merge(DatosCredencial::qa()->todos(), ['modalidad' => $m])),
            ['width' => 792.0, 'height' => 612.0], (int) $p->schema_version))->first(fn ($x) => $x->id === $p->diseno['elements'][5]['id']);
        $v = $plan(ModalidadImpresa::VIRTUAL);
        $pr = $plan('Presencial');
        $this->assertSame([ModalidadImpresa::VIRTUAL], array_column($v->lineas, 'texto'), 'sin prefijo ni sufijo');
        $this->assertSame(['Modalidad: Presencial, Bogotá'], array_column($pr->lineas, 'texto'), 'presencial: prefijo y sufijo como siempre');
        foreach (['campo', 'fontFamily', 'fontWeight', 'fontSizeConfigurado', 'fontSizeEfectivo', 'x', 'y', 'width', 'height', 'align', 'color'] as $k) {
            $this->assertSame($pr->{$k}, $v->{$k}, "propiedad {$k}");
        }
        $this->assertEqualsWithDelta($pr->lineas[0]['baseline'], $v->lineas[0]['baseline'], 0.0001, 'misma línea base');
    }

    // ── Reproducción histórica ────────────────────────────────────────────────

    public function test_los_datos_congelados_anteriores_se_reproducen_igual(): void
    {
        $p = $this->plantilla('modalidad', ', Bogotá');
        $dibujar = fn (array $datos) => array_column(collect(PlanificadorTexto::planificar($p->diseno, DatosCredencial::fromArray(array_merge(DatosCredencial::qa()->todos(), $datos)),
            ['width' => 792.0, 'height' => 612.0], (int) $p->schema_version))->first(fn ($x) => $x->id === $p->diseno['elements'][5]['id'])->lineas, 'texto');
        // Un snapshot emitido ANTES de la regla («Virtual» congelado) se dibuja exactamente como entonces: el motor no reinterpreta datos anteriores.
        $this->assertSame(['Virtual, Bogotá'], $dibujar(['modalidad' => 'Virtual']));
        $this->assertSame(['Virtual-Sincrónico y Remoto, Bogotá'], $dibujar(['modalidad' => 'Virtual-Sincrónico y Remoto']));
        $this->assertSame(['Presencial, Bogotá'], $dibujar(['modalidad' => 'Presencial']));
        // Solo el texto oficial exacto (el que guarda una emisión hecha con la regla) se imprime sin afijos.
        $this->assertSame([ModalidadImpresa::VIRTUAL], $dibujar(['modalidad' => ModalidadImpresa::VIRTUAL]));
    }

    public function test_instalar_la_regla_no_cambia_ningun_certificado_emitido(): void
    {
        $lote = $this->base($this->plantilla('modalidad', ', Bogotá'), [], ['Presencial' => 1, 'Virtual' => 2]);
        app(EmisorLote::class)->emitirTodo($lote, $this->admin()->id);
        $this->comoAntes($lote);
        $antes = DB::table('cf_emisiones')->orderBy('id')->get()->map(fn ($e) => (array) $e)->all();
        $pdfs = Emision::all()->mapWithKeys(fn ($e) => [$e->id => Storage::disk('local')->get($e->pdf_archivo)])->all();

        // Planear (lo que haría el panel o la simulación) no escribe nada ni toca ningún PDF.
        foreach ([ReemisorBase::OFICIALES, ReemisorBase::QA] as $g) {
            app(ReemisorBase::class)->planear($lote->fresh(), $g);
            app(ReemisorBase::class)->planear($lote->fresh(), $g, ReemisorBase::SOLO_VIRTUALES);
        }
        $this->assertSame($antes, DB::table('cf_emisiones')->orderBy('id')->get()->map(fn ($e) => (array) $e)->all());
        $this->assertSame($pdfs, Emision::all()->mapWithKeys(fn ($e) => [$e->id => Storage::disk('local')->get($e->pdf_archivo)])->all());
    }

    // ── Reemisión selectiva ───────────────────────────────────────────────────

    public function test_la_reemision_solo_toma_los_virtuales_desactualizados_y_conserva_los_presenciales(): void
    {
        $lote = $this->base($this->plantilla('modalidad', ', Bogotá'), [], ['Presencial' => 2, 'Virtual' => 2]);
        app(EmisorLote::class)->emitirTodo($lote, $this->admin()->id);
        $this->comoAntes($lote);
        $presenciales = $this->emisiones($lote, false)->filter(fn ($e) => ! ModalidadImpresa::esVirtual($this->modalidadDe($e)))->mapWithKeys(fn ($e) => [$e->id => $e->pdf_hash])->all();
        $pdfAntes = Emision::all()->mapWithKeys(fn ($e) => [$e->id => Storage::disk('local')->get($e->pdf_archivo)])->all();

        $plan = app(ReemisorBase::class)->planear($lote->fresh(), ReemisorBase::OFICIALES, ReemisorBase::SOLO_VIRTUALES);
        $this->assertSame([2, 2, null], [$plan['desactualizadas'], $plan['a_reemitir'], $plan['bloqueo']]);
        $this->assertSame(['presenciales' => 0, 'ya_correctas' => 0], $plan['excluidas']);

        $r = app(ReemisorBase::class)->reemitir($lote->fresh(), ReemisorBase::OFICIALES, $plan['huella'], self::MOTIVO, $this->admin()->id, ReemisorBase::SOLO_VIRTUALES);
        $this->assertSame(2, $r['total']);
        foreach ($this->emisiones($lote, false) as $e) {
            if (isset($presenciales[$e->id])) {
                $this->assertSame([1, $presenciales[$e->id]], [$e->version, $e->pdf_hash], 'presencial intacta');
            } else {
                $this->assertSame(2, $e->version);
                $this->assertContains(ModalidadImpresa::VIRTUAL, $this->textos($e));
            }
        }
        $revocadas = $this->emisiones($lote, false, Emision::REVOCADA);
        $this->assertCount(2, $revocadas);
        foreach ($revocadas as $old) {
            $this->assertSame($pdfAntes[$old->id], Storage::disk('local')->get($old->pdf_archivo), 'la versión anterior conserva su PDF byte a byte');
            $this->assertSame(hash('sha256', $pdfAntes[$old->id]), $old->pdf_hash);
            $this->assertStringStartsWith('Reemplazada por la versión 2. Motivo: ', (string) $old->motivo_revocacion);
        }
        // Repetir no duplica nada.
        $otra = app(ReemisorBase::class)->planear($lote->fresh(), ReemisorBase::OFICIALES, ReemisorBase::SOLO_VIRTUALES);
        $this->assertSame([0, 0], [$otra['desactualizadas'], $otra['a_reemitir']]);
        try {
            app(ReemisorBase::class)->reemitir($lote->fresh(), ReemisorBase::OFICIALES, $otra['huella'], self::MOTIVO, $this->admin()->id, ReemisorBase::SOLO_VIRTUALES);
            $this->fail('no debía reemitir nada');
        } catch (EmisionException $e) {
            $this->assertSame(EmisionException::SIN_DESACTUALIZADAS, $e->codigo);
        }
    }

    public function test_qa_se_reemite_aparte_y_solo_sus_virtuales(): void
    {
        $lote = $this->base($this->plantilla('modalidad', ', Bogotá'), [], ['Presencial' => 1, 'Virtual' => 1]);
        app(EmisorLote::class)->emitirTodo($lote, $this->admin()->id);
        $this->comoAntes($lote);

        $of = app(ReemisorBase::class)->planear($lote->fresh(), ReemisorBase::OFICIALES, ReemisorBase::SOLO_VIRTUALES);
        $qa = app(ReemisorBase::class)->planear($lote->fresh(), ReemisorBase::QA, ReemisorBase::SOLO_VIRTUALES);
        $this->assertSame([1, 2], [$of['a_reemitir'], $qa['a_reemitir']], '1 oficial virtual; 2 contactos QA en la modalidad virtual');
        $r = app(ReemisorBase::class)->reemitir($lote->fresh(), ReemisorBase::QA, $qa['huella'], self::MOTIVO, $this->admin()->id, ReemisorBase::SOLO_VIRTUALES);
        $this->assertSame(2, $r['total']);
        foreach ($this->emisiones($lote, true)->filter(fn ($e) => $e->version === 2) as $e) {
            $this->assertContains(self::MARCADOR, $this->textos($e));
            $this->assertContains(ModalidadImpresa::VIRTUAL, $this->textos($e));
        }
        $this->assertSame(1, app(ReemisorBase::class)->planear($lote->fresh(), ReemisorBase::OFICIALES, ReemisorBase::SOLO_VIRTUALES)['a_reemitir'], 'los oficiales siguen pendientes: grupos separados');
    }

    public function test_con_cambio_de_plantilla_la_seleccion_excluye_presenciales_y_las_ya_correctas(): void
    {
        $lote = $this->base($this->plantilla('modalidad'), [], ['Presencial' => 2, 'Virtual' => 1, 'Virtual-Sincrónico y Remoto' => 1], false);
        app(EmisorLote::class)->emitirPendientes($lote, $this->admin()->id);
        // Una virtual queda como en producción («Virtual-Sincrónico y Remoto», grafía anterior); la otra ya imprime el texto oficial exacto (como la base #27).
        $virtuales = $this->emisiones($lote, false)->filter(fn ($e) => ModalidadImpresa::esVirtual($this->modalidadDe($e)))->values();
        $d = (array) $virtuales[0]->datos_snapshot;
        $d['modalidad'] = 'Virtual-Sincrónico y Remoto';
        DB::table('cf_emisiones')->where('id', $virtuales[0]->id)->update(['datos_snapshot' => json_encode($d)]);

        $sucesora = $this->plantilla('modalidad');
        Artisan::call('credential-flow:base-cambiar-plantilla', ['base' => $lote->id, 'plantilla' => $sucesora->id]);
        preg_match('/Huella del plan: ([0-9a-f]{64})/', Artisan::output(), $m);
        $this->assertSame(0, Artisan::call('credential-flow:base-cambiar-plantilla', ['base' => $lote->id, 'plantilla' => $sucesora->id, '--usuario' => $this->admin()->id,
            '--motivo' => 'Prueba: plantilla sucesora vigente', '--plan' => $m[1], '--ejecutar' => true]), Artisan::output());

        $todo = app(ReemisorBase::class)->planear($lote->fresh(), ReemisorBase::OFICIALES);
        $sel = app(ReemisorBase::class)->planear($lote->fresh(), ReemisorBase::OFICIALES, ReemisorBase::SOLO_VIRTUALES);
        $this->assertSame(4, $todo['desactualizadas'], 'sin selección: el cambio de plantilla desactualiza a todas');
        $this->assertSame([1, ['presenciales' => 2, 'ya_correctas' => 1]], [$sel['a_reemitir'], $sel['excluidas']]);
        $this->assertSame([$virtuales[0]->id], $sel['emisiones']);
        $this->assertNotSame($todo['huella'], $sel['huella'], 'la huella distingue la selección');
    }

    public function test_una_plantilla_archivada_sigue_bloqueando_la_reemision_selectiva(): void
    {
        $p = $this->plantilla('modalidad', ', Bogotá');
        $lote = $this->base($p, [], ['Virtual' => 1], false);
        app(EmisorLote::class)->emitirPendientes($lote, $this->admin()->id);
        $this->comoAntes($lote);
        DB::table('cf_plantillas')->where('id', $p->id)->update(['archivada_at' => now()]);

        $plan = app(ReemisorBase::class)->planear($lote->fresh(), ReemisorBase::OFICIALES, ReemisorBase::SOLO_VIRTUALES);
        $this->assertSame(EmisionException::PLANTILLA_ARCHIVADA, $plan['bloqueo']['code'] ?? null);
    }

    public function test_doble_modalidad_reemite_solo_la_participacion_virtual_de_la_misma_persona(): void
    {
        $evento = EventoCertificacion::create(['nombre' => 'Seminario doble sintético', 'nombre_normalizado' => NombreConservador::normalizar('Seminario doble sintético'),
            'anio' => 2026, 'fecha_texto' => '5 y 6 de noviembre de 2026', 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW]);
        DB::table('cf_eventos_doble_modalidad')->insert(['evento_id' => $evento->id, 'vigente_evento_id' => $evento->id, 'estado' => 'vigente', 'origen' => 'panel',
            'motivo' => 'Autorización sintética para la prueba de doble modalidad', 'autorizada_por' => $this->superAdmin()->id, 'created_at' => now(), 'updated_at' => now()]);
        $lote = $this->base($this->plantilla('modalidad'), [], ['Presencial' => 1], false, $evento);
        $persona = $lote->participantes()->firstOrFail();
        $this->participante($lote, $persona->nombre_completo, $persona->documento)->update(['modalidad' => 'Virtual']);
        app(EmisorLote::class)->emitirPendientes($lote->fresh(), $this->admin()->id);
        $this->assertSame(2, Emision::where('estado', Emision::EMITIDA)->count(), 'dos participaciones del mismo documento');
        $this->comoAntes($lote, 'Virtual', null);

        $plan = app(ReemisorBase::class)->planear($lote->fresh(), ReemisorBase::OFICIALES, ReemisorBase::SOLO_VIRTUALES);
        $this->assertSame(1, $plan['a_reemitir']);
        $virtual = Emision::query()->whereIn('id', $plan['emisiones'])->firstOrFail();
        $this->assertSame('Virtual', $this->modalidadDe($virtual));
        $r = app(ReemisorBase::class)->reemitir($lote->fresh(), ReemisorBase::OFICIALES, $plan['huella'], self::MOTIVO, $this->admin()->id, ReemisorBase::SOLO_VIRTUALES);
        $this->assertSame(1, $r['total']);
        $vig = $this->emisiones($lote, false)->keyBy(fn ($e) => $this->modalidadDe($e));
        $this->assertSame([1, 2], [$vig['Presencial']->version, $vig['Virtual']->version]);
        $this->assertContains('Presencial', $this->textos($vig['Presencial']));
        $this->assertContains(ModalidadImpresa::VIRTUAL, $this->textos($vig['Virtual']));
    }

    public function test_sin_seleccion_el_comportamiento_y_la_huella_de_siempre_no_cambian(): void
    {
        $lote = $this->base($this->plantilla('modalidad'), [], ['Presencial' => 1], false);
        app(EmisorLote::class)->emitirPendientes($lote, $this->admin()->id);
        $plan = app(ReemisorBase::class)->planear($lote->fresh(), ReemisorBase::OFICIALES);
        $this->assertArrayNotHasKey('excluidas', $plan);
        $this->assertSame([0, 0], [$plan['desactualizadas'], $plan['a_reemitir']]);
    }

    // ── Comando ───────────────────────────────────────────────────────────────

    public function test_el_comando_simula_y_ejecuta_solo_los_virtuales_con_su_propia_huella(): void
    {
        $lote = $this->base($this->plantilla('modalidad', ', Bogotá'), [], ['Presencial' => 1, 'Virtual' => 1]);
        app(EmisorLote::class)->emitirTodo($lote, $this->admin()->id);
        $this->comoAntes($lote);

        [$huellaTodo] = $this->simulacion($lote);
        [$huella, $salida] = $this->simulacion($lote, ['--solo-virtuales' => true]);
        $this->assertNotNull($huella);
        $this->assertNotSame($huellaTodo, $huella);
        $this->assertStringContainsString('SOLO VIRTUALES', $salida);
        $this->assertMatchesRegularExpression('/OFICIALES.*a reemitir ahora 1/', $salida);
        $this->assertMatchesRegularExpression('/QA.*a reemitir ahora 2/', $salida);

        // La huella de la simulación sin selección no sirve para ejecutar la selectiva.
        $this->assertSame(1, Artisan::call('credential-flow:base-reemitir', ['base' => $lote->id, '--solo-virtuales' => true, '--usuario' => $this->admin()->id,
            '--motivo' => self::MOTIVO, '--plan' => $huellaTodo, '--ejecutar' => true]));
        $this->assertSame(0, Artisan::call('credential-flow:base-reemitir', ['base' => $lote->id, '--solo-virtuales' => true, '--usuario' => $this->admin()->id,
            '--motivo' => self::MOTIVO, '--plan' => $huella, '--ejecutar' => true]), Artisan::output());
        $this->assertSame(3, Emision::where('version', 2)->count(), '1 oficial + 2 QA virtuales');
        $this->assertSame(0, Emision::where('version', 2)->get()->filter(fn ($e) => ! ModalidadImpresa::esVirtual($this->modalidadDe($e)))->count(), 'ninguna presencial');
    }
}
