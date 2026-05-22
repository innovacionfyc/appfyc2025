<script setup>
import { ref, computed, nextTick, watch } from "vue";
import { Head, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import {
  Plus,
  Calendar,
  MapPin,
  Eye,
  Edit3,
  Search,
  Users,
  Copy,
  ChevronRight,
  LayoutGrid,
  List,
  AlignJustify,
  X,
  Trash2,
  Undo2
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

  const parseLocal = (dateStr) => {
    if (!dateStr) return null;
    const normalized = dateStr.includes('T') ? dateStr : dateStr.replace(' ', 'T');
    return new Date(normalized);
  };

  const start = parseLocal(inicio);
  const end = parseLocal(fin);

  if (isNaN(start.getTime())) return "Fecha por definir";

  const capitalize = (str) => str.charAt(0).toUpperCase() + str.slice(1);
  const getDayName = (d) => capitalize(d.toLocaleString("es-CO", { weekday: "long" }));
  const getDayNum = (d) => d.getDate();
  const getMonth = (d) => d.toLocaleString("es-CO", { month: "long" });
  const getYear = (d) => d.getFullYear();
  
  const formatTime = (d) => {
    return d.toLocaleString("es-CO", {
      hour: "numeric",
      minute: "2-digit",
      hour12: true,
    }).toUpperCase();
  };

  const hasValidEnd = end && !isNaN(end.getTime());
  

  const isStartMidnight = start.getHours() === 0 && start.getMinutes() === 0;
  const isEndMidnight = hasValidEnd ? (end.getHours() === 0 && end.getMinutes() === 0) : true;
  const hasSpecificTime = !(isStartMidnight && isEndMidnight);

  const timeStartStr = formatTime(start);
  const timeEndStr = hasValidEnd ? formatTime(end) : "";

  if (!hasValidEnd || start.toDateString() === end.toDateString()) {
    const baseDate = `${getDayName(start)} ${getDayNum(start)} de ${getMonth(start)} de ${getYear(start)}`;
    
    if (!hasValidEnd) {
      return hasSpecificTime 
        ? `${baseDate} | a partir de las ${timeStartStr}` 
        : `${baseDate} | todo el día`;
    }
    
    if (!hasSpecificTime) return `${baseDate} | todo el día`;
    
    if (timeStartStr === timeEndStr) {
      return `${baseDate} | a las ${timeStartStr}`;
    }

    return `${baseDate} | ${timeStartStr} - ${timeEndStr}`;
  }


  if (start.getMonth() === end.getMonth() && start.getFullYear() === end.getFullYear()) {
    if (hasSpecificTime) {
       return `Del ${getDayName(start).toLowerCase()} ${getDayNum(start)} (${timeStartStr}) al ${getDayName(end).toLowerCase()} ${getDayNum(end)} (${timeEndStr}) de ${getMonth(start)} de ${getYear(start)}`;
    }
    return `Del ${getDayName(start).toLowerCase()} ${getDayNum(start)} al ${getDayName(end).toLowerCase()} ${getDayNum(end)} de ${getMonth(start)} de ${getYear(start)}`;
  }


  if (start.getFullYear() === end.getFullYear()) {
    return `Del ${getDayNum(start)} de ${getMonth(start)} al ${getDayNum(end)} de ${getMonth(end)} de ${getYear(start)}`;
  }


  return `Del ${getDayNum(start)} de ${getMonth(start)} de ${getYear(start)} al ${getDayNum(end)} de ${getMonth(end)} de ${getYear(end)}`;
};

const currentView = ref(localStorage.getItem("event_view_pref") || "grid");

watch(currentView, (newView) => {
  localStorage.setItem("event_view_pref", newView);
});

const setView = (view) => {
  currentView.value = view;
};


const pendingDeletions = ref(new Set()); 

const undoToast = ref({
  show: false,
  eventoId: null,
  timeoutId: null, 
  intervalId: null, 
  timeLeft: 8,
});

const filteredEventos = computed(() => {
  return props.eventos.filter((e) => 
    e.titulo.toLowerCase().includes(searchQuery.value.toLowerCase()) &&
    !pendingDeletions.value.has(e.id) 
  );
});

const eliminarEvento = (evento) => {
  if (confirm(`¿Estás seguro de que deseas eliminar "${evento.titulo}"? Esta acción destruirá todos sus archivos.`)) {
    
    pendingDeletions.value.add(evento.id);

    undoToast.value.show = true;
    undoToast.value.eventoId = evento.id;
    undoToast.value.timeLeft = 8;

    if (undoToast.value.intervalId) clearInterval(undoToast.value.intervalId);
    if (undoToast.value.timeoutId) clearTimeout(undoToast.value.timeoutId);

    undoToast.value.intervalId = setInterval(() => {
      undoToast.value.timeLeft--;
    }, 1000);

    undoToast.value.timeoutId = setTimeout(() => {
      clearInterval(undoToast.value.intervalId);
      undoToast.value.show = false;
      pendingDeletions.value.delete(evento.id); 

      router.delete(route("eventos.destroy", evento.id), {
        preserveScroll: true
      });
    }, 8000);
  }
};

const deshacerEliminacion = () => {
  clearTimeout(undoToast.value.timeoutId);
  clearInterval(undoToast.value.intervalId);

  pendingDeletions.value.delete(undoToast.value.eventoId);
  undoToast.value.show = false;
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

      <div class="mx-auto space-y-10">
        <div
          class="bg-slate-900 p-5 rounded-xl border border-white/5 shadow-xl flex flex-col md:flex-row items-center justify-between gap-5"
        >
          <div class="flex items-center gap-4">
            <div
              class="w-12 h-12 bg-white/10 backdrop-blur-md rounded-2xl flex items-center justify-center border border-white/10"
            >
              <LayoutGrid class="w-5 h-5 text-white" />
            </div>
            <div class="flex flex-col">
              <h2 class="text-[18px] font-black text-white">Eventos activos</h2>
              <p class="text-[14px] font-bold text-slate-500">
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
             <div class="flex bg-white/5 p-1 rounded-2xl border border-white/10 mr-2">
                <button
                  @click="setView('grid')"
                  :class="[
                    'p-2 rounded-xl transition-all',
                    currentView === 'grid'
                      ? 'bg-orange-600 text-white shadow-lg'
                      : 'text-slate-500 hover:text-white',
                  ]"
                  title="Vista Cuadrícula"
                >
                  <LayoutGrid class="w-4 h-4" />
                </button>
                <button
                  @click="setView('list')"
                  :class="[
                    'p-2 rounded-xl transition-all',
                    currentView === 'list'
                      ? 'bg-orange-600 text-white shadow-lg'
                      : 'text-slate-500 hover:text-white',
                  ]"
                  title="Vista Lista"
                >
                  <List class="w-4 h-4" />
                </button>
                <button
                  @click="setView('detailed')"
                  :class="[
                    'p-2 rounded-xl transition-all',
                    currentView === 'detailed'
                      ? 'bg-orange-600 text-white shadow-lg'
                      : 'text-slate-500 hover:text-white',
                  ]"
                  title="Vista Detallada"
                >
                  <AlignJustify class="w-4 h-4" />
                </button>
              </div>

           
            <BtnUniversal
            label="Crear evento"
            icon="add"
            icon-position="right"
            size="md"
            @click="openModal(null, 'create')"
          />
          </div>
        </div>

        <div
          v-if="currentView === 'grid'"
          class="grid grid-cols-1 md:grid-cols-2 2xl:grid-cols-3 gap-10"
        >
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
                  class="text-3xl font-black text-slate-900 leading-tight group-hover:text-orange-600 transition-colors duration-500"
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
                  <p class="text-[10px] font-bold text-slate-400 flex items-center gap-1">
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
                    @click="eliminarEvento(evento)"
                    class="w-11 h-11 flex items-center justify-center bg-white text-slate-400 rounded-full hover:text-red-600 hover:shadow-lg transition-all"
                  >
                    <Trash2 class="w-4 h-4" />
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-else-if="currentView === 'list'" class="space-y-3">
          <div
            v-for="evento in filteredEventos"
            :key="evento.id"
            class="bg-white p-4 rounded-3xl border border-slate-100 flex items-center gap-6 hover:shadow-md transition-all group"
          >
            <img
              :src="
                evento.imagen_relacionada
                  ? '/storage/' + evento.imagen_relacionada
                  : '/images/default-bg.webp'
              "
              class="w-16 h-16 rounded-2xl object-cover shrink-0"
            />

            <div class="flex-grow min-w-0">
              <h4 class="font-black text-slate-900 truncate lowercase">
                {{ evento.titulo }}
              </h4>
              <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                {{ formatEventRange(evento.fecha_hora_inicio) }}
              </p>
            </div>

            <div class="hidden lg:block shrink-0 px-4 border-l border-slate-100">
              <span class="text-xs font-black text-slate-700">{{
                formatPrice(evento.precio_jornada)
              }}</span>
            </div>

            <div class="flex items-center gap-2">
              <button
                @click="openPreview(evento)"
                class="p-2.5 bg-slate-50 text-slate-400 hover:text-slate-900 rounded-xl transition-all"
              >
                <Eye class="w-4 h-4" />
              </button>
              <button
                @click="openModal(evento, 'edit')"
                class="p-2.5 bg-slate-50 text-slate-400 hover:text-blue-600 rounded-xl transition-all"
              >
                <Edit3 class="w-4 h-4" />
              </button>
              <button
                @click="eliminarEvento(evento)"
                class="p-2.5 bg-rose-50 text-rose-400 hover:text-rose-600 rounded-xl transition-all"
              >
                <Trash2 class="w-4 h-4" />
              </button>
            </div>
          </div>
        </div>

        <div v-else-if="currentView === 'detailed'" class="space-y-6">
          <div
            v-for="evento in filteredEventos"
            :key="evento.id"
            class="bg-white rounded-[3rem] border border-slate-100 overflow-hidden flex flex-col md:flex-row hover:shadow-xl transition-all group"
          >
            <div class="md:w-1/3 h-64 md:h-auto relative">
              <img
                :src="
                  evento.imagen_relacionada
                    ? '/storage/' + evento.imagen_relacionada
                    : '/images/default-bg.webp'
                "
                class="w-full h-full object-cover"
              />
              <div
                class="absolute inset-0 bg-gradient-to-r from-black/20 to-transparent"
              ></div>
            </div>

            <div class="md:w-2/3 p-8 flex flex-col">
              <div class="flex justify-between items-start mb-4">
                <span
                  class="px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-widest bg-slate-100 text-slate-500"
                >
                  {{ evento.area_formacion?.nombre }}
                </span>
                <div class="flex gap-2">
                  <button
                    @click="openModal(evento, 'edit')"
                    class="p-2 text-slate-300 hover:text-blue-600 transition-colors"
                  >
                    <Edit3 class="w-5 h-5" />
                  </button>
                  <button
                    @click="eliminarEvento(evento)"
                    class="p-2 text-slate-300 hover:text-rose-600 transition-colors"
                  >
                    <Trash2 class="w-5 h-5" />
                  </button>
                </div>
              </div>

              <h3 class="text-2xl font-black text-slate-900 mb-2 lowercase">
                {{ evento.titulo }}
              </h3>
              <p class="text-sm text-slate-500 mb-6 line-clamp-2">
                {{ evento.subtitulo }}
              </p>

              <div
                class="mt-auto pt-6 border-t border-slate-50 flex items-center justify-between"
              >
                <div class="flex gap-6">
                  <div class="flex items-center gap-2 text-xs font-bold text-slate-400">
                    <Calendar class="w-4 h-4 text-orange-500" />
                    {{ formatEventRange(evento.fecha_hora_inicio) }}
                  </div>
                  <div class="flex items-center gap-2 text-xs font-bold text-slate-400">
                    <Users class="w-4 h-4 text-orange-500" />
                    {{ evento.conferencistas?.length }} Expertos
                  </div>
                </div>
                <button
                  @click="openPreview(evento)"
                  class="px-6 py-3 bg-slate-900 text-white rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-orange-600 transition-all"
                >
                  gestionar jornada
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Sidebar>
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
  </AuthenticatedLayout>

  <Teleport to="body">
  <div
    v-if="undoToast.show"
    class="fixed bottom-10 left-1/2 -translate-x-1/2 z-[100] flex items-center gap-4 bg-slate-900 text-white px-6 py-3.5 rounded-full shadow-2xl transition-all duration-300"
    style="animation: slideUpFade 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;"
  >
    <span class="text-[13px] font-medium tracking-wide">
      Evento eliminado correctamente.
    </span>
    
    <div class="h-4 w-px bg-slate-700"></div>
    
    <button
      @click="deshacerEliminacion"
      class="text-[13px] font-black text-blue-400 hover:text-blue-300 transition-colors flex items-center gap-2 group"
    >
      <Undo2 class="w-4 h-4 group-hover:-rotate-45 transition-transform" />
      Deshacer ({{ undoToast.timeLeft }}s)
    </button>
  </div>
</Teleport>
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

@keyframes slideUpFade {
  from {
    opacity: 0;
    transform: translate(-50%, 20px);
  }
  to {
    opacity: 1;
    transform: translate(-50%, 0);
  }
}
</style>
