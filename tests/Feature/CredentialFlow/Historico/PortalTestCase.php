<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Mail\CodigoAccesoMail;
use App\Support\CredentialFlow\Legado\ResolutorPlantillaDirectorio;
use App\Support\CredentialFlow\Legado\ResolutorPlantillaLegado;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Feature\CredentialFlow\EmisionesTestCase;
use Tests\Feature\CredentialFlow\Historico\Concerns\MigraHistoricoSintetico;
use Tests\Feature\CredentialFlow\StagingEv\FixturesStagingEv;

/**
 * Base del portal público (Fase 7): histórico SINTÉTICO migrado de verdad y correo SIEMPRE falso (Mail::fake).
 *
 * Documentos: 1000001 (Ana: 2 correos válidos; evento 1 ok descargable + eventos 2/4/5/6 en revisión, plantilla pendiente, revocado,
 * reemplazado) · 2000001 (Gina: duplicado idéntico ok) · 2000002 (Hugo: un conflictivo + una fila ok sin plantilla) · ABC123 (solo
 * `revision_documento`) · 1000002 (correo inválido) · 1000003 (sin correo).
 */
abstract class PortalTestCase extends EmisionesTestCase
{
    use FixturesStagingEv, MigraHistoricoSintetico;

    public const CORREO2 = 'ana.dos@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararHistorico();
        Mail::fake();

        $p = fn (int $id, int $evento, ?string $doc, string $nombre, ?string $correo, ?int $verif = null) => [
            'id' => $id, 'tipo_documento' => 'CC', 'documento' => $doc, 'nombre' => $nombre, 'correo' => $correo, 'id_evento' => $evento, 'num_verificacion' => $verif,
        ];
        $d = $this->datos();
        foreach ([1 => 5237, 7 => 5300, 8 => 5300] as $id => $codigo) {
            $d = $this->conCambio($d, 'participante', $id, ['num_verificacion' => $codigo]);
        }
        $d = $this->conCambio($d, 'participante', 1, ['correo' => self::CORREO.';'.self::CORREO2]);
        $d['participante'][] = $p(16, 2, self::DOCUMENTO, self::NOMBRE, self::CORREO, 6001);
        $d['participante'][] = $p(17, 4, self::DOCUMENTO, self::NOMBRE, self::CORREO, 6002);
        $d['participante'][] = $p(18, 5, self::DOCUMENTO, self::NOMBRE, self::CORREO, 6003);
        $d['participante'][] = $p(19, 6, self::DOCUMENTO, self::NOMBRE, self::CORREO, 6004);
        $d['participante'][] = $p(24, 2, '2000002', 'HUGO NUEVE', 'hugo@example.test', 6005);
        // La variante conflictiva de Hugo tiene SU propio correo (si compartiera correo con el otro grupo, el correo cruzaría grupos y no habría OTP).
        $d = $this->conCambio($d, 'participante', 10, ['correo' => 'hugo.x@example.test']);

        // Documentos MULTI-GRUPO (política de privacidad multi-identidad).
        // 3000001: María (3 filas: con correo, sin correo y con otro correo) y Mario (2 filas) · 3000002: un correo compartido por dos grupos
        // 3000003: un grupo sin correo · 3000004: duplicado conflictivo que cruza grupos · 3000005: duplicado consolidado que cruza grupos.
        foreach ([
            [30, 1, '3000001', 'MARIA PEREZ', 'm1@example.test'], [31, 2, '3000001', 'María  Pérez', null], [32, 3, '3000001', 'MARIA PEREZ.', 'm2@example.test'],
            [33, 4, '3000001', 'MARIO LOPEZ', 'l1@example.test'], [34, 5, '3000001', 'MARIO LOPEZ', 'l2@example.test'],
            [40, 1, '3000002', 'MARIA GOMEZ', 'x@example.test'], [41, 2, '3000002', 'MARIO GOMEZ', 'x@example.test'],
            [50, 1, '3000003', 'ANA RUIZ', 'ar@example.test'], [51, 2, '3000003', 'ANA RUIZ X', null],
            [60, 1, '3000004', 'LUZ DIAZ', 'ld@example.test'], [61, 1, '3000004', 'LUZ DIAZ X', 'ldx@example.test'], [62, 2, '3000004', 'LUZ DIAZ', 'ld@example.test'],
            [70, 1, '3000005', 'PAZ SOL', 'ps@example.test'], [71, 1, '3000005', 'PAZ SOL', 'ps@example.test'],
        ] as [$id, $ev, $doc, $nombre, $correo]) {
            $d['participante'][] = $p($id, $ev, $doc, $nombre, $correo, $id === 71 ? 7070 : 7000 + $id);
        }
        $this->migrarSintetico($d);

        // Estados de prueba sobre filas ya migradas.
        DB::table('cf_certificados_legado')->where('id', $this->idCert(17))->update(['conciliacion_estado' => 'revision_documento']);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(18))->update(['estado' => 'revocado']);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(19))->update(['estado' => 'reemplazado']);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(24))->update(['conciliacion_estado' => 'ok']);
        // Las filas multi-grupo de eventos sin plantilla se marcan conciliadas (habilitantes) para probar el alcance; solo la de Alfa se descarga.
        foreach ([30, 31, 32, 33, 34, 40, 41, 50, 51, 62] as $o) {
            DB::table('cf_certificados_legado')->where('id', $this->idCert($o))->update(['conciliacion_estado' => 'ok']);
        }
        // 3000005: la copia consolidada pasa a OTRO grupo de nombre (con su propio correo): su canónico queda fuera de ese grupo.
        DB::table('cf_certificados_legado')->where('id', $this->idCert(71))->update(['nombre_completo' => 'OTRO SOL']);
        DB::table('cf_correos')->where('certificado_legado_id', $this->idCert(71))->delete();
        DB::table('cf_correos')->insert(['certificado_legado_id' => $this->idCert(71), 'correo' => 'os@example.test', 'correo_normalizado' => 'os@example.test', 'estado' => 'valido', 'orden' => 1, 'es_principal' => true, 'origen' => 'legado', 'created_at' => now(), 'updated_at' => now()]);

        $dir = $this->dirImagenes;
        $this->app->bind(ResolutorPlantillaLegado::class, fn () => new ResolutorPlantillaDirectorio($dir));
    }

    protected function solicitar(string $documento, string $correo): TestResponse
    {
        return $this->post(route('portal.solicitar'), ['documento' => $documento, 'correo' => $correo]);
    }

    /** Último código enviado por el correo falso (o null si no se envió ninguno). */
    protected function ultimoCodigo(): ?string
    {
        $enviados = Mail::sent(CodigoAccesoMail::class);

        return $enviados->isEmpty() ? null : $enviados->last()->codigo;
    }

    protected function validarCodigo(string $codigo): TestResponse
    {
        return $this->post(route('portal.validar'), ['codigo' => $codigo]);
    }

    /** Documento + correo + OTP correcto: deja la sesión pública abierta. */
    protected function entrar(string $documento = '1.000.001', string $correo = self::CORREO): TestResponse
    {
        $this->solicitar($documento, $correo)->assertRedirect(route('portal.codigo'));
        $codigo = $this->ultimoCodigo();
        $this->assertNotNull($codigo, 'Se esperaba un OTP');

        return $this->validarCodigo($codigo);
    }

    protected function normalizar(TestResponse $r): array
    {
        $h = $r->headers->all();
        unset($h['date'], $h['x-ratelimit-remaining'], $h['x-ratelimit-limit'], $h['set-cookie']);
        $h['content-security-policy'] = preg_replace("/'nonce-[^']+'/", "'nonce-N'", $h['content-security-policy'] ?? []);

        return [preg_replace(['/nonce="[^"]+"/', '/name="_token" value="[^"]+"/'], ['nonce="N"', 'name="_token" value="T"'], $r->getContent()), $h, $r->getStatusCode()];
    }
}
