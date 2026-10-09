<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Plantilla;
use App\Services\CredentialFlow\PlantillaService;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Legado\ResolutorPlantillaDirectorio;
use App\Support\CredentialFlow\Reemplazo\CreadorPlantillaReemplazo;
use App\Support\CredentialFlow\Reemplazo\ReemplazoHistorico;
use App\Support\CredentialFlow\Reemplazo\ReglasValorAprobado;
use App\Support\CredentialFlow\Reemplazo\SolicitudReemplazo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CredentialFlow\Support\PdfBase;

/**
 * Base de la Fase 10B-2B-2A: el histórico SINTÉTICO de VariantesTestCase más cuatro documentales y uno sin diferencia, todos del evento 1
 * (plantilla histórica ok), creados por el DETECTOR real.
 *
 *   120 DOC_WHITESPACE (espacio de no separación inicial) · 121 DOC_SEPARADORES (puntos) · 122 DOC_OTRO (guion) · 123 DOC_LETRAS alfanumérico ·
 *   124 documento normal (sin caso de documento).
 */
abstract class ReemplazoTestCase extends VariantesTestCase
{
    protected int $nElemento = 0;

    protected function participantesExtra(\Closure $p): array
    {
        return [
            $p(120, "\u{00A0}8300001", 'PERSONA ESPACIO', 'r1@example.test'),
            $p(121, '83.000.002', 'PERSONA PUNTOS', 'r2@example.test'),
            $p(122, '8300-0003', 'PERSONA GUION', 'r3@example.test'),
            $p(123, '83A00004', 'PERSONA ALFA', 'r4@example.test'),
            $p(124, '8300005', 'PERSONA NORMAL', 'r5@example.test'),
        ];
    }

    protected function tearDown(): void
    {
        AlmacenEmisiones::$antesDeEscribir = null;

        parent::tearDown();
    }

    protected function servicio(): ReemplazoHistorico
    {
        return app(ReemplazoHistorico::class);
    }

    protected function elementoDiseno(array $c = []): array
    {
        $this->nElemento++;

        return array_merge([
            'id' => sprintf('00000000-0000-4000-8000-%012d', 7000 + $this->nElemento), 'type' => 'text', 'field' => null, 'text' => '',
            'x' => 96, 'y' => 100, 'width' => 600, 'height' => 40, 'fontFamily' => 'outfit', 'fontSize' => 22,
            'fontWeight' => 700, 'color' => '#000000', 'align' => 'center',
        ], $c);
    }

    protected function qr(): array
    {
        $this->nElemento++;

        return ['id' => sprintf('00000000-0000-4000-8000-%012d', 7000 + $this->nElemento), 'type' => 'qr', 'x' => 640, 'y' => 460, 'width' => 96, 'height' => 96];
    }

    /** Plantilla moderna real (PDF base legible + diseño). Por defecto: nombre, documento con «C.C.» y QR. @param list<array<string,mixed>>|null $elementos */
    protected function plantillaModerna(?array $elementos = null): Plantilla
    {
        $this->actingAs($this->admin())->post(route('credential-flow.plantillas.store'), ['nombre' => 'Plantilla '.(++$this->nElemento), 'descripcion' => 'x', 'pdf' => $this->pdf()])->assertSessionHasNoErrors();
        $p = Plantilla::latest('id')->firstOrFail();
        $bytes = PdfBase::crear();
        Storage::disk('local')->put($p->rutaPdfEsperada(), $bytes);
        $elementos ??= [
            $this->elementoDiseno(['field' => 'nombre_completo', 'y' => 200]),
            $this->elementoDiseno(['field' => 'documento', 'y' => 260, 'prefix' => 'C.C. ']),
            $this->qr(),
        ];
        $diseno = ['page' => ['width' => 792, 'height' => 612], 'elements' => $elementos];
        $p->update(['hash_sha256' => hash('sha256', $bytes), 'diseno' => $diseno, 'schema_version' => DisenoSchema::versionPara($diseno)]);
        auth()->logout();

        return $p->fresh();
    }

    protected function clon(int $old): Plantilla
    {
        // El clon se confirma para reemplazos (10B-2B-2B): el servicio exige que el administrador haya revisado su diseño.
        $plantilla = $this->creadorClon()->paraCertificado(CertificadoLegado::findOrFail($this->idCert($old)))['plantilla'];

        return CreadorPlantillaReemplazo::confirmarDiseno($plantilla, $this->actor());
    }

    protected function creadorClon(): CreadorPlantillaReemplazo
    {
        return new CreadorPlantillaReemplazo(app(PlantillaService::class), new ResolutorPlantillaDirectorio($this->dirImagenes));
    }

    /** Solicitud válida para el participante sintético `$old` (la regla que le corresponde). */
    protected function solicitud(int $old, int $plantillaId, array $cambios = []): SolicitudReemplazo
    {
        $base = match ($old) {
            120 => ['reglaDocumento' => ReglasValorAprobado::DOC_SIN_NBSP],
            121 => ['reglaDocumento' => ReglasValorAprobado::DOC_CON_SEPARADORES, 'valorDocumento' => '83.000.002', 'confirmado' => true],
            122 => ['reglaDocumento' => ReglasValorAprobado::DOC_SIN_SIGNO, 'confirmado' => true],
            123 => ['reglaDocumento' => ReglasValorAprobado::DOC_MANUAL, 'valorDocumento' => '83A00004', 'confirmado' => true, 'evidencia' => 'Verificado con el soporte original del participante.'],
        };

        return new SolicitudReemplazo(...array_merge(['plantillaId' => $plantillaId], $base, $cambios));
    }

    protected function reemplazar(int $old, ?int $plantillaId = null, array $cambios = [], ?string $motivo = null): array
    {
        $plantillaId ??= $this->plantillaModerna()->id;

        return $this->servicio()->reemplazar($this->casoDe($old), $this->actor(), $motivo ?? self::MOTIVO, $this->solicitud($old, $plantillaId, $cambios));
    }

    /** Todo lo que el reemplazo puede escribir: sirve para comprobar que un rechazo NO escribe nada. */
    protected function escritura(): string
    {
        return md5(json_encode([
            DB::table('cf_lotes')->orderBy('id')->get()->all(), DB::table('cf_participantes')->orderBy('id')->get()->all(), DB::table('cf_emisiones')->orderBy('id')->get()->all(),
            DB::table('cf_plantillas')->orderBy('id')->get()->all(), DB::table('movimientos')->orderBy('id')->get()->all(),
            DB::table('cf_certificados_legado')->orderBy('id')->get()->all(), DB::table('cf_conciliaciones')->orderBy('id')->get()->all(), DB::table('cf_conciliaciones_eventos')->orderBy('id')->get()->all(),
            collect(Storage::disk('local')->allFiles())->sort()->values()->all(),
        ]));
    }
}
