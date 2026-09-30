<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Verificación pública de credenciales
    |--------------------------------------------------------------------------
    |
    | La URL que codifica el QR (y la que se comparte) es {base_url}/verificar/{codigo}. La base NO se toma de
    | la petición del administrador: un QR impreso es permanente y debe apuntar siempre al dominio público.
    | Si CREDENTIAL_FLOW_VERIFICACION_URL no está definida se usa APP_URL. Si el dominio cambia algún día,
    | el anterior debe seguir sirviendo /verificar/{codigo} (o redirigir): los QR ya impresos no se pueden editar.
    |
    */
    'verificacion' => [
        'base_url' => env('CREDENTIAL_FLOW_VERIFICACION_URL'),
        'entidad' => 'F&C Consultores S.A.S.',

        // Límite general por IP y límite de consultas fallidas por IP (por minuto).
        'limite_por_minuto' => 60,
        'limite_fallos_por_minuto' => 15,
    ],

];
