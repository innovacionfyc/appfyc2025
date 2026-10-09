<script setup>
import { computed } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import NavHistorico from "@/Components/CredentialFlow/Historico/NavHistorico.vue";
import Insignia from "@/Components/CredentialFlow/Historico/Insignia.vue";
import Paginador from "@/Components/CredentialFlow/Historico/Paginador.vue";
import { useFiltrosHistorico } from "@/Composables/CredentialFlow/useFiltrosHistorico";
import { ArrowRight, Search, X, History } from "lucide-vue-next";

const props = defineProps({
  eventos: { type: Object, required: true }, // paginador de Laravel (SOLO LECTURA)
  filtros: { type: Object, required: true },
  opciones: { type: Object, default: () => ({ anios: [] }) },
  resumen: { type: Object, default: () => ({}) },
});

const { filtros, aplicar, aplicarConEspera, limpiar, hayFiltros } = useFiltrosHistorico(() => route("credential-flow.historico.index"), props.filtros, { orden: "anio" });

const n = (v) => (v ?? 0).toLocaleString("es-CO");

const headerStats = computed(() => [
  { label: "Eventos", value: n(props.resumen.eventos), icon: "event", color: "text-primary-vinotinto", bg: "bg-primary-vinotinto/10" },
  { label: "Certificados", value: n(props.resumen.certificados), icon: "workspace_premium", color: "text-primary-naranja", bg: "bg-primary-naranja/10" },
]);

const conciliaciones = [
  { clave: "ok", etiqueta: "Sin novedad", tono: "emerald" },
  { clave: "duplicado_consolidado", etiqueta: "Duplicados históricos", tono: "sky" },
  { clave: "pendiente_plantilla", etiqueta: "Plantilla pendiente", tono: "amber" },
  { clave: "pendiente_conciliacion", etiqueta: "Necesitan conciliación", tono: "rose" },
  { clave: "revision_documento", etiqueta: "Documento en revisión", tono: "rose" },
];

const SELECT = "w-full rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-primary-vinotinto/30 focus:border-primary-vinotinto";
</script>

<template>
  <Head title="Credential Flow · Histórico" />

  <AuthenticatedLayout>
    <Sidebar>
      <NavHistorico actual="eventos" />

      <DashboardHeader title="Histórico" subtitle="Consulta los certificados de las evaluaciones anteriores. Esta sección es solo de lectura." :stats="headerStats" />

      <!-- Resumen por estado de conciliación: cada chip filtra el listado -->
      <div class="mt-6 flex flex-wrap gap-2" data-resumen-conciliacion>
        <button
          v-for="c in conciliaciones"
          :key="c.clave"
          type="button"
          :class="['inline-flex items-center gap-2 px-3 py-1.5 rounded-full border text-[12px] font-bold transition-all', filtros.conciliacion === c.clave ? 'border-primary-vinotinto bg-primary-vinotinto/5 text-primary-vinotinto' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50']"
          @click="filtros.conciliacion = filtros.conciliacion === c.clave ? '' : c.clave; aplicar()"
        >
          <Insignia :etiqueta="n(resumen.por_conciliacion?.[c.clave])" :tono="c.tono" />
          {{ c.etiqueta }}
        </button>
      </div>

      <!-- Filtros (se aplican en el servidor) -->
      <form class="mt-6 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-5 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4" data-filtros @submit.prevent="aplicar">
        <label class="sm:col-span-2 xl:col-span-2 relative block">
          <span class="sr-only">Buscar evento por nombre</span>
          <Search class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
          <input v-model="filtros.q" type="search" maxlength="80" placeholder="Buscar evento por nombre…" :class="[SELECT, 'pl-10']" data-filtro="q" @input="aplicarConEspera" />
        </label>
        <label class="block">
          <span class="sr-only">Año</span>
          <select v-model="filtros.anio" :class="SELECT" data-filtro="anio" @change="aplicar">
            <option value="">Todos los años</option>
            <option v-for="a in opciones.anios" :key="a" :value="String(a)">{{ a }}</option>
            <option value="sin">Sin año identificado</option>
          </select>
        </label>
        <label class="block">
          <span class="sr-only">Estado de la plantilla</span>
          <select v-model="filtros.plantilla" :class="SELECT" data-filtro="plantilla" @change="aplicar">
            <option value="">Cualquier plantilla</option>
            <option value="ok">Plantilla disponible</option>
            <option value="faltante">Imagen faltante</option>
            <option value="extension_invalida">Extensión inválida</option>
            <option value="con_candidata">Con candidata en revisión</option>
            <option value="sin_plantilla">Sin plantilla</option>
          </select>
        </label>
        <label class="block">
          <span class="sr-only">Estado de conciliación</span>
          <select v-model="filtros.conciliacion" :class="SELECT" data-filtro="conciliacion" @change="aplicar">
            <option value="">Cualquier conciliación</option>
            <option v-for="c in conciliaciones" :key="c.clave" :value="c.clave">{{ c.etiqueta }}</option>
          </select>
        </label>
        <label class="block">
          <span class="sr-only">Descargas</span>
          <select v-model="filtros.descargas" :class="SELECT" data-filtro="descargas" @change="aplicar">
            <option value="">Con o sin descargas</option>
            <option value="con">Con descargas</option>
            <option value="sin">Sin descargas</option>
          </select>
        </label>
        <label class="block">
          <span class="sr-only">Código legado</span>
          <select v-model="filtros.codigo" :class="SELECT" data-filtro="codigo" @change="aplicar">
            <option value="">Con o sin código</option>
            <option value="con">Con código legado</option>
            <option value="sin">Sin código legado</option>
          </select>
        </label>
        <label class="block">
          <span class="sr-only">Orden</span>
          <select v-model="filtros.orden" :class="SELECT" data-filtro="orden" @change="aplicar">
            <option value="anio">Año (más reciente primero)</option>
            <option value="nombre">Nombre (A–Z)</option>
            <option value="certificados">Más certificados</option>
          </select>
        </label>
        <div class="flex items-center">
          <button v-if="hayFiltros()" type="button" class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-2xl bg-slate-100 text-sm font-bold text-slate-600 hover:bg-slate-200" data-accion="limpiar" @click="limpiar()">
            <X class="w-4 h-4" /> Limpiar filtros
          </button>
        </div>
      </form>

      <div v-if="eventos.data.length === 0" class="mt-6 text-center py-16 space-y-3 bg-white rounded-[2rem] border border-dashed border-slate-200" data-sin-eventos>
        <div class="w-16 h-16 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto"><History class="w-8 h-8 text-slate-400" /></div>
        <p class="text-slate-600 font-bold text-lg">No encontramos eventos con esos filtros</p>
        <p class="text-slate-400 text-sm max-w-md mx-auto px-4">Prueba con otro nombre o limpia los filtros para ver todo el histórico.</p>
      </div>

      <div v-else class="mt-6 space-y-4">
        <div class="overflow-x-auto bg-white rounded-[2rem] border border-slate-100 shadow-sm">
          <table class="min-w-full text-sm" data-tabla-eventos>
            <thead class="text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
              <tr>
                <th class="text-left font-black px-6 py-4">Evento</th>
                <th class="text-left font-black px-4 py-4">Año</th>
                <th class="text-left font-black px-4 py-4">Estado</th>
                <th class="text-right font-black px-4 py-4">Certificados</th>
                <th class="text-right font-black px-4 py-4">Descargados</th>
                <th class="text-right font-black px-4 py-4">Con código</th>
                <th class="text-left font-black px-4 py-4">Plantilla</th>
                <th class="text-left font-black px-4 py-4">Pendientes</th>
                <th class="px-6 py-4"><span class="sr-only">Acciones</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="e in eventos.data" :key="e.id" class="hover:bg-slate-50/60 transition-colors" :data-evento="e.id">
                <td class="px-6 py-4 font-extrabold text-slate-900 break-words max-w-xs">{{ e.nombre }}</td>
                <td class="px-4 py-4 whitespace-nowrap font-semibold" :class="e.anio === null ? 'text-slate-400 italic' : 'text-slate-600'">{{ e.anio_etiqueta }}</td>
                <td class="px-4 py-4 text-slate-600 font-semibold">{{ e.estado }}</td>
                <td class="px-4 py-4 text-right font-black text-slate-900">{{ n(e.certificados) }}</td>
                <td class="px-4 py-4 text-right font-semibold text-slate-600">{{ n(e.descargados) }}</td>
                <td class="px-4 py-4 text-right font-semibold text-slate-600">{{ n(e.con_codigo) }}</td>
                <td class="px-4 py-4">
                  <div class="flex flex-col items-start gap-1">
                    <Insignia :etiqueta="e.plantilla.etiqueta" :tono="e.plantilla.tono" />
                    <Insignia v-if="e.con_candidata" etiqueta="Con candidata" tono="sky" />
                  </div>
                </td>
                <td class="px-4 py-4">
                  <div class="flex flex-wrap gap-1">
                    <Insignia v-if="e.revision_documento" :etiqueta="`${n(e.revision_documento)} documento`" tono="rose" />
                    <Insignia v-if="e.pendiente_conciliacion" :etiqueta="`${n(e.pendiente_conciliacion)} conciliar`" tono="rose" />
                    <Insignia v-if="e.pendiente_plantilla" :etiqueta="`${n(e.pendiente_plantilla)} plantilla`" tono="amber" />
                    <span v-if="!e.revision_documento && !e.pendiente_conciliacion && !e.pendiente_plantilla" class="text-slate-300 font-bold">—</span>
                  </div>
                </td>
                <td class="px-6 py-4 text-right">
                  <Link :href="route('credential-flow.historico.eventos.show', e.id)" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-primary-vinotinto/10 text-[12px] font-bold text-primary-vinotinto hover:bg-primary-vinotinto/20 transition-all" :aria-label="`Abrir el evento ${e.nombre}`">
                    Abrir <ArrowRight class="w-3.5 h-3.5" />
                  </Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <Paginador :paginador="eventos" unidad="eventos" />
      </div>
    </Sidebar>
  </AuthenticatedLayout>
</template>
