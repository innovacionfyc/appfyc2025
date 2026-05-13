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

      <div class="p-8 max-w-[1700px] mx-auto space-y-10">
        <div
          class="flex flex-col lg:flex-row justify-between items-center gap-6 bg-slate-900 p-8 rounded-[3rem] shadow-2xl relative overflow-hidden group"
        >
          <div
            class="absolute inset-0 bg-gradient-to-r from-orange-600/20 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-700"
          ></div>

          <div class="relative z-10 flex items-center gap-6">
            <div
              class="w-16 h-16 bg-white/10 backdrop-blur-xl rounded-[2rem] flex items-center justify-center border border-white/20 shadow-inner"
            >
              <LayoutGrid class="w-8 h-8 text-white" />
            </div>
            <div>
              <h2 class="text-3xl font-black text-white tracking-tight">
                Catálogo Maestro
              </h2>
              <p class="text-slate-400 text-xs font-bold uppercase tracking-[0.3em]">
                Total: {{ filteredEventos.length }} Unidades
              </p>
            </div>
          </div>

          <div class="relative z-10 flex items-center gap-4 w-full lg:w-auto">
            <div class="relative flex-1 lg:w-[450px]">
              <Search
                class="absolute left-5 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-500"
              />
              <input
                v-model="searchQuery"
                type="text"
                placeholder="Buscar por título del evento..."
                class="w-full pl-14 pr-6 py-5 bg-white/5 border-none rounded-full text-white placeholder:text-slate-600 focus:ring-2 focus:ring-orange-500/50 focus:bg-white/10 transition-all font-medium"
              />
            </div>
            <button
              @click="openModal(null, 'create')"
              class="flex items-center gap-3 px-8 py-5 bg-orange-600 hover:bg-orange-500 text-white rounded-full font-black uppercase text-xs tracking-widest transition-all hover:scale-105 active:scale-95 shadow-xl shadow-orange-900/20"
            >
              <Plus class="w-5 h-5" /> Nueva Jornada
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 2xl:grid-cols-3 gap-10">
          <div
            v-for="evento in filteredEventos"
            :key="evento.id"
            class="group bg-white rounded-[3.5rem] border border-slate-100 shadow-sm hover:shadow-[0_40px_80px_-20px_rgba(0,0,0,0.08)] transition-all duration-700 flex flex-col relative overflow-hidden"
          >
            <div class="relative h-64 overflow-hidden">
              <img
                :src="
                  evento.imagen_relacionada
                    ? '/storage/' + evento.imagen_relacionada
                    : '/images/default-bg.webp'
                "
                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-[2s]"
              />
              <div
                class="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-900/20 to-transparent"
              ></div>

              <div class="absolute top-6 left-6">
                <span
                  class="px-4 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest backdrop-blur-md border border-white/10 shadow-xl"
                  :class="getStatusClass(evento.estado_id)"
                >
                  {{ evento.estado?.tipo_estado }}
                </span>
              </div>

             <div class="mb-6 md:mb-8 flex justify-center">
              <img
                :src="getAreaTagImage()"
                :alt="evento?.area_formacion?.nombre || 'Área de formación'"
                class="h-20 md:h-28 w-auto object-contain drop-shadow-lg hover:scale-105 transition-transform duration-300"
              />
            </div>
              <p
                class="text-sm md:text-md text-slate-400 font-medium max-w-xl mx-auto md:mb-2"
              >
                · {{ evento.modo_evento || "Sin línea de formación" }} ·
              </p>
              <h1 class="font-black text-white leading-tight mb-1 text-5xl">
                {{ evento.titulo || "Sin título definido" }}
              </h1>
              <p
                class="text-sm md:text-md text-white font-medium max-w-xl mx-auto md:mb-2"
              >
                {{ evento.subtitulo || "Sin subtitulo definido" }}
              </p>
            </div>

            <div class="p-10 space-y-8 flex-1 flex flex-col">
              <div class="flex items-start gap-5">
                <div
                  class="flex-shrink-0 w-16 h-16 bg-slate-900 rounded-3xl flex flex-col items-center justify-center text-white shadow-xl shadow-slate-200"
                >
                  <Calendar class="w-5 h-5 text-orange-500 mb-1" />
                  <span class="text-[10px] font-black uppercase tracking-tighter"
                    >Agenda</span
                  >
                </div>
                <div class="space-y-1">
                  <p
                    class="text-[11px] font-black text-slate-400 uppercase tracking-widest"
                  >
                    Fecha Programada
                  </p>
                  <p class="text-lg font-black text-slate-800 leading-none">
                    {{
                      formatEventRange(evento?.fecha_hora_inicio, evento?.fecha_hora_fin)
                    }}
                  </p>
                  <p class="text-xs font-bold text-slate-500 flex items-center gap-1">
                    <MapPin class="w-3 h-3" />
                    {{ evento.ubicacion || "Ubicación por definir" }}
                  </p>
                </div>
              </div>

              <div class="grid grid-cols-2 gap-4 pt-6 border-t border-slate-50">
                <div class="space-y-1">
                  <p
                    class="text-[9px] font-black text-slate-400 uppercase tracking-tighter"
                  >
                    Inversión Jornada
                  </p>
                  <div class="flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full bg-emerald-500"></div>
                    <span class="text-sm font-black text-slate-700">{{
                      formatPrice(evento.precio_jornada)
                    }}</span>
                  </div>
                </div>
                <div class="space-y-1 border-l border-slate-100 pl-4">
                  <p
                    class="text-[9px] font-black text-slate-400 uppercase tracking-tighter"
                  >
                    Staff Académico
                  </p>
                  <div class="flex items-center gap-2">
                    <Users class="w-4 h-4 text-slate-400" />
                    <span class="text-sm font-black text-slate-700"
                      >{{ evento.conferencistas?.length }} Expertos</span
                    >
                  </div>
                </div>
              </div>

              <div class="flex items-center gap-3 pt-6 mt-auto">
                <BtnUniversal
                  @click="openPreview(evento)"
                  label="Previsualización web"
                  icon="globe"
                  icon-position="right"
                  :activeColor="evento.area_formacion.color_hex_principal"
                />

                <div
                  class="flex gap-1.5 bg-slate-50 p-1.5 rounded-[2.2rem] border border-slate-100"
                >
                  <button
                    @click="openModal(evento, 'edit')"
                    class="w-12 h-12 flex items-center justify-center bg-white text-slate-400 rounded-full hover:text-blue-600 hover:shadow-md transition-all shadow-sm"
                  >
                    <Edit3 class="w-4 h-4" />
                  </button>
                  <button
                    @click="openModal(evento, 'duplicate')"
                    class="w-12 h-12 flex items-center justify-center bg-white text-slate-400 rounded-full hover:text-indigo-600 hover:shadow-md transition-all shadow-sm"
                  >
                    <Copy class="w-4 h-4" />
                  </button>
                  <button
                    @click="deleteEvent(evento.id)"
                    class="w-12 h-12 flex items-center justify-center bg-white text-slate-400 rounded-full hover:text-red-600 hover:shadow-md transition-all shadow-sm"
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
