<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\EventoCertificacion;
use App\Support\CredentialFlow\Eventos\AutorizacionesDobleModalidad;
use App\Support\CredentialFlow\Participantes\DobleModalidad;

/** Las mismas pruebas del portal con doble modalidad, con el evento autorizado en la base de datos por un super-admin y sin la variable heredada. */
class PortalDobleModalidadBaseDeDatosTest extends PortalDobleModalidadTest
{
    protected function autorizarEvento(EventoCertificacion $evento): void
    {
        config(['credential_flow.doble_modalidad.eventos' => null]);
        app(AutorizacionesDobleModalidad::class)->autorizar($evento, $this->superAdmin()->load('perfilOrganizador.rol'), 'Autorización sintética de prueba');
        $this->assertSame(DobleModalidad::ORIGEN_BASE_DE_DATOS, DobleModalidad::origen($evento->id));
    }
}
