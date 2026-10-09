<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\ConsolidacionCodigo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CredentialFlow\Historico\HistoricoTestCase;

/**
 * Base de la Fase 10B-2A: histórico SINTÉTICO con pares de variantes que difieren (o no) solo en el código. Los casos de DIF_VERIF se crean a
 * mano, igual que los dejó el detector de 10A antes de 10B-1.5 (el detector actual ya no los crea).
 *
 * Pares (evento 1 = plantilla ok): A 70(cód. 6101)+71(NULL) con descarga en 70 · B 72(6102)+73(NULL) con descarga en 72 ·
 * C 74+75 distinto correo (DIF_CORREO) · D 76(6103)+77(NULL) con nombre distinto (código Y nombre) · E 78(6104)+79(NULL) con descarga en AMBAS ·
 * F 80(6105)+81(NULL) sin descargas · y los grupos de nombre y tipo de la base (Hugo, Julia).
 */
abstract class ConsolidacionTestCase extends HistoricoTestCase
{
    protected const MOTIVO = 'La diferencia es solo la asignación tardía del código.';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $p = fn (int $id, string $doc, string $nombre, ?int $verif, ?string $correo = null) => [
            'id' => $id, 'tipo_documento' => 'CC', 'documento' => $doc, 'nombre' => $nombre, 'correo' => $correo ?? "d{$doc}@example.test", 'id_evento' => 1, 'num_verificacion' => $verif,
        ];
        $d = $this->datos();
        foreach ([
            $p(70, '8100001', 'PAR ALFA', 6101), $p(71, '8100001', 'PAR ALFA', null),
            $p(72, '8100002', 'PAR BETA', 6102), $p(73, '8100002', 'PAR BETA', null),
            $p(74, '8100003', 'PAR CORREO', null, 'uno@example.test'), $p(75, '8100003', 'PAR CORREO', null, 'dos@example.test'),
            $p(76, '8100004', 'PAR NOMBRE UNO', 6103), $p(77, '8100004', 'PAR NOMBRE DOS', null),
            $p(78, '8100005', 'PAR EVIDENCIA', 6104), $p(79, '8100005', 'PAR EVIDENCIA', null),
            $p(80, '8100006', 'PAR SIN DESCARGA', 6105), $p(81, '8100006', 'PAR SIN DESCARGA', null),
        ] as $fila) {
            $d['participante'][] = $fila;
        }
        foreach ([[20, 70], [21, 72], [22, 78], [23, 79]] as [$id, $part]) {
            $d['descargas'][] = ['id' => $id, 'fecha' => '2026-01-10 10:00:00', 'id_evento' => 1, 'id_participante' => $part];
        }
        $this->migrarSintetico($d);
    }

    /** Crea el caso de conflicto de un grupo como lo hacía el detector antes de 10B-1.5. @return int id del caso */
    protected function caso(int $oldCert): int
    {
        $grupo = (string) DB::table('cf_certificados_legado')->where('id', $this->idCert($oldCert))->value('grupo_duplicado');
        $etiquetas = (string) (json_decode((string) DB::table('cf_certificados_legado')->where('id', $this->idCert($oldCert))->value('snapshot_legado'), true)['duplicado']['etiquetas'] ?? '');
        $id = DB::table('cf_conciliaciones')->insertGetId([
            'tipo' => Conciliacion::TIPO_CONFLICTO_VARIANTES, 'estado' => 'abierto', 'evento_id' => (int) DB::table('cf_certificados_legado')->where('grupo_duplicado', $grupo)->value('evento_id'), 'referencia_tipo' => 'grupo_duplicado',
            'referencia_clave' => $grupo, 'motivo_origen' => $etiquetas, 'clave_idempotencia' => 'conflicto_variantes:grupo_duplicado:'.$grupo, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (DB::table('cf_certificados_legado')->where('grupo_duplicado', $grupo)->pluck('id') as $cert) {
            DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $id, 'certificado_legado_id' => $cert, 'rol' => 'variante', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('cf_conciliaciones_eventos')->insert(['conciliacion_id' => $id, 'accion' => 'detectado', 'created_at' => now()]);

        return (int) $id;
    }

    protected function servicio(): ConsolidacionCodigo
    {
        return app(ConsolidacionCodigo::class);
    }

    protected function actor(): int
    {
        return $this->admin()->id;
    }

    protected function cert(int $old): object
    {
        return DB::table('cf_certificados_legado')->where('id', $this->idCert($old))->first();
    }

    /** Lo histórico que la consolidación NO puede tocar (descargas, correos, snapshots, código legado y el mapa ORIGINAL). */
    protected function evidencia(): string
    {
        return md5(json_encode([
            DB::table('cf_descargas')->orderBy('id')->get()->all(), DB::table('cf_correos')->orderBy('id')->get()->all(),
            DB::table('cf_certificados_legado')->orderBy('id')->get()->map(fn ($c) => collect((array) $c)->only(['id', 'evento_id', 'nombre_completo', 'documento', 'documento_clave', 'tipo_documento', 'correo', 'codigo_legado', 'snapshot_legado', 'grupo_duplicado', 'plantilla_legado_id'])->all())->all(),
            DB::table('cf_migraciones_map')->where('origen_tabla', '!=', 'conciliacion')->orderBy('id')->get()->all(), DB::table('cf_codigos_historicos')->get()->all(),
            DB::table('cf_codigo_historico_contador')->get()->all(),
        ]));
    }

    protected function firmaCaso(int $id): string
    {
        return md5(json_encode([DB::table('cf_conciliaciones')->find($id), DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $id)->get()->all(), DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $id)->get()->all(),
            DB::table('cf_certificados_legado')->orderBy('id')->pluck('conciliacion_estado', 'id')->all(), DB::table('cf_migraciones_map')->count()]));
    }
}
