// resources/js/Composables/useDateTime.js

import { ref, computed, onMounted, onUnmounted } from 'vue';

// Constantes fuera de la función para no recrearlas cada segundo
const nombreDias = ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"];
const monthNamesClock = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];

export function useDateTime() {
  const now = ref(new Date());

  const nombreDia = computed(() => nombreDias[now.value.getDay()]);
  const dia = computed(() => now.value.getDate());
  const mes = computed(() => monthNamesClock[now.value.getMonth()]);
  const anio = computed(() => now.value.getFullYear());

  const hora = computed(() => {
    let horas = now.value.getHours();
    const minutos = now.value.getMinutes().toString().padStart(2, "0");
    const segundos = now.value.getSeconds().toString().padStart(2, "0");
    const periodo = horas >= 12 ? "pm" : "am";

    if (horas > 12) horas -= 12;
    else if (horas === 0) horas = 12;
    
    return `${horas}:${minutos}:${segundos} ${periodo}`;
  });

  const saludo = computed(() => {
    const horaActual = now.value.getHours();
    if (horaActual < 12) return "¡Buenos días!";
    if (horaActual < 18) return "¡Buenas tardes!";
    return "¡Buenas noches!";
  });

  let clockInterval = null;
  onMounted(() => {
    clockInterval = setInterval(() => {
      now.value = new Date();
    }, 1000);
  });

  onUnmounted(() => {
    clearInterval(clockInterval);
  });

  return { nombreDia, dia, mes, anio, hora, saludo };
}