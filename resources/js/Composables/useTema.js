import { ref, onMounted, onUnmounted, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useTema() {
    const page = usePage();
    const modoOscuro = ref(false);
    const animando = ref(false);

    // Variable para mantener la referencia al listener del sistema
    let mediaQueryList = null;

    // Esta función se llamará cuando el tema del sistema operativo cambie
    const handleSystemThemeChange = (e) => {
        // IMPORTANTE: Solo cambia el tema si el usuario NO ha elegido uno manualmente
        if (localStorage.getItem('modoOscuro') === null) {
            modoOscuro.value = e.matches;
            document.documentElement.classList.toggle("dark", e.matches);
        }
    };

    const aplicarTemaCompleto = () => {
        if (typeof window === 'undefined') return;

        // La lógica de prioridad no cambia: 1º localStorage, 2º Sistema Operativo
        const preferencia = localStorage.getItem("modoOscuro");
        modoOscuro.value =
            preferencia !== null
                ? preferencia === "true"
                : window.matchMedia("(prefers-color-scheme: dark)").matches;
        document.documentElement.classList.toggle("dark", modoOscuro.value);

        // Lógica de colores personalizados (sin cambios)
        const user = page.props.auth.user;
        const primary = user?.data?.establecimiento_asignado?.aplicacion_web?.estilo?.color_primary || '#f05235';
        const secondary = user?.data?.establecimiento_asignado?.aplicacion_web?.estilo?.color_secondary || '#aaa';

        document.documentElement.style.setProperty('--color-primary', primary);
        document.documentElement.style.setProperty('--color-secondary', secondary);
    };

    onMounted(() => {
        // Aplica el tema en la carga inicial
        aplicarTemaCompleto();

        // ✅ NUEVO: Se crea un listener que "escucha" los cambios del tema del sistema
        mediaQueryList = window.matchMedia("(prefers-color-scheme: dark)");
        mediaQueryList.addEventListener('change', handleSystemThemeChange);
    });

    // ✅ NUEVO: Limpiamos el listener cuando el componente se destruye para evitar fugas de memoria
    onUnmounted(() => {
        if (mediaQueryList) {
            mediaQueryList.removeEventListener('change', handleSystemThemeChange);
        }
    });

    watch(() => page.props.auth.user, (newUser, oldUser) => {
        if (newUser?.id !== oldUser?.id) {
            aplicarTemaCompleto();
        }
    }, {
        deep: true
    });

    const cambiarTema = () => {
        modoOscuro.value = !modoOscuro.value;
        document.documentElement.classList.toggle("dark", modoOscuro.value);
        // Al guardar en localStorage, la preferencia del usuario anula la del sistema
        localStorage.setItem("modoOscuro", modoOscuro.value);
    };

    // La función de animación no necesita cambios
    const animarCambioTema = (event) => {
        if (animando.value || !document.startViewTransition) {
            if (!animando.value) cambiarTema();
            return;
        }

        const x = event.clientX;
        const y = event.clientY;
        const endRadius = Math.hypot(
            Math.max(x, window.innerWidth - x),
            Math.max(y, window.innerHeight - y)
        );

        const transition = document.startViewTransition(() => {
            cambiarTema();
        });

        transition.ready.then(() => {
            const shadowColor = modoOscuro.value ? 'rgba(255, 255, 255, 0.2)' : 'rgba(0, 0, 0, 0.4)';
            document.documentElement.animate(
                [{
                    clipPath: `circle(0px at ${x}px ${y}px)`,
                    filter: `drop-shadow(0 0 5px ${shadowColor})`,
                }, {
                    clipPath: `circle(${endRadius}px at ${x}px ${y}px)`,
                    filter: `drop-shadow(0 0 30px ${shadowColor})`,
                }], {
                duration: 600,
                easing: "ease-in-out",
                pseudoElement: "::view-transition-new(root)",
            }
            );
        });

        animando.value = true;
        setTimeout(() => {
            animando.value = false;
        }, 700);
    };

    return {
        modoOscuro,
        animando,
        animarCambioTema,
    };
}