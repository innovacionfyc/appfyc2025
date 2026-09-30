<script setup>
import { ref, watch, onMounted } from "vue";
import axios from "axios";
import { FileDown, ChevronLeft, ChevronRight } from "lucide-vue-next";

// Historial de emisiones del lote (más recientes primero). Se recarga cuando cambia `version`.
const props = defineProps({
  loteId: { type: Number, required: true },
  version: { type: Number, default: 0 },
  descargando: { type: [Number, String], default: null },
});
const emit = defineEmits(["descargar"]);

const filas = ref([]);
const pagina = ref(1);
const paginas = ref(1);
const total = ref(0);
const cargando = ref(false);
const error = ref(false);

const formatFecha = (iso) => {
  if (!iso) return "—";
  const d = new Date(iso);
  return isNaN(d.getTime()) ? "—" : new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "short", year: "numeric", hour: "numeric", minute: "2-digit", timeZone: "America/Bogota" }).format(d);
};

async function cargar(p = pagina.value) {
  cargando.value = true;
  error.value = false;
  try {
    const { data } = await axios.get(route("credential-flow.lotes.emisiones", props.loteId), { params: { page: p } });
    filas.value = data.emisiones;
    pagina.value = data.pagina;
    paginas.value = data.paginas;
    total.value = data.total;
  } catch {
    error.value = true;
  } finally {
    cargando.value = false;
  }
}

onMounted(() => cargar(1));
watch(() => props.version, () => cargar(1));
</script>

<template>
  <section class="mt-6 bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden" data-historial-emisiones>
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
      <h3 class="text-sm font-black text-slate-900">Historial de emisiones</h3>
      <span class="text-[12px] font-bold text-slate-400">{{ total }} registro(s)</span>
    </div>

    <p v-if="error" class="px-6 py-6 text-sm font-semibold text-red-600" role="alert">No se pudo cargar el historial.</p>
    <p v-else-if="!cargando && filas.length === 0" class="px-6 py-8 text-sm font-medium text-slate-400 text-center" data-historial-vacio>Todavía no se ha emitido ninguna credencial de este lote.</p>

    <div v-else class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
          <tr>
            <th class="text-left font-black px-6 py-3">Participante</th>
            <th class="text-left font-black px-4 py-3">Versión</th>
            <th class="text-left font-black px-4 py-3">Estado</th>
            <th class="text-left font-black px-4 py-3">Emitida</th>
            <th class="text-left font-black px-4 py-3">Revocación</th>
            <th class="px-6 py-3 text-right">PDF</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="e in filas" :key="e.id" :data-emision="e.id" :data-estado="e.estado">
            <td class="px-6 py-3 font-bold text-slate-800 break-words">{{ e.participante }}</td>
            <td class="px-4 py-3 font-mono text-[12px] text-slate-500">v{{ e.version }}<span class="ml-2 px-1.5 py-0.5 rounded bg-slate-100 text-[10px] font-black text-slate-500 font-sans" :data-qr="e.con_qr ? 1 : 0">{{ e.con_qr ? "Con QR" : "Sin QR" }}</span></td>
            <td class="px-4 py-3">
              <span class="px-2.5 py-1 rounded-full text-[11px] font-black" :class="e.estado === 'emitida' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-600'">{{ e.estado === "emitida" ? "Vigente" : "Revocada" }}</span>
            </td>
            <td class="px-4 py-3 text-[12px] text-slate-500">{{ formatFecha(e.emitido_at) }}<br /><span class="text-slate-400 break-all">{{ e.emitido_por ?? "" }}</span></td>
            <td class="px-4 py-3 text-[12px] text-slate-500 max-w-xs break-words">
              <template v-if="e.estado !== 'emitida'">{{ formatFecha(e.revocado_at) }}<br /><span class="text-slate-400">{{ e.motivo_revocacion }}</span></template>
              <template v-else>—</template>
            </td>
            <td class="px-6 py-3 text-right">
              <button type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-slate-100 text-[12px] font-bold text-slate-600 hover:bg-slate-200 disabled:opacity-50" :disabled="descargando !== null" :aria-label="`Descargar la versión ${e.version} de ${e.participante}`" data-accion="descargar-historial" @click="emit('descargar', e)">
                <FileDown class="w-3.5 h-3.5" /> Descargar
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="paginas > 1" class="px-6 py-3 border-t border-slate-100 flex items-center justify-between">
      <button type="button" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-slate-100 text-[12px] font-bold text-slate-600 disabled:opacity-40" :disabled="pagina <= 1 || cargando" @click="cargar(pagina - 1)"><ChevronLeft class="w-4 h-4" /> Anterior</button>
      <span class="text-[12px] font-bold text-slate-500">Página {{ pagina }} de {{ paginas }}</span>
      <button type="button" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-slate-100 text-[12px] font-bold text-slate-600 disabled:opacity-40" :disabled="pagina >= paginas || cargando" @click="cargar(pagina + 1)">Siguiente <ChevronRight class="w-4 h-4" /></button>
    </div>
  </section>
</template>
