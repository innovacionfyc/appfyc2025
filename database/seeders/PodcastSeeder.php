<?php

namespace Database\Seeders;

use App\Models\Estado;
use App\Models\PodcastComentario;
use App\Models\PodcastEpisodio;
use App\Models\PodcastReaccion;
use App\Models\PodcastTemporada;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * SOLO DESARROLLO / LOCAL. No se registra en DatabaseSeeder y no debe ejecutarse en producción.
 *
 * Datos de demostración para visualizar la UI del podcast. Salvo el episodio 1 de la
 * temporada 1 (ejemplo aprobado), los invitados y contenidos son ficticios. Los IDs de
 * YouTube son placeholders con formato válido (DEMO0000001…) y NO corresponden a videos
 * reales ni al canal de F&C.
 */
class PodcastSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('PodcastSeeder es solo para desarrollo. No se ejecutó.');
            return;
        }

        $this->asegurarEstados();

        $activo = Estado::where('tipo_estado', 'Activo')->value('id');
        $borrador = Estado::where('tipo_estado', 'Borrador')->value('id');

        $temporada1 = PodcastTemporada::updateOrCreate(
            ['numero' => 1],
            [
                'titulo' => 'Quienes construyen lo público',
                'slug' => 'temporada-1',
                'descripcion' => 'Una primera temporada dedicada a las trayectorias que han dado forma a la administración pública colombiana: presupuesto, contratación, control y talento humano.',
                'estado_id' => $activo,
            ]
        );

        $temporada2 = PodcastTemporada::updateOrCreate(
            ['numero' => 2],
            [
                'titulo' => '[DEMO] Temporada en preparación',
                'slug' => 'temporada-2',
                'descripcion' => '[DEMO] Temporada de prueba en estado borrador para validar el selector y los estados.',
                'estado_id' => $borrador,
            ]
        );

        $episodios = [
            // Ejemplo aprobado (único con datos reales de invitado; sin cargo inventado).
            [
                'temporada' => $temporada1, 'numero' => 1, 'estado_id' => $activo, 'destacado' => true,
                'titulo' => 'Tres décadas moldeando el presupuesto público de Colombia',
                'invitado_nombre' => 'Ezequiel Lenis Ramírez', 'invitado_cargo' => null,
                'descripcion' => 'Una conversación sobre cómo se construye, se negocia y se defiende el presupuesto de la Nación, y sobre las decisiones que dejan huella mucho después de aprobarse.',
                'fecha_publicacion' => '2026-08-14', 'duracion_segundos' => 47 * 60, 'youtube_video_id' => 'DEMO0000001',
            ],
            [
                'temporada' => $temporada1, 'numero' => 2, 'estado_id' => $activo, 'destacado' => false,
                'titulo' => 'Contratación estatal: lo que la norma no explica',
                'invitado_nombre' => 'Ana Lucía Gómez', 'invitado_cargo' => '[DEMO] Consultora en contratación pública',
                'descripcion' => '[DEMO] De los pliegos a la ejecución: los criterios prácticos que separan un proceso bien estructurado de uno que termina en controversia.',
                'fecha_publicacion' => '2026-08-28', 'duracion_segundos' => 39 * 60, 'youtube_video_id' => 'DEMO0000002',
            ],
            [
                'temporada' => $temporada1, 'numero' => 3, 'estado_id' => $activo, 'destacado' => false,
                'titulo' => 'Control fiscal: prevenir antes que sancionar',
                'invitado_nombre' => 'Julián Restrepo', 'invitado_cargo' => '[DEMO] Auditor y docente universitario',
                'descripcion' => '[DEMO] Cómo entender el control fiscal como una herramienta de gestión y no solo como una amenaza.',
                'fecha_publicacion' => '2026-09-11', 'duracion_segundos' => 52 * 60, 'youtube_video_id' => 'DEMO0000003',
            ],
            [
                'temporada' => $temporada1, 'numero' => 4, 'estado_id' => $activo, 'destacado' => false,
                'titulo' => 'Liderar equipos en el Estado sin perder la vocación',
                'invitado_nombre' => 'María Fernanda Ortiz', 'invitado_cargo' => '[DEMO] Directora de talento humano',
                'descripcion' => '[DEMO] Rotación, mérito y motivación: una charla franca sobre dirigir personas dentro de una entidad pública hoy.',
                'fecha_publicacion' => '2026-09-18', 'duracion_segundos' => 44 * 60, 'youtube_video_id' => 'DEMO0000004',
            ],
            [
                'temporada' => $temporada1, 'numero' => 5, 'estado_id' => $borrador, 'destacado' => false,
                'titulo' => '[DEMO] Episodio en borrador, no visible al público',
                'invitado_nombre' => 'Invitado de prueba', 'invitado_cargo' => '[DEMO]',
                'descripcion' => '[DEMO] Sirve para validar que el público solo vea episodios activos.',
                'fecha_publicacion' => null, 'duracion_segundos' => null, 'youtube_video_id' => 'DEMO0000005',
            ],
            [
                'temporada' => $temporada2, 'numero' => 1, 'estado_id' => $borrador, 'destacado' => false,
                'titulo' => '[DEMO] Primer episodio de la temporada 2',
                'invitado_nombre' => 'Invitada de prueba', 'invitado_cargo' => '[DEMO]',
                'descripcion' => '[DEMO] Episodio de una temporada aún no publicada.',
                'fecha_publicacion' => null, 'duracion_segundos' => null, 'youtube_video_id' => 'DEMO0000006',
            ],
        ];

        $creados = [];
        foreach ($episodios as $datos) {
            $temporada = $datos['temporada'];
            unset($datos['temporada']);

            $slug = sprintf('t%d-e%02d-%s', $temporada->numero, $datos['numero'], Str::limit(Str::slug($datos['titulo']), 120, ''));

            $creados[] = PodcastEpisodio::updateOrCreate(
                ['temporada_id' => $temporada->id, 'numero' => $datos['numero']],
                $datos + [
                    'slug' => $slug,
                    'youtube_url' => 'https://www.youtube.com/watch?v=' . $datos['youtube_video_id'],
                ]
            );
        }

        $this->sembrarInteraccionesDemo($creados[0], $creados[1]);

        $this->command->info('PodcastSeeder: 2 temporadas, ' . count($creados) . ' episodios, comentarios y reacciones de prueba.');
    }

    /**
     * Los seeders de catálogo no han corrido en todas las bases locales; EstadosSeeder es idempotente.
     */
    private function asegurarEstados(): void
    {
        $faltan = Estado::whereIn('tipo_estado', ['Activo', 'Borrador'])->count() < 2;

        if ($faltan) {
            $this->call(EstadosSeeder::class);
        }
    }

    private function sembrarInteraccionesDemo(PodcastEpisodio $episodio1, PodcastEpisodio $episodio2): void
    {
        $comentarios = [
            ['nombre' => 'Lector de prueba 1', 'estado_moderacion' => PodcastComentario::APROBADO, 'contenido' => '[DEMO] Comentario aprobado para probar el listado público.'],
            ['nombre' => 'Lector de prueba 2', 'estado_moderacion' => PodcastComentario::APROBADO, 'contenido' => '[DEMO] Segundo comentario aprobado.'],
            ['nombre' => 'Lector de prueba 3', 'estado_moderacion' => PodcastComentario::PENDIENTE, 'contenido' => '[DEMO] Comentario pendiente de moderación; no debe verse en público.'],
            ['nombre' => 'Lector de prueba 4', 'estado_moderacion' => PodcastComentario::RECHAZADO, 'contenido' => '[DEMO] Comentario rechazado; no debe verse en público.'],
        ];

        foreach ($comentarios as $i => $datos) {
            PodcastComentario::firstOrCreate(
                ['episodio_id' => $episodio1->id, 'nombre' => $datos['nombre']],
                $datos + [
                    'correo' => null,
                    'ip_hash' => hash('sha256', 'demo-ip-' . $i),
                    'user_agent' => 'PodcastSeeder/demo',
                    'moderado_en' => $datos['estado_moderacion'] === PodcastComentario::PENDIENTE ? null : now(),
                ]
            );
        }

        // Fingerprint con el esquema previsto: HMAC (APP_KEY) de un identificador anónimo.
        foreach ([1, 2, 3] as $i) {
            PodcastReaccion::firstOrCreate([
                'episodio_id' => $episodio1->id,
                'tipo' => PodcastReaccion::ME_GUSTA,
                'fingerprint' => hash_hmac('sha256', 'demo-visitante-' . $i, config('app.key')),
            ]);
        }

        PodcastReaccion::firstOrCreate([
            'episodio_id' => $episodio2->id,
            'tipo' => PodcastReaccion::ME_GUSTA,
            'fingerprint' => hash_hmac('sha256', 'demo-visitante-1', config('app.key')),
        ]);
    }
}
