<script setup>
import { Link } from "@inertiajs/vue3";

// Ruta de navegación + pestañas del Histórico (todo solo lectura).
// `actual`: eventos | plantillas | sin-evento | buscar | encuestas | casos. `migas`: [{ texto, href? }] adicionales tras «Histórico».
defineProps({
  actual: { type: String, default: "eventos" },
  migas: { type: Array, default: () => [] },
});

const pestanas = [
  { id: "eventos", texto: "Eventos", href: () => route("credential-flow.historico.index") },
  { id: "plantillas", texto: "Plantillas históricas", href: () => route("credential-flow.historico.plantillas.index") },
  { id: "sin-evento", texto: "Imágenes sin evento", href: () => route("credential-flow.historico.plantillas.index", { estado: "sin_evento" }) },
  { id: "buscar", texto: "Buscar", href: () => route("credential-flow.historico.buscar") },
  { id: "encuestas", texto: "Encuestas", href: () => route("credential-flow.historico.encuestas") },
  { id: "casos", texto: "Casos por revisar", href: () => route("credential-flow.historico.casos.index") },
];
</script>

<template>
  <div>
    <nav class="mb-3 flex flex-wrap items-center gap-2 text-[12px] font-bold text-slate-400" aria-label="Ruta de navegación">
      <Link :href="route('credential-flow.index')" class="hover:text-primary-vinotinto transition-colors">Credential Flow</Link>
      <span>/</span>
      <Link v-if="migas.length" :href="route('credential-flow.historico.index')" class="hover:text-primary-vinotinto transition-colors">Histórico</Link>
      <span v-else class="text-slate-600">Histórico</span>
      <template v-for="(m, i) in migas" :key="i">
        <span>/</span>
        <Link v-if="m.href" :href="m.href" class="hover:text-primary-vinotinto transition-colors max-w-[16rem] truncate">{{ m.texto }}</Link>
        <span v-else class="text-slate-600 max-w-[16rem] truncate">{{ m.texto }}</span>
      </template>
    </nav>

    <div class="flex flex-wrap gap-2 mb-6" role="tablist" aria-label="Secciones del histórico" data-nav-historico>
      <Link
        v-for="p in pestanas"
        :key="p.id"
        :href="p.href()"
        role="tab"
        :aria-selected="actual === p.id"
        :class="['px-4 py-2 rounded-full text-[13px] font-bold transition-all', actual === p.id ? 'bg-primary-vinotinto text-white shadow-sm' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50']"
      >{{ p.texto }}</Link>
    </div>
  </div>
</template>
