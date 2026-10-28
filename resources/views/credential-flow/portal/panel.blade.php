@extends('credential-flow.portal.layout')
@section('titulo', 'Certificados disponibles')
@section('acciones')
    <form class="en-linea" method="POST" action="{{ route('portal.salir') }}" data-cargando>@csrf<button class="boton sec chico" type="submit">Cerrar sesión</button></form>
@endsection
@php
    $l = $listado ?? null;
    $consulta = fn (array $extra = []) => array_filter(
        array_merge(['q' => $l['q'] ?? '', 'anio' => $l['anio'] ?? null, 'orden' => ($l['orden'] ?? 'recientes') === 'recientes' ? null : $l['orden'],
            'publicado' => $l['publicado'] ?? null, 'desde' => $l['dia_desde'] ?? null, 'hasta' => $l['dia_hasta'] ?? null], $extra),
        fn ($v) => $v !== null && $v !== '' && $v !== 1
    );
    // Fechas en palabras («23 de octubre de 2026»), sin depender del idioma del servidor.
    $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $fecha = fn (string $f) => (int) substr($f, 8, 2).' de '.$meses[(int) substr($f, 5, 2)].' de '.substr($f, 0, 4);
    $mesDe = fn (string $periodo) => ucfirst($meses[(int) substr($periodo, 5, 2)]).' de '.substr($periodo, 0, 4);
    // La fecha del evento tal como se imprimió; si ya trae su redacción («Realizado los días…») no se le antepone otra.
    $realizado = fn (?string $r) => $r === null ? null : (preg_match('/^\s*realizad/iu', $r) === 1 ? mb_strtoupper(mb_substr(trim($r), 0, 1)).mb_substr(trim($r), 1) : 'Fecha del evento: '.$r);
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
        .filtros .campo:last-child { grid-column: 1 / -1; }
        .filtros .caja, .dias .caja { padding: 0 .85rem; border-radius: .8rem; background: #fff; }
        .filtros .caja select, .dias .caja input { width: 100%; padding: .55rem 0; font-size: .9rem; }
        .etq-filtro { display: block; margin: 0 0 .2rem .2rem; font-size: .72rem; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: var(--tenue); }
        .dias { margin-top: .6rem; }
        .dias summary { cursor: pointer; width: fit-content; font-size: .85rem; font-weight: 700; color: var(--vino); }
        .dias-campos { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; align-items: end; margin-top: .5rem; }
        .dias-campos .boton { grid-column: 1 / -1; margin-top: 0; }
        .limpiar { margin: .5rem 0 0; text-align: right; font-size: .85rem; }
        .nota { margin: 0 .25rem .6rem; font-size: .82rem; }
        .separador { margin: 0; padding: .55rem 1.1rem; background: #f8fafc; border-bottom: 1px solid #eef2f6; font-size: .74rem; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: var(--tenue); }
        .cert-fechas { display: flex; flex-wrap: wrap; gap: .15rem .75rem; margin: .3rem 0 0; font-size: .82rem; }
        .cert-fechas .publicado { font-weight: 700; color: #334155; }
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
            .filtros { grid-template-columns: repeat(3, minmax(0, 1fr)); align-items: end; }
            .filtros .campo:last-child { grid-column: auto; }
            .dias-campos { grid-template-columns: 12rem 12rem auto; }
            .dias-campos .boton { grid-column: auto; }
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
                    <label class="solo-lector" for="q">Buscar por nombre de la capacitación</label>
                    <input id="q" name="q" type="search" maxlength="80" value="{{ $l['q'] }}" placeholder="Buscar capacitación">
                    <button class="boton chico" type="submit">Buscar</button>
                </div>
                <div class="filtros">
                    <div class="campo">
                        <label class="etq-filtro" for="orden">Ordenar</label>
                        <div class="caja">
                            <select id="orden" name="orden">
                                <option value="recientes" @selected($l['orden'] === 'recientes')>Más recientes</option>
                                <option value="antiguos" @selected($l['orden'] === 'antiguos')>Más antiguos</option>
                                <option value="nombre" @selected($l['orden'] === 'nombre')>Nombre (A–Z)</option>
                            </select>
                        </div>
                    </div>
                    <div class="campo">
                        <label class="etq-filtro" for="publicado">Publicado en</label>
                        <div class="caja">
                            <select id="publicado" name="publicado" @disabled($l['periodos'] === [])>
                                <option value="">Cualquier fecha</option>
                                @foreach ($l['periodos'] as $anioPub => $mesesPub)
                                    <optgroup label="{{ $anioPub }}">
                                        <option value="{{ $anioPub }}" @selected($l['publicado'] === (string) $anioPub)>Todo {{ $anioPub }}</option>
                                        @foreach ($mesesPub as $periodo)<option value="{{ $periodo }}" @selected($l['publicado'] === $periodo)>{{ $mesDe($periodo) }}</option>@endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="campo">
                        <label class="etq-filtro" for="anio">Año del evento</label>
                        <div class="caja">
                            <select id="anio" name="anio">
                                <option value="">Todos los años</option>
                                @foreach ($l['anios'] as $a)<option value="{{ $a }}" @selected($l['anio'] === (string) $a)>{{ $a }}</option>@endforeach
                                @if ($l['hay_sin_anio'])<option value="sin" @selected($l['anio'] === 'sin')>Sin año</option>@endif
                            </select>
                        </div>
                    </div>
                </div>
                <details class="dias" @if ($l['dia_desde'] !== null || $l['dia_hasta'] !== null) open @endif data-dias>
                    <summary>Buscar por días de publicación</summary>
                    <div class="dias-campos">
                        <div class="campo"><label class="etq-filtro" for="desde">Desde</label><div class="caja"><input id="desde" name="desde" type="date" value="{{ $l['dia_desde'] }}"></div></div>
                        <div class="campo"><label class="etq-filtro" for="hasta">Hasta</label><div class="caja"><input id="hasta" name="hasta" type="date" value="{{ $l['dia_hasta'] }}"></div></div>
                        <button class="boton sec chico" type="submit">Aplicar</button>
                    </div>
                </details>
                @if ($l['filtrado'])<p class="limpiar"><a class="enlace" href="{{ route('portal.panel') }}">Limpiar filtros</a></p>@endif
            </form>

            @if ($l['sin_fecha_ocultos'] > 0)
                <p class="suave nota" data-nota-historicos>{{ $l['sin_fecha_ocultos'] === 1 ? 'Un certificado histórico no tiene' : $l['sin_fecha_ocultos'].' certificados históricos no tienen' }} fecha de publicación registrada y no {{ $l['sin_fecha_ocultos'] === 1 ? 'aparece' : 'aparecen' }} con este filtro de fechas.</p>
            @endif

            <p class="suave rango" data-rango>
                @if ($l['total'] > 0)
                    Mostrando {{ $l['desde'] }}–{{ $l['hasta'] }} de {{ $l['total'] }}@if ($l['filtrado']) resultados @endif
                @endif
            </p>
        @endif

        <div class="lista" data-lista>
            @php $grupoAnterior = null; @endphp
            @forelse ($tarjetas as $t)
                @php
                    $enc = empty($t['moderno']) ? ($encuestas[$t['id']] ?? null) : null;
                    $sinFecha = $t['publicado_at'] === null;
                    $real = $realizado($t['realizado'] ?? null);
                @endphp
                {{-- Los históricos sin fecha registrada van SIEMPRE en su propio bloque, al final y claramente separado (sea cual sea el orden elegido). --}}
                @if ($sinFecha && $grupoAnterior !== true)
                    <p class="separador" data-separador="historicos">Certificados históricos · sin fecha de publicación registrada</p>
                @endif
                @php $grupoAnterior = $sinFecha; @endphp
                <article class="cert" data-certificado="{{ $t['tipo'] }}"@if (! empty($t['moderno'])) data-moderno @endif @if (! $sinFecha) data-publicado="{{ substr($t['publicado_at'], 0, 10) }}" @endif>
                    <div class="cert-info">
                        <h2>{{ $t['evento'] }}</h2>
                        <p class="cert-fechas">
                            @if (! $sinFecha)<span class="publicado" data-fecha-publicacion>{{ $t['tipo'] === 'actualizado' ? 'Actualizado' : 'Publicado' }} el {{ $fecha($t['publicado_at']) }}</span>
                            @else<span class="suave" data-sin-fecha>Certificado histórico</span>@endif
                            @if ($real !== null)<span class="suave" data-fecha-evento>{{ $real }}</span>
                            @elseif ($t['anio'] !== null)<span class="suave" data-fecha-evento>Evento de {{ $t['anio'] }}</span>@endif
                        </p>
                        <div class="cert-meta">
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
                    @if ($l !== null && ($l['filtro_invalido'] ?? null) !== null)
                        <h2 data-filtro-invalido>{{ $l['filtro_invalido'] }}</h2>
                        <p class="suave">Revisar el filtro o quitarlo para ver los certificados.</p>
                        <a class="boton sec" href="{{ route('portal.panel') }}">Limpiar filtros</a>
                    @elseif ($l !== null && $l['total_sin_filtros'] > 0)
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
