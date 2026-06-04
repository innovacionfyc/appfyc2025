<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Caducidad automática de eventos
    |--------------------------------------------------------------------------
    | Si se establece en true, el scheduler ejecutará eventos:caducar cada
    | minuto para mover eventos finalizados al estado "caducados".
    |
    | Por defecto está desactivado. Para habilitarlo en un entorno específico,
    | agregar en el .env:
    |   EVENTOS_CADUCAR_AUTOMATICO=true
    |
    | IMPORTANTE: Activar solo si el scheduler (schedule:run vía cron) está
    | configurado explícitamente en el servidor.
    */
    'caducar_automatico' => env('EVENTOS_CADUCAR_AUTOMATICO', false),
];
