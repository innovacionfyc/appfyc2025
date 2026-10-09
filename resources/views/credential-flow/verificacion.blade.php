<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="referrer" content="no-referrer">
    <meta name="color-scheme" content="light">
    <title>Verificación de credencial · F&amp;C Consultores</title>
    <style nonce="{{ $nonce }}">
        :root { --vino: #942934; --texto: #1e293b; --suave: #64748b; --borde: #e2e8f0; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: #f1f5f9; color: var(--texto); font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; line-height: 1.5; }
        main { width: 100%; max-width: 32rem; margin: 0 auto; padding: 1.25rem 1rem 2rem; }
        .marca { display: flex; align-items: center; gap: .6rem; margin: .5rem 0 1.25rem; font-weight: 800; letter-spacing: .02em; color: var(--vino); }
        .marca span.sello { display: inline-flex; width: 2.25rem; height: 2.25rem; align-items: center; justify-content: center; border-radius: .6rem; background: var(--vino); color: #fff; font-size: .95rem; }
        .tarjeta { background: #fff; border: 1px solid var(--borde); border-radius: 1.25rem; box-shadow: 0 1px 3px rgba(15, 23, 42, .08); overflow: hidden; }
        .estado { display: flex; align-items: center; gap: .85rem; padding: 1.25rem 1.25rem; border-bottom: 1px solid var(--borde); }
        .estado svg { flex: none; width: 2.75rem; height: 2.75rem; }
        .estado h1 { margin: 0; font-size: 1.35rem; line-height: 1.2; }
        .estado p { margin: .15rem 0 0; font-size: .9rem; }
        .valida { background: #dcfce7; color: #14532d; }
        .revocada { background: #fee2e2; color: #7f1d1d; }
        .neutra { background: #f1f5f9; color: #334155; }
        .aviso { background: #fef3c7; color: #78350f; }
        dl { margin: 0; padding: 1.25rem; display: grid; gap: 1rem; }
        dt { font-size: .72rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--suave); }
        dd { margin: .1rem 0 0; font-size: 1.02rem; font-weight: 600; overflow-wrap: anywhere; }
        dd.nombre { font-size: 1.25rem; font-weight: 800; }
        dd.codigo { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .95rem; letter-spacing: .04em; }
        .nota { margin: 0; padding: 0 1.25rem 1.25rem; font-size: .9rem; color: var(--suave); }
        .arriba { padding-top: 1.25rem; }
        .pie { margin: 1rem .25rem 0; font-size: .78rem; color: var(--suave); text-align: center; }
    </style>
</head>
<body>
<main>
    <div class="marca" aria-label="F&amp;C Consultores"><span class="sello" aria-hidden="true">F&amp;C</span> <span>{{ $entidad }}</span></div>

    <section class="tarjeta" data-estado="{{ $estado }}">
        @if ($estado === 'valida')
            <div class="estado valida" role="status">
                <svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="4"/><path d="M14 25l7 7 13-15" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <div><h1>Credencial válida</h1><p>Credencial emitida por {{ $entidad }}</p></div>
            </div>
            <dl>
                <div><dt>Nombre</dt><dd class="nombre" data-dato="nombre">{{ $nombre }}</dd></div>
                <div><dt>Evento</dt><dd data-dato="evento">{{ $evento }}</dd></div>
                @if (($fecha_evento ?? '') !== '')<div><dt>Fecha del evento</dt><dd data-dato="fecha-evento">{{ $fecha_evento }}</dd></div>@endif
                @if (($intensidad ?? '') !== '')<div><dt>Intensidad horaria</dt><dd data-dato="intensidad">{{ $intensidad }}</dd></div>@endif
                <div><dt>Fecha de emisión</dt><dd data-dato="emitida">{{ $emitida }}</dd></div>
                <div><dt>Emitida por</dt><dd>{{ $entidad }}</dd></div>
                <div><dt>Código de verificación</dt><dd class="codigo" data-dato="codigo">{{ $codigo }}</dd></div>
            </dl>
        @elseif ($estado === 'revocada')
            <div class="estado revocada" role="status">
                <svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="4"/><path d="M16 16l16 16M32 16L16 32" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round"/></svg>
                <div><h1>Credencial revocada</h1><p>Esta credencial ya no es válida.</p></div>
            </div>
            <dl>
                <div><dt>Evento</dt><dd data-dato="evento">{{ $evento }}</dd></div>
                @if (($fecha_evento ?? '') !== '')<div><dt>Fecha del evento</dt><dd data-dato="fecha-evento">{{ $fecha_evento }}</dd></div>@endif
                <div><dt>Fecha de emisión</dt><dd data-dato="emitida">{{ $emitida }}</dd></div>
                <div><dt>Código de verificación</dt><dd class="codigo" data-dato="codigo">{{ $codigo }}</dd></div>
            </dl>
            <p class="nota">Esta credencial ya no es válida. Si recibió una versión más reciente, verifique esa.</p>
        @elseif ($estado === 'legado_valido')
            <div class="estado valida" role="status">
                <svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="4"/><path d="M14 25l7 7 13-15" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <div><h1>Certificado histórico válido</h1><p>Certificado emitido por {{ $entidad }}</p></div>
            </div>
            <dl>
                <div><dt>Evento</dt><dd data-dato="evento">{{ $evento }}</dd></div>
                @if ($anio !== null)<div><dt>Año</dt><dd data-dato="anio">{{ $anio }}</dd></div>@endif
                <div><dt>Estado</dt><dd data-dato="estado">Válido</dd></div>
                <div><dt>Origen</dt><dd data-dato="origen">Certificado histórico (sistema anterior)</dd></div>
                <div><dt>Código de verificación</dt><dd class="codigo" data-dato="codigo">{{ $codigo }}</dd></div>
            </dl>
        @elseif ($estado === 'legado_revision')
            <div class="estado aviso" role="status">
                <svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="4"/><path d="M24 12v14M24 32v4" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round"/></svg>
                <div><h1>Certificado histórico en revisión</h1><p>Este certificado histórico se encuentra en revisión.</p></div>
            </div>
            <p class="nota arriba">Existe un registro histórico con ese código. Si necesita más información, comuníquese con la entidad emisora ({{ $entidad }}).</p>
        @elseif ($estado === 'legado_revocado')
            <div class="estado revocada" role="status">
                <svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="4"/><path d="M16 16l16 16M32 16L16 32" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round"/></svg>
                <div><h1>Certificado revocado</h1><p>Este certificado ya no es válido.</p></div>
            </div>
            <dl>
                <div><dt>Evento</dt><dd data-dato="evento">{{ $evento }}</dd></div>
                @if ($anio !== null)<div><dt>Año</dt><dd data-dato="anio">{{ $anio }}</dd></div>@endif
                <div><dt>Origen</dt><dd data-dato="origen">Certificado histórico (sistema anterior)</dd></div>
                <div><dt>Código de verificación</dt><dd class="codigo" data-dato="codigo">{{ $codigo }}</dd></div>
            </dl>
        @elseif ($estado === 'legado_reemplazado')
            <div class="estado neutra" role="status">
                <svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="4"/><path d="M14 24h20M27 17l7 7-7 7" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <div><h1>Certificado histórico reemplazado</h1><p>Este certificado histórico fue reemplazado por una versión posterior.</p></div>
            </div>
            <dl>
                <div><dt>Evento</dt><dd data-dato="evento">{{ $evento }}</dd></div>
                @if ($anio !== null)<div><dt>Año</dt><dd data-dato="anio">{{ $anio }}</dd></div>@endif
                <div><dt>Código de verificación</dt><dd class="codigo" data-dato="codigo">{{ $codigo }}</dd></div>
            </dl>
            @if (! empty($enlace_moderno))<p class="nota"><a href="{{ $enlace_moderno }}" data-enlace="version-posterior">Verificar la versión posterior</a></p>@endif
        @elseif ($estado === 'limitada')
            <div class="estado aviso" role="status">
                <svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="4"/><path d="M24 12v14M24 32v4" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round"/></svg>
                <div><h1>Demasiadas consultas</h1><p>Espere un minuto e inténtelo de nuevo.</p></div>
            </div>
        @else
            <div class="estado neutra" role="status">
                <svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="4"/><path d="M18 19c0-4 3-6 6-6s6 2 6 5c0 4-6 5-6 9M24 35v1" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round"/></svg>
                <div><h1>Credencial no encontrada</h1><p>No existe ninguna credencial con ese código.</p></div>
            </div>
            <p class="nota arriba">Revise que el código esté completo y sin errores. Si el problema continúa, comuníquese con la entidad emisora ({{ $entidad }}).</p>
        @endif
    </section>

    <p class="pie">Verificación de credenciales de {{ $entidad }}</p>
</main>
</body>
</html>
