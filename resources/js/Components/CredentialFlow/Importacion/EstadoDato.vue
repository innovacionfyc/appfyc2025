<script setup>
// Etiqueta de estado de un dato: DETECTADO / REVISAR / FALTANTE (más «Revisado» cuando el administrador ya lo confirmó o lo escribió).
import { computed } from "vue";
import { CircleCheck, TriangleAlert, CircleAlert, PencilLine, Minus } from "lucide-vue-next";

const props = defineProps({ estado: { type: String, required: true } });

const MAPA = {
  detectado: { texto: "Detectado", clase: "bg-emerald-50 text-emerald-700 ring-emerald-200", icono: CircleCheck },
  confirmado: { texto: "Revisado", clase: "bg-emerald-50 text-emerald-700 ring-emerald-200", icono: CircleCheck },
  escrito: { texto: "Escrito por ti", clase: "bg-sky-50 text-sky-700 ring-sky-200", icono: PencilLine },
  revisar: { texto: "Revisar", clase: "bg-amber-50 text-amber-800 ring-amber-300", icono: TriangleAlert },
  faltante: { texto: "Faltante", clase: "bg-rose-50 text-rose-700 ring-rose-200", icono: CircleAlert },
  sin_dato: { texto: "Sin dato", clase: "bg-slate-100 text-slate-500 ring-slate-200", icono: Minus },
};
const m = computed(() => MAPA[props.estado] ?? MAPA.sin_dato);
</script>

<template>
  <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-black uppercase tracking-wide ring-1" :class="m.clase" :data-estado="estado">
    <component :is="m.icono" class="w-3.5 h-3.5" aria-hidden="true" />
    {{ m.texto }}
  </span>
</template>
