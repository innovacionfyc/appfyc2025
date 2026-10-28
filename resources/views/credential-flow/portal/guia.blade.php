@extends('credential-flow.portal.layout')
@section('titulo', 'Cómo descargar tu certificado')
@section('acciones')
    <a class="g-ayuda" href="#ayuda" data-abrir-ayuda><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .8-1 1.5v.4"/><path d="M12 17h.01"/></svg><span class="g-corto">Ayuda</span><span class="g-ancho">¿Necesitas ayuda?</span></a>
@endsection
@php
    // Pantallas de la recreación del portal: SOLO datos ficticios (nunca de un participante real).
    $logo = '/images/logo-fyc.png';
    $vigencia = (int) config('credential_flow.portal.otp_vigencia_minutos');
    // La dirección se parte solo después de cada «/», nunca a mitad del dominio («fycconsultores.com/» + «certificados»).
    $direccionPartida = collect(explode('/', $direccion))->map(fn ($s) => '<span class="nw">'.e($s).'</span>')->implode('/<wbr>');
    $pasos = [1 => 'Entra a la página', 'Escribe tus datos', 'Revisa tu correo', 'Escribe el código', 'Busca tu certificado', 'Descárgalo'];
    $repetir = '<button class="btn-repetir solo-js" type="button" data-repetir><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>Ver la animación otra vez</button>';
    $info = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>';
    $toque = '<span class="toque"><span class="toque-anillo"></span><span class="toque-punto"></span><span class="toque-burbuja"><span class="tactil">Toca aquí</span><span class="raton">Haz clic aquí</span></span></span>';
@endphp
@push('estilos')
    <style nonce="{{ $nonce }}">
        /* Guía «¿Cómo descargar mi certificado?»: mismo lenguaje visual del portal (vinotinto, naranja de acción, slate) con animaciones que se apagan con movimiento reducido. */
        .guia { --pri: var(--vino); --pri2: #6e1d27; --tinte: #fbf0f1; --tinte2: #fff5ec; --oliva: #5e5a2a; --oro: #c08a2e; font-size: 17px; line-height: 1.55; }
        .guia button { font-family: inherit; }
        .guia button:focus-visible, .guia a:focus-visible, .g-ayuda:focus-visible { outline: 3px solid var(--rosa); outline-offset: 3px; }
        .guia [tabindex="-1"]:focus { outline: 0; }
        .solo-lector { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); border: 0; white-space: nowrap; }
        /* El deslizamiento entre pasos nunca crea desplazamiento lateral (clip no convierte a <main> en contenedor de desplazamiento: lo pegajoso sigue funcionando). */
        main { overflow-x: clip; }
        /* Instrucciones según el dispositivo: «Toca» en pantallas táctiles y «Haz clic» con ratón. */
        .raton { display: none; }
        @media (hover: hover) and (pointer: fine) { .tactil { display: none; } .raton { display: inline; } }
        /* Sin JavaScript los pasos se leen uno debajo del otro; con JavaScript se ve una pantalla a la vez. */
        .solo-js { display: none; }
        .con-js .btn-copiar.solo-js, .con-js .btn-repetir.solo-js { display: inline-flex; }
        .con-js .pantalla:not(.activa) { display: none; }
        .pantalla + .pantalla { margin-top: 3rem; }
        .con-js .pantalla + .pantalla { margin-top: 0; }

        .g-ayuda { display: inline-flex; align-items: center; gap: .5rem; min-height: 44px; padding: 0 1rem; border: 1.5px solid var(--borde); border-radius: 999px; background: #fff; color: var(--vino); font-size: 1rem; font-weight: 700; text-decoration: none; transition: border-color .2s, background .2s; }
        .g-ayuda:hover { border-color: var(--vino); background: #fbf0f1; }
        .g-ayuda svg { width: 1.25rem; height: 1.25rem; }
        .g-ancho { display: none; }

        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; min-height: 56px; padding: 0 22px; border-radius: 18px; font-size: 19px; font-weight: 800; letter-spacing: .01em; text-decoration: none; cursor: pointer; transition: transform .2s, box-shadow .2s, background .2s, border-color .2s; }
        .btn svg { width: 22px; height: 22px; flex: none; }
        .btn-pri { border: 0; color: #fff; background: linear-gradient(135deg, #d24b0e, #b83d09); box-shadow: 0 10px 22px -12px rgba(194, 65, 12, .75); }
        .btn-pri:hover { color: #fff; transform: translateY(-2px); box-shadow: 0 16px 28px -12px rgba(194, 65, 12, .8); }
        .btn-pri:active { transform: scale(.98); }
        .btn-sec { border: 2px solid var(--borde); background: #fff; color: #334155; }
        .btn-sec:hover { color: #334155; border-color: #cbd5e1; background: var(--fondo); }

        .ceja { margin: 0 0 8px; font-size: 13px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; color: var(--pri); }
        .enlace-texto { font-weight: 800; color: var(--pri); text-underline-offset: 3px; }

        /* Bienvenida */
        .bienvenida { display: grid; grid-template-columns: minmax(0, 1fr); gap: 4px; align-items: center; }
        .bv-arte { animation: sube .7s cubic-bezier(.2, .7, .2, 1) both; }
        .bv-lienzo { position: relative; max-width: 440px; margin: 0 auto; }
        .bv-lienzo svg { display: block; width: 100%; height: auto; }
        .bv-ovi { position: absolute; left: 9.5%; top: 14%; width: 42.4%; height: auto; transform-origin: 50% 96%; animation: oviEntra .8s cubic-bezier(.3, 1.4, .5, 1) .2s both, saluda 3.2s ease-in-out 1.2s infinite; }
        .bv-texto { animation: sube .6s cubic-bezier(.2, .7, .2, 1) .15s both; }
        .guia .bv-h { margin: 0 0 12px; font-size: 34px; line-height: 1.1; font-weight: 800; letter-spacing: -.025em; }
        .bv-h em { font-style: normal; color: var(--pri); }
        .guia .bv-lead { margin: 0 0 18px; max-width: 34em; font-size: 18px; color: #475569; }
        .chips { list-style: none; margin: 0 0 22px; padding: 0; display: flex; flex-wrap: wrap; gap: 8px; }
        .chip { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 999px; background: #f1f5f9; color: #334155; font-size: 15px; font-weight: 700; }
        .chip svg { width: 18px; height: 18px; flex: none; color: var(--pri); }
        .bv-cta { width: 100%; }
        .guia .bv-lista-t { margin: 28px 0 10px; font-size: 13px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: #64748b; }
        .bv-pasos { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
        .bv-paso { display: flex; align-items: center; gap: 10px; min-height: 54px; padding: 8px 12px; border: 1.5px solid #e9edf2; border-radius: 16px; background: #fff; color: #334155; font-size: 15px; font-weight: 700; line-height: 1.25; text-decoration: none; transition: border-color .2s, transform .2s; }
        .bv-paso:hover { color: #334155; border-color: var(--pri); transform: translateY(-1px); }
        .bv-num { flex: none; width: 30px; height: 30px; border-radius: 50%; background: var(--tinte); color: var(--pri); display: grid; place-items: center; font-weight: 800; font-size: 14px; }
        .guia .bv-ayuda { margin: 18px 0 0; font-size: 16px; color: #475569; }

        /* Ilustraciones */
        .f-pri { fill: var(--pri); } .f-tinte { fill: var(--tinte); } .f-tinte2 { fill: var(--tinte2); } .f-oro { fill: var(--oro); } .f-rosa { fill: var(--rosa); }
        .flota, .brilla { transform-box: fill-box; transform-origin: center; }
        .flota { animation: flota 5s ease-in-out infinite; }
        .brilla { animation: brilla 2.6s ease-in-out infinite; }
        .brilla.b2 { animation-delay: .9s; } .brilla.b3 { animation-delay: 1.7s; }

        /* Progreso */
        .progreso { margin: 0 0 18px; }
        .prog-fila { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .guia .prog-num { margin: 0; font-size: 14px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: var(--pri); }
        .prog-nombre { font-size: 15px; font-weight: 600; letter-spacing: 0; text-transform: none; color: #475569; }
        .btn-reinicio { flex: none; display: inline-flex; align-items: center; gap: 6px; min-height: 44px; padding: 0 4px; border: 0; background: none; color: #475569; font-size: 15px; font-weight: 700; cursor: pointer; }
        .btn-reinicio:hover { color: var(--pri); }
        .btn-reinicio svg { width: 18px; height: 18px; }
        .prog-segs { list-style: none; margin: 2px 0 0; padding: 0; display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 6px; }
        .seg { position: relative; display: block; width: 100%; height: 36px; padding: 0; border: 0; background: transparent; cursor: pointer; }
        .seg::before { content: ""; position: absolute; left: 0; right: 0; top: 15px; height: 7px; border-radius: 999px; background: #e5e7eb; transition: background .4s; }
        .seg:hover::before { background: #d1d5db; }
        .seg.hecho::before { background: var(--pri); }
        .seg.activo::before { background: linear-gradient(90deg, var(--rosa), var(--pri)); }

        /* Cada paso */
        .escena { display: grid; grid-template-columns: minmax(0, 1fr); grid-template-areas: "texto" "demo" "tips"; gap: 20px; }
        .entra-adelante { animation: entraA .5s cubic-bezier(.2, .7, .2, 1) both; }
        .entra-atras { animation: entraB .5s cubic-bezier(.2, .7, .2, 1) both; }
        .texto { grid-area: texto; } .demo { grid-area: demo; } .tips { grid-area: tips; }
        .guia .esc-h { margin: 0 0 10px; font-size: 30px; line-height: 1.15; font-weight: 800; letter-spacing: -.02em; }
        .guia .lead { margin: 0; font-size: 18px; color: #334155; }
        .demo-panel { position: relative; min-height: 530px; padding: 28px 12px 76px; border-radius: 28px; background: var(--tinte); display: flex; justify-content: center; align-items: flex-start; overflow: hidden; }
        .demo-panel::before { content: ""; position: absolute; width: 260px; height: 260px; right: -90px; top: -90px; border-radius: 50%; background: rgba(255, 255, 255, .55); }
        .demo-panel::after { content: ""; position: absolute; width: 180px; height: 180px; left: -70px; bottom: -60px; border-radius: 50%; background: rgba(255, 255, 255, .4); }
        .btn-repetir { position: absolute; z-index: 6; left: 50%; bottom: 16px; transform: translateX(-50%); align-items: center; gap: 8px; min-height: 44px; padding: 0 18px; border: 0; border-radius: 999px; background: #fff; color: #334155; font-size: 15px; font-weight: 700; white-space: nowrap; box-shadow: 0 6px 16px -8px rgba(15, 23, 42, .35); cursor: pointer; }
        .btn-repetir:hover { color: var(--pri); }
        .btn-repetir svg { width: 18px; height: 18px; }
        .tips-lista { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
        .tip { display: flex; gap: 14px; align-items: flex-start; padding: 14px 16px; border: 1px solid #eceff3; border-radius: 18px; background: #fff; }
        .tip-num { flex: none; width: 32px; height: 32px; border-radius: 50%; background: var(--pri); color: #fff; font-weight: 800; font-size: 16px; display: grid; place-items: center; }
        .guia .tip-t { margin: 0 0 2px; font-size: 17px; font-weight: 800; line-height: 1.35; }
        .guia .tip-d { margin: 0; font-size: 16px; color: #475569; }
        .nota { display: flex; gap: 12px; align-items: flex-start; margin-top: 12px; padding: 14px 16px; border: 1px solid #fde3c9; border-radius: 18px; background: var(--tinte2); color: #7c2d12; font-size: 16px; }
        .nota svg { flex: none; width: 22px; height: 22px; margin-top: 1px; color: #c2410c; }
        .guia .nota p { margin: 0; }
        .url-caja { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 10px; margin: 16px 0 6px; padding: 8px 8px 8px 14px; border: 2px solid var(--pri); border-radius: 18px; background: #fff; box-shadow: 0 10px 24px -18px rgba(15, 23, 42, .5); }
        .url-ico { display: grid; color: var(--pri); }
        .url-ico svg { width: 22px; height: 22px; }
        /* La dirección solo se parte después de «/» (<wbr>): nunca a mitad del dominio. Si no cabe junto a «Copiar», el botón baja a su propia línea. */
        .url-linea { flex: 1 1 auto; display: flex; align-items: center; gap: 10px; }
        .url-txt { font-size: 18px; font-weight: 800; line-height: 1.3; color: var(--texto); }
        .nw { white-space: nowrap; }
        .btn-copiar { flex: none; margin-left: auto; align-items: center; gap: 6px; min-height: 44px; padding: 0 14px; border: 0; border-radius: 12px; background: var(--tinte); color: var(--pri); font-size: 15px; font-weight: 800; cursor: pointer; }
        .btn-copiar svg { width: 18px; height: 18px; }
        .guia .url-aviso { margin: 0; font-size: 15px; color: #64748b; }
        .ir-portal { margin-top: 16px; }
        .btn-portal { width: 100%; min-height: 52px; padding: 0 16px; font-size: 16px; }
        .guia .ir-portal-nota { margin: 8px 0 0; font-size: 15px; color: #475569; }
        .guia .asunto { margin: 14px 0 0; padding: 12px 16px; border: 1.5px dashed #cbd5e1; border-radius: 16px; background: var(--fondo); }
        .asunto-e { display: block; font-size: 12px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: #64748b; }
        .asunto-t { display: block; font-size: 17px; font-weight: 800; line-height: 1.35; color: var(--texto); }
        .donde { margin-top: 18px; }
        .donde-t { margin: 0 0 10px; font-size: 21px; font-weight: 800; letter-spacing: -.01em; }
        .disp-grid { display: grid; gap: 10px; }
        .disp { padding: 14px 16px; border: 1px solid #eceff3; border-radius: 18px; background: var(--fondo); }
        .guia .disp-cab { display: flex; align-items: center; gap: 10px; margin: 0 0 4px; font-size: 17px; font-weight: 800; }
        .disp-cab svg { width: 22px; height: 22px; flex: none; color: var(--pri); }
        .guia .disp p:not(.disp-cab) { margin: 0; font-size: 16px; color: #475569; }

        /* Navegación inferior */
        .navbar { position: sticky; bottom: 0; z-index: 9; margin: 24px -1rem 0; background: rgba(255, 255, 255, .95); -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px); border-top: 1px solid #eef0f3; }
        .nav-in { padding: 12px 1rem calc(12px + env(safe-area-inset-bottom)); display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.6fr); gap: 10px; }
        .nav-in .btn { padding: 0 14px; }

        /* Recreación del celular con el portal real (datos ficticios) */
        .m-disp { position: relative; z-index: 1; width: min(320px, 100%); padding: 10px; border-radius: 42px; background: #0f172a; box-shadow: 0 30px 60px -30px rgba(15, 23, 42, .6), 0 0 0 1px rgba(15, 23, 42, .08); animation: sube .6s cubic-bezier(.2, .7, .2, 1) both; }
        .m-pant { position: relative; height: 510px; border-radius: 32px; background: #fff; overflow: hidden; font-size: 13px; line-height: 1.4; color: #0f172a; }
        .m-pant p { margin: 0; }
        .m-isla { position: absolute; z-index: 6; top: 9px; left: 50%; width: 74px; height: 20px; margin-left: -37px; border-radius: 999px; background: #0f172a; }
        .m-arriba { height: 34px; }
        .m-nav { padding: 34px 10px 8px; background: #f1f5f9; border-bottom: 1px solid #e2e8f0; }
        .m-url { display: flex; align-items: center; gap: 6px; height: 34px; padding: 0 10px; border: 2px solid #e2e8f0; border-radius: 999px; background: #fff; font-size: 12.5px; font-weight: 700; color: #334155; animation: foco 2.2s ease .3s both; }
        .m-url svg { width: 12px; height: 12px; flex: none; color: #16a34a; }
        .m-url-txt { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; clip-path: inset(0 0 0 0); animation: teclea 1.6s steps(24, end) .6s both; }
        .m-carga { height: 3px; background: #d32f57; transform-origin: left; opacity: 0; animation: carga 1s ease 2.3s both; }
        .m-sitio { animation: aparece .5s ease 3s both; }
        .m-cab { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-bottom: 1px solid #eef0f3; }
        .m-logo { display: block; height: 24px; width: auto; }
        .m-cab-tit { padding-left: 8px; border-left: 1px solid #e2e8f0; font-size: 9.5px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: #64748b; }
        .m-salir { margin-left: auto; padding: 4px 8px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 9.5px; font-weight: 800; color: #334155; }
        .m-card { margin: 12px; padding: 14px 13px; border: 1px solid #e2e8f0; border-radius: 18px; background: #fff; box-shadow: 0 14px 28px -20px rgba(15, 23, 42, .35); }
        .m-segs { display: flex; gap: 4px; margin: 0 0 8px; }
        .m-segs span { flex: 1; height: 3px; border-radius: 9px; background: #e2e8f0; }
        .m-segs span.on { background: linear-gradient(90deg, #d32f57, #942934); }
        .m-pant .m-paso { margin: 0 0 3px; font-size: 9.5px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; color: #942934; }
        .m-pant .m-h { margin: 0 0 4px; font-size: 17px; line-height: 1.2; font-weight: 800; letter-spacing: -.01em; }
        .m-p { font-size: 11px; color: #475569; }
        .m-pant .m-label { margin: 10px 0 4px; padding: 0 3px; font-size: 11px; font-weight: 700; color: #64748b; }
        .m-caja { position: relative; display: flex; align-items: center; gap: 7px; height: 38px; padding: 0 10px; border: 2px solid #e2e8f0; border-radius: 11px; background: #f8fafc; font-size: 13px; font-weight: 700; color: #334155; }
        .m-caja svg { width: 13px; height: 13px; flex: none; color: #cbd5e1; }
        .a-foco { animation: foco 2s ease both; animation-delay: var(--t, 0s); }
        .tecleo { white-space: nowrap; overflow: hidden; clip-path: inset(0 0 0 0); animation: teclea 1.1s steps(20, end) both; animation-delay: calc(var(--t, 0s) + .2s); }
        .m-boton { position: relative; display: flex; align-items: center; justify-content: center; height: 40px; margin-top: 12px; border-radius: 12px; background: linear-gradient(135deg, #e96510, #ea580c); color: #fff; font-size: 13px; font-weight: 800; box-shadow: 0 4px 6px -1px rgba(249, 115, 22, .25); }
        .m-boton.chico { display: inline-flex; height: 32px; margin: 0; padding: 0 12px; border-radius: 9px; font-size: 11.5px; }
        /* Espacio libre encima del botón señalado: allí aparece «Toca aquí» sin tapar ningún texto. */
        .m-boton.con-toque { margin-top: 44px; }
        .m-acc.con-toque { margin-top: 36px; }
        .a-presiona { animation: presiona .4s ease both; animation-delay: calc(var(--t, 0s) + .15s); }
        .m-enlaces { display: flex; justify-content: space-between; gap: 8px; margin-top: 12px; padding-top: 10px; border-top: 1px solid #e2e8f0; font-size: 11.5px; font-weight: 800; color: #942934; }
        .m-codigo { justify-content: center; gap: 6px; height: 48px; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 22px; font-weight: 700; }
        /* Paso 4: teclado del celular con el código sugerido, como lo ofrecen algunos teléfonos. */
        .m-teclado { position: absolute; z-index: 3; left: 0; right: 0; bottom: 0; padding: 8px 6px 12px; background: #e2e8f0; animation: tecladoSube .5s cubic-bezier(.2, .8, .2, 1) .3s both; }
        .m-sugerencias { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 4px; margin-bottom: 8px; }
        .m-sug { height: 28px; border-radius: 7px; background: #f1f5f9; }
        .m-sug.on { display: grid; place-items: center; background: #fff; color: #0f172a; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px; font-weight: 700; letter-spacing: .08em; box-shadow: 0 1px 2px rgba(15, 23, 42, .15); }
        .m-teclas { display: grid; grid-template-columns: repeat(10, minmax(0, 1fr)); gap: 4px; margin-top: 5px; }
        .m-teclas.r2 { grid-template-columns: repeat(9, minmax(0, 1fr)); padding: 0 12px; }
        .m-teclas span { height: 20px; border-radius: 5px; background: #fff; box-shadow: 0 1px 0 rgba(15, 23, 42, .12); }
        .dig { display: inline-block; animation: digito .35s cubic-bezier(.3, 1.4, .5, 1) both; animation-delay: calc(1s + var(--i) * .3s); }
        .posrel { position: relative; }
        .t05 { --t: .5s; } .t06 { --t: .6s; } .t07 { --t: .7s; } .t09 { --t: .9s; } .t10 { --t: 1s; } .t12 { --t: 1.2s; } .t15 { --t: 1.5s; } .t22 { --t: 2.2s; } .t23 { --t: 2.3s; }
        .t24 { --t: 2.4s; } .t30 { --t: 3s; } .t31 { --t: 3.1s; } .t32 { --t: 3.2s; } .t35 { --t: 3.5s; } .t38 { --t: 3.8s; } .t39 { --t: 3.9s; } .t41 { --t: 4.1s; } .t42 { --t: 4.2s; } .t44 { --t: 4.4s; }
        .i0 { --i: 0; } .i1 { --i: 1; } .i2 { --i: 2; } .i3 { --i: 3; } .i4 { --i: 4; } .i5 { --i: 5; }

        /* Señales de la guía (no son parte del portal) */
        /* Números de la guía: por defecto en el margen izquierdo del elemento (nunca sobre su texto); «esquina» para bloques
           cuya esquina superior derecha está libre; «linea» junto al texto, para elementos pequeños o pegados al borde. */
        .marca { position: absolute; z-index: 5; left: -23px; top: 50%; margin-top: -10px; width: 20px; height: 20px; border-radius: 50%; background: var(--pri); color: #fff; font-size: 11px; font-weight: 800; line-height: 1; display: grid; place-items: center; box-shadow: 0 0 0 2px #fff, 0 3px 8px -2px rgba(15, 23, 42, .35); animation: pop .4s cubic-bezier(.3, 1.6, .5, 1) both; animation-delay: var(--t, 0s); }
        .marca.esquina { left: auto; right: -9px; top: -9px; margin-top: 0; }
        .marca.linea { position: relative; left: auto; top: auto; margin: 0; display: inline-grid; flex: none; vertical-align: middle; }
        .toque { position: absolute; z-index: 6; top: 50%; right: 16px; width: 0; height: 0; pointer-events: none; }
        .toque-punto { position: absolute; left: -11px; top: -11px; width: 22px; height: 22px; border-radius: 50%; background: rgba(15, 23, 42, .74); box-shadow: 0 0 0 3px rgba(255, 255, 255, .95), 0 6px 14px rgba(15, 23, 42, .35); animation: pop .4s cubic-bezier(.3, 1.6, .5, 1) both; animation-delay: var(--t, 0s); }
        .toque-anillo { position: absolute; left: -22px; top: -22px; width: 44px; height: 44px; border: 3px solid rgba(15, 23, 42, .5); border-radius: 50%; opacity: 0; animation: anillo 1s ease-out 2 both; animation-delay: calc(var(--t, 0s) + .25s); }
        .toque-burbuja { position: absolute; right: -14px; top: -48px; padding: 5px 10px; border-radius: 10px; background: #0f172a; color: #fff; font-size: 11.5px; font-weight: 800; white-space: nowrap; box-shadow: 0 8px 18px -8px rgba(15, 23, 42, .6); transform-origin: right bottom; animation: pop .4s ease both; animation-delay: calc(var(--t, 0s) + .1s); }
        .guia-nota { flex: none; padding: 4px 9px; border-radius: 9px; background: #0f172a; color: #fff; font-size: 10.5px; font-weight: 800; white-space: nowrap; animation: pop .4s ease both; animation-delay: var(--t, 0s); }

        /* Paso 3: sobre y bandeja */
        /* La carta sube hasta 64 px sobre el sobre: con 104 px arriba nunca llega a la ranura de la cámara del celular. */
        .sobre-zona { height: 220px; padding-top: 104px; }
        .sobre { position: relative; width: 176px; height: 110px; margin: 0 auto; animation: sobreCae .7s cubic-bezier(.3, 1.4, .5, 1) .2s both; }
        .sobre-fondo { position: absolute; inset: 0; border-radius: 12px; background: var(--pri2); }
        .carta { position: absolute; z-index: 1; left: 12px; right: 12px; bottom: 12px; height: 120px; padding: 12px 8px; border-radius: 10px; background: #fff; text-align: center; box-shadow: 0 6px 18px -8px rgba(15, 23, 42, .35); transform: translateY(-50px); animation: cartaSube .8s cubic-bezier(.2, .8, .2, 1) 1s both; }
        .m-pant .carta-tit { font-size: 9.5px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: var(--pri); }
        .m-pant .carta-cod { margin: 5px 0 2px; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 22px; font-weight: 700; letter-spacing: .14em; }
        .carta-nota { font-size: 9.5px; font-weight: 700; color: #64748b; }
        .sobre-frente { position: absolute; z-index: 2; left: 0; bottom: 0; width: 100%; height: 100%; display: block; }
        .sobre-badge { position: absolute; z-index: 3; right: -10px; top: -10px; width: 30px; height: 30px; border-radius: 50%; background: #e11d48; color: #fff; display: grid; place-items: center; box-shadow: 0 0 0 3px #fff; animation: pop .45s cubic-bezier(.3, 1.6, .5, 1) .8s both; }
        .sobre-badge svg { width: 16px; height: 16px; transform-origin: 50% 10%; animation: campana .9s ease-in-out 1.3s both; }
        .m-bandeja { margin: 6px 10px 0; border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; }
        .m-bandeja-cab { display: flex; align-items: center; gap: 6px; padding: 8px 10px; border-bottom: 1px solid #eef0f3; font-size: 12px; font-weight: 800; }
        .m-bandeja-cab svg { width: 13px; height: 13px; color: #64748b; }
        .m-carpetas { display: flex; align-items: center; gap: 5px; padding: 7px 10px; border-bottom: 1px solid #eef0f3; }
        .m-carpeta { position: relative; padding: 3px 8px; border-radius: 999px; background: #f1f5f9; color: #475569; font-size: 10.5px; font-weight: 700; }
        .m-carpeta.on { background: #0f172a; color: #fff; }
        .m-msg { position: relative; display: flex; gap: 8px; align-items: flex-start; padding: 9px 10px; border-bottom: 1px solid #f1f5f9; }
        .m-msg:last-child { border-bottom: 0; }
        .m-msg.nuevo { background: #fff7ed; animation: entraMsg .5s ease 1.7s both; }
        .m-av { flex: none; width: 24px; height: 24px; border-radius: 50%; background: var(--pri); color: #fff; font-size: 8.5px; font-weight: 800; display: grid; place-items: center; }
        .m-av.gris { background: #e2e8f0; }
        .m-msg-txt { flex: 1; min-width: 0; }
        .m-de { display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 800; }
        .m-de .m-hora { margin-left: auto; }
        .m-hora { font-size: 10px; font-weight: 700; color: #64748b; }
        .m-asunto { font-size: 11px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .m-esq { height: 7px; margin: 4px 0; border-radius: 9px; background: #eef0f3; }
        .w40 { width: 40%; } .w55 { width: 55%; } .w75 { width: 75%; } .w85 { width: 85%; }

        /* Paso 5: lista de certificados */
        .m-panel { padding: 10px 12px 0; }
        .m-resumen { display: flex; flex-wrap: wrap; gap: 4px; margin: 6px 0 0; }
        .m-resumen span { padding: 2px 7px; border-radius: 999px; background: #f1f5f9; color: #475569; font-size: 9.5px; font-weight: 700; }
        .m-resumen span.ok { background: #dcfce7; color: #14532d; }
        .m-buscar { position: relative; display: flex; align-items: center; gap: 6px; margin-top: 10px; padding: 3px 3px 3px 10px; border: 2px solid #e2e8f0; border-radius: 13px; background: #fff; animation: foco 2.2s ease both; animation-delay: var(--t, 0s); }
        .m-buscar svg { width: 13px; height: 13px; flex: none; color: #94a3b8; }
        .m-buscar-txt { flex: 1; min-width: 0; font-size: 13px; font-weight: 700; color: #334155; }
        .m-buscar .tecleo { display: inline-block; }
        .m-filtros { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-top: 8px; }
        .m-etq { display: flex; align-items: center; gap: 4px; min-height: 20px; margin: 0 0 2px 3px; font-size: 9px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; color: #64748b; }
        .m-select { position: relative; display: flex; align-items: center; justify-content: space-between; height: 32px; padding: 0 8px; border: 2px solid #e2e8f0; border-radius: 9px; font-size: 11px; font-weight: 700; color: #334155; }
        .m-select svg { width: 11px; height: 11px; color: #94a3b8; }
        .m-rango { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin: 8px 3px 6px; font-size: 10px; color: #64748b; }
        .m-lista { position: relative; margin: 0 12px; border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; }
        .m-lista.sep { margin-top: 10px; }
        .m-cert { position: relative; padding: 10px 11px; border-bottom: 1px solid #eef0f3; }
        .m-cert:last-child { border-bottom: 0; }
        .m-cert.top { padding-right: 20px; border-radius: 13px 13px 0 0; background: #fffbfb; box-shadow: inset 0 0 0 2px var(--pri); animation: resalta 1s ease both; animation-delay: var(--t, 0s); }
        .m-cert-h { font-size: 12.5px; font-weight: 800; line-height: 1.3; }
        .m-pant .m-fecha { margin: 3px 0 0; font-size: 10.5px; font-weight: 700; color: #334155; }
        .m-meta { display: flex; align-items: center; gap: 6px; margin-top: 5px; }
        .m-insignia { padding: 1px 7px; border-radius: 999px; background: #dcfce7; color: #14532d; font-size: 9px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
        .m-acc { display: flex; align-items: center; gap: 10px; margin-top: 8px; }
        .m-acc .m-boton { flex: 1; }
        .m-verif { font-size: 11px; font-weight: 800; color: #942934; }
        .m-desvanece { position: absolute; z-index: 2; left: 0; right: 0; bottom: 0; height: 70px; background: linear-gradient(rgba(255, 255, 255, 0), #fff); pointer-events: none; }

        /* Paso 6: descarga */
        .m-descargas { position: relative; margin: 12px; padding: 10px 11px; border-radius: 14px; background: #0f172a; color: #fff; animation: sube .4s ease 1.3s both; }
        .m-pant .m-desc-t { margin: 0 0 7px; font-size: 9.5px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: #cbd5e1; }
        .m-desc-fila { display: flex; align-items: center; gap: 9px; }
        .pdf { flex: none; display: block; width: 26px; height: 31px; animation: vuela .8s cubic-bezier(.3, .9, .4, 1) 1.1s both; }
        .m-desc-info { flex: 1; min-width: 0; }
        .m-desc-nombre { font-size: 12px; font-weight: 800; }
        .m-prog { height: 5px; margin-top: 5px; border-radius: 9px; background: rgba(255, 255, 255, .18); overflow: hidden; }
        .m-prog span { display: block; height: 100%; border-radius: 9px; background: #4ade80; transform-origin: left; animation: llena 1.3s ease-in-out 1.8s both; }
        .m-desc-estado { display: grid; margin-top: 4px; font-size: 10.5px; font-weight: 700; }
        .m-desc-estado span { grid-area: 1 / 1; }
        .m-desc-estado .uno { color: #cbd5e1; opacity: 0; animation: sale .2s ease 3.1s both reverse; }
        .m-desc-estado .dos { display: inline-flex; align-items: center; gap: 4px; color: #86efac; animation: pop .4s ease 3.2s both; }
        .m-desc-estado svg { width: 11px; height: 11px; }
        .m-invita { position: relative; margin: 0 12px; padding: 9px 10px; border: 1px solid #fed7aa; border-radius: 12px; background: #fff7ed; animation: sube .4s ease 3.8s both; }
        .m-invita strong { display: block; font-size: 12px; }
        .m-pant .m-invita p { margin: 1px 0 6px; font-size: 10px; color: #475569; }
        .m-invita-acc { display: flex; align-items: center; gap: 10px; }
        .m-mt { font-size: 11px; font-weight: 800; color: #942934; }

        /* Final */
        .final { position: relative; max-width: 900px; margin: 0 auto; padding: 8px 0 24px; text-align: center; }
        .final-cab { position: relative; animation: sube .5s ease both; }
        .final-cab > * { position: relative; z-index: 1; }
        .final-cab > .confeti { position: absolute; z-index: 0; }
        /* El confeti cae solo en la franja de la ilustración (arriba del título) y queda debajo de todo el texto. */
        .confeti { left: 0; right: 0; top: 0; height: 172px; overflow: hidden; pointer-events: none; }
        .cf { position: absolute; top: 0; width: 10px; height: 16px; border-radius: 3px; animation: cae 1.9s cubic-bezier(.2, .6, .4, 1) both; }
        .cf:nth-child(1) { left: 4%; top: 30px; background: var(--pri); transform: rotate(25deg); animation-delay: .1s; }
        .cf:nth-child(2) { left: 11%; top: 112px; background: var(--oro); transform: rotate(-30deg); animation-delay: .35s; border-radius: 50%; width: 12px; height: 12px; }
        .cf:nth-child(3) { left: 18%; top: 52px; background: #e96510; transform: rotate(60deg); animation-delay: .2s; }
        .cf:nth-child(4) { left: 25%; top: 128px; background: var(--rosa); transform: rotate(-15deg); animation-delay: .5s; }
        .cf:nth-child(5) { left: 31%; top: 15px; background: var(--oliva); transform: rotate(40deg); animation-delay: .05s; width: 8px; height: 14px; }
        .cf:nth-child(6) { left: 40%; top: 6px; background: var(--oro); transform: rotate(75deg); animation-delay: .4s; }
        .cf:nth-child(7) { left: 47%; top: 146px; background: var(--pri); transform: rotate(-50deg); animation-delay: .6s; border-radius: 50%; width: 10px; height: 10px; }
        .cf:nth-child(8) { left: 55%; top: 10px; background: var(--rosa); transform: rotate(20deg); animation-delay: .15s; }
        .cf:nth-child(9) { left: 62%; top: 138px; background: #e96510; transform: rotate(-70deg); animation-delay: .45s; }
        .cf:nth-child(10) { left: 68%; top: 60px; background: var(--oliva); transform: rotate(35deg); animation-delay: .25s; border-radius: 50%; width: 12px; height: 12px; }
        .cf:nth-child(11) { left: 74%; top: 124px; background: var(--oro); transform: rotate(-25deg); animation-delay: .55s; }
        .cf:nth-child(12) { left: 80%; top: 38px; background: var(--pri); transform: rotate(55deg); animation-delay: .1s; }
        .cf:nth-child(13) { left: 86%; top: 105px; background: var(--rosa); transform: rotate(-40deg); animation-delay: .35s; width: 8px; height: 14px; }
        .cf:nth-child(14) { left: 92%; top: 15px; background: #e96510; transform: rotate(15deg); animation-delay: .2s; }
        .cf:nth-child(15) { left: 95%; top: 118px; background: var(--oro); transform: rotate(-60deg); animation-delay: .5s; border-radius: 50%; width: 10px; height: 10px; }
        .cf:nth-child(16) { left: 2%; top: 142px; background: var(--oliva); transform: rotate(30deg); animation-delay: .65s; }
        .final-arte { display: flex; align-items: flex-end; justify-content: center; gap: 6px; margin: 24px auto 10px; }
        .final-ovi { width: 128px; height: auto; transform-origin: 50% 96%; animation: oviEntra .8s cubic-bezier(.3, 1.4, .5, 1) .5s both, saluda 3.2s ease-in-out 1.5s infinite; }
        .medalla { flex: none; width: 140px; height: 140px; margin: 0 0 4px; animation: pop .7s cubic-bezier(.3, 1.5, .5, 1) .3s both; }
        .medalla svg { display: block; width: 100%; height: 100%; }
        .guia .final-h { margin: 0 0 10px; font-size: 32px; line-height: 1.12; font-weight: 800; letter-spacing: -.02em; }
        .final-h span { color: var(--pri); }
        .guia .final-p { margin: 0 auto 22px; max-width: 34em; font-size: 18px; color: #475569; }
        .final-acc { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; }
        .final-acc .btn { flex: 1 1 240px; max-width: 340px; }

        /* Ayuda */
        .ayuda-sec { margin-top: 40px; padding-top: 28px; border-top: 1px solid #eef0f3; text-align: left; scroll-margin-top: 90px; }
        .guia .ayuda-h { margin: 0 0 4px; font-size: 26px; line-height: 1.2; font-weight: 800; letter-spacing: -.015em; }
        .guia .ayuda-sub, .hoja .ayuda-sub { margin: 0 0 16px; font-size: 17px; color: #475569; }
        .ay-grid { display: grid; gap: 10px; text-align: left; }
        .ay-card { display: flex; gap: 14px; align-items: flex-start; padding: 16px; border: 1px solid #e9edf2; border-radius: 20px; background: #fff; }
        .ay-ico { flex: none; width: 46px; height: 46px; border-radius: 14px; background: #fbf0f1; color: var(--vino); display: grid; place-items: center; }
        .ay-ico svg { width: 23px; height: 23px; }
        .ay-card .ay-t { margin: 0 0 3px; font-size: 17px; font-weight: 800; line-height: 1.35; }
        .ay-card .ay-d { margin: 0; font-size: 16px; color: #475569; }
        .ay-contacto { background: #fff5ec; border-color: #fde3c9; }
        .ay-contacto a { font-weight: 800; overflow-wrap: anywhere; }
        .ay-ovi { flex: none; width: 58px; height: auto; margin: -4px 0 -6px; }
        .hoja { width: 100%; max-width: 100%; max-height: 88vh; margin: auto auto 0; padding: 0 16px 24px; border: 0; border-radius: 26px 26px 0 0; background: #fff; color: var(--texto); font-size: 17px; line-height: 1.55; box-shadow: 0 -20px 60px -20px rgba(15, 23, 42, .4); overflow-y: auto; }
        .hoja[open] { animation: hojaSube .35s cubic-bezier(.2, .8, .2, 1) both; }
        .hoja::backdrop { background: rgba(15, 23, 42, .45); }
        .agarre { width: 44px; height: 5px; margin: 10px auto 0; border-radius: 9px; background: #e2e8f0; }
        .hoja-cab { position: sticky; top: 0; z-index: 1; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 0; background: #fff; }
        .hoja-cab h2 { margin: 0; font-size: 24px; font-weight: 800; letter-spacing: -.015em; }
        .btn-cerrar { flex: none; width: 46px; height: 46px; border: 0; border-radius: 50%; background: #f1f5f9; color: #334155; display: grid; place-items: center; cursor: pointer; }
        .btn-cerrar svg { width: 20px; height: 20px; }
        .btn-cerrar:focus-visible { outline: 3px solid var(--rosa); outline-offset: 3px; }

        @keyframes entraA { from { opacity: 0; transform: translateX(32px); } }
        @keyframes entraB { from { opacity: 0; transform: translateX(-32px); } }
        @keyframes sube { from { opacity: 0; transform: translateY(16px); } }
        @keyframes aparece { from { opacity: 0; } }
        @keyframes pop { 0% { opacity: 0; transform: scale(.3); } 70% { opacity: 1; transform: scale(1.1); } 100% { opacity: 1; } }
        @keyframes teclea { from { clip-path: inset(0 100% 0 0); } }
        @keyframes carga { 0% { transform: scaleX(0); opacity: 1; } 85% { transform: scaleX(1); opacity: 1; } 100% { transform: scaleX(1); opacity: 0; } }
        @keyframes foco { 0%, 100% { border-color: #e2e8f0; background: #f8fafc; box-shadow: none; } 12%, 85% { border-color: #e11d48; background: #fff; box-shadow: 0 0 0 3px rgba(225, 29, 72, .15); } }
        @keyframes anillo { 0% { opacity: .9; transform: scale(.4); } 100% { opacity: 0; transform: scale(1.9); } }
        @keyframes presiona { 0%, 100% { transform: none; } 50% { transform: scale(.93); } }
        @keyframes sobreCae { from { opacity: 0; transform: translateY(-46px) rotate(-6deg); } }
        @keyframes cartaSube { from { transform: translateY(4px); } }
        @keyframes entraMsg { from { opacity: 0; transform: translateY(-14px); } }
        @keyframes digito { from { opacity: 0; transform: translateY(-14px) scale(1.5); } }
        @keyframes resalta { from { box-shadow: inset 0 0 0 0 rgba(0, 0, 0, 0); background: #fff; } }
        @keyframes vuela { 0% { opacity: 0; transform: translate(60px, -150px) scale(.6) rotate(-14deg); } 25% { opacity: 1; } }
        @keyframes llena { from { transform: scaleX(0); } }
        @keyframes sale { from { opacity: 0; } to { opacity: 1; } }
        @keyframes cae { from { opacity: 0; transform: translateY(-160px) rotate(0deg); } 30% { opacity: 1; } }
        @keyframes flota { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-9px); } }
        @keyframes brilla { 0%, 100% { opacity: .3; transform: scale(.75); } 50% { opacity: 1; transform: scale(1); } }
        @keyframes hojaSube { from { transform: translateY(100%); } }
        @keyframes oviEntra { from { opacity: 0; transform: translateY(30px) scale(.85); } }
        @keyframes saluda { 0%, 100% { transform: rotate(0); } 25% { transform: rotate(-3deg); } 75% { transform: rotate(2.5deg); } }
        @keyframes campana { 0%, 100% { transform: rotate(0); } 20% { transform: rotate(-16deg); } 40% { transform: rotate(14deg); } 60% { transform: rotate(-8deg); } 80% { transform: rotate(5deg); } }
        @keyframes tecladoSube { from { transform: translateY(100%); } }

        @media (min-width: 560px) { .g-ancho { display: inline; } .g-corto { display: none; } }
        @media (min-width: 640px) {
            .ay-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .ay-contacto { grid-column: 1 / -1; }
            .bv-cta { width: auto; min-width: 280px; }
            .btn-portal { width: auto; }
            .navbar { margin-left: -1.5rem; margin-right: -1.5rem; }
            .nav-in { display: flex; justify-content: flex-end; padding-left: 1.5rem; padding-right: 1.5rem; }
            .nav-in .btn { min-width: 200px; padding: 0 24px; }
        }
        @media (min-width: 640px) and (max-width: 899px) { .disp-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (min-width: 900px) {
            .bienvenida { grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr); gap: 56px; padding: 8px 0 24px; }
            .bv-arte { order: 2; }
            .guia .bv-h { font-size: 52px; }
            .bv-pasos { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .escena { grid-template-columns: minmax(0, 1fr) minmax(0, 1.05fr); grid-template-rows: auto 1fr; grid-template-areas: "demo texto" "demo tips"; column-gap: 56px; row-gap: 20px; align-items: start; }
            .demo { position: sticky; top: 96px; }
            .demo-panel { min-height: 600px; padding-top: 40px; }
            .m-disp { width: 340px; }
            .m-pant { height: 540px; }
            .confeti { height: 236px; }
            .guia .esc-h { font-size: 40px; }
            .guia .final-h { font-size: 46px; }
            .final-ovi { width: 160px; }
            .medalla { width: 170px; height: 170px; }
            .hoja { width: min(780px, calc(100% - 48px)); margin: auto; padding: 0 28px 28px; border-radius: 28px; }
            .hoja[open] { animation: aparece .3s ease both; }
            .agarre { display: none; }
            .hoja-cab { padding-top: 22px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .guia *, .guia *::before, .guia *::after, .hoja, .hoja * { animation: none !important; transition: none !important; }
        }
    </style>
@endpush
@section('contenido')
<div class="guia" data-guia>
<script nonce="{{ $nonce }}">document.currentScript.parentElement.classList.add('con-js');</script>
<p class="solo-lector" aria-live="polite" data-anuncio></p>

{{-- Progreso (solo con JavaScript; sin él, los pasos se leen uno debajo del otro). --}}
<div class="progreso" data-progreso hidden>
    <div class="prog-fila">
        <p class="prog-num">Paso <span data-num>1</span> de 6 <span class="prog-nombre">· <span data-nombre>{{ $pasos[1] }}</span></span></p>
        <button class="btn-reinicio" type="button" data-ir="0"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>Reiniciar</button>
    </div>
    <ol class="prog-segs" aria-label="Progreso de la guía">
        @foreach ($pasos as $n => $corto)
            <li><button class="seg" type="button" data-ir="{{ $n }}" data-seg="{{ $n }}" aria-label="Paso {{ $n }}: {{ $corto }}"></button></li>
        @endforeach
    </ol>
</div>

{{-- Bienvenida --}}
<section class="pantalla bienvenida activa" id="inicio-guia" data-pantalla="0" aria-labelledby="t-bienvenida">
    <div class="bv-arte" aria-hidden="true">
        <div class="bv-lienzo">
            <svg viewBox="0 0 420 330" fill="none">
                <circle cx="215" cy="178" r="140" class="f-tinte"/>
                <circle cx="352" cy="64" r="28" class="f-tinte2"/>
                <ellipse cx="215" cy="306" rx="165" ry="9" fill="#0f172a" opacity=".07"/>
                <rect x="236" y="164" width="146" height="96" rx="9" fill="#1e293b"/>
                <rect x="243" y="171" width="132" height="82" rx="4" fill="#fff"/>
                <rect x="243" y="171" width="132" height="13" rx="4" class="f-tinte"/>
                <rect x="253" y="191" width="66" height="50" rx="4" fill="#fff" stroke="#e2c58f" stroke-width="2"/>
                <rect x="261" y="200" width="40" height="4" rx="2" class="f-pri"/>
                <rect x="261" y="209" width="50" height="3" rx="1.5" fill="#e2e8f0"/>
                <rect x="261" y="216" width="34" height="3" rx="1.5" fill="#e2e8f0"/>
                <circle cx="306" cy="230" r="7" class="f-oro"/>
                <rect x="326" y="212" width="40" height="17" rx="8.5" fill="#e96510"/>
                <path d="M346 215.5 v8 M342.5 220.5 l3.5 3.5 3.5-3.5" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M226 260 H392 L384 270 H234 Z" fill="#cbd5e1"/>
                <rect x="40" y="270" width="352" height="12" rx="6" fill="#e2e8f0"/>
                <rect x="70" y="282" width="10" height="22" rx="3" fill="#cbd5e1"/>
                <rect x="352" y="282" width="10" height="22" rx="3" fill="#cbd5e1"/>
                <g class="flota">
                    <rect x="254" y="44" width="118" height="80" rx="10" fill="#0f172a" opacity=".07"/>
                    <rect x="248" y="38" width="118" height="80" rx="10" fill="#fff" stroke="#e2e8f0" stroke-width="1.5"/>
                    <rect x="262" y="53" width="58" height="6" rx="3" class="f-pri"/>
                    <rect x="262" y="67" width="82" height="4" rx="2" fill="#e2e8f0"/>
                    <rect x="262" y="77" width="64" height="4" rx="2" fill="#e2e8f0"/>
                    <rect x="262" y="87" width="44" height="4" rx="2" fill="#e2e8f0"/>
                    <path d="M336 100 l-5 22 8-5 6 7 2-22 Z" class="f-pri"/>
                    <circle cx="343" cy="98" r="13" class="f-oro"/>
                    <path d="M337 98 l4 4 8-8" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
                </g>
                <path class="brilla f-oro" d="M26 36 l4 10 10 4 -10 4 -4 10 -4 -10 -10 -4 10 -4 Z"/>
                <path class="brilla b2 f-rosa" d="M392 150 l3 7 7 3 -7 3 -3 7 -3 -7 -7 -3 7 -3 Z"/>
                <path class="brilla b3 f-oro" d="M226 124 l2.5 6 6 2.5 -6 2.5 -2.5 6 -2.5 -6 -6 -2.5 6 -2.5 Z"/>
            </svg>
            <img class="bv-ovi" src="/images/ovi/ovi-saluda.png" alt="" width="402" height="519">
        </div>
    </div>
    <div class="bv-texto">
        <p class="ceja">Guía rápida · Certificados F&amp;C</p>
        <h1 id="t-bienvenida" class="bv-h" tabindex="-1">Descarga tu certificado en <em>6 pasos sencillos</em></h1>
        <p class="bv-lead">Te acompañamos desde que entras a nuestra página hasta que tu certificado en PDF queda guardado en tu celular o computador. No necesitas instalar nada.</p>
        <ul class="chips">
            <li class="chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>Toma solo unos minutos</li>
            <li class="chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 10h4M7 14h6M15 10h2"/></svg>Ten a mano tu documento y tu correo</li>
        </ul>
        <a class="btn btn-pri bv-cta" href="#paso-1" data-ir="1">Empezar la guía<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
        <p class="bv-lista-t">O ve directo a un paso</p>
        <ol class="bv-pasos">
            @foreach ($pasos as $n => $corto)
                <li><a class="bv-paso" href="#paso-{{ $n }}" data-ir="{{ $n }}"><span class="bv-num">{{ $n }}</span><span>{{ $corto }}</span></a></li>
            @endforeach
        </ol>
        <p class="bv-ayuda">¿Tienes alguna dificultad? <a class="enlace-texto" href="#ayuda" data-abrir-ayuda>Mira nuestros consejos</a></p>
    </div>
</section>

{{-- Paso 1 --}}
<section class="pantalla escena" id="paso-1" data-pantalla="1" data-nombre="{{ $pasos[1] }}" aria-labelledby="t-p1">
    <div class="texto">
        <p class="ceja">Paso 1 · {{ $pasos[1] }}</p>
        <h2 id="t-p1" class="esc-h" tabindex="-1">¡Vamos por tu certificado!</h2>
        <p class="lead">Abre el navegador de tu celular o computador y escribe esta dirección en la barra de arriba:</p>
        <div class="url-caja">
            <span class="url-linea">
                <span class="url-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></span>
                <span class="url-txt" data-direccion>{!! $direccionPartida !!}</span>
            </span>
            <button class="btn-copiar solo-js" type="button" data-copiar="{{ route('portal.inicio') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h8"/></svg><span data-copiar-txt>Copiar</span></button>
        </div>
        <p class="url-aviso">Escríbela tal cual, sin espacios.</p>
        <div class="ir-portal">
            <a class="btn btn-sec btn-portal" href="{{ route('portal.inicio') }}" target="_blank" rel="noopener">Ir a consultar mis certificados<span class="solo-lector"> (se abre en una pestaña nueva)</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg></a>
            <p class="ir-portal-nota">Se abre en una pestaña nueva. Esta guía sigue aquí: usa «Siguiente» para continuar.</p>
        </div>
    </div>
    <div class="demo">
        <div class="demo-panel">
            <div class="m-disp" aria-hidden="true">
                <div class="m-pant">
                    <span class="m-isla"></span>
                    <div class="m-nav"><div class="m-url"><span class="marca linea t05">2</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg><span class="m-url-txt">{{ $direccion }}</span></div></div>
                    <div class="m-carga"></div>
                    <div class="m-sitio">
                        <div class="m-cab"><img class="m-logo" src="{{ $logo }}" alt=""><span class="m-cab-tit">Certificados</span></div>
                        <div class="m-card posrel">
                            <span class="marca esquina t35">3</span>
                            <div class="m-segs"><span class="on"></span><span></span><span></span></div>
                            <p class="m-paso">Paso 1 de 3</p>
                            <p class="m-h">Consulta de certificados</p>
                            <p class="m-p">Número de documento y correo electrónico registrados en el evento.</p>
                            <p class="m-label">Número de documento</p>
                            <div class="m-caja"></div>
                            <p class="m-label">Correo electrónico</p>
                            <div class="m-caja"></div>
                            <div class="m-boton">Continuar</div>
                        </div>
                    </div>
                </div>
            </div>
            {!! $repetir !!}
        </div>
    </div>
    <div class="tips">
        <ol class="tips-lista">
            <li class="tip"><span class="tip-num">1</span><div><p class="tip-t">Abre tu navegador</p><p class="tip-d">Es el programa con el que entras a internet: Chrome, Safari, Edge o Firefox.</p></div></li>
            <li class="tip"><span class="tip-num">2</span><div><p class="tip-t">Escribe la dirección arriba</p><p class="tip-d"><span class="tactil">Toca la barra de arriba, escribe la dirección y presiona «Ir».</span><span class="raton">Haz clic en la barra de direcciones, escribe la dirección y presiona la tecla «Enter».</span></p></div></li>
            <li class="tip"><span class="tip-num">3</span><div><p class="tip-t">Confirma que llegaste</p><p class="tip-d">Verás el logo de F&amp;C Consultores y el título «Consulta de certificados».</p></div></li>
        </ol>
    </div>
</section>

{{-- Paso 2 --}}
<section class="pantalla escena" id="paso-2" data-pantalla="2" data-nombre="{{ $pasos[2] }}" aria-labelledby="t-p2">
    <div class="texto">
        <p class="ceja">Paso 2 · {{ $pasos[2] }}</p>
        <h2 id="t-p2" class="esc-h" tabindex="-1">Cuéntanos quién eres</h2>
        <p class="lead">Escribe solo tu número de documento, sin puntos, espacios ni letras, y tu correo electrónico. Usa los mismos datos que registraste al inscribirte en la capacitación.</p>
    </div>
    <div class="demo">
        <div class="demo-panel">
            <div class="m-disp" aria-hidden="true">
                <div class="m-pant">
                    <span class="m-isla"></span>
                    <div class="m-arriba"></div>
                    <div class="m-cab"><img class="m-logo" src="{{ $logo }}" alt=""><span class="m-cab-tit">Certificados</span></div>
                    <div class="m-card">
                        <div class="m-segs"><span class="on"></span><span></span><span></span></div>
                        <p class="m-paso">Paso 1 de 3</p>
                        <p class="m-h">Consulta de certificados</p>
                        <p class="m-p">Si los datos coinciden con los registros, se envía un código de acceso al correo indicado.</p>
                        <p class="m-label">Número de documento</p>
                        <div class="m-caja a-foco t06">
                            <span class="marca t05">1</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 10h4M7 14h6M15 10h2"/></svg>
                            <span class="tecleo">1023456789</span>
                        </div>
                        <p class="m-label">Correo electrónico</p>
                        <div class="m-caja a-foco t24">
                            <span class="marca t23">2</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                            <span class="tecleo">ana.ejemplo@correo.com</span>
                        </div>
                        <div class="m-boton con-toque a-presiona t42">
                            <span class="marca t41">3</span>
                            Continuar
                            {!! $toque !!}
                        </div>
                    </div>
                </div>
            </div>
            {!! $repetir !!}
        </div>
    </div>
    <div class="tips">
        <ol class="tips-lista">
            <li class="tip"><span class="tip-num">1</span><div><p class="tip-t">Número de documento</p><p class="tip-d">Solo los números, completos y seguidos (por ejemplo, 1023456789). No hace falta escribir C.C., T.I. ni otras siglas.</p></div></li>
            <li class="tip"><span class="tip-num">2</span><div><p class="tip-t">Correo electrónico</p><p class="tip-d">Revisa la arroba (@) y que no le falte ninguna letra.</p></div></li>
            <li class="tip"><span class="tip-num">3</span><div><p class="tip-t"><span class="tactil">Toca</span><span class="raton">Haz clic en</span> «Continuar»</p><p class="tip-d">Es el botón naranja. Si tus datos coinciden con nuestros registros, te enviaremos un código a ese correo.</p></div></li>
        </ol>
        <div class="nota">{!! $info !!}<p>La página tiene solo 3 pantallas. En esta verás «Paso 1 de 3».</p></div>
    </div>
</section>

{{-- Paso 3 --}}
<section class="pantalla escena" id="paso-3" data-pantalla="3" data-nombre="{{ $pasos[3] }}" aria-labelledby="t-p3">
    <div class="texto">
        <p class="ceja">Paso 3 · {{ $pasos[3] }}</p>
        <h2 id="t-p3" class="esc-h" tabindex="-1">¡Tienes un mensaje!</h2>
        <p class="lead">Te enviamos un correo con un código de 6 números. Abre tu correo (Gmail, Outlook, Hotmail u otro) y busca este mensaje:</p>
        <p class="asunto"><span class="asunto-e">Asunto del correo</span><span class="asunto-t">Código de acceso para consultar certificados</span></p>
    </div>
    <div class="demo">
        <div class="demo-panel">
            <div class="m-disp" aria-hidden="true">
                <div class="m-pant">
                    <span class="m-isla"></span>
                    <div class="sobre-zona">
                        <div class="sobre">
                            <div class="sobre-fondo"></div>
                            <div class="carta"><p class="carta-tit">Código de acceso</p><p class="carta-cod">274619</p><p class="carta-nota">Código de ejemplo</p></div>
                            <svg class="sobre-frente" viewBox="0 0 176 110" preserveAspectRatio="none"><path d="M0 30 L88 76 L176 30 V98 Q176 110 164 110 H12 Q0 110 0 98 Z" class="f-pri"/><path d="M0 98 L64 62 M176 98 L112 62" stroke="#fff" stroke-opacity=".22" stroke-width="2"/></svg>
                            <span class="sobre-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg></span>
                        </div>
                    </div>
                    <div class="m-bandeja">
                        <div class="m-bandeja-cab"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 13h4l2 3h4l2-3h4"/><path d="M5 5h14l1 8v6H4v-6z"/></svg>Bandeja de entrada</div>
                        <div class="m-carpetas"><span class="m-carpeta on">Principal</span><span class="m-carpeta">Promociones</span><span class="m-carpeta">Spam</span><span class="marca linea t30">2</span></div>
                        <div class="m-msg nuevo">
                            <span class="m-av">F&amp;C</span>
                            <div class="m-msg-txt"><div class="m-de"><span class="marca linea t22">1</span><span>F&amp;C Consultores</span><span class="m-hora">Ahora</span></div><div class="m-asunto">Código de acceso para consultar certificados</div></div>
                        </div>
                        <div class="m-msg"><span class="m-av gris"></span><div class="m-msg-txt"><div class="m-esq w55"></div><div class="m-esq w85"></div></div></div>
                        <div class="m-msg"><span class="m-av gris"></span><div class="m-msg-txt"><div class="m-esq w40"></div><div class="m-esq w75"></div></div></div>
                    </div>
                </div>
            </div>
            {!! $repetir !!}
        </div>
    </div>
    <div class="tips">
        <ol class="tips-lista">
            <li class="tip"><span class="tip-num">1</span><div><p class="tip-t">Ábrelo y mira el código</p><p class="tip-d">Son 6 números. Puedes anotarlos o copiarlos.</p></div></li>
            <li class="tip"><span class="tip-num">2</span><div><p class="tip-t">¿No aparece?</p><p class="tip-d">Espera un minuto y revisa las carpetas Spam, Correo no deseado o Promociones.</p></div></li>
        </ol>
        <div class="nota"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><p>El código sirve durante {{ $vigencia }} minutos. No lo compartas con nadie.</p></div>
    </div>
</section>

{{-- Paso 4 --}}
<section class="pantalla escena" id="paso-4" data-pantalla="4" data-nombre="{{ $pasos[4] }}" aria-labelledby="t-p4">
    <div class="texto">
        <p class="ceja">Paso 4 · {{ $pasos[4] }}</p>
        <h2 id="t-p4" class="esc-h" tabindex="-1">¡Ya casi estamos!</h2>
        <p class="lead">Regresa a la página de certificados y escribe los 6 números en la casilla «Código de acceso». Luego <span class="tactil">toca</span><span class="raton">haz clic en</span> «Entrar».</p>
    </div>
    <div class="demo">
        <div class="demo-panel">
            <div class="m-disp" aria-hidden="true">
                <div class="m-pant">
                    <span class="m-isla"></span>
                    <div class="m-arriba"></div>
                    <div class="m-cab"><img class="m-logo" src="{{ $logo }}" alt=""><span class="m-cab-tit">Certificados</span></div>
                    <div class="m-card">
                        <div class="m-segs"><span class="on"></span><span class="on"></span><span></span></div>
                        <p class="m-paso">Paso 2 de 3</p>
                        <p class="m-h">Código de acceso</p>
                        <p class="m-p">Código de 6 dígitos enviado al correo electrónico. Vigencia de {{ $vigencia }} minutos.</p>
                        <p class="m-label">Código de acceso</p>
                        <div class="m-caja m-codigo a-foco t07">
                            <span class="marca t06">1</span>
                            <span class="dig i0">2</span><span class="dig i1">7</span><span class="dig i2">4</span><span class="dig i3">6</span><span class="dig i4">1</span><span class="dig i5">9</span>
                        </div>
                        <div class="m-boton con-toque a-presiona t32">
                            <span class="marca t31">2</span>
                            Entrar
                            {!! $toque !!}
                        </div>
                        <div class="m-enlaces posrel"><span class="marca t44">3</span><span>Enviar otro código</span><span>Usar otros datos</span></div>
                    </div>
                    <div class="m-teclado">
                        <div class="m-sugerencias"><span class="m-sug on">274619</span><span class="m-sug"></span><span class="m-sug"></span></div>
                        <div class="m-teclas">@for ($k = 0; $k < 10; $k++)<span></span>@endfor</div>
                        <div class="m-teclas r2">@for ($k = 0; $k < 9; $k++)<span></span>@endfor</div>
                    </div>
                </div>
            </div>
            {!! $repetir !!}
        </div>
    </div>
    <div class="tips">
        <ol class="tips-lista">
            <li class="tip"><span class="tip-num">1</span><div><p class="tip-t">Escribe el código</p><p class="tip-d">Los 6 números que llegaron a tu correo.</p></div></li>
            <li class="tip"><span class="tip-num">2</span><div><p class="tip-t"><span class="tactil">Toca</span><span class="raton">Haz clic en</span> «Entrar»</p><p class="tip-d">Si el código es correcto, verás tus certificados enseguida.</p></div></li>
            <li class="tip"><span class="tip-num">3</span><div><p class="tip-t">¿Algo salió mal?</p><p class="tip-d">«Enviar otro código» te manda uno nuevo. «Usar otros datos» te deja corregir tu documento o tu correo.</p></div></li>
        </ol>
        <div class="nota">{!! $info !!}<p>En algunos celulares el código aparece sobre el teclado: tócalo y se escribe solo.</p></div>
    </div>
</section>

{{-- Paso 5 --}}
<section class="pantalla escena" id="paso-5" data-pantalla="5" data-nombre="{{ $pasos[5] }}" aria-labelledby="t-p5">
    <div class="texto">
        <p class="ceja">Paso 5 · {{ $pasos[5] }}</p>
        <h2 id="t-p5" class="esc-h" tabindex="-1">¡Aquí están tus certificados!</h2>
        <p class="lead">Verás la lista «Certificados disponibles». Los publicados más recientemente aparecen primero, así que el de tu última capacitación suele estar arriba.</p>
    </div>
    <div class="demo">
        <div class="demo-panel">
            <div class="m-disp" aria-hidden="true">
                <div class="m-pant">
                    <span class="m-isla"></span>
                    <div class="m-arriba"></div>
                    <div class="m-cab"><img class="m-logo" src="{{ $logo }}" alt=""><span class="m-salir">Cerrar sesión</span></div>
                    <div class="m-panel">
                        <p class="m-paso">Paso 3 de 3</p>
                        <p class="m-h">Certificados disponibles</p>
                        <div class="m-resumen"><span>3 certificados</span><span class="ok">3 disponibles para descargar</span></div>
                        <div class="m-buscar t24">
                            <span class="marca linea t23">1</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                            <span class="m-buscar-txt"><span class="tecleo">Tributaria</span></span>
                            <span class="m-boton chico">Buscar</span>
                        </div>
                        <div class="m-filtros">
                            <div><span class="m-etq">Ordenar <span class="marca linea t38">2</span></span><div class="m-select">Más recientes<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></div></div>
                            <div><span class="m-etq">Publicado en</span><div class="m-select">Cualquier fecha<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></div></div>
                        </div>
                        <div class="m-rango"><span>Mostrando 1–3 de 3</span><span class="guia-nota t12">El más reciente, primero</span></div>
                    </div>
                    <div class="m-lista">
                        <div class="m-cert top t09">
                            <span class="marca esquina t10">3</span>
                            <p class="m-cert-h">Seminario de Actualización Tributaria 2026</p>
                            <p class="m-fecha">Publicado el 20 de octubre de 2026</p>
                            <div class="m-meta"><span class="m-insignia">Disponible</span></div>
                            <div class="m-acc"><span class="m-boton chico">Descargar PDF</span><span class="m-verif">Verificar</span></div>
                        </div>
                        <div class="m-cert">
                            <p class="m-cert-h">Diplomado en NIIF para Pymes</p>
                            <p class="m-fecha">Publicado el 14 de agosto de 2026</p>
                            <div class="m-meta"><span class="m-insignia">Disponible</span></div>
                            <div class="m-acc"><span class="m-boton chico">Descargar PDF</span><span class="m-verif">Verificar</span></div>
                        </div>
                        <div class="m-desvanece"></div>
                    </div>
                </div>
            </div>
            {!! $repetir !!}
        </div>
    </div>
    <div class="tips">
        <ol class="tips-lista">
            <li class="tip"><span class="tip-num">1</span><div><p class="tip-t">Busca por nombre</p><p class="tip-d">Escribe una palabra de la capacitación en «Buscar capacitación» y <span class="tactil">toca</span><span class="raton">haz clic en</span> «Buscar».</p></div></li>
            <li class="tip"><span class="tip-num">2</span><div><p class="tip-t">Ordena o filtra</p><p class="tip-d">En «Ordenar» elige Más recientes, Más antiguos o <span class="nw">Nombre (A–Z)</span>. También puedes filtrar por «Publicado en» y «Año del evento».</p></div></li>
            <li class="tip"><span class="tip-num">3</span><div><p class="tip-t">Reconoce el tuyo</p><p class="tip-d">Cada certificado muestra el nombre de la capacitación, la fecha en que se publicó y la etiqueta verde «Disponible».</p></div></li>
        </ol>
        <div class="nota">{!! $info !!}<p>La búsqueda y los filtros aparecen cuando tienes 2 o más certificados. Los certificados históricos sin fecha de publicación salen al final, en un grupo aparte.</p></div>
    </div>
</section>

{{-- Paso 6 --}}
<section class="pantalla escena" id="paso-6" data-pantalla="6" data-nombre="{{ $pasos[6] }}" aria-labelledby="t-p6">
    <div class="texto">
        <p class="ceja">Paso 6 · {{ $pasos[6] }}</p>
        <h2 id="t-p6" class="esc-h" tabindex="-1">¡Listo! Tu certificado te espera</h2>
        <p class="lead"><span class="tactil">Toca</span><span class="raton">Haz clic en</span> el botón naranja «Descargar PDF» al lado de tu certificado. El archivo se guarda en tu celular o computador.</p>
    </div>
    <div class="demo">
        <div class="demo-panel">
            <div class="m-disp" aria-hidden="true">
                <div class="m-pant">
                    <span class="m-isla"></span>
                    <div class="m-arriba"></div>
                    <div class="m-cab"><img class="m-logo" src="{{ $logo }}" alt=""><span class="m-salir">Cerrar sesión</span></div>
                    <div class="m-panel"><p class="m-paso">Paso 3 de 3</p><p class="m-h">Certificados disponibles</p></div>
                    <div class="m-lista sep">
                        <div class="m-cert">
                            <p class="m-cert-h">Seminario de Actualización Tributaria 2026</p>
                            <p class="m-fecha">Publicado el 20 de octubre de 2026</p>
                            <div class="m-meta"><span class="m-insignia">Disponible</span></div>
                            <div class="m-acc con-toque">
                                <span class="m-boton chico a-presiona t07"><span class="marca t05">1</span>Descargar PDF{!! $toque !!}</span>
                                <span class="m-verif">Verificar</span>
                            </div>
                        </div>
                    </div>
                    <div class="m-descargas">
                        <span class="marca esquina t15">2</span>
                        <p class="m-desc-t">Descargas</p>
                        <div class="m-desc-fila">
                            <svg class="pdf" viewBox="0 0 30 36"><path d="M4 2h15l9 9v21a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z" fill="#fff" stroke="#e2e8f0" stroke-width="1.5"/><path d="M19 2v9h9" fill="#f1f5f9" stroke="#e2e8f0" stroke-width="1.5"/><rect x="0" y="17" width="22" height="11" rx="2.5" fill="#dc2626"/><text x="11" y="25.2" text-anchor="middle" font-size="7.5" font-weight="800" fill="#fff" font-family="Arial, sans-serif">PDF</text></svg>
                            <div class="m-desc-info">
                                <div class="m-desc-nombre">Tu certificado (PDF)</div>
                                <div class="m-prog"><span></span></div>
                                <div class="m-desc-estado"><span class="uno">Descargando…</span><span class="dos"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5 9-10"/></svg>Descarga completa</span></div>
                            </div>
                        </div>
                    </div>
                    <div class="m-invita">
                        <span class="marca esquina t39">3</span>
                        <strong>Evaluación del evento</strong>
                        <p>La opinión recibida contribuye a mejorar continuamente nuestros eventos.</p>
                        <div class="m-invita-acc"><span class="m-boton chico">Responder encuesta</span><span class="m-mt">Más tarde</span></div>
                    </div>
                </div>
            </div>
            {!! $repetir !!}
        </div>
    </div>
    <div class="tips">
        <ol class="tips-lista">
            <li class="tip"><span class="tip-num">1</span><div><p class="tip-t"><span class="tactil">Toca</span><span class="raton">Haz clic en</span> «Descargar PDF»</p><p class="tip-d">Si tu certificado fue actualizado, el botón dice «Descargar certificado».</p></div></li>
            <li class="tip"><span class="tip-num">2</span><div><p class="tip-t">Espera unos segundos</p><p class="tip-d">Tu navegador te avisará cuando la descarga termine.</p></div></li>
            <li class="tip"><span class="tip-num">3</span><div><p class="tip-t">Encuesta opcional</p><p class="tip-d">Puede aparecer una invitación para evaluar el evento. Respóndela si quieres o <span class="tactil">toca</span><span class="raton">haz clic en</span> «Más tarde»: tu descarga no depende de ella.</p></div></li>
        </ol>
        <div class="donde">
            <h3 class="donde-t">¿Dónde quedó mi certificado?</h3>
            <div class="disp-grid">
                <div class="disp"><p class="disp-cab"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="7" y="2" width="10" height="20" rx="2.5"/><path d="M11 18h2"/></svg>Celular Android</p><p>Desliza hacia abajo la barra de arriba y toca la descarga, o abre la app «Archivos» y entra a «Descargas».</p></div>
                <div class="disp"><p class="disp-cab"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="7" y="2" width="10" height="20" rx="2.5"/><path d="M11 18h2"/></svg>iPhone</p><p>Toca el ícono de descargas del navegador, o abre la app «Archivos» y busca la carpeta «Descargas».</p></div>
                <div class="disp"><p class="disp-cab"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="5" width="16" height="11" rx="1.5"/><path d="M2 19h20"/></svg>Computador</p><p>Abre la carpeta «Descargas». También puedes abrirlo desde el aviso de descarga del navegador.</p></div>
            </div>
        </div>
    </div>
</section>

{{-- Final --}}
<section class="pantalla final" id="listo" data-pantalla="7" aria-labelledby="t-final">
    <div class="final-cab">
        <div class="confeti" aria-hidden="true">@for ($i = 0; $i < 16; $i++)<span class="cf"></span>@endfor</div>
        <div class="final-arte" aria-hidden="true">
            <img class="final-ovi" src="/images/ovi/ovi-saluda.png" alt="" width="402" height="519">
            <div class="medalla">
                <svg viewBox="0 0 160 160" fill="none">
                    <circle cx="80" cy="82" r="74" class="f-tinte"/>
                    <rect x="40" y="26" width="80" height="104" rx="10" fill="#fff" stroke="#e2e8f0" stroke-width="2"/>
                    <rect x="52" y="44" width="44" height="6" rx="3" class="f-pri"/>
                    <rect x="52" y="58" width="56" height="4" rx="2" fill="#e2e8f0"/>
                    <rect x="52" y="68" width="48" height="4" rx="2" fill="#e2e8f0"/>
                    <rect x="52" y="78" width="36" height="4" rx="2" fill="#e2e8f0"/>
                    <path d="M86 112 l-6 28 9-6 7 8 2-28 Z" class="f-pri"/>
                    <circle cx="94" cy="108" r="16" class="f-oro"/>
                    <path d="M87 108 l5 5 9-10" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="122" cy="36" r="20" fill="#16a34a"/>
                    <path d="M112 36 l7 7 13-14" stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
        </div>
        <p class="ceja">Proceso completado</p>
        <h2 id="t-final" class="final-h" tabindex="-1">¡Felicitaciones! <span>Ya tienes tu certificado</span></h2>
        <p class="final-p">Guárdalo en un lugar fácil de encontrar. Si lo necesitas otra vez, puedes volver a la página y descargarlo de nuevo.</p>
        <div class="final-acc">
            <a class="btn btn-pri" href="{{ route('portal.inicio') }}">Consultar mis certificados<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
            <a class="btn btn-sec" href="#inicio-guia" data-ir="0"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>Ver la guía otra vez</a>
        </div>
    </div>
    <div class="ayuda-sec" id="ayuda">
        <h3 class="ayuda-h">¿Necesitas ayuda?</h3>
        <p class="ayuda-sub">Si algo no salió como esperabas, revisa estos consejos.</p>
        @include('credential-flow.portal.partials.guia-ayuda')
    </div>
</section>

{{-- Navegación inferior (solo con JavaScript) --}}
<nav class="navbar" aria-label="Navegación de la guía" data-navbar hidden>
    <div class="nav-in">
        <button class="btn btn-sec" type="button" data-anterior><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>Anterior</button>
        <button class="btn btn-pri" type="button" data-siguiente><span data-siguiente-txt>Siguiente</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
    </div>
</nav>

<dialog class="hoja" aria-labelledby="t-ayuda-dialogo" data-dialogo-ayuda>
    <div class="agarre" aria-hidden="true"></div>
    <div class="hoja-cab">
        <h2 id="t-ayuda-dialogo">¿Necesitas ayuda?</h2>
        <button class="btn-cerrar" type="button" aria-label="Cerrar" data-cerrar-ayuda><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
    </div>
    <p class="ayuda-sub">Revisa estos consejos sencillos. La mayoría de las dificultades se resuelven así.</p>
    @include('credential-flow.portal.partials.guia-ayuda')
</dialog>
</div>
@endsection
@push('scripts')
    <script nonce="{{ $nonce }}">
        // Guía paso a paso: una pantalla a la vez, con historial (el botón «Atrás» del navegador vuelve al paso anterior) y enlaces directos #paso-N.
        (function () {
            var guia = document.querySelector('[data-guia]');
            if (!guia) { return; }
            var pantallas = [].slice.call(guia.querySelectorAll('[data-pantalla]'));
            var progreso = guia.querySelector('[data-progreso]');
            var navbar = guia.querySelector('[data-navbar]');
            var anuncio = guia.querySelector('[data-anuncio]');
            var dialogo = document.querySelector('[data-dialogo-ayuda]');
            var actual = 0;
            var quieto = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            function deHash() {
                var m = /^#paso-([1-6])$/.exec(location.hash);
                if (m) { return +m[1]; }
                return location.hash === '#listo' || location.hash === '#ayuda' ? 7 : 0;
            }

            function hashDe(n) { return n === 0 ? location.pathname + location.search : (n === 7 ? '#listo' : '#paso-' + n); }

            function mostrar(n, desdeUsuario) {
                var dir = n >= actual ? 'entra-adelante' : 'entra-atras';
                actual = n;
                pantallas.forEach(function (p) {
                    var esta = +p.getAttribute('data-pantalla') === n;
                    p.classList.toggle('activa', esta);
                    p.classList.remove('entra-adelante', 'entra-atras');
                    if (esta && p.classList.contains('escena')) { p.classList.add(dir); }
                });
                var enPaso = n >= 1 && n <= 6;
                progreso.hidden = !enPaso;
                navbar.hidden = !enPaso;
                if (enPaso) {
                    var p = pantallas[n];
                    guia.querySelector('[data-num]').textContent = n;
                    guia.querySelector('[data-nombre]').textContent = p.getAttribute('data-nombre');
                    guia.querySelector('[data-siguiente-txt]').textContent = n === 6 ? '¡Terminé!' : 'Siguiente';
                    [].forEach.call(guia.querySelectorAll('[data-seg]'), function (s) {
                        var k = +s.getAttribute('data-seg');
                        s.classList.toggle('hecho', k < n);
                        s.classList.toggle('activo', k === n);
                        if (k === n) { s.setAttribute('aria-current', 'step'); } else { s.removeAttribute('aria-current'); }
                    });
                    anuncio.textContent = 'Paso ' + n + ' de 6: ' + p.getAttribute('data-nombre');
                } else {
                    anuncio.textContent = n === 7 ? '¡Felicitaciones! Ya tienes tu certificado' : '';
                }
                if (desdeUsuario) {
                    window.scrollTo({ top: 0, behavior: quieto ? 'auto' : 'smooth' });
                    var t = pantallas[n].querySelector('[tabindex="-1"]');
                    if (t) { t.focus({ preventScroll: true }); }
                }
            }

            function ir(n) {
                if (n === actual) { return; }
                history.pushState({ paso: n }, '', hashDe(n));
                mostrar(n, true);
            }

            guia.addEventListener('click', function (e) {
                var a = e.target.closest('[data-ir]');
                if (a) { e.preventDefault(); ir(+a.getAttribute('data-ir')); return; }
                if (e.target.closest('[data-siguiente]')) { ir(Math.min(7, actual + 1)); return; }
                if (e.target.closest('[data-anterior]')) { ir(Math.max(0, actual - 1)); return; }
                var r = e.target.closest('[data-repetir]');
                if (r) {
                    // Reemplazar la maqueta por una copia reinicia sus animaciones desde el principio.
                    var disp = r.parentNode.querySelector('.m-disp');
                    if (disp) { disp.parentNode.replaceChild(disp.cloneNode(true), disp); }
                    return;
                }
                var c = e.target.closest('[data-copiar]');
                if (c && navigator.clipboard) {
                    navigator.clipboard.writeText(c.getAttribute('data-copiar')).then(function () {
                        var txt = c.querySelector('[data-copiar-txt]');
                        txt.textContent = '¡Copiada!';
                        setTimeout(function () { txt.textContent = 'Copiar'; }, 2200);
                    }, function () {});
                }
            });

            document.addEventListener('click', function (e) {
                if (!e.target.closest('[data-abrir-ayuda]')) { return; }
                if (dialogo && typeof dialogo.showModal === 'function') {
                    e.preventDefault();
                    dialogo.showModal();
                } else {
                    e.preventDefault();
                    ir(7);
                    document.getElementById('ayuda').scrollIntoView();
                }
            });

            if (dialogo) {
                dialogo.addEventListener('click', function (e) {
                    // Un toque fuera de la hoja (en el fondo oscuro) o en «Cerrar» la cierra.
                    if (e.target === dialogo || e.target.closest('[data-cerrar-ayuda]')) { dialogo.close(); }
                });
            }

            window.addEventListener('popstate', function () { mostrar(deHash(), true); });

            mostrar(deHash(), false);
            // Un enlace directo (#paso-N, #listo) abre su pantalla desde arriba; #ayuda baja a los consejos. Se hace al cargar para ganarle al salto del ancla.
            if (location.hash) {
                window.addEventListener('load', function () {
                    var ayuda = location.hash === '#ayuda' ? document.getElementById('ayuda') : null;
                    if (ayuda) { ayuda.scrollIntoView(); } else { window.scrollTo(0, 0); }
                });
            }
        })();
    </script>
@endpush
