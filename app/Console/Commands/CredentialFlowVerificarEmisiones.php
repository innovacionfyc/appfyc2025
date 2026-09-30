<?php

namespace App\Console\Commands;

use App\Models\CredentialFlow\Emision;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Emisiones\CodigoEmision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de solo lectura de las emisiones de Credential Flow: archivo existente, bytes, SHA-256, snapshot
 * con la forma esperada, coherencia de `participante_vigente`, una sola vigente por participante y PDFs
 * huérfanos (o residuos de staging/temporales) en el almacén. NO borra, NO repara y NO modifica nada.
 * Sale con código 1 si encuentra cualquier problema. Ejecutarlo tras restaurar un backup.
 */
class CredentialFlowVerificarEmisiones extends Command
{
    protected $signature = 'credential-flow:verificar-emisiones';

    protected $description = 'Verifica (solo lectura) la integridad de las emisiones de Credential Flow y sus PDFs';

    private const CLAVES_DATOS = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria', 'lote_nombre', 'plantilla_nombre'];

    /** @var array<int,string> */
    private array $problemas = [];

    public function handle(): int
    {
        $disco = AlmacenEmisiones::disco();
        $conocidos = [];
        $total = 0;

        foreach (Emision::query()->orderBy('id')->cursor() as $e) {
            $total++;
            $conocidos[(string) $e->pdf_archivo] = true;
            $id = "Emisión #{$e->id}";

            $this->verificarArchivo($e, $id);
            $this->verificarSnapshot($e, $id);
            $this->verificarVigencia($e, $id);
        }

        $duplicadas = DB::table('cf_emisiones')->where('estado', Emision::EMITIDA)->select('participante_id')->groupBy('participante_id')->havingRaw('count(*) > 1')->pluck('participante_id');
        foreach ($duplicadas as $participante) {
            $this->problemas[] = "Participante #{$participante}: tiene más de una emisión vigente.";
        }

        foreach ($disco->allFiles(AlmacenEmisiones::RAIZ) as $archivo) {
            if (isset($conocidos[$archivo])) {
                continue;
            }
            $residuo = str_contains($archivo, '/.tmp/') || str_contains($archivo, '/.staging/');
            $this->problemas[] = ($residuo ? 'Residuo de escritura/staging' : 'PDF huérfano (sin emisión en la base)').': '.$archivo;
        }

        $this->line("Emisiones revisadas: {$total}");
        if ($this->problemas === []) {
            $this->info('Todo correcto: archivos, hashes, snapshots y vigencias coinciden.');

            return self::SUCCESS;
        }

        foreach ($this->problemas as $p) {
            $this->error($p);
        }
        $this->error(count($this->problemas).' problema(s) encontrado(s). No se modificó nada.');

        return self::FAILURE;
    }

    private function verificarArchivo(Emision $e, string $id): void
    {
        $disco = AlmacenEmisiones::disco();
        $ruta = (string) $e->pdf_archivo;

        if (preg_match('#^'.preg_quote(AlmacenEmisiones::RAIZ, '#').'/[0-9a-f]{2}/[0-9a-f-]{36}\.pdf$#', $ruta) !== 1) {
            $this->problemas[] = "{$id}: la ruta del PDF no tiene la forma esperada.";

            return;
        }
        if (! $disco->exists($ruta)) {
            $this->problemas[] = "{$id}: falta el archivo PDF.";

            return;
        }

        $fisica = $disco->path($ruta);
        if (filesize($fisica) !== (int) $e->pdf_bytes) {
            $this->problemas[] = "{$id}: el tamaño del PDF no coincide con pdf_bytes.";
        }
        if (! hash_equals((string) $e->pdf_hash, (string) hash_file('sha256', $fisica))) {
            $this->problemas[] = "{$id}: el SHA-256 del PDF no coincide con pdf_hash.";
        }
    }

    private function verificarSnapshot(Emision $e, string $id): void
    {
        $datos = $e->datos_snapshot;
        if (! is_array($datos) || array_diff(self::CLAVES_DATOS, array_keys($datos)) !== []) {
            $this->problemas[] = "{$id}: datos_snapshot no tiene la forma esperada.";
        }
        $diseno = $e->diseno_snapshot;
        if (! is_array($diseno) || ! isset($diseno['page'], $diseno['elements'])) {
            $this->problemas[] = "{$id}: diseno_snapshot no tiene la forma esperada.";
        }
        $generador = $e->generador_snapshot;
        if (! is_array($generador) || ! isset($generador['generador_version'], $generador['metricas_sha256'])) {
            $this->problemas[] = "{$id}: generador_snapshot no tiene la forma esperada.";
        }
        if ((int) $e->schema_version < 1 || strlen((string) $e->plantilla_pdf_hash) !== 64) {
            $this->problemas[] = "{$id}: schema_version o plantilla_pdf_hash inválidos.";
        }
        if (! CodigoEmision::valido((string) $e->codigo)) {
            $this->problemas[] = "{$id}: el código de emisión no tiene el formato esperado.";
        }
    }

    private function verificarVigencia(Emision $e, string $id): void
    {
        if ($e->estado === Emision::EMITIDA) {
            if ((int) $e->participante_vigente !== (int) $e->participante_id) {
                $this->problemas[] = "{$id}: está vigente pero participante_vigente no coincide con el participante.";
            }
        } elseif ($e->estado === Emision::REVOCADA) {
            if ($e->participante_vigente !== null || $e->revocado_at === null) {
                $this->problemas[] = "{$id}: está revocada pero conserva participante_vigente o no tiene revocado_at.";
            }
        } else {
            $this->problemas[] = "{$id}: estado desconocido.";
        }
    }
}
