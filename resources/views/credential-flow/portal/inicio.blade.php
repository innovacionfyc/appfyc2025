@extends('credential-flow.portal.layout')
@section('titulo', 'Consulta tus certificados')
@section('ancho', 'estrecho')
@section('contenido')
    <section class="estrecho aparece" data-pantalla="inicio">
        <div class="tarjeta">
            @include('credential-flow.portal.partials.pasos', ['paso' => 1])
            <p class="paso">Paso 1 de 3</p>
            <h1>Consulta tus certificados</h1>
            <p class="suave">Escribe tu documento y el correo con el que te inscribiste. Si coinciden con nuestros registros, te enviaremos un código al correo.</p>

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
                        <input id="correo" name="correo" type="email" autocomplete="email" maxlength="254" value="{{ old('correo') }}" placeholder="tucorreo@ejemplo.com" required>
                    </div>
                    @error('correo')<p class="suave" role="alert">{{ $message }}</p>@enderror
                </div>

                <button class="boton ancho" type="submit">Continuar</button>
            </form>
        </div>
    </section>
@endsection
