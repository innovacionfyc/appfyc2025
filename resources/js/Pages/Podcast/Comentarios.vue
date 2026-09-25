<script setup>
import { ref, computed, watch } from "vue";
import { Head, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import ConfirmacionesPop from "@/Components/Modales/Confirmaciones/ConfirmacionesPop.vue";
import { useConfirmationModal } from "@/Composables/useConfirmationModal";
import { usePodcastAdmin } from "@/Composables/usePodcastAdmin";
import {
  MessageSquare, Search, X, Check, Ban, Trash2, RotateCcw, ChevronLeft, ChevronRight, Mail, Clock, ExternalLink, ShieldCheck,
} from "lucide-vue-next";

const props = defineProps({
  comentarios: { type: Object, default: () => ({ data: [], pagina: 1, ultima: 1, total: 0, desde: null, hasta: null }) },
  episodios: { type: Array, default: () => [] },
  filtros: { type: Object, default: () => ({}) },
  stats: { type: Object, default: () => ({}) },
});

const { formatFecha, iniciales } = usePodcastAdmin();
const { confirmationState, openConfirmationModal, closeConfirmationModal, handleConfirm } = useConfirmationModal();

// ── Filtros (query params soportados por el backend) ──────────────────────────
const filtroEstado = ref(props.filtros.estado ?? "");
const filtroEpisodio = ref(props.filtros.episodio_id ?? "");
const busqueda = ref(props.filtros.q ?? "");

const hayFiltros = computed(() => !!(filtroEstado.value || filtroEpisodio.value || busqueda.value));
const enPapelera = computed(() => filtroEstado.value === "papelera");

const estadosFiltro = [
  { value: "", label: "Todos los estados" },
  { value: "pendiente", label: "Pendientes" },
  { value: "aprobado", label: "Aprobados" },
  { value: "rechazado", label: "Rechazados" },
  { value: "papelera", label: "Papelera" },
];

const parametros = (extra = {}) => {
  const params = { ...extra };
  if (filtroEstado.value) params.estado = filtroEstado.value;
  if (filtroEpisodio.value) params.episodio_id = filtroEpisodio.value;
  if (busqueda.value.trim()) params.q = busqueda.value.trim();
  return params;
};

const aplicarFiltros = (pagina = 1) => {
  router.get(route("podcast.comentarios.index"), parametros(pagina > 1 ? { page: pagina } : {}), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    only: ["comentarios", "filtros", "stats"],
  });
};

const limpiarFiltros = () => {
  filtroEstado.value = "";
  filtroEpisodio.value = "";
  busqueda.value = "";
  aplicarFiltros();
};

watch([filtroEstado, filtroEpisodio], () => aplicarFiltros());

let timerBusqueda = null;
watch(busqueda, () => {
  clearTimeout(timerBusqueda);
  timerBusqueda = setTimeout(() => aplicarFiltros(), 400);
});

// ── Stats header ──────────────────────────────────────────────────────────────
const headerStats = computed(() => [
  { label: "Pendientes", value: props.stats.pendientes ?? 0, icon: "pending", color: "text-amber-500", bg: "bg-amber-50" },
  { label: "Aprobados", value: props.stats.aprobados ?? 0, icon: "check_circle", color: "text-emerald-500", bg: "bg-emerald-50" },
  { label: "Rechazados", value: props.stats.rechazados ?? 0, icon: "block", color: "text-slate-500", bg: "bg-slate-100" },
  { label: "Papelera", value: props.stats.papelera ?? 0, icon: "delete", color: "text-rose-400", bg: "bg-rose-50" },
]);

// ── Acciones ──────────────────────────────────────────────────────────────────
const procesando = ref(false);

const opcionesRouter = {
  preserveScroll: true,
  preserveState: true,
  only: ["comentarios", "stats"],
  onStart: () => (procesando.value = true),
  onFinish: () => (procesando.value = false),
};

const resumen = (c) => `“${c.contenido.length > 90 ? c.contenido.slice(0, 90) + "…" : c.contenido}”`;

// Aprobar es reversible (se puede rechazar después): no requiere confirmación.
const aprobar = (c) => {
  if (procesando.value) return;
  router.post(route("podcast.comentarios.aprobar", c.id), {}, opcionesRouter);
};

const rechazar = (c) => {
  openConfirmationModal({
    title: `Rechazar comentario de ${c.nombre}`,
    icon: "block",
    confirmText: "Rechazar",
    message: `${resumen(c)} dejará de mostrarse en el podcast. Podrás aprobarlo más adelante si cambias de opinión.`,
    onConfirm: () => router.post(route("podcast.comentarios.rechazar", c.id), {}, opcionesRouter),
  });
};

const eliminar = (c) => {
  openConfirmationModal({
    title: `Enviar a la papelera`,
    icon: "delete",
    confirmText: "Enviar a la papelera",
    message: `El comentario de ${c.nombre} ${resumen(c)} saldrá del listado y del podcast. Podrás restaurarlo desde la papelera.`,
    onConfirm: () => router.delete(route("podcast.comentarios.destroy", c.id), opcionesRouter),
  });
};

const restaurar = (c) => {
  openConfirmationModal({
    title: `Restaurar comentario`,
    icon: "restore_from_trash",
    confirmText: "Restaurar",
    message: `El comentario de ${c.nombre} volverá al listado con su estado anterior (${c.estado_moderacion}). Solo se mostrará en el podcast si está aprobado.`,
    onConfirm: () => router.post(route("podcast.comentarios.restore", c.id), {}, opcionesRouter),
  });
};

// ── Presentación ──────────────────────────────────────────────────────────────
const estadoBadge = (estado) =>
  ({
    pendiente: "bg-amber-500/10 text-amber-600 ring-1 ring-amber-500/20",
    aprobado: "bg-emerald-500/10 text-emerald-600 ring-1 ring-emerald-500/20",
    rechazado: "bg-slate-500/10 text-slate-500 ring-1 ring-slate-400/20",
  })[estado] ?? "bg-blue-500/10 text-blue-600 ring-1 ring-blue-500/20";

const fechaHora = (iso) =>
  iso
    ? new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "short", year: "numeric", hour: "numeric", minute: "2-digit" }).format(new Date(iso))
    : "";

const lista = computed(() => props.comentarios.data ?? []);
const pagina = computed(() => props.comentarios.pagina ?? 1);
const ultima = computed(() => props.comentarios.ultima ?? 1);

const paginas = computed(() => {
  const total = ultima.value;
  const actual = pagina.value;
  const rango = [];
  for (let p = Math.max(1, actual - 2); p <= Math.min(total, actual + 2); p++) rango.push(p);
  return rango;
});
</script>

<template>
  <Head title="Podcast · Comentarios" />

  <AuthenticatedLayout>
    <Sidebar>
      <DashboardHeader
        title="Comentarios"
        subtitle="Moderación de los comentarios del podcast Íntimamente Hablando"
        :stats="headerStats"
      />

      <!-- Barra de control + filtros -->
      <div class="bg-slate-900 p-5 rounded-xl border border-white/5 shadow-xl flex flex-col xl:flex-row xl:items-center justify-between gap-5 mb-8">
        <div class="flex items-center gap-4 min-w-0">
          <div class="w-12 h-12 bg-podcast-acento/15 rounded-2xl flex items-center justify-center border border-podcast-acento/20 shrink-0">
            <MessageSquare class="w-5 h-5 text-podcast-acento" />
          </div>
          <div class="min-w-0">
            <h2 class="text-[18px] font-black text-white truncate">
              {{ enPapelera ? "Papelera" : "Bandeja de moderación" }}
            </h2>
            <p class="text-[14px] font-bold text-slate-500">
              {{ comentarios.total }} comentario{{ comentarios.total !== 1 ? "s" : "" }}
              <span v-if="hayFiltros" class="text-slate-600">· con filtros</span>
              <span v-if="stats.pendientes && !filtroEstado" class="text-amber-400">· {{ stats.pendientes }} por revisar</span>
            </p>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:flex xl:items-center gap-3 w-full xl:w-auto">
          <div class="relative sm:col-span-2 xl:w-64 group">
            <Search class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600 group-focus-within:text-podcast-acento transition-colors" />
            <input
              v-model="busqueda"
              type="text"
              aria-label="Buscar comentarios"
              placeholder="Buscar por nombre, correo o texto..."
              class="w-full pl-11 pr-4 py-3 bg-white/5 border-none rounded-2xl text-white placeholder:text-slate-600 focus:ring-1 focus:ring-podcast-acento/40 focus:bg-white/10 transition-all text-xs font-medium"
            />
          </div>

          <select
            v-model="filtroEstado"
            aria-label="Filtrar por estado"
            class="w-full xl:w-44 px-4 py-3 bg-white/5 border-none rounded-2xl text-xs font-medium focus:ring-1 focus:ring-podcast-acento/40 focus:bg-white/10 transition-all"
            :class="filtroEstado ? 'text-white' : 'text-slate-500'"
          >
            <option v-for="e in estadosFiltro" :key="e.value" :value="e.value" class="text-slate-900">{{ e.label }}</option>
          </select>

          <select
            v-model="filtroEpisodio"
            aria-label="Filtrar por episodio"
            class="w-full xl:w-56 px-4 py-3 bg-white/5 border-none rounded-2xl text-xs font-medium focus:ring-1 focus:ring-podcast-acento/40 focus:bg-white/10 transition-all"
            :class="filtroEpisodio ? 'text-white' : 'text-slate-500'"
          >
            <option value="" class="text-slate-900">Todos los episodios</option>
            <option v-for="e in episodios" :key="e.id" :value="e.id" class="text-slate-900">{{ e.codigo }} · {{ e.titulo }}</option>
          </select>

          <button
            v-if="hayFiltros"
            type="button"
            @click="limpiarFiltros"
            class="sm:col-span-2 xl:col-auto inline-flex items-center justify-center gap-1.5 px-4 py-3 rounded-2xl text-xs font-bold text-slate-300 bg-white/5 hover:bg-white/10 hover:text-white transition-all"
          >
            <X class="w-3.5 h-3.5" /> Limpiar filtros
          </button>
        </div>
      </div>

      <!-- Estado vacío -->
      <div v-if="lista.length === 0" class="text-center py-20 space-y-4 bg-white rounded-[2rem] border border-dashed border-slate-200">
        <div class="w-20 h-20 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto">
          <MessageSquare class="w-9 h-9 text-slate-400" />
        </div>
        <template v-if="hayFiltros">
          <p class="text-slate-600 font-bold text-lg">
            {{ enPapelera ? "La papelera está vacía" : "No encontramos comentarios con estos filtros" }}
          </p>
          <p class="text-slate-400 text-sm">Prueba con otra búsqueda o limpia los filtros.</p>
          <div class="pt-2 flex justify-center">
            <button type="button" @click="limpiarFiltros" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition-all">
              <X class="w-4 h-4" /> Limpiar filtros
            </button>
          </div>
        </template>
        <template v-else>
          <p class="text-slate-600 font-bold text-lg">Aún no hay comentarios</p>
          <p class="text-slate-400 text-sm max-w-md mx-auto">Cuando los oyentes comenten un episodio, aparecerán aquí para su revisión.</p>
        </template>
      </div>

      <!-- Lista -->
      <div v-else class="space-y-3">
        <article
          v-for="c in lista"
          :key="c.id"
          class="bg-white rounded-[1.75rem] border transition-all"
          :class="[
            c.deleted_at ? 'border-rose-100 opacity-80 hover:opacity-100' : 'border-slate-100 hover:border-slate-200 hover:shadow-md',
            c.estado_moderacion === 'pendiente' && !c.deleted_at ? 'ring-1 ring-amber-200' : '',
          ]"
        >
          <div class="p-5 sm:p-6 flex flex-col lg:flex-row lg:items-start gap-5">
            <!-- Autor -->
            <div class="flex items-start gap-3 lg:w-64 shrink-0 min-w-0">
              <span class="w-11 h-11 rounded-full bg-podcast-oscuro text-white text-xs font-black flex items-center justify-center shrink-0">
                {{ iniciales(c.nombre) }}
              </span>
              <div class="min-w-0">
                <p class="text-sm font-black text-slate-900 truncate">{{ c.nombre }}</p>
                <p v-if="c.correo" class="text-[11px] text-slate-500 truncate flex items-center gap-1" :title="c.correo">
                  <Mail class="w-3 h-3 shrink-0" /> {{ c.correo }}
                </p>
                <p v-else class="text-[11px] text-slate-400 italic">Sin correo</p>
                <p class="text-[11px] text-slate-400 flex items-center gap-1 mt-1">
                  <Clock class="w-3 h-3 shrink-0" /> {{ fechaHora(c.created_at) }}
                </p>
              </div>
            </div>

            <!-- Contenido -->
            <div class="flex-1 min-w-0">
              <div class="flex flex-wrap items-center gap-2 mb-2.5">
                <span class="px-2.5 py-1 rounded-full text-[9px] font-black uppercase tracking-widest" :class="estadoBadge(c.estado_moderacion)">
                  {{ c.estado_moderacion }}
                </span>
                <span v-if="c.deleted_at" class="px-2.5 py-1 rounded-full text-[9px] font-black uppercase tracking-widest bg-rose-500/10 text-rose-500 ring-1 ring-rose-500/20">
                  En papelera
                </span>
                <a
                  v-if="c.episodio"
                  :href="`/podcast/${c.episodio.slug}#comentarios`"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="inline-flex items-center gap-1 text-[10px] font-black uppercase tracking-widest text-slate-400 hover:text-podcast-oscuro transition-colors truncate max-w-full"
                  :title="c.episodio.titulo"
                >
                  {{ c.episodio.codigo }} · <span class="truncate normal-case tracking-normal font-bold">{{ c.episodio.titulo }}</span>
                  <ExternalLink class="w-3 h-3 shrink-0" />
                </a>
              </div>

              <!-- Interpolación escapada, saltos de línea respetados -->
              <p class="text-sm text-slate-700 leading-relaxed whitespace-pre-line break-words">{{ c.contenido }}</p>

              <p v-if="c.moderado_por || c.moderado_en" class="mt-3 text-[11px] text-slate-400 flex items-center gap-1">
                <ShieldCheck class="w-3 h-3 shrink-0" />
                Moderado por <span class="font-bold text-slate-500">{{ c.moderado_por ?? "—" }}</span>
                <span v-if="c.moderado_en">· {{ fechaHora(c.moderado_en) }}</span>
              </p>
            </div>

            <!-- Acciones -->
            <div class="flex flex-wrap lg:flex-col items-stretch gap-2 lg:w-40 shrink-0">
              <template v-if="c.deleted_at">
                <button type="button" :disabled="procesando" @click="restaurar(c)" class="inline-flex items-center justify-center gap-2 px-3 py-2.5 rounded-xl text-xs font-bold bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition-all disabled:opacity-50">
                  <RotateCcw class="w-4 h-4" /> Restaurar
                </button>
              </template>
              <template v-else>
                <button
                  v-if="c.estado_moderacion !== 'aprobado'"
                  type="button"
                  :disabled="procesando"
                  @click="aprobar(c)"
                  class="inline-flex items-center justify-center gap-2 px-3 py-2.5 rounded-xl text-xs font-bold bg-emerald-500 text-white hover:bg-emerald-600 shadow-sm transition-all disabled:opacity-50"
                >
                  <Check class="w-4 h-4" /> Aprobar
                </button>
                <button
                  v-if="c.estado_moderacion !== 'rechazado'"
                  type="button"
                  :disabled="procesando"
                  @click="rechazar(c)"
                  class="inline-flex items-center justify-center gap-2 px-3 py-2.5 rounded-xl text-xs font-bold bg-slate-100 text-slate-600 hover:bg-slate-200 transition-all disabled:opacity-50"
                >
                  <Ban class="w-4 h-4" /> Rechazar
                </button>
                <button type="button" :disabled="procesando" @click="eliminar(c)" class="inline-flex items-center justify-center gap-2 px-3 py-2.5 rounded-xl text-xs font-bold bg-rose-50 text-rose-500 hover:bg-rose-100 transition-all disabled:opacity-50">
                  <Trash2 class="w-4 h-4" /> Papelera
                </button>
              </template>
            </div>
          </div>
        </article>

        <!-- Paginación -->
        <nav v-if="ultima > 1" class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-4" aria-label="Paginación de comentarios">
          <p class="text-xs font-semibold text-slate-500 tabular-nums">
            Mostrando {{ comentarios.desde }}–{{ comentarios.hasta }} de {{ comentarios.total }}
          </p>
          <div class="flex items-center gap-1.5">
            <button type="button" :disabled="pagina <= 1 || procesando" @click="aplicarFiltros(pagina - 1)" class="p-2.5 rounded-xl bg-white border border-slate-200 text-slate-500 hover:text-podcast-oscuro hover:border-slate-300 disabled:opacity-40 transition-all" aria-label="Página anterior">
              <ChevronLeft class="w-4 h-4" />
            </button>
            <button
              v-for="p in paginas"
              :key="p"
              type="button"
              :disabled="procesando"
              :aria-current="p === pagina ? 'page' : undefined"
              @click="p !== pagina && aplicarFiltros(p)"
              class="min-w-[2.5rem] px-3 py-2 rounded-xl text-xs font-black tabular-nums transition-all"
              :class="p === pagina ? 'bg-podcast-oscuro text-white' : 'bg-white border border-slate-200 text-slate-600 hover:border-slate-300'"
            >
              {{ p }}
            </button>
            <button type="button" :disabled="pagina >= ultima || procesando" @click="aplicarFiltros(pagina + 1)" class="p-2.5 rounded-xl bg-white border border-slate-200 text-slate-500 hover:text-podcast-oscuro hover:border-slate-300 disabled:opacity-40 transition-all" aria-label="Página siguiente">
              <ChevronRight class="w-4 h-4" />
            </button>
          </div>
        </nav>
      </div>
    </Sidebar>
  </AuthenticatedLayout>

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
