<script setup>
import { ref, computed, watch, onBeforeUnmount } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import ConfirmacionesPop from "@/Components/Modales/Confirmaciones/ConfirmacionesPop.vue";
import ParticipanteModal from "@/Components/CredentialFlow/Lotes/ParticipanteModal.vue";
import LoteEditModal from "@/Components/CredentialFlow/Lotes/LoteEditModal.vue";
import { useConfirmationModal } from "@/Composables/useConfirmationModal";
import { useDescargaPdf } from "@/Composables/CredentialFlow/useDescargaPdf";
import {
  ArrowLeft, Search, UserPlus, SquarePen, Trash2, FileDown, Loader2, CircleAlert, ChevronLeft, ChevronRight, LayoutTemplate, FileSpreadsheet, CalendarDays,
} from "lucide-vue-next";

const props = defineProps({
  lote: { type: Object, required: true },
  participantes: { type: Object, required: true }, // paginador de Laravel
  filtros: { type: Object, default: () => ({ q: "" }) },
});

const { confirmationState, openConfirmationModal, closeConfirmationModal, handleConfirm } = useConfirmationModal();
const { generando, error: errorPdf, descargar } = useDescargaPdf();

const procesando = ref(false);
const modalParticipante = ref({ abierto: false, participante: null });
const modalLote = ref(false);

const headerStats = computed(() => [
  { label: "Participantes", value: props.lote.total, icon: "groups", color: "text-primary-vinotinto", bg: "bg-primary-vinotinto/10" },
]);

const formatFecha = (iso) => {
  if (!iso) return "—";
  const d = new Date(iso);
  if (isNaN(d.getTime())) return "—";
  return new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "short", year: "numeric", hour: "numeric", minute: "2-digit", timeZone: "America/Bogota" }).format(d);
};

// ── Búsqueda en el servidor con debounce ──────────────────────────────────────
const q = ref(props.filtros.q ?? "");
let temporizador = null;
watch(q, (valor) => {
  clearTimeout(temporizador);
  temporizador = setTimeout(() => {
    if ((valor ?? "") === (props.filtros.q ?? "")) return;
    router.get(route("credential-flow.lotes.show", props.lote.id), valor ? { q: valor } : {}, { preserveState: true, preserveScroll: true, replace: true, only: ["participantes", "filtros"] });
  }, 350);
});
onBeforeUnmount(() => clearTimeout(temporizador));

const irA = (url) => url && router.get(url, {}, { preserveState: true, preserveScroll: true, only: ["participantes", "filtros"] });

// ── Acciones ──────────────────────────────────────────────────────────────────
const abrirAlta = () => (modalParticipante.value = { abierto: true, participante: null });
const abrirEdicion = (p) => (modalParticipante.value = { abierto: true, participante: p });

const eliminarParticipante = (p) => {
  openConfirmationModal({
    title: "Eliminar participante",
    icon: "delete",
    confirmText: "Eliminar",
    message: `Se eliminará a "${p.nombre_completo}" del lote. Podrás volver a agregarlo con el mismo documento.`,
    onConfirm: () =>
      router.delete(route("credential-flow.participantes.destroy", [props.lote.id, p.id]), {
        preserveScroll: true,
        onStart: () => (procesando.value = true),
        onFinish: () => (procesando.value = false),
      }),
  });
};

const eliminarLote = () => {
  openConfirmationModal({
    title: "Eliminar lote",
    icon: "delete",
    confirmText: "Eliminar",
    message: `El lote "${props.lote.nombre}" y sus ${props.lote.total} participantes dejarán de mostrarse. La plantilla y su PDF no se tocan.`,
    onConfirm: () =>
      router.delete(route("credential-flow.lotes.destroy", props.lote.id), {
        onStart: () => (procesando.value = true),
        onFinish: () => (procesando.value = false),
      }),
  });
};

const generarPdf = (p) =>
  descargar(p.id, route("credential-flow.participantes.pdf", [props.lote.id, p.id]), `credencial-${p.id}.pdf`);
</script>

<template>
  <Head :title="`Credential Flow · ${lote.nombre}`" />

  <AuthenticatedLayout>
    <Sidebar>
      <nav class="mb-3 flex flex-wrap items-center gap-2 text-[12px] font-bold text-slate-400" aria-label="Ruta de navegación">
        <Link :href="route('credential-flow.index')" class="hover:text-primary-vinotinto transition-colors">Credential Flow</Link>
        <span>/</span>
        <Link :href="route('credential-flow.lotes.index')" class="hover:text-primary-vinotinto transition-colors">Lotes</Link>
        <span>/</span>
        <span class="text-slate-600">{{ lote.nombre }}</span>
      </nav>

      <DashboardHeader :title="lote.nombre" :subtitle="lote.descripcion || 'Lote de participantes'" :stats="headerStats">
        <template #actions>
          <Link :href="route('credential-flow.lotes.index')" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 transition-all">
            <ArrowLeft class="w-4 h-4" />
            Volver a lotes
          </Link>
        </template>
      </DashboardHeader>

      <!-- Datos del lote -->
      <section class="mt-8 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6 sm:p-8" data-datos-lote>
        <div class="flex flex-wrap items-start justify-between gap-4">
          <dl class="grid gap-x-10 gap-y-3 sm:grid-cols-2 xl:grid-cols-3 text-sm min-w-0 flex-1">
            <div class="sm:col-span-2 xl:col-span-3">
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Evento</dt>
              <dd class="font-extrabold text-slate-900 break-words" data-dato="evento">{{ lote.datos_comunes.evento }}</dd>
            </div>
            <div>
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Fecha</dt>
              <dd class="font-bold text-slate-700 break-words" data-dato="fecha">{{ lote.datos_comunes.fecha }}</dd>
            </div>
            <div>
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Intensidad horaria</dt>
              <dd class="font-bold text-slate-700" data-dato="intensidad">{{ lote.datos_comunes.intensidad_horaria }}</dd>
            </div>
            <div>
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Plantilla</dt>
              <dd class="font-bold text-slate-700 inline-flex items-center gap-1.5">
                <LayoutTemplate class="w-3.5 h-3.5 text-slate-400" />
                <Link v-if="lote.plantilla" :href="route('credential-flow.plantillas.editor', lote.plantilla.id)" class="hover:text-primary-vinotinto underline-offset-2 hover:underline">{{ lote.plantilla.nombre }}</Link>
                <span v-else>—</span>
              </dd>
            </div>
            <div v-if="lote.archivo_nombre">
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Archivo importado</dt>
              <dd class="font-bold text-slate-700 inline-flex items-center gap-1.5 break-all"><FileSpreadsheet class="w-3.5 h-3.5 text-slate-400 shrink-0" />{{ lote.archivo_nombre }}</dd>
            </div>
            <div>
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Creado</dt>
              <dd class="font-bold text-slate-700 inline-flex items-center gap-1.5"><CalendarDays class="w-3.5 h-3.5 text-slate-400" />{{ formatFecha(lote.created_at) }}</dd>
            </div>
          </dl>

          <div class="flex flex-wrap gap-2">
            <button type="button" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-slate-100 text-[13px] font-bold text-slate-600 hover:bg-slate-200 transition-all" data-accion="editar-lote" @click="modalLote = true">
              <SquarePen class="w-4 h-4" /> Editar lote
            </button>
            <button type="button" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-rose-50 text-[13px] font-bold text-rose-500 hover:bg-rose-100 transition-all" data-accion="eliminar-lote" @click="eliminarLote">
              <Trash2 class="w-4 h-4" /> Eliminar lote
            </button>
          </div>
        </div>
      </section>

      <!-- Barra de búsqueda y alta -->
      <div class="mt-6 bg-slate-900 p-5 rounded-xl border border-white/5 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="relative w-full md:w-96 group">
          <Search class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600 group-focus-within:text-secondary-vinotinto2 transition-colors" />
          <input v-model="q" type="text" placeholder="Buscar por nombre o documento..." aria-label="Buscar participantes" data-buscar class="w-full pl-11 pr-4 py-3 bg-white/5 border-none rounded-2xl text-white placeholder:text-slate-600 focus:ring-1 focus:ring-secondary-vinotinto2/40 focus:bg-white/10 transition-all text-xs font-medium" />
        </div>
        <button type="button" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90 transition-all" data-accion="agregar-participante" @click="abrirAlta">
          <UserPlus class="w-4 h-4" /> Agregar participante
        </button>
      </div>

      <div v-if="errorPdf" class="mt-4 flex items-start gap-3 px-4 py-3 rounded-2xl bg-red-50 text-red-700 text-[13px] font-semibold" role="alert" data-aviso="error-pdf" :data-codigo="errorPdf.code">
        <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" />
        <span class="flex-1">No se pudo generar el PDF: {{ errorPdf.message }}</span>
        <button type="button" class="text-[12px] underline" @click="errorPdf = null">Cerrar</button>
      </div>

      <!-- Tabla -->
      <div v-if="participantes.data.length === 0" class="mt-6 text-center py-16 bg-white rounded-[2rem] border border-dashed border-slate-200" data-sin-participantes>
        <p class="text-slate-600 font-bold text-lg">{{ filtros.q ? "Sin resultados" : "Este lote no tiene participantes" }}</p>
        <p class="text-slate-400 text-sm mt-1">{{ filtros.q ? `Nadie coincide con "${filtros.q}".` : "Agrega uno manualmente." }}</p>
      </div>

      <div v-else class="mt-6 space-y-4">
        <div class="overflow-x-auto bg-white rounded-[2rem] border border-slate-100 shadow-sm">
          <table class="min-w-full text-sm" data-tabla-participantes>
            <thead class="text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
              <tr>
                <th class="text-left font-black px-6 py-4">Nombre completo</th>
                <th class="text-left font-black px-4 py-4">Documento</th>
                <th class="text-left font-black px-4 py-4 w-28">Fila origen</th>
                <th class="px-6 py-4 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="p in participantes.data" :key="p.id" class="hover:bg-slate-50/60 transition-colors" :data-participante="p.id">
                <td class="px-6 py-3 font-extrabold text-slate-900 break-words">{{ p.nombre_completo }}</td>
                <td class="px-4 py-3 font-semibold text-slate-600 break-words">{{ p.documento }}</td>
                <td class="px-4 py-3 font-mono text-[12px] text-slate-400">{{ p.fila_origen ?? "manual" }}</td>
                <td class="px-6 py-3">
                  <div class="flex items-center justify-end gap-1.5">
                    <button type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-primary-vinotinto/10 text-[12px] font-bold text-primary-vinotinto hover:bg-primary-vinotinto/20 transition-all disabled:opacity-50" :disabled="generando !== null" :aria-label="`Generar PDF de ${p.nombre_completo}`" data-accion="pdf" @click="generarPdf(p)">
                      <Loader2 v-if="generando === p.id" class="w-3.5 h-3.5 animate-spin" />
                      <FileDown v-else class="w-3.5 h-3.5" />
                      {{ generando === p.id ? "Generando…" : "Generar PDF" }}
                    </button>
                    <button type="button" class="p-2 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-all" title="Editar" :aria-label="`Editar a ${p.nombre_completo}`" data-accion="editar" @click="abrirEdicion(p)">
                      <SquarePen class="w-4 h-4" />
                    </button>
                    <button type="button" class="p-2 rounded-xl text-rose-400 hover:text-rose-600 hover:bg-rose-50 transition-all" title="Eliminar" :aria-label="`Eliminar a ${p.nombre_completo}`" data-accion="eliminar" @click="eliminarParticipante(p)">
                      <Trash2 class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="participantes.last_page > 1" class="flex items-center justify-between px-2" data-paginacion>
          <button type="button" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-40" :disabled="!participantes.prev_page_url" @click="irA(participantes.prev_page_url)">
            <ChevronLeft class="w-4 h-4" /> Anterior
          </button>
          <span class="text-[13px] font-bold text-slate-500">Página {{ participantes.current_page }} de {{ participantes.last_page }} · {{ participantes.total }} resultados</span>
          <button type="button" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-40" :disabled="!participantes.next_page_url" @click="irA(participantes.next_page_url)">
            Siguiente <ChevronRight class="w-4 h-4" />
          </button>
        </div>
      </div>
    </Sidebar>
  </AuthenticatedLayout>

  <ParticipanteModal :show="modalParticipante.abierto" :lote-id="lote.id" :participante="modalParticipante.participante" @close="modalParticipante.abierto = false" />
  <LoteEditModal :show="modalLote" :lote="lote" @close="modalLote = false" />

  <ConfirmacionesPop
    :is-open="confirmationState.isOpen"
    :title="confirmationState.title"
    :message="confirmationState.message"
    :icon="confirmationState.icon"
    :confirm-text="confirmationState.confirmText"
    :is-loading="procesando"
    @close="closeConfirmationModal"
    @confirm="handleConfirm"
  />
</template>
