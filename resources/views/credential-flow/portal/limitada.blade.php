@extends('credential-flow.portal.layout')
@section('titulo', 'Demasiados intentos')
@section('contenido')
    <section class="tarjeta" data-pantalla="limitada">
        <h1>Demasiados intentos</h1>
        <p class="suave">Espera unos minutos e inténtalo de nuevo.</p>
        <a class="boton sec" href="{{ route('portal.inicio') }}">Volver al inicio</a>
    </section>
@endsection
