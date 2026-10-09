<script setup>
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import NavHistorico from "@/Components/CredentialFlow/Historico/NavHistorico.vue";
import Insignia from "@/Components/CredentialFlow/Historico/Insignia.vue";
import Paginador from "@/Components/CredentialFlow/Historico/Paginador.vue";
import { useFiltrosHistorico } from "@/Composables/CredentialFlow/useFiltrosHistorico";
import { ClipboardCheck, X, Info } from "lucide-vue-next";

// Casos por revisar (conciliación histórica): SOLO LECTURA. El listado nunca muestra nombres, documentos, correos ni códigos.
const props = defineProps({
  casos: { type: Object, required: true }, // paginador de Laravel
  resumen: { type: Object, required: true },
  filtros: { type: Object, required: true },
  opciones: { type: Object, required: true },
});

const { filtros, aplicar, limpiar, hayFiltros } = useFiltrosHistorico(() => route("credential-flow.historico.casos.index"), props.filtros);

const n = (v) => (v ?? 0).toLocaleString("es-CO");
const fecha = (v) => {
  if (!v) return "—";
  const d = new Date(String(v).replace(" ", "T"));
  return isNaN(d.getTime()) ? v : new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "short", year: "numeric" }).format(d);
};
const CAMPO = "w-full rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-primary-vinotinto/30 focus:border-primary-vinotinto";
const elegirTipo = (tipo) => {
  filtros.tipo = filtros.tipo === tipo ? "" : tipo;
  aplicar();
};
</script>

<template>
  <Head title="Credential Flow · Casos por revisar" />

  <AuthenticatedLayout>
    <Sidebar>
      <NavHistorico actual="casos" :migas="[{ texto: 'Casos por revisar' }]" />

      <DashboardHeader title="Casos por revisar" subtitle="Situaciones del histórico que necesitan una decisión de una persona. Por ahora solo se pueden consultar." />

      <p class="mt-4 flex items-start gap-2 max-w-3xl text-sm font-semibold text-slate-500" data-nota-solo-lectura>
        <Info class="w-4 h-4 mt-0.5 shrink-0" />
        Aquí no se corrige nada: el histórico se conserva tal como estaba. Abre un caso para ver la evidencia (con los datos personales parcialmente ocultos).
      </p>

      <div class="mt-6 flex flex-wrap gap-2" data-resumen-casos>
        <button
          v-for="t in opciones.tipos"
          :key="t.valor"
          type="button"
          :title="t.ayuda"
          :class="['inline-flex items-center gap-2 px-3 py-1.5 rounded-full border text-[12px] font-bold transition-all', filtros.tipo === t.valor ? 'border-primary-vinotinto bg-primary-vinotinto/5 text-primary-vinotinto' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50']"
          :data-tipo="t.valor"
          @click="elegirTipo(t.valor)"
        >
          <Insignia :etiqueta="n(resumen.por_tipo[t.valor])" tono="slate" /> {{ t.etiqueta }}
        </button>
      </div>

      <form class="mt-6 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4" data-filtros @submit.prevent="aplicar">
        <label class="block">
          <span class="text-[11px] uppercase tracking-wide font-black text-slate-400">Tipo</span>
          <select v-model="filtros.tipo" :class="CAMPO" data-filtro="tipo" @change="aplicar">
            <option value="">Todos</option>
            <option v-for="t in opciones.tipos" :key="t.valor" :value="t.valor">{{ t.etiqueta }}</option>
          </select>
        </label>
        <label class="block">
          <span class="text-[11px] uppercase tracking-wide font-black text-slate-400">Estado</span>
          <select v-model="filtros.estado" :class="CAMPO" data-filtro="estado" @change="aplicar">
            <option value="">Todos</option>
            <option v-for="e in opciones.estados" :key="e.valor" :value="e.valor">{{ e.etiqueta }} ({{ n(resumen.por_estado[e.valor]) }})</option>
          </select>
        </label>
        <label class="block">
          <span class="text-[11px] uppercase tracking-wide font-black text-slate-400">Evento</span>
          <select v-model="filtros.evento" :class="CAMPO" data-filtro="evento" @change="aplicar">
            <option value="">Todos</option>
            <option v-for="e in opciones.eventos" :key="e.valor" :value="String(e.valor)">{{ e.etiqueta }}</option>
          </select>
        </label>
        <div class="flex items-end">
          <button v-if="hayFiltros()" type="button" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-slate-100 text-slate-600 hover:bg-slate-200 text-sm font-bold" @click="limpiar()"><X class="w-4 h-4" /> Limpiar filtros</button>
        </div>
      </form>

      <div v-if="casos.data.length === 0" class="mt-6 text-center py-16 space-y-3 bg-white rounded-[2rem] border border-dashed border-slate-200" data-sin-casos>
        <div class="w-16 h-16 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto"><ClipboardCheck class="w-8 h-8 text-slate-400" /></div>
        <p class="text-slate-600 font-bold text-lg">{{ hayFiltros() ? "No hay casos con esos filtros" : "No hay casos por revisar" }}</p>
        <p class="text-sm font-semibold text-slate-400">Los casos aparecen cuando se ejecuta la detección de conciliaciones.</p>
      </div>

      <div v-else class="mt-6 space-y-4">
        <div class="overflow-x-auto bg-white rounded-[2rem] border border-slate-100 shadow-sm">
          <table class="min-w-full text-sm" data-tabla-casos>
            <thead class="text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
              <tr>
                <th class="text-left font-black px-6 py-4">Tipo</th>
                <th class="text-left font-black px-4 py-4">Estado</th>
                <th class="text-left font-black px-4 py-4">Evento</th>
                <th class="text-left font-black px-4 py-4">Motivo</th>
                <th class="text-right font-black px-4 py-4">Certificados</th>
                <th class="text-left font-black px-4 py-4">Detectado</th>
                <th class="px-6 py-4"><span class="sr-only">Abrir</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="c in casos.data" :key="c.id" class="align-top hover:bg-slate-50/60 transition-colors" :data-caso="c.id">
                <td class="px-6 py-4 text-slate-700 font-bold">{{ c.tipo_info.etiqueta }}</td>
                <td class="px-4 py-4"><Insignia :etiqueta="c.estado_info.etiqueta" :tono="c.estado_info.tono" /></td>
                <td class="px-4 py-4 text-slate-600 font-semibold max-w-[16rem]">
                  <template v-if="c.evento">{{ c.evento.nombre }} <span class="text-slate-400">· {{ c.evento.anio_etiqueta }}</span></template>
                  <span v-else class="text-slate-400">Varios eventos</span>
                </td>
                <td class="px-4 py-4 text-slate-600 font-semibold max-w-[18rem]">{{ c.motivo ?? "—" }}</td>
                <td class="px-4 py-4 text-right text-slate-600 font-semibold">{{ n(c.afectados) }}</td>
                <td class="px-4 py-4 text-slate-500 font-semibold whitespace-nowrap">{{ fecha(c.detectado_at) }}</td>
                <td class="px-6 py-4 text-right">
                  <Link :href="route('credential-flow.historico.casos.show', c.id)" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-primary-vinotinto/10 text-[12px] font-bold text-primary-vinotinto hover:bg-primary-vinotinto/20 transition-all" :aria-label="`Abrir el caso ${c.id}`">Abrir</Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <Paginador :paginador="casos" unidad="casos" />
      </div>
    </Sidebar>
  </AuthenticatedLayout>
</template>
