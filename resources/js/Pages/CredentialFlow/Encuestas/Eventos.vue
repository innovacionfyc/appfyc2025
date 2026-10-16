<script setup>
import { computed } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import NavEncuestas from "@/Components/CredentialFlow/Encuestas/NavEncuestas.vue";
import FiltrosEncuestas from "@/Components/CredentialFlow/Encuestas/FiltrosEncuestas.vue";
import Paginador from "@/Components/CredentialFlow/Historico/Paginador.vue";
import Insignia from "@/Components/CredentialFlow/Historico/Insignia.vue";
import { ClipboardList, ArrowRight } from "lucide-vue-next";

// Resultados por evento: lista paginada en el servidor de los eventos con respuestas (solo conteos).
const props = defineProps({
  filtros: { type: Object, required: true },
  opciones: { type: Object, required: true },
  eventos: { type: Object, required: true }, // paginador de Laravel
  sin_evento: { type: Number, default: 0 },
});

const n = (v) => (v ?? 0).toLocaleString("es-CO");
const fecha = (v) => (v ? String(v).slice(0, 10) : "—");
// Al abrir un evento viajan solo los filtros que no dependen del evento (versión, origen y fechas de respuesta).
const paraDetalle = computed(() => Object.fromEntries(Object.entries(props.filtros).filter(([k, v]) => ["version", "origen", "resp_desde", "resp_hasta"].includes(k) && v !== null && v !== "")));
const CAJA = "bg-white rounded-[2rem] border border-slate-100 shadow-sm";
</script>

<template>
  <Head title="Credential Flow · Resultados por evento" />

  <AuthenticatedLayout>
    <Sidebar>
      <NavEncuestas actual="eventos" :filtros="filtros" :migas="[{ texto: 'Resultados por evento' }]" />

      <DashboardHeader title="Resultados por evento" subtitle="Cada evento conserva resultados propios: nunca se mezclan con los de otro evento." />

      <div class="mt-6">
        <FiltrosEncuestas :filtros="filtros" :opciones="opciones" :url="() => route('credential-flow.encuestas.eventos')" />
      </div>

      <section :class="[CAJA, 'p-4 sm:p-6']" data-lista-eventos>
        <div v-if="eventos.data.length === 0" class="text-center py-12 space-y-3" data-sin-resultados>
          <div class="w-14 h-14 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto"><ClipboardList class="w-7 h-7 text-slate-400" /></div>
          <p class="text-slate-600 font-bold">No hay eventos con respuestas para estos filtros</p>
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
              <tr>
                <th class="text-left font-black py-3 pr-4">Evento</th>
                <th class="text-left font-black py-3 pr-4">Año</th>
                <th class="text-right font-black py-3 pr-4">Respuestas</th>
                <th class="hidden sm:table-cell text-right font-black py-3 pr-4">Personas únicas</th>
                <th class="hidden sm:table-cell text-right font-black py-3 pr-4">Versiones</th>
                <th class="hidden md:table-cell text-left font-black py-3 pr-4 whitespace-nowrap">Primera respuesta</th>
                <th class="hidden md:table-cell text-left font-black py-3 whitespace-nowrap">Última respuesta</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="e in eventos.data" :key="e.id" :data-evento="e.id">
                <td class="py-3 pr-4 font-bold text-slate-800 max-w-[28rem]">
                  <Link :href="route('credential-flow.encuestas.eventos.show', { evento: e.id, ...paraDetalle })" class="hover:text-primary-vinotinto break-words inline-flex items-center gap-1.5">
                    {{ e.nombre }} <ArrowRight class="w-3.5 h-3.5 shrink-0" />
                  </Link>
                </td>
                <td class="py-3 pr-4 text-slate-600 font-semibold">{{ e.anio ?? "—" }}</td>
                <td class="py-3 pr-4 text-right font-black text-slate-800" data-respuestas>{{ n(e.respuestas) }}</td>
                <td class="hidden sm:table-cell py-3 pr-4 text-right font-semibold text-slate-600">{{ n(e.personas) }}</td>
                <td class="hidden sm:table-cell py-3 pr-4 text-right font-semibold text-slate-600">{{ n(e.versiones) }}</td>
                <td class="hidden md:table-cell py-3 pr-4 text-slate-500 font-semibold whitespace-nowrap">{{ fecha(e.primera) }}</td>
                <td class="hidden md:table-cell py-3 text-slate-500 font-semibold whitespace-nowrap">{{ fecha(e.ultima) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <div class="mt-4"><Paginador :paginador="eventos" unidad="eventos" /></div>

      <section v-if="sin_evento > 0" :class="[CAJA, 'mt-6 p-5 flex flex-wrap items-center justify-between gap-3']" data-sin-evento>
        <div>
          <h2 class="text-base font-black text-slate-900">Eventos no disponibles</h2>
          <p class="text-sm font-semibold text-slate-500">Respuestas de eventos que ya no existen en el sistema. Se conservan aparte y no se asignan a ningún otro evento.</p>
        </div>
        <div class="flex items-center gap-3">
          <Insignia :etiqueta="n(sin_evento) + ' respuestas'" tono="slate" />
          <Link :href="route('credential-flow.encuestas.eventos.show', { evento: 'sin-evento', ...paraDetalle })" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-[13px] font-bold text-slate-600 hover:bg-slate-50">
            Ver resultados <ArrowRight class="w-4 h-4" />
          </Link>
        </div>
      </section>
    </Sidebar>
  </AuthenticatedLayout>
</template>
