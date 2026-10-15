@extends('credential-flow.portal.layout')
@section('titulo', 'Escribe tu código')
@section('ancho', 'estrecho')
@section('contenido')
    <section class="estrecho aparece" data-pantalla="codigo">
        <div class="tarjeta">
            @include('credential-flow.portal.partials.pasos', ['paso' => 2])
            <p class="paso">Paso 2 de 3</p>
            <h1>Verifica tu correo</h1>
            <p class="suave">Revisa tu correo e ingresa el código de 6 dígitos. Vence en {{ (int) config('credential_flow.portal.otp_vigencia_minutos') }} minutos.</p>

            <form method="POST" action="{{ route('portal.validar') }}" novalidate data-cargando>
                @csrf
                <div class="campo">
                    <label for="codigo">Código</label>
                    <div class="caja codigo">
                        <input id="codigo" name="codigo" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" placeholder="······" required>
                    </div>
                </div>
                <button class="boton ancho" type="submit">Entrar</button>
            </form>

            <div class="enlaces">
                <form class="en-linea" method="POST" action="{{ route('portal.reenviar') }}" data-cargando>@csrf<button class="enlace" type="submit">Enviar otro código</button></form>
                <a class="enlace" href="{{ route('portal.inicio') }}">Usar otros datos</a>
            </div>
        </div>
    </section>
@endsection
