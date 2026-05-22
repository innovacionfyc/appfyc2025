<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Evento;
use App\Models\Movimiento;
use Carbon\Carbon;

class CerrarEventosCaducados extends Command
{
    protected $signature = 'eventos:caducar';
    protected $description = 'Mueve los eventos finalizados a la papelera automáticamente por fecha y hora exacta';

    public function handle()
    {
        $eventosCaducados = Evento::where('estado_id', 1)
            ->where('fecha_hora_fin', '<=', Carbon::now())
            ->get();

        if ($eventosCaducados->isEmpty()) {
            $this->info('[' . Carbon::now()->format('Y-m-d H:i:s') . '] No hay eventos por caducar en este minuto.');
            return;
        }

        foreach ($eventosCaducados as $evento) {
            $evento->update([
                'estado_id' => 5,
                'texto_dinamico' => $evento->texto_dinamico . ' [en "caducados" por culminación del mismo]'
            ]);

            Movimiento::create([
                'user_id' => null,
                'tipo' => 'eliminacion',
                'modulo' => 'cambio automático',
                'descripcion' => "El evento '{$evento->titulo}' fue movido a 'caducados' por caducidad automática.",
                'metadata' => ['ip' => '127.0.0.1', 'ua' => 'CLI / Cron Job'],
                'created_at' => now()
            ]);

            $this->info("Evento ID {$evento->id} caducado con éxito a las " . now()->format('H:i:s'));
        }
    }
}