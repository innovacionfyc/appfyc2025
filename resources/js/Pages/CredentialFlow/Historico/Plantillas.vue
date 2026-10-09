<script setup>
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import NavHistorico from "@/Components/CredentialFlow/Historico/NavHistorico.vue";
import Insignia from "@/Components/CredentialFlow/Historico/Insignia.vue";
import Paginador from "@/Components/CredentialFlow/Historico/Paginador.vue";
import { useFiltrosHistorico } from "@/Composables/CredentialFlow/useFiltrosHistorico";
import { Search, X, ImageOff } from "lucide-vue-next";

const props = defineProps({
  plantillas: { type: Object, required: true }, // paginador de Laravel (SOLO LECTURA)
  filtros: { type: Object, required: true },
  conteos: { type: Object, default: () => ({}) },
});

const { filtros, aplicar, aplicarConEspera, limpiar, hayFiltros } = useFiltrosHistorico(() => route("credential-flow.historico.plantillas.index"), props.filtros);

const n = (v) => (v ?? 0).toLocaleString("es-CO");
const SELECT = "w-full rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-primary-vinotinto/30 focus:border-primary-vinotinto";

const grupos = [
  { clave: "", etiqueta: "Todas", conteo: "total", tono: "slate" },
  { clave: "ok", etiqueta: "Disponibles", conteo: "ok", tono: "emerald" },
  { clave: "faltante", etiqueta: "Imagen faltante", conteo: "faltante", tono: "rose" },
  { clave: "extension_invalida", etiqueta: "Extensión inválida", conteo: "extension_invalida", tono: "amber" },
  { clave: "sin_evento", etiqueta: "Imágenes sin evento", conteo: "sin_evento", tono: "sky" },
];

const elegir = (clave) => {
  filtros.estado = filtros.estado === clave ? "" : clave;
  aplicar();
};
</script>

<template>
  <Head title="Credential Flow · Plantillas históricas" />

  <AuthenticatedLayout>
    <Sidebar>
      <NavHistorico :actual="filtros.estado === 'sin_evento' ? 'sin-evento' : 'plantillas'" :migas="[{ texto: 'Plantillas históricas' }]" />

      <DashboardHeader title="Plantillas históricas" subtitle="Las imágenes de fondo con las que se generaban los certificados anteriores. Solo lectura." />

      <div class="mt-6 flex flex-wrap gap-2" data-resumen-plantillas>
        <button
          v-for="g in grupos"
          :key="g.clave"
          type="button"
          :class="['inline-flex items-center gap-2 px-3 py-1.5 rounded-full border text-[12px] font-bold transition-all', (filtros.estado ?? '') === g.clave ? 'border-primary-vinotinto bg-primary-vinotinto/5 text-primary-vinotinto' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50']"
          @click="elegir(g.clave)"
        >
          <Insignia :etiqueta="n(conteos[g.conteo])" :tono="g.tono" /> {{ g.etiqueta }}
        </button>
      </div>

      <p v-if="filtros.estado === 'sin_evento'" class="mt-4 text-sm font-semibold text-slate-500 max-w-3xl" data-nota-sin-evento>
        Estas imágenes existen en el sistema anterior pero ningún evento las usa. Se conservan por si hacen falta. Las que figuran como «candidata en revisión»
        se parecen por nombre a un evento sin imagen; es solo una pista y no se enlazan automáticamente.
      </p>

      <form class="mt-6 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-5 grid grid-cols-1 sm:grid-cols-3 gap-4" data-filtros @submit.prevent="aplicar">
        <label class="relative block sm:col-span-2">
          <span class="sr-only">Buscar plantilla</span>
          <Search class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
          <input v-model="filtros.q" type="search" maxlength="80" placeholder="Buscar por nombre de imagen o huella…" :class="[SELECT, 'pl-10']" data-filtro="q" @input="aplicarConEspera" />
        </label>
        <div class="flex gap-3">
          <label class="block flex-1">
            <span class="sr-only">Se puede generar</span>
            <select v-model="filtros.renderizable" :class="SELECT" data-filtro="renderizable" @change="aplicar">
              <option value="">Generable o no</option>
              <option value="si">Se puede generar</option>
              <option value="no">No se puede generar</option>
            </select>
          </label>
          <button v-if="hayFiltros()" type="button" class="inline-flex items-center px-3 rounded-2xl bg-slate-100 text-slate-600 hover:bg-slate-200" aria-label="Limpiar filtros" @click="limpiar()"><X class="w-4 h-4" /></button>
        </div>
      </form>

      <div v-if="plantillas.data.length === 0" class="mt-6 text-center py-16 space-y-3 bg-white rounded-[2rem] border border-dashed border-slate-200" data-sin-plantillas>
        <div class="w-16 h-16 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto"><ImageOff class="w-8 h-8 text-slate-400" /></div>
        <p class="text-slate-600 font-bold text-lg">No encontramos plantillas con esos filtros</p>
      </div>

      <div v-else class="mt-6 space-y-4">
        <div class="overflow-x-auto bg-white rounded-[2rem] border border-slate-100 shadow-sm">
          <table class="min-w-full text-sm" data-tabla-plantillas>
            <thead class="text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
              <tr>
                <th class="text-left font-black px-6 py-4">Imagen</th>
                <th class="text-left font-black px-4 py-4">Evento</th>
                <th class="text-left font-black px-4 py-4">Estado</th>
                <th class="text-left font-black px-4 py-4">Tipo</th>
                <th class="text-left font-black px-4 py-4">Tamaño</th>
                <th class="text-right font-black px-4 py-4">Peso</th>
                <th class="text-left font-black px-4 py-4">Huella</th>
                <th class="text-left font-black px-6 py-4">Se puede generar</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="p in plantillas.data" :key="p.id" class="align-top hover:bg-slate-50/60 transition-colors" :data-plantilla="p.id">
                <td class="px-6 py-4 font-bold text-slate-800 max-w-[16rem] break-words" :title="p.nombre">{{ p.nombre }}</td>
                <td class="px-4 py-4 text-slate-600 font-semibold max-w-[16rem]">
                  <template v-if="p.eventos.length">
                    <Link v-for="e in p.eventos" :key="e.id" :href="route('credential-flow.historico.eventos.show', e.id)" class="block hover:text-primary-vinotinto break-words">{{ e.nombre }} <span class="text-slate-400">· {{ e.anio_etiqueta }}</span></Link>
                  </template>
                  <span v-else class="text-slate-400 italic">Sin evento</span>
                  <p v-if="p.candidata_para" class="mt-1 text-[11px] font-semibold text-sky-700" data-candidata>
                    Se parece a «{{ p.candidata_para.evento?.nombre }}» ({{ p.candidata_para.similitud }}). No enlazada.
                  </p>
                </td>
                <td class="px-4 py-4"><Insignia :etiqueta="p.estado_info.etiqueta" :tono="p.estado_info.tono" /></td>
                <td class="px-4 py-4 text-slate-600 font-semibold whitespace-nowrap">{{ p.mime ?? "—" }}</td>
                <td class="px-4 py-4 text-slate-600 font-semibold whitespace-nowrap">{{ p.dimensiones ?? "—" }}</td>
                <td class="px-4 py-4 text-right text-slate-600 font-semibold whitespace-nowrap">{{ p.bytes ?? "—" }}</td>
                <td class="px-4 py-4 text-slate-500 font-mono">{{ p.sha ?? "—" }}</td>
                <td class="px-6 py-4"><Insignia :etiqueta="p.renderizable ? 'Sí' : 'No'" :tono="p.renderizable ? 'emerald' : 'slate'" /></td>
              </tr>
            </tbody>
          </table>
        </div>
        <Paginador :paginador="plantillas" unidad="plantillas" />
      </div>
    </Sidebar>
  </AuthenticatedLayout>
</template>
