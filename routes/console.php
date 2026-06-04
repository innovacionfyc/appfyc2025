<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// La tarea de caducidad automática solo se registra si está habilitada
// explícitamente mediante EVENTOS_CADUCAR_AUTOMATICO=true en el .env.
if (config('eventos.caducar_automatico', false)) {
    Schedule::command('eventos:caducar')->everyMinute();
}