<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Evento;
use App\Models\Movimiento;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CerrarEventosCaducados extends Command
{
    // El nombre que usaría el comando manualmente
    protected $signature = 'eventos:caducar';
    protected $description = 'Mueve los eventos finalizados a la papelera automáticamente';

    public function handle(Request $request)
    {
        $eventosCaducados = Evento::where('estado_id', 1)
            ->where('fecha_hora_fin', '<', Carbon::now())
            ->get();

        foreach ($eventosCaducados as $evento) {

            $evento->update([
                'estado_id' => 5,
                'texto_dinamico' => $evento->texto_dinamico . ' [en papelera por caducidad]'
            ]);


            Movimiento::create([
                'user_id' => null,
                'tipo' => 'eliminacion',
                'modulo' => 'cambio automático',
                'descripcion' => "El evento '{$evento->titulo}' fue movido a papelera por caducidad automática.",
                'metadata' => ['ip' => $request->ip(), 'ua' => $request->userAgent()],
                'created_at' => now()
            ]);

            $this->info("Evento ID {$evento->id} caducado con éxito.");
        }
    }
}
