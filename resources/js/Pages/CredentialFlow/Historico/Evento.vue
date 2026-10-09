<script setup>
import { Head } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import NavHistorico from "@/Components/CredentialFlow/Historico/NavHistorico.vue";
import Insignia from "@/Components/CredentialFlow/Historico/Insignia.vue";
import Paginador from "@/Components/CredentialFlow/Historico/Paginador.vue";
import AvisoHistorico from "@/Components/CredentialFlow/Historico/AvisoHistorico.vue";
import TablaCertificados from "@/Components/CredentialFlow/Historico/TablaCertificados.vue";
import { useFiltrosHistorico } from "@/Composables/CredentialFlow/useFiltrosHistorico";
import { Search, X } from "lucide-vue-next";

const props = defineProps({
  evento: { type: Object, required: true },
  certificados: { type: Object, required: true }, // paginador de Laravel
  filtros: { type: Object, required: true },
});

const { filtros, aplicar, aplicarConEspera, limpiar, hayFiltros } = useFiltrosHistorico(() => route("credential-flow.historico.eventos.show", props.evento.id), props.filtros);

const n = (v) => (v ?? 0).toLocaleString("es-CO");
const SELECT = "w-full rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-primary-vinotinto/30 focus:border-primary-vinotinto";

const tarjetas = [
  { id: "certificados", etiqueta: "Certificados", valor: () => n(props.evento.certificados) },
  { id: "codigo", etiqueta: "Con código legado", valor: () => n(props.evento.con_codigo) },
  { id: "descargados", etiqueta: "Con descargas", valor: () => `${n(props.evento.descargados)} (${n(props.evento.descargas)} en total)` },
  { id: "duplicados", etiqueta: "Duplicados históricos", valor: () => n(props.evento.duplicados) },
];
</script>

<template>
  <Head :title="`Credential Flow · ${evento.nombre}`" />

  <AuthenticatedLayout>
    <Sidebar>
      <NavHistorico actual="eventos" :migas="[{ texto: evento.nombre }]" />

      <header class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
          <Insignia :etiqueta="evento.estado" tono="slate" />
          <Insignia :etiqueta="evento.anio_etiqueta" :tono="evento.anio === null ? 'amber' : 'sky'" />
          <Insignia :etiqueta="evento.plantilla.etiqueta" :tono="evento.plantilla.tono" />
        </div>
        <h2 class="text-3xl md:text-4xl font-extrabold text-slate-900 tracking-tight break-words">{{ evento.nombre }}</h2>
        <p class="text-sm font-semibold text-slate-400">Identificador en el sistema anterior: <span class="font-mono text-slate-500">{{ evento.old_id ?? "—" }}</span> · Solo lectura</p>
      </header>

      <div class="mt-6 grid grid-cols-2 xl:grid-cols-4 gap-4" data-resumen-evento>
        <div v-for="t in tarjetas" :key="t.id" class="bg-white rounded-[2rem] border border-slate-100 shadow-sm px-5 py-4">
          <p class="text-[11px] uppercase tracking-wide font-black text-slate-400">{{ t.etiqueta }}</p>
          <p class="mt-1 text-xl font-black text-slate-900 break-words">{{ t.valor() }}</p>
        </div>
      </div>

      <!-- Plantilla del evento -->
      <section v-if="evento.plantilla_detalle" class="mt-4 bg-white rounded-[2rem] border border-slate-100 shadow-sm px-5 py-4 text-sm" data-plantilla-evento>
        <p class="text-[11px] uppercase tracking-wide font-black text-slate-400">Plantilla del evento</p>
        <dl class="mt-2 grid grid-cols-2 md:grid-cols-4 gap-x-6 gap-y-2 font-semibold text-slate-600">
          <div class="col-span-2 md:col-span-1"><dt class="text-[11px] font-black text-slate-400">Imagen</dt><dd class="break-words">{{ evento.plantilla_detalle.nombre }}</dd></div>
          <template v-if="evento.plantilla_detalle.contenido">
            <div><dt class="text-[11px] font-black text-slate-400">Tipo</dt><dd>{{ evento.plantilla_detalle.mime ?? "—" }}</dd></div>
            <div><dt class="text-[11px] font-black text-slate-400">Tamaño</dt><dd>{{ evento.plantilla_detalle.dimensiones ?? "—" }} · {{ evento.plantilla_detalle.bytes ?? "—" }}</dd></div>
          </template>
          <div><dt class="text-[11px] font-black text-slate-400">Se puede generar</dt><dd>{{ evento.plantilla_detalle.renderizable ? "Sí" : "No" }}</dd></div>
        </dl>
        <p v-if="!evento.plantilla_detalle.contenido" class="mt-2 text-[12px] font-semibold text-slate-400">No hay archivo de imagen para este evento en el sistema anterior.</p>
      </section>

      <div v-if="evento.avisos.length" class="mt-4 space-y-3" data-avisos>
        <AvisoHistorico v-for="(a, i) in evento.avisos" :key="i" :aviso="a" />
      </div>

      <h3 class="mt-10 text-lg font-extrabold text-slate-900">Certificados del evento</h3>

      <form class="mt-3 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-5 grid grid-cols-1 sm:grid-cols-3 gap-4" data-filtros @submit.prevent="aplicar">
        <label class="relative block sm:col-span-2">
          <span class="sr-only">Buscar por nombre, documento o código</span>
          <Search class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
          <input v-model="filtros.q" type="search" maxlength="80" placeholder="Nombre, documento exacto o código…" :class="[SELECT, 'pl-10']" data-filtro="q" @input="aplicarConEspera" />
        </label>
        <div class="flex gap-3">
          <label class="block flex-1">
            <span class="sr-only">Conciliación</span>
            <select v-model="filtros.conciliacion" :class="SELECT" data-filtro="conciliacion" @change="aplicar">
              <option value="">Cualquier estado</option>
              <option value="ok">Sin novedad</option>
              <option value="duplicado_consolidado">Duplicado histórico</option>
              <option value="pendiente_plantilla">Plantilla pendiente</option>
              <option value="pendiente_conciliacion">Necesita conciliación</option>
              <option value="revision_documento">Documento en revisión</option>
            </select>
          </label>
          <button v-if="hayFiltros()" type="button" class="inline-flex items-center px-3 rounded-2xl bg-slate-100 text-slate-600 hover:bg-slate-200" aria-label="Limpiar filtros" @click="limpiar()"><X class="w-4 h-4" /></button>
        </div>
      </form>

      <div v-if="certificados.data.length === 0" class="mt-4 text-center py-12 bg-white rounded-[2rem] border border-dashed border-slate-200 text-slate-500 font-bold" data-sin-certificados>
        No hay certificados que coincidan.
      </div>
      <div v-else class="mt-4 space-y-4">
        <TablaCertificados :filas="certificados.data" />
        <Paginador :paginador="certificados" unidad="certificados" />
      </div>
    </Sidebar>
  </AuthenticatedLayout>
</template>
