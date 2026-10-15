@extends('credential-flow.portal.layout', ['sinSesion' => true])
@section('titulo', 'Portal no disponible')
@section('ancho', 'estrecho')
@section('contenido')
    <section class="estrecho aparece" data-pantalla="portal-no-disponible">
        <div class="tarjeta centrado">
            <p class="paso">Portal de certificados</p>
            <h1>El portal de certificados aún no está disponible</h1>
            <p class="suave">Intentar nuevamente más adelante.</p>
        </div>
    </section>
@endsection
