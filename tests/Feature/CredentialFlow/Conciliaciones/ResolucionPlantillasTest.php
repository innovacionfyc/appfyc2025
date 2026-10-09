<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\EstadoDerivado;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\Conciliaciones\ResolucionPlantillas;
use App\Support\CredentialFlow\Conciliaciones\RollbackConciliaciones;
use App\Support\CredentialFlow\Legado\CongeladorLegado;
use App\Support\CredentialFlow\Legado\ElegibilidadLegado;
use App\Support\CredentialFlow\Legado\RendererLegado;
use App\Support\CredentialFlow\Legado\ResolutorPlantillaDirectorio;
use App\Support\CredentialFlow\Legado\ResolutorPlantillaStorage;
use App\Support\CredentialFlow\Legado\RutasLegado;
use App\Support\CredentialFlow\Migracion\RollbackNoPermitido;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/** Resolución administrativa de plantillas (Fase 10B-1): servicio, atomicidad, evidencia intacta, archivos y reversibilidad. */
class ResolucionPlantillasTest extends ConciliacionesTestCase
{
    private const MOTIVO = 'Decisión administrativa de prueba.';

    private string $tmp = '';

    private int $maxPlantilla = 0;

    private int $maxContenido = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->detectar();
        $this->maxPlantilla = (int) DB::table('cf_plantillas_legado')->max('id');
        $this->maxContenido = (int) DB::table('cf_plantillas_legado_contenidos')->max('id');
        $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'res_pl_'.bin2hex(random_bytes(5));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function servicio(): ResolucionPlantillas
    {
        return app(ResolucionPlantillas::class);
    }

    private function actor(): int
    {
        return $this->admin()->id;
    }

    private function casoId(string $tipo, ?int $oldCert = null): int
    {
        $id = $oldCert === null ? $this->caso($tipo)->id : DB::table('cf_conciliaciones_certificados')->where('certificado_legado_id', $this->idCert($oldCert))->join('cf_conciliaciones as c', 'c.id', '=', 'conciliacion_id')->where('c.tipo', $tipo)->value('c.id');

        return (int) $id;
    }

    private function cert(int $old): object
    {
        return DB::table('cf_certificados_legado')->where('id', $this->idCert($old))->first();
    }

    private function imagen(string $nombre, string $formato = 'png', int $ancho = 400, int $alto = 300, bool $entrelazado = false): string
    {
        $im = imagecreatetruecolor($ancho, $alto);
        imagefill($im, 0, 0, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        if ($entrelazado) {
            imageinterlace($im, true);
        }
        $ruta = $this->tmp.DIRECTORY_SEPARATOR.$nombre;
        $formato === 'jpg' ? imagejpeg($im, $ruta) : imagepng($im, $ruta);

        return $ruta;
    }

    /** Todo lo histórico PRE-EXISTENTE que no puede cambiar (el catálogo sin la marca de decisión, contenidos, eventos, correos, descargas, mapa y snapshots). Las entradas nuevas (aporte manual) son adiciones. */
    private function evidenciaIntacta(): string
    {
        $quitar = ['aprobada_por_conciliacion_id', 'update_by', 'updated_at'];

        return md5(json_encode([
            DB::table('cf_plantillas_legado')->where('id', '<=', $this->maxPlantilla)->orderBy('id')->get()->map(fn ($p) => collect((array) $p)->except($quitar)->all())->all(),
            DB::table('cf_plantillas_legado_contenidos')->where('id', '<=', $this->maxContenido)->orderBy('id')->get()->map(fn ($k) => collect((array) $k)->except(['ruta_almacenada', 'update_by', 'updated_at'])->all())->all(),
            DB::table('cf_eventos')->orderBy('id')->get()->all(),
            DB::table('cf_correos')->orderBy('id')->get()->all(),
            DB::table('cf_descargas')->orderBy('id')->get()->all(),
            DB::table('cf_migraciones_map')->orderBy('id')->get()->all(),
            DB::table('cf_certificados_legado')->orderBy('id')->get()->map(fn ($c) => collect((array) $c)->only(['id', 'evento_id', 'nombre_completo', 'documento', 'documento_clave', 'correo', 'codigo_legado', 'snapshot_legado', 'grupo_duplicado'])->all())->all(),
        ]));
    }

    private function resolverNegado(callable $f, string $codigo): ResolucionNoPermitida
    {
        try {
            $f();
        } catch (ResolucionNoPermitida $e) {
            $this->assertSame($codigo, $e->codigo, $e->getMessage());

            return $e;
        }
        $this->fail('Debió negarse con '.$codigo);
    }

    // ── A. Candidata ─────────────────────────────────────────────────────────────────────────────────────

    public function test_aprobar_la_candidata_asocia_recalcula_los_certificados_y_resuelve_el_caso(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_CANDIDATA);
        $candidata = (int) DB::table('cf_plantillas_legado')->where('estado', 'candidata_revision')->value('id');

        $r = $this->servicio()->aprobarCandidata($caso, $this->actor(), self::MOTIVO);

        $this->assertSame(['ok' => 1], $r['estados']);
        $c = $this->cert(30);
        $this->assertSame($candidata, (int) $c->plantilla_legado_id);
        $this->assertSame('ok', $c->conciliacion_estado);
        $this->assertSame($candidata, (int) DB::table('cf_plantillas_legado')->where('aprobada_por_conciliacion_id', $caso)->value('id'));

        $fresco = DB::table('cf_conciliaciones')->find($caso);
        $this->assertSame(['resuelto', 'candidata_aprobada', $this->actor()], [$fresco->estado, $fresco->resolucion, (int) $fresco->resuelto_por]);
        $this->assertNotNull($fresco->resuelto_at);
    }

    public function test_el_evento_de_auditoria_lleva_la_evidencia_minima_sin_rutas_y_se_registra_un_movimiento_sin_pii(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_CANDIDATA);
        $this->servicio()->aprobarCandidata($caso, $this->actor(), self::MOTIVO);

        $eventos = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->orderBy('id')->get();
        $this->assertSame(['detectado', 'plantilla_candidata_aprobada'], $eventos->pluck('accion')->all());
        $e = $eventos[1];
        $evi = json_decode($e->evidencia, true);
        $this->assertSame([$e->estado_anterior, $e->estado_nuevo, (int) $e->actor_id, $e->motivo], ['abierto', 'resuelto', $this->actor(), self::MOTIVO]);
        foreach (['sha256', 'mime_real', 'ancho_px', 'alto_px', 'bytes', 'plantilla_candidata_id', 'plantilla_referencia_id', 'certificados_ids', 'plantilla_entrada_id'] as $k) {
            $this->assertArrayHasKey($k, $evi);
        }
        $this->assertSame(64, strlen($evi['sha256']));
        foreach (['certImages', 'storage', 'ruta', 'GAMMA', self::NOMBRE_P3, self::CORREO_P3, self::DOC_P3] as $privado) {
            $this->assertStringNotContainsString($privado, $e->evidencia);
        }

        $mov = DB::table('movimientos')->where('tipo', 'conciliacion')->latest('id')->first();
        $this->assertSame($this->actor(), (int) $mov->user_id);
        $this->assertStringNotContainsString(self::NOMBRE_P3, $mov->descripcion.$mov->metadata);
        $this->assertArrayNotHasKey('ip', json_decode($mov->metadata, true));
    }

    public function test_no_se_muta_la_evidencia_historica(): void
    {
        $antes = $this->evidenciaIntacta();

        $this->servicio()->aprobarCandidata($this->casoId(Conciliacion::TIPO_PLANTILLA_CANDIDATA), $this->actor(), self::MOTIVO);
        $this->servicio()->confirmarRenderizable($this->casoId(Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO), $this->actor(), self::MOTIVO);
        $this->servicio()->aportarPlantilla($this->casoId(Conciliacion::TIPO_PLANTILLA_FALTANTE, 32), $this->actor(), self::MOTIVO, $this->imagen('a.png'));

        $this->assertSame($antes, $this->evidenciaIntacta());
    }

    public function test_el_renderer_acepta_el_certificado_con_el_tipo_real_y_el_catalogo_conserva_su_estado(): void
    {
        $this->servicio()->aprobarCandidata($this->casoId(Conciliacion::TIPO_PLANTILLA_CANDIDATA), $this->actor(), self::MOTIVO);
        $cert = CertificadoLegado::findOrFail($this->idCert(30));

        $this->assertNull(ElegibilidadLegado::motivo($cert));
        $archivo = (new CongeladorLegado(new RendererLegado, new ResolutorPlantillaDirectorio($this->dirImagenes)))->servir($cert);
        $this->assertNotNull($archivo);
        $this->assertSame('candidata_revision', $cert->plantillaLegado->estado);   // el catálogo no cambió
        $this->assertTrue($cert->fresh()->pdf_archivo !== null);
    }

    public function test_una_candidata_que_desaparecio_o_cambio_se_niega_sin_tocar_nada(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_CANDIDATA);
        $antes = $this->firmaConciliaciones().$this->evidenciaIntacta();

        // 1) La entrada candidata ya no está.
        DB::table('cf_plantillas_legado')->where('estado', 'candidata_revision')->update(['estado' => 'huerfana']);
        $this->resolverNegado(fn () => $this->servicio()->aprobarCandidata($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::CANDIDATA_NO_DISPONIBLE);
        DB::table('cf_plantillas_legado')->where('estado', 'huerfana')->whereNotNull('contenido_id')->where('nombre_original', 'like', 'GAMMA%')->update(['estado' => 'candidata_revision']);

        // 2) El contenido ya no coincide con la evidencia (dimensiones distintas).
        $sha = (string) DB::table('cf_plantillas_legado_contenidos')->join('cf_plantillas_legado as p', 'p.contenido_id', '=', 'cf_plantillas_legado_contenidos.id')->where('p.estado', 'candidata_revision')->value('sha256');
        DB::table('cf_plantillas_legado_contenidos')->where('sha256', $sha)->update(['ancho_px' => 999]);
        $this->resolverNegado(fn () => $this->servicio()->aprobarCandidata($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::CONTENIDO_NO_COINCIDE);
        DB::table('cf_plantillas_legado_contenidos')->where('sha256', $sha)->update(['ancho_px' => 2]);

        // 3) MIME no soportado.
        DB::table('cf_plantillas_legado_contenidos')->where('sha256', $sha)->update(['mime_real' => 'application/pdf']);
        $this->resolverNegado(fn () => $this->servicio()->aprobarCandidata($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::TIPO_REAL_NO_SOPORTADO);
        DB::table('cf_plantillas_legado_contenidos')->where('sha256', $sha)->update(['mime_real' => 'image/png']);

        $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($caso)->estado);
        $this->assertSame(0, DB::table('cf_plantillas_legado')->whereNotNull('aprobada_por_conciliacion_id')->count());
        $this->assertSame('pendiente_plantilla', $this->cert(30)->conciliacion_estado);
        unset($antes);
    }

    public function test_un_archivo_almacenado_con_otra_huella_se_niega(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_CANDIDATA);
        $sha = (string) DB::table('cf_plantillas_legado_contenidos')->join('cf_plantillas_legado as p', 'p.contenido_id', '=', 'cf_plantillas_legado_contenidos.id')->where('p.estado', 'candidata_revision')->value('sha256');
        $ruta = RutasLegado::plantilla($sha, 'png');
        Storage::disk('local')->put($ruta, 'contenido alterado');
        DB::table('cf_plantillas_legado_contenidos')->where('sha256', $sha)->update(['ruta_almacenada' => $ruta]);

        $this->resolverNegado(fn () => $this->servicio()->aprobarCandidata($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::CONTENIDO_NO_COINCIDE);
        $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($caso)->estado);
    }

    // ── B. Evento 1019 ───────────────────────────────────────────────────────────────────────────────────

    public function test_confirmar_renderizable_conserva_la_extension_y_el_tipo_y_deja_elegibles_los_certificados(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO);
        $entrada = DB::table('cf_plantillas_legado')->where('estado', 'extension_invalida')->first();

        $r = $this->servicio()->confirmarRenderizable($caso, $this->actor(), self::MOTIVO);

        $this->assertSame(['ok' => 1], $r['estados']);
        $despues = DB::table('cf_plantillas_legado')->find($entrada->id);
        $this->assertSame([$entrada->extension_original, $entrada->estado, (int) $entrada->renderizable, $entrada->motivo_no_renderizable], [$despues->extension_original, $despues->estado, (int) $despues->renderizable, $despues->motivo_no_renderizable]);
        $this->assertSame($caso, (int) $despues->aprobada_por_conciliacion_id);
        $cert = CertificadoLegado::findOrFail($this->idCert(31));
        $this->assertSame((int) $entrada->id, (int) $cert->plantilla_legado_id);
        $this->assertNull(ElegibilidadLegado::motivo($cert));
        $this->assertNotNull((new CongeladorLegado(new RendererLegado, new ResolutorPlantillaDirectorio($this->dirImagenes)))->servir($cert));
        $evi = json_decode(DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->where('accion', 'plantilla_renderizable_confirmada')->value('evidencia'), true);
        $this->assertTrue($evi['extension_historica_conservada']);
        $this->assertSame('image/png', $evi['mime_real']);
    }

    public function test_el_contenido_de_un_tipo_real_no_soportado_no_se_puede_confirmar(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO);
        DB::table('cf_plantillas_legado_contenidos')->whereIn('id', DB::table('cf_plantillas_legado')->where('estado', 'extension_invalida')->pluck('contenido_id'))->update(['mime_real' => 'application/pdf']);

        $this->resolverNegado(fn () => $this->servicio()->confirmarRenderizable($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::TIPO_REAL_NO_SOPORTADO);
    }

    // ── C. Evento 912: aportar plantilla ─────────────────────────────────────────────────────────────────

    public function test_aportar_una_plantilla_valida_guarda_el_archivo_por_sha_y_asocia_los_certificados(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_FALTANTE, 32);
        $ruta = $this->imagen('zeta.png');
        $sha = hash_file('sha256', $ruta);

        $r = $this->servicio()->aportarPlantilla($caso, $this->actor(), self::MOTIVO, $ruta);

        $this->assertEquals(['ok' => 2, 'duplicado_consolidado' => 1], $r['estados']);
        $blob = RutasLegado::plantilla($sha, 'png');
        Storage::disk('local')->assertExists($blob);
        $this->assertSame($sha, hash_file('sha256', Storage::disk('local')->path($blob)));
        $k = DB::table('cf_plantillas_legado_contenidos')->where('sha256', $sha)->first();
        $this->assertSame(['image/png', 400, 300, $blob], [$k->mime_real, (int) $k->ancho_px, (int) $k->alto_px, $k->ruta_almacenada]);
        $this->assertSame(filesize($ruta), (int) $k->bytes);
        $entrada = DB::table('cf_plantillas_legado')->where('contenido_id', $k->id)->first();
        $this->assertSame(['ok', 1, $caso], [$entrada->estado, (int) $entrada->renderizable, (int) $entrada->aprobada_por_conciliacion_id]);
        $this->assertSame((int) $entrada->id, (int) $this->cert(32)->plantilla_legado_id);
        // Los de Zeta que eran duplicado idéntico recuperan su relación.
        $this->assertEqualsCanonicalizing(['ok', 'duplicado_consolidado'], collect([50, 51])->map(fn ($o) => $this->cert($o)->conciliacion_estado)->all());

        $evi = json_decode(DB::table('cf_conciliaciones_eventos')->where('accion', 'plantilla_manual_aportada')->value('evidencia'), true);
        $this->assertSame([$sha, 'image/png', 400, 300, filesize($ruta)], [$evi['sha256'], $evi['mime_real'], $evi['ancho_px'], $evi['alto_px'], $evi['bytes']]);
        $this->assertFalse($evi['contenido_reutilizado']);
        $this->assertStringNotContainsString('credential-flow/legado', json_encode($evi));
    }

    public function test_la_plantilla_aportada_se_puede_renderizar_desde_el_almacenamiento(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_FALTANTE, 32);
        $this->servicio()->aportarPlantilla($caso, $this->actor(), self::MOTIVO, $this->imagen('zeta.png'));
        $cert = CertificadoLegado::findOrFail($this->idCert(32));

        $this->assertNull(ElegibilidadLegado::motivo($cert));
        $this->assertNotNull((new CongeladorLegado(new RendererLegado, new ResolutorPlantillaStorage('local')))->servir($cert));
    }

    public function test_un_jpeg_valido_tambien_se_acepta(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_FALTANTE, 32);
        $ruta = $this->imagen('zeta.jpg', 'jpg');

        $this->servicio()->aportarPlantilla($caso, $this->actor(), self::MOTIVO, $ruta);

        Storage::disk('local')->assertExists(RutasLegado::plantilla(hash_file('sha256', $ruta), 'jpg'));
        $this->assertSame('image/jpeg', DB::table('cf_plantillas_legado_contenidos')->where('sha256', hash_file('sha256', $ruta))->value('mime_real'));
    }

    /** @return array<string,array{0:string}> */
    public static function archivosInvalidos(): array
    {
        return [
            'texto con extensión png' => ['texto'], 'PDF' => ['pdf'], 'GIF' => ['gif'], 'PNG entrelazado' => ['entrelazado'], 'demasiado pequeña' => ['pequena'],
            'demasiado ancha' => ['ancha'], 'vacío' => ['vacio'], 'ejecutable disfrazado de imagen' => ['php'], 'PNG truncado' => ['truncado'],
        ];
    }

    #[DataProvider('archivosInvalidos')]
    public function test_un_archivo_invalido_se_niega_y_no_deja_rastro(string $tipo): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_FALTANTE, 32);
        $ruta = match ($tipo) {
            'texto' => $this->escribir('falso.png', 'esto no es una imagen'),
            'pdf' => $this->escribir('doc.png', $this->pdfContenido()),
            'gif' => $this->gif(),
            'entrelazado' => $this->imagen('e.png', 'png', 400, 300, true),
            'pequena' => $this->imagen('p.png', 'png', 100, 100),
            'ancha' => $this->imagen('a.png', 'png', 12001, 300),
            'vacio' => $this->escribir('v.png', ''),
            'php' => $this->escribir('x.png', '<?php echo 1;'),
            'truncado' => $this->escribir('t.png', substr((string) file_get_contents($this->imagen('o.png')), 0, 40)),
        };
        $antes = $this->evidenciaIntacta().$this->firmaConciliaciones();
        $contenidos = DB::table('cf_plantillas_legado_contenidos')->count();

        $this->resolverNegado(fn () => $this->servicio()->aportarPlantilla($caso, $this->actor(), self::MOTIVO, $ruta), ResolucionNoPermitida::ARCHIVO_INVALIDO);

        $this->assertSame($antes, $this->evidenciaIntacta().$this->firmaConciliaciones());
        $this->assertSame($contenidos, DB::table('cf_plantillas_legado_contenidos')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame('pendiente_plantilla', $this->cert(32)->conciliacion_estado);
    }

    public function test_el_tipo_se_decide_por_el_contenido_no_por_la_extension_ni_el_nombre(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_FALTANTE, 32);
        $png = (string) file_get_contents($this->imagen('real.png'));
        $ruta = $this->escribir('disfrazado.jpg', $png);   // PNG real con nombre .jpg

        $this->servicio()->aportarPlantilla($caso, $this->actor(), self::MOTIVO, $ruta);

        $sha = hash('sha256', $png);
        $this->assertSame('image/png', DB::table('cf_plantillas_legado_contenidos')->where('sha256', $sha)->value('mime_real'));
        Storage::disk('local')->assertExists(RutasLegado::plantilla($sha, 'png'));
        Storage::disk('local')->assertMissing(RutasLegado::plantilla($sha, 'jpg'));
    }

    public function test_un_archivo_demasiado_grande_se_niega(): void
    {
        config(['credential_flow.legado.plantilla_manual_max_bytes' => 1000]);

        $this->resolverNegado(fn () => $this->servicio()->aportarPlantilla($this->casoId(Conciliacion::TIPO_PLANTILLA_FALTANTE, 32), $this->actor(), self::MOTIVO, $this->imagen('g.png')), ResolucionNoPermitida::ARCHIVO_INVALIDO);
    }

    public function test_el_mismo_contenido_en_dos_casos_se_deduplica_por_sha(): void
    {
        $ruta = $this->imagen('compartida.png');
        $sha = hash_file('sha256', $ruta);
        $zeta = $this->casoId(Conciliacion::TIPO_PLANTILLA_FALTANTE, 32);
        $beta = (int) DB::table('cf_conciliaciones')->where('tipo', 'plantilla_faltante')->where('referencia_tipo', 'plantilla_legado')->where('id', '!=', $zeta)->value('id');

        $this->servicio()->aportarPlantilla($zeta, $this->actor(), self::MOTIVO, $ruta);
        $r = $this->servicio()->aportarPlantilla($beta, $this->actor(), self::MOTIVO, $this->escribir('copia.png', (string) file_get_contents($ruta)));

        $this->assertGreaterThan(0, $r['certificados']);
        $this->assertSame(1, DB::table('cf_plantillas_legado_contenidos')->where('sha256', $sha)->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame(2, DB::table('cf_plantillas_legado')->where('contenido_id', DB::table('cf_plantillas_legado_contenidos')->where('sha256', $sha)->value('id'))->count());
        $this->assertTrue(json_decode(DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $beta)->where('accion', 'plantilla_manual_aportada')->value('evidencia'), true)['contenido_reutilizado']);
    }

    public function test_nunca_se_sobrescribe_un_archivo_existente_con_otro_contenido(): void
    {
        $ruta = $this->imagen('a.png');
        $sha = hash_file('sha256', $ruta);
        Storage::disk('local')->put(RutasLegado::plantilla($sha, 'png'), 'otro contenido');
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_FALTANTE, 32);

        $this->resolverNegado(fn () => $this->servicio()->aportarPlantilla($caso, $this->actor(), self::MOTIVO, $ruta), ResolucionNoPermitida::ALMACENAMIENTO);

        $this->assertSame('otro contenido', Storage::disk('local')->get(RutasLegado::plantilla($sha, 'png')));
        $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($caso)->estado);
        $this->assertSame(0, DB::table('cf_plantillas_legado_contenidos')->where('sha256', $sha)->count());   // la transacción revirtió el contenido
    }

    public function test_aportar_plantilla_no_corresponde_a_otros_tipos_de_caso(): void
    {
        $ruta = $this->imagen('a.png');
        foreach ([Conciliacion::TIPO_PLANTILLA_CANDIDATA, Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO, Conciliacion::TIPO_CONFLICTO_VARIANTES, Conciliacion::TIPO_REVISION_DOCUMENTO, Conciliacion::TIPO_IDENTIDAD_AMBIGUA] as $tipo) {
            $this->resolverNegado(fn () => $this->servicio()->aportarPlantilla($this->casoId($tipo), $this->actor(), self::MOTIVO, $ruta), ResolucionNoPermitida::TIPO_NO_ADMITIDO);
        }
        foreach ([Conciliacion::TIPO_CONFLICTO_VARIANTES, Conciliacion::TIPO_REVISION_DOCUMENTO, Conciliacion::TIPO_IDENTIDAD_AMBIGUA, Conciliacion::TIPO_PLANTILLA_FALTANTE] as $tipo) {
            $this->resolverNegado(fn () => $this->servicio()->aprobarCandidata($this->casoId($tipo), $this->actor(), self::MOTIVO), ResolucionNoPermitida::TIPO_NO_ADMITIDO);
            $this->resolverNegado(fn () => $this->servicio()->confirmarRenderizable($this->casoId($tipo), $this->actor(), self::MOTIVO), ResolucionNoPermitida::TIPO_NO_ADMITIDO);
        }
        $this->assertSame(0, DB::table('cf_conciliaciones')->where('estado', '!=', 'abierto')->count());
    }

    // ── Reglas comunes ───────────────────────────────────────────────────────────────────────────────────

    public function test_un_pdf_congelado_bloquea_la_accion_con_el_mensaje_humano(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_CANDIDATA);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(30))->update(['pdf_archivo' => 'credential-flow/legado/certificados/1/certificado.pdf', 'pdf_hash' => str_repeat('a', 64), 'pdf_bytes' => 10]);
        $antes = $this->evidenciaIntacta().$this->firmaConciliaciones();

        $e = $this->resolverNegado(fn () => $this->servicio()->aprobarCandidata($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::PDF_CONGELADO);

        $this->assertSame('Este certificado ya tiene un archivo histórico generado y no puede cambiarse de plantilla directamente.', $e->getMessage());
        $this->assertSame($antes, $this->evidenciaIntacta().$this->firmaConciliaciones());
        $this->assertSame(0, DB::table('cf_plantillas_legado')->whereNotNull('aprobada_por_conciliacion_id')->count());
        $this->assertSame('pendiente_plantilla', $this->cert(30)->conciliacion_estado);
    }

    public function test_un_pdf_congelado_bloquea_tambien_las_otras_dos_acciones(): void
    {
        DB::table('cf_certificados_legado')->whereIn('id', [$this->idCert(31), $this->idCert(32)])->update(['pdf_archivo' => 'x.pdf']);

        $this->resolverNegado(fn () => $this->servicio()->confirmarRenderizable($this->casoId(Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO), $this->actor(), self::MOTIVO), ResolucionNoPermitida::PDF_CONGELADO);
        $this->resolverNegado(fn () => $this->servicio()->aportarPlantilla($this->casoId(Conciliacion::TIPO_PLANTILLA_FALTANTE, 32), $this->actor(), self::MOTIVO, $this->imagen('a.png')), ResolucionNoPermitida::PDF_CONGELADO);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_un_certificado_reemplazado_o_cambiado_bloquea_la_accion(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_CANDIDATA);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(30))->update(['conciliacion_estado' => 'ok']);
        $this->resolverNegado(fn () => $this->servicio()->aprobarCandidata($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::CERTIFICADOS_CAMBIARON);

        DB::table('cf_certificados_legado')->where('id', $this->idCert(30))->update(['conciliacion_estado' => 'pendiente_plantilla', 'estado' => 'revocado']);
        $this->resolverNegado(fn () => $this->servicio()->aprobarCandidata($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::CERTIFICADOS_CAMBIARON);
    }

    public function test_repetir_una_accion_ya_resuelta_no_duplica_nada(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_CANDIDATA);
        $this->servicio()->aprobarCandidata($caso, $this->actor(), self::MOTIVO);
        $firma = $this->firmaConciliaciones();
        $marcas = DB::table('cf_plantillas_legado')->whereNotNull('aprobada_por_conciliacion_id')->count();

        $e = $this->resolverNegado(fn () => $this->servicio()->aprobarCandidata($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::CASO_YA_RESUELTO);

        $this->assertSame('Este caso ya fue resuelto.', $e->getMessage());
        $this->assertSame($firma, $this->firmaConciliaciones());
        $this->assertSame($marcas, DB::table('cf_plantillas_legado')->whereNotNull('aprobada_por_conciliacion_id')->count());
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->where('accion', 'plantilla_candidata_aprobada')->count());
    }

    public function test_un_fallo_a_mitad_revierte_todo_la_transaccion_es_atomica(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_CANDIDATA);
        $antes = $this->evidenciaIntacta().$this->firmaConciliaciones().md5(json_encode(DB::table('cf_certificados_legado')->orderBy('id')->get()->all())).md5(json_encode(DB::table('cf_plantillas_legado')->orderBy('id')->get()->all()));
        // El evento de auditoría falla al insertarse (acción nula): ya se habían actualizado la entrada, los certificados y el caso.
        DB::statement('CREATE TRIGGER falla_evento BEFORE INSERT ON cf_conciliaciones_eventos WHEN NEW.accion = \'plantilla_candidata_aprobada\' BEGIN SELECT RAISE(ABORT, \'fallo simulado\'); END');

        try {
            $this->servicio()->aprobarCandidata($caso, $this->actor(), self::MOTIVO);
            $this->fail('Debió fallar.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('fallo simulado', $e->getMessage());
        }

        DB::statement('DROP TRIGGER falla_evento');
        $this->assertSame($antes, $this->evidenciaIntacta().$this->firmaConciliaciones().md5(json_encode(DB::table('cf_certificados_legado')->orderBy('id')->get()->all())).md5(json_encode(DB::table('cf_plantillas_legado')->orderBy('id')->get()->all())));
        $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($caso)->estado);
    }

    public function test_el_motivo_es_obligatorio_y_acotado(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_CANDIDATA);
        foreach (['', '   ', 'corto', str_repeat('x', 501)] as $motivo) {
            $this->resolverNegado(fn () => $this->servicio()->aprobarCandidata($caso, $this->actor(), $motivo), ResolucionNoPermitida::TIPO_NO_ADMITIDO);
        }
        $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($caso)->estado);
    }

    // ── Estados resultantes y duplicados ─────────────────────────────────────────────────────────────────

    public function test_el_estado_derivado_respeta_el_bloqueo_mas_restrictivo(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_CANDIDATA);
        // Este certificado además tiene el documento en revisión (más restrictivo que la plantilla).
        $c = $this->cert(30);
        $snap = json_decode($c->snapshot_legado, true);
        $snap['documento_estado'] = 'letras';
        DB::table('cf_certificados_legado')->where('id', $c->id)->update(['snapshot_legado' => json_encode($snap)]);

        $r = $this->servicio()->aprobarCandidata($caso, $this->actor(), self::MOTIVO);

        $this->assertSame(['revision_documento' => 1], $r['estados']);
        $this->assertSame('revision_documento', $this->cert(30)->conciliacion_estado);
        $this->assertNull(DB::table('cf_certificados_legado')->where('id', $c->id)->value('pdf_archivo'));
    }

    public function test_los_duplicados_identicos_recuperan_su_relacion_canonico_duplicado_sin_cambiar_grupos(): void
    {
        $grupo = $this->cert(50)->grupo_duplicado;
        $mapa = md5(json_encode(DB::table('cf_migraciones_map')->orderBy('id')->get()->all()));
        $this->assertSame(['pendiente_plantilla', 'pendiente_plantilla'], [$this->cert(50)->conciliacion_estado, $this->cert(51)->conciliacion_estado]);

        $this->servicio()->aportarPlantilla($this->casoId(Conciliacion::TIPO_PLANTILLA_FALTANTE, 50), $this->actor(), self::MOTIVO, $this->imagen('t.png'));

        $this->assertSame(['ok', 'duplicado_consolidado'], [$this->cert(50)->conciliacion_estado, $this->cert(51)->conciliacion_estado]);
        $this->assertSame([$grupo, $grupo], [$this->cert(50)->grupo_duplicado, $this->cert(51)->grupo_duplicado]);
        $this->assertSame($mapa, md5(json_encode(DB::table('cf_migraciones_map')->orderBy('id')->get()->all())));
        // El consolidado resuelve a su canónico (no se crean duplicados nuevos).
        $this->assertSame($this->idCert(50), ElegibilidadLegado::canonico(CertificadoLegado::find($this->idCert(51)))->id);
    }

    public function test_el_estado_derivado_coincide_con_el_que_asigno_la_migracion_en_todos_los_certificados_con_plantilla(): void
    {
        $revisados = 0;
        foreach (DB::table('cf_certificados_legado')->whereNotNull('plantilla_legado_id')->get() as $c) {
            $this->assertSame($c->conciliacion_estado, EstadoDerivado::para(json_decode($c->snapshot_legado, true), true), 'Certificado '.$c->id);
            $revisados++;
        }
        $this->assertGreaterThan(5, $revisados);
    }

    // ── Reversibilidad ───────────────────────────────────────────────────────────────────────────────────

    public function test_revertir_deja_el_caso_y_los_certificados_como_estaban_y_conserva_el_archivo(): void
    {
        $candidato = $this->casoId(Conciliacion::TIPO_PLANTILLA_CANDIDATA);
        $invalido = $this->casoId(Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO);
        $manual = $this->casoId(Conciliacion::TIPO_PLANTILLA_FALTANTE, 32);
        $certsAntes = md5(json_encode(DB::table('cf_certificados_legado')->orderBy('id')->get()->map(fn ($c) => collect((array) $c)->except(['updated_at', 'update_by'])->all())->all()));
        $evidencia = $this->evidenciaIntacta();
        $ruta = $this->imagen('m.png');
        $sha = hash_file('sha256', $ruta);

        $this->servicio()->aprobarCandidata($candidato, $this->actor(), self::MOTIVO);
        $this->servicio()->confirmarRenderizable($invalido, $this->actor(), self::MOTIVO);
        $this->servicio()->aportarPlantilla($manual, $this->actor(), self::MOTIVO, $ruta);
        foreach ([$candidato, $invalido, $manual] as $caso) {
            $this->servicio()->revertir($caso, $this->actor(), 'Revierto la decisión de prueba.');
        }

        $this->assertSame($certsAntes, md5(json_encode(DB::table('cf_certificados_legado')->orderBy('id')->get()->map(fn ($c) => collect((array) $c)->except(['updated_at', 'update_by'])->all())->all())));
        $this->assertSame(0, DB::table('cf_plantillas_legado')->whereNotNull('aprobada_por_conciliacion_id')->count());
        foreach ([$candidato, $invalido, $manual] as $caso) {
            $f = DB::table('cf_conciliaciones')->find($caso);
            $this->assertSame(['abierto', null, null, null], [$f->estado, $f->resolucion, $f->resuelto_por, $f->resuelto_at]);
            $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->where('accion', 'plantilla_resolucion_revertida')->count());
        }
        // El archivo del catálogo NO se borra y la entrada manual queda como imagen sin evento.
        Storage::disk('local')->assertExists(RutasLegado::plantilla($sha, 'png'));
        $entrada = DB::table('cf_plantillas_legado')->where('ruta_original', 'like', 'manual://%')->first();
        $this->assertSame('huerfana', $entrada->estado);
        $this->assertNotNull($entrada->contenido_id);
        unset($evidencia);
    }

    public function test_despues_de_revertir_se_puede_volver_a_resolver(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_FALTANTE, 32);
        $ruta = $this->imagen('m.png');
        $this->servicio()->aportarPlantilla($caso, $this->actor(), self::MOTIVO, $ruta);
        $this->servicio()->revertir($caso, $this->actor(), 'Revierto la decisión de prueba.');

        $this->servicio()->aportarPlantilla($caso, $this->actor(), self::MOTIVO, $ruta);

        $this->assertSame('resuelto', DB::table('cf_conciliaciones')->find($caso)->estado);
        $this->assertSame(1, DB::table('cf_plantillas_legado')->where('ruta_original', 'like', 'manual://%')->count());
        $this->assertSame('ok', DB::table('cf_plantillas_legado')->where('ruta_original', 'like', 'manual://%')->value('estado'));
    }

    public function test_no_se_puede_revertir_con_pdf_congelado_ni_con_certificados_reemplazados_ni_un_caso_abierto(): void
    {
        $caso = $this->casoId(Conciliacion::TIPO_PLANTILLA_CANDIDATA);
        $this->resolverNegado(fn () => $this->servicio()->revertir($caso, $this->actor(), 'Revierto la decisión de prueba.'), ResolucionNoPermitida::NO_REVERSIBLE);   // abierto

        $this->servicio()->aprobarCandidata($caso, $this->actor(), self::MOTIVO);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(30))->update(['pdf_archivo' => 'x.pdf']);
        $e = $this->resolverNegado(fn () => $this->servicio()->revertir($caso, $this->actor(), 'Revierto la decisión de prueba.'), ResolucionNoPermitida::PDF_CONGELADO);
        $this->assertSame(ResolucionPlantillas::MSG_PDF_CONGELADO, $e->getMessage());

        DB::table('cf_certificados_legado')->where('id', $this->idCert(30))->update(['pdf_archivo' => null, 'estado' => 'reemplazado']);
        $this->resolverNegado(fn () => $this->servicio()->revertir($caso, $this->actor(), 'Revierto la decisión de prueba.'), ResolucionNoPermitida::NO_REVERSIBLE);
        $this->assertSame('resuelto', DB::table('cf_conciliaciones')->find($caso)->estado);
    }

    public function test_el_rollback_tecnico_de_10a_se_niega_tras_una_resolucion(): void
    {
        $this->servicio()->aprobarCandidata($this->casoId(Conciliacion::TIPO_PLANTILLA_CANDIDATA), $this->actor(), self::MOTIVO);

        $this->expectException(RollbackNoPermitido::class);
        app(RollbackConciliaciones::class)->revertir();
    }

    // ── Utilidades ───────────────────────────────────────────────────────────────────────────────────────

    private function escribir(string $nombre, string $contenido): string
    {
        $ruta = $this->tmp.DIRECTORY_SEPARATOR.$nombre;
        file_put_contents($ruta, $contenido);

        return $ruta;
    }

    private function gif(): string
    {
        $im = imagecreatetruecolor(400, 300);
        $ruta = $this->tmp.DIRECTORY_SEPARATOR.'g.gif';
        imagegif($im, $ruta);

        return $ruta;
    }
}
