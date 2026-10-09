<?php

namespace Tests\Feature\CredentialFlow\StagingEv;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base de las pruebas del staging histórico. Solo SQLite en memoria (nunca la base local) y SOLO datos sintéticos
 * (ver FixturesStagingEv): ningún nombre, documento, correo ni token de estas pruebas pertenece a una persona real.
 */
abstract class StagingEvTestCase extends TestCase
{
    use FixturesStagingEv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas del staging solo pueden correr sobre SQLite.');
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $this->artisan('migrate', ['--path' => 'database/staging/ev', '--force' => true])->assertExitCode(0);
    }
}
