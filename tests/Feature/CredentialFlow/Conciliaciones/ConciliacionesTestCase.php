<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\DetectorConciliaciones;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CredentialFlow\Historico\HistoricoTestCase;

/**
 * Base de las pruebas de la Fase 10A: histórico SINTÉTICO migrado de verdad (sin datos de personas reales), ampliado con certificados
 * en eventos con la imagen faltante (con candidata), de extensión inválida, sin imagen y faltante sin candidata, para que cada tipo
 * de caso exista.
 *
 * Eventos: 1 Alfa (ok) · 2 Beta (imagen faltante, sin candidata) · 3 Gamma (faltante CON candidata) · 4 Delta (extensión inválida)
 * · 5 Epsilon (sin imagen) · 6 Zeta (faltante, sin candidata).
 */
abstract class ConciliacionesTestCase extends HistoricoTestCase
{
    protected const DOC_P3 = '5000001';

    protected const DOC_P4 = '5000002';

    protected const NOMBRE_P3 = 'LAURA TREINTA PRIVADO';

    protected const CORREO_P3 = 'laura.privada@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        $p = fn (int $id, int $evento, string $doc, string $nombre, string $correo, int $verif) => [
            'id' => $id, 'tipo_documento' => 'CC', 'documento' => $doc, 'nombre' => $nombre, 'correo' => $correo, 'id_evento' => $evento, 'num_verificacion' => $verif,
        ];
        $d = $this->datos();
        $d['participante'][] = $p(30, 3, self::DOC_P3, self::NOMBRE_P3, self::CORREO_P3, 9301);
        $d['participante'][] = $p(31, 4, self::DOC_P4, 'MARIO TREINTA Y UNO', 'mario31@example.test', 9302);
        $d['participante'][] = $p(32, 6, '5000003', 'NORA TREINTA Y DOS', 'nora32@example.test', 9303);
        $d['participante'][] = $p(33, 5, '5000004', 'OSCAR TREINTA Y TRES', 'oscar33@example.test', 9304);
        // Duplicado IDÉNTICO en un evento sin imagen utilizable (estado restrictivo: plantilla pendiente).
        $d['participante'][] = $p(50, 6, '7000001', 'TINA UNO', 'tina@example.test', 9501);
        $d['participante'][] = $p(51, 6, '7000001', 'TINA UNO', 'tina@example.test', 9501);
        $this->migrarSintetico($d);
    }

    /** @return array<string,mixed> */
    protected function detectar(bool $simular = false): array
    {
        return app(DetectorConciliaciones::class)->ejecutar($simular);
    }

    /** @return Collection<int,object> */
    protected function casos(?string $tipo = null)
    {
        return DB::table('cf_conciliaciones')->when($tipo, fn ($q) => $q->where('tipo', $tipo))->orderBy('id')->get();
    }

    protected function certsDelCaso(int $id): array
    {
        return DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $id)->orderBy('certificado_legado_id')->pluck('certificado_legado_id')->map(fn ($x) => (int) $x)->all();
    }

    /** Contenido completo de lo que Fase 10A NO puede tocar (certificados, snapshots, correos, descargas, plantillas, encuestas, corridas, eventos). */
    protected function firmaHistorico(): string
    {
        $tablas = ['cf_certificados_legado', 'cf_correos', 'cf_descargas', 'cf_plantillas_legado', 'cf_plantillas_legado_contenidos', 'cf_eventos', 'cf_encuestas', 'cf_encuestas_versiones', 'cf_encuestas_preguntas', 'cf_encuestas_opciones',
            'cf_encuestas_respuestas', 'cf_encuestas_respuestas_detalle', 'cf_migraciones_corridas', 'cf_migraciones_map', 'cf_accesos_otp', 'cf_envios', 'cf_envios_intentos', 'cf_emisiones', 'movimientos'];
        $f = [];
        foreach ($tablas as $t) {
            $f[$t] = md5(json_encode(DB::table($t)->orderBy('id')->get()->all()));
        }

        return md5(json_encode($f));
    }

    /** Estado de los tres tablas de conciliación, para comparar ejecuciones. */
    protected function firmaConciliaciones(): string
    {
        return md5(json_encode([
            DB::table('cf_conciliaciones')->orderBy('id')->get()->all(),
            DB::table('cf_conciliaciones_certificados')->orderBy('id')->get()->all(),
            DB::table('cf_conciliaciones_eventos')->orderBy('id')->get()->all(),
        ]));
    }

    protected function caso(string $tipo): Conciliacion
    {
        return Conciliacion::where('tipo', $tipo)->orderBy('id')->firstOrFail();
    }
}
