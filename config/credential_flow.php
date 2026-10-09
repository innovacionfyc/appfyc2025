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

    /*
    |--------------------------------------------------------------------------
    | Correo transaccional (Fase 9)
    |--------------------------------------------------------------------------
    |
    | Solo configuración funcional: NO hay credenciales SMTP aquí (van en el .env mediante MAIL_*). Nunca se usa el transporte `log`
    | para códigos OTP salvo que se permita de forma explícita (solo para depurar en local): dejaría el código en un archivo de texto.
    |
    */
    'correo' => [
        // Interruptor general: en false no se envía nada (los desafíos OTP quedan registrados como «no enviados»).
        'habilitado' => (bool) env('CREDENTIAL_FLOW_CORREO_HABILITADO', true),

        // Remitente institucional. Si no se define, se usa MAIL_FROM_ADDRESS / MAIL_FROM_NAME.
        'remitente' => [
            'direccion' => env('CREDENTIAL_FLOW_CORREO_REMITENTE'),
            'nombre' => env('CREDENTIAL_FLOW_CORREO_REMITENTE_NOMBRE', 'F&C Consultores'),
        ],
        'reply_to' => [
            'direccion' => env('CREDENTIAL_FLOW_CORREO_REPLY_TO'),
            'nombre' => env('CREDENTIAL_FLOW_CORREO_REPLY_TO_NOMBRE', 'F&C Consultores'),
        ],

        // Protección contra picos accidentales: envíos lógicos por minuto y por hora, en toda la aplicación.
        'limite_global_por_minuto' => 30,
        'limite_global_por_hora' => 300,

        // Espera (ms) antes del ÚNICO reintento inmediato de un OTP tras un error temporal.
        'reintento_inmediato_ms' => 300,

        'permitir_transporte_log' => (bool) env('CREDENTIAL_FLOW_CORREO_PERMITIR_LOG', false),

        // Plantillas: código, versión, vistas (HTML y texto plano) e intentos máximos (1 inicial + reintentos inmediatos).
        'plantillas' => [
            'otp_acceso' => [
                'version' => 1,
                'html' => 'emails.credential-flow.codigo-acceso',
                'texto' => 'emails.credential-flow.codigo-acceso-texto',
                'max_intentos' => 2,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Portal público de certificados históricos (documento + correo + OTP)
    |--------------------------------------------------------------------------
    */
    // Identidad aprobada (Fases 10B-3B y 10B-3C-3). TRES interruptores, todos APAGADOS por defecto y sin variable en el .env:
    //  - decisiones_enabled: el portal consulta las decisiones de identidad (resolver + OTP con scope congelado).
    //  - multi_scope_enabled: la SESIÓN acepta un scope de varios grupos (SesionPortal::SOPORTA_MULTI_GRUPO es true desde 10B-3B-2 y solo se usa con este
    //    interruptor y el anterior).
    //  - mass_scope_enabled: acceso masivo (>= 100 certificados) con doble control; ver más abajo.
    'identidad' => [
        'decisiones_enabled' => (bool) env('CREDENTIAL_FLOW_IDENTIDAD_DECISIONES', false),
        'multi_scope_enabled' => (bool) env('CREDENTIAL_FLOW_IDENTIDAD_MULTI_SCOPE', false),
        // mass_scope_enabled (10B-3C-3): CONTROL OPERATIVO PERMANENTE (no es solo un andamio de implementación). Un scope masivo (>= 100 certificados) solo abre
        // el portal con los TRES interruptores encendidos Y una autorización con doble control (segundo administrador). Apagado: ningún OTP ni sesión masiva.
        'mass_scope_enabled' => (bool) env('CREDENTIAL_FLOW_IDENTIDAD_MASS_SCOPE', false),
    ],

    // INTERRUPTOR MAESTRO del portal público /certificados (solicitud y validación de OTP, sesión, panel, descarga y el correo del portal). Desplegar el código NO
    // activa el portal: apagado por defecto y si la variable no existe. Se cambia a mano, solo en una fase autorizada, tras `credential-flow:portal:preflight`.
    // Es independiente de los tres interruptores de identidad y NO afecta a la verificación pública `/verificar/{codigo}`.
    'portal_enabled' => (bool) env('CREDENTIAL_FLOW_PORTAL_ENABLED', false),

    'portal' => [
        'otp_vigencia_minutos' => 10,
        'otp_max_intentos' => 5,
        'reenvio_segundos' => 60,
        'otp_max_por_hora' => 3,
        'otp_max_por_dia' => 8,
        // Bloqueo temporal: tras `bloqueos_para_bloquear` desafíos bloqueados por intentos en la ventana, no se emiten más OTP.
        'bloqueo_minutos' => 30,
        'bloqueos_para_bloquear' => 3,
        'sesion_inactividad_minutos' => 30,
        'sesion_maxima_minutos' => 120,
        // Límites por IP (RateLimiter): solicitud de OTP, validación de OTP y descarga de PDF.
        'limite_solicitud_por_minuto' => 5,
        'limite_solicitud_por_hora' => 30,
        'limite_validacion_por_minuto' => 10,
        'limite_descarga_por_minuto' => 20,
        // Enlace de soporte (WhatsApp/contacto) si existe; si es null solo se muestra el texto.
        'contacto_url' => env('CREDENTIAL_FLOW_PORTAL_CONTACTO_URL'),
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
