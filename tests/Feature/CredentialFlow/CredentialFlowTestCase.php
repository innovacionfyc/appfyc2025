<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Plantilla;
use App\Models\Usuario;
use Database\Seeders\AreaFormacionSeeder;
use Database\Seeders\EquipoSeeder;
use Database\Seeders\EstadosSeeder;
use Database\Seeders\RolSeeder;
use Database\Seeders\TipoDocumentosSeeder;
use Database\Seeders\UsuarioSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Base de las pruebas de Credential Flow.
 *
 * No usa RefreshDatabase: varias migraciones existentes de `eventos` usan ALTER TABLE ... MODIFY
 * (solo MySQL) y no se pueden ejecutar sobre SQLite. En su lugar se migran únicamente las tablas
 * que necesita el módulo (autenticación/roles + cf_plantillas) sobre SQLite en memoria, que es
 * distinta en cada prueba. Los discos `local` y `public` son falsos.
 */
abstract class CredentialFlowTestCase extends TestCase
{
    /** Migraciones mínimas necesarias (rutas relativas a la raíz del proyecto). */
    private const MIGRACIONES = [
        'database/migrations/2026_02_23_173914_estado_table.php',
        'database/migrations/2026_02_23_174005_tipos_documento_table.php',
        'database/migrations/2026_02_23_174030_roles_table.php',
        'database/migrations/2026_02_23_174108_areas_formacion_table.php',
        'database/migrations/2026_02_23_174226_equipos_fyc_table.php',
        'database/migrations/2026_02_23_174318_usuarios_table.php',
        'database/migrations/2026_02_23_174423_perfil_organizadores_table.php',
        'database/migrations/2026_02_23_174512_perfil_conferencistas_table.php',
        'database/migrations/2026_02_26_205405_create_movimientos_table.php',
        'database/migrations/2026_09_29_160000_cf_plantillas_table.php',
        'database/migrations/2026_09_30_100000_cf_lotes_table.php',
        'database/migrations/2026_09_30_100100_cf_participantes_table.php',
        'database/migrations/2026_09_30_200000_cf_emisiones_table.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Salvaguarda: estas pruebas nunca deben tocar la base local (MySQL).
        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas de Credential Flow solo pueden correr sobre SQLite.');
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $this->registrarRutasSiFaltan();

        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');

        $this->artisan('migrate', ['--path' => self::MIGRACIONES, '--force' => true])->assertExitCode(0);

        $this->seed([
            EstadosSeeder::class,
            TipoDocumentosSeeder::class,
            AreaFormacionSeeder::class,
            EquipoSeeder::class,
            RolSeeder::class,
            UsuarioSeeder::class,
        ]);
    }

    /**
     * routes/web.php carga routes/web/** con require_once: en la segunda aplicación creada dentro
     * del mismo proceso (es lo que hace PHPUnit entre pruebas) esos archivos ya figuran como
     * incluidos y no se registra ninguna ruta. Solo en ese caso se vuelven a cargar, con el mismo
     * grupo `web` que usa el arranque real. No se modifica routes/web.php.
     */
    private function registrarRutasSiFaltan(): void
    {
        if (Route::has('credential-flow.plantillas.index')) {
            return;
        }

        Route::middleware('web')->group(function () {
            foreach (File::allFiles(base_path('routes/web')) as $archivo) {
                require $archivo->getPathname();
            }
        });

        $rutas = app('router')->getRoutes();
        $rutas->refreshNameLookups();
        $rutas->refreshActionLookups();
    }

    protected function usuario(string $correo): Usuario
    {
        return Usuario::where('correo_principal', $correo)->firstOrFail();
    }

    /** Rol `admin`. */
    protected function admin(): Usuario
    {
        return $this->usuario('erik@fycconsultores.com');
    }

    protected function superAdmin(): Usuario
    {
        return $this->usuario('jhoann@fycconsultores.com');
    }

    /** Rol `comercial` (sin acceso a Credential Flow). */
    protected function comercial(): Usuario
    {
        return $this->usuario('ale@fycconsultores.com');
    }

    protected function pdfContenido(string $extra = ''): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
            ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 792 612] >>\nendobj\n"
            ."trailer\n<< /Root 1 0 R /Size 4 >>\n%%EOF\n".$extra;
    }

    protected function pdf(string $nombre = 'Certificado base.pdf', string $extra = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, $this->pdfContenido($extra));
    }

    /** Crea una plantilla por el flujo real (petición HTTP) y la devuelve. */
    protected function crearPlantilla(array $datos = []): Plantilla
    {
        $this->actingAs($this->admin())
            ->post(route('credential-flow.plantillas.store'), array_merge([
                'nombre' => 'Plantilla de prueba',
                'descripcion' => 'Descripción de prueba',
                'pdf' => $this->pdf(),
            ], $datos))
            ->assertSessionHasNoErrors();

        return Plantilla::latest('id')->firstOrFail();
    }
}
