<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\ConsolidacionVariantes;
use App\Support\CredentialFlow\Conciliaciones\DetectorConciliaciones;
use App\Support\CredentialFlow\Conciliaciones\GestionCaso;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CredentialFlow\Historico\HistoricoTestCase;

/**
 * Base de la Fase 10B-2B-1: histórico SINTÉTICO migrado de verdad y casos creados por el DETECTOR real. Evento 1 = plantilla ok.
 *
 * DIF_CORREO: A 90+91 (sin código) · B 92+93 (ambas con el código 6201; descarga SOLO en 93) · C 94–97 (cuatro variantes, correos solapados) ·
 *             D 98+99 (correo válido frente a inválido) · E 100+101 (cambian correo Y nombre: NO es solo correo).
 * DIF_NOMBRE: cosmético 102+103 (tildes y mayúsculas) · real 104+105 (mismo correo, uno contiene al otro).
 * Revisión de documento: 106 DOC_VACIO con nombre · 107 DOC_VACIO sin nombre ni correo · 108 DOC_LETRAS texto · 109 DOC_LETRAS alfanumérico ·
 *             110 DOC_WHITESPACE (NBSP inicial) · y los de la base (separadores `73.156.827`, `ABC123`, vacíos con nombre).
 */
abstract class VariantesTestCase extends HistoricoTestCase
{
    protected const MOTIVO = 'Decisión administrativa de prueba.';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $p = fn (int $id, ?string $doc, ?string $nombre, ?string $correo, ?int $verif = null) => [
            'id' => $id, 'tipo_documento' => 'CC', 'documento' => $doc, 'nombre' => $nombre, 'correo' => $correo, 'id_evento' => 1, 'num_verificacion' => $verif,
        ];
        $d = $this->datos();
        foreach ([
            $p(90, '8200001', 'PAR CORREO A', 'a1@example.test'), $p(91, '8200001', 'PAR CORREO A', 'a2@example.test'),
            $p(92, '8200002', 'PAR CORREO B', 'b1@example.test', 6201), $p(93, '8200002', 'PAR CORREO B', 'b2@example.test', 6201),
            $p(94, '8200003', 'PAR CUATRO', 'c1@example.test'), $p(95, '8200003', 'PAR CUATRO', 'c2@example.test'), $p(96, '8200003', 'PAR CUATRO', 'c1@example.test;c3@example.test'), $p(97, '8200003', 'PAR CUATRO', 'c4@example.test'),
            $p(98, '8200004', 'PAR VALIDO INVALIDO', 'd1@example.test'), $p(99, '8200004', 'PAR VALIDO INVALIDO', 'correo-invalido'),
            $p(100, '8200005', 'PAR NOMBRE X', 'e1@example.test'), $p(101, '8200005', 'PAR NOMBRE Y', 'e2@example.test'),
            $p(102, '8200006', 'María Pérez Cosmética', 'f1@example.test'), $p(103, '8200006', 'MARIA PEREZ COSMETICA', 'f1@example.test'),
            $p(104, '8200007', 'ANA LUZ UNO', 'g1@example.test'), $p(105, '8200007', 'ANA LUZ UNO DOS', 'g1@example.test'),
            $p(106, null, 'SIN DOCUMENTO CON NOMBRE', 'h1@example.test'), $p(107, null, null, null),
            $p(108, 'NO TIENE CEDULA', 'DOCUMENTO TEXTO', 'i1@example.test'), $p(109, '123A456', 'DOCUMENTO ALFANUMERICO', 'j1@example.test'), $p(110, "\u{00A0}8200010", 'DOCUMENTO NBSP', 'k1@example.test'),
        ] as $fila) {
            $d['participante'][] = $fila;
        }
        foreach ($this->participantesExtra($p) as $fila) {
            $d['participante'][] = $fila;
        }
        $d['descargas'][] = ['id' => 30, 'fecha' => '2026-01-10 10:00:00', 'id_evento' => 1, 'id_participante' => 93];
        $this->migrarSintetico($d);
        app(DetectorConciliaciones::class)->ejecutar();
    }

    /**
     * Gancho para clases derivadas: participantes sintéticos adicionales (mismo formato que el fixture base).
     *
     * @param  \Closure  $p  fabrica la fila: (id, documento, nombre, correo, num_verificacion = null)
     * @return list<array<string,mixed>>
     */
    protected function participantesExtra(\Closure $p): array
    {
        return [];
    }

    protected function variantes(): ConsolidacionVariantes
    {
        return app(ConsolidacionVariantes::class);
    }

    protected function gestion(): GestionCaso
    {
        return app(GestionCaso::class);
    }

    protected function actor(): int
    {
        return $this->admin()->id;
    }

    protected function cert(int $old): object
    {
        return DB::table('cf_certificados_legado')->where('id', $this->idCert($old))->first();
    }

    /** Id del caso que contiene al participante `$old` (creado por el detector real). */
    protected function casoDe(int $old): int
    {
        return (int) DB::table('cf_conciliaciones_certificados')->where('certificado_legado_id', $this->idCert($old))->value('conciliacion_id');
    }

    protected function estadoCaso(int $caso): string
    {
        return (string) DB::table('cf_conciliaciones')->find($caso)->estado;
    }

    /** Lo histórico que estas acciones NO pueden tocar. */
    protected function evidencia(): string
    {
        return md5(json_encode([
            DB::table('cf_descargas')->orderBy('id')->get()->all(), DB::table('cf_correos')->orderBy('id')->get()->all(),
            DB::table('cf_certificados_legado')->orderBy('id')->get()->map(fn ($c) => collect((array) $c)->only(['id', 'evento_id', 'nombre_completo', 'documento', 'documento_clave', 'tipo_documento', 'correo', 'codigo_legado', 'snapshot_legado', 'grupo_duplicado', 'plantilla_legado_id'])->all())->all(),
            DB::table('cf_migraciones_map')->where('origen_tabla', '!=', 'conciliacion')->orderBy('id')->get()->all(), DB::table('cf_codigos_historicos')->get()->all(), DB::table('cf_codigo_historico_contador')->get()->all(),
        ]));
    }

    protected function estados(): string
    {
        return md5(json_encode(DB::table('cf_certificados_legado')->orderBy('id')->pluck('conciliacion_estado', 'id')->all()));
    }

    protected function firmaCaso(int $id): string
    {
        return md5(json_encode([DB::table('cf_conciliaciones')->find($id), DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $id)->get()->all(), DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $id)->get()->all(), $this->estados(), DB::table('cf_migraciones_map')->count()]));
    }

    protected function esCaso(int $id, string $tipo): bool
    {
        return DB::table('cf_conciliaciones')->where('id', $id)->where('tipo', $tipo)->exists() && Conciliacion::find($id) !== null;
    }
}
