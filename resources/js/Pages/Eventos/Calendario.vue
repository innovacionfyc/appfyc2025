<script setup>
import { ref, computed } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import PreviewEventModal from "@/Components/Eventos/PreviewEventModal.vue";
import CreateEventModal from "@/Components/Eventos/CreateEventModal.vue";

import {
  ChevronLeft,
  ChevronRight,
  Plus,
  Calendar as CalendarIcon,
  Clock,
  MapPin,
  Filter,
  Search,
} from "lucide-vue-next";

const props = defineProps({
  eventos: Array,
  areas: Array,
  estados: Array,
  conferencistas: Array,
  organizador: Array,
  formularios: Array,
});

// --- ESTADOS ---
const viewDate = ref(new Date());
const currentView = ref("month");
const selectedAreaId = ref(null);
const isPreviewOpen = ref(false);
const isCreateModalOpen = ref(false);
const selectedEvento = ref(null);

const monthNames = [
  "enero",
  "febrero",
  "marzo",
  "abril",
  "mayo",
  "junio",
  "julio",
  "agosto",
  "septiembre",
  "octubre",
  "noviembre",
  "diciembre",
];
const dayNames = [
  "domingo",
  "lunes",
  "martes",
  "miércoles",
  "jueves",
  "viernes",
  "sábado",
];
const dayNamesShort = ["dom", "lun", "mar", "mié", "jue", "vie", "sáb"];

// --- FILTRADO ---
const filteredEventos = computed(() => {
  return props.eventos.filter((e) =>
    selectedAreaId.value ? e.area_formacion_id === selectedAreaId.value : true
  );
});

// --- LÓGICA DE FECHAS ---
const calendarData = computed(() => {
  const year = viewDate.value.getFullYear();
  const month = viewDate.value.getMonth();
  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const days = [];

  const prevLastDay = new Date(year, month, 0).getDate();
  for (let i = firstDay - 1; i >= 0; i--)
    days.push({ d: prevLastDay - i, m: month - 1, y: year, current: false });
  for (let i = 1; i <= daysInMonth; i++)
    days.push({ d: i, m: month, y: year, current: true });
  const remaining = 42 - days.length;
  for (let i = 1; i <= remaining; i++)
    days.push({ d: i, m: month + 1, y: year, current: false });

  return days;
});

const getEventsForDate = (d, m, y) => {
  const calendarDate = new Date(y, m, d);
  calendarDate.setHours(0, 0, 0, 0);

  return filteredEventos.value.filter((e) => {
    const start = new Date(e.fecha_hora_inicio);
    const end = new Date(e.fecha_hora_fin);
    start.setHours(0, 0, 0, 0);
    end.setHours(0, 0, 0, 0);
    return calendarDate >= start && calendarDate <= end;
  });
};

const getEventPosition = (d, m, y, event) => {
  const current = new Date(y, m, d);
  current.setHours(0, 0, 0, 0);
  const start = new Date(event.fecha_hora_inicio);
  const end = new Date(event.fecha_hora_fin);
  start.setHours(0, 0, 0, 0);
  end.setHours(0, 0, 0, 0);

  const isStart = current.getTime() === start.getTime();
  const isEnd = current.getTime() === end.getTime();

  if (isStart && isEnd) return "single";
  if (isStart) return "start";
  if (isEnd) return "end";
  return "middle";
};

// Centraliza las clases de Tailwind
const getEventClasses = (date, ev) => {
  const pos = getEventPosition(date.d, date.m, date.y, ev);
  return {
    // Evento de un solo día
    'rounded-lg shadow-sm mx-1': pos === 'single',
    // Inicio de un evento multidía
    'rounded-l-lg ml-1': pos === 'start',
    // Parte media de un evento multidía
    'rounded-none': pos === 'middle',
    // Final de un evento multidía
    'rounded-r-lg mr-1': pos === 'end',
  };
};

const getEventStyles = (ev, date) => {
  const color = ev.area_formacion?.color_hex_principal || '#e11d48';
  const pos = getEventPosition(date.d, date.m, date.y, ev);
  
  // Si es el inicio o único, usamos el color sólido para que destaque
  // Si es continuación, podemos usar una opacidad ligeramente distinta para efecto premium
  const isSolid = pos === 'start' || pos === 'single';
  
  return {
    backgroundColor: isSolid ? color : `${color}dd`, // Sólido o 85% de opacidad
    color: '#ffffff', // Texto siempre blanco para máxima legibilidad sobre colores
    boxShadow: isSolid ? `0 2px 4px ${color}20` : 'none'
  };
};

const shouldShowTitle = (date, ev) => {
  const pos = getEventPosition(date.d, date.m, date.y, ev);
  const dayOfWeek = new Date(date.y, date.m, date.d).getDay();
  
  // Mostrar título si: es el inicio, es un solo día, es el día 1 del mes, o es Lunes (re-confirmación visual)
  return pos === 'start' || pos === 'single' || date.d === 1 || dayOfWeek === 1;
};

const hoveredColumn = ref(null);

// Calculamos el estilo de las columnas dinámicamente
const gridStyle = computed(() => {
  if (hoveredColumn.value === null) return "repeat(7, 1fr)";
  let columns = [];
  for (let i = 0; i < 7; i++) {
    // La columna bajo el mouse crece (1.8fr), las demás se encogen (1fr)
    columns.push(i === hoveredColumn.value ? "1.8fr" : "1fr");
  }
  return columns.join(" ");
});

const getColumnIndex = (index) => index % 7;

const navigate = (step) => {
  const d = new Date(viewDate.value);
  if (currentView.value === "month") d.setMonth(d.getMonth() + step);
  if (currentView.value === "day") d.setDate(d.getDate() + step);
  if (currentView.value === "year") d.setFullYear(d.getFullYear() + step);
  viewDate.value = d;
};

const openPreview = (ev) => {
  selectedEvento.value = ev;
  isPreviewOpen.value = true;
};

const formatTime = (dateStr) => {
  return new Date(dateStr).toLocaleTimeString("es-ES", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: true,
  });
};

// Calcula la estructura exacta del mes (Lunes a Domingo)
const getDaysInMonthForYear = (monthIdx, year) => {
  const firstDay = new Date(year, monthIdx, 1).getDay();
  const daysInMonth = new Date(year, monthIdx + 1, 0).getDate();
  const days = [];

  // Ajuste para que la semana empiece en Lunes
  const offset = firstDay === 0 ? 6 : firstDay - 1;

  for (let i = 0; i < offset; i++) days.push({ day: null });
  for (let i = 1; i <= daysInMonth; i++) days.push({ day: i });

  return days;
};

// Obtiene el color del primer evento encontrado para ese día
const getDayEventColor = (d, m, y) => {
  if (!d) return null;
  const evs = getEventsForDate(d, m, y);
  return evs.length > 0 ? evs[0].area_formacion?.color_hex_principal || "#ef4444" : null;
};

// Navegación rápida: clic en día de año -> vista de día
const jumpToDay = (d, m, y) => {
  if (!d) return;
  viewDate.value = new Date(y, m, d);
  currentView.value = "day";
};
</script>

<template>
  <Head title="Calendario Maestro" />
  <AuthenticatedLayout>
    <Sidebar>
      <div class="min-h-screen bg-slate-50 p-4 lg:p-8">
        <DashboardHeader
          title="calendario maestro"
          subtitle="gestión avanzada de jornadas y eventos académicos"
          :stats="[]"
        />

        <div
          class="mt-8 flex flex-col lg:flex-row gap-6 items-center justify-between bg-white p-6 rounded-[2.5rem] shadow-[0_8px_30px_rgb(0,0,0,0.04)] border border-slate-100"
        >
          <div class="flex items-center gap-6">
            <div
              class="flex items-center bg-slate-50 p-1.5 rounded-2xl border border-slate-200 shadow-inner"
            >
              <button
                @click="navigate(-1)"
                class="p-2 hover:bg-white hover:text-rose-600 rounded-xl transition-all"
              >
                <ChevronLeft class="w-5 h-5" />
              </button>
              <button
                @click="viewDate = new Date()"
                class="px-5 text-[10px] font-black uppercase tracking-widest text-slate-500 hover:text-rose-600 transition-colors"
              >
                hoy
              </button>
              <button
                @click="navigate(1)"
                class="p-2 hover:bg-white hover:text-rose-600 rounded-xl transition-all"
              >
                <ChevronRight class="w-5 h-5" />
              </button>
            </div>

            <div class="flex flex-col">
              <h2 class="text-2xl font-black text-slate-900 capitalize leading-none">
                {{ currentView !== "year" ? monthNames[viewDate.getMonth()] : "" }}
                <span class="text-rose-600">{{ viewDate.getFullYear() }}</span>
              </h2>
            </div>
          </div>

          <div
            class="hidden xl:flex items-center gap-3 bg-slate-50 p-2 rounded-3xl border border-slate-100"
          >
            <button
              @click="selectedAreaId = null"
              class="px-4 py-2 rounded-2xl text-[9px] font-black uppercase tracking-widest transition-all"
              :class="
                !selectedAreaId
                  ? 'bg-white text-rose-600 shadow-sm'
                  : 'text-slate-400 hover:text-slate-600'
              "
            >
              todos
            </button>
            <div class="h-4 w-px bg-slate-200 mx-1"></div>
            <button
              v-for="area in areas"
              :key="area.id"
              @click="selectedAreaId = area.id"
              class="flex items-center gap-2 px-4 py-2 rounded-2xl text-[9px] font-black uppercase tracking-widest transition-all group"
              :class="
                selectedAreaId === area.id
                  ? 'bg-white shadow-sm'
                  : 'text-slate-400 hover:text-slate-600'
              "
            >
              <span
                class="w-2 h-2 rounded-full"
                :style="{ backgroundColor: area.color_hex_principal }"
              ></span>
              {{ area.nombre }}
            </button>
          </div>

          <div class="flex items-center gap-4">
            <div class="flex bg-slate-100 p-1 rounded-2xl border border-slate-200">
              <button
                v-for="v in ['day', 'month', 'year']"
                :key="v"
                @click="currentView = v"
                class="px-5 py-2 text-[10px] font-black uppercase tracking-widest rounded-xl transition-all"
                :class="
                  currentView === v
                    ? 'bg-white text-rose-600 shadow-sm'
                    : 'text-slate-400'
                "
              >
                {{ v === "day" ? "día" : v === "month" ? "mes" : "año" }}
              </button>
            </div>
            <button
              @click="isCreateModalOpen = true"
              class="p-4 bg-rose-600 text-white rounded-2xl shadow-xl shadow-rose-100 hover:bg-rose-700 hover:-translate-y-0.5 transition-all active:scale-95"
            >
              <Plus class="w-6 h-6" />
            </button>
          </div>
        </div>

        <div
          class="mt-8 bg-white rounded-[3.5rem] shadow-[0_20px_50px_rgba(0,0,0,0.02)] border border-slate-100 overflow-hidden"
        >
          <template v-if="currentView === 'month'">
  <div class="grid grid-cols-7 border-b border-slate-100 bg-slate-50/50">
    <div v-for="day in dayNamesShort" :key="day" class="py-4 text-center">
      <span class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">{{ day }}</span>
    </div>
  </div>

  <div class="grid grid-cols-7 auto-rows-fr min-h-[750px] bg-slate-100 gap-[1px] border-b border-slate-100">
    <div
      v-for="(date, i) in calendarData"
      :key="i"
      class="relative bg-white p-1 flex flex-col gap-1 transition-all hover:bg-slate-50/80"
      :class="[!date.current ? 'bg-slate-50/50 opacity-40' : '']"
    >
      <div class="flex justify-end p-1 mb-1">
        <span
          class="text-[11px] font-black w-7 h-7 flex items-center justify-center rounded-lg transition-all"
          :class="date.d === new Date().getDate() && date.m === new Date().getMonth() && date.y === new Date().getFullYear()
              ? 'bg-rose-600 text-white shadow-md shadow-rose-200'
              : 'text-slate-400'"
        >
          {{ date.d }}
        </span>
      </div>

      <div class="flex flex-col gap-0.5 overflow-y-auto no-scrollbar pb-1">
        <button
          v-for="ev in getEventsForDate(date.d, date.m, date.y)"
          :key="ev.id"
          @click="openPreview(ev)"
          class="w-full flex items-center min-h-[24px] px-2 py-1 transition-all hover:brightness-95 group/event relative border-y border-transparent"
          :class="getEventClasses(date, ev)"
          :style="getEventStyles(ev, date)"
        >
          <div 
            v-if="getEventPosition(date.d, date.m, date.y, ev) === 'start' || getEventPosition(date.d, date.m, date.y, ev) === 'single'"
            class="absolute left-0 top-0 bottom-0 w-1 rounded-full opacity-60 bg-white/40 ml-0.5 my-1"
          ></div>

          <span
            v-if="shouldShowTitle(date, ev)"
            class="text-[9px] font-black truncate lowercase tracking-tight pl-1"
            :class="[getEventPosition(date.d, date.m, date.y, ev) === 'single' || getEventPosition(date.d, date.m, date.y, ev) === 'start' ? 'text-white' : '']"
          >
            {{ ev.titulo }}
          </span >
        </button>
      </div>
    </div>
  </div>
</template>

          <template v-else-if="currentView === 'day'">
            <div class="p-6 lg:p-16 max-w-5xl mx-auto">
              <header
                class="flex flex-col sm:flex-row sm:items-end justify-between gap-6 mb-16 border-b border-slate-100 pb-10"
              >
                <div class="space-y-2">
                  <h3
                    class="text-6xl font-black text-slate-900 tracking-tighter leading-none lowercase"
                  >
                    {{ dayNames[viewDate.getDay()] }}
                  </h3>
                  <p
                    class="text-xl font-bold text-slate-400 capitalize flex items-center gap-2"
                  >
                    <span class="text-indigo-600">{{ viewDate.getDate() }}</span>
                    {{ monthNames[viewDate.getMonth()] }}, {{ viewDate.getFullYear() }}
                  </p>
                </div>

                <div class="px-6 py-3 bg-slate-50 rounded-2xl border border-slate-100">
                  <p
                    class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400"
                  >
                    jornadas para hoy:
                    <span class="text-indigo-600">{{
                      getEventsForDate(
                        viewDate.getDate(),
                        viewDate.getMonth(),
                        viewDate.getFullYear()
                      ).length
                    }}</span>
                  </p>
                </div>
              </header>

              <div class="relative pl-8 md:pl-12">
                <div
                  class="absolute left-0 top-0 bottom-0 w-[2px] bg-slate-100 rounded-full"
                ></div>

                <div class="space-y-10">
                  <div
                    v-for="ev in getEventsForDate(
                      viewDate.getDate(),
                      viewDate.getMonth(),
                      viewDate.getFullYear()
                    )"
                    :key="ev.id"
                    class="relative group"
                  >
                    <div
                      class="absolute -left-[2.35rem] md:-left-[3.35rem] top-8 w-5 h-5 bg-white border-4 rounded-full z-10 transition-transform group-hover:scale-125"
                      :style="{ borderColor: ev.area_formacion?.color_hex_principal }"
                    ></div>

                    <div
                      @click="openPreview(ev)"
                      class="p-6 md:p-10 rounded-[2.5rem] bg-white border border-slate-100 hover:border-indigo-100 hover:shadow-[0_20px_50px_-20px_rgba(0,0,0,0.05)] transition-all duration-500 cursor-pointer flex flex-col md:flex-row md:items-center justify-between gap-8"
                    >
                      <div class="flex-1 space-y-4">
                        <div class="flex items-center gap-3">
                          <span
                            class="text-[9px] font-black uppercase tracking-widest px-3 py-1 rounded-full"
                            :style="{
                              backgroundColor:
                                ev.area_formacion?.color_hex_principal + '15',
                              color: ev.area_formacion?.color_hex_principal,
                            }"
                          >
                            {{ ev.area_formacion?.nombre }}
                          </span>
                          <div
                            v-if="ev.modalidad === 'Virtual'"
                            class="flex items-center gap-1.5 text-blue-500"
                          >
                            <Monitor class="w-3 h-3" />
                            <span class="text-[9px] font-black uppercase tracking-widest"
                              >En Línea</span
                            >
                          </div>
                        </div>

                        <h4
                          class="text-3xl md:text-4xl font-black text-slate-800 leading-[1.1] tracking-tight lowercase group-hover:text-indigo-600 transition-colors"
                        >
                          {{ ev.titulo }}
                        </h4>

                        <div class="flex flex-wrap items-center gap-6 pt-2">
                          <div
                            class="flex items-center gap-2 text-slate-400 font-bold text-xs uppercase tracking-wider"
                          >
                            <Clock class="w-4 h-4" />
                            {{ formatTime(ev.fecha_hora_inicio) }}
                          </div>
                          <div
                            class="flex items-center gap-2 text-slate-400 font-bold text-xs uppercase tracking-wider"
                          >
                            <MapPin class="w-4 h-4" />
                            {{ ev.ubicacion || "Por definir" }}
                          </div>
                        </div>
                      </div>

                      <div class="flex-none">
                        <div
                          class="w-16 h-16 rounded-full bg-slate-50 flex items-center justify-center text-slate-300 group-hover:bg-indigo-600 group-hover:text-white transition-all duration-500"
                        >
                          <ChevronRight class="w-6 h-6" />
                        </div>
                      </div>
                    </div>
                  </div>

                  <div
                    v-if="
                      getEventsForDate(
                        viewDate.getDate(),
                        viewDate.getMonth(),
                        viewDate.getFullYear()
                      ).length === 0
                    "
                    class="py-32 flex flex-col items-center justify-center text-center space-y-6"
                  >
                    <div
                      class="w-24 h-24 bg-slate-50 rounded-[2.5rem] flex items-center justify-center border border-slate-100"
                    >
                      <CalendarIcon class="w-10 h-10 text-slate-200" />
                    </div>
                    <div class="space-y-1">
                      <p
                        class="font-black uppercase tracking-[0.2em] text-xs text-slate-900"
                      >
                        sin compromisos
                      </p>
                      <p class="text-slate-400 font-medium text-xs">
                        tienes el día libre de jornadas académicas.
                      </p>
                    </div>
                    <button
                      @click="isCreateModalOpen = true"
                      class="px-6 py-3 bg-white border border-slate-200 rounded-2xl text-[10px] font-black uppercase tracking-widest text-indigo-600 hover:bg-slate-50 transition-all"
                    >
                      + programar algo ahora
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </template>

          <template v-else-if="currentView === 'year'">
            <div
              class="p-4 md:p-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 gap-6 md:gap-10 bg-[#F8F9FD]"
            >
              <div
                v-for="(month, idx) in monthNames"
                :key="idx"
                class="bg-white p-6 md:p-8 rounded-[2.5rem] shadow-[0_10px_40px_-15px_rgba(0,0,0,0.03)] border border-slate-100 flex flex-col group/month hover:shadow-xl hover:border-slate-200 transition-all duration-500"
              >
                <div class="flex items-center justify-between mb-6">
                  <h4
                    class="text-sm font-black text-slate-900 uppercase tracking-[0.2em] group-hover/month:text-indigo-600 transition-colors"
                  >
                    {{ month }}
                  </h4>
                  <span class="text-[10px] font-black text-slate-300">{{
                    viewDate.getFullYear()
                  }}</span>
                </div>

                <div class="grid grid-cols-7 mb-3">
                  <div
                    v-for="d in ['l', 'm', 'm', 'j', 'v', 's', 'd']"
                    :key="d"
                    class="text-[8px] font-black text-slate-300 uppercase text-center"
                  >
                    {{ d }}
                  </div>
                </div>

                <div class="grid grid-cols-7 gap-1 md:gap-1.5">
                  <div
                    v-for="(dateObj, dIdx) in getDaysInMonthForYear(
                      idx,
                      viewDate.getFullYear()
                    )"
                    :key="dIdx"
                    @click="jumpToDay(dateObj.day, idx, viewDate.getFullYear())"
                    class="aspect-square flex items-center justify-center text-[10px] font-bold rounded-lg md:rounded-xl transition-all cursor-pointer relative group/day"
                    :class="[
                      !dateObj.day
                        ? 'opacity-0 pointer-events-none'
                        : 'hover:bg-slate-50',
                      getDayEventColor(dateObj.day, idx, viewDate.getFullYear())
                        ? 'text-white shadow-lg scale-110 z-10'
                        : 'text-slate-400',
                    ]"
                    :style="
                      getDayEventColor(dateObj.day, idx, viewDate.getFullYear())
                        ? {
                            backgroundColor: getDayEventColor(
                              dateObj.day,
                              idx,
                              viewDate.getFullYear()
                            ),
                            shadowColor:
                              getDayEventColor(dateObj.day, idx, viewDate.getFullYear()) +
                              '40',
                          }
                        : {}
                    "
                  >
                    {{ dateObj.day }}

                    <div
                      v-if="getDayEventColor(dateObj.day, idx, viewDate.getFullYear())"
                      class="absolute -top-1 -right-1 w-2 h-2 bg-white rounded-full border-2 border-transparent"
                      :style="{
                        borderColor: getDayEventColor(
                          dateObj.day,
                          idx,
                          viewDate.getFullYear()
                        ),
                      }"
                    ></div>
                  </div>
                </div>

                <div
                  class="mt-6 pt-4 border-t border-slate-50 flex items-center justify-between"
                >
                  <div class="flex -space-x-2">
                    <div
                      v-for="areaId in [
                        ...new Set(
                          props.eventos
                            .filter(
                              (e) => new Date(e.fecha_hora_inicio).getMonth() === idx
                            )
                            .map((e) => e.area_formacion_id)
                        ),
                      ]"
                      :key="areaId"
                      class="w-3 h-3 rounded-full border-2 border-white"
                      :style="{
                        backgroundColor: areas.find((a) => a.id === areaId)
                          ?.color_hex_principal,
                      }"
                    ></div>
                  </div>
                  <p
                    class="text-[8px] font-black text-slate-300 uppercase tracking-widest"
                  >
                    {{
                      props.eventos.filter(
                        (e) => new Date(e.fecha_hora_inicio).getMonth() === idx
                      ).length
                    }}
                    jornadas
                  </p>
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>

      <PreviewEventModal
        :show="isPreviewOpen"
        :evento="selectedEvento"
        @close="isPreviewOpen = false"
      />
      <CreateEventModal
        :show="isCreateModalOpen"
        mode="create"
        :estados="estados"
        :areas="areas"
        :organizador="organizador"
        :conferencistas="conferencistas"
        :formularios="formularios"
        @close="isCreateModalOpen = false"
        @success="isCreateModalOpen = false"
      />
    </Sidebar>
  </AuthenticatedLayout>
</template>

<style scoped>
.grid-cols-7 {
  display: grid !important;
  grid-template-columns: repeat(7, 1fr) !important;
  width: 100%;
}

.no-scrollbar::-webkit-scrollbar {
  display: none;
}

.no-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}

button {
  transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

.line-clamp-1 {
  display: -webkit-box;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
