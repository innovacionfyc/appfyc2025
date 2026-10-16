<script setup>
import { computed } from "vue";

// Línea de tendencia (SVG sin dependencias). `puntos`: [{ etiqueta, valor }] ordenados en el tiempo.
const props = defineProps({
  puntos: { type: Array, required: true },
  titulo: { type: String, default: "Evolución" },
});
const W = 640;
const H = 160;
const P = { l: 36, r: 12, t: 12, b: 26 };
const max = computed(() => Math.max(1, ...props.puntos.map((p) => p.valor)));
const x = (i) => P.l + (props.puntos.length <= 1 ? (W - P.l - P.r) / 2 : (i * (W - P.l - P.r)) / (props.puntos.length - 1));
const y = (v) => P.t + (H - P.t - P.b) * (1 - v / max.value);
const linea = computed(() => props.puntos.map((p, i) => `${i === 0 ? "M" : "L"}${x(i).toFixed(1)},${y(p.valor).toFixed(1)}`).join(" "));
const area = computed(() => (props.puntos.length ? `${linea.value} L${x(props.puntos.length - 1).toFixed(1)},${H - P.b} L${x(0).toFixed(1)},${H - P.b} Z` : ""));
// Etiquetas del eje X: no más de 8 para no saturar.
const salto = computed(() => Math.max(1, Math.ceil(props.puntos.length / 8)));
</script>

<template>
  <figure data-grafico-tendencia>
    <svg :viewBox="`0 0 ${W} ${H}`" class="w-full h-auto" role="img" :aria-label="titulo">
      <line :x1="P.l" :x2="W - P.r" :y1="H - P.b" :y2="H - P.b" stroke="#e2e8f0" />
      <line :x1="P.l" :x2="W - P.r" :y1="y(max)" :y2="y(max)" stroke="#f1f5f9" />
      <text :x="P.l - 6" :y="y(max) + 4" text-anchor="end" font-size="11" fill="#94a3b8" font-weight="700">{{ max.toLocaleString("es-CO") }}</text>
      <text :x="P.l - 6" :y="H - P.b + 4" text-anchor="end" font-size="11" fill="#94a3b8" font-weight="700">0</text>
      <path v-if="area" :d="area" fill="#942934" fill-opacity="0.08" />
      <path :d="linea" fill="none" stroke="#942934" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
      <g v-for="(p, i) in puntos" :key="i">
        <circle :cx="x(i)" :cy="y(p.valor)" r="3.5" fill="#942934"><title>{{ p.etiqueta }}: {{ p.valor.toLocaleString("es-CO") }}</title></circle>
        <text v-if="i % salto === 0" :x="x(i)" :y="H - 8" text-anchor="middle" font-size="10" fill="#94a3b8" font-weight="700">{{ p.etiqueta }}</text>
      </g>
    </svg>
  </figure>
</template>
