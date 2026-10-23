@extends('credential-flow.portal.layout')
@section('titulo', 'Consulta de certificados')
@section('ancho', 'estrecho')
@push('estilos')
    <style nonce="{{ $nonce }}">
        .guia-enlace { display: flex; align-items: center; gap: .9rem; margin-top: 1rem; padding: .8rem 1rem; border: 1px solid var(--borde); border-radius: 1.25rem; background: #fff; color: var(--texto); text-decoration: none; box-shadow: var(--sombra); transition: all .3s cubic-bezier(.4, 0, .2, 1); }
        .guia-enlace:hover { border-color: var(--vino); transform: translateY(-2px); }
        .guia-enlace:focus-visible { outline: 0; box-shadow: 0 0 0 4px rgba(225, 29, 72, .2); }
        .guia-enlace img { flex: none; width: 2.6rem; height: auto; }
        .guia-enlace .guia-texto { flex: 1; min-width: 0; }
        .guia-enlace strong { display: block; font-size: 1rem; color: var(--vino); }
        .guia-enlace .suave { display: block; font-size: .88rem; }
        .guia-enlace svg { flex: none; width: 1.3rem; height: 1.3rem; color: var(--vino); }
    </style>
@endpush
@section('contenido')
    <section class="estrecho aparece" data-pantalla="inicio">
        <div class="tarjeta">
            @include('credential-flow.portal.partials.pasos', ['paso' => 1])
            <p class="paso">Paso 1 de 3</p>
            <h1>Consulta de certificados</h1>
            <p class="suave">Número de documento y correo electrónico registrados en el evento. Si los datos coinciden con los registros, se envía un código de acceso al correo indicado.</p>

            <form method="POST" action="{{ route('portal.solicitar') }}" novalidate data-cargando>
                @csrf
                <div class="campo">
                    <label for="documento">Número de documento</label>
                    <div class="caja">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 10h4M7 14h6M15 10h2"/></svg>
                        <input id="documento" name="documento" type="text" inputmode="text" autocomplete="off" maxlength="60" value="{{ old('documento') }}" placeholder="Ej. 1012345678" required>
                    </div>
                    @error('documento')<p class="suave" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="campo">
                    <label for="correo">Correo electrónico</label>
                    <div class="caja">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                        <input id="correo" name="correo" type="email" autocomplete="email" maxlength="254" value="{{ old('correo') }}" placeholder="correo@ejemplo.com" required>
                    </div>
                    @error('correo')<p class="suave" role="alert">{{ $message }}</p>@enderror
                </div>

                <button class="boton ancho" type="submit">Continuar</button>
            </form>
        </div>

        <a class="guia-enlace" href="{{ route('portal.guia') }}" data-guia-enlace>
            <img src="/images/ovi/ovi-saluda.png" alt="" width="402" height="519">
            <span class="guia-texto"><strong>¿Cómo descargar mi certificado?</strong><span class="suave">Guía paso a paso, con ejemplos</span></span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
    </section>
@endsection
