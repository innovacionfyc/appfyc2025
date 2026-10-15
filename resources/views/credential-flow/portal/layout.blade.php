@php
    $entidad = $entidad ?? config('credential_flow.verificacion.entidad');
    $contacto = $contacto ?? null;
    $sinSesion = $sinSesion ?? false;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="referrer" content="no-referrer">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#942934">
    <title>@yield('titulo', 'Tus certificados') · F&amp;C Consultores</title>
    <link rel="icon" href="/images/logo-fyc-web.png" type="image/png">
    <style nonce="{{ $nonce }}">
        /* Lenguaje visual de appfyc: Figtree, vinotinto/naranja de marca, slate, bordes redondeados, transiciones de 300 ms y encabezado de vidrio. */
        :root {
            --vino: #942934; --rosa: #D32F57; --naranja: #E96510; --foco: #e11d48;
            --texto: #0f172a; --suave: #64748b; --tenue: #94a3b8; --borde: #e2e8f0; --fondo: #f8fafc;
            --ok-fondo: #dcfce7; --ok-texto: #14532d; --alerta-fondo: #fef3c7; --alerta-texto: #78350f; --mal-fondo: #fee2e2; --mal-texto: #7f1d1d;
            --sombra: 0 1px 2px rgba(15, 23, 42, .04), 0 18px 40px -22px rgba(15, 23, 42, .22);
        }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0; min-height: 100vh; display: flex; flex-direction: column; color: var(--texto); font-family: "Figtree", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            line-height: 1.5; background-color: #fff;
            background-image: radial-gradient(800px 300px at 15% -20%, rgba(211, 47, 87, .08), transparent 60%), radial-gradient(800px 300px at 85% -20%, rgba(243, 147, 34, .08), transparent 60%);
            background-repeat: no-repeat;
        }
        ::selection { background: var(--vino); color: #fff; }
        a { color: var(--vino); }
        img { max-width: 100%; }

        .cabecera {
            position: sticky; top: 0; z-index: 10; background: rgba(255, 255, 255, .72); -webkit-backdrop-filter: blur(16px); backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(226, 232, 240, .7);
        }
        .cabecera-in { max-width: 72rem; margin: 0 auto; padding: .65rem 1rem; display: flex; align-items: center; gap: 1rem; }
        .logo { display: inline-flex; align-items: center; border-radius: .75rem; transition: transform .3s cubic-bezier(.4, 0, .2, 1); }
        .logo:active { transform: scale(.95); }
        .logo img { display: block; height: 2.6rem; width: auto; }
        .seccion { display: none; align-items: center; gap: 1rem; font-size: .72rem; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: var(--suave); }
        .seccion::before { content: ""; width: 1px; height: 1.4rem; background: var(--borde); }
        .acciones { margin-left: auto; display: flex; align-items: center; gap: .6rem; }

        main { flex: 1; width: 100%; max-width: 72rem; margin: 0 auto; padding: 1.75rem 1rem 2rem; }
        .estrecho { max-width: 28rem; margin-left: auto; margin-right: auto; }
        .aparece { animation: aparece .45s cubic-bezier(.4, 0, .2, 1) both; }
        @keyframes aparece { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) { .aparece { animation: none; } * { transition: none !important; } }

        .paso { margin: 0 0 .5rem; font-size: .7rem; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; color: var(--vino); }
        h1 { margin: 0 0 .5rem; font-size: 1.5rem; line-height: 1.2; font-weight: 800; letter-spacing: -.015em; color: var(--texto); }
        h2 { margin: 0; font-size: 1rem; line-height: 1.35; font-weight: 700; }
        p { margin: .35rem 0; }
        .suave { color: var(--suave); font-size: .92rem; }
        .pasos { list-style: none; display: flex; gap: .35rem; margin: 0 0 1.1rem; padding: 0; }
        .pasos li { flex: 1; height: .25rem; border-radius: 999px; background: var(--borde); transition: background .3s; }
        .pasos li.hecho, .pasos li.activo { background: linear-gradient(90deg, var(--rosa), var(--vino)); }

        .tarjeta { background: #fff; border: 1px solid var(--borde); border-radius: 1.5rem; box-shadow: var(--sombra); padding: 1.6rem 1.4rem; }

        label { display: block; margin: 1.15rem 0 .4rem; padding: 0 .25rem; font-weight: 700; font-size: .8rem; color: var(--tenue); transition: color .3s; }
        .campo:focus-within > label { color: var(--foco); }
        .caja { display: flex; align-items: center; gap: .65rem; padding: .1rem 1rem; background: var(--fondo); border: 2px solid #e2e8f0; border-radius: .9rem; transition: all .3s cubic-bezier(.4, 0, .2, 1); }
        .caja:hover { border-color: #cbd5e1; }
        .caja:focus-within { background: #fff; border-color: var(--foco); box-shadow: 0 0 0 4px rgba(225, 29, 72, .15), 0 12px 24px -14px rgba(15, 23, 42, .3); }
        .caja svg { flex: none; width: 1.2rem; height: 1.2rem; color: #cbd5e1; transition: color .3s; }
        .caja:focus-within svg { color: var(--foco); }
        .caja input, .caja select { flex: 1; min-width: 0; width: 100%; padding: .78rem 0; border: 0; background: transparent; font: inherit; font-size: 1rem; font-weight: 600; color: #334155; outline: 0; }
        .caja input::placeholder { color: #cbd5e1; font-weight: 500; }
        .caja select { cursor: pointer; }
        .caja:has(input:user-invalid) { border-color: #ef4444; background: #fef2f2; }
        .caja.codigo { justify-content: center; }
        .caja.codigo input { text-align: center; letter-spacing: .7rem; padding-left: .7rem; font-size: 1.75rem; font-family: ui-monospace, Menlo, Consolas, monospace; font-weight: 700; padding-top: .7rem; padding-bottom: .7rem; }

        .boton {
            position: relative; display: inline-flex; align-items: center; justify-content: center; gap: .5rem; margin-top: 1.4rem; padding: .85rem 1.4rem; border: 1px solid rgba(255, 255, 255, .1); border-radius: 1rem;
            background: linear-gradient(135deg, var(--naranja), #ea580c); color: #fff; font: inherit; font-size: .95rem; font-weight: 800; letter-spacing: .02em; text-align: center; text-decoration: none; cursor: pointer;
            box-shadow: 0 4px 6px -1px rgba(249, 115, 22, .25); transition: all .3s cubic-bezier(.4, 0, .2, 1);
        }
        .boton:hover { transform: translateY(-2px); box-shadow: 0 10px 20px -5px rgba(249, 115, 22, .35); }
        .boton:active { transform: scale(.98); }
        .boton:focus-visible { outline: 0; box-shadow: 0 0 0 4px rgba(233, 101, 16, .3); }
        .boton.ancho { width: 100%; }
        .boton.sec { background: #fff; color: #334155; border: 1px solid var(--borde); box-shadow: none; }
        .boton.sec:hover { background: var(--fondo); border-color: #cbd5e1; box-shadow: none; }
        .boton.chico { margin-top: 0; padding: .5rem .95rem; font-size: .82rem; border-radius: .8rem; }
        .boton.cargando { pointer-events: none; opacity: .85; }
        .boton.cargando::after { content: ""; width: 1rem; height: 1rem; border: 2px solid rgba(255, 255, 255, .5); border-top-color: #fff; border-radius: 50%; animation: gira .7s linear infinite; }
        .boton.sec.cargando::after { border-color: rgba(51, 65, 85, .3); border-top-color: #334155; }
        @keyframes gira { to { transform: rotate(360deg); } }
        .enlace { background: none; border: 0; padding: 0; font: inherit; font-weight: 700; color: var(--vino); text-decoration: none; cursor: pointer; transition: color .3s; }
        .enlace:hover { color: var(--rosa); text-decoration: underline; }

        .aviso, .error { display: flex; gap: .6rem; padding: .8rem 1rem; margin: 0 0 1rem; border-radius: .9rem; font-size: .9rem; font-weight: 600; }
        .aviso { background: #fff7ed; border: 1px solid #fed7aa; color: #7c2d12; }
        .error { background: #fef2f2; border: 1px solid #fecaca; color: var(--mal-texto); }

        .fila { display: flex; justify-content: space-between; align-items: center; gap: .75rem; flex-wrap: wrap; }
        form.en-linea { display: inline; }
        .enlaces { margin-top: 1.1rem; padding-top: 1rem; border-top: 1px solid var(--borde); display: flex; justify-content: space-between; align-items: center; gap: .75rem; flex-wrap: wrap; font-size: .88rem; }
        .centrado { text-align: center; }

        .insignia { display: inline-block; font-size: .66rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; padding: .12rem .5rem; border-radius: 999px; background: var(--alerta-fondo); color: var(--alerta-texto); white-space: nowrap; }
        .insignia.ok { background: var(--ok-fondo); color: var(--ok-texto); }
        .insignia.mal { background: var(--mal-fondo); color: var(--mal-texto); }
        .insignia.neutra { background: #f1f5f9; color: #475569; }

        .soporte { margin: 1.25rem auto 0; display: flex; align-items: center; justify-content: center; gap: .5rem; padding: 0 .5rem; font-size: .84rem; color: var(--suave); text-align: center; }
        .soporte svg { flex: none; width: 1.1rem; height: 1.1rem; color: #16a34a; }
        .soporte a { font-weight: 700; text-decoration: none; }
        .soporte a:hover { text-decoration: underline; }
        .pie { padding: 1rem; font-size: .75rem; color: var(--tenue); text-align: center; }

        @media (min-width: 640px) {
            .logo img { height: 3rem; }
            .cabecera-in { padding: .7rem 1.5rem; }
            main { padding: 2.5rem 1.5rem 2.5rem; }
            .estrecho { padding-top: 1.25rem; }
            .seccion { display: inline-flex; }
            h1 { font-size: 1.85rem; }
            .tarjeta { padding: 2rem 1.9rem; }
        }
        @media (min-width: 1024px) { .logo img { height: 3.25rem; } .estrecho { padding-top: 2.25rem; } }
    </style>
    @stack('estilos')
</head>
<body>
<header class="cabecera">
    <div class="cabecera-in">
        <a class="logo" href="/" aria-label="F&amp;C Consultores"><img src="/images/logo-fyc.png" alt="F&amp;C Consultores" width="133" height="52"></a>
        <span class="seccion">Certificados</span>
        <div class="acciones">@yield('acciones')</div>
    </div>
</header>

<main>
    <div class="@yield('ancho')">
        @if (! $sinSesion && session('aviso'))<div class="aviso" role="status" data-mensaje="aviso">{{ session('aviso') }}</div>@endif
        @if (! $sinSesion && session('error'))<div class="error" role="alert" data-mensaje="error">{{ session('error') }}</div>@endif
    </div>

    @yield('contenido')

    @if (! empty($contacto))
        <p class="soporte" data-soporte="whatsapp">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2a9.9 9.9 0 0 0-8.5 14.9L2 22l5.25-1.5A9.9 9.9 0 1 0 12.04 2Zm0 18.1a8.2 8.2 0 0 1-4.2-1.15l-.3-.18-3.1.9.93-3.02-.2-.31a8.2 8.2 0 1 1 6.87 3.76Zm4.5-6.15c-.25-.12-1.46-.72-1.69-.8-.23-.09-.39-.12-.56.12-.16.25-.64.8-.78.96-.15.17-.29.19-.54.06a6.7 6.7 0 0 1-3.3-2.88c-.25-.43.25-.4.72-1.33.08-.16.04-.3-.02-.42-.06-.12-.56-1.35-.77-1.85-.2-.48-.4-.42-.56-.43h-.47c-.16 0-.43.06-.65.3-.23.25-.86.85-.86 2.07s.88 2.4 1 2.57c.12.16 1.74 2.65 4.2 3.72 1.56.67 2.17.73 2.95.62.48-.07 1.46-.6 1.67-1.18.2-.58.2-1.07.15-1.18-.07-.1-.23-.16-.48-.28Z"/></svg>
            <span>¿Tienes problemas para acceder o descargar tu certificado? <a href="{{ $contacto }}" target="_blank" rel="noopener noreferrer">Escríbenos por WhatsApp.</a></span>
        </p>
    @endif
</main>

<footer class="pie">&copy; {{ date('Y') }} F&amp;C Consultores S.A.S. · Consulta de certificados</footer>

<script nonce="{{ $nonce }}">
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f.matches || !f.matches('[data-cargando]')) { return; }
        var b = f.querySelector('button[type=submit]');
        if (b) { b.classList.add('cargando'); b.setAttribute('aria-busy', 'true'); setTimeout(function () { b.disabled = true; }, 0); }
    });
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('button.cargando').forEach(function (b) { b.classList.remove('cargando'); b.disabled = false; b.removeAttribute('aria-busy'); });
    });
</script>
</body>
</html>
