<script setup>
import { router } from "@inertiajs/vue3";
import { ChevronLeft, ChevronRight } from "lucide-vue-next";

// Paginador de Laravel (la paginación es SIEMPRE del servidor): { current_page, last_page, total, prev_page_url, next_page_url }.
defineProps({
  paginador: { type: Object, required: true },
  unidad: { type: String, default: "resultados" },
});

const ir = (url) => url && router.get(url, {}, { preserveScroll: false });
</script>

<template>
  <div v-if="paginador.last_page > 1" class="flex items-center justify-between gap-3 px-2" data-paginacion>
    <button type="button" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-40" :disabled="!paginador.prev_page_url" @click="ir(paginador.prev_page_url)">
      <ChevronLeft class="w-4 h-4" /> Anterior
    </button>
    <span class="text-[13px] font-bold text-slate-500 text-center">Página {{ paginador.current_page }} de {{ paginador.last_page }} · {{ paginador.total.toLocaleString("es-CO") }} {{ unidad }}</span>
    <button type="button" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-40" :disabled="!paginador.next_page_url" @click="ir(paginador.next_page_url)">
      Siguiente <ChevronRight class="w-4 h-4" />
    </button>
  </div>
</template>
