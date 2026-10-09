<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\ConciliacionEvento;
use App\Models\CredentialFlow\Emision;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Emisiones\CodigoEmision;
use App\Support\CredentialFlow\Emisiones\EmisorCredencial;
use App\Support\CredentialFlow\Reemplazo\CreadorPlantillaReemplazo;
use App\Support\CredentialFlow\Reemplazo\EmisionVigente;
use App\Support\CredentialFlow\Reemplazo\ReemplazoHistorico;
use App\Support\CredentialFlow\Reemplazo\ReglasValorAprobado;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Reemplazo de un certificado histórico por una emisión moderna (Fase 10B-2B-2A): servicio, reglas, lote, atomicidad y cadena. */
class ReemplazoHistoricoTest extends ReemplazoTestCase
{
    private function negado(callable $f, string $codigo): ResolucionNoPermitida
    {
        try {
            $f();
        } catch (ResolucionNoPermitida $e) {
            $this->assertSame($codigo, $e->codigo, $e->getMessage());

            return $e;
        }
        $this->fail('Debió negarse con '.$codigo);
    }

    // ── Reemplazo completo ───────────────────────────────────────────────────────────────────────────────

    public function test_el_reemplazo_crea_lote_participante_emision_pdf_enlace_cierre_y_auditoria(): void
    {
        $plantilla = $this->plantillaModerna();
        $conciliacion = $this->cert(120)->conciliacion_estado;
        $mapa = DB::table('cf_migraciones_map')->count();
        $caso = $this->casoDe(120);

        $r = $this->reemplazar(120, $plantilla->id);

        // Lote técnico marcado, apuntando al evento HISTÓRICO, con fecha e intensidad vacías (la plantilla no las usa).
        $cert = $this->cert(120);
        $lote = DB::table('cf_lotes')->find($r['lote_id']);
        $comunes = json_decode($lote->datos_comunes, true);
        $this->assertTrue($r['lote_creado']);
        $this->assertSame([(int) $cert->evento_id, $plantilla->id], [(int) $lote->evento_id, (int) $lote->plantilla_id]);
        $this->assertSame([ReemplazoHistorico::ORIGEN_LOTE, '', ''], [$comunes['origen'], $comunes['fecha'], $comunes['intensidad_horaria']]);
        $this->assertSame((string) DB::table('cf_eventos')->where('id', $cert->evento_id)->value('nombre'), $comunes['evento']);

        // Participante con el valor APROBADO (sin el espacio de no separación) y sin correo.
        $p = DB::table('cf_participantes')->find($r['participante_id']);
        $this->assertSame(['PERSONA ESPACIO', '8300001', '8300001', (int) $r['lote_id']], [$p->nombre_completo, $p->documento, $p->documento_clave, (int) $p->lote_id]);
        $this->assertNull($p->correo);
        $this->assertSame(0, DB::table('cf_correos')->where('participante_id', $p->id)->count());

        // Emisión moderna: código de 20 caracteres, versión 1, vigente, operación determinista, snapshot y PDF íntegros.
        $e = Emision::findOrFail($r['emision_id']);
        $this->assertTrue(CodigoEmision::valido($e->codigo));
        $this->assertSame([1, 'emitida', (int) $p->id, ReemplazoHistorico::operacion((int) $cert->id), $this->actor()], [$e->version, $e->estado, (int) $e->participante_vigente, $e->operacion, (int) $e->emitido_por]);
        $this->assertSame(['PERSONA ESPACIO', '8300001', '', ''], [$e->datos_snapshot['nombre_completo'], $e->datos_snapshot['documento'], $e->datos_snapshot['fecha'], $e->datos_snapshot['intensidad_horaria']]);
        $this->assertStringStartsWith('%PDF', AlmacenEmisiones::leerVerificado($e));
        $this->assertSame(hash('sha256', AlmacenEmisiones::leerVerificado($e)), $e->pdf_hash);

        // El histórico queda reemplazado y enlazado; su conciliación y su evidencia NO cambian; ningún código se crea ni se reutiliza.
        $this->assertSame(['reemplazado', (int) $e->id, $conciliacion], [$cert->estado, (int) $cert->reemplazado_por_emision_id, $cert->conciliacion_estado]);
        $this->assertSame($mapa, DB::table('cf_migraciones_map')->count());
        $this->assertSame(0, DB::table('cf_codigos_historicos')->count());
        $this->assertNotSame($e->codigo, $cert->codigo_legado);

        // Caso cerrado + evento append-only.
        $f = DB::table('cf_conciliaciones')->find($caso);
        $this->assertSame(['resuelto', ReemplazoHistorico::RESOLUCION, $this->actor()], [$f->estado, $f->resolucion, (int) $f->resuelto_por]);
        $ev = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->orderByDesc('id')->first();
        $this->assertSame([ReemplazoHistorico::ACCION, 'abierto', 'resuelto', self::MOTIVO], [$ev->accion, $ev->estado_anterior, $ev->estado_nuevo, $ev->motivo]);
        $this->artisan('credential-flow:verificar-emisiones')->assertExitCode(0);
    }

    public function test_el_historico_es_inmutable_y_la_auditoria_no_guarda_datos_personales(): void
    {
        $plantilla = $this->plantillaModerna();
        $antes = $this->evidencia();
        $snapshot = DB::table('cf_certificados_legado')->where('id', $this->idCert(120))->value('snapshot_legado');

        $r = $this->reemplazar(120, $plantilla->id);

        $this->assertSame($antes, $this->evidencia(), 'nombre, documento, snapshot, código, descargas, correos y mapa originales intactos');
        $this->assertSame($snapshot, DB::table('cf_certificados_legado')->where('id', $this->idCert(120))->value('snapshot_legado'));

        $ev = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $this->casoDe(120))->orderByDesc('id')->first();
        $evi = json_decode($ev->evidencia, true);
        foreach (['certificado_historico_id', 'emision_id', 'participante_id', 'lote_id', 'plantilla_id', 'documento', 'nombre', 'operacion'] as $k) {
            $this->assertArrayHasKey($k, $evi);
        }
        $this->assertSame(['documento_sin_nbsp', hash('sha256', '8300001'), 7], [$evi['documento']['regla'], $evi['documento']['sha256'], $evi['documento']['longitud']]);
        $this->assertSame(['nombre_historico', hash('sha256', 'PERSONA ESPACIO'), 15], [$evi['nombre']['regla'], $evi['nombre']['sha256'], $evi['nombre']['longitud']]);
        $mov = DB::table('movimientos')->where('tipo', 'conciliacion')->latest('id')->first();
        foreach ([$ev->evidencia, $ev->motivo.$mov->descripcion.$mov->metadata] as $texto) {
            foreach (['PERSONA ESPACIO', '8300001', 'r1@example.test', 'credential-flow/emisiones', '.pdf'] as $privado) {
                $this->assertStringNotContainsString($privado, $texto);
            }
        }
        $this->assertArrayNotHasKey('ip', json_decode($mov->metadata, true));
        $this->assertSame($r['emision_id'], $evi['emision_id']);
    }

    public function test_un_pdf_historico_congelado_se_conserva_intacto(): void
    {
        $bytes = $this->pdfContenido();
        Storage::disk('local')->put('credential-flow/legado/prueba.pdf', $bytes);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(120))->update(['pdf_archivo' => 'credential-flow/legado/prueba.pdf', 'pdf_hash' => hash('sha256', $bytes), 'pdf_bytes' => strlen($bytes)]);
        $antes = DB::table('cf_certificados_legado')->where('id', $this->idCert(120))->first(['pdf_archivo', 'pdf_hash', 'pdf_bytes']);

        $r = $this->reemplazar(120);

        $this->assertEquals($antes, DB::table('cf_certificados_legado')->where('id', $this->idCert(120))->first(['pdf_archivo', 'pdf_hash', 'pdf_bytes']));
        $this->assertSame($bytes, Storage::disk('local')->get('credential-flow/legado/prueba.pdf'));
        $this->assertNotSame('credential-flow/legado/prueba.pdf', Emision::find($r['emision_id'])->pdf_archivo, 'la emisión moderna es otro archivo independiente');
    }

    // ── Reglas del documento y del nombre ───────────────────────────────────────────────────────────────

    public function test_las_tres_reglas_automaticas_y_la_manual_producen_el_valor_aprobado(): void
    {
        $p = $this->plantillaModerna();

        $this->assertSame('83000002', $this->documentoDe($this->reemplazar(121, $p->id, ['valorDocumento' => '83000002'])), 'el admin puede aprobar el valor sin separadores');
        $this->assertSame('83000003', $this->documentoDe($this->reemplazar(122, $p->id)), 'sin signo: solo letras y dígitos');
        $this->assertSame('83A00004', $this->documentoDe($this->reemplazar(123, $p->id)), 'alfanumérico: solo con valor confirmado y evidencia');
    }

    private function documentoDe(array $resultado): string
    {
        return (string) DB::table('cf_participantes')->where('id', $resultado['participante_id'])->value('documento');
    }

    public function test_el_documento_alfanumerico_no_se_corrige_de_forma_automatica(): void
    {
        $p = $this->plantillaModerna();
        $caso = $this->casoDe(123);
        $antes = $this->escritura();

        foreach ([ReglasValorAprobado::DOC_SIN_NBSP, ReglasValorAprobado::DOC_SIN_SIGNO, ReglasValorAprobado::DOC_CON_SEPARADORES] as $regla) {
            $this->negado(fn () => $this->servicio()->reemplazar($caso, $this->actor(), self::MOTIVO, $this->solicitud(123, $p->id, ['reglaDocumento' => $regla, 'valorDocumento' => '83A00004', 'confirmado' => true])), ResolucionNoPermitida::REGLA_NO_APLICA);
        }
        $this->negado(fn () => $this->reemplazar(123, $p->id, ['evidencia' => null]), ResolucionNoPermitida::EVIDENCIA_REQUERIDA);
        $this->negado(fn () => $this->reemplazar(123, $p->id, ['evidencia' => 'corta']), ResolucionNoPermitida::EVIDENCIA_REQUERIDA);
        $this->negado(fn () => $this->reemplazar(123, $p->id, ['confirmado' => false]), ResolucionNoPermitida::CONFIRMACION_REQUERIDA);
        $this->negado(fn () => $this->reemplazar(123, $p->id, ['valorDocumento' => null]), ResolucionNoPermitida::VALOR_NO_VALIDO);
        $this->assertSame($antes, $this->escritura());
        $this->assertSame('abierto', $this->estadoCaso($caso));
    }

    public function test_cada_regla_exige_lo_suyo_y_no_se_aplica_a_otra_categoria(): void
    {
        $p = $this->plantillaModerna();
        $antes = $this->escritura();

        $this->negado(fn () => $this->reemplazar(121, $p->id, ['confirmado' => false]), ResolucionNoPermitida::CONFIRMACION_REQUERIDA);
        $this->negado(fn () => $this->reemplazar(121, $p->id, ['valorDocumento' => '83.000.003']), ResolucionNoPermitida::VALOR_NO_VALIDO);      // otra identidad
        $this->negado(fn () => $this->reemplazar(121, $p->id, ['valorDocumento' => ' 83.000.002']), ResolucionNoPermitida::VALOR_NO_VALIDO);    // con espacio: se normalizaría
        $this->negado(fn () => $this->reemplazar(122, $p->id, ['confirmado' => false]), ResolucionNoPermitida::CONFIRMACION_REQUERIDA);
        $this->negado(fn () => $this->reemplazar(120, $p->id, ['reglaDocumento' => ReglasValorAprobado::DOC_SIN_SIGNO, 'confirmado' => true]), ResolucionNoPermitida::REGLA_NO_APLICA);
        $this->negado(fn () => $this->reemplazar(120, $p->id, ['reglaDocumento' => 'inventada']), ResolucionNoPermitida::REGLA_NO_APLICA);
        $this->negado(fn () => $this->reemplazar(120, $p->id, ['reglaNombre' => 'inventada']), ResolucionNoPermitida::REGLA_NO_APLICA);
        $this->negado(fn () => $this->reemplazar(120, $p->id, ['reglaDocumento' => ReglasValorAprobado::DOC_MANUAL, 'valorDocumento' => '123', 'confirmado' => true, 'evidencia' => 'Evidencia suficiente aportada.']), ResolucionNoPermitida::VALOR_NO_VALIDO);   // demasiado corto
        $this->assertSame($antes, $this->escritura());
    }

    public function test_el_nombre_aprobado_vive_en_el_participante_y_la_auditoria_solo_guarda_su_huella(): void
    {
        $nombreHistorico = DB::table('cf_certificados_legado')->where('id', $this->idCert(120))->value('nombre_completo');

        $this->negado(fn () => $this->reemplazar(120, null, ['reglaNombre' => ReglasValorAprobado::NOMBRE_CONFIRMADO, 'valorNombre' => 'Persona Espacio Corregida', 'confirmado' => true, 'evidencia' => 'Documento de identidad verificado.']), ResolucionNoPermitida::VALOR_NO_VALIDO);
        $this->negado(fn () => $this->reemplazar(120, null, ['reglaNombre' => ReglasValorAprobado::NOMBRE_CONFIRMADO, 'valorNombre' => 'PERSONA ESPACIO CORREGIDA', 'confirmado' => true]), ResolucionNoPermitida::EVIDENCIA_REQUERIDA);

        $r = $this->reemplazar(120, null, ['reglaNombre' => ReglasValorAprobado::NOMBRE_CONFIRMADO, 'valorNombre' => 'PERSONA ESPACIO CORREGIDA', 'confirmado' => true, 'evidencia' => 'Documento de identidad verificado.']);

        $this->assertSame('PERSONA ESPACIO CORREGIDA', DB::table('cf_participantes')->where('id', $r['participante_id'])->value('nombre_completo'));
        $this->assertSame('PERSONA ESPACIO CORREGIDA', Emision::find($r['emision_id'])->datos_snapshot['nombre_completo']);
        $this->assertSame($nombreHistorico, DB::table('cf_certificados_legado')->where('id', $this->idCert(120))->value('nombre_completo'), 'el histórico conserva su nombre');
        $evi = json_decode(DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $this->casoDe(120))->orderByDesc('id')->value('evidencia'), true);
        $this->assertSame(['nombre_confirmado', hash('sha256', 'PERSONA ESPACIO CORREGIDA'), 25], [$evi['nombre']['regla'], $evi['nombre']['sha256'], $evi['nombre']['longitud']]);
        $this->assertStringNotContainsString('CORREGIDA', json_encode($evi));
        $this->assertStringNotContainsString('Documento de identidad', json_encode($evi), 'la evidencia manual solo deja su huella');
    }

    // ── Campos, plantilla y caracteres ──────────────────────────────────────────────────────────────────

    public function test_fecha_e_intensidad_nunca_se_inventan_y_solo_se_exigen_si_la_plantilla_las_usa(): void
    {
        $usaFecha = $this->plantillaModerna([$this->elementoDiseno(['field' => 'nombre_completo', 'y' => 200]), $this->elementoDiseno(['field' => 'documento', 'y' => 260]), $this->elementoDiseno(['field' => 'fecha', 'y' => 320])]);
        $usaHoras = $this->plantillaModerna([$this->elementoDiseno(['field' => 'nombre_completo', 'y' => 200]), $this->elementoDiseno(['field' => 'documento', 'y' => 260]), $this->elementoDiseno(['field' => 'intensidad_horaria', 'y' => 320])]);
        $antes = $this->escritura();

        $this->negado(fn () => $this->reemplazar(120, $usaFecha->id), ResolucionNoPermitida::CAMPO_SIN_VALOR);
        $this->negado(fn () => $this->reemplazar(120, $usaFecha->id, ['fecha' => '   ']), ResolucionNoPermitida::CAMPO_SIN_VALOR);
        $this->negado(fn () => $this->reemplazar(120, $usaHoras->id), ResolucionNoPermitida::CAMPO_SIN_VALOR);
        $this->negado(fn () => $this->reemplazar(120, $usaHoras->id, ['fecha' => '17 DE SEPTIEMBRE DE 2026']), ResolucionNoPermitida::CAMPO_SIN_VALOR);   // aportar el campo equivocado no sirve
        $this->assertSame($antes, $this->escritura());

        $r = $this->reemplazar(120, $usaFecha->id, ['fecha' => '17 DE SEPTIEMBRE DE 2026']);
        $this->assertSame('17 DE SEPTIEMBRE DE 2026', Emision::find($r['emision_id'])->datos_snapshot['fecha']);
        $this->assertSame('17 DE SEPTIEMBRE DE 2026', json_decode(DB::table('cf_lotes')->where('id', $r['lote_id'])->value('datos_comunes'), true)['fecha']);

        $r2 = $this->reemplazar(121, $usaHoras->id, ['intensidadHoraria' => '16 HORAS']);
        $this->assertSame(['', '16 HORAS'], [Emision::find($r2['emision_id'])->datos_snapshot['fecha'], Emision::find($r2['emision_id'])->datos_snapshot['intensidad_horaria']]);
    }

    public function test_un_evento_con_caracteres_no_imprimibles_solo_bloquea_si_la_plantilla_lo_imprime(): void
    {
        $evento = (int) $this->cert(120)->evento_id;
        DB::table('cf_eventos')->where('id', $evento)->update(['nombre' => 'EVENTO ☃ ESPECIAL']);
        $conEvento = $this->plantillaModerna([$this->elementoDiseno(['field' => 'nombre_completo', 'y' => 200]), $this->elementoDiseno(['field' => 'documento', 'y' => 260]), $this->elementoDiseno(['field' => 'evento', 'y' => 320])]);
        $antes = $this->escritura();

        $this->negado(fn () => $this->reemplazar(120, $conEvento->id), ResolucionNoPermitida::DATO_NO_IMPRIMIBLE);
        $this->assertSame($antes, $this->escritura());

        // El texto del evento ya está en la imagen y la plantilla no lo imprime: puede emitirse.
        $r = $this->reemplazar(120, $this->plantillaModerna()->id);
        $this->assertSame('EVENTO ☃ ESPECIAL', Emision::find($r['emision_id'])->datos_snapshot['evento']);
    }

    public function test_se_rechazan_plantillas_sin_diseno_corruptas_eliminadas_incompletas_o_de_otro_tipo_de_documento(): void
    {
        $antes = null;

        $sinDiseno = $this->plantillaModerna();
        $sinDiseno->update(['diseno' => null]);
        $corrupta = $this->plantillaModerna();
        Storage::disk('local')->put($corrupta->rutaPdfEsperada(), 'no es el PDF original');
        $eliminada = $this->plantillaModerna();
        $eliminada->delete();
        $sinNombre = $this->plantillaModerna([$this->elementoDiseno(['field' => 'documento', 'y' => 260])]);
        $otroTipo = $this->plantillaModerna([$this->elementoDiseno(['field' => 'nombre_completo', 'y' => 200]), $this->elementoDiseno(['field' => 'documento', 'y' => 260, 'prefix' => 'C.E. '])]);
        $noCabe = $this->plantillaModerna([$this->elementoDiseno(['field' => 'nombre_completo', 'y' => 200, 'width' => 4, 'height' => 4]), $this->elementoDiseno(['field' => 'documento', 'y' => 260])]);
        $antes = $this->escritura();

        foreach ([$sinDiseno, $corrupta, $eliminada, $sinNombre, $otroTipo] as $p) {
            $this->negado(fn () => $this->reemplazar(120, $p->id), ResolucionNoPermitida::PLANTILLA_INVALIDA);
        }
        $this->negado(fn () => $this->reemplazar(120, 999999), ResolucionNoPermitida::PLANTILLA_INVALIDA);
        $this->assertContains($this->servicio()->evaluar($this->casoDe(120), $this->solicitud(120, $noCabe->id))['bloqueos'][0]['codigo'] ?? null, [ResolucionNoPermitida::DATO_NO_IMPRIMIBLE, ResolucionNoPermitida::PLANTILLA_INVALIDA]);
        $this->assertSame($antes, $this->escritura());
    }

    // ── Caso, certificado, actor y motivo ───────────────────────────────────────────────────────────────

    public function test_el_certificado_sale_del_caso_y_solo_se_aceptan_casos_documentales(): void
    {
        $p = $this->plantillaModerna();
        $antes = $this->escritura();

        $this->negado(fn () => $this->servicio()->reemplazar($this->casoDe(120), $this->actor(), self::MOTIVO, $this->solicitud(120, $p->id, ['certificadoId' => $this->idCert(121)])), ResolucionNoPermitida::CERTIFICADO_NO_PERTENECE);
        $this->negado(fn () => $this->servicio()->reemplazar($this->casoDe(120), $this->actor(), self::MOTIVO, $this->solicitud(120, $p->id, ['certificadoId' => 99999999])), ResolucionNoPermitida::CERTIFICADO_NO_PERTENECE);
        $this->negado(fn () => $this->servicio()->reemplazar($this->casoDe(90), $this->actor(), self::MOTIVO, $this->solicitud(120, $p->id)), ResolucionNoPermitida::TIPO_NO_ADMITIDO);   // conflicto de variantes
        $this->negado(fn () => $this->servicio()->reemplazar($this->casoDe(106), $this->actor(), self::MOTIVO, $this->solicitud(120, $p->id)), ResolucionNoPermitida::EVIDENCIA_REQUERIDA);  // DOC_VACIO (10B-3C-4): solo con el documento correcto registrado como evidencia externa
        $this->assertSame($antes, $this->escritura());

        $explicito = $this->servicio()->reemplazar($this->casoDe(120), $this->actor(), self::MOTIVO, $this->solicitud(120, $p->id, ['certificadoId' => $this->idCert(120)]));
        $this->assertSame($this->idCert(120), $explicito['certificado_id']);
    }

    public function test_el_estado_del_caso_y_del_historico_se_revalida(): void
    {
        $p = $this->plantillaModerna();

        DB::table('cf_conciliaciones')->where('id', $this->casoDe(121))->update(['estado' => 'descartado']);
        $this->negado(fn () => $this->reemplazar(121, $p->id), ResolucionNoPermitida::CASO_YA_RESUELTO);

        DB::table('cf_certificados_legado')->where('id', $this->idCert(122))->update(['estado' => 'revocado']);
        $this->negado(fn () => $this->reemplazar(122, $p->id), ResolucionNoPermitida::ESTADO_HISTORICO_INVALIDO);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(122))->update(['estado' => 'vigente', 'revocado_at' => now()]);
        $this->negado(fn () => $this->reemplazar(122, $p->id), ResolucionNoPermitida::ESTADO_HISTORICO_INVALIDO);
        $this->assertSame(0, DB::table('cf_emisiones')->count());
    }

    public function test_solo_un_administrador_con_un_motivo_valido_puede_reemplazar(): void
    {
        $p = $this->plantillaModerna();
        $antes = $this->escritura();
        $caso = $this->casoDe(120);
        $s = $this->solicitud(120, $p->id);

        $this->negado(fn () => $this->servicio()->reemplazar($caso, $this->comercial()->id, self::MOTIVO, $s), ResolucionNoPermitida::ACTOR_NO_AUTORIZADO);
        $this->negado(fn () => $this->servicio()->reemplazar($caso, 987654, self::MOTIVO, $s), ResolucionNoPermitida::ACTOR_NO_AUTORIZADO);
        foreach (['', '   ', 'corto', str_repeat('x', 501)] as $m) {
            $this->negado(fn () => $this->servicio()->reemplazar($caso, $this->actor(), $m, $s), ResolucionNoPermitida::TIPO_NO_ADMITIDO);
        }
        $this->assertSame($antes, $this->escritura());

        $this->servicio()->reemplazar($caso, $this->superAdmin()->id, self::MOTIVO, $s);
        $this->assertSame($this->superAdmin()->id, (int) DB::table('cf_conciliaciones')->find($caso)->resuelto_por);
    }

    // ── Idempotencia, lote y unicidad ───────────────────────────────────────────────────────────────────

    public function test_repetir_el_reemplazo_no_crea_nada_y_avisa_que_ya_fue_reemplazado(): void
    {
        $p = $this->plantillaModerna();
        $this->reemplazar(120, $p->id);
        $firma = $this->escritura();

        $e = $this->negado(fn () => $this->reemplazar(120, $p->id), ResolucionNoPermitida::YA_REEMPLAZADO);

        $this->assertSame('Este certificado ya fue reemplazado.', $e->getMessage());
        $this->assertSame($firma, $this->escritura());
        $this->assertSame([1, 1, 1], [DB::table('cf_emisiones')->count(), DB::table('cf_participantes')->count(), DB::table('cf_lotes')->count()]);
        $this->assertSame(ReemplazoHistorico::operacion($this->idCert(120)), ReemplazoHistorico::operacion($this->idCert(120)));
        $this->assertNotSame(ReemplazoHistorico::operacion($this->idCert(120)), ReemplazoHistorico::operacion($this->idCert(121)));
        $this->assertTrue(Str::isUuid(ReemplazoHistorico::operacion(1)));
    }

    public function test_dos_historicos_del_mismo_evento_y_plantilla_reutilizan_el_lote_y_otra_plantilla_usa_otro(): void
    {
        $a = $this->plantillaModerna();
        $b = $this->plantillaModerna();

        $r1 = $this->reemplazar(120, $a->id);
        $r2 = $this->reemplazar(121, $a->id);
        $r3 = $this->reemplazar(122, $b->id);

        $this->assertSame([true, false, true], [$r1['lote_creado'], $r2['lote_creado'], $r3['lote_creado']]);
        $this->assertSame($r1['lote_id'], $r2['lote_id']);
        $this->assertNotSame($r1['lote_id'], $r3['lote_id']);
        $this->assertSame(2, DB::table('cf_participantes')->where('lote_id', $r1['lote_id'])->count());
        $this->assertSame(2, DB::table('cf_lotes')->count());
        $this->assertSame(0, DB::table('cf_eventos')->where('origen', '!=', 'legado')->count(), 'no se crea ningún evento moderno artificial');
    }

    public function test_el_indice_unico_impide_que_dos_historicos_apunten_a_la_misma_emision(): void
    {
        $r = $this->reemplazar(120);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(121))->update(['reemplazado_por_emision_id' => $r['emision_id']]);
    }

    // ── Atomicidad y archivos ───────────────────────────────────────────────────────────────────────────

    public function test_si_falla_la_escritura_del_archivo_no_queda_nada(): void
    {
        $p = $this->plantillaModerna();
        $antes = $this->escritura();
        AlmacenEmisiones::$antesDeEscribir = fn () => throw new \RuntimeException('disco lleno simulado');

        try {
            $this->reemplazar(120, $p->id);
            $this->fail('Debió fallar');
        } catch (\Throwable) {
        }
        AlmacenEmisiones::$antesDeEscribir = null;

        $this->assertSame($antes, $this->escritura());
        $this->assertSame('abierto', $this->estadoCaso($this->casoDe(120)));
        $this->assertSame('vigente', $this->cert(120)->estado);
    }

    public function test_si_falla_algo_despues_de_escribir_el_archivo_se_revierte_todo_y_se_borra_el_archivo(): void
    {
        $p = $this->plantillaModerna();
        $antes = $this->escritura();
        $archivosAntes = collect(Storage::disk('local')->allFiles())->sort()->values()->all();
        // Falla dentro de la transacción de la emisión, con el archivo ya escrito, el histórico ya enlazado y el caso ya cerrado.
        ConciliacionEvento::creating(fn () => throw new \RuntimeException('fallo simulado al auditar'));

        try {
            $this->reemplazar(120, $p->id);
            $this->fail('Debió fallar');
        } catch (\RuntimeException $e) {
            $this->assertSame('fallo simulado al auditar', $e->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ConciliacionEvento::class);
        }

        $this->assertSame($antes, $this->escritura());
        $this->assertSame($archivosAntes, collect(Storage::disk('local')->allFiles())->sort()->values()->all(), 'ningún PDF queda en disco');
        $this->assertSame(['vigente', null], [$this->cert(120)->estado, $this->cert(120)->reemplazado_por_emision_id]);
        $this->assertSame('abierto', $this->estadoCaso($this->casoDe(120)));
        $this->assertSame(0, DB::table('cf_participantes')->count());
        // Y un reintento posterior sí funciona.
        $this->assertSame('resuelto', $this->estadoCaso((int) tap($this->reemplazar(120, $p->id), fn () => null)['caso_id']));
    }

    public function test_un_archivo_huerfano_por_una_caida_se_detecta_y_no_afecta_a_las_emisiones_validas(): void
    {
        $this->reemplazar(120);
        $this->artisan('credential-flow:verificar-emisiones')->assertExitCode(0);

        // Caída entre escribir el archivo y confirmar: queda, como máximo, un PDF sin fila (inofensivo, sin enlace alguno).
        Storage::disk('local')->put('credential-flow/emisiones/ab/'.Str::uuid().'.pdf', $this->pdfContenido());

        $this->artisan('credential-flow:verificar-emisiones')->expectsOutputToContain('PDF huérfano')->assertExitCode(1);
        $this->assertSame(1, DB::table('cf_emisiones')->count());
    }

    // ── Preview interno ─────────────────────────────────────────────────────────────────────────────────

    public function test_la_previsualizacion_genera_el_pdf_sin_persistir_nada(): void
    {
        $p = $this->plantillaModerna();
        $antes = $this->escritura();

        $pdf = $this->servicio()->previsualizar($this->casoDe(120), $this->solicitud(120, $p->id));

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame($antes, $this->escritura(), 'ni emisión, ni participante, ni lote, ni código, ni archivo');
        $this->negado(fn () => $this->servicio()->previsualizar($this->casoDe(123), $this->solicitud(123, $p->id, ['evidencia' => null])), ResolucionNoPermitida::EVIDENCIA_REQUERIDA);
    }

    public function test_evaluar_informa_sin_escribir_y_sin_valores_en_claro(): void
    {
        $p = $this->plantillaModerna();
        $antes = $this->escritura();

        $ok = $this->servicio()->evaluar($this->casoDe(121), $this->solicitud(121, $p->id));
        $no = $this->servicio()->evaluar($this->casoDe(123), $this->solicitud(123, $p->id, ['reglaDocumento' => ReglasValorAprobado::DOC_SIN_NBSP]));

        $this->assertTrue($ok['aplicable']);
        $this->assertSame(['nombre_completo', 'documento'], $ok['campos_usados']);
        $this->assertSame([false, ResolucionNoPermitida::REGLA_NO_APLICA], [$no['aplicable'], $no['bloqueos'][0]['codigo']]);
        $this->assertSame([ReglasValorAprobado::DOC_MANUAL], $no['reglas_documento']);
        foreach ([$ok, $no] as $r) {
            $this->assertStringNotContainsString('83.000.002', json_encode($r));
            $this->assertStringNotContainsString('PERSONA', json_encode($r));
        }
        $this->assertSame($antes, $this->escritura());
    }

    // ── Cadena de reemplazo y revocación ────────────────────────────────────────────────────────────────

    public function test_la_cadena_historico_a_b_la_decide_la_cadena_moderna_y_el_historico_no_se_reapunta(): void
    {
        $r = $this->reemplazar(120);
        $a = Emision::findOrFail($r['emision_id']);

        $this->assertSame([[$a->id], $a->id], [EmisionVigente::paraCertificado($this->idCert(120))['cadena'], EmisionVigente::paraCertificado($this->idCert(120))['vigente']->id]);

        $b = app(EmisorCredencial::class)->reemitir($a, 'Reemisión de prueba de la cadena.', $this->actor());

        $res = EmisionVigente::paraCertificado($this->idCert(120));
        $this->assertSame([$a->id, $b->id], $res['cadena']);
        $this->assertSame($b->id, $res['vigente']->id);
        $this->assertSame($b->id, $res['ultima']->id);
        $this->assertSame($a->id, (int) $this->cert(120)->reemplazado_por_emision_id, 'el histórico sigue apuntando a A: la cadena moderna decide');
        $this->assertSame('reemplazado', $this->cert(120)->estado);
    }

    public function test_revocar_la_emision_moderna_no_devuelve_el_historico_a_vigente(): void
    {
        $r = $this->reemplazar(120);
        $a = Emision::findOrFail($r['emision_id']);

        app(EmisorCredencial::class)->revocar($a, 'Revocación de prueba de la emisión.', $this->actor());

        $res = EmisionVigente::paraCertificado($this->idCert(120));
        $this->assertSame([$a->id, null], [$res['ultima']->id, $res['vigente']]);
        $this->assertSame(['reemplazado', $a->id, 'resuelto'], [$this->cert(120)->estado, (int) $this->cert(120)->reemplazado_por_emision_id, $this->estadoCaso($this->casoDe(120))]);
        $this->negado(fn () => $this->reemplazar(120), ResolucionNoPermitida::YA_REEMPLAZADO);
    }

    public function test_el_recorrido_de_la_cadena_no_cae_en_ciclos_y_un_certificado_sin_reemplazo_no_tiene_cadena(): void
    {
        $this->assertNull(EmisionVigente::paraCertificado($this->idCert(120)));
        $this->assertNull(EmisionVigente::paraCertificado(CertificadoLegado::findOrFail($this->idCert(120))));
        $this->assertSame(['ultima' => null, 'vigente' => null, 'cadena' => [], 'anomalia' => EmisionVigente::ANOMALIA_FALTANTE, 'estado' => EmisionVigente::ESTADO_ANOMALIA], EmisionVigente::desdeEmision(99999999));

        $r = $this->reemplazar(120);
        $a = Emision::findOrFail($r['emision_id']);
        $b = app(EmisorCredencial::class)->reemitir($a, 'Reemisión de prueba de la cadena.', $this->actor());
        // Corrupción forzada: A «reemplaza» a B y B a A.
        DB::table('cf_emisiones')->where('id', $a->id)->update(['reemplaza_id' => $b->id]);

        $res = EmisionVigente::desdeEmision($a);

        $this->assertSame([$a->id, $b->id], $res['cadena'], 'se detiene al volver a un nodo visitado');
    }

    public function test_el_codigo_historico_y_el_moderno_son_independientes(): void
    {
        $r = $this->reemplazar(120);

        $this->assertSame(0, DB::table('cf_codigos_historicos')->count());
        $this->assertNull($this->cert(120)->codigo_legado);
        $this->assertSame(0, DB::table('cf_certificados_legado')->where('codigo_legado', Emision::find($r['emision_id'])->codigo)->count());
    }

    // ── Interfaz (10B-2B-2B): elegibilidad, puerta del diseño y frescura de la vista previa ─────────────────────

    public function test_el_servicio_exige_el_diseno_confirmado_del_clon_pero_permite_la_vista_previa_antes(): void
    {
        $plantilla = $this->creadorClon()->paraCertificado(CertificadoLegado::findOrFail($this->idCert(120)))['plantilla'];   // sin confirmar
        $s = $this->solicitud(120, $plantilla->id);
        $antes = $this->escritura();

        $this->negado(fn () => $this->servicio()->reemplazar($this->casoDe(120), $this->actor(), self::MOTIVO, $s), ResolucionNoPermitida::DISENO_NO_CONFIRMADO);
        $this->assertStringStartsWith('%PDF', $this->servicio()->previsualizar($this->casoDe(120), $s), 'ver cómo queda es necesario para poder confirmar el diseño');
        $this->assertSame($antes, $this->escritura());

        CreadorPlantillaReemplazo::confirmarDiseno($plantilla, $this->actor());
        $this->assertTrue(CreadorPlantillaReemplazo::disenoConfirmado($plantilla->fresh()));
        $this->assertSame('resuelto', $this->estadoCaso((int) $this->servicio()->reemplazar($this->casoDe(120), $this->actor(), self::MOTIVO, $s)['caso_id']));
    }

    public function test_confirmar_el_diseno_solo_vale_para_un_clon_con_nombre_y_documento(): void
    {
        $normal = $this->plantillaModerna();
        $this->negado(fn () => CreadorPlantillaReemplazo::confirmarDiseno($normal, $this->actor()), ResolucionNoPermitida::PLANTILLA_INVALIDA);

        $clon = $this->creadorClon()->paraCertificado(CertificadoLegado::findOrFail($this->idCert(120)))['plantilla'];
        $d = $clon->diseno;
        $d['elements'] = array_values(array_filter($d['elements'], fn ($e) => ($e['field'] ?? null) !== 'documento'));
        $clon->update(['diseno' => $d]);
        $this->negado(fn () => CreadorPlantillaReemplazo::confirmarDiseno($clon->fresh(), $this->actor()), ResolucionNoPermitida::PLANTILLA_INVALIDA);
    }

    public function test_la_huella_de_la_vista_previa_es_estable_y_el_servicio_la_revalida_al_emitir(): void
    {
        $clon = $this->clon(120);
        $s = $this->solicitud(120, $clon->id);
        $caso = $this->casoDe(120);

        $h = $this->servicio()->huellaDe($caso, $s);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h);
        $this->assertSame($h, $this->servicio()->huellaDe($caso, $s));
        $this->assertNotSame($h, $this->servicio()->huellaDe($caso, $this->solicitud(120, $clon->id, ['reglaDocumento' => ReglasValorAprobado::DOC_MANUAL, 'valorDocumento' => '8300001', 'confirmado' => true, 'evidencia' => 'Evidencia suficiente aportada.'])));
        $antes = $this->escritura();
        $this->negado(fn () => $this->servicio()->reemplazar($caso, $this->actor(), self::MOTIVO, $s, str_repeat('0', 64)), ResolucionNoPermitida::PREVIEW_DESACTUALIZADO);
        $this->assertSame($antes, $this->escritura(), 'una vista previa vieja no emite nada');
        $this->servicio()->reemplazar($caso, $this->actor(), self::MOTIVO, $s, $h);
        $this->assertSame('reemplazado', $this->cert(120)->estado);
    }

    public function test_elegible_solo_para_los_documentales_abiertos_con_el_historico_vigente(): void
    {
        $e = fn (int $old) => $this->servicio()->elegible(Conciliacion::findOrFail($this->casoDe($old)));

        foreach ([120, 121, 122, 123] as $old) {
            $this->assertSame([true, null], [$e($old)['elegible'], $e($old)['codigo']], (string) $old);
        }
        foreach ([106, 108, 90, 102] as $old) {
            $this->assertFalse($e($old)['elegible'], (string) $old);   // nombres reales, DOC_VACIO, DOC_LETRAS de texto, consolidaciones
        }
        $this->reemplazar(120);
        $r = $e(120);
        $this->assertSame([false, ResolucionNoPermitida::YA_REEMPLAZADO, 'Este certificado ya fue reemplazado.'], [$r['elegible'], $r['codigo'], $r['motivo']], 'quien llega segundo ve «ya reemplazado», no «caso resuelto»');
        DB::table('cf_conciliaciones')->where('id', $this->casoDe(121))->update(['estado' => 'descartado']);
        $this->assertSame(ResolucionNoPermitida::CASO_YA_RESUELTO, $e(121)['codigo']);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(122))->update(['estado' => 'revocado']);
        $this->assertSame(ResolucionNoPermitida::ESTADO_HISTORICO_INVALIDO, $e(122)['codigo']);
    }
}
