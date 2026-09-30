<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Emisiones\CodigoEmision;
use App\Support\CredentialFlow\Emisiones\EspacioDisco;
use App\Support\CredentialFlow\Participantes\Texto;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CredentialFlow\Support\PdfBase;

/** Utilidades comunes de las pruebas de la Fase 7 (emisiones). */
abstract class EmisionesTestCase extends CredentialFlowTestCase
{
    protected int $n = 0;

    protected function tearDown(): void
    {
        // Las simulaciones de estas pruebas son estáticas: se restauran siempre.
        EspacioDisco::simular(null);
        CodigoEmision::$fuente = null;
        AlmacenEmisiones::$antesDeEscribir = null;

        parent::tearDown();
    }

    protected function elemento(array $c = []): array
    {
        $this->n++;

        return array_merge([
            'id' => sprintf('00000000-0000-4000-8000-%012d', $this->n), 'type' => 'text', 'field' => null, 'text' => '',
            'x' => 96, 'y' => 100, 'width' => 600, 'height' => 40, 'fontFamily' => 'outfit', 'fontSize' => 22,
            'fontWeight' => 700, 'color' => '#000000', 'align' => 'center',
        ], $c);
    }

    /** Plantilla real con PDF base FPDI-legible (con su hash guardado) y un diseño que usa los cinco campos. */
    protected function plantillaLista(?array $elementos = null): Plantilla
    {
        $p = $this->crearPlantilla(['nombre' => 'Plantilla '.(++$this->n)]);
        $bytes = PdfBase::crear();
        Storage::disk('local')->put($p->rutaPdfEsperada(), $bytes);
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'];
        $elementos ??= array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 60 + $i * 60]), $campos, array_keys($campos));
        $p->update(['hash_sha256' => hash('sha256', $bytes), 'diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => $elementos]]);

        return $p->fresh();
    }

    protected function loteCon(int $participantes = 1, ?Plantilla $plantilla = null): Lote
    {
        $plantilla ??= $this->plantillaLista();
        $lote = Lote::create([
            'plantilla_id' => $plantilla->id,
            'nombre' => 'Lote '.(++$this->n),
            'datos_comunes' => ['evento' => 'CONGRESO DE FINANZAS', 'fecha' => '17 de septiembre de 2026', 'intensidad_horaria' => '30 horas'],
        ]);
        for ($i = 1; $i <= $participantes; $i++) {
            $this->participante($lote, "Persona Número $i", 'C.C. '.number_format(20_000_000 + $i, 0, ',', '.'));
        }

        return $lote->fresh();
    }

    protected function participante(Lote $lote, string $nombre, string $documento): Participante
    {
        return $lote->participantes()->create([
            'nombre_completo' => Texto::mayusculas($nombre),
            'documento' => $documento,
            'documento_clave' => Texto::claveDocumento($documento),
        ]);
    }

    protected function emitirPor(Lote $lote, Participante $p, $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->admin())->postJson(route('credential-flow.participantes.emitir', [$lote, $p]));
    }

    /** @return array<int,string> archivos del almacén de emisiones (incluye residuos .tmp/.staging) */
    protected function archivosDeEmisiones(): array
    {
        return Storage::disk('local')->allFiles(AlmacenEmisiones::RAIZ);
    }
}
