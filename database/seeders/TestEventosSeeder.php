<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Faker\Factory as Faker;

class TestEventosSeeder extends Seeder
{
    public function run()
    {
        $faker = Faker::create('es_ES');

        $this->command->info('Iniciando volcado de Conferencistas y Eventos...');

        // 1. Limpiar SÓLO las tablas que vamos a afectar
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('evento_conferencista')->truncate();
        DB::table('eventos')->truncate();
        DB::table('contenidos_tematicos')->truncate();
        DB::table('perfil_conferencistas')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // 2. Obtener llaves foráneas dinámicamente de lo que ya tienes creado
        $areasIds = DB::table('areas_formacion')->pluck('id')->toArray();
        
        if (empty($areasIds)) {
            $this->command->error('No encontré Áreas de Formación. ¡Asegúrate de correr tu seeder de Áreas primero!');
            return;
        }

        // Tomamos el primer organizador y estado que encuentre (Fallback a 1 por seguridad)
        $organizadorId = DB::table('usuarios')->first()->id ?? 1;
        $estadoId = DB::table('estados')->first()->id ?? 1;

        // ==========================================
        // 3. CREACIÓN DE 30 CONFERENCISTAS
        // ==========================================
        $this->command->info('Creando 30 Conferencistas...');
        
        $conferencistasIds = [];
        for ($i = 0; $i < 30; $i++) {
            $conferencistasIds[] = DB::table('perfil_conferencistas')->insertGetId([
                'usuario_id' => null,
                'primer_nombre' => $faker->firstName(),
                'segundo_nombre' => $faker->optional(0.5)->firstName(),
                'primer_apellido' => $faker->lastName(),
                'segundo_apellido' => $faker->optional(0.5)->lastName(),
                'foto' => null, // Esto activará ui-avatars en tu Vue
                'biografia' => "Consultor experto con más de " . rand(5, 20) . " años de experiencia. Especialista en la implementación de normativas y procesos estratégicos.",
                'telefono' => $faker->numerify('3#########'),
                'correo' => $faker->unique()->safeEmail(),
                'url_hv' => 'https://linkedin.com/in/' . $faker->userName(),
                'areas_encargadas' => json_encode(['nombre' => $faker->randomElement(['Jurídica', 'Talento Humano', 'Gestión', 'Finanzas'])]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ==========================================
        // 4. CREACIÓN DE 10 EVENTOS
        // ==========================================
        $this->command->info('Creando 10 Eventos y asignando conferencistas...');

        // Lista actualizada de tipos de evento
        $tiposEvento = [
            'SEMINARIO', 'JORNADA', 'MODULO', 'MODULO_VIRTUAL', 'CNG', 'CNG_VIRTUAL', 
            'CURSO_INTENSIVO_VIRTUAL', 'CURSO_INTENSIVO_HIBRIDO', 'DIPLOMADO_VIRTUAL', 
            'DIPLOMADO_HIBRIDO', 'CI_CNG', 'JOR_MOD', 'CUR_CNG_MOD_DUPLA'
        ];
        
        for ($i = 0; $i < 10; $i++) {
            // Contenido Temático
            $contenidoTematicoId = DB::table('contenidos_tematicos')->insertGetId([
                'modulos' => json_encode([
                    [
                        'tema' => 'Módulo 1: Fundamentos y Actualización Normativa',
                        'subtemas' => ['Análisis de la nueva ley', 'Impacto en las entidades']
                    ],
                    [
                        'tema' => 'Módulo 2: Taller Práctico y Herramientas',
                        'subtemas' => ['Uso de software especializado', 'Resolución de dudas']
                    ]
                ]),
                'alcance' => 'Al finalizar, el asistente estará en la capacidad de implementar los conocimientos en su entidad.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $tipo = $faker->randomElement($tiposEvento);
            $modalidad = $faker->randomElement(['Presencial', 'Virtual', 'Híbrido']);
            $titulo = "Actualización en " . $faker->catchPhrase();
            
            // Fechas aleatorias entre el mes pasado y el próximo mes
            $diasOffset = rand(-15, 30);
            $fechaInicio = Carbon::now()->addDays($diasOffset)->setTime(8, 0);
            $fechaFin = (clone $fechaInicio)->addDays(rand(0, 2))->setTime(17, 0);

            // Crear el Evento con los 10 campos de precios sincronizados
            $eventoId = DB::table('eventos')->insertGetId([
                'organizador_id' => $organizadorId,
                'contenido_tematico_id' => $contenidoTematicoId,
                'estado_id' => $estadoId,
                'area_formacion_id' => $faker->randomElement($areasIds),
                'formulario_base_id' => null,
                
                'modo_evento' => 'Jornada de actualización',
                'titulo' => $titulo,
                'slug' => Str::slug($titulo) . '-' . uniqid(),
                'subtitulo' => $faker->realText(80),
                'imagen_relacionada' => null,
                
                'modalidad' => $modalidad,
                'url_folleto' => 'dummy-brochure.pdf',
                'url_folleto_secundario' => in_array($tipo, ['CI_CNG']) ? 'dummy-congreso.pdf' : null,
                'url_formulario_inscripcion' => 'https://docs.google.com/forms/d/e/1FAIpQLSc...',
                'ubicacion' => $modalidad === 'Virtual' ? null : 'Hotel Tequendama, Bogotá',
                
                'fecha_hora_inicio' => $fechaInicio,
                'fecha_hora_fin' => $fechaFin,
                
                // --- 10 CAMPOS DE PRECIOS ---
                'precio_jornada'                  => in_array($tipo, ['JORNADA', 'JOR_MOD']) ? 1200000 : 0,
                'precio_seminario'                => $tipo === 'SEMINARIO' ? 950000 : 0,
                'precio_modulo'                   => in_array($tipo, ['MODULO', 'JOR_MOD', 'CUR_CNG_MOD_DUPLA']) ? 600000 : 0,
                'precio_modulo_virtual'           => $tipo === 'MODULO_VIRTUAL' ? 450000 : 0,
                'precio_cng'                      => in_array($tipo, ['CNG', 'CI_CNG', 'CUR_CNG_MOD_DUPLA']) ? 1950000 : 0,
                'precio_cng_virtual'              => $tipo === 'CNG_VIRTUAL' ? 1400000 : 0,
                'precio_curso_intensivo_hibrido'  => in_array($tipo, ['CURSO_INTENSIVO_HIBRIDO', 'CI_CNG', 'CUR_CNG_MOD_DUPLA']) ? 2195000 : 0,
                'precio_curso_intensivo_virtual'  => $tipo === 'CURSO_INTENSIVO_VIRTUAL' ? 1750000 : 0,
                'precio_diplomado_hibrido'        => $tipo === 'DIPLOMADO_HIBRIDO' ? 3500000 : 0,
                'precio_diplomado_virtual'        => $tipo === 'DIPLOMADO_VIRTUAL' ? 2800000 : 0,
                
                'tipo_evento' => $tipo,
                'tiene_oferta_valor' => $faker->boolean(50),
                'oferta_valor' => 'Incluye material físico y certificado avalado.',
                
                'color_hex_secundario' => $faker->hexColor(),
                'estilo_temario' => $faker->randomElement(['lista', 'cuadricula', 'tarjetas']),
                'estilo_expertos' => $faker->randomElement(['lista', 'tarjetas']),
                'estilo_plantilla' => $faker->randomElement(['clasico', 'invertido', 'minimalista']),
                'estilo_card' => $faker->randomElement(['minimalista', 'destacado']),
                
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Tabla pivote: Asignar de 1 a 4 conferencistas a este evento
            $conferencistasAsignados = $faker->randomElements($conferencistasIds, rand(1, 4));
            
            foreach ($conferencistasAsignados as $confId) {
                DB::table('evento_conferencista')->insert([
                    'evento_id' => $eventoId,
                    'conferencista_id' => $confId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->command->info('¡Seeders ejecutados con éxito! Datos de prueba listos.');
    }
}