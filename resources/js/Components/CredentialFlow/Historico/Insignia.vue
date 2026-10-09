<script setup>
// Insignia de estado del histórico. Los tonos salen del sistema visual existente (emerald/amber/rose/sky/slate).
defineProps({
  etiqueta: { type: String, required: true },
  tono: { type: String, default: "slate" },
});

const TONOS = {
  emerald: "bg-emerald-50 text-emerald-700",
  amber: "bg-amber-50 text-amber-700",
  rose: "bg-rose-50 text-rose-600",
  sky: "bg-sky-50 text-sky-700",
  slate: "bg-slate-100 text-slate-500",
};
</script>

<template>
  <span :class="['inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-black whitespace-nowrap', TONOS[tono] ?? TONOS.slate]">{{ etiqueta }}</span>
</template>
