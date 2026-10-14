<?php

namespace Tests\Feature\CredentialFlow\Migracion;

use App\Models\CredentialFlow\MigracionCorrida;
use App\Support\CredentialFlow\Conciliaciones\DetectorConciliaciones;
use App\Support\CredentialFlow\Legado\PreflightCodigosHistoricos;
use App\Support\CredentialFlow\Migracion\DeltaRechazado;
use App\Support\CredentialFlow\Migracion\MigradorDelta;
use App\Support\CredentialFlow\Migracion\MigradorDeltaEncuestas;
use App\Support\CredentialFlow\Migracion\MigradorEncuestas;
use App\Support\CredentialFlow\Migracion\MigradorHistorico;
use App\Support\CredentialFlow\Migracion\ResultadoMigracion;
use App\Support\CredentialFlow\Migracion\RollbackDelta;
use App\Support\CredentialFlow\Migracion\RollbackNoPermitido;
use App\Support\CredentialFlow\StagingEv\EscanerImagenes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\CredentialFlow\Historico\HistoricoTestCase;

/**
 * Fase 11F-B: migración DELTA (snapshot final → solo lo nuevo) sobre un histórico base ya migrado. Todo sintético.
 *
 * Base: el staging de las pruebas (6 eventos, 15 participantes, 5 descargas, 3 encuestas) migrado con MigradorHistorico +
 * MigradorEncuestas. Delta: snapshot `s2` con 1 evento nuevo (7, con imagen nueva), 4 participantes nuevos, 5 descargas nuevas
 * (3 sobre certificados base y 2 sobre certificados delta), 1 token y 1 encuesta nuevos.
 */
class MigracionDeltaTest extends HistoricoTestCase
{
    private const TABLAS = ['cf_descargas', 'cf_correos', 'cf_certificados_legado', 'cf_plantillas_legado', 'cf_plantillas_legado_contenidos', 'cf_eventos', 'cf_migraciones_map',
        'cf_encuestas_respuestas', 'cf_encuestas_respuestas_detalle', 'cf_encuestas_versiones', 'cf_encuestas_preguntas', 'cf_encuestas_opciones', 'cf_conciliaciones', 'cf_migraciones_corridas'];

    private string $png = '';

    // ── Utilidades ────────────────────────────────────────────────────────────

    /** @param array<string,string> $extra nombre → contenido */
    private function imagenes(array $extra = []): array
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'delta_'.bin2hex(random_bytes(6));
        mkdir($dir);
        if ($this->png === '') {
            $im = imagecreatetruecolor(2, 2);
            ob_start();
            imagepng($im);
            $this->png = (string) ob_get_clean();
        }
        file_put_contents($dir.'/ALFA 2024.png', $this->png);
        file_put_contents($dir.'/DELTA 2023', $this->png);
        file_put_contents($dir.'/ORFANA.png', $this->png.'o');
        file_put_contents($dir.'/GAMMA_.png', $this->png.'g');
        foreach ($extra as $nombre => $contenido) {
            file_put_contents($dir.'/'.$nombre, $contenido);
        }
        $r = (new EscanerImagenes)->escanear($dir);
        File::deleteDirectory($dir);

        return $r;
    }

    private function participante(int $id, int $evento, ?string $tipo, ?string $doc, ?string $nombre, ?string $correo, ?int $verif = null): array
    {
        return ['id' => $id, 'tipo_documento' => $tipo, 'documento' => $doc, 'nombre' => $nombre, 'correo' => $correo, 'id_evento' => $evento, 'num_verificacion' => $verif];
    }

    /** Staging base (s1) migrado: certificados y encuestas. */
    private function base(): void
    {
        $this->cargar('s1', $this->datos(), $this->imagenes());
        (new MigradorHistorico)->ejecutar();
        (new MigradorEncuestas)->ejecutar();
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private function datosDelta(): array
    {
        $d = $this->datos();
        $d['evento'][] = ['id' => 7, 'nombre' => 'Curso Eta 2026', 'imagen_certificado' => 'ETA 2026.png'];
        $d['participante'][] = $this->participante(16, 7, 'CC', '3000001', 'MARTA DIECISEIS', 'marta@example.test', 777);
        $d['participante'][] = $this->participante(17, 7, 'CC', '3000002', 'NICO DIECISIETE', 'nico1@example.test; nico2@example.test');
        $d['participante'][] = $this->participante(18, 7, 'CC', '3000003', 'OLGA DIECIOCHO', null);
        $d['participante'][] = $this->participante(19, 7, 'CC', 'ABC', 'PEDRO DIECINUEVE', 'pedro@example.test');
        $d['descargas'][] = ['id' => 6, 'fecha' => '2026-10-14 10:00:00', 'id_evento' => 1, 'id_participante' => 1];
        $d['descargas'][] = ['id' => 7, 'fecha' => '2026-10-14 10:05:00', 'id_evento' => 1, 'id_participante' => 7];
        $d['descargas'][] = ['id' => 8, 'fecha' => '2026-10-14 10:10:00', 'id_evento' => 2, 'id_participante' => 3];
        $d['descargas'][] = ['id' => 9, 'fecha' => '2026-10-14 11:00:00', 'id_evento' => 7, 'id_participante' => 16];
        $d['descargas'][] = ['id' => 10, 'fecha' => '2026-10-14 11:05:00', 'id_evento' => 7, 'id_participante' => 17];
        $d['token'][] = ['id' => 4, 'codigo' => 'tokensecreto-ccc', 'fecha_creacion' => '2026-10-14 09:00:00', 'id_participante' => 16];
        $d['encuesta'][] = [
            'id' => 4, 'pregunta1' => 'Excelente', 'justificacion_pregunta1' => null, 'pregunta2' => 'Bueno', 'pregunta3' => 'Bueno', 'pregunta4' => 'Bueno',
            'pregunta5' => 'Muy bien', 'pregunta6' => '', 'pregunta7' => '', 'pregunta8' => '', 'pregunta9' => '',
            'id_evento' => 7, 'documento_participante' => '3000001', 'fecha' => '2026-10-14 12:00:00',
        ];

        return $d;
    }

    /** Carga el snapshot nuevo (s2) con la imagen nueva. */
    private function snapshotDelta(?array $datos = null, ?array $imagenesExtra = null): void
    {
        if ($imagenesExtra === null) {
            // PNG válido y distinto de los del base (3×3): contenido NUEVO por SHA.
            $im = imagecreatetruecolor(3, 3);
            ob_start();
            imagepng($im);
            $imagenesExtra = ['ETA 2026.png' => (string) ob_get_clean()];
        }
        $this->cargar('s2', $datos ?? $this->datosDelta(), $this->imagenes($imagenesExtra));
    }

    /** @return array<string,int> */
    private function conteos(): array
    {
        $r = [];
        foreach (self::TABLAS as $t) {
            $r[$t] = DB::table($t)->count();
        }

        return $r;
    }

    private function delta(): ResultadoMigracion
    {
        return (new MigradorDelta)->ejecutar();
    }

    private function idCertDe(int $old): ?int
    {
        $id = DB::table('cf_migraciones_map')->where('origen_tabla', 'participante')->where('origen_id', (string) $old)->value('destino_id');

        return $id === null ? null : (int) $id;
    }

    // ── 1–2. vacío y repetido ─────────────────────────────────────────────────

    public function test_delta_vacio_es_no_op_y_no_escribe_nada(): void
    {
        $this->base();
        $this->cargar('s2', $this->datos(), $this->imagenes());   // mismo contenido, otro SHA de dump: todo «igual»
        $antes = $this->conteos();

        $r = $this->delta();

        $this->assertTrue($r->noOp);
        $this->assertSame($antes, $this->conteos());
        $this->assertSame(0, MigracionCorrida::where('tipo', MigracionCorrida::TIPO_LEGADO_DELTA)->count());
    }

    public function test_el_mismo_delta_dos_veces_es_no_op(): void
    {
        $this->base();
        $this->snapshotDelta();
        $primera = $this->delta();
        $this->assertFalse($primera->noOp);
        $despues = $this->conteos();

        $segunda = $this->delta();

        $this->assertTrue($segunda->noOp);
        $this->assertSame($despues, $this->conteos());
        $this->assertSame(1, MigracionCorrida::where('tipo', MigracionCorrida::TIPO_LEGADO_DELTA)->count());
    }

    // ── 3–4. evento nuevo, imagen nueva, descargas ────────────────────────────

    public function test_evento_nuevo_con_participantes_correos_y_descargas(): void
    {
        $this->base();
        $antes = $this->conteos();
        $this->snapshotDelta();

        $r = $this->delta();
        $t = $r->totales;
        $despues = $this->conteos();

        $this->assertFalse($r->noOp);
        $this->assertSame(1, $despues['cf_eventos'] - $antes['cf_eventos']);
        $this->assertSame(4, $despues['cf_certificados_legado'] - $antes['cf_certificados_legado']);
        $this->assertSame(4, $despues['cf_correos'] - $antes['cf_correos']);   // 1 + 2 + 0 + 1
        $this->assertSame(5, $despues['cf_descargas'] - $antes['cf_descargas']);
        $c = MigracionCorrida::findOrFail($r->corridaId);
        $this->assertSame(MigracionCorrida::TIPO_LEGADO_DELTA, $c->tipo);
        $this->assertSame(MigracionCorrida::ESTADO_COMPLETADA, $c->estado);
        $this->assertSame(1, $t['eventos']);
        $this->assertSame(4, $t['certificados']);
        foreach ($t['validaciones'] as $v) {
            $this->assertTrue($v['ok'], 'No cuadra: '.$v['clave']);
        }
        // Todo lo creado lleva la corrida delta y nada es visible ni tiene PDF.
        foreach (['cf_eventos', 'cf_certificados_legado', 'cf_correos', 'cf_descargas'] as $tabla) {
            $this->assertSame($despues[$tabla] - $antes[$tabla], DB::table($tabla)->where('corrida_id', $r->corridaId)->count(), $tabla);
        }
        $this->assertSame(0, DB::table('cf_certificados_legado')->where('corrida_id', $r->corridaId)->where('visible_portal', true)->count());
    }

    public function test_imagen_nueva_crea_contenido_y_plantilla_sin_tocar_las_anteriores(): void
    {
        $this->base();
        $contenidosAntes = DB::table('cf_plantillas_legado_contenidos')->pluck('id', 'sha256')->all();
        $plantillasAntes = DB::table('cf_plantillas_legado')->count();
        $this->snapshotDelta();

        $r = $this->delta();

        $this->assertSame(count($contenidosAntes) + 1, DB::table('cf_plantillas_legado_contenidos')->count());
        $this->assertSame($plantillasAntes + 1, DB::table('cf_plantillas_legado')->count());
        foreach ($contenidosAntes as $sha => $id) {
            $this->assertSame(0, DB::table('cf_plantillas_legado_contenidos')->where('id', $id)->where('corrida_id', $r->corridaId)->count());
        }
        $evento = DB::table('cf_eventos')->where('corrida_id', $r->corridaId)->first();
        $this->assertNotNull($evento->plantilla_legado_id);
        $this->assertSame('ok', DB::table('cf_plantillas_legado')->where('id', $evento->plantilla_legado_id)->value('estado'));
    }

    public function test_misma_imagen_por_sha_reutiliza_el_contenido(): void
    {
        $this->base();
        $contenidos = DB::table('cf_plantillas_legado_contenidos')->count();
        // El evento nuevo usa un archivo NUEVO por nombre pero con el MISMO contenido que ALFA 2024.png.
        $this->snapshotDelta(null, ['ETA 2026.png' => $this->png]);

        $r = $this->delta();

        $this->assertSame($contenidos, DB::table('cf_plantillas_legado_contenidos')->count());
        $this->assertSame(0, DB::table('cf_plantillas_legado_contenidos')->where('corrida_id', $r->corridaId)->count());
        $this->assertSame(1, DB::table('cf_plantillas_legado')->where('corrida_id', $r->corridaId)->count());
    }

    public function test_descarga_nueva_sobre_certificado_base_solo_inserta_la_descarga(): void
    {
        $this->base();
        $certsAntes = DB::table('cf_certificados_legado')->count();
        $certBase = $this->idCertDe(1);
        $descargasBase = DB::table('cf_descargas')->where('certificado_legado_id', $certBase)->count();
        $this->snapshotDelta();

        $r = $this->delta();

        $this->assertSame($descargasBase + 1, DB::table('cf_descargas')->where('certificado_legado_id', $certBase)->count());
        $nueva = DB::table('cf_descargas')->where('certificado_legado_id', $certBase)->where('corrida_id', $r->corridaId)->first();
        $this->assertNotNull($nueva);
        $this->assertSame('2026-10-14 10:00:00', substr((string) $nueva->descargado_at, 0, 19));
        $this->assertSame($certsAntes + 4, DB::table('cf_certificados_legado')->count());   // el certificado base no se duplicó
        $this->assertSame(1, DB::table('cf_migraciones_map')->where('origen_tabla', 'participante')->where('origen_id', '1')->count());
        $this->assertSame(3, $r->totales['descargas']['sobre_certificados_base']);
    }

    public function test_descarga_nueva_sobre_certificado_delta(): void
    {
        $this->base();
        $this->snapshotDelta();

        $r = $this->delta();

        $cert = $this->idCertDe(16);
        $this->assertNotNull($cert);
        $this->assertSame(1, DB::table('cf_descargas')->where('certificado_legado_id', $cert)->where('corrida_id', $r->corridaId)->count());
        $this->assertSame(2, $r->totales['descargas']['sobre_certificados_delta']);
    }

    // ── 8, 12. tokens y código legado ─────────────────────────────────────────

    public function test_token_nuevo_no_produce_escritura_moderna(): void
    {
        $this->base();
        $this->snapshotDelta();
        $tablasTokens = array_filter(DB::select("select name from sqlite_master where type='table' and name like 'cf\\_%' escape '\\'"), fn ($t) => str_contains($t->name, 'token'));
        $this->assertSame([], array_values($tablasTokens));   // el modelo moderno no tiene dónde guardar tokens

        $r = $this->delta();

        $this->assertSame(0, $r->totales['tokens']['migrados']);
        $this->assertSame(1, DB::table('stg_ev_token')->where('old_id', 4)->count());   // sigue solo en staging
        $this->assertSame(0, DB::table('cf_migraciones_map')->where('origen_tabla', 'token')->count());
    }

    public function test_codigo_legado_nuevo_se_preserva_y_el_preflight_del_rango_reservado_sigue_ok(): void
    {
        $this->base();
        $this->snapshotDelta();

        $this->delta();

        $this->assertSame('777', (string) DB::table('cf_certificados_legado')->where('id', $this->idCertDe(16))->value('codigo_legado'));
        $this->assertTrue((new PreflightCodigosHistoricos)->verificar()['ok']);
        $this->assertSame(0, DB::table('cf_codigos_historicos')->count());   // no se asigna ningún código moderno
    }

    // ── 9–10. cambios destructivos ────────────────────────────────────────────

    public function test_fila_cambiada_rechaza_todo_el_delta_sin_escribir(): void
    {
        $this->base();
        $datos = $this->conCambio($this->datosDelta(), 'participante', 1, ['nombre' => 'ANA UNO CAMBIADA']);
        $this->snapshotDelta($datos);
        $antes = $this->conteos();

        try {
            $this->delta();
            $this->fail('Debía rechazar el delta');
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::FILAS_CAMBIADAS, $e->codigo);
            $this->assertSame(['participante' => 1], $e->detalle['cambiadas']);
        }
        $this->assertSame($antes, $this->conteos());
    }

    public function test_fila_ausente_rechaza_todo_el_delta_sin_escribir(): void
    {
        $this->base();
        $datos = $this->sinFila($this->datosDelta(), 'participante', 5);
        $this->snapshotDelta($datos);
        $antes = $this->conteos();

        try {
            $this->delta();
            $this->fail('Debía rechazar el delta');
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::FILAS_AUSENTES, $e->codigo);
            $this->assertSame(['participante' => 1], $e->detalle['ausentes']);
        }
        $this->assertSame($antes, $this->conteos());
    }

    // ── Paridad con el migrador base y re-ejecución tras rollback ─────────────

    /** Campos de negocio de un certificado (sin ids, sin corrida, sin etiqueta de grupo) por old_id del participante. */
    private function huellaDeCertificados(array $oldIds): array
    {
        $r = [];
        foreach ($oldIds as $old) {
            $c = DB::table('cf_certificados_legado')->where('id', $this->idCertDe($old))->first();
            $snap = json_decode((string) $c->snapshot_legado, true);
            unset($snap['migracion']['delta'], $snap['migracion']['hash_fila'], $snap['duplicado']['grupo_staging']);
            $r[$old] = [
                'evento' => DB::table('cf_eventos')->where('id', $c->evento_id)->value('nombre'), 'tipo_documento' => $c->tipo_documento, 'documento_clave' => $c->documento_clave,
                'conciliacion' => $c->conciliacion_estado, 'correo_estado' => $c->correo_estado, 'codigo' => $c->codigo_legado, 'con_plantilla' => $c->plantilla_legado_id !== null,
                'estado' => $c->estado, 'grupo' => $c->grupo_duplicado !== null, 'snapshot' => $snap,
                'correos' => DB::table('cf_correos')->where('certificado_legado_id', $c->id)->orderBy('orden')->get(['correo', 'correo_normalizado', 'estado', 'orden'])->map(fn ($x) => (array) $x)->all(),
                'descargas' => DB::table('cf_descargas')->where('certificado_legado_id', $c->id)->orderBy('descargado_at')->pluck('descargado_at')->map(fn ($f) => substr((string) $f, 0, 19))->all(),
            ];
        }

        return $r;
    }

    /** Eventos (por old_id) y plantillas (por ruta) con sus contenidos: campos de negocio, sin ids ni corrida. */
    private function huellaDeEventosYPlantillas(array $oldEventos, array $rutas): array
    {
        $entrada = function (?object $p) {
            if ($p === null) {
                return null;
            }
            $c = $p->contenido_id === null ? null : DB::table('cf_plantillas_legado_contenidos')->where('id', $p->contenido_id)->first();

            return [
                'ruta' => $p->ruta_original, 'nombre' => $p->nombre_original, 'norm' => $p->nombre_normalizado, 'ext' => $p->extension_original, 'estado' => $p->estado,
                'renderizable' => (bool) $p->renderizable, 'motivo' => $p->motivo_no_renderizable, 'notas' => $p->notas,
                'contenido' => $c === null ? null : [$c->sha256, (int) $c->bytes, $c->mime_real, $c->ancho_px, $c->alto_px, $c->ruta_almacenada],
            ];
        };
        $r = ['eventos' => [], 'plantillas' => []];
        foreach ($oldEventos as $old) {
            $e = DB::table('cf_eventos')->where('id', DB::table('cf_migraciones_map')->where('origen_tabla', 'evento')->where('origen_id', (string) $old)->value('destino_id'))->first();
            $r['eventos'][$old] = [
                'nombre' => $e->nombre, 'norm' => $e->nombre_normalizado, 'anio' => $e->anio, 'estado' => $e->estado, 'origen' => $e->origen,
                'notas' => json_decode((string) $e->notas, true), 'plantilla' => $entrada($e->plantilla_legado_id === null ? null : DB::table('cf_plantillas_legado')->where('id', $e->plantilla_legado_id)->first()),
            ];
        }
        foreach ($rutas as $ruta) {
            $r['plantillas'][$ruta] = $entrada(DB::table('cf_plantillas_legado')->where('ruta_original', $ruta)->first());
        }

        return $r;
    }

    /** Respuestas (por old_id de la encuesta) con sus detalles y vínculos expresados por old_id del participante. */
    private function huellaDeEncuestas(array $oldIds): array
    {
        $r = [];
        foreach ($oldIds as $old) {
            $resp = DB::table('cf_encuestas_respuestas')->where('id', DB::table('cf_migraciones_map')->where('origen_tabla', 'encuesta')->where('origen_id', (string) $old)->value('destino_id'))->first();
            $partOld = $resp->certificado_legado_id === null ? null : DB::table('cf_migraciones_map')->where('origen_tabla', 'participante')->where('destino_id', $resp->certificado_legado_id)->value('origen_id');
            $r[$old] = [
                'clasificacion' => $resp->clasificacion, 'version' => (int) DB::table('cf_encuestas_versiones')->where('id', $resp->version_id)->value('numero'),
                'evento' => $resp->evento_id === null ? null : DB::table('cf_eventos')->where('id', $resp->evento_id)->value('nombre'), 'participante_old' => $partOld,
                'completada' => substr((string) $resp->completada_at, 0, 19), 'hash' => $resp->documento_hash !== null,
                'detalles' => DB::table('cf_encuestas_respuestas_detalle as d')->leftJoin('cf_encuestas_preguntas as p', 'p.id', '=', 'd.pregunta_id')->leftJoin('cf_encuestas_opciones as o', 'o.id', '=', 'd.opcion_id')
                    ->where('d.respuesta_id', $resp->id)->orderBy('d.clave_historica')->get(['d.clave_historica', 'd.valor_texto', 'p.clave as pregunta', 'o.valor as opcion'])->map(fn ($x) => (array) $x)->all(),
            ];
        }

        return $r;
    }

    public function test_paridad_el_delta_produce_lo_mismo_que_migrar_todo_de_una_vez(): void
    {
        $datos = $this->datosDelta();
        $datos['participante'][] = $this->participante(22, 7, 'CC', '3000001', 'MARTA DIECISEIS X', 'marta@example.test', 777);   // duplicado conflictivo del evento nuevo
        $datos['participante'][] = $this->participante(23, 7, 'CC', '3000009', 'ZOE VEINTITRES', 'zoe@example.test');
        $datos['participante'][] = $this->participante(24, 7, 'CC', '3000009', 'ZOE VEINTITRES', 'zoe@example.test');                   // duplicado idéntico
        $nuevos = [16, 17, 18, 19, 22, 23, 24];
        $rutaNueva = EscanerImagenes::PREFIJO_RUTA.'ETA 2026.png';
        $im = imagecreatetruecolor(3, 3);
        ob_start();
        imagepng($im);
        $png3 = (string) ob_get_clean();
        $resumen = function () use ($nuevos, $rutaNueva) {
            $porMapa = [];
            foreach (['evento', 'participante', 'descarga', 'imagen', 'imagen_contenido', 'evento_imagen_faltante', 'encuesta'] as $t) {
                $porMapa[$t] = DB::table('cf_migraciones_map')->where('origen_tabla', $t)->count();
            }

            return [
                'certificados' => $this->huellaDeCertificados($nuevos),
                'eventos_y_plantillas' => $this->huellaDeEventosYPlantillas([1, 2, 7], [EscanerImagenes::PREFIJO_RUTA.'ALFA 2024.png', $rutaNueva]),
                'encuestas' => $this->huellaDeEncuestas([1, 3, 4]),
                'mapas' => $porMapa,
                'contenidos' => DB::table('cf_plantillas_legado_contenidos')->orderBy('sha256')->pluck('sha256')->all(),
                'totales' => array_diff_key($this->conteos(), ['cf_migraciones_corridas' => 1, 'cf_conciliaciones' => 1]),
                'descargas_cert_base_1' => DB::table('cf_descargas')->where('certificado_legado_id', $this->idCertDe(1))->count(),
            ];
        };

        // A) base + delta (certificados y encuestas).
        $this->base();
        $this->snapshotDelta($datos);
        $this->delta();
        (new MigradorDeltaEncuestas)->ejecutar();
        $conDelta = $resumen();

        // B) todo el snapshot final migrado de una vez con los migradores base, en una aplicación limpia.
        $this->tearDown();
        $this->setUp();
        $this->cargar('s2', $datos, $this->imagenes(['ETA 2026.png' => $png3]));
        (new MigradorHistorico)->ejecutar();
        (new MigradorEncuestas)->ejecutar();
        $todoJunto = $resumen();

        $this->assertSame($todoJunto['certificados'], $conDelta['certificados']);
        $this->assertSame($todoJunto['eventos_y_plantillas'], $conDelta['eventos_y_plantillas']);
        $this->assertSame($todoJunto['encuestas'], $conDelta['encuestas']);
        $this->assertSame($todoJunto['contenidos'], $conDelta['contenidos']);
        $this->assertSame($todoJunto['mapas'], $conDelta['mapas']);
        $this->assertSame($todoJunto['totales'], $conDelta['totales']);
        $this->assertSame($todoJunto['descargas_cert_base_1'], $conDelta['descargas_cert_base_1']);
        $this->assertSame('777', (string) $conDelta['certificados'][16]['codigo']);
    }

    public function test_tras_el_rollback_un_delta_nuevo_reproduce_exactamente_lo_mismo(): void
    {
        $this->base();
        $this->snapshotDelta();
        $antes = $this->conteos();
        $primero = $this->delta();
        $despuesPrimero = $this->conteos();
        (new RollbackDelta)->revertirLegado($primero->corridaId);

        $segundo = $this->delta();

        $this->assertFalse($segundo->noOp);
        $this->assertNotSame($primero->corridaId, $segundo->corridaId);
        $esperado = $despuesPrimero;
        $esperado['cf_migraciones_corridas'] += 1;   // la corrida revertida queda registrada
        $this->assertSame($esperado, $this->conteos());
        $this->assertGreaterThan($antes['cf_certificados_legado'], $this->conteos()['cf_certificados_legado']);
    }

    // ── Simulación, clave y referencias ───────────────────────────────────────

    public function test_simular_valida_y_no_escribe_nada(): void
    {
        $this->base();
        $this->snapshotDelta();
        $antes = $this->conteos();

        $r = (new MigradorDelta)->ejecutar(true);

        $this->assertTrue($r->noOp);
        $this->assertSame(1, $r->totales['eventos_nuevos']);
        $this->assertSame(4, $r->totales['participantes_nuevos']);
        $this->assertSame(5, $r->totales['descargas_nuevas']);
        $this->assertSame(1, $r->totales['imagenes_nuevas']);
        $this->assertSame(1, $r->totales['tokens_nuevos_no_migrados']);
        $this->assertSame($antes, $this->conteos());
    }

    public function test_cambiar_la_app_key_rechaza_el_delta(): void
    {
        $this->base();
        $this->snapshotDelta();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        try {
            $this->delta();
            $this->fail('Debía rechazar por APP_KEY distinta');
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::CLAVE_DISTINTA, $e->codigo);
        }
    }

    public function test_participante_nuevo_en_un_evento_que_no_existe_se_rechaza(): void
    {
        $this->base();
        $datos = $this->datosDelta();
        $datos['participante'][] = $this->participante(23, 99, 'CC', '6000001', 'SIN EVENTO', 'x@example.test');
        $this->snapshotDelta($datos);
        $antes = $this->conteos();

        try {
            $this->delta();
            $this->fail('Debía rechazar');
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::PARTICIPANTE_SIN_EVENTO, $e->codigo);
        }
        $this->assertSame($antes, $this->conteos());
    }

    public function test_sin_corrida_base_no_hay_delta(): void
    {
        $this->cargar('s1', $this->datos(), $this->imagenes());

        try {
            $this->delta();
            $this->fail('Debía rechazar');
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::SIN_BASE, $e->codigo);
        }
    }

    public function test_los_comandos_delta_simulan_y_aplican(): void
    {
        $this->base();
        $this->snapshotDelta();
        $antes = $this->conteos();

        $this->artisan('credential-flow:legado:delta', ['--simular' => true])->expectsOutputToContain('Simulación')->assertExitCode(0);
        $this->assertSame($antes, $this->conteos());
        $this->artisan('credential-flow:legado:delta')->expectsOutputToContain('Delta completado')->assertExitCode(0);
        $this->artisan('credential-flow:legado:delta')->expectsOutputToContain('ya está incorporado')->assertExitCode(0);
        $this->artisan('credential-flow:encuestas:delta')->expectsOutputToContain('Delta de encuestas completado')->assertExitCode(0);
        $corrida = (int) MigracionCorrida::where('tipo', MigracionCorrida::TIPO_ENCUESTAS_DELTA)->value('id');
        $this->artisan('credential-flow:encuestas:delta-rollback', ['id' => $corrida])->assertExitCode(1);   // sin --confirmar no hace nada
        $this->artisan('credential-flow:encuestas:delta-rollback', ['id' => $corrida, '--confirmar' => true])->expectsOutputToContain('revertida')->assertExitCode(0);
        $corridaLegado = (int) MigracionCorrida::where('tipo', MigracionCorrida::TIPO_LEGADO_DELTA)->value('id');
        $this->artisan('credential-flow:legado:delta-rollback', ['id' => $corridaLegado, '--confirmar' => true])->expectsOutputToContain('revertida')->assertExitCode(0);
    }

    public function test_el_rollback_delta_no_acepta_corridas_base(): void
    {
        $this->base();
        $baseId = (int) MigracionCorrida::where('tipo', MigracionCorrida::TIPO_LEGADO)->value('id');

        try {
            (new RollbackDelta)->revertirLegado($baseId);
            $this->fail('Debía negarse');
        } catch (RollbackNoPermitido $e) {
            $this->assertSame(RollbackNoPermitido::TIPO_NO_ES_DELTA, $e->codigo);
        }
    }

    // ── 15. grupo duplicado existente ─────────────────────────────────────────

    public function test_participante_nuevo_en_grupo_duplicado_existente_se_rechaza_con_resultado_explicito(): void
    {
        $this->base();
        $datos = $this->datosDelta();
        // Mismo evento 1 y documento 2000002 que el grupo conflictivo base (participantes 9 y 10).
        $datos['participante'][] = $this->participante(20, 1, 'CC', '2000002', 'HUGO NUEVE', 'hugo@example.test');
        $this->snapshotDelta($datos);
        $antes = $this->conteos();

        try {
            $this->delta();
            $this->fail('Debía rechazar el delta');
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::GRUPO_EXISTENTE, $e->codigo);
            $this->assertSame(1, $e->detalle['participantes_nuevos_en_grupos_existentes']);
            $this->assertSame(2, $e->detalle['certificados_existentes_afectados']);
        }
        $this->assertSame($antes, $this->conteos());
    }

    public function test_participante_nuevo_en_evento_existente_sin_duplicado_es_aditivo(): void
    {
        $this->base();
        $datos = $this->datosDelta();
        $datos['participante'][] = $this->participante(21, 1, 'CC', '4000001', 'QUIQUE VEINTIUNO', 'quique@example.test');
        $this->snapshotDelta($datos);

        $r = $this->delta();

        $cert = DB::table('cf_certificados_legado')->where('id', $this->idCertDe(21))->first();
        $this->assertSame((int) DB::table('cf_migraciones_map')->where('origen_tabla', 'evento')->where('origen_id', '1')->where('corrida_id', '!=', $r->corridaId)->value('destino_id'), (int) $cert->evento_id);
        $this->assertNotNull($cert->plantilla_legado_id);   // hereda la plantilla usable del evento base
        $this->assertSame('ok', $cert->conciliacion_estado);
    }

    // ── 14. detector ──────────────────────────────────────────────────────────

    public function test_detector_tras_el_delta_no_duplica_casos_anteriores_y_crea_los_nuevos(): void
    {
        $this->base();
        $detector = app(DetectorConciliaciones::class);
        $detector->ejecutar();
        $idsBase = DB::table('cf_conciliaciones')->orderBy('id')->pluck('clave_idempotencia', 'id')->all();
        $this->assertNotEmpty($idsBase);

        // Delta con un duplicado conflictivo propio del evento nuevo (mismo documento y distinto nombre).
        $datos = $this->datosDelta();
        $datos['participante'][] = $this->participante(22, 7, 'CC', '3000001', 'MARTA DIECISEIS X', 'marta@example.test', 777);
        $this->snapshotDelta($datos);
        $this->delta();

        $r = $detector->ejecutar();

        $this->assertGreaterThan(0, $r['total']['nuevos']);
        $this->assertGreaterThanOrEqual(count($idsBase), $r['total']['existentes']);
        foreach ($idsBase as $id => $clave) {   // los casos anteriores siguen siendo los mismos
            $this->assertSame($clave, DB::table('cf_conciliaciones')->where('id', $id)->value('clave_idempotencia'));
        }
        $this->assertSame(0, $detector->ejecutar()['total']['nuevos']);
        $this->assertSame(DB::table('cf_conciliaciones')->count(), DB::table('cf_conciliaciones')->distinct()->count('clave_idempotencia'));
    }

    // ── 7, 9. encuestas delta ─────────────────────────────────────────────────

    public function test_encuesta_nueva_reutiliza_la_estructura_y_solo_crea_respuesta_y_detalles(): void
    {
        $this->base();
        $this->snapshotDelta();
        $this->delta();
        $versiones = DB::table('cf_encuestas_versiones')->count();
        $preguntas = DB::table('cf_encuestas_preguntas')->count();
        $opciones = DB::table('cf_encuestas_opciones')->count();
        $respuestas = DB::table('cf_encuestas_respuestas')->count();

        $r = (new MigradorDeltaEncuestas)->ejecutar();

        $this->assertFalse($r->noOp);
        $this->assertSame($versiones, DB::table('cf_encuestas_versiones')->count());
        $this->assertSame($preguntas, DB::table('cf_encuestas_preguntas')->count());
        $this->assertSame($opciones, DB::table('cf_encuestas_opciones')->count());
        $this->assertSame($respuestas + 1, DB::table('cf_encuestas_respuestas')->count());
        $resp = DB::table('cf_encuestas_respuestas')->where('corrida_id', $r->corridaId)->first();
        $this->assertSame('vinculada', $resp->clasificacion);
        $this->assertSame($this->idCertDe(16), (int) $resp->certificado_legado_id);
        $this->assertSame(MigracionCorrida::TIPO_ENCUESTAS_DELTA, MigracionCorrida::findOrFail($r->corridaId)->tipo);
        foreach ($r->totales['validaciones'] as $v) {
            $this->assertTrue($v['ok'], 'No cuadra: '.$v['clave']);
        }
        $this->assertTrue((new MigradorDeltaEncuestas)->ejecutar()->noOp);
    }

    public function test_encuestas_delta_exige_que_el_delta_de_certificados_ya_este_migrado(): void
    {
        $this->base();
        $this->snapshotDelta();

        try {
            (new MigradorDeltaEncuestas)->ejecutar();
            $this->fail('La encuesta del evento nuevo no tiene evento migrado');
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::EVENTO_NO_MIGRADO, $e->codigo);
        }
        $this->assertSame(0, DB::table('cf_encuestas_respuestas')->where('evento_id', null)->where('old_evento_id', 7)->count());
    }

    public function test_encuesta_con_opcion_no_observada_en_la_estructura_base_se_rechaza(): void
    {
        $this->base();
        $datos = $this->datosDelta();
        $datos['encuesta'][3]['pregunta1'] = 'Opinión inventada';
        $this->snapshotDelta($datos);
        $this->delta();

        try {
            (new MigradorDeltaEncuestas)->ejecutar();
            $this->fail('Debía rechazar la opción nueva');
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::ESTRUCTURA_ENCUESTA_NUEVA, $e->codigo);
        }
        $this->assertSame(3, DB::table('cf_encuestas_respuestas')->count());
    }

    // ── 16–17. rollback ───────────────────────────────────────────────────────

    public function test_rollback_delta_devuelve_la_base_exacta_y_marca_la_corrida(): void
    {
        $this->base();
        $antes = $this->conteos();
        $contenidos = DB::table('cf_plantillas_legado_contenidos')->count();
        $this->snapshotDelta();
        $r = $this->delta();
        $enc = (new MigradorDeltaEncuestas)->ejecutar();

        $rb = new RollbackDelta;
        $rb->revertirEncuestas($enc->corridaId);
        $out = $rb->revertirLegado($r->corridaId);

        $this->assertSame(1, $out['eventos']);
        $this->assertSame(4, $out['certificados']);
        $this->assertSame(5, $out['descargas']);
        $this->assertSame($contenidos, DB::table('cf_plantillas_legado_contenidos')->count());
        $despues = $this->conteos();
        $antes['cf_migraciones_corridas'] += 2;   // las corridas delta quedan registradas como revertidas
        $this->assertSame($antes, $despues);
        $this->assertSame(MigracionCorrida::ESTADO_REVERTIDA, MigracionCorrida::findOrFail($r->corridaId)->estado);
        // La base sigue intacta y la corrida #1 sigue completada.
        $this->assertSame(1, MigracionCorrida::where('tipo', MigracionCorrida::TIPO_LEGADO)->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->count());
    }

    public function test_rollback_delta_se_bloquea_si_hay_encuestas_delta_o_casos_posteriores(): void
    {
        $this->base();
        $this->snapshotDelta();
        $r = $this->delta();
        $enc = (new MigradorDeltaEncuestas)->ejecutar();
        $rb = new RollbackDelta;

        try {
            $rb->revertirLegado($r->corridaId);
            $this->fail('Debía bloquear por encuestas delta');
        } catch (RollbackNoPermitido $e) {
            $this->assertSame(RollbackNoPermitido::ENCUESTAS_VINCULADAS, $e->codigo);
        }
        $rb->revertirEncuestas($enc->corridaId);

        // Un caso de conciliación sobre un certificado delta también bloquea.
        $cert = $this->idCertDe(16);
        $caso = DB::table('cf_conciliaciones')->insertGetId([
            'tipo' => 'revision_documento', 'estado' => 'abierto', 'evento_id' => null, 'referencia_tipo' => 'certificado', 'referencia_clave' => (string) $cert,
            'motivo_origen' => 'TEST', 'clave_idempotencia' => 'test:'.$cert, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso, 'certificado_legado_id' => $cert, 'rol' => 'afectado', 'created_at' => now(), 'updated_at' => now()]);
        try {
            $rb->revertirLegado($r->corridaId);
            $this->fail('Debía bloquear por casos');
        } catch (RollbackNoPermitido $e) {
            $this->assertSame(RollbackNoPermitido::CASOS_POSTERIORES, $e->codigo);
        }
        $this->assertSame(MigracionCorrida::ESTADO_COMPLETADA, MigracionCorrida::findOrFail($r->corridaId)->estado);
    }

    public function test_rollback_delta_se_bloquea_ante_casos_que_referencian_plantilla_evento_o_grupo_del_delta(): void
    {
        $this->base();
        $datos = $this->datosDelta();
        $datos['participante'][] = $this->participante(22, 7, 'CC', '3000001', 'MARTA DIECISEIS X', 'marta@example.test', 777);   // grupo duplicado propio del delta
        $this->snapshotDelta($datos);
        $r = $this->delta();
        $plantilla = (string) DB::table('cf_plantillas_legado')->where('corrida_id', $r->corridaId)->value('id');
        $evento = (string) DB::table('cf_eventos')->where('corrida_id', $r->corridaId)->value('id');
        $grupo = (string) DB::table('cf_certificados_legado')->where('corrida_id', $r->corridaId)->whereNotNull('grupo_duplicado')->value('grupo_duplicado');
        $this->assertNotSame('', $grupo);

        foreach ([['plantilla_legado', $plantilla], ['evento', $evento], ['grupo_duplicado', $grupo]] as [$tipo, $clave]) {
            $caso = DB::table('cf_conciliaciones')->insertGetId([
                'tipo' => 'plantilla_candidata', 'estado' => 'abierto', 'evento_id' => null, 'referencia_tipo' => $tipo, 'referencia_clave' => $clave,
                'motivo_origen' => 'TEST', 'clave_idempotencia' => "test:{$tipo}:{$clave}", 'created_at' => now(), 'updated_at' => now(),
            ]);
            try {
                (new RollbackDelta)->revertirLegado($r->corridaId);
                $this->fail("Debía bloquear por el caso de referencia {$tipo}");
            } catch (RollbackNoPermitido $e) {
                $this->assertSame(RollbackNoPermitido::CASOS_POSTERIORES, $e->codigo, $tipo);
            }
            DB::table('cf_conciliaciones')->where('id', $caso)->delete();
        }
        $this->assertSame(MigracionCorrida::ESTADO_COMPLETADA, MigracionCorrida::findOrFail($r->corridaId)->estado);
    }

    // ── Rehearsal con las proporciones de producción ──────────────────────────

    public function test_rehearsal_con_las_proporciones_de_la_ventana_real(): void
    {
        $this->base();
        $d = $this->datos();
        $d['evento'][] = ['id' => 7, 'nombre' => 'Curso Eta 2026', 'imagen_certificado' => 'ETA 2026.png'];
        for ($i = 0; $i < 29; $i++) {
            $d['participante'][] = $this->participante(100 + $i, 7, 'CC', (string) (5000000 + $i), 'PERSONA '.$i, "p{$i}@example.test", $i < 4 ? 900 + $i : null);
        }
        // 23 descargas: 18 sobre certificados base y 5 sobre certificados del evento nuevo.
        $baseIds = [1, 3, 6, 7, 11, 12];
        for ($i = 0; $i < 18; $i++) {
            $d['descargas'][] = ['id' => 100 + $i, 'fecha' => '2026-10-14 10:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).':00', 'id_evento' => 1, 'id_participante' => $baseIds[$i % 6]];
        }
        for ($i = 0; $i < 5; $i++) {
            $d['descargas'][] = ['id' => 200 + $i, 'fecha' => '2026-10-14 11:0'.$i.':00', 'id_evento' => 7, 'id_participante' => 100 + $i];
        }
        for ($i = 0; $i < 9; $i++) {
            $d['token'][] = ['id' => 50 + $i, 'codigo' => 'tok-'.$i, 'fecha_creacion' => '2026-10-14 09:00:00', 'id_participante' => 100 + $i];
        }
        $d['encuesta'][] = [
            'id' => 4, 'pregunta1' => 'Excelente', 'justificacion_pregunta1' => null, 'pregunta2' => 'Bueno', 'pregunta3' => 'Bueno', 'pregunta4' => 'Bueno',
            'pregunta5' => '', 'pregunta6' => '', 'pregunta7' => '', 'pregunta8' => '', 'pregunta9' => '', 'id_evento' => 7, 'documento_participante' => '5000000', 'fecha' => '2026-10-14 12:00:00',
        ];
        $antes = $this->conteos();
        $this->snapshotDelta($d);

        $r = $this->delta();
        $e = (new MigradorDeltaEncuestas)->ejecutar();
        $despues = $this->conteos();

        $this->assertSame(1, $despues['cf_eventos'] - $antes['cf_eventos']);
        $this->assertSame(29, $despues['cf_certificados_legado'] - $antes['cf_certificados_legado']);
        $this->assertSame(23, $despues['cf_descargas'] - $antes['cf_descargas']);
        $this->assertSame(18, $r->totales['descargas']['sobre_certificados_base']);
        $this->assertSame(5, $r->totales['descargas']['sobre_certificados_delta']);
        $this->assertSame(1, $despues['cf_plantillas_legado_contenidos'] - $antes['cf_plantillas_legado_contenidos']);
        $this->assertSame(1, $despues['cf_encuestas_respuestas'] - $antes['cf_encuestas_respuestas']);
        foreach (['participante' => 29, 'evento' => 1, 'descarga' => 23, 'imagen' => 1, 'imagen_contenido' => 1] as $origen => $n) {
            $this->assertSame($n, DB::table('cf_migraciones_map')->where('corrida_id', $r->corridaId)->where('origen_tabla', $origen)->count(), $origen);
        }
        $this->assertSame(1, DB::table('cf_migraciones_map')->where('corrida_id', $e->corridaId)->where('origen_tabla', 'encuesta')->count());
        // Los tokens (9) siguen solo en el staging.
        $this->assertSame(0, DB::table('cf_migraciones_map')->where('origen_tabla', 'token')->count());
        // Base intacta: ningún id de la corrida #1 cambió de corrida.
        $this->assertSame(0, DB::table('cf_certificados_legado')->where('corrida_id', 1)->whereColumn('updated_at', '!=', 'created_at')->count());
        // Idempotencia del delta completo.
        $this->assertTrue($this->delta()->noOp);
        $this->assertTrue((new MigradorDeltaEncuestas)->ejecutar()->noOp);
    }
}
