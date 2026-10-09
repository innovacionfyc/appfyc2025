@extends('credential-flow.portal.layout')
@section('titulo', 'Consulta tus certificados')
@section('contenido')
    <section class="tarjeta" data-pantalla="inicio">
        <h1>Consulta tus certificados</h1>
        <p class="suave">Escribe tu documento y el correo con el que te inscribiste. Si coinciden con nuestros registros, te enviaremos un código al correo.</p>

        <form method="POST" action="{{ route('portal.solicitar') }}" novalidate>
            @csrf
            <label for="documento">Número de documento</label>
            <input id="documento" name="documento" type="text" inputmode="text" autocomplete="off" maxlength="60" value="{{ old('documento') }}" required>
            @error('documento')<p class="suave" role="alert">{{ $message }}</p>@enderror

            <label for="correo">Correo electrónico</label>
            <input id="correo" name="correo" type="email" autocomplete="email" maxlength="254" value="{{ old('correo') }}" required>
            @error('correo')<p class="suave" role="alert">{{ $message }}</p>@enderror

            <button class="boton ancho" type="submit">Continuar</button>
        </form>
    </section>
@endsection
