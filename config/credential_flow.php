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

        // Códigos LEGADO (numéricos cortos, enumerables): límite específico por IP, escalonado, sobre TODA consulta de código legado.
        'limite_legado_por_minuto' => 30,
        'limite_legado_por_hora' => 300,
    ],

    // Códigos HISTÓRICOS asignados por Credential Flow (Fase 10B-1.5) a certificados del sistema viejo que nunca se descargaron allí.
    // Rango RESERVADO de 5 dígitos, muy por encima del máximo histórico (11215) y compatible con el formato público ^\d{4,5}$. La fila del
    // contador (cf_codigo_historico_contador) guarda su propio inicio/fin: estos valores solo la inicializan. Antes del cutover debe pasar
    // `credential-flow:codigos-historicos:preflight`.
    'codigos_historicos' => [
        'inicio' => 50000,
        'fin' => 99999,
    ],

];
