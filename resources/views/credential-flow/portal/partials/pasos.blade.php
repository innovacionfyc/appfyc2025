<ol class="pasos" aria-label="Progreso">
    @foreach ([1, 2, 3] as $n)
        <li class="{{ $n < $paso ? 'hecho' : ($n === $paso ? 'activo' : '') }}"></li>
    @endforeach
</ol>
