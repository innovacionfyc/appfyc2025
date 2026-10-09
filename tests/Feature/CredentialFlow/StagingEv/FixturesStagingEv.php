<?php

namespace Tests\Feature\CredentialFlow\StagingEv;

use App\Support\CredentialFlow\StagingEv\FuenteArreglos;
use App\Support\CredentialFlow\StagingEv\ImportadorSnapshot;
use App\Support\CredentialFlow\StagingEv\ResultadoCarga;
use App\Support\CredentialFlow\StagingEv\SnapshotInfo;

/** Datos sintéticos y ayudas compartidas por las pruebas del staging (SQLite y MySQL). Nada pertenece a una persona real. */
trait FixturesStagingEv
{
    // Marcadores sintéticos que NUNCA deben aparecer en un reporte.
    public const NOMBRE = 'ANA UNO PRUEBA';

    public const DOCUMENTO = '1000001';

    public const CORREO = 'ana.uno@example.test';

    public const TOKEN = 'tokensecreto-aaa';

    public const TEXTO_ENCUESTA = 'TEXTOLIBREPRIVADO';

    /** @return array<string,array<int,array<string,mixed>>> */
    protected function datos(): array
    {
        $p = fn (int $id, int $evento, ?string $tipo, ?string $doc, ?string $nombre, ?string $correo, ?int $verif = null) => [
            'id' => $id, 'tipo_documento' => $tipo, 'documento' => $doc, 'nombre' => $nombre, 'correo' => $correo, 'id_evento' => $evento, 'num_verificacion' => $verif,
        ];
        $enc = fn (int $id, int $evento, string $doc, string $p1) => [
            'id' => $id, 'pregunta1' => $p1, 'justificacion_pregunta1' => null, 'pregunta2' => 'Bueno', 'pregunta3' => 'Bueno', 'pregunta4' => 'Bueno',
            'pregunta5' => self::TEXTO_ENCUESTA, 'pregunta6' => '', 'pregunta7' => '', 'pregunta8' => '', 'pregunta9' => '',
            'id_evento' => $evento, 'documento_participante' => $doc, 'fecha' => '2026-02-01 09:00:00',
        ];

        return [
            'evento' => [
                ['id' => 1, 'nombre' => 'Curso Alfa 2024', 'imagen_certificado' => 'ALFA 2024.png'],
                ['id' => 2, 'nombre' => 'Curso Beta 2025', 'imagen_certificado' => 'BETA 2025.jpg'],
                ['id' => 3, 'nombre' => 'Curso Gamma', 'imagen_certificado' => 'GAMMA.png'],
                ['id' => 4, 'nombre' => 'Curso Delta 2023', 'imagen_certificado' => 'DELTA 2023'],
                ['id' => 5, 'nombre' => 'Curso Epsilon 2026', 'imagen_certificado' => ''],
                ['id' => 6, 'nombre' => 'Curso Zeta 2024 y 2025', 'imagen_certificado' => 'ZETA.png'],
            ],
            'participante' => [
                $p(1, 1, 'CC', self::DOCUMENTO, self::NOMBRE, self::CORREO, 100),
                $p(2, 1, 'CC', '1000002', 'BETO DOS PRUEBA', 'correo-invalido'),
                $p(3, 2, 'CE', '1000003', 'CARLA TRES', null),
                $p(4, 2, null, 'ABC123', 'DIEGO CUATRO', 'diego@example.test'),
                $p(5, 2, 'CC', '', 'ELENA CINCO', ''),
                $p(6, 3, 'CC', '73.156.827', 'FELIPE SEIS', 'felipe@example.test'),
                // Duplicado IDÉNTICO (evento 1)
                $p(7, 1, 'CC', '2000001', 'GINA SIETE', 'gina@example.test', 101),
                $p(8, 1, 'CC', '2000001', 'GINA SIETE', 'gina@example.test', 101),
                // Duplicado CONFLICTIVO por nombre (evento 1)
                $p(9, 1, 'CC', '2000002', 'HUGO NUEVE', 'hugo@example.test'),
                $p(10, 1, 'CC', '2000002', 'HUGO NUEVE X', 'hugo@example.test'),
                // CONFLICTIVO solo por código NULL vs valor (evento 2)
                $p(11, 2, 'CC', '2000003', 'IVAN ONCE', 'ivan@example.test', 205),
                $p(12, 2, 'CC', '2000003', 'IVAN ONCE', 'ivan@example.test', null),
                // CONFLICTIVO por tipo (evento 3)
                $p(13, 3, 'CC', '2000004', 'JULIA TRECE', 'julia@example.test'),
                $p(14, 3, 'CE', '2000004', 'JULIA TRECE', 'julia@example.test'),
                // Varios documentos vacíos en el mismo evento: NO son duplicados entre sí.
                $p(15, 2, 'CC', null, 'KARLA QUINCE', null),
            ],
            'token' => [
                ['id' => 1, 'codigo' => self::TOKEN, 'fecha_creacion' => '2026-01-01 08:00:00', 'id_participante' => 1],
                ['id' => 2, 'codigo' => self::TOKEN, 'fecha_creacion' => '2026-01-02 08:00:00', 'id_participante' => 2],
                ['id' => 3, 'codigo' => 'tokensecreto-bbb', 'fecha_creacion' => '0000-00-00 00:00:00', 'id_participante' => 999],
            ],
            'descargas' => [
                ['id' => 1, 'fecha' => '2026-01-01 10:00:00', 'id_evento' => 1, 'id_participante' => 1],
                ['id' => 2, 'fecha' => '2026-01-05 10:00:00', 'id_evento' => 1, 'id_participante' => 1],
                ['id' => 3, 'fecha' => '2026-01-03 10:00:00', 'id_evento' => 1, 'id_participante' => 7],
                ['id' => 4, 'fecha' => '2026-01-04 10:00:00', 'id_evento' => 1, 'id_participante' => 999],
                ['id' => 5, 'fecha' => '2026-01-06 10:00:00', 'id_evento' => 1, 'id_participante' => 3],
            ],
            'encuesta' => [
                $enc(1, 1, self::DOCUMENTO, 'Excelente'),
                $enc(2, 9, 'X-999', 'Bueno'),
                $enc(3, 1, '1000002', 'Bueno'),
            ],
            'preguntas_encuesta' => [['id' => 1, 'texto' => '¿Cómo calificas el evento?', 'tipo_respuesta' => 'unica', 'num_opciones' => 2]],
            'opciones_respuesta' => [
                ['id' => 1, 'opcion' => 'Excelente', 'id_pregunta' => 1],
                ['id' => 2, 'opcion' => 'Bueno', 'id_pregunta' => 1],
            ],
        ];
    }

    /** @param array<string,array<int,array<string,mixed>>>|null $datos */
    protected function fuente(?array $datos = null): FuenteArreglos
    {
        return new FuenteArreglos($datos ?? $this->datos(), str_repeat('e', 64));
    }

    protected function info(string $etiqueta, ?string $sha = null): SnapshotInfo
    {
        return new SnapshotInfo($etiqueta, 'Snapshot sintético de pruebas', 'prueba.sql.gz', $sha ?? hash('sha256', $etiqueta), '2026-10-05 13:48:00', 'MariaDB 10.6.23');
    }

    /** @param array<int,array<string,mixed>>|null $imagenes */
    protected function cargar(string $etiqueta, ?array $datos = null, ?array $imagenes = null, ?string $sha = null): ResultadoCarga
    {
        return (new ImportadorSnapshot)->cargar($this->info($etiqueta, $sha), $this->fuente($datos), $imagenes);
    }

    /** @param array<string,array<int,array<string,mixed>>> $datos */
    protected function sinFila(array $datos, string $tabla, int $id): array
    {
        $datos[$tabla] = array_values(array_filter($datos[$tabla], fn ($f) => $f['id'] !== $id));

        return $datos;
    }

    /** @param array<string,array<int,array<string,mixed>>> $datos */
    protected function conCambio(array $datos, string $tabla, int $id, array $cambios): array
    {
        foreach ($datos[$tabla] as &$f) {
            if ($f['id'] === $id) {
                $f = $cambios + $f;
            }
        }

        return $datos;
    }
}
