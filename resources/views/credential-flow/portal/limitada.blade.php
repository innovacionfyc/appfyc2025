@extends('credential-flow.portal.layout')
@section('titulo', 'Demasiados intentos')
@section('ancho', 'estrecho')
@section('contenido')
    <section class="estrecho aparece" data-pantalla="limitada">
        <div class="tarjeta centrado">
            <p class="paso">Un momento</p>
            <h1>Demasiados intentos</h1>
            <p class="suave">Intentar nuevamente en unos minutos.</p>
            <a class="boton sec" href="{{ route('portal.inicio') }}">Volver al inicio</a>
        </div>
    </section>
@endsection
