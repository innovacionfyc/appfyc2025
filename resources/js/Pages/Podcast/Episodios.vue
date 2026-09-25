<script setup>
import { ref, computed, watch } from "vue";
import { Head, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";
import ConfirmacionesPop from "@/Components/Modales/Confirmaciones/ConfirmacionesPop.vue";
import EpisodioModal from "@/Components/Podcast/Admin/EpisodioModal.vue";
import { useConfirmationModal } from "@/Composables/useConfirmationModal";
import { usePodcastAdmin } from "@/Composables/usePodcastAdmin";
import {
  ListVideo, Search, Edit3, Trash2, RotateCcw, ChevronDown, Mic, Star, MessageSquare, Heart, Calendar, Play, X, ExternalLink,
} from "lucide-vue-next";

const props = defineProps({
  episodios: { type: Array, default: () => [] },
  eliminados: { type: Array, default: () => [] },
  temporadas: { type: Array, default: () => [] },
  estados: { type: Array, default: () => [] },
  filtros: { type: Object, default: () => ({}) },
  stats: { type: Object, default: () => ({}) },
});

const { estadoClass, formatFecha, formatDuracion, iniciales } = usePodcastAdmin();
const { confirmationState, openConfirmationModal, closeConfirmationModal, handleConfirm } = useConfirmationModal();

// ── Modal ─────────────────────────────────────────────────────────────────────
const isModalOpen = ref(false);
const modalMode = ref("create");
const selected = ref(null);

const openModal = (episodio = null, mode = "create") => {
  modalMode.value = mode;
  selected.value = episodio;
  isModalOpen.value = true;
};

// Último número usado por temporada (incluye papelera: el UNIQUE de BD también la cuenta)
const ultimosNumeros = computed(() => {
  const mapa = {};
  [...props.episodios, ...props.eliminados].forEach((e) => {
    mapa[e.temporada_id] = Math.max(mapa[e.temporada_id] ?? 0, Number(e.numero) || 0);
  });
  return mapa;
});

// ── Filtros (query params soportados por el backend) ──────────────────────────
const filtroTemporada = ref(props.filtros.temporada_id ?? "");
const filtroEstado = ref(props.filtros.estado_id ?? "");
const busqueda = ref(props.filtros.q ?? "");

const hayFiltros = computed(() => !!(filtroTemporada.value || filtroEstado.value || busqueda.value));

const aplicarFiltros = () => {
  const params = {};
  if (filtroTemporada.value) params.temporada_id = filtroTemporada.value;
  if (filtroEstado.value) params.estado_id = filtroEstado.value;
  if (busqueda.value.trim()) params.q = busqueda.value.trim();

  router.get(route("podcast.episodios.index"), params, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    only: ["episodios", "filtros", "stats"],
  });
};

const limpiarFiltros = () => {
  filtroTemporada.value = "";
  filtroEstado.value = "";
  busqueda.value = "";
  aplicarFiltros();
};

watch([filtroTemporada, filtroEstado], aplicarFiltros);

let timerBusqueda = null;
watch(busqueda, () => {
  clearTimeout(timerBusqueda);
  timerBusqueda = setTimeout(aplicarFiltros, 400);
});

// ── Stats header ──────────────────────────────────────────────────────────────
const headerStats = computed(() => [
  { label: "Episodios", value: props.stats.total ?? 0, icon: "play_circle", color: "text-podcast-oscuro", bg: "bg-podcast-acento/20" },
  { label: "Activos", value: props.stats.activos ?? 0, icon: "check_circle", color: "text-emerald-500", bg: "bg-emerald-50" },
  { label: "Borradores", value: props.stats.borradores ?? 0, icon: "edit_note", color: "text-amber-500", bg: "bg-amber-50" },
  { label: "Comentarios pendientes", value: props.stats.comentarios_pendientes ?? 0, icon: "forum", color: "text-blue-500", bg: "bg-blue-50" },
  { label: "Eliminados", value: props.stats.eliminados ?? 0, icon: "delete", color: "text-rose-400", bg: "bg-rose-50" },
]);

// ── Eliminar / restaurar ──────────────────────────────────────────────────────
const procesando = ref(false);
const papeleraAbierta = ref(false);

const opcionesRouter = {
  preserveScroll: true,
  onStart: () => (procesando.value = true),
  onFinish: () => (procesando.value = false),
};

const eliminar = (e) => {
  openConfirmationModal({
    title: `Eliminar ${e.codigo}`,
    icon: "delete",
    confirmText: "Enviar a la papelera",
    message: `"${e.titulo}" dejará de estar disponible públicamente. Los comentarios y reacciones se conservarán y volverán a estar disponibles si restauras el episodio.`,
    onConfirm: () => router.delete(route("podcast.episodios.destroy", e.id), opcionesRouter),
  });
};

const restaurar = (e) => {
  openConfirmationModal({
    title: `Restaurar ${e.codigo}`,
    icon: "restore_from_trash",
    confirmText: "Restaurar",
    message: `"${e.titulo}" volverá al listado con su estado anterior. Si ya existe otro episodio destacado, este se restaurará sin la marca de destacado.`,
    onConfirm: () => router.post(route("podcast.episodios.restore", e.id), {}, opcionesRouter),
  });
};

const tituloTemporada = (e) => (e.temporada ? `Temporada ${e.temporada.numero}` : "Sin temporada");
</script>

<template>
  <Head title="Podcast · Episodios" />

  <AuthenticatedLayout>
    <Sidebar>
      <DashboardHeader
        title="Episodios"
        subtitle="Conversaciones de Íntimamente Hablando publicadas en YouTube"
        :stats="headerStats"
      >
        <template #actions>
          <BtnUniversal
            label="Nuevo episodio"
            icon="add"
            icon-position="right"
            size="md"
            activeColor="#3f4e54"
            class="md:w-auto"
            @click="openModal(null, 'create')"
          />
        </template>
      </DashboardHeader>

      <!-- Barra de control + filtros -->
      <div class="bg-slate-900 p-5 rounded-xl border border-white/5 shadow-xl flex flex-col xl:flex-row xl:items-center justify-between gap-5 mb-4">
        <div class="flex items-center gap-4 min-w-0">
          <div class="w-12 h-12 bg-podcast-acento/15 rounded-2xl flex items-center justify-center border border-podcast-acento/20 shrink-0">
            <Mic class="w-5 h-5 text-podcast-acento" />
          </div>
          <div class="min-w-0">
            <h2 class="text-[18px] font-black text-white truncate">Íntimamente Hablando</h2>
            <p class="text-[14px] font-bold text-slate-500">
              {{ episodios.length }} episodio{{ episodios.length !== 1 ? "s" : "" }}
              <span v-if="hayFiltros" class="text-slate-600">· con filtros</span>
            </p>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:flex xl:items-center gap-3 w-full xl:w-auto">
          <div class="relative sm:col-span-2 xl:w-64 group">
            <Search class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600 group-focus-within:text-podcast-acento transition-colors" />
            <input
              v-model="busqueda"
              type="text"
              placeholder="Buscar por título o invitado..."
              class="w-full pl-11 pr-4 py-3 bg-white/5 border-none rounded-2xl text-white placeholder:text-slate-600 focus:ring-1 focus:ring-podcast-acento/40 focus:bg-white/10 transition-all text-xs font-medium"
            />
          </div>

          <select
            v-model="filtroTemporada"
            class="w-full xl:w-44 px-4 py-3 bg-white/5 border-none rounded-2xl text-xs font-medium focus:ring-1 focus:ring-podcast-acento/40 focus:bg-white/10 transition-all"
            :class="filtroTemporada ? 'text-white' : 'text-slate-500'"
          >
            <option value="" class="text-slate-900">Todas las temporadas</option>
            <option v-for="t in temporadas" :key="t.id" :value="t.id" class="text-slate-900">Temporada {{ t.numero }}</option>
          </select>

          <select
            v-model="filtroEstado"
            class="w-full xl:w-40 px-4 py-3 bg-white/5 border-none rounded-2xl text-xs font-medium focus:ring-1 focus:ring-podcast-acento/40 focus:bg-white/10 transition-all"
            :class="filtroEstado ? 'text-white' : 'text-slate-500'"
          >
            <option value="" class="text-slate-900">Todos los estados</option>
            <option v-for="e in estados" :key="e.id" :value="e.id" class="text-slate-900">{{ e.tipo_estado }}</option>
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

      <!-- Destacado actual -->
      <div class="flex items-center gap-3 px-5 py-3 mb-8 rounded-2xl border" :class="stats.destacado ? 'bg-podcast-acento/10 border-podcast-acento/30' : 'bg-white border-slate-100'">
        <Star class="w-4 h-4 shrink-0" :class="stats.destacado ? 'text-podcast-oscuro' : 'text-slate-300'" :fill="stats.destacado ? '#c2c027' : 'none'" />
        <p class="text-xs font-semibold text-slate-600 truncate">
          <template v-if="stats.destacado">Destacado actual: <span class="font-black text-slate-900">{{ stats.destacado }}</span></template>
          <template v-else>Sin episodio destacado — el público verá el más reciente.</template>
        </p>
      </div>

      <!-- Estado vacío -->
      <div v-if="episodios.length === 0" class="text-center py-20 space-y-4 bg-white rounded-[2rem] border border-dashed border-slate-200">
        <div class="w-20 h-20 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto">
          <ListVideo class="w-9 h-9 text-slate-400" />
        </div>
        <template v-if="hayFiltros">
          <p class="text-slate-600 font-bold text-lg">No encontramos episodios con estos filtros</p>
          <p class="text-slate-400 text-sm">Prueba con otra búsqueda o limpia los filtros.</p>
          <div class="pt-2 flex justify-center">
            <button type="button" @click="limpiarFiltros" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition-all">
              <X class="w-4 h-4" /> Limpiar filtros
            </button>
          </div>
        </template>
        <template v-else>
          <p class="text-slate-600 font-bold text-lg">Aún no hay episodios en esta selección</p>
          <p class="text-slate-400 text-sm max-w-md mx-auto">Crea el primero pegando el enlace del video en YouTube.</p>
          <div class="pt-2 flex justify-center">
            <BtnUniversal label="Crear el primer episodio" icon="add" icon-position="right" size="md" activeColor="#3f4e54" class="w-auto" @click="openModal(null, 'create')" />
          </div>
        </template>
      </div>

      <!-- Grid de episodios -->
      <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        <article
          v-for="e in episodios"
          :key="e.id"
          class="group flex flex-col bg-white rounded-[2rem] border overflow-hidden hover:shadow-xl transition-all duration-300"
          :class="e.destacado ? 'border-podcast-acento/50 shadow-md shadow-podcast-acento/10' : 'border-slate-100 hover:border-slate-200'"
        >
          <div class="relative aspect-video bg-podcast-oscuro overflow-hidden">
            <img :src="e.miniatura_url" :alt="e.titulo" class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" loading="lazy" />
            <div class="absolute inset-0 bg-gradient-to-t from-slate-900/70 via-transparent to-transparent"></div>
            <div class="absolute top-3 left-3 flex items-center gap-2">
              <span class="px-2.5 py-1 rounded-full bg-black/50 backdrop-blur-md text-white text-[10px] font-black uppercase tracking-widest">{{ e.codigo }}</span>
              <span v-if="e.destacado" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-podcast-acento text-podcast-oscuro text-[10px] font-black uppercase tracking-widest">
                <Star class="w-3 h-3" fill="currentColor" /> Destacado
              </span>
            </div>
            <span class="absolute top-3 right-3 px-2.5 py-1 rounded-full text-[9px] font-black uppercase tracking-widest backdrop-blur-md" :class="estadoClass(e.estado)">
              {{ e.estado || "Sin estado" }}
            </span>
            <a
              :href="e.youtube_watch_url"
              target="_blank"
              rel="noopener noreferrer"
              class="absolute bottom-3 left-3 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-black/50 backdrop-blur-md text-white text-[10px] font-bold hover:bg-black/70 transition-colors"
              title="Ver en YouTube"
            >
              <Play class="w-3 h-3" fill="currentColor" /> {{ formatDuracion(e.duracion_segundos) || "YouTube" }}
              <ExternalLink class="w-3 h-3 opacity-70" />
            </a>
          </div>

          <div class="flex flex-col flex-1 p-5">
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2 flex items-center gap-1.5">
              {{ tituloTemporada(e) }}
              <span class="text-slate-300">·</span>
              <Calendar class="w-3 h-3" />
              {{ formatFecha(e.fecha_publicacion) || "Sin fecha" }}
            </p>
            <h3 class="text-base font-bold text-slate-900 leading-snug line-clamp-2 group-hover:text-podcast-oscuro transition-colors mb-3">
              {{ e.titulo }}
            </h3>

            <div class="flex items-center gap-2.5 mb-4">
              <span class="w-8 h-8 rounded-full overflow-hidden bg-podcast-oscuro text-white text-[10px] font-black flex items-center justify-center shrink-0">
                <img v-if="e.invitado_foto_url" :src="e.invitado_foto_url" alt="" class="w-full h-full object-cover" />
                <template v-else>{{ iniciales(e.invitado_nombre) }}</template>
              </span>
              <div class="min-w-0">
                <p class="text-xs font-bold text-slate-800 truncate">{{ e.invitado_nombre }}</p>
                <p v-if="e.invitado_cargo" class="text-[11px] text-slate-400 truncate">{{ e.invitado_cargo }}</p>
              </div>
            </div>

            <div class="mt-auto flex items-center justify-between gap-3 pt-3 border-t border-slate-100">
              <div class="flex items-center gap-3 text-[11px] font-bold text-slate-400 tabular-nums">
                <span class="flex items-center gap-1" :title="`${e.comentarios_aprobados_count} aprobados de ${e.comentarios_count}`">
                  <MessageSquare class="w-3.5 h-3.5" /> {{ e.comentarios_aprobados_count }}<span v-if="e.comentarios_count > e.comentarios_aprobados_count" class="text-amber-500">/{{ e.comentarios_count }}</span>
                </span>
                <span class="flex items-center gap-1"><Heart class="w-3.5 h-3.5" /> {{ e.reacciones_count }}</span>
              </div>
              <div class="flex items-center gap-1.5">
                <button type="button" @click="openModal(e, 'edit')" class="p-2.5 bg-slate-50 text-slate-400 hover:text-podcast-oscuro hover:bg-podcast-acento/20 rounded-xl transition-all" title="Editar">
                  <Edit3 class="w-4 h-4" />
                </button>
                <button type="button" @click="eliminar(e)" class="p-2.5 bg-rose-50 text-rose-400 hover:text-rose-600 hover:bg-rose-100 rounded-xl transition-all" title="Eliminar">
                  <Trash2 class="w-4 h-4" />
                </button>
              </div>
            </div>
          </div>
        </article>
      </div>

      <!-- Papelera -->
      <section class="mt-10">
        <button
          type="button"
          @click="papeleraAbierta = !papeleraAbierta"
          class="w-full flex items-center justify-between gap-4 px-5 py-4 bg-white rounded-2xl border border-slate-100 hover:border-slate-200 transition-all"
          :aria-expanded="papeleraAbierta"
        >
          <span class="flex items-center gap-3">
            <span class="w-9 h-9 rounded-xl bg-rose-50 text-rose-400 flex items-center justify-center"><Trash2 class="w-4 h-4" /></span>
            <span class="text-left">
              <span class="block text-sm font-black text-slate-800">Papelera</span>
              <span class="block text-[11px] font-semibold text-slate-400">
                {{ eliminados.length }} episodio{{ eliminados.length !== 1 ? "s" : "" }} eliminado{{ eliminados.length !== 1 ? "s" : "" }}
              </span>
            </span>
          </span>
          <ChevronDown class="w-5 h-5 text-slate-400 transition-transform" :class="{ 'rotate-180': papeleraAbierta }" />
        </button>

        <div v-show="papeleraAbierta" class="mt-3 space-y-2">
          <p v-if="eliminados.length === 0" class="text-center text-sm text-slate-400 font-medium py-8 bg-white/60 rounded-2xl border border-dashed border-slate-200">
            No hay elementos eliminados.
          </p>
          <div v-for="e in eliminados" :key="e.id" class="bg-white/70 p-3 pr-4 sm:pr-5 rounded-2xl border border-slate-100 flex items-center gap-4 opacity-80 hover:opacity-100 transition-opacity">
            <div class="w-20 h-12 shrink-0 rounded-xl overflow-hidden bg-slate-200">
              <img :src="e.miniatura_url" alt="" class="w-full h-full object-cover grayscale" loading="lazy" />
            </div>
            <div class="flex-grow min-w-0">
              <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">{{ e.codigo }}</p>
              <h4 class="text-sm font-bold text-slate-700 truncate">{{ e.titulo }}</h4>
              <p class="text-[11px] text-slate-400">Eliminado el {{ formatFecha(e.deleted_at) }} · {{ e.invitado_nombre }}</p>
            </div>
            <button type="button" @click="restaurar(e)" class="inline-flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-bold bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition-all shrink-0">
              <RotateCcw class="w-4 h-4" /><span class="hidden sm:inline">Restaurar</span>
            </button>
          </div>
        </div>
      </section>
    </Sidebar>
  </AuthenticatedLayout>

  <EpisodioModal
    :show="isModalOpen"
    :mode="modalMode"
    :episodio="selected"
    :temporadas="temporadas"
    :estados="estados"
    :ultimos-numeros="ultimosNumeros"
    :destacado-actual="stats.destacado"
    @close="isModalOpen = false"
    @success="isModalOpen = false"
  />

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
