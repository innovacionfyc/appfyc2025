<?php

namespace App\Console\Commands;

use App\Models\CredentialFlow\AutorizacionDobleModalidad;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\Usuario;
use App\Support\CredentialFlow\Eventos\AutorizacionDobleModalidadException;
use App\Support\CredentialFlow\Eventos\AutorizacionesDobleModalidad;
use Illuminate\Console\Command;
use Throwable;

/**
 * Registra en el historial de autorizaciones (origen «configuracion_heredada») un evento que HOY autoriza la variable CREDENTIAL_FLOW_DOBLE_MODALIDAD_EVENTOS.
 * Es el paso de transición antes de retirar esa variable: el evento sigue autorizado igual, pero ahora también por la base de datos y con autor, fecha y motivo.
 *
 * Por defecto solo SIMULA (no escribe nada). Para registrar hacen falta --ejecutar, un SUPER-ADMIN (--usuario) y un motivo. No toca participantes, bases ni
 * certificados, ni el .env.
 */
class CredentialFlowDobleModalidadHeredar extends Command
{
    protected $signature = 'credential-flow:doble-modalidad-heredar
        {evento : Id del evento (cf_eventos) que hoy autoriza la variable heredada}
        {--usuario= : Id del super-admin que lo registra}
        {--motivo= : Por qué se registra (10 a 500 caracteres; queda en el historial y en Movimientos)}
        {--ejecutar : Escribe de verdad (sin esta opción solo simula)}';

    protected $description = 'Registra como autorización heredada (base de datos) un evento que hoy autoriza la variable de doble modalidad; por defecto solo simula';

    public function handle(AutorizacionesDobleModalidad $autorizaciones): int
    {
        $evento = EventoCertificacion::withTrashed()->find((int) $this->argument('evento'));
        if ($evento === null) {
            $this->error('No existe ese evento.');

            return self::FAILURE;
        }

        $d = $autorizaciones->diagnostico($evento, AutorizacionDobleModalidad::ORIGEN_HEREDADA);
        $this->line(($this->option('ejecutar') ? 'PLAN' : 'SIMULACIÓN (no se escribió nada)').": registrar la autorización heredada del evento #{$evento->id}");
        $this->line('  En la variable heredada: '.($d['heredado'] ? 'SÍ' : 'NO').' · autorizado hoy: '.($d['autorizado'] ? 'SÍ' : 'NO').' · origen: '.($d['origen'] ?? '—'));
        $i = $d['impacto'];
        $this->line("  Bases: {$i['bases']} · certificados vigentes (reales): {$i['emisiones_vigentes']} · documentos con dos participaciones: {$i['documentos_dobles']}"
            ." · programaciones activas: {$i['programaciones_activas']} · borradores en revisión: {$i['borradores_en_revision']}");

        $bloqueos = $d['bloqueos_autorizar'];
        if (! $d['heredado']) {
            $bloqueos[] = ['code' => AutorizacionDobleModalidadException::NO_HEREDADO, 'message' => 'Este evento no está en la variable heredada: autorízalo desde el panel.'];
        }
        foreach ($bloqueos as $b) {
            $this->error("  BLOQUEO {$b['code']}: {$b['message']}");
        }
        if (! $this->option('ejecutar')) {
            if ($bloqueos === []) {
                $this->comment('Para registrar: --usuario=ID --motivo="..." --ejecutar (requiere un super-admin). No cambia el .env ni ningún participante o certificado.');
            }

            return $bloqueos === [] ? self::SUCCESS : self::FAILURE;
        }

        $usuario = is_numeric($this->option('usuario')) ? Usuario::query()->with('perfilOrganizador.rol')->find((int) $this->option('usuario')) : null;
        try {
            $r = $autorizaciones->autorizar($evento, $usuario, (string) $this->option('motivo'), AutorizacionDobleModalidad::ORIGEN_HEREDADA);
        } catch (AutorizacionDobleModalidadException $e) {
            $this->error("No se cambió nada: {$e->getMessage()}");

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('No se cambió nada: ocurrió un error inesperado ('.class_basename($e).'). Revisa el registro.');
            report($e);

            return self::FAILURE;
        }

        $this->info("REGISTRADA: autorización #{$r['autorizacion_id']} (origen configuracion_heredada) · movimiento #{$r['movimiento_id']}. El evento sigue autorizado; "
            .'la variable heredada se puede retirar después, con su propia autorización.');

        return self::SUCCESS;
    }
}
