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
  Tag,
  LayoutGrid,
  List,
  AlignJustify,
  X,
  Trash2,
  Undo2,
  Monitor,
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

const previewUrl = ref("");

const openPreview = (evento) => {
  selectedEventForPreview.value = evento;

  previewUrl.value = route("evento.show", evento.slug);

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
    label: "Conferencistas Globales",
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
    const normalized = dateStr.includes("T") ? dateStr : dateStr.replace(" ", "T");
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
    return d
      .toLocaleString("es-CO", {
        hour: "numeric",
        minute: "2-digit",
        hour12: true,
      })
      .toUpperCase();
  };

  const hasValidEnd = end && !isNaN(end.getTime());

  const isStartMidnight = start.getHours() === 0 && start.getMinutes() === 0;
  const isEndMidnight = hasValidEnd
    ? end.getHours() === 0 && end.getMinutes() === 0
    : true;
  const hasSpecificTime = !(isStartMidnight && isEndMidnight);

  const timeStartStr = formatTime(start);
  const timeEndStr = hasValidEnd ? formatTime(end) : "";

  if (!hasValidEnd || start.toDateString() === end.toDateString()) {
    const baseDate = `${getDayName(start)} ${getDayNum(start)} de ${getMonth(
      start
    )} de ${getYear(start)}`;

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
      return `Del ${getDayName(start).toLowerCase()} ${getDayNum(
        start
      )} (${timeStartStr}) al ${getDayName(end).toLowerCase()} ${getDayNum(
        end
      )} (${timeEndStr}) de ${getMonth(start)} de ${getYear(start)}`;
    }
    return `Del ${getDayName(start).toLowerCase()} ${getDayNum(start)} al ${getDayName(
      end
    ).toLowerCase()} ${getDayNum(end)} de ${getMonth(start)} de ${getYear(start)}`;
  }

  if (start.getFullYear() === end.getFullYear()) {
    return `Del ${getDayNum(start)} de ${getMonth(start)} al ${getDayNum(
      end
    )} de ${getMonth(end)} de ${getYear(start)}`;
  }

  return `Del ${getDayNum(start)} de ${getMonth(start)} de ${getYear(
    start
  )} al ${getDayNum(end)} de ${getMonth(end)} de ${getYear(end)}`;
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
  return props.eventos.filter(
    (e) =>
      e.titulo.toLowerCase().includes(searchQuery.value.toLowerCase()) &&
      !pendingDeletions.value.has(e.id)
  );
});

const eliminarEvento = (evento) => {
  if (
    confirm(
      `¿Estás seguro de que deseas eliminar "${evento.titulo}"? Esta acción destruirá todos sus archivos.`
    )
  ) {
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
        preserveScroll: true,
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

const obtenerPrecioPrincipal = (evento) => {
  if (evento.precio_jornada > 0)
    return { etiqueta: "Jornada", valor: evento.precio_jornada };
  if (evento.precio_diplomado > 0)
    return { etiqueta: "Diplomado", valor: evento.precio_diplomado };
  if (evento.precio_curso_intensivo > 0)
    return { etiqueta: "Intensivo", valor: evento.precio_curso_intensivo };
  if (evento.precio_modulo > 0)
    return { etiqueta: "Por Módulo", valor: evento.precio_modulo };
  if (evento.precio_cng > 0) return { etiqueta: "Precio CNG", valor: evento.precio_cng };
  if (evento.precio_seminario > 0) return { etiqueta: "Seminario", valor: evento.precio_seminario };

  return { etiqueta: "Inversión", valor: 0 };
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
          class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8"
        >
          <div
            v-for="evento in filteredEventos"
            :key="evento.id"
            class="group bg-white rounded-[2.5rem] border border-slate-100/80 shadow-sm hover:shadow-2xl hover:shadow-slate-200/50 transition-all duration-500 flex flex-col overflow-hidden"
          >
            <div class="relative h-64 overflow-hidden bg-slate-100">
              <img
                :src="
                  evento.imagen_relacionada
                    ? '/storage/' + evento.imagen_relacionada
                    : '/images/default-bg.webp'
                "
                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 ease-out"
              />
              <div
                class="absolute inset-0 bg-gradient-to-t from-slate-900/90 via-slate-900/20 to-transparent"
              ></div>

              <div class="absolute top-5 left-5 flex gap-2">
                <span
                  class="px-3 py-1.5 rounded-xl text-[9px] font-black uppercase tracking-widest backdrop-blur-md shadow-sm"
                  :class="getStatusClass(evento.estado_id)"
                >
                  {{ evento.estado?.tipo_estado || "Borrador" }}
                </span>
              </div>

              <div
                class="absolute bottom-5 right-5 w-14 h-14 bg-white/10 backdrop-blur-md rounded-2xl p-2.5 border border-white/20 shadow-lg group-hover:-translate-y-2 transition-transform duration-500"
              >
                <img
                  :src="getAreaTagImage()"
                  class="w-full h-full object-contain drop-shadow-md"
                />
              </div>

              <div class="absolute bottom-5 left-5 right-24 text-white">
                <p
                  class="text-[10px] font-bold text-slate-300 uppercase tracking-widest mb-1 flex items-center gap-1.5"
                >
                  <Calendar class="w-3 h-3 text-orange-400" />
                  {{
                    formatEventRange(
                      evento.fecha_hora_inicio,
                      evento.fecha_hora_fin
                    ).split("|")[0]
                  }}
                </p>
                <h3 class="text-xl font-black leading-tight line-clamp-2 drop-shadow-md">
                  {{ evento.titulo }}
                </h3>
              </div>
            </div>

            <div class="p-6 flex-1 flex flex-col bg-white">
              <div class="flex items-center gap-2 mb-4">
                <span
                  class="w-2 h-2 rounded-full"
                  :style="{
                    backgroundColor:
                      evento.area_formacion?.color_hex_principal || '#f97316',
                  }"
                ></span>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">
                  {{ evento.modo_evento || "Línea de formación" }}
                </p>
              </div>

              <div class="flex items-center gap-1.5 mb-5">
                <MapPin
                  v-if="evento.modalidad === 'Presencial'"
                  class="w-5 h-5 opacity-40"
                />
                <Monitor v-else class="w-3 h-3 opacity-40" />
                <span class="text-[16px] font-bold">
                  {{ evento.modalidad === "Presencial" ? evento.ubicacion : "Virtual" }}
                </span>
              </div>

              <div
                class="grid grid-cols-2 gap-4 mb-6 p-4 bg-slate-50 rounded-3xl border border-slate-100/50"
              >
                <div class="flex flex-col">
                  <span
                    class="text-[9px] font-bold text-slate-400 uppercase tracking-wider mb-1"
                  >
                    {{ obtenerPrecioPrincipal(evento).etiqueta }}
                  </span>
                  <span
                    class="text-sm font-black"
                    :class="
                      obtenerPrecioPrincipal(evento).valor > 0
                        ? 'text-emerald-600'
                        : 'text-slate-800'
                    "
                  >
                    {{
                      obtenerPrecioPrincipal(evento).valor > 0
                        ? formatPrice(obtenerPrecioPrincipal(evento).valor)
                        : "Gratuito"
                    }}
                  </span>
                </div>
                <div class="flex flex-col border-l border-slate-200 pl-4">
                  <span
                    class="text-[9px] font-bold text-slate-400 uppercase tracking-wider mb-1"
                    >Conferencistas</span
                  >
                  <div
                    class="flex items-center gap-1.5 text-sm font-black text-slate-800"
                  >
                    <Users class="w-3.5 h-3.5 text-orange-500" />
                    {{ evento.conferencistas?.length || 0 }}
                  </div>
                </div>
              </div>

              <div class="flex items-center gap-2 mt-auto">
                <button
                  @click="openPreview(evento)"
                  class="flex-1 flex items-center justify-center gap-2 py-3.5 bg-slate-900 text-white rounded-2xl font-black uppercase text-[10px] tracking-widest hover:bg-orange-600 transition-all shadow-md hover:shadow-orange-200"
                >
                  Ver Perfil
                </button>
                <div class="flex bg-slate-50 p-1.5 rounded-2xl border border-slate-100">
                  <button
                    @click="openModal(evento, 'edit')"
                    class="p-2 text-slate-400 hover:text-blue-600 hover:bg-white rounded-xl transition-all"
                    title="Editar"
                  >
                    <Edit3 class="w-4 h-4" />
                  </button>
                  <button
                    @click="openModal(evento, 'duplicate')"
                    class="p-2 text-slate-400 hover:text-emerald-600 hover:bg-white rounded-xl transition-all"
                    title="Duplicar"
                  >
                    <Copy class="w-4 h-4" />
                  </button>
                  <button
                    @click="eliminarEvento(evento)"
                    class="p-2 text-slate-400 hover:text-red-600 hover:bg-white rounded-xl transition-all"
                    title="Eliminar"
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
            class="bg-white p-3 pr-4 rounded-[2rem] border border-slate-100 flex flex-col md:flex-row items-start md:items-center gap-4 hover:shadow-lg hover:border-slate-200 transition-all group"
          >
            <div
              class="relative w-full md:w-24 h-32 md:h-20 shrink-0 rounded-2xl overflow-hidden bg-slate-100"
            >
              <img
                :src="
                  evento.imagen_relacionada
                    ? '/storage/' + evento.imagen_relacionada
                    : '/images/default-bg.webp'
                "
                class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500"
              />
              <div
                class="absolute inset-0 bg-slate-900/10 group-hover:bg-transparent transition-colors"
              ></div>
            </div>

            <div class="flex-grow min-w-0 flex flex-col justify-center">
              <div class="flex items-center gap-2 mb-1.5">
                <span
                  class="px-2 py-0.5 rounded-md text-[8px] font-black uppercase tracking-widest bg-slate-100 text-slate-500"
                >
                  {{ evento.area_formacion?.nombre }}
                </span>
                <div class="w-1 h-1 rounded-full bg-slate-300"></div>
                <span
                  class="text-[9px] font-bold text-slate-400 uppercase tracking-widest"
                  :class="getStatusClass(evento.estado_id).split(' ')[1]"
                >
                  {{ evento.estado?.tipo_estado }}
                </span>
              </div>
              <h4
                class="text-sm font-black text-slate-900 truncate leading-tight group-hover:text-orange-600 transition-colors"
              >
                {{ evento.titulo }}
              </h4>
              <p
                class="text-[11px] font-medium text-slate-500 mt-1 flex items-center gap-1.5 truncate"
              >
                <Calendar class="w-3 h-3 text-slate-400" />
                {{ formatEventRange(evento.fecha_hora_inicio, evento.fecha_hora_fin) }}
              </p>
            </div>

            <div
              class="hidden lg:flex flex-col shrink-0 px-6 border-l border-slate-100 min-w-[140px]"
            >
              <span
                class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-0.5"
              >
                {{ obtenerPrecioPrincipal(evento).etiqueta }}
              </span>
              <span
                class="text-sm font-black"
                :class="
                  obtenerPrecioPrincipal(evento).valor > 0
                    ? 'text-emerald-600'
                    : 'text-slate-800'
                "
              >
                {{
                  obtenerPrecioPrincipal(evento).valor > 0
                    ? formatPrice(obtenerPrecioPrincipal(evento).valor)
                    : "Gratuito"
                }}
              </span>
            </div>

            <div
              class="flex items-center gap-1.5 w-full md:w-auto md:opacity-50 group-hover:opacity-100 transition-opacity justify-end mt-2 md:mt-0"
            >
              <button
                @click="openPreview(evento)"
                class="p-2.5 bg-slate-50 text-slate-500 hover:text-orange-600 hover:bg-orange-50 rounded-xl transition-all"
                title="Ver Detalles"
              >
                <Eye class="w-4 h-4" />
              </button>
              <button
                @click="openModal(evento, 'edit')"
                class="p-2.5 bg-slate-50 text-slate-500 hover:text-blue-600 hover:bg-blue-50 rounded-xl transition-all"
                title="Editar"
              >
                <Edit3 class="w-4 h-4" />
              </button>
              <button
                @click="openModal(evento, 'duplicate')"
                class="p-2 text-slate-400 hover:text-emerald-600 hover:bg-white rounded-xl transition-all"
                title="Duplicar"
              >
                <Copy class="w-4 h-4" />
              </button>
              <button
                @click="eliminarEvento(evento)"
                class="p-2.5 bg-rose-50 text-rose-400 hover:text-rose-600 hover:bg-rose-100 rounded-xl transition-all"
                title="Eliminar"
              >
                <Trash2 class="w-4 h-4" />
              </button>
            </div>
          </div>
        </div>

        <div v-else-if="currentView === 'detailed'" class="space-y-8">
          <div
            v-for="evento in filteredEventos"
            :key="evento.id"
            class="bg-white rounded-[3rem] border border-slate-100 overflow-hidden flex flex-col lg:flex-row hover:shadow-2xl hover:shadow-slate-200/40 transition-all duration-500 group"
          >
            <div class="lg:w-2/5 h-64 lg:h-auto relative overflow-hidden bg-slate-900">
              <img
                :src="
                  evento.imagen_relacionada
                    ? '/storage/' + evento.imagen_relacionada
                    : '/images/default-bg.webp'
                "
                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 opacity-90 group-hover:opacity-100"
              />
              <div
                class="absolute inset-0 bg-gradient-to-r from-slate-900/50 to-transparent"
              ></div>

              <div class="absolute bottom-6 left-6 right-6">
                <span
                  class="px-4 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-widest backdrop-blur-md shadow-lg border border-white/20 text-white"
                  :class="getStatusClass(evento.estado_id)"
                >
                  {{ evento.estado?.tipo_estado || "Borrador" }}
                </span>
              </div>
            </div>

            <div class="lg:w-3/5 p-8 lg:p-10 flex flex-col bg-white">
              <div
                class="flex flex-col sm:flex-row justify-between items-start gap-4 mb-4"
              >
                <div>
                  <span
                    class="px-3 py-1 rounded-lg text-[9px] font-black uppercase tracking-widest bg-slate-100 text-slate-500 inline-block mb-3"
                  >
                    {{ evento.area_formacion?.nombre }}
                  </span>
                  <h3
                    class="text-2xl lg:text-3xl font-black text-slate-900 leading-tight"
                  >
                    {{ evento.titulo }}
                  </h3>
                </div>

                <div
                  class="flex gap-1 bg-slate-50 p-1.5 rounded-2xl border border-slate-100 shrink-0"
                >
                  <button
                    @click="openModal(evento, 'edit')"
                    class="p-2.5 text-slate-400 hover:text-blue-600 hover:bg-white rounded-xl transition-all shadow-sm"
                  >
                    <Edit3 class="w-4 h-4" />
                  </button>
                  <button
                    @click="openModal(evento, 'duplicate')"
                    class="p-2.5 text-slate-400 hover:text-emerald-600 hover:bg-white rounded-xl transition-all shadow-sm"
                  >
                    <Copy class="w-4 h-4" />
                  </button>
                  <button
                    @click="eliminarEvento(evento)"
                    class="p-2.5 text-slate-400 hover:text-rose-600 hover:bg-white rounded-xl transition-all shadow-sm"
                  >
                    <Trash2 class="w-4 h-4" />
                  </button>
                </div>
              </div>

              <p
                class="text-sm text-slate-500 mb-8 line-clamp-3 leading-relaxed max-w-2xl"
              >
                {{
                  evento.subtitulo ||
                  "Sin descripción o subtítulo registrado para esta publicación."
                }}
              </p>

              <div
                class="mt-auto pt-6 border-t border-slate-100 flex flex-wrap gap-4 items-center justify-between"
              >
                <div class="flex items-center gap-4 lg:gap-8 flex-wrap flex-1">
                  <div class="flex items-center gap-3">
                    <div
                      class="w-10 h-10 rounded-full bg-orange-50 flex items-center justify-center shrink-0"
                    >
                      <Calendar class="w-4 h-4 text-orange-500" />
                    </div>
                    <div class="flex flex-col">
                      <span
                        class="text-[9px] font-black uppercase tracking-widest text-slate-400"
                        >Agenda</span
                      >
                      <span
                        class="text-[11px] font-bold text-slate-700 truncate max-w-[130px]"
                        :title="
                          formatEventRange(
                            evento.fecha_hora_inicio,
                            evento.fecha_hora_fin
                          )
                        "
                      >
                        {{
                          formatEventRange(
                            evento.fecha_hora_inicio,
                            evento.fecha_hora_fin
                          ).split("|")[0]
                        }}
                      </span>
                    </div>
                  </div>

                  <div
                    class="flex items-center gap-3 border-l border-slate-100 pl-4 lg:pl-8"
                  >
                    <div
                      class="w-10 h-10 rounded-full bg-blue-50 flex items-center justify-center shrink-0"
                    >
                      <Users class="w-4 h-4 text-blue-500" />
                    </div>
                    <div class="flex flex-col">
                      <span
                        class="text-[9px] font-black uppercase tracking-widest text-slate-400"
                        >Equipo</span
                      >
                      <span class="text-[11px] font-bold text-slate-700"
                        >{{ evento.conferencistas?.length || 0 }} Conferencistas</span
                      >
                    </div>
                  </div>

                  <div
                    class="flex items-center gap-3 border-l border-slate-100 pl-4 lg:pl-8"
                  >
                    <div
                      class="w-10 h-10 rounded-full bg-emerald-50 flex items-center justify-center shrink-0"
                    >
                      <Tag class="w-4 h-4 text-emerald-500" />
                    </div>
                    <div class="flex flex-col">
                      <span
                        class="text-[9px] font-black uppercase tracking-widest text-slate-400"
                      >
                        {{ obtenerPrecioPrincipal(evento).etiqueta }}
                      </span>
                      <span
                        class="text-[11px] font-bold"
                        :class="
                          obtenerPrecioPrincipal(evento).valor > 0
                            ? 'text-emerald-600'
                            : 'text-slate-700'
                        "
                      >
                        {{
                          obtenerPrecioPrincipal(evento).valor > 0
                            ? formatPrice(obtenerPrecioPrincipal(evento).valor)
                            : "Gratuito"
                        }}
                      </span>
                    </div>
                  </div>
                </div>

                <div class="w-full sm:w-auto mt-4 sm:mt-0">
                  <button
                    @click="openPreview(evento)"
                    class="w-full sm:w-auto px-8 py-3.5 bg-slate-900 text-white rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-orange-600 transition-all shadow-lg hover:shadow-orange-200"
                  >
                    Detalles Completos
                  </button>
                </div>
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
      style="animation: slideUpFade 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards"
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
