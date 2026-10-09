<script setup>
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import Insignia from "@/Components/CredentialFlow/Historico/Insignia.vue";
import Paginador from "@/Components/CredentialFlow/Historico/Paginador.vue";
import { useFiltrosHistorico } from "@/Composables/CredentialFlow/useFiltrosHistorico";
import { Mail, X, Info } from "lucide-vue-next";

// Envíos de correo: SOLO LECTURA. No hay reenvíos, acciones masivas ni edición. Solo se ve el destinatario enmascarado.
const props = defineProps({
  envios: { type: Object, required: true }, // paginador de Laravel
  resumen: { type: Object, required: true },
  filtros: { type: Object, required: true },
  opciones: { type: Object, required: true },
});

const { filtros, aplicar, limpiar, hayFiltros } = useFiltrosHistorico(() => route("credential-flow.envios.index"), props.filtros);

const n = (v) => (v ?? 0).toLocaleString("es-CO");
const fecha = (v) => {
  if (!v) return "—";
  const d = new Date(String(v).replace(" ", "T"));
  return isNaN(d.getTime()) ? v : new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }).format(d);
};
const CAMPO = "w-full rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-primary-vinotinto/30 focus:border-primary-vinotinto";
const elegir = (estado) => {
  filtros.estado = filtros.estado === estado ? "" : estado;
  aplicar();
};
</script>

<template>
  <Head title="Credential Flow · Envíos" />

  <AuthenticatedLayout>
    <Sidebar>
      <nav class="mb-3 flex flex-wrap items-center gap-2 text-[12px] font-bold text-slate-400" aria-label="Ruta de navegación">
        <Link :href="route('credential-flow.index')" class="hover:text-primary-vinotinto transition-colors">Credential Flow</Link>
        <span>/</span>
        <span class="text-slate-600">Envíos</span>
      </nav>

      <DashboardHeader title="Envíos de correo" subtitle="Los códigos de acceso que se enviaron a las personas y lo que respondió el servidor de correo. Solo lectura." />

      <p class="mt-4 flex items-start gap-2 max-w-3xl text-sm font-semibold text-slate-500" data-nota-entrega>
        <Info class="w-4 h-4 mt-0.5 shrink-0" />
        «Aceptado por el servidor de correo» significa que el servidor recibió el mensaje. No confirma que haya llegado a la bandeja de entrada de la persona.
        Por seguridad solo se muestra el correo parcialmente oculto, nunca el código ni el contenido del mensaje.
      </p>

      <div class="mt-6 flex flex-wrap gap-2" data-resumen-envios>
        <button
          v-for="e in opciones.estados"
          :key="e.valor"
          type="button"
          :title="e.ayuda"
          :class="['inline-flex items-center gap-2 px-3 py-1.5 rounded-full border text-[12px] font-bold transition-all', filtros.estado === e.valor ? 'border-primary-vinotinto bg-primary-vinotinto/5 text-primary-vinotinto' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50']"
          :data-estado="e.valor"
          @click="elegir(e.valor)"
        >
          <Insignia :etiqueta="n(resumen.por_estado[e.valor])" :tono="e.tono" /> {{ e.etiqueta }}
        </button>
      </div>

      <form class="mt-6 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4" data-filtros @submit.prevent="aplicar">
        <label class="block">
          <span class="text-[11px] uppercase tracking-wide font-black text-slate-400">Estado</span>
          <select v-model="filtros.estado" :class="CAMPO" data-filtro="estado" @change="aplicar">
            <option value="">Todos</option>
            <option v-for="e in opciones.estados" :key="e.valor" :value="e.valor">{{ e.etiqueta }}</option>
          </select>
        </label>
        <label class="block">
          <span class="text-[11px] uppercase tracking-wide font-black text-slate-400">Tipo de correo</span>
          <select v-model="filtros.tipo" :class="CAMPO" data-filtro="tipo" @change="aplicar">
            <option value="">Todos</option>
            <option v-for="t in opciones.tipos" :key="t.valor" :value="t.valor">{{ t.etiqueta }}</option>
          </select>
        </label>
        <label class="block">
          <span class="text-[11px] uppercase tracking-wide font-black text-slate-400">Desde</span>
          <input v-model="filtros.desde" type="date" :class="CAMPO" data-filtro="desde" @change="aplicar" />
        </label>
        <label class="block">
          <span class="text-[11px] uppercase tracking-wide font-black text-slate-400">Hasta</span>
          <input v-model="filtros.hasta" type="date" :class="CAMPO" data-filtro="hasta" @change="aplicar" />
        </label>
        <div class="flex items-end">
          <button v-if="hayFiltros()" type="button" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-slate-100 text-slate-600 hover:bg-slate-200 text-sm font-bold" @click="limpiar()"><X class="w-4 h-4" /> Limpiar filtros</button>
        </div>
      </form>

      <div v-if="envios.data.length === 0" class="mt-6 text-center py-16 space-y-3 bg-white rounded-[2rem] border border-dashed border-slate-200" data-sin-envios>
        <div class="w-16 h-16 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto"><Mail class="w-8 h-8 text-slate-400" /></div>
        <p class="text-slate-600 font-bold text-lg">{{ hayFiltros() ? "No hay envíos con esos filtros" : "Todavía no hay envíos registrados" }}</p>
        <p class="text-sm font-semibold text-slate-400">Los envíos aparecen aquí cuando una persona pide su código en el portal de certificados.</p>
      </div>

      <div v-else class="mt-6 space-y-4">
        <div class="overflow-x-auto bg-white rounded-[2rem] border border-slate-100 shadow-sm">
          <table class="min-w-full text-sm" data-tabla-envios>
            <thead class="text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
              <tr>
                <th class="text-left font-black px-6 py-4">Fecha</th>
                <th class="text-left font-black px-4 py-4">Tipo</th>
                <th class="text-left font-black px-4 py-4">Estado</th>
                <th class="text-left font-black px-4 py-4">Destinatario</th>
                <th class="text-right font-black px-4 py-4">Intentos</th>
                <th class="text-left font-black px-6 py-4">Detalle del problema</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="e in envios.data" :key="e.id" class="align-top hover:bg-slate-50/60 transition-colors" :data-envio="e.id">
                <td class="px-6 py-4 text-slate-600 font-semibold whitespace-nowrap">{{ fecha(e.fecha) }}</td>
                <td class="px-4 py-4 text-slate-700 font-bold">{{ e.tipo_etiqueta }}</td>
                <td class="px-4 py-4"><Insignia :etiqueta="e.estado_info.etiqueta" :tono="e.estado_info.tono" /></td>
                <td class="px-4 py-4 text-slate-600 font-mono" data-destinatario>{{ e.destinatario }}</td>
                <td class="px-4 py-4 text-right text-slate-600 font-semibold whitespace-nowrap">{{ e.intentos }} de {{ e.max_intentos }}</td>
                <td class="px-6 py-4 text-slate-600 font-semibold max-w-[24rem]">
                  <template v-if="e.error_texto">{{ e.error_texto }} <span class="block text-[11px] text-slate-400 font-mono">Código: {{ e.error_codigo }}</span></template>
                  <span v-else class="text-slate-300">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <Paginador :paginador="envios" unidad="envíos" />
      </div>
    </Sidebar>
  </AuthenticatedLayout>
</template>
