<script setup>
import { ref, computed, nextTick } from "vue";
import { Head, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import {
  Plus,
  Calendar,
  MapPin,
  Eye,
  Edit3,
  Trash2,
  Search,
  Users,
  Copy,
  TrendingUp,
  LayoutGrid,
  Zap,
  CalendarCheck,
  ChevronRight,
  MoreHorizontal,
  MousePointerClick,
} from "lucide-vue-next";

import BtnUniversal from "@/Components/BtnUniversal.vue";
import PreviewEventModal from "@/Components/Eventos/PreviewEventModal.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import CreateEventModal from "@/Components/Eventos/CreateEventModal.vue";

const props = defineProps({
  eventos: Array,
  areas: Array,
  estados: Array,
  conferencistas: Array,
  organizador: Array,
  stats_counts: Object,
  formularios: Array,
});

const searchQuery = ref("");
const selectedEventForPreview = ref(null);
const isPreviewOpen = ref(false);

const isModalOpen = ref(false);
const modalMode = ref("create");
const selectedEvento = ref(null);

const filteredEventos = computed(() => {
  return props.eventos.filter((e) =>
    e.titulo.toLowerCase().includes(searchQuery.value.toLowerCase())
  );
});

const openModal = (evento = null, mode = "create") => {
  isModalOpen.value = false;
  selectedEvento.value = null;
  nextTick(() => {
    modalMode.value = mode;
    selectedEvento.value = evento;
    isModalOpen.value = true;
  });
};

const openPreview = (evento) => {
  selectedEventForPreview.value = evento;
  isPreviewOpen.value = true;
};

const formatPrice = (val) => {
  return new Intl.NumberFormat("es-CO", {
    style: "currency",
    currency: "COP",
    maximumFractionDigits: 0,
  }).format(val);
};

const getStatusClass = (estadoId) => {
  const map = {
    1: "bg-emerald-500/10 text-emerald-600 ring-1 ring-emerald-500/20",
    2: "bg-amber-500/10 text-amber-600 ring-1 ring-amber-500/20",
    3: "bg-slate-500/10 text-slate-600 ring-1 ring-slate-500/20",
  };
  return map[estadoId] || "bg-blue-500/10 text-blue-600";
};

const headerStats = computed(() => [
  {
    label: "Eventos Activos",
    value: props.eventos.filter((e) => e.estado_id === 1).length,
    icon: "calendar_today",
    color: "text-emerald-500",
    bg: "bg-emerald-50",
  },
  {
    label: "Expertos Globales",
    value: props.stats_counts?.total_conferencistas || 0,
    icon: "record_voice_over",
    color: "text-orange-500",
    bg: "bg-orange-50",
  },
]);

const deleteEvent = (id) => {
  if (
    confirm(
      "¿Estás absolutamente seguro? Esta acción eliminará permanentemente todos los datos vinculados."
    )
  ) {
    router.delete(route("eventos.destroy", id), { preserveScroll: true });
  }
};

const evento = props.eventos;
const getAreaTagImage = () => {
  const areaName = evento?.area_formacion?.nombre;

  const imagenesPorArea = {
    Jurídica: "/images/areasFormacion/juridica_web.png",
    "Talento Humano": "/images/areasFormacion/talento_humano_web.png",
    "Gestión y Políticas Públicas": "/images/areasFormacion/gestion_publica_web.png",
    "Enfoques Misionales": "/images/areasFormacion/enfoque_misional_web.png",
    "Finanzas y Hacienda Pública": "/images/areasFormacion/finanzas_publicas_web.png",
  };

  return imagenesPorArea[areaName] || "/images/areasFormacion/formacion_defecto_web.png";
};

const formatEventRange = (inicio, fin) => {
  if (!inicio) return "Fecha por definir";

  const start = new Date(inicio);
  const end = fin ? new Date(fin) : null;

  const getDayName = (d) => d.toLocaleString("es-ES", { weekday: "long" });
  const getDayNum = (d) => d.getDate();
  const getMonth = (d) => d.toLocaleString("es-ES", { month: "long" });
  const getYear = (d) => d.getFullYear();

  if (!end || start.toDateString() === end.toDateString()) {
    return `${getDayName(start)} ${getDayNum(start)} de ${getMonth(start)} de ${getYear(
      start
    )} | todo el día`;
  }

  if (start.getMonth() === end.getMonth() && start.getFullYear() === end.getFullYear()) {
    return `${getDayName(start)} ${getDayNum(start)} al ${getDayName(end)} ${getDayNum(
      end
    )} de ${getMonth(start)} de ${getYear(start)}`;
  }

  return `${getDayName(start)} ${getDayNum(start)} de ${getMonth(start)} — ${getDayName(
    end
  )} ${getDayNum(end)} de ${getMonth(end)} de ${getYear(end)}`;
};
</script>

<template>
  <Head title="Gestión de Eventos" />

  <AuthenticatedLayout>
    <Sidebar>
      <DashboardHeader
        title="Portafolio Académico"
        subtitle="Centro de mando para la supervisión y despliegue de seminarios"
        :stats="headerStats"
      />

      <div class="p-8 mx-auto space-y-10">
        <div
          class="bg-slate-900 p-5 rounded-[2.5rem] border border-white/5 shadow-xl flex flex-col md:flex-row items-center justify-between gap-5"
        >
          <div class="flex items-center gap-4">
            <div
              class="w-12 h-12 bg-white/10 backdrop-blur-md rounded-2xl flex items-center justify-center border border-white/10"
            >
              <LayoutGrid class="w-5 h-5 text-white" />
            </div>
            <div class="flex flex-col">
              <h2 class="text-lg font-black text-white lowercase leading-tight">
                catálogo maestro
              </h2>
              <p class="text-[9px] font-bold text-slate-500 uppercase tracking-[0.2em]">
                {{ filteredEventos.length }} jornadas encontradas
              </p>
            </div>
          </div>

          <div class="flex items-center gap-3 w-full md:w-auto">
            <div class="relative flex-grow md:w-72 lg:w-96 group">
              <Search
                class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600 group-focus-within:text-orange-500 transition-colors"
              />
              <input
                v-model="searchQuery"
                type="text"
                placeholder="buscar jornada..."
                class="w-full pl-11 pr-4 py-3 bg-white/5 border-none rounded-2xl text-white placeholder:text-slate-600 focus:ring-1 focus:ring-orange-500/30 focus:bg-white/10 transition-all text-xs font-medium"
              />
              <button
                v-if="searchQuery"
                @click="searchQuery = ''"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white"
              >
                <X class="w-3 h-3" />
              </button>
            </div>

            <button
              @click="openModal(null, 'create')"
              class="flex items-center gap-2 px-6 py-3 bg-orange-600 hover:bg-orange-500 text-white rounded-2xl font-black uppercase text-[10px] tracking-widest transition-all active:scale-95 shadow-lg shadow-orange-900/20 whitespace-nowrap"
            >
              <Plus class="w-4 h-4" />
              <span class="hidden sm:inline">nueva jornada</span>
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 2xl:grid-cols-3 gap-10">
          <div
            v-for="evento in filteredEventos"
            :key="evento.id"
            class="group bg-white rounded-[3.5rem] border border-slate-100 shadow-sm hover:shadow-[0_40px_80px_-20px_rgba(0,0,0,0.1)] transition-all duration-700 flex flex-col relative overflow-hidden"
          >
            <div class="relative h-72 overflow-hidden">
              <img
                :src="
                  evento.imagen_relacionada
                    ? '/storage/' + evento.imagen_relacionada
                    : '/images/default-bg.webp'
                "
                class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-[3s] ease-out"
              />

              <div
                class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-transparent to-transparent"
              ></div>

              <div class="absolute top-6 left-6">
                <span
                  class="px-4 py-1.5 rounded-full text-[9px] font-black uppercase tracking-[0.2em] backdrop-blur-md border border-white/20 shadow-2xl text-white"
                  :class="getStatusClass(evento.estado_id)"
                >
                  {{ evento.estado?.tipo_estado }}
                </span>
              </div>

              <div
                class="absolute bottom-6 right-6 w-20 h-20 bg-white/10 backdrop-blur-xl rounded-3xl p-2 border border-white/20 shadow-2xl transition-transform duration-500 group-hover:rotate-6"
              >
                <img
                  :src="getAreaTagImage(evento.area_formacion?.nombre)"
                  class="w-full h-full object-contain drop-shadow-md"
                />
              </div>
            </div>

            <div class="p-10 space-y-6 flex-1 flex flex-col">
              <div class="space-y-2">
                <div class="flex items-center gap-2">
                  <span
                    class="w-2 h-2 rounded-full"
                    :style="{
                      backgroundColor: evento.area_formacion?.color_hex_principal,
                    }"
                  ></span>
                  <p
                    class="text-[10px] font-black text-slate-400 uppercase tracking-[0.3em]"
                  >
                    {{ evento.modo_evento || "formación continua" }}
                  </p>
                </div>
                <h3
                  class="text-3xl font-black text-slate-900 leading-tight lowercase group-hover:text-orange-600 transition-colors duration-500"
                >
                  {{ evento.titulo }}
                </h3>
                <p class="text-xs font-bold text-slate-500 line-clamp-2 leading-relaxed">
                  {{ evento.subtitulo }}
                </p>
              </div>

              <div
                class="bg-slate-50 p-6 rounded-[2.5rem] flex items-center gap-5 border border-slate-100 transition-colors group-hover:bg-white group-hover:border-orange-100"
              >
                <div
                  class="w-14 h-14 bg-slate-900 rounded-2xl flex flex-col items-center justify-center text-white shadow-xl group-hover:bg-orange-600 transition-colors"
                >
                  <Calendar class="w-5 h-5 mb-0.5" />
                  <span class="text-[8px] font-black uppercase tracking-tighter"
                    >cita</span
                  >
                </div>
                <div class="space-y-1">
                  <p class="text-[11px] font-black text-slate-800 leading-none">
                    {{
                      formatEventRange(evento?.fecha_hora_inicio, evento?.fecha_hora_fin)
                    }}
                  </p>
                  <p
                    class="text-[10px] font-bold text-slate-400 flex items-center gap-1 lowercase"
                  >
                    <MapPin class="w-3 h-3 text-orange-500" />
                    {{ evento.ubicacion || "sede central por definir" }}
                  </p>
                </div>
              </div>

              <div class="grid grid-cols-2 gap-6 py-2">
                <div class="space-y-1">
                  <p
                    class="text-[9px] font-black text-slate-400 uppercase tracking-widest"
                  >
                    inversión
                  </p>
                  <div class="flex items-center gap-2">
                    <span class="text-xl font-black text-slate-900">{{
                      formatPrice(evento.precio_jornada)
                    }}</span>
                    <span
                      class="text-[8px] font-bold text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded-md uppercase"
                      >iva inc.</span
                    >
                  </div>
                </div>
                <div class="space-y-1 border-l border-slate-100 pl-6">
                  <p
                    class="text-[9px] font-black text-slate-400 uppercase tracking-widest"
                  >
                    académicos
                  </p>
                  <div class="flex items-center gap-2">
                    <Users class="w-4 h-4 text-slate-900" />
                    <span class="text-sm font-black text-slate-900"
                      >{{ evento.conferencistas?.length }} expertos</span
                    >
                  </div>
                </div>
              </div>

              <div class="flex items-center gap-3 pt-6 border-t border-slate-50 mt-auto">
                <button
                  @click="openPreview(evento)"
                  class="flex-grow flex items-center justify-center gap-2 px-6 py-4 bg-slate-900 text-white rounded-2xl font-black uppercase text-[10px] tracking-widest hover:bg-orange-600 transition-all hover:shadow-xl hover:shadow-orange-200"
                >
                  ver detalles <ChevronRight class="w-4 h-4" />
                </button>

                <div class="flex gap-1.5 bg-slate-100 p-1.5 rounded-[1.8rem]">
                  <button
                    @click="openModal(evento, 'edit')"
                    class="w-11 h-11 flex items-center justify-center bg-white text-slate-400 rounded-full hover:text-blue-600 hover:shadow-lg transition-all"
                  >
                    <Edit3 class="w-4 h-4" />
                  </button>
                  <button
                    @click="openModal(evento, 'duplicate')"
                    class="w-11 h-11 flex items-center justify-center bg-white text-slate-400 rounded-full hover:text-emerald-600 hover:shadow-lg transition-all"
                  >
                    <Copy class="w-4 h-4" />
                  </button>
                  <button
                    @click="deleteEvent(evento.id)"
                    class="w-11 h-11 flex items-center justify-center bg-white text-slate-400 rounded-full hover:text-red-600 hover:shadow-lg transition-all"
                  >
                    <Trash2 class="w-4 h-4" />
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <PreviewEventModal
          :show="isPreviewOpen"
          :evento="selectedEventForPreview"
          @close="isPreviewOpen = false"
        />

        <CreateEventModal
          :show="isModalOpen"
          :mode="modalMode"
          :evento="selectedEvento"
          :estados="estados"
          :areas="areas"
          :organizador="organizador"
          :conferencistas="conferencistas"
          :formularios="formularios"
          @close="isModalOpen = false"
          @success="isModalOpen = false"
        />
      </div>
    </Sidebar>
  </AuthenticatedLayout>
</template>

<style scoped>
img {
  -webkit-backface-visibility: hidden;
  -ms-transform: translateZ(0);
  -webkit-transform: translateZ(0);
  transform: translateZ(0);
}

.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
