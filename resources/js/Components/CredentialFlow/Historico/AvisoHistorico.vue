<script setup>
import { TriangleAlert, Info } from "lucide-vue-next";

// Aviso humano. `texto` es lo que entiende cualquier persona; `tecnico` (motivo) y `evidencia` (candidata) son solo para el administrador.
defineProps({
  aviso: { type: Object, required: true },
});

const TONOS = {
  amber: "bg-amber-50 text-amber-800 border-amber-100",
  rose: "bg-rose-50 text-rose-700 border-rose-100",
  sky: "bg-sky-50 text-sky-800 border-sky-100",
  slate: "bg-slate-50 text-slate-600 border-slate-100",
};
</script>

<template>
  <div :class="['flex items-start gap-3 px-4 py-3 rounded-2xl border text-[13px]', TONOS[aviso.tono] ?? TONOS.slate]" role="note" :data-aviso="aviso.tipo">
    <component :is="aviso.tono === 'amber' || aviso.tono === 'rose' ? TriangleAlert : Info" class="w-4 h-4 mt-0.5 shrink-0" />
    <div class="min-w-0 space-y-1">
      <p class="font-extrabold">{{ aviso.titulo }}</p>
      <p class="font-semibold leading-snug">{{ aviso.texto }}</p>
      <p v-if="aviso.tecnico" class="text-[12px] font-medium opacity-80" data-tecnico>{{ aviso.tecnico }}</p>
      <dl v-if="aviso.evidencia" class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-1 text-[12px]" data-evidencia>
        <div><dt class="font-black opacity-70">Huella</dt><dd class="font-mono">{{ aviso.evidencia.sha }}</dd></div>
        <div><dt class="font-black opacity-70">Tipo</dt><dd>{{ aviso.evidencia.mime ?? "—" }}</dd></div>
        <div><dt class="font-black opacity-70">Tamaño</dt><dd>{{ aviso.evidencia.dimensiones ?? "—" }} · {{ aviso.evidencia.bytes ?? "—" }}</dd></div>
        <div><dt class="font-black opacity-70">Parecido del nombre</dt><dd>{{ aviso.evidencia.similitud ?? "—" }}</dd></div>
        <p class="col-span-full font-semibold opacity-80">{{ aviso.evidencia.nota }}</p>
      </dl>
    </div>
  </div>
</template>
