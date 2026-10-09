<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\GestionCaso;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use Illuminate\Support\Facades\DB;

/** «Marcar requiere soporte» y «Descartar caso» (Fase 10B-2B-1): solo cambian el caso. */
class GestionCasoTest extends VariantesTestCase
{
    private function accion(int $old): ?string
    {
        return $this->gestion()->evaluar(Conciliacion::find($this->casoDe($old)))['accion'];
    }

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

    public function test_cada_caso_recibe_solo_la_accion_que_le_corresponde(): void
    {
        $this->assertSame(GestionCaso::SOPORTE, $this->accion(104));    // nombres realmente distintos
        $this->assertSame(GestionCaso::SOPORTE, $this->accion(106));    // documento vacío con nombre
        $this->assertSame(GestionCaso::DESCARTE, $this->accion(107));   // vacío sin nombre ni correo
        $this->assertSame(GestionCaso::SOPORTE, $this->accion(108));    // texto no identificador
        // Confirmables o consolidables: quedan para otras acciones / 10B-2B-2.
        foreach ([109, 110, 90, 102] as $old) {
            $this->assertNull($this->accion($old), (string) $old);
        }
    }

    public function test_soporte_cambia_solo_el_caso_y_deja_los_certificados_intactos(): void
    {
        $caso = $this->casoDe(106);
        $evidencia = $this->evidencia();
        $estados = $this->estados();
        $mapa = DB::table('cf_migraciones_map')->count();

        $this->gestion()->marcarRequiereSoporte($caso, $this->actor(), self::MOTIVO);

        $f = DB::table('cf_conciliaciones')->find($caso);
        $this->assertSame('requiere_soporte', $f->estado);
        $this->assertSame($evidencia, $this->evidencia());
        $this->assertSame($estados, $this->estados());
        $this->assertSame($mapa, DB::table('cf_migraciones_map')->count());
        $e = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->orderByDesc('id')->first();
        $this->assertSame([GestionCaso::ACCION_SOPORTE, 'abierto', 'requiere_soporte', self::MOTIVO], [$e->accion, $e->estado_anterior, $e->estado_nuevo, $e->motivo]);
        $this->assertStringNotContainsString('8200', (string) $e->evidencia);
    }

    public function test_descartar_no_borra_nada_y_marca_el_caso_descartado(): void
    {
        $caso = $this->casoDe(107);
        $evidencia = $this->evidencia();
        $certs = DB::table('cf_certificados_legado')->count();

        $this->gestion()->descartar($caso, $this->actor(), self::MOTIVO);

        $f = DB::table('cf_conciliaciones')->find($caso);
        $this->assertSame(['descartado', GestionCaso::RESOLUCION_DESCARTE], [$f->estado, $f->resolucion]);
        $this->assertSame($evidencia, $this->evidencia());
        $this->assertSame($certs, DB::table('cf_certificados_legado')->count());
        $this->assertSame(GestionCaso::ACCION_DESCARTE, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->orderByDesc('id')->value('accion'));
    }

    public function test_las_acciones_no_se_aplican_a_casos_de_otra_categoria(): void
    {
        $antes = $this->evidencia();
        // Descartar un caso con nombre; soporte a uno confirmable o consolidable; descartar uno consolidable.
        $this->negado(fn () => $this->gestion()->descartar($this->casoDe(106), $this->actor(), self::MOTIVO), ResolucionNoPermitida::TIPO_NO_ADMITIDO);
        foreach ([109, 110, 90, 102] as $old) {
            $this->negado(fn () => $this->gestion()->marcarRequiereSoporte($this->casoDe($old), $this->actor(), self::MOTIVO), ResolucionNoPermitida::TIPO_NO_ADMITIDO);
        }
        $this->negado(fn () => $this->gestion()->descartar($this->casoDe(104), $this->actor(), self::MOTIVO), ResolucionNoPermitida::TIPO_NO_ADMITIDO);
        $this->assertSame($antes, $this->evidencia());
        $this->assertSame(0, DB::table('cf_conciliaciones')->where('estado', '!=', 'abierto')->count());
    }

    public function test_es_idempotente_y_exige_motivo(): void
    {
        $caso = $this->casoDe(104);
        foreach (['', 'corto'] as $m) {
            $this->negado(fn () => $this->gestion()->marcarRequiereSoporte($caso, $this->actor(), $m), ResolucionNoPermitida::TIPO_NO_ADMITIDO);
        }
        $this->gestion()->marcarRequiereSoporte($caso, $this->actor(), self::MOTIVO);
        $firma = $this->firmaCaso($caso);

        $e = $this->negado(fn () => $this->gestion()->marcarRequiereSoporte($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::CASO_YA_RESUELTO);

        $this->assertSame('Este caso ya fue resuelto.', $e->getMessage());
        $this->assertSame($firma, $this->firmaCaso($caso));
    }

    public function test_reabrir_devuelve_el_caso_a_abierto_con_evento_nuevo_y_conserva_el_historial(): void
    {
        $caso = $this->casoDe(107);
        $this->negado(fn () => $this->gestion()->reabrir($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::NO_REVERSIBLE);
        $this->gestion()->descartar($caso, $this->actor(), self::MOTIVO);

        $this->gestion()->reabrir($caso, $this->actor(), 'Reabro para revisarlo de nuevo.');

        $f = DB::table('cf_conciliaciones')->find($caso);
        $this->assertSame(['abierto', null], [$f->estado, $f->resolucion]);
        $this->assertSame(['detectado', GestionCaso::ACCION_DESCARTE, GestionCaso::ACCION_REABIERTA], DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->orderBy('id')->pluck('accion')->all());
        // Una decisión posterior distinta (p. ej. consolidación) no se reabre con esta vía.
        $otro = $this->casoDe(90);
        $this->variantes()->consolidar($otro, $this->actor(), self::MOTIVO, 'correo');
        $this->negado(fn () => $this->gestion()->reabrir($otro, $this->actor(), self::MOTIVO), ResolucionNoPermitida::NO_REVERSIBLE);
    }

    public function test_los_trece_documentales_confirmables_quedan_abiertos_y_sin_accion(): void
    {
        foreach ([109, 110] as $old) {
            $this->assertNull($this->accion($old));
            $this->assertSame('abierto', $this->estadoCaso($this->casoDe($old)));
        }
    }
}
