@extends('credential-flow.portal.layout')
@section('titulo', 'Certificados disponibles')
@section('acciones')
    <form class="en-linea" method="POST" action="{{ route('portal.salir') }}" data-cargando>@csrf<button class="boton sec chico" type="submit">Cerrar sesión</button></form>
@endsection
@php
    $l = $listado ?? null;
    $consulta = fn (array $extra = []) => array_filter(
        array_merge(['q' => $l['q'] ?? '', 'anio' => $l['anio'] ?? null, 'orden' => ($l['orden'] ?? 'recientes') === 'recientes' ? null : $l['orden']], $extra),
        fn ($v) => $v !== null && $v !== '' && $v !== 1
    );
    $etiqueta = fn (string $tipo) => match ($tipo) { 'disponible' => 'Disponible', 'actualizado' => 'Certificado actualizado', 'revocado' => 'Revocado', 'reemplazado' => 'Versión posterior', default => 'En revisión' };
    $clase = fn (string $tipo) => match ($tipo) { 'disponible', 'actualizado' => 'ok', 'revocado' => 'mal', 'reemplazado' => 'neutra', default => '' };
@endphp
@push('estilos')
    <style nonce="{{ $nonce }}">
        .encabezado { margin: .25rem 0 1.25rem; }
        .encabezado h1 { margin-bottom: .6rem; }
        .resumen { display: flex; flex-wrap: wrap; gap: .4rem; margin: 0; padding: 0; list-style: none; }
        .resumen li { padding: .2rem .7rem; border-radius: 999px; background: #f1f5f9; color: #475569; font-size: .8rem; font-weight: 700; }
        .resumen li.ok { background: var(--ok-fondo); color: var(--ok-texto); }

        .busqueda { margin: 0 0 1rem; }
        .buscador { display: flex; align-items: center; gap: .6rem; padding: .25rem .3rem .25rem 1rem; background: #fff; border: 2px solid var(--borde); border-radius: 1.1rem; box-shadow: var(--sombra); transition: all .3s cubic-bezier(.4, 0, .2, 1); }
        .buscador:hover { border-color: #cbd5e1; }
        .buscador:focus-within { border-color: var(--foco); box-shadow: 0 0 0 4px rgba(225, 29, 72, .15), 0 12px 24px -14px rgba(15, 23, 42, .3); }
        .buscador svg { flex: none; width: 1.25rem; height: 1.25rem; color: #94a3b8; }
        .buscador:focus-within svg { color: var(--foco); }
        .buscador input { flex: 1; min-width: 0; padding: .7rem 0; border: 0; background: transparent; font: inherit; font-size: 1rem; font-weight: 600; color: #334155; outline: 0; }
        .buscador input::placeholder { color: #94a3b8; font-weight: 500; }
        .buscador .boton { margin-top: 0; padding: .6rem 1.1rem; font-size: .88rem; border-radius: .85rem; }
        .filtros { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin-top: .6rem; }
        .filtros .caja { padding: 0 .85rem; border-radius: .8rem; background: #fff; }
        .filtros .caja select { padding: .55rem 0; font-size: .9rem; }
        .filtros .limpiar { grid-column: 1 / -1; text-align: right; font-size: .85rem; }
        .solo-lector { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); border: 0; white-space: nowrap; }
        .rango { margin: 0 .25rem .5rem; font-size: .82rem; min-height: 1.1rem; }

        .lista { background: #fff; border: 1px solid var(--borde); border-radius: 1.25rem; box-shadow: var(--sombra); overflow: hidden; }
        .cert { display: grid; grid-template-columns: 1fr; gap: .7rem; align-items: center; padding: .95rem 1.1rem; border-bottom: 1px solid #eef2f6; transition: background .3s; }
        .cert:last-child { border-bottom: 0; }
        .cert:hover { background: var(--fondo); }
        .cert-info { min-width: 0; }
        .cert h2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; font-size: .96rem; }
        .cert-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .2rem .6rem; margin-top: .3rem; }
        .cert-meta .suave { font-size: .8rem; }
        .cert-msg { margin: .35rem 0 0; font-size: .84rem; }
        .cert-acciones { display: flex; align-items: center; gap: .9rem; }
        .cert-acciones .boton { flex: 1; }
        .cert-acciones .enlace { font-size: .85rem; white-space: nowrap; }
        .invita { grid-column: 1 / -1; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem 1rem; padding: .75rem 1rem; border: 1px solid #fed7aa; border-radius: .9rem; background: #fff7ed; animation: aparece .4s cubic-bezier(.4, 0, .2, 1) both; }
        .invita[hidden] { display: none; }
        .invita strong { font-size: .9rem; }
        .invita p { margin: .1rem 0 0; font-size: .82rem; }
        .invita-acciones { display: flex; align-items: center; gap: 1rem; }
        .invita .enlace { font-size: .86rem; }
        .vacio { margin: 0; border: 0; box-shadow: none; padding: 2.25rem 1.25rem; }

        .paginador { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: .25rem; margin: 1.4rem 0 .25rem; }
        .paginador a, .paginador .actual { min-width: 2.2rem; padding: .35rem .6rem; text-align: center; border-radius: .7rem; font-weight: 700; font-size: .85rem; text-decoration: none; color: #475569; transition: all .3s cubic-bezier(.4, 0, .2, 1); }
        .paginador a:hover { background: #f1f5f9; color: var(--vino); }
        .paginador .actual { background: var(--vino); color: #fff; }
        .paginador .puntos { color: var(--tenue); padding: 0 .1rem; }
        .paginador .etq { display: none; }

        @media (min-width: 480px) { .paginador .etq { display: inline; } }
        @media (min-width: 640px) {
            .encabezado { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; }
            .encabezado h1 { margin-bottom: 0; }
            .filtros { grid-template-columns: 11rem 12rem 1fr; align-items: center; }
            .filtros .limpiar { grid-column: auto; }
            .cert { grid-template-columns: 1fr auto; padding: .8rem 1.2rem; gap: 1.25rem; }
            .cert-acciones .boton { flex: none; }
        }
    </style>
@endpush
@section('contenido')
    <section class="aparece" data-pantalla="panel">
        <div class="encabezado">
            <div>
                <p class="paso">Paso 3 de 3</p>
                <h1>Certificados disponibles</h1>
            </div>
            @if ($l !== null && $l['total_sin_filtros'] > 0)
                <ul class="resumen" data-resumen>
                    <li>{{ $l['total_sin_filtros'] }} {{ $l['total_sin_filtros'] === 1 ? 'certificado' : 'certificados' }}</li>
                    @if ($l['descargables'] > 0)<li class="ok">{{ $l['descargables'] }} {{ $l['descargables'] === 1 ? 'disponible' : 'disponibles' }} para descargar</li>@endif
                </ul>
            @endif
        </div>

        @if ($l !== null && $l['con_controles'])
            <form class="busqueda" method="GET" action="{{ route('portal.panel') }}" role="search" data-controles>
                <div class="buscador">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <label class="solo-lector" for="q">Buscar por nombre del evento</label>
                    <input id="q" name="q" type="search" maxlength="80" value="{{ $l['q'] }}" placeholder="Buscar evento">
                    <button class="boton chico" type="submit">Buscar</button>
                </div>
                <div class="filtros">
                    <div class="campo">
                        <label class="solo-lector" for="anio">Año</label>
                        <div class="caja">
                            <select id="anio" name="anio">
                                <option value="">Todos los años</option>
                                @foreach ($l['anios'] as $a)<option value="{{ $a }}" @selected($l['anio'] === (string) $a)>{{ $a }}</option>@endforeach
                                @if ($l['hay_sin_anio'])<option value="sin" @selected($l['anio'] === 'sin')>Sin año</option>@endif
                            </select>
                        </div>
                    </div>
                    <div class="campo">
                        <label class="solo-lector" for="orden">Orden</label>
                        <div class="caja">
                            <select id="orden" name="orden">
                                <option value="recientes" @selected($l['orden'] === 'recientes')>Más recientes</option>
                                <option value="antiguos" @selected($l['orden'] === 'antiguos')>Más antiguos</option>
                            </select>
                        </div>
                    </div>
                    @if ($l['filtrado'])<a class="enlace limpiar" href="{{ route('portal.panel') }}">Limpiar filtros</a>@endif
                </div>
            </form>

            <p class="suave rango" data-rango>
                @if ($l['total'] > 0)
                    Mostrando {{ $l['desde'] }}–{{ $l['hasta'] }} de {{ $l['total'] }}@if ($l['filtrado']) resultados @endif
                @endif
            </p>
        @endif

        <div class="lista" data-lista>
            @forelse ($tarjetas as $t)
                @php $enc = empty($t['moderno']) ? ($encuestas[$t['id']] ?? null) : null; @endphp
                <article class="cert" data-certificado="{{ $t['tipo'] }}"@if (! empty($t['moderno'])) data-moderno @endif>
                    <div class="cert-info">
                        <h2>{{ $t['evento'] }}</h2>
                        <div class="cert-meta">
                            @if ($t['anio'] !== null)<span class="suave">Año {{ $t['anio'] }}</span>@endif
                            <span class="insignia {{ $clase($t['tipo']) }}">{{ $etiqueta($t['tipo']) }}</span>
                            @if ($enc === 'respondida')<span class="insignia neutra" data-encuesta="respondida">Encuesta respondida</span>@endif
                            @if ($t['codigo'] !== null)<span class="suave">Código del certificado: {{ $t['codigo'] }}</span>@endif
                            @if (! empty($t['estado_emision']))<span class="suave">Estado: {{ $t['estado_emision'] }}</span>@endif
                        </div>
                        <p class="suave cert-msg {{ $t['tipo'] === 'disponible' ? 'solo-lector' : '' }}">{{ $t['mensaje'] }}</p>
                    </div>
                    <div class="cert-acciones">
                        @if ($t['descargable'])
                            <a class="boton chico" data-descarga href="{{ ! empty($t['moderno']) ? route('portal.descargar-moderno', $t['id']) : route('portal.descargar', $t['id']) }}">{{ $t['tipo'] === 'actualizado' ? 'Descargar certificado' : 'Descargar PDF' }}</a>
                            @if ($t['codigo'] !== null)
                                <a class="enlace" href="{{ route('verificacion.publica', $t['codigo']) }}" target="_blank" rel="noopener noreferrer">Verificar</a>
                            @endif
                            @if ($enc === 'pendiente')
                                <a class="enlace" data-encuesta="evaluar" href="{{ route('portal.encuesta', $t['id']) }}">Evaluar evento</a>
                            @endif
                        @elseif ($t['enlace_moderno'])
                            <a class="boton sec chico" href="{{ $t['enlace_moderno'] }}" rel="noopener">Ver la versión más reciente</a>
                        @elseif ($contacto && ! in_array($t['tipo'], ['revocado', 'reemplazado'], true))
                            <a class="boton sec chico" href="{{ $contacto }}" target="_blank" rel="noopener noreferrer">Contactar soporte</a>
                        @endif
                    </div>
                    @if ($enc === 'pendiente')
                        <aside class="invita" data-invitacion hidden>
                            <div class="invita-texto">
                                <strong>Evaluación del evento</strong>
                                <p class="suave">La opinión recibida contribuye a mejorar continuamente nuestros eventos y servicios.</p>
                            </div>
                            <div class="invita-acciones">
                                <a class="boton chico" href="{{ route('portal.encuesta', $t['id']) }}">Responder encuesta</a>
                                <form class="en-linea" method="POST" action="{{ route('portal.encuesta.despues', $t['id']) }}">@csrf<button class="enlace" type="submit">Más tarde</button></form>
                            </div>
                        </aside>
                    @endif
                </article>
            @empty
                <div class="tarjeta centrado vacio">
                    @if ($l !== null && $l['total_sin_filtros'] > 0)
                        <h2>No se encontraron resultados para la búsqueda</h2>
                        <p class="suave">Probar con otras palabras o quitar los filtros.</p>
                        <a class="boton sec" href="{{ route('portal.panel') }}">Limpiar filtros</a>
                    @else
                        <p>No se encontraron certificados disponibles.</p>
                    @endif
                </div>
            @endforelse
        </div>

        @if ($l !== null && $l['paginas'] > 1)
            @php
                $ini = max(1, $l['pagina'] - 2);
                $fin = min($l['paginas'], $l['pagina'] + 2);
            @endphp
            <nav class="paginador" aria-label="Paginación" data-paginador>
                @if ($l['pagina'] > 1)<a href="{{ route('portal.panel', $consulta(['page' => $l['pagina'] - 1])) }}" rel="prev" aria-label="Página anterior">&lsaquo;<span class="etq"> Anterior</span></a>@endif
                @if ($ini > 1)<a href="{{ route('portal.panel', $consulta(['page' => 1])) }}">1</a>@if ($ini > 2)<span class="puntos">…</span>@endif @endif
                @for ($p = $ini; $p <= $fin; $p++)
                    @if ($p === $l['pagina'])<span class="actual" aria-current="page">{{ $p }}</span>
                    @else<a href="{{ route('portal.panel', $consulta(['page' => $p])) }}">{{ $p }}</a>@endif
                @endfor
                @if ($fin < $l['paginas'])@if ($fin < $l['paginas'] - 1)<span class="puntos">…</span>@endif<a href="{{ route('portal.panel', $consulta(['page' => $l['paginas']])) }}">{{ $l['paginas'] }}</a>@endif
                @if ($l['pagina'] < $l['paginas'])<a href="{{ route('portal.panel', $consulta(['page' => $l['pagina'] + 1])) }}" rel="next" aria-label="Página siguiente"><span class="etq">Siguiente </span>&rsaquo;</a>@endif
            </nav>
        @endif

    </section>
@endsection
@push('scripts')
    <script nonce="{{ $nonce }}">
        // Tras iniciar una descarga se muestra, sin bloquear nada, la invitación opcional del certificado (si existe).
        document.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('a[data-descarga]') : null;
            if (!a) { return; }
            var fila = a.closest('.cert');
            var invita = fila ? fila.querySelector('[data-invitacion]') : null;
            if (invita) { setTimeout(function () { invita.hidden = false; }, 900); }
        });
    </script>
@endpush
