<script setup>
import { computed } from "vue";
import { Link } from "@inertiajs/vue3";

// Ruta de navegación + pestañas de Encuestas dentro de Credential Flow (todo solo lectura).
// `actual`: resumen | eventos | informes | comentarios. `filtros`: los filtros activos viajan entre pestañas. `migas`: [{ texto, href? }] tras «Encuestas».
const props = defineProps({
  actual: { type: String, default: "resumen" },
  filtros: { type: Object, default: () => ({}) },
  migas: { type: Array, default: () => [] },
  puedeComentarios: { type: Boolean, default: false },
});

// Los filtros del EVENTO (nombre, año, fechas del evento, evento exacto) viajan entre pestañas; el evento exacto solo en el detalle.
const activos = computed(() =>
  Object.fromEntries(Object.entries(props.filtros).filter(([k, v]) => k !== "evento" && v !== "" && v !== null && v !== undefined))
);

const pestanas = computed(() => [
  { id: "resumen", texto: "Resumen", href: route("credential-flow.encuestas.resumen", activos.value) },
  { id: "eventos", texto: "Resultados por evento", href: route("credential-flow.encuestas.eventos", activos.value) },
  { id: "informes", texto: "Informes y exportaciones", href: route("credential-flow.encuestas.informes", activos.value) },
  ...(props.puedeComentarios ? [{ id: "comentarios", texto: "Comentarios (restringido)", href: route("credential-flow.encuestas.comentarios", activos.value) }] : []),
]);
</script>

<template>
  <div>
    <nav class="mb-3 flex flex-wrap items-center gap-2 text-[12px] font-bold text-slate-400" aria-label="Ruta de navegación">
      <Link :href="route('credential-flow.index')" class="hover:text-primary-vinotinto transition-colors">Credential Flow</Link>
      <span>/</span>
      <Link v-if="migas.length" :href="route('credential-flow.encuestas.resumen')" class="hover:text-primary-vinotinto transition-colors">Encuestas</Link>
      <span v-else class="text-slate-600">Encuestas</span>
      <template v-for="(m, i) in migas" :key="i">
        <span>/</span>
        <Link v-if="m.href" :href="m.href" class="hover:text-primary-vinotinto transition-colors max-w-[16rem] truncate">{{ m.texto }}</Link>
        <span v-else class="text-slate-600 max-w-[20rem] truncate">{{ m.texto }}</span>
      </template>
    </nav>

    <div class="flex flex-wrap gap-2 mb-6" role="tablist" aria-label="Secciones de encuestas" data-nav-encuestas>
      <Link
        v-for="p in pestanas"
        :key="p.id"
        :href="p.href"
        role="tab"
        :aria-selected="actual === p.id"
        :class="['px-4 py-2 rounded-full text-[13px] font-bold transition-all', actual === p.id ? 'bg-primary-vinotinto text-white shadow-sm' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50']"
      >{{ p.texto }}</Link>
    </div>
  </div>
</template>
