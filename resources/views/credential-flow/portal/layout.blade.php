<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="referrer" content="no-referrer">
    <meta name="color-scheme" content="light">
    <title>@yield('titulo', 'Tus certificados') · F&amp;C Consultores</title>
    <style nonce="{{ $nonce }}">
        :root { --vino: #942934; --texto: #1e293b; --suave: #64748b; --borde: #e2e8f0; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: #f1f5f9; color: var(--texto); font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; line-height: 1.5; }
        main { width: 100%; max-width: 40rem; margin: 0 auto; padding: 1.25rem 1rem 2rem; }
        .marca { display: flex; align-items: center; gap: .6rem; margin: .5rem 0 1.25rem; font-weight: 800; letter-spacing: .02em; color: var(--vino); }
        .sello { display: inline-flex; width: 2.25rem; height: 2.25rem; align-items: center; justify-content: center; border-radius: .6rem; background: var(--vino); color: #fff; font-size: .95rem; }
        .tarjeta { background: #fff; border: 1px solid var(--borde); border-radius: 1.25rem; box-shadow: 0 1px 3px rgba(15, 23, 42, .08); padding: 1.25rem; margin-bottom: 1rem; }
        h1 { margin: 0 0 .5rem; font-size: 1.4rem; line-height: 1.25; }
        h2 { margin: 0 0 .25rem; font-size: 1.05rem; line-height: 1.3; }
        p { margin: .35rem 0; }
        .suave { color: var(--suave); font-size: .9rem; }
        label { display: block; margin: 1rem 0 .3rem; font-weight: 700; font-size: .9rem; }
        input[type=text], input[type=email] { width: 100%; padding: .8rem .9rem; border: 1px solid #cbd5e1; border-radius: .75rem; font-size: 1.05rem; background: #fff; color: var(--texto); }
        input:focus { outline: 3px solid #f3c9cd; border-color: var(--vino); }
        .codigo-input { letter-spacing: .5rem; text-align: center; font-size: 1.5rem; font-family: ui-monospace, Menlo, Consolas, monospace; }
        .boton { display: inline-block; margin-top: 1.1rem; padding: .8rem 1.4rem; border: 0; border-radius: .75rem; background: var(--vino); color: #fff; font-weight: 800; font-size: 1rem; cursor: pointer; text-decoration: none; }
        .boton.sec { background: #fff; color: var(--vino); border: 1px solid var(--vino); }
        .boton.ancho { width: 100%; text-align: center; }
        .aviso { background: #ecfeff; border: 1px solid #a5f3fc; color: #155e75; border-radius: .75rem; padding: .75rem .9rem; margin-bottom: 1rem; }
        .error { background: #fee2e2; border: 1px solid #fecaca; color: #7f1d1d; border-radius: .75rem; padding: .75rem .9rem; margin-bottom: 1rem; }
        .cert { display: grid; gap: .25rem; }
        .insignia { display: inline-block; font-size: .72rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; padding: .15rem .55rem; border-radius: 999px; background: #e2e8f0; color: #334155; }
        .insignia.ok { background: #dcfce7; color: #14532d; }
        .insignia.alerta { background: #fef3c7; color: #78350f; }
        .insignia.mal { background: #fee2e2; color: #7f1d1d; }
        .fila { display: flex; justify-content: space-between; align-items: center; gap: .75rem; flex-wrap: wrap; }
        form.en-linea { display: inline; }
        .pie { margin: 1rem .25rem 0; font-size: .78rem; color: var(--suave); text-align: center; }
        a { color: var(--vino); }
    </style>
</head>
<body>
<main>
    <div class="marca" aria-label="F&amp;C Consultores"><span class="sello" aria-hidden="true">F&amp;C</span> <span>{{ $entidad }}</span></div>

    @if (session('aviso'))<div class="aviso" role="status" data-mensaje="aviso">{{ session('aviso') }}</div>@endif
    @if (session('error'))<div class="error" role="alert" data-mensaje="error">{{ session('error') }}</div>@endif

    @yield('contenido')

    <p class="pie">Consulta de certificados de {{ $entidad }}</p>
</main>
</body>
</html>
