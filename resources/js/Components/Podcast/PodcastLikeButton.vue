<script setup>
import { computed, ref } from "vue";
import axios from "axios";

const props = defineProps({
  // Estado inicial calculado en el servidor para este visitante
  total: { type: Number, default: 0 },
  activo: { type: Boolean, default: false },
  url: { type: String, required: true },
});

const total = ref(props.total);
const activo = ref(props.activo);
const procesando = ref(false);
const aviso = ref("");

const etiqueta = computed(() =>
  activo.value ? "Quitar mi me gusta" : "Me gusta este episodio"
);

const textoConteo = computed(() =>
  total.value === 1 ? "1 me gusta" : `${total.value} me gusta`
);

// Envía la intención (dar/quitar), no un toggle: repetir la misma solicitud no cambia el resultado.
const alternar = async () => {
  if (procesando.value) return;

  procesando.value = true;
  aviso.value = "";

  try {
    const { data } = await axios.post(props.url, { accion: activo.value ? "quitar" : "dar" });
    total.value = data.total;
    activo.value = data.activo;
  } catch (error) {
    const status = error?.response?.status;
    aviso.value =
      status === 429
        ? "Demasiadas solicitudes seguidas. Intenta de nuevo en un momento."
        : "No pudimos registrar tu me gusta. Intenta de nuevo.";
  } finally {
    procesando.value = false;
  }
};
</script>

<template>
  <div class="inline-flex flex-col items-start gap-2">
    <button
      type="button"
      :aria-pressed="activo"
      :aria-busy="procesando"
      :aria-label="`${etiqueta} (${textoConteo})`"
      :disabled="procesando"
      class="group inline-flex items-center gap-2.5 px-5 py-2.5 rounded-full text-sm font-bold border-2 transition-all duration-300 select-none active:scale-95 focus:outline-none focus-visible:ring-4 focus-visible:ring-podcast-oscuro/20 disabled:cursor-wait"
      :class="
        activo
          ? 'bg-podcast-oscuro border-podcast-oscuro text-white shadow-md shadow-podcast-oscuro/20'
          : 'bg-white border-slate-200 text-slate-700 hover:border-podcast-oscuro/40 hover:text-podcast-oscuro'
      "
      @click="alternar"
    >
      <span
        class="material-symbols-rounded text-[1.35em] leading-none transition-transform duration-300 group-hover:scale-110"
        :class="activo ? 'text-podcast-acento' : ''"
        :style="activo ? 'font-variation-settings: \'FILL\' 1' : ''"
        aria-hidden="true"
        >favorite</span
      >
      <span aria-hidden="true">{{ activo ? "Te gusta" : "Me gusta" }}</span>
      <span
        aria-hidden="true"
        class="min-w-[1.5rem] px-2 py-0.5 rounded-full text-xs font-black tabular-nums"
        :class="activo ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-600'"
        >{{ total }}</span
      >
    </button>

    <p
      v-if="aviso"
      role="status"
      aria-live="polite"
      class="text-xs font-semibold text-rose-600"
    >
      {{ aviso }}
    </p>
    <span v-else class="sr-only" role="status" aria-live="polite">{{ textoConteo }}</span>
  </div>
</template>
