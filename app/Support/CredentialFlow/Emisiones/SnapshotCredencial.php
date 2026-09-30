<?php

namespace App\Support\CredentialFlow\Emisiones;

use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\FuentesCredential;
use App\Support\CredentialFlow\Generacion\DatosCredencial;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use App\Support\CredentialFlow\Participantes\DatosDeParticipante;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Storage;

/**
 * Todo lo que produce el PDF de una emisión, congelado en el momento de emitir: los cinco valores impresos,
 * el diseño completo, el schema, el hash del PDF base y los datos del generador (versión, TCPDF/FPDI y hashes
 * de las fuentes realmente usadas). El PDF se renderiza DESDE este snapshot, nunca desde el diseño vivo.
 *
 * `huella` es un hash determinista de las entradas vivas (participante, datos del lote, plantilla, diseño, schema
 * y hash del PDF base). Se vuelve a calcular con la base bloqueada para detectar cambios concurrentes.
 */
final readonly class SnapshotCredencial
{
    /**
     * @param  array<string,string>  $datos  nombre_completo, documento, evento, fecha, intensidad_horaria + lote_nombre, plantilla_nombre
     * @param  array<string,mixed>  $diseno
     * @param  array<string,mixed>  $generador
     */
    public function __construct(
        public array $datos,
        public array $diseno,
        public int $schemaVersion,
        public string $plantillaPdfHash,
        public array $generador,
        public string $huella,
        public string $codigo,
    ) {}

    /**
     * Carga el PDF base de la plantilla y comprueba que su SHA-256 coincide con el guardado (una sola vez por
     * operación). @return array{0:string,1:string} bytes y hash
     *
     * @throws GeneracionCredencialException
     */
    public static function cargarPlantilla(Plantilla $plantilla): array
    {
        $disco = Storage::disk(Plantilla::DISCO);
        $ruta = $plantilla->rutaPdfEsperada();

        if ($plantilla->archivo_pdf !== $ruta || ! $disco->exists($ruta)) {
            throw GeneracionCredencialException::con(GeneracionCredencialException::PLANTILLA_SIN_PDF, 'No se encontró el PDF base de la plantilla.');
        }

        $bytes = $disco->get($ruta);
        $hash = hash('sha256', $bytes);
        if (! hash_equals((string) $plantilla->hash_sha256, $hash)) {
            throw GeneracionCredencialException::con(GeneracionCredencialException::PLANTILLA_ALTERADA, 'El PDF base de la plantilla no coincide con el registrado. No se puede emitir.');
        }

        return [$bytes, $hash];
    }

    /**
     * @param  string  $plantillaPdfHash  hash ya verificado con cargarPlantilla()
     *
     * @throws GeneracionCredencialException
     */
    public static function capturar(Participante $participante, Lote $lote, Plantilla $plantilla, string $plantillaPdfHash): self
    {
        $diseno = $plantilla->diseno;
        if (! is_array($diseno) || ! isset($diseno['page'], $diseno['elements'])) {
            throw GeneracionCredencialException::con(GeneracionCredencialException::SIN_DISENO, 'La plantilla no tiene un diseño guardado. Guarda el diseño en el editor primero.');
        }

        $datos = DatosDeParticipante::para($participante, $lote)->todos();
        ksort($datos);
        $datos['lote_nombre'] = (string) $lote->nombre;
        $datos['plantilla_nombre'] = (string) $plantilla->nombre;

        return new self(
            $datos,
            $diseno,
            (int) $plantilla->schema_version,
            $plantillaPdfHash,
            self::generador($diseno),
            self::huella($participante, $lote, $plantilla),
            CodigoEmision::unico(),
        );
    }

    /** Solo los cinco valores impresos (sin los nombres administrativos). */
    public function datosCredencial(): DatosCredencial
    {
        return DatosCredencial::fromArray(array_diff_key($this->datos, ['lote_nombre' => 1, 'plantilla_nombre' => 1]));
    }

    /** Hash determinista de las entradas vivas que originan el snapshot. */
    public static function huella(Participante $participante, Lote $lote, Plantilla $plantilla): string
    {
        return self::hashCanonico([
            'participante' => ['id' => $participante->id, 'lote_id' => $participante->lote_id, 'nombre_completo' => $participante->nombre_completo, 'documento' => $participante->documento],
            'lote' => ['id' => $lote->id, 'plantilla_id' => $lote->plantilla_id, 'nombre' => $lote->nombre, 'datos_comunes' => $lote->datos_comunes],
            'plantilla' => ['id' => $plantilla->id, 'nombre' => $plantilla->nombre, 'diseno' => $plantilla->diseno, 'schema_version' => (int) $plantilla->schema_version, 'hash' => $plantilla->hash_sha256],
        ]);
    }

    /**
     * Datos del generador con los que se produce el PDF. Los hashes de las fuentes salen de metricas.json
     * (la fuente de verdad ya verificada con `credential-flow:fuentes-metricas --verify`).
     *
     * @param  array<string,mixed>  $diseno
     * @return array<string,mixed>
     */
    public static function generador(array $diseno): array
    {
        $metricas = FuentesCredential::metricas()['fuentes'];
        $fuentes = [];
        foreach ($diseno['elements'] ?? [] as $el) {
            $familia = (string) ($el['fontFamily'] ?? '');
            $peso = (string) ($el['fontWeight'] ?? '');
            if (isset($metricas[$familia][$peso]['sha256'])) {
                $fuentes[$familia][$peso] = $metricas[$familia][$peso]['sha256'];
            }
        }
        foreach ($fuentes as &$pesos) {
            ksort($pesos);
        }
        unset($pesos);
        ksort($fuentes);

        return [
            'generador_version' => GeneradorCredencialPdf::GENERADOR_VERSION,
            'tcpdf' => InstalledVersions::getPrettyVersion('tecnickcom/tcpdf'),
            'fpdi' => InstalledVersions::getPrettyVersion('setasign/fpdi'),
            'fuentes' => $fuentes,
            'metricas_sha256' => hash_file('sha256', FuentesCredential::rutaMetricas()),
        ];
    }

    /** @param  array<string,mixed>  $datos */
    private static function hashCanonico(array $datos): string
    {
        return hash('sha256', json_encode(self::ordenar($datos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function ordenar(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }
        $ordenado = array_map(fn ($v) => self::ordenar($v), $valor);
        if (! array_is_list($ordenado)) {
            ksort($ordenado);
        }

        return $ordenado;
    }
}
