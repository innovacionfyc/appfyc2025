<script setup>
import { computed } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import { Plus, Users, LayoutTemplate, CalendarDays, ChevronLeft, ChevronRight, ArrowRight } from "lucide-vue-next";
import { router } from "@inertiajs/vue3";

const props = defineProps({
  lotes: { type: Object, required: true }, // paginador de Laravel: { data, current_page, last_page, total, prev_page_url, next_page_url }
});

const headerStats = computed(() => [
  { label: "Lotes", value: props.lotes.total ?? 0, icon: "groups", color: "text-primary-vinotinto", bg: "bg-primary-vinotinto/10" },
]);

const formatFecha = (iso) => {
  if (!iso) return "—";
  const d = new Date(iso);
  if (isNaN(d.getTime())) return "—";
  return new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "short", year: "numeric", timeZone: "America/Bogota" }).format(d);
};

const ir = (url) => url && router.get(url, {}, { preserveScroll: false });
</script>

<template>
  <Head title="Credential Flow · Lotes" />

  <AuthenticatedLayout>
    <Sidebar>
      <nav class="mb-3 flex items-center gap-2 text-[12px] font-bold text-slate-400" aria-label="Ruta de navegación">
        <Link :href="route('credential-flow.index')" class="hover:text-primary-vinotinto transition-colors">Credential Flow</Link>
        <span>/</span>
        <span class="text-slate-600">Lotes</span>
      </nav>

      <DashboardHeader title="Lotes de participantes" subtitle="Listas de asistentes importadas, listas para generar credenciales" :stats="headerStats">
        <template #actions>
          <Link
            :href="route('credential-flow.lotes.nuevo')"
            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90 transition-all"
            data-accion="nuevo-lote"
          >
            <Plus class="w-4 h-4" />
            Nuevo lote
          </Link>
        </template>
      </DashboardHeader>

      <div v-if="lotes.data.length === 0" class="mt-8 text-center py-20 space-y-4 bg-white rounded-[2rem] border border-dashed border-slate-200" data-sin-lotes>
        <div class="w-20 h-20 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto">
          <Users class="w-9 h-9 text-slate-400" />
        </div>
        <p class="text-slate-600 font-bold text-lg">Aún no hay lotes</p>
        <p class="text-slate-400 text-sm max-w-md mx-auto px-4">
          Un lote agrupa a los participantes de un evento con una plantilla. Importa el primero desde un Excel o CSV.
        </p>
        <div class="pt-2 flex justify-center">
          <Link :href="route('credential-flow.lotes.nuevo')" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90 transition-all">
            Crear primer lote
          </Link>
        </div>
      </div>

      <div v-else class="mt-8 space-y-4">
        <div class="overflow-x-auto bg-white rounded-[2rem] border border-slate-100 shadow-sm">
          <table class="min-w-full text-sm" data-tabla-lotes>
            <thead class="text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
              <tr>
                <th class="text-left font-black px-6 py-4">Lote</th>
                <th class="text-left font-black px-4 py-4">Plantilla</th>
                <th class="text-left font-black px-4 py-4">Evento</th>
                <th class="text-left font-black px-4 py-4">Fecha</th>
                <th class="text-right font-black px-4 py-4">Participantes</th>
                <th class="text-left font-black px-4 py-4">Creado</th>
                <th class="px-6 py-4"><span class="sr-only">Acciones</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="l in lotes.data" :key="l.id" class="hover:bg-slate-50/60 transition-colors">
                <td class="px-6 py-4 font-extrabold text-slate-900 break-words max-w-xs">{{ l.nombre }}</td>
                <td class="px-4 py-4 text-slate-600 font-semibold">
                  <span class="inline-flex items-center gap-1.5"><LayoutTemplate class="w-3.5 h-3.5 text-slate-400" />{{ l.plantilla ?? "—" }}</span>
                </td>
                <td class="px-4 py-4 text-slate-600 font-semibold break-words max-w-xs">{{ l.evento ?? "—" }}</td>
                <td class="px-4 py-4 text-slate-600 font-semibold">{{ l.fecha ?? "—" }}</td>
                <td class="px-4 py-4 text-right font-black text-slate-900">{{ l.participantes }}</td>
                <td class="px-4 py-4 text-slate-500 font-semibold whitespace-nowrap">
                  <span class="inline-flex items-center gap-1.5"><CalendarDays class="w-3.5 h-3.5 text-slate-400" />{{ formatFecha(l.created_at) }}</span>
                </td>
                <td class="px-6 py-4 text-right">
                  <Link :href="route('credential-flow.lotes.show', l.id)" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-primary-vinotinto/10 text-[12px] font-bold text-primary-vinotinto hover:bg-primary-vinotinto/20 transition-all" :aria-label="`Abrir el lote ${l.nombre}`">
                    Abrir <ArrowRight class="w-3.5 h-3.5" />
                  </Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="lotes.last_page > 1" class="flex items-center justify-between px-2" data-paginacion>
          <button type="button" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-40" :disabled="!lotes.prev_page_url" @click="ir(lotes.prev_page_url)">
            <ChevronLeft class="w-4 h-4" /> Anterior
          </button>
          <span class="text-[13px] font-bold text-slate-500">Página {{ lotes.current_page }} de {{ lotes.last_page }}</span>
          <button type="button" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-40" :disabled="!lotes.next_page_url" @click="ir(lotes.next_page_url)">
            Siguiente <ChevronRight class="w-4 h-4" />
          </button>
        </div>
      </div>
    </Sidebar>
  </AuthenticatedLayout>
</template>
