<script setup>
import { computed, ref } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import { Download, Unlink, ChevronLeft, ChevronRight } from "lucide-vue-next";

const props = defineProps({
  evento: { type: Object, required: true }, // { id, nombre, anio, fecha_texto, reales{...}, qa{...}, bases }
  bases: { type: Array, default: () => [] },
  emisiones: { type: Object, required: true }, // paginador (solo participantes reales)
  estado: { type: String, default: "emitida" },
  disponibles: { type: Array, default: () => [] }, // bases sin evento que se pueden sumar
});

const headerStats = computed(() => [
  { label: "Vigentes", value: props.evento.reales.vigentes, icon: "verified", color: "text-emerald-700", bg: "bg-emerald-50" },
  { label: "Pendientes", value: props.evento.reales.pendientes, icon: "pending", color: "text-amber-600", bg: "bg-amber-50" },
  { label: "Revocados", value: props.evento.reales.revocadas, icon: "block", color: "text-rose-600", bg: "bg-rose-50" },
]);

const incluirQa = ref(false);
const zipUrl = computed(() => route("credential-flow.eventos.zip", props.evento.id) + (incluirQa.value ? "?incluir_qa=1" : ""));
const baseElegida = ref("");

const formatFecha = (iso) => {
  if (!iso) return "—";
  const d = new Date(iso);
  return isNaN(d.getTime()) ? "—" : new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "short", year: "numeric", timeZone: "America/Bogota" }).format(d);
};

const cambiarEstado = (estado) => router.get(route("credential-flow.eventos.show", props.evento.id), { estado }, { preserveScroll: true, preserveState: true });
const ir = (url) => url && router.get(url, {}, { preserveScroll: true });
const sumar = () => baseElegida.value && router.put(route("credential-flow.lotes.evento", baseElegida.value), { evento_id: props.evento.id }, { preserveScroll: true, onSuccess: () => (baseElegida.value = "") });
const quitar = (b) => router.put(route("credential-flow.lotes.evento", b.id), { evento_id: null }, { preserveScroll: true });
</script>

<template>
  <Head :title="`Credential Flow · ${evento.nombre}`" />

  <AuthenticatedLayout>
    <Sidebar>
      <nav class="mb-3 flex flex-wrap items-center gap-x-2 pr-14 md:pr-0 text-[12px] font-bold text-slate-400" aria-label="Ruta de navegación">
        <Link :href="route('credential-flow.index')" class="hover:text-primary-vinotinto transition-colors">Credential Flow</Link>
        <span>/</span>
        <Link :href="route('credential-flow.eventos.index')" class="hover:text-primary-vinotinto transition-colors">Eventos</Link>
        <span>/</span>
        <span class="text-slate-600 break-words">{{ evento.nombre }}</span>
      </nav>

      <DashboardHeader :title="evento.nombre" :subtitle="evento.fecha_texto ?? String(evento.anio ?? '')" :stats="headerStats">
        <template #actions>
          <div class="flex flex-wrap items-center gap-3">
            <Link :href="route('credential-flow.lotes.nuevo', { evento: evento.id })" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-all" data-accion="importar-participantes">
              Importar participantes
            </Link>
            <label class="inline-flex items-center gap-2 text-[12px] font-bold text-slate-500">
              <input v-model="incluirQa" type="checkbox" class="rounded border-slate-300" data-opcion="incluir-qa" />
              Incluir participantes de control (QA)
            </label>
            <a :href="zipUrl" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90 transition-all" data-accion="zip-evento">
              <Download class="w-4 h-4" /> Descargar ZIP de certificados nuevos
            </a>
            <p class="basis-full text-[11px] font-semibold text-slate-400" data-aviso-zip>Contiene solo certificados nuevos vigentes. Los históricos se descargan por evento desde el módulo Histórico.</p>
          </div>
        </template>
      </DashboardHeader>

      <p class="mt-6 rounded-2xl bg-primary-vinotinto/5 border border-primary-vinotinto/10 px-4 py-3 text-[13px] font-bold text-primary-vinotinto" data-siguiente-paso-evento>
        Siguiente paso:
        <template v-if="bases.length === 0">importa los participantes de este evento («Importar participantes», arriba).</template>
        <template v-else>abre una base para revisar a los participantes, confirmar la plantilla y la vista previa, y emitir. Para otra modalidad, importa otra base en este mismo evento.</template>
      </p>

      <section class="mt-8 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6" data-bases-evento>
        <h2 class="text-sm font-extrabold text-slate-700">Bases del evento</h2>
        <p class="mt-1 text-[13px] font-medium text-slate-500">Un evento puede tener varias bases (por ejemplo presencial y virtual). Los participantes de control (QA) se cuentan aparte, estén en la base que estén, y no entran al ZIP por defecto.</p>
        <ul class="mt-4 divide-y divide-slate-100">
          <li v-for="b in bases" :key="b.id" class="flex flex-wrap items-center justify-between gap-3 py-3" :data-base="b.id">
            <div class="min-w-0">
              <p class="font-extrabold text-slate-800 break-words">
                <Link :href="route('credential-flow.lotes.show', b.id)" class="hover:text-primary-vinotinto">{{ b.nombre }}</Link>
              </p>
              <p class="text-[12px] font-semibold text-slate-400">{{ b.plantilla ?? "—" }} · {{ b.reales }} reales · {{ b.vigentes }} vigentes · {{ b.revocadas }} revocados<template v-if="b.qa"> · <span class="text-violet-600" data-qa-base>{{ b.qa }} de control (QA)</span></template></p>
            </div>
            <button type="button" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full bg-slate-100 text-[12px] font-bold text-slate-600 hover:bg-slate-200" data-accion="quitar-base" @click="quitar(b)">
              <Unlink class="w-3.5 h-3.5" /> Quitar del evento
            </button>
          </li>
        </ul>
        <div v-if="disponibles.length" class="mt-4 flex flex-wrap items-center gap-2">
          <select v-model="baseElegida" class="rounded-xl border border-slate-200 text-sm font-semibold text-slate-700 px-3 py-2" data-campo="sumar-base">
            <option value="">Sumar otra base al evento…</option>
            <option v-for="d in disponibles" :key="d.id" :value="d.id">{{ d.nombre }}</option>
          </select>
          <button type="button" :disabled="!baseElegida" class="px-4 py-2 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold disabled:opacity-40" data-accion="sumar-base" @click="sumar">Sumar</button>
        </div>
      </section>

      <section class="mt-8 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6" data-emisiones-evento>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="text-sm font-extrabold text-slate-700">Certificados de participantes reales</h2>
          <div class="inline-flex rounded-full bg-slate-100 p-1 text-[12px] font-bold">
            <button type="button" class="px-3 py-1 rounded-full" :class="estado === 'emitida' ? 'bg-white shadow text-slate-900' : 'text-slate-500'" @click="cambiarEstado('emitida')">Vigentes</button>
            <button type="button" class="px-3 py-1 rounded-full" :class="estado === 'revocada' ? 'bg-white shadow text-slate-900' : 'text-slate-500'" @click="cambiarEstado('revocada')">Revocados</button>
          </div>
        </div>

        <p v-if="emisiones.data.length === 0" class="mt-4 text-sm font-semibold text-slate-400" data-sin-emisiones>No hay certificados {{ estado === "emitida" ? "vigentes" : "revocados" }} para mostrar.</p>
        <div v-else class="relative mt-4 overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
              <tr>
                <th class="text-left font-black px-4 py-3">Participante</th>
                <th class="hidden sm:table-cell text-left font-black px-4 py-3">Código</th>
                <th class="hidden sm:table-cell text-left font-black px-4 py-3">Versión</th>
                <th class="hidden sm:table-cell text-left font-black px-4 py-3">Emitido</th>
                <th class="px-4 py-3"><span class="sr-only">Descargar</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="e in emisiones.data" :key="e.id" :data-emision="e.id">
                <td class="px-4 py-3 font-bold text-slate-800">{{ e.participante ?? "—" }}</td>
                <td class="hidden sm:table-cell px-4 py-3 font-mono text-[12px] text-slate-500">{{ e.codigo }}</td>
                <td class="hidden sm:table-cell px-4 py-3 text-slate-500 font-semibold">v{{ e.version }}</td>
                <td class="hidden sm:table-cell px-4 py-3 text-slate-500 font-semibold whitespace-nowrap">{{ formatFecha(e.emitido_at) }}</td>
                <td class="px-4 py-3 text-right">
                  <a :href="route('credential-flow.emisiones.descargar', e.id)" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-primary-vinotinto/10 text-[12px] font-bold text-primary-vinotinto hover:bg-primary-vinotinto/20">
                    <Download class="w-3.5 h-3.5" /> PDF
                  </a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="emisiones.last_page > 1" class="mt-4 flex items-center justify-between" data-paginacion>
          <button type="button" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 disabled:opacity-40" :disabled="!emisiones.prev_page_url" @click="ir(emisiones.prev_page_url)"><ChevronLeft class="w-4 h-4" /> Anterior</button>
          <span class="text-[13px] font-bold text-slate-500">Página {{ emisiones.current_page }} de {{ emisiones.last_page }}</span>
          <button type="button" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 disabled:opacity-40" :disabled="!emisiones.next_page_url" @click="ir(emisiones.next_page_url)">Siguiente <ChevronRight class="w-4 h-4" /></button>
        </div>
      </section>
    </Sidebar>
  </AuthenticatedLayout>
</template>
