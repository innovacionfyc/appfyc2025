@extends('credential-flow.portal.layout')
@section('titulo', 'Evaluación del evento')
@section('acciones')
    <form class="en-linea" method="POST" action="{{ route('portal.salir') }}" data-cargando>@csrf<button class="boton sec chico" type="submit">Cerrar sesión</button></form>
@endsection
@section('ancho', 'encuesta-ancho')
@php
    $errores = $errores ?? [];
    $valorAnterior = fn (string $clave, $defecto = null) => old('r.'.$clave, $defecto);
@endphp
@push('estilos')
    <style nonce="{{ $nonce }}">
        .encuesta-ancho, .encuesta { max-width: 46rem; margin-left: auto; margin-right: auto; }
        .evento { margin: .9rem 0 0; padding: .7rem 1rem; background: var(--fondo); border: 1px solid var(--borde); border-radius: .9rem; }
        .evento h2 { font-size: .98rem; }
        .pregunta { margin: 0; padding: 1.1rem 0; border-top: 1px solid var(--borde); min-width: 0; }
        .evento + form .pregunta:first-of-type, .evento + form .error + .pregunta { border-top: 0; }
        form[data-formulario] { margin-top: .4rem; }
        .pregunta .titulo { display: block; line-height: 1.4; width: 100%; margin: 0 0 .65rem; padding: 0; font-size: .98rem; font-weight: 700; color: var(--texto); }
        .pregunta .num { display: inline-block; min-width: 1.5rem; color: var(--vino); font-weight: 800; }
        .opciones { display: flex; flex-wrap: wrap; gap: .5rem; }
        .opcion { position: relative; margin: 0; padding: 0; }
        .opcion input { position: absolute; opacity: 0; inset: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
        .opcion span { display: inline-flex; align-items: center; gap: .5rem; padding: .55rem 1rem; border: 2px solid var(--borde); border-radius: .9rem; background: #fff; color: #334155; font-size: .92rem; font-weight: 700; transition: all .3s cubic-bezier(.4, 0, .2, 1); }
        .opcion:hover span { border-color: #cbd5e1; background: var(--fondo); }
        .opcion input:focus-visible + span { border-color: var(--foco); box-shadow: 0 0 0 4px rgba(225, 29, 72, .15); }
        .opcion input:checked + span { border-color: var(--vino); background: #fdf2f4; color: var(--vino); box-shadow: 0 6px 14px -10px rgba(148, 41, 52, .6); }
        .opcion input:active + span { transform: scale(.98); }
        .campo-texto { width: 100%; min-height: 6.5rem; padding: .8rem 1rem; resize: vertical; font: inherit; font-size: .98rem; color: #334155; background: var(--fondo); border: 2px solid var(--borde); border-radius: .9rem; transition: all .3s cubic-bezier(.4, 0, .2, 1); }
        .campo-texto:hover { border-color: #cbd5e1; }
        .campo-texto:focus { outline: 0; background: #fff; border-color: var(--foco); box-shadow: 0 0 0 4px rgba(225, 29, 72, .15), 0 12px 24px -14px rgba(15, 23, 42, .3); }
        .error.aparte { margin-top: 1rem; }
        .fallo { margin: .5rem 0 0; font-size: .85rem; font-weight: 700; color: var(--mal-texto); }
        .contador { display: block; margin-top: .3rem; text-align: right; font-size: .75rem; color: var(--tenue); }
        .acciones-encuesta { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; margin-top: 1.25rem; padding-top: 1.1rem; border-top: 1px solid var(--borde); }
        .acciones-encuesta .boton { margin-top: 0; }
        @media (max-width: 479px) { .acciones-encuesta .boton { width: 100%; } .opcion span { padding: .5rem .85rem; } }
    </style>
@endpush
@section('contenido')
    <section class="encuesta aparece" data-pantalla="encuesta">
        <div class="tarjeta">
            <p class="paso">Evaluación del evento</p>
            <h1>Evaluación del evento</h1>
            <p class="suave">{{ $definicion['introduccion'] ?: 'La opinión recibida contribuye a mejorar continuamente nuestros eventos y servicios.' }}</p>
            <div class="evento" data-evento>
                <h2>{{ $tarjeta['evento'] }}</h2>
                @if ($tarjeta['anio'] !== null)<span class="suave">Año {{ $tarjeta['anio'] }}</span>@endif
            </div>

            <form method="POST" action="{{ route('portal.encuesta.guardar', $tarjeta['id']) }}" novalidate data-cargando data-formulario="encuesta">
                @csrf
                @if (! empty($errores['_']))<div class="error aparte" role="alert" data-mensaje="error">{{ $errores['_'] }}</div>@endif

                @foreach ($definicion['preguntas'] as $i => $p)
                    @php($clave = $p['clave'])
                    @php($idCampo = 'p-'.$clave)
                    @if ($p['tipo'] === 'texto')
                        @php($max = min(2000, (int) ($p['configuracion']['max_caracteres'] ?? 2000)))
                        <div class="pregunta" data-pregunta="{{ $clave }}">
                            <label class="titulo" for="{{ $idCampo }}"><span class="num">{{ $i + 1 }}.</span>{{ $p['texto'] }}@if (! $p['obligatoria']) <span class="suave">(opcional)</span>@endif</label>
                            <textarea class="campo-texto" id="{{ $idCampo }}" name="r[{{ $clave }}]" maxlength="{{ $max }}" rows="3">{{ $valorAnterior($clave, '') }}</textarea>
                            @if (! empty($errores[$clave]))<p class="fallo" role="alert">{{ $errores[$clave] }}</p>@endif
                        </div>
                    @else
                        @php($multiple = $p['tipo'] === 'opcion_multiple')
                        @php($previo = $valorAnterior($clave))
                        <div class="pregunta" role="group" aria-labelledby="t-{{ $clave }}" data-pregunta="{{ $clave }}">
                            <p class="titulo" id="t-{{ $clave }}"><span class="num">{{ $i + 1 }}.</span>{{ $p['texto'] }}@if (! $p['obligatoria']) <span class="suave">(opcional)</span>@endif</p>
                            <div class="opciones">
                                @if ($p['tipo'] === 'escala')
                                    @php($cfg = (array) $p['configuracion'])
                                    @for ($n = (int) ($cfg['min'] ?? 1); $n <= (int) ($cfg['max'] ?? 5); $n++)
                                        <label class="opcion"><input type="radio" name="r[{{ $clave }}]" value="{{ $n }}" @checked((string) $previo === (string) $n)><span>{{ $n }}</span></label>
                                    @endfor
                                @else
                                    @foreach ($p['opciones'] as $o)
                                        <label class="opcion">
                                            <input type="{{ $multiple ? 'checkbox' : 'radio' }}" name="r[{{ $clave }}]{{ $multiple ? '[]' : '' }}" value="{{ $o['valor'] }}" @checked($multiple ? in_array($o['valor'], (array) $previo, true) : (string) $previo === (string) $o['valor'])>
                                            <span>{{ $o['etiqueta'] }}</span>
                                        </label>
                                    @endforeach
                                @endif
                            </div>
                            @if (! empty($errores[$clave]))<p class="fallo" role="alert">{{ $errores[$clave] }}</p>@endif
                        </div>
                    @endif
                @endforeach

                <div class="acciones-encuesta">
                    <a class="boton sec" href="{{ route('portal.panel') }}">Volver a certificados</a>
                    <button class="boton" type="submit">Enviar respuestas</button>
                </div>
            </form>
        </div>
    </section>
@endsection
