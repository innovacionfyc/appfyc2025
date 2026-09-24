<script setup>
import { ref, computed } from "vue";
import { Head, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";
import ConfirmacionesPop from "@/Components/Modales/Confirmaciones/ConfirmacionesPop.vue";
import TemporadaModal from "@/Components/Podcast/Admin/TemporadaModal.vue";
import { useConfirmationModal } from "@/Composables/useConfirmationModal";
import { usePodcastAdmin } from "@/Composables/usePodcastAdmin";
import { Layers, Search, Edit3, Trash2, RotateCcw, ListVideo, ChevronDown, Mic } from "lucide-vue-next";

const props = defineProps({
  temporadas: { type: Array, default: () => [] },
  eliminadas: { type: Array, default: () => [] },
  estados: { type: Array, default: () => [] },
  stats: { type: Object, default: () => ({}) },
});

const { estadoClass, formatFecha } = usePodcastAdmin();
const { confirmationState, openConfirmationModal, closeConfirmationModal, handleConfirm } = useConfirmationModal();

// ── Modal ─────────────────────────────────────────────────────────────────────
const isModalOpen = ref(false);
const modalMode = ref("create");
const selected = ref(null);

const openModal = (temporada = null, mode = "create") => {
  modalMode.value = mode;
  selected.value = temporada;
  isModalOpen.value = true;
};

const siguienteNumero = computed(() => {
  const numeros = [...props.temporadas, ...props.eliminadas].map((t) => Number(t.numero) || 0);
  return (numeros.length ? Math.max(...numeros) : 0) + 1;
});

// ── Búsqueda local ────────────────────────────────────────────────────────────
const searchQuery = ref("");
const filtradas = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  if (!q) return props.temporadas;
  return props.temporadas.filter(
    (t) => t.titulo.toLowerCase().includes(q) || String(t.numero) === q || `temporada ${t.numero}`.includes(q)
  );
});

// ── Stats header ──────────────────────────────────────────────────────────────
const headerStats = computed(() => [
  { label: "Temporadas", value: props.stats.total ?? 0, icon: "layers", color: "text-podcast-oscuro", bg: "bg-podcast-acento/20" },
  { label: "Activas", value: props.stats.activas ?? 0, icon: "check_circle", color: "text-emerald-500", bg: "bg-emerald-50" },
  { label: "Borradores", value: props.stats.borradores ?? 0, icon: "edit_note", color: "text-amber-500", bg: "bg-amber-50" },
  { label: "Episodios", value: props.stats.episodios ?? 0, icon: "play_circle", color: "text-blue-500", bg: "bg-blue-50" },
  { label: "Eliminadas", value: props.stats.eliminadas ?? 0, icon: "delete", color: "text-rose-400", bg: "bg-rose-50" },
]);

// ── Eliminar / restaurar ──────────────────────────────────────────────────────
const procesando = ref(false);
const papeleraAbierta = ref(false);

const opcionesRouter = {
  preserveScroll: true,
  onStart: () => (procesando.value = true),
  onFinish: () => (procesando.value = false),
};

const eliminar = (temporada) => {
  const n = temporada.episodios_count ?? 0;
  openConfirmationModal({
    title: `Eliminar temporada ${temporada.numero}`,
    icon: "delete",
    confirmText: "Enviar a la papelera",
    message:
      n > 0
        ? `Esta temporada contiene ${n} episodio${n === 1 ? "" : "s"}. Los episodios no serán eliminados, pero la temporada dejará de estar disponible públicamente. Podrás restaurarla desde la papelera.`
        : `"${temporada.titulo}" pasará a la papelera y dejará de estar disponible públicamente. Podrás restaurarla cuando quieras.`,
    onConfirm: () => router.delete(route("podcast.temporadas.destroy", temporada.id), opcionesRouter),
  });
};

const restaurar = (temporada) => {
  openConfirmationModal({
    title: `Restaurar temporada ${temporada.numero}`,
    icon: "restore_from_trash",
    confirmText: "Restaurar",
    message: `"${temporada.titulo}" volverá al listado con el estado que tenía antes de eliminarla.`,
    onConfirm: () => router.post(route("podcast.temporadas.restore", temporada.id), {}, opcionesRouter),
  });
};
</script>

<template>
  <Head title="Podcast · Temporadas" />

  <AuthenticatedLayout>
    <Sidebar>
      <DashboardHeader
        title="Temporadas"
        subtitle="Organiza los episodios de Íntimamente Hablando por temporadas"
        :stats="headerStats"
      >
        <template #actions>
          <BtnUniversal
            label="Nueva temporada"
            icon="add"
            icon-position="right"
            size="md"
            activeColor="#3f4e54"
            class="md:w-auto"
            @click="openModal(null, 'create')"
          />
        </template>
      </DashboardHeader>

      <!-- Barra de control -->
      <div
        class="bg-slate-900 p-5 rounded-xl border border-white/5 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-5 mb-8"
      >
        <div class="flex items-center gap-4 min-w-0">
          <div class="w-12 h-12 bg-podcast-acento/15 rounded-2xl flex items-center justify-center border border-podcast-acento/20 shrink-0">
            <Mic class="w-5 h-5 text-podcast-acento" />
          </div>
          <div class="min-w-0">
            <h2 class="text-[18px] font-black text-white truncate">Íntimamente Hablando</h2>
            <p class="text-[14px] font-bold text-slate-500">
              {{ filtradas.length }} temporada{{ filtradas.length !== 1 ? "s" : "" }}
              <span v-if="searchQuery" class="text-slate-600">· filtradas</span>
            </p>
          </div>
        </div>

        <div class="relative w-full md:w-72 group">
          <Search class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600 group-focus-within:text-podcast-acento transition-colors" />
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Buscar por número o título..."
            class="w-full pl-11 pr-4 py-3 bg-white/5 border-none rounded-2xl text-white placeholder:text-slate-600 focus:ring-1 focus:ring-podcast-acento/40 focus:bg-white/10 transition-all text-xs font-medium"
          />
        </div>
      </div>

      <!-- Estado vacío -->
      <div v-if="filtradas.length === 0" class="text-center py-20 space-y-4 bg-white rounded-[2rem] border border-dashed border-slate-200">
        <div class="w-20 h-20 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto">
          <Layers class="w-9 h-9 text-slate-400" />
        </div>
        <template v-if="searchQuery">
          <p class="text-slate-600 font-bold text-lg">Sin resultados</p>
          <p class="text-slate-400 text-sm">No encontramos temporadas que coincidan con "{{ searchQuery }}".</p>
        </template>
        <template v-else>
          <p class="text-slate-600 font-bold text-lg">Aún no hay temporadas</p>
          <p class="text-slate-400 text-sm max-w-md mx-auto">
            Crea la primera para comenzar a organizar Íntimamente Hablando.
          </p>
          <div class="pt-2 flex justify-center">
            <BtnUniversal label="Crear la primera temporada" icon="add" icon-position="right" size="md" activeColor="#3f4e54" class="w-auto" @click="openModal(null, 'create')" />
          </div>
        </template>
      </div>

      <!-- Listado -->
      <div v-else class="space-y-3">
        <article
          v-for="t in filtradas"
          :key="t.id"
          class="bg-white p-3 pr-4 sm:pr-5 rounded-[2rem] border border-slate-100 flex flex-col md:flex-row md:items-center gap-4 hover:shadow-lg hover:border-slate-200 transition-all duration-300 group"
        >
          <!-- Portada -->
          <div class="relative w-full md:w-24 h-32 md:h-24 shrink-0 rounded-2xl overflow-hidden bg-podcast-oscuro flex items-center justify-center">
            <img
              v-if="t.imagen_portada_url"
              :src="t.imagen_portada_url"
              :alt="t.titulo"
              class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
            />
            <template v-else>
              <div
                class="absolute inset-0 opacity-[0.08]"
                style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 20px 20px"
              ></div>
              <span class="relative text-2xl font-black text-podcast-acento tracking-tight">T{{ t.numero }}</span>
            </template>
          </div>

          <!-- Datos -->
          <div class="flex-grow min-w-0">
            <div class="flex flex-wrap items-center gap-2 mb-1">
              <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Temporada {{ t.numero }}</span>
              <span class="px-2.5 py-0.5 rounded-lg text-[9px] font-black uppercase tracking-widest" :class="estadoClass(t.estado)">
                {{ t.estado || "Sin estado" }}
              </span>
            </div>
            <h4 class="text-sm sm:text-base font-black text-slate-900 truncate group-hover:text-podcast-oscuro transition-colors">
              {{ t.titulo }}
            </h4>
            <p class="text-[12px] text-slate-500 line-clamp-2 mt-0.5 max-w-2xl">
              {{ t.descripcion || "Sin descripción." }}
            </p>
          </div>

          <!-- Métricas -->
          <div class="flex md:flex-col items-center md:items-end gap-4 md:gap-1 shrink-0 md:border-l md:border-slate-100 md:pl-5 text-right">
            <p class="flex items-center gap-1.5 text-sm font-black text-slate-900 tabular-nums">
              <ListVideo class="w-4 h-4 text-slate-400" />
              {{ t.episodios_count }}
              <span class="text-[11px] font-semibold text-slate-400">episodio{{ t.episodios_count === 1 ? "" : "s" }}</span>
            </p>
            <p class="text-[11px] font-semibold text-emerald-600 tabular-nums">
              {{ t.episodios_activos_count }} activo{{ t.episodios_activos_count === 1 ? "" : "s" }}
            </p>
          </div>

          <!-- Acciones -->
          <div class="flex items-center gap-1.5 shrink-0 md:opacity-60 group-hover:opacity-100 transition-opacity">
            <button
              type="button"
              @click="openModal(t, 'edit')"
              class="p-2.5 bg-slate-50 text-slate-400 hover:text-podcast-oscuro hover:bg-podcast-acento/20 rounded-xl transition-all"
              title="Editar"
            >
              <Edit3 class="w-4 h-4" />
            </button>
            <button
              type="button"
              @click="eliminar(t)"
              class="p-2.5 bg-rose-50 text-rose-400 hover:text-rose-600 hover:bg-rose-100 rounded-xl transition-all"
              title="Eliminar"
            >
              <Trash2 class="w-4 h-4" />
            </button>
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
            <span class="w-9 h-9 rounded-xl bg-rose-50 text-rose-400 flex items-center justify-center">
              <Trash2 class="w-4 h-4" />
            </span>
            <span class="text-left">
              <span class="block text-sm font-black text-slate-800">Papelera</span>
              <span class="block text-[11px] font-semibold text-slate-400">
                {{ eliminadas.length }} temporada{{ eliminadas.length !== 1 ? "s" : "" }} eliminada{{ eliminadas.length !== 1 ? "s" : "" }}
              </span>
            </span>
          </span>
          <ChevronDown class="w-5 h-5 text-slate-400 transition-transform" :class="{ 'rotate-180': papeleraAbierta }" />
        </button>

        <div v-show="papeleraAbierta" class="mt-3 space-y-2">
          <p v-if="eliminadas.length === 0" class="text-center text-sm text-slate-400 font-medium py-8 bg-white/60 rounded-2xl border border-dashed border-slate-200">
            No hay elementos eliminados.
          </p>
          <div
            v-for="t in eliminadas"
            :key="t.id"
            class="bg-white/70 p-3 pr-4 sm:pr-5 rounded-2xl border border-slate-100 flex items-center gap-4 opacity-80 hover:opacity-100 transition-opacity"
          >
            <div class="w-12 h-12 shrink-0 rounded-xl bg-slate-100 flex items-center justify-center text-sm font-black text-slate-400">
              T{{ t.numero }}
            </div>
            <div class="flex-grow min-w-0">
              <h4 class="text-sm font-bold text-slate-700 truncate">{{ t.titulo }}</h4>
              <p class="text-[11px] text-slate-400">
                Eliminada el {{ formatFecha(t.deleted_at) }} · {{ t.episodios_count }} episodio{{ t.episodios_count === 1 ? "" : "s" }}
              </p>
            </div>
            <button
              type="button"
              @click="restaurar(t)"
              class="inline-flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-bold bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition-all shrink-0"
            >
              <RotateCcw class="w-4 h-4" />
              <span class="hidden sm:inline">Restaurar</span>
            </button>
          </div>
        </div>
      </section>
    </Sidebar>
  </AuthenticatedLayout>

  <TemporadaModal
    :show="isModalOpen"
    :mode="modalMode"
    :temporada="selected"
    :estados="estados"
    :siguiente-numero="siguienteNumero"
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
