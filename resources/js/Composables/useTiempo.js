// Composables/useTiempo.js
import { ref, onMounted, onUnmounted, watch } from 'vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import 'dayjs/locale/es';

dayjs.extend(relativeTime);
dayjs.locale('es');

export function useTiempo(userSource) {
    const tiempoActivo = ref(null);
    const diasRestantes = ref(null);
    let intervalId = null;

    const calcularTiempoActivo = (fechaCreacion) => {
        if (!fechaCreacion) return 'No disponible';
        // Usamos 'false' para quitar "hace" o "en"
        return dayjs(fechaCreacion).fromNow(false); 
    };

    // ✅ FUNCIÓN CORREGIDA
    const calcularDiasRestantes = (factura) => {
        // Ahora usamos el objeto 'factura' que se pasa como argumento
        if (!factura || !factura.fecha_pago) {
            return 'N/A'; // Devolvemos un valor por defecto más claro
        }
        const fechaPago = dayjs(factura.fecha_pago);
        const diasMembresia = factura.dias_restantes || 0;
        const fechaExpiracion = fechaPago.add(diasMembresia, 'day');
        const dias = fechaExpiracion.diff(dayjs(), 'day');
        
        return dias > 0 ? dias : 0;
    };

    const actualizarValores = () => {
        const user = userSource.value;
        if (user) {
            tiempoActivo.value = calcularTiempoActivo(user.perfil_usuario?.created_at);
            
            // ✅ BÚSQUEDA DE FACTURA MÁS ROBUSTA
            // Busca la factura en las posibles ubicaciones para ser compatible con propietarios y empleados
            const factura = 
                user.establecimiento?.facturas?.[0] || 
                user.establecimiento_asignado?.facturas?.[0] ||
                user.perfil_empleado?.establecimiento?.facturas?.[0];

            diasRestantes.value = calcularDiasRestantes(factura);
        } else {
            tiempoActivo.value = null;
            diasRestantes.value = null;
        }
    };

    // Usamos 'watch' para reaccionar cuando el usuario cambie
    watch(userSource, actualizarValores, { immediate: true });

    onMounted(() => {
        intervalId = setInterval(actualizarValores, 60000); // Actualiza cada minuto
    });

    onUnmounted(() => {
        if (intervalId) {
            clearInterval(intervalId);
        }
    });

    return { tiempoActivo, diasRestantes };
}