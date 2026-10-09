@extends('credential-flow.portal.layout')
@section('titulo', 'Tus certificados')
@section('contenido')
    <section data-pantalla="panel">
        <div class="fila">
            <h1>Tus certificados</h1>
            <form class="en-linea" method="POST" action="{{ route('portal.salir') }}">@csrf<button class="boton sec" type="submit">Cerrar sesión</button></form>
        </div>

        @forelse ($tarjetas as $t)
            <article class="tarjeta cert" data-certificado="{{ $t['tipo'] }}">
                <h2>{{ $t['evento'] }}</h2>
                <div class="fila">
                    @if ($t['anio'] !== null)<span class="suave">Año {{ $t['anio'] }}</span>@endif
                    <span class="insignia {{ match ($t['tipo']) { 'disponible', 'actualizado' => 'ok', 'revocado' => 'mal', 'reemplazado' => '', default => 'alerta' } }}">{{ match ($t['tipo']) { 'disponible' => 'Disponible', 'actualizado' => 'Certificado actualizado', 'revocado' => 'Revocado', 'reemplazado' => 'Versión posterior', default => 'En revisión' } }}</span>
                </div>
                @if ($t['codigo'] !== null)<p class="suave">Código del certificado: {{ $t['codigo'] }}</p>@endif
                @if (! empty($t['estado_emision']))<p class="suave">Estado: {{ $t['estado_emision'] }}</p>@endif
                <p>{{ $t['mensaje'] }}</p>

                @if ($t['descargable'])
                    <a class="boton" href="{{ route('portal.descargar', $t['id']) }}">{{ $t['tipo'] === 'actualizado' ? 'Descargar certificado' : 'Descargar PDF' }}</a>
                @elseif ($t['enlace_moderno'])
                    <a class="boton sec" href="{{ $t['enlace_moderno'] }}" rel="noopener">Ver la versión más reciente</a>
                @elseif ($contacto && ! in_array($t['tipo'], ['revocado', 'reemplazado'], true))
                    <a class="boton sec" href="{{ $contacto }}" rel="noopener">Escríbenos</a>
                @endif
            </article>
        @empty
            <article class="tarjeta"><p>No encontramos certificados para mostrar. Escríbenos y te ayudamos.</p></article>
        @endforelse
    </section>
@endsection
