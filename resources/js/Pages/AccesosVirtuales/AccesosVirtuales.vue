<script setup>
import { ref, computed } from "vue";
import { Head, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";
import CreateAccesoModal from "@/Components/AccesosVirtuales/CreateAccesoModal.vue";
import {
  Video,
  Search,
  Edit3,
  Trash2,
  Copy,
  Check,
  Calendar,
  Clock,
  ExternalLink,
} from "lucide-vue-next";

const props = defineProps({
  accesos: { type: Array, default: () => [] },
  estados: { type: Array, default: () => [] },
});

// ── Modal ────────────────────────────────────────────────────────────────────
const isModalOpen = ref(false);
const modalMode   = ref("create");
const selectedAcceso = ref(null);

const openModal = (acceso = null, mode = "create") => {
  modalMode.value    = mode;
  selectedAcceso.value = acceso;
  isModalOpen.value  = true;
};

// ── Búsqueda ──────────────────────────────────────────────────────────────────
const searchQuery = ref("");
const filteredAccesos = computed(() =>
  props.accesos.filter((a) =>
    a.nombre.toLowerCase().includes(searchQuery.value.toLowerCase())
  )
);

// ── Stats header ──────────────────────────────────────────────────────────────
const headerStats = computed(() => [
  {
    label: "Activos",
    value: props.accesos.filter((a) => a.estado_id === 1).length,
    icon: "video_call",
    color: "text-emerald-500",
    bg: "bg-emerald-50",
  },
  {
    label: "Total",
    value: props.accesos.length,
    icon: "link",
    color: "text-blue-500",
    bg: "bg-blue-50",
  },
]);

// ── Helpers ───────────────────────────────────────────────────────────────────
const getStatusClass = (estadoId) =>
  estadoId === 1
    ? "bg-emerald-500/10 text-emerald-600 ring-1 ring-emerald-500/20"
    : "bg-slate-500/10 text-slate-500 ring-1 ring-slate-400/20";

const formatFecha = (acceso) => {
  if (!acceso.fecha) return "Sin fecha";
  // Tomar solo los primeros 10 caracteres (Y-m-d) para evitar issues con ISO 8601 de Eloquent
  const dateStr = String(acceso.fecha).substring(0, 10);
  const d = new Date(dateStr + "T00:00:00");
  if (isNaN(d.getTime())) return "Sin fecha definida";
  return d.toLocaleDateString("es-CO", {
    weekday: "short",
    day: "numeric",
    month: "short",
    year: "numeric",
  });
};

const getPublicUrl = (acceso) =>
  `${window.location.origin}/acceso/${acceso.slug}`;

// ── Copiar URL ────────────────────────────────────────────────────────────────
const copiedId = ref(null);
const copyUrl = (acceso) => {
  navigator.clipboard.writeText(getPublicUrl(acceso)).then(() => {
    copiedId.value = acceso.id;
    setTimeout(() => { copiedId.value = null; }, 2500);
  });
};

// ── Eliminar ──────────────────────────────────────────────────────────────────
const eliminarAcceso = (acceso) => {
  if (confirm(`¿Eliminar el acceso virtual "${acceso.nombre}"?\nEl registro pasará a la papelera y seguirá recuperable.`)) {
    router.delete(route("accesos-virtuales.destroy", acceso.id), {
      preserveScroll: true,
    });
  }
};
</script>

<template>
  <Head title="Accesos Virtuales" />

  <AuthenticatedLayout>
    <Sidebar>
      <DashboardHeader
        title="Accesos Virtuales"
        subtitle="Páginas públicas de acceso a reuniones y eventos en línea"
        :stats="headerStats"
      />

      <!-- Barra de control -->
      <div
        class="bg-slate-900 p-5 rounded-xl border border-white/5 shadow-xl flex flex-col md:flex-row items-center justify-between gap-5 mb-8"
      >
        <div class="flex items-center gap-4">
          <div
            class="w-12 h-12 bg-white/10 backdrop-blur-md rounded-2xl flex items-center justify-center border border-white/10"
          >
            <Video class="w-5 h-5 text-white" />
          </div>
          <div>
            <h2 class="text-[18px] font-black text-white">Accesos registrados</h2>
            <p class="text-[14px] font-bold text-slate-500">
              {{ filteredAccesos.length }} resultado{{ filteredAccesos.length !== 1 ? "s" : "" }}
            </p>
          </div>
        </div>

        <div class="flex items-center gap-3 w-full md:w-auto">
          <div class="relative flex-grow md:w-72 group">
            <Search
              class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600 group-focus-within:text-orange-500 transition-colors"
            />
            <input
              v-model="searchQuery"
              type="text"
              placeholder="buscar por nombre..."
              class="w-full pl-11 pr-4 py-3 bg-white/5 border-none rounded-2xl text-white placeholder:text-slate-600 focus:ring-1 focus:ring-orange-500/30 focus:bg-white/10 transition-all text-xs font-medium"
            />
          </div>
          <BtnUniversal
            label="Nuevo acceso"
            icon="add"
            icon-position="right"
            size="md"
            @click="openModal(null, 'create')"
          />
        </div>
      </div>

      <!-- Estado vacío -->
      <div v-if="filteredAccesos.length === 0" class="text-center py-24 space-y-4">
        <div
          class="w-20 h-20 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto"
        >
          <Video class="w-9 h-9 text-slate-400" />
        </div>
        <p class="text-slate-600 font-bold text-lg">Sin accesos virtuales</p>
        <p class="text-slate-400 text-sm">
          {{ searchQuery ? "No hay resultados para tu búsqueda." : 'Crea el primero con "Nuevo acceso".' }}
        </p>
      </div>

      <!-- Listado -->
      <div v-else class="space-y-3">
        <div
          v-for="acceso in filteredAccesos"
          :key="acceso.id"
          class="bg-white p-3 pr-5 rounded-[2rem] border border-slate-100 flex flex-col md:flex-row items-start md:items-center gap-4 hover:shadow-lg hover:border-slate-200 transition-all duration-300 group"
        >
          <!-- Banner thumbnail -->
          <div
            class="relative w-full md:w-20 h-20 shrink-0 rounded-2xl overflow-hidden bg-gradient-to-br from-slate-200 to-slate-300 flex items-center justify-center"
          >
            <img
              v-if="acceso.imagen_banner_url"
              :src="acceso.imagen_banner_url"
              class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500"
              @error="(e) => e.target.style.display = 'none'"
            />
            <Video v-else class="w-6 h-6 text-slate-400" />
            <div class="absolute inset-0 bg-slate-900/10 group-hover:bg-transparent transition-colors" />
          </div>

          <!-- Datos principales -->
          <div class="flex-grow min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <span
                class="px-2.5 py-0.5 rounded-lg text-[9px] font-black uppercase tracking-widest"
                :class="getStatusClass(acceso.estado_id)"
              >
                {{ acceso.estado?.tipo_estado || "Sin estado" }}
              </span>
            </div>
            <h4
              class="text-sm font-black text-slate-900 truncate group-hover:text-orange-600 transition-colors"
            >
              {{ acceso.nombre }}
            </h4>
            <p class="text-[11px] font-medium text-slate-500 flex items-center gap-1.5 mt-0.5">
              <Calendar class="w-3 h-3 shrink-0" />
              {{ formatFecha(acceso) }}
              <span v-if="acceso.hora" class="flex items-center gap-1 ml-1">
                <Clock class="w-3 h-3 shrink-0" />
                {{ acceso.hora?.substring(0, 5) }}
              </span>
            </p>
          </div>

          <!-- URL pública -->
          <div
            class="hidden lg:flex flex-col shrink-0 max-w-[240px] border-l border-slate-100 pl-5"
          >
            <span
              class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1"
              >URL pública</span
            >
            <p
              class="text-[11px] font-mono text-slate-500 truncate"
              :title="getPublicUrl(acceso)"
            >
              /acceso/{{ acceso.slug }}
            </p>
          </div>

          <!-- Acciones -->
          <div class="flex items-center gap-1.5 shrink-0">
            <button
              @click="copyUrl(acceso)"
              :title="copiedId === acceso.id ? '¡Copiado!' : 'Copiar URL pública'"
              class="p-2.5 rounded-xl transition-all"
              :class="
                copiedId === acceso.id
                  ? 'bg-emerald-50 text-emerald-600'
                  : 'bg-slate-50 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50'
              "
            >
              <Check v-if="copiedId === acceso.id" class="w-4 h-4" />
              <Copy v-else class="w-4 h-4" />
            </button>
            <a
              :href="getPublicUrl(acceso)"
              target="_blank"
              class="p-2.5 bg-slate-50 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-xl transition-all"
              title="Abrir página pública"
            >
              <ExternalLink class="w-4 h-4" />
            </a>
            <button
              @click="openModal(acceso, 'edit')"
              class="p-2.5 bg-slate-50 text-slate-400 hover:text-orange-600 hover:bg-orange-50 rounded-xl transition-all"
              title="Editar"
            >
              <Edit3 class="w-4 h-4" />
            </button>
            <button
              @click="eliminarAcceso(acceso)"
              class="p-2.5 bg-rose-50 text-rose-400 hover:text-rose-600 hover:bg-rose-100 rounded-xl transition-all"
              title="Eliminar"
            >
              <Trash2 class="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>
    </Sidebar>
  </AuthenticatedLayout>

  <!-- Modal crear/editar -->
  <CreateAccesoModal
    :show="isModalOpen"
    :mode="modalMode"
    :acceso="selectedAcceso"
    :estados="estados"
    @close="isModalOpen = false"
    @success="isModalOpen = false"
  />
</template>
