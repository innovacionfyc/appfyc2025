@extends('credential-flow.portal.layout')
@section('titulo', 'Escribe tu código')
@section('contenido')
    <section class="tarjeta" data-pantalla="codigo">
        <h1>Escribe tu código</h1>
        <p class="suave">Revisa tu correo e ingresa el código de 6 dígitos. Vence en {{ (int) config('credential_flow.portal.otp_vigencia_minutos') }} minutos.</p>

        <form method="POST" action="{{ route('portal.validar') }}" novalidate>
            @csrf
            <label for="codigo">Código</label>
            <input id="codigo" class="codigo-input" name="codigo" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required>
            <button class="boton ancho" type="submit">Entrar</button>
        </form>

        <div class="fila">
            <form class="en-linea" method="POST" action="{{ route('portal.reenviar') }}">@csrf<button class="boton sec" type="submit">Enviar otro código</button></form>
            <a href="{{ route('portal.inicio') }}">Usar otros datos</a>
        </div>
    </section>
@endsection
