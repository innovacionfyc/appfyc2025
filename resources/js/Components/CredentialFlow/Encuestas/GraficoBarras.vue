<script setup>
import { computed } from "vue";

// Barras horizontales sin dependencias (HTML/CSS accesible). `items`: [{ etiqueta, valor, nota? }]. `base`: denominador para el % (opcional).
const props = defineProps({
  items: { type: Array, required: true },
  titulo: { type: String, default: "" },
  color: { type: String, default: "bg-primary-vinotinto/75" },
  base: { type: Number, default: null },
});
const n = (v) => (v ?? 0).toLocaleString("es-CO");
const max = computed(() => Math.max(1, ...props.items.map((i) => i.valor)));
const pct = (v) => (props.base ? `${((v * 100) / props.base).toFixed(1).replace(".", ",")} %` : null);
</script>

<template>
  <ul class="space-y-2" role="list" :aria-label="titulo || undefined" data-grafico-barras>
    <li v-for="(it, i) in items" :key="i" class="flex flex-wrap sm:flex-nowrap items-center gap-x-3 gap-y-1 text-sm" :data-barra="it.etiqueta">
      <span class="w-full sm:w-56 sm:shrink-0 font-semibold text-slate-600 truncate" :title="it.etiqueta">{{ it.etiqueta }}</span>
      <span class="flex-1 h-2.5 rounded-full bg-slate-100 overflow-hidden" aria-hidden="true">
        <span :class="['block h-full rounded-full transition-all duration-500', color]" :style="{ width: (it.valor / max) * 100 + '%' }"></span>
      </span>
      <span class="w-16 text-right font-black text-slate-700" data-valor>{{ n(it.valor) }}</span>
      <span v-if="base" class="w-16 text-right text-[12px] font-bold text-slate-400" data-porcentaje>{{ pct(it.valor) }}</span>
    </li>
  </ul>
</template>
