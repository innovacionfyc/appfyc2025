<script setup>
import { computed } from "vue";
import { Head, usePage } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import NavEncuestas from "@/Components/CredentialFlow/Encuestas/NavEncuestas.vue";
import FiltrosEncuestas from "@/Components/CredentialFlow/Encuestas/FiltrosEncuestas.vue";
import { FileSpreadsheet, FileText, AlertTriangle } from "lucide-vue-next";

// Informes y exportaciones: XLSX (4 hojas) y CSV (3 conjuntos). Los archivos reflejan EXACTAMENTE los filtros de esta pantalla y solo contienen agregados.
const props = defineProps({
  filtros: { type: Object, required: true },
  opciones: { type: Object, required: true },
  resumen: { type: Object, required: true },
  filas_distribucion: { type: Number, default: 0 },
  limite_filas: { type: Number, default: 100000 },
});

const n = (v) => (v ?? 0).toLocaleString("es-CO");
const CAJA = "bg-white rounded-[2rem] border border-slate-100 shadow-sm";
const activos = computed(() => Object.fromEntries(Object.entries(props.filtros).filter(([, v]) => v !== null && v !== "")));
const excede = computed(() => props.filas_distribucion > props.limite_filas);
const errores = computed(() => usePage().props.errors ?? {});
const csv = computed(() => [
  { id: "resumen", titulo: "Resumen por evento", detalle: "Una fila por evento: respuestas, participaciones únicas, versiones y fechas." },
  { id: "preguntas", titulo: "Resultados por pregunta", detalle: "Una fila por versión y pregunta: respondidas, omitidas y porcentaje." },
  { id: "distribucion", titulo: "Distribución de opciones", detalle: "Evento, versión, pregunta y opción con respuestas y porcentaje sobre las respondidas." },
]);
</script>

<template>
  <Head title="Credential Flow · Informes y exportaciones" />

  <AuthenticatedLayout>
    <Sidebar>
      <NavEncuestas actual="informes" :filtros="filtros" :migas="[{ texto: 'Informes y exportaciones' }]" />

      <DashboardHeader title="Informes y exportaciones" subtitle="Archivos de Excel y CSV con los resultados de las encuestas, según los filtros elegidos." />

      <div class="mt-6">
        <FiltrosEncuestas :filtros="filtros" :opciones="opciones" :url="() => route('credential-flow.encuestas.informes')" />
      </div>

      <div v-if="errores.exportacion" class="mb-6 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-800" role="alert" data-error-exportacion>
        <AlertTriangle class="w-5 h-5 shrink-0" /> {{ errores.exportacion }}
      </div>

      <section :class="[CAJA, 'p-6']" data-contenido-informe>
        <h2 class="text-lg font-black text-slate-900">Contenido del informe</h2>
        <p class="text-sm font-semibold text-slate-500 mb-4">Con los filtros actuales el informe incluye:</p>
        <ul class="grid grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
          <li><p class="text-[11px] uppercase tracking-wide font-black text-slate-400">Respuestas</p><p class="text-2xl font-black text-slate-900" data-total="respuestas">{{ n(resumen.respuestas) }}</p></li>
          <li><p class="text-[11px] uppercase tracking-wide font-black text-slate-400">Participaciones únicas</p><p class="text-2xl font-black text-slate-900">{{ n(resumen.participaciones_unicas) }}</p></li>
          <li><p class="text-[11px] uppercase tracking-wide font-black text-slate-400">Eventos evaluados</p><p class="text-2xl font-black text-slate-900">{{ n(resumen.eventos_evaluados) }}</p></li>
          <li><p class="text-[11px] uppercase tracking-wide font-black text-slate-400">Filas de distribución</p><p class="text-2xl font-black text-slate-900">{{ n(filas_distribucion) }}</p></li>
        </ul>
        <p v-if="excede" class="mt-4 text-sm font-bold text-amber-700" data-excede>La distribución supera el límite de {{ n(limite_filas) }} filas: reducir el alcance con los filtros para exportar.</p>
      </section>

      <section :class="[CAJA, 'mt-6 p-6']" data-exportar-xlsx>
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div class="flex items-start gap-4">
            <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-700 flex items-center justify-center shrink-0"><FileSpreadsheet class="w-6 h-6" /></div>
            <div>
              <h2 class="text-lg font-black text-slate-900">Excel (XLSX)</h2>
              <p class="text-sm font-semibold text-slate-500 max-w-2xl">Cuatro hojas: información y filtros aplicados, resumen por evento, resultados por pregunta y versión, y distribución de opciones con porcentajes.</p>
            </div>
          </div>
          <a v-if="!excede && resumen.respuestas > 0" :href="route('credential-flow.encuestas.exportar.xlsx', activos)" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90" data-descargar="xlsx">Descargar Excel</a>
          <span v-else class="text-[13px] font-bold text-slate-400" data-descarga-no-disponible>{{ resumen.respuestas === 0 ? "Sin respuestas para exportar" : "Descarga no disponible" }}</span>
        </div>
      </section>

      <section :class="[CAJA, 'mt-6 p-6']" data-exportar-csv>
        <div class="flex items-start gap-4 mb-4">
          <div class="w-12 h-12 rounded-2xl bg-sky-50 text-sky-700 flex items-center justify-center shrink-0"><FileText class="w-6 h-6" /></div>
          <div>
            <h2 class="text-lg font-black text-slate-900">CSV</h2>
            <p class="text-sm font-semibold text-slate-500">UTF-8 con separador «;», compatible con Excel en español. Tres conjuntos independientes.</p>
          </div>
        </div>
        <ul class="divide-y divide-slate-100">
          <li v-for="c in csv" :key="c.id" class="py-3 flex flex-wrap items-center justify-between gap-3" :data-csv="c.id">
            <div><p class="font-bold text-slate-800">{{ c.titulo }}</p><p class="text-[12px] font-semibold text-slate-500">{{ c.detalle }}</p></div>
            <a v-if="!excede && resumen.respuestas > 0" :href="route('credential-flow.encuestas.exportar.csv', { ...activos, conjunto: c.id })" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-[13px] font-bold text-slate-600 hover:bg-slate-50">Descargar CSV</a>
          </li>
        </ul>
      </section>

      <section :class="[CAJA, 'mt-6 p-6']" data-notas>
        <h2 class="text-base font-black text-slate-900 mb-2">Cómo leer los datos</h2>
        <ul class="space-y-1.5 text-sm font-semibold text-slate-600 list-disc pl-5">
          <li><b>Respuestas</b> son los registros conservados; las <b>participaciones únicas</b> cuentan una persona una sola vez por evento.</li>
          <li>Los porcentajes se calculan sobre las respuestas con contenido de cada pregunta; la base figura junto a cada porcentaje.</li>
          <li>La participación sobre personas elegibles no se calcula: no existe un denominador verificable para todos los eventos.</li>
          <li>Las respuestas de eventos que ya no existen aparecen en una categoría propia y no se asignan a otro evento.</li>
          <li>Los comentarios (texto libre) no se incluyen en los archivos; solo se consultan en la vista restringida del super-admin.</li>
        </ul>
      </section>
    </Sidebar>
  </AuthenticatedLayout>
</template>
