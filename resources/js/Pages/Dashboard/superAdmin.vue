<script setup>
import { ref, computed } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import { useAuthStore } from "@/stores/auth";

import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import CreateEventModal from "@/Components/Eventos/CreateEventModal.vue";

// ICONOS
import {
  Users,
  Calendar,
  Plus,
  Activity,
  Zap,
  Clock,
  MapPin,
  Monitor,
  LayoutGrid,
  User,
} from "lucide-vue-next";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import PreviewEventModal from "@/Components/Eventos/PreviewEventModal.vue";

const props = defineProps({
  auth: Object,
  estados: Array,
  movimientos: Array,
  areas: Array,
  formularios: Array,
  conferencistas: Array,
  organizador: Array,
  stats_counts: Object,
  agenda_proxima: Array,
});

const contadorEventos = props.stats_counts?.eventos_activos;

const stats = computed(() => [
  {
    name: "Eventos activos",
    value: contadorEventos,
    icon: Calendar,
    color: "text-blue-600",
    bg: "bg-blue-50",
    trend: "+" + props.stats_counts.eventos_recientes + " esta semana",
  },
  {
    name: "Conferencistas",
    value: props.stats_counts?.conferencistas || 0,
    icon: Users,
    color: "text-primary-vinotinto",
    bg: "bg-rose-50",
    trend: "Registrados",
  },
]);


const headerStats = [
  {
    label: "Perfil",
    value: props.auth?.user?.perfil_organizador?.rol?.nombre,
    icon: "verified_user",
    color: "text-sembg-semaforo-verde",
    bg: "bg-emerald-50",
  },
  {
    label: "Equipo",
    value: props.auth?.user?.perfil_organizador?.equipo?.nombre,
    icon: "check_circle",
    color: "text-primary-naranja",
    bg: "bg-blue-50",
  },
];

const isCreateModalOpen = ref(false);
const isSpeakerModalOpen = ref(false);

const handleOpenDependency = (dependencyType) => {
  isCreateModalOpen.value = false;
  if (dependencyType === "conferencistas")
    setTimeout(() => (isSpeakerModalOpen.value = true), 300);
};

const formatDate = (dateStr) => {
  const date = new Date(dateStr);
  return {
    diaNum: date.getDate(),
    mes: date.toLocaleString("es-ES", { month: "short" }).replace(".", ""),
    diaNombre: date.toLocaleString("es-ES", { weekday: "short" }).replace(".", ""),
    hora: date.toLocaleString("es-ES", {
      hour: "2-digit",
      minute: "2-digit",
      hour12: true,
    }),
  };
};

const isPreviewOpen = ref(false);
const selectedEvento = ref(null);

const previewUrl = ref("");
const openPreview = (evento) => {
  selectedEvento.value = evento;

  previewUrl.value = route("evento.show", evento.slug);

  isPreviewOpen.value = true;
};

const getExecutionDay = (evento) => {
  const start = new Date(evento.fecha);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  start.setHours(0, 0, 0, 0);

  const diffTime = Math.abs(today - start);
  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;

  return diffDays > evento.duracion_dias ? evento.duracion_dias : diffDays;
};

const calcularTotalDias = (evento) => {
  const start = new Date(evento.fecha);
  const end = new Date(evento.fecha_fin);
  const diffTime = Math.abs(end - start);
  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
  return diffDays;
};
</script>

<template>
  <Head title="Panel de Administración | F&C" />

  <AuthenticatedLayout>
    <Sidebar>
      <DashboardHeader
        :title="'Bienvenido nuevamente ' + auth.user.perfil_organizador.primer_nombre"
        :subtitle="auth.user.perfil_organizador.rol.descripcion"
        :stats="headerStats"
      >
        <template #actions> </template>
      </DashboardHeader>

      <div
        class="mx-auto space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-1000"
      >
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-8">
          <div class="lg:col-span-2 space-y-8">
            <div
              class="bg-mono-blanco rounded-2xl border border-slate-100 p-8 shadow-sm h-auto flex flex-col"
            >
              <section
                class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10 animate-in fade-in slide-in-from-bottom-4 duration-700"
              >
                <div
                  v-for="stat in stats"
                  :key="stat.name"
                  class="group relative bg-mono-blanco/60 backdrop-blur-2xl p-5 rounded-xl border border-mono-blanco shadow-[0_10px_40px_rgba(0,0,0,0.03)] hover:shadow-xl hover:-translate-y-1 transition-all duration-500 overflow-hidden"
                >
                  <div
                    class="absolute -top-10 -right-10 w-32 h-32 bg-gradient-to-br from-mono-blanco/40 to-transparent rounded-full blur-2xl"
                  ></div>

                  <div class="flex items-start justify-between relative z-10">
                    <div
                      :class="[
                        stat.bg,
                        stat.color,
                        'w-8 h-8 rounded-2xl flex items-center justify-center shadow-sm border border-mono-blanco/50 transition-transform group-hover:scale-110 duration-500',
                      ]"
                    >
                      <component :is="stat.icon" class="w-5 h-5" />
                    </div>

                    <div
                      class="px-3 py-1.5 rounded-full bg-mono-blanco/80 border border-mono-blanco shadow-sm flex items-center gap-1.5"
                    >
                      <div
                        class="w-1.5 h-1.5 rounded-full bg-semaforo-verde animate-pulse"
                      ></div>
                      <span class="text-[11px] font-bold text-slate-500 leading-none">
                        {{ stat.trend }}
                      </span>
                    </div>
                  </div>

                  <div class="mt-6 relative z-10">
                    <p class="text-sm font-semibold text-slate-400 mb-1 leading-none">
                      {{ stat.name }}
                    </p>
                    <h3 class="text-4xl font-black text-mono-negro tracking-tight">
                      {{ stat.value }}
                    </h3>
                  </div>
                </div>
              </section>
              <div
                class="mb-5 bg-gradient-to-br from-primary-vinotinto to-secondary-vinotinto2 w-full rounded-2xl p-5 text-mono-blanco relative overflow-hidden shadow-2xl shadow-rose-200"
              >
                <div class="2xl:flex 2xl:justify-between items-center">
                  <div class="flex gap-3">
                    <div
                      class="2xl:flex hidden w-14 h-14 bg-mono-blanco/10 backdrop-blur-sm rounded-md border border-mono-blanco/20 items-center justify-center"
                    >
                      <LayoutGrid class="w-8 h-8 text-mono-blanco/50" />
                    </div>
                    <div class="">
                      <h3 class="text-3xl font-black">Control de Eventos</h3>
                      <p class="text-rose-100 font-medium leading-relaxed">
                        Tienes {{ contadorEventos }} eventos próximos a iniciar esta
                        semana.
                      </p>
                    </div>
                  </div>

                  <button
                    @click="isCreateModalOpen = true"
                    class="bg-mono-blanco text-primary-vinotinto font-bold py-5 px-8 rounded-xl shadow-lg hover:bg-rose-50 transition-all active:scale-95 flex items-center gap-2"
                  >
                    <Plus class="w-5 h-5" /> Crear ahora
                  </button>
                </div>
              </div>

              <div class="flex items-center justify-between mb-5">
                <div>
                  <h4 class="text-xl font-black text-mono-negro">Agenda activa</h4>
                  <p class="text-xs text-slate-400 font-medium">
                    Esta semana + 4 posteriores
                  </p>
                </div>
                <Link
                  href="/admin/eventos/calendario"
                  class="p-3 bg-slate-50 hover:bg-rose-50 text-slate-400 hover:text-primary-vinotinto rounded-2xl transition-all flex items-center gap-3"
                >
                  <Calendar class="w-5 h-5" />
                  <span class="text-semibold"> Ver calendario completo</span>
                </Link>
              </div>

              <div
                v-if="agenda_proxima.length > 0"
                class="space-y-4 overflow-y-auto pr-2 max-h-[600px] custom-scrollbar"
              >
                <div v-for="(evento, index) in agenda_proxima" :key="evento.id">
                  <div
                    v-if="index === 0 && evento.es_esta_semana"
                    class="mb-4 flex items-center gap-3"
                  >
                    <span
                      class="flex-none text-[14px] font-black text-primary-vinotinto bg-rose-50 px-3 py-1 rounded-full"
                      >Esta semana</span
                    >
                    <div class="h-px bg-rose-100 flex-grow"></div>
                  </div>

                  <div
                    v-if="
                      index > 0 &&
                      !evento.es_esta_semana &&
                      agenda_proxima[index - 1].es_esta_semana
                    "
                    class="my-6 flex items-center gap-3"
                  >
                    <span
                      class="flex-none text-[14px] font-black text-slate-400 bg-slate-50 px-3 py-1 rounded-full"
                      >Próximamente</span
                    >
                    <div class="h-px bg-slate-100 flex-grow"></div>
                  </div>

                  <div
                    @click="openPreview(evento)"
                    class="group relative flex items-start gap-4 p-4 rounded-[2.2rem] transition-all duration-500 border border-transparent hover:border-slate-100 hover:bg-mono-blanco hover:shadow-xl hover:shadow-slate-200/40 cursor-pointer"
                    :class="{ 'bg-rose-50/40 border-rose-100': evento.es_hoy }"
                  >
                    <div
                      class="flex-none w-20 h-20 rounded-2xl flex flex-col items-center justify-center transition-all duration-500 group-hover:scale-110 shadow-sm"
                      :class="
                        evento.es_hoy
                          ? 'bg-primary-vinotinto text-mono-blanco'
                          : 'text-mono-blanco'
                      "
                      :style="{ backgroundColor: !evento.es_hoy ? evento.color : '' }"
                    >
                      <span class="text-[10px] font-black uppercase opacity-70">
                        {{ formatDate(evento.fecha).diaNombre }}
                      </span>

                      <span class="text-2xl font-black text-center leading-none">
                        <template
                          v-if="
                            formatDate(evento.fecha).diaNum ===
                            formatDate(evento.fecha_fin).diaNum
                          "
                        >
                          {{ formatDate(evento.fecha).diaNum }}
                        </template>

                        <template v-else-if="calcularTotalDias(evento) >= 3">
                          {{ formatDate(evento.fecha).diaNum }} al
                          {{ formatDate(evento.fecha_fin).diaNum }}
                        </template>

                        <template v-else>
                          {{ formatDate(evento.fecha).diaNum }} y
                          {{ formatDate(evento.fecha_fin).diaNum }}
                        </template>
                      </span>
                      <span class="text-[10px] font-black uppercase opacity-70">{{
                        formatDate(evento.fecha).mes
                      }}</span>
                    </div>

                    <div class="flex-grow min-w-0 pt-1">
                      <div class="flex items-center gap-2 mb-1.5">
                        <div
                          v-if="evento.es_hoy"
                          class="flex items-center gap-1 bg-primary-vinotinto text-[12px] text-mono-blanco px-2 py-0.5 rounded-md font-semibold shadow-sm"
                        >
                          <span v-if="evento.duracion_dias > 1"
                            >En ejecución: día {{ getExecutionDay(evento) }}/{{
                              evento.duracion_dias
                            }}</span
                          >
                          <span v-else>hoy</span>
                        </div>

                        <div
                          v-else-if="evento.duracion_dias > 1"
                          class=" text-mono-blanco text-[12px] px-2 py-0.5 rounded-md font-semibold"
                           :style="{ backgroundColor: evento.color }"
                        >
                          {{ Math.floor(evento.duracion_dias) }}
                          <span v-if="evento.duracion_dias > 2">Días</span>
                          <span v-else>Día</span> de jornada
                        </div>

                        <span class="text-[13px] font-bold text-slate-400">
                          · {{ evento.area }} ·
                        </span>
                      </div>

                      <h5
                        class="text-md font-black text-mono-negro mb-2 truncate group-hover:text-primary-vinotinto transition-colors"
                      >
                        {{ evento.titulo }}
                      </h5>
                      <div class="flex items-center gap-1.5 truncate">
                        <User class="w-3 h-3 opacity-40" />
                        <span class="text-[12px] font-bold">
                          {{ evento.organizador }}
                        </span>
                      </div>

                      <div class="flex items-center gap-4 text-slate-400">
                        <div class="flex items-center gap-1.5">
                          <Clock class="w-3 h-3 opacity-40" />
                          <span class="text-[12px] font-bold">{{
                            formatDate(evento.fecha).hora
                          }}</span>
                          -
                          <span class="text-[10px] font-bold">{{
                            formatDate(evento.fecha_fin).hora
                          }}</span>
                        </div>
                        <div class="flex items-center gap-1.5 truncate">
                          <MapPin
                            v-if="evento.modalidad === 'Presencial'"
                            class="w-3 h-3 opacity-40"
                          />
                          <Monitor v-else class="w-3 h-3 opacity-40" />
                          <span class="text-[12px] font-bold">
                            {{
                              evento.modalidad === "Presencial"
                                ? evento.ubicacion
                                : "Virtual"
                            }}
                          </span>
                        </div>
                      </div>
                    </div>

                    <div
                      class="absolute right-4 top-1/2 -translate-y-1/2 w-1 h-8 rounded-full opacity-10 group-hover:opacity-100 group-hover:h-12 transition-all duration-500"
                      :style="{ backgroundColor: evento.color }"
                    ></div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="space-y-8">
            <div
              class="bg-mono-blanco/70 backdrop-blur-xl rounded-[3rem] border border-mono-blanco p-8 shadow-sm"
            >
              <h4 class="text-lg font-black text-mono-negro mb-6 flex items-center gap-2">
                <Activity class="w-5 h-5 text-primary-vinotinto" /> Actividad
              </h4>
              <div class="space-y-6">
                <TransitionGroup
                  enter-active-class="transition-all duration-500"
                  enter-from-class="opacity-0 translate-x-4"
                  enter-to-class="opacity-100 translate-x-0"
                >
                  <div v-for="mov in movimientos" :key="mov.id" class="flex gap-4 group">
                    <div
                      :class="[
                        'w-11 h-11 rounded-2xl flex items-center justify-center shrink-0 transition-all group-hover:scale-110',
                        mov.tipo === 'registro'
                          ? 'bg-emerald-50 text-sembg-semaforo-verde'
                          : 'bg-primary-naranja/20 text-primary-naranja',
                      ]"
                    >
                      <span class="text-xs font-bold">{{ mov.user[0] }}</span>
                    </div>

                    <div class="flex-1 min-w-0">
                      <p class="text-[14px] text-slate-600 leading-snug">
                        <span class="font-bold text-primary-naranja">{{ mov.user }}</span>
                        {{ mov.descripcion }}
                      </p>
                      <p
                        class="text-[11px] font-medium text-slate-400 mt-1 flex items-center gap-1"
                      >
                        <Clock class="w-3 h-3" /> {{ mov.tiempo }}
                      </p>
                    </div>
                  </div>
                </TransitionGroup>

                <div v-if="movimientos.length === 0" class="py-10 text-center">
                  <p class="text-sm font-medium text-slate-400">
                    no hay movimientos registrados todavía.
                  </p>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>

      <CreateEventModal
        :show="isCreateModalOpen"
        :estados="estados"
        :areas="areas"
        :organizador="organizador"
        :conferencistas="conferencistas"
        :formularios="formularios"
        @close="isCreateModalOpen = false"
        @openDependency="handleOpenDependency"
      />

      <PreviewEventModal
        :show="isPreviewOpen"
        :evento="selectedEvento"
        @close="isPreviewOpen = false"
      />
    </Sidebar>
  </AuthenticatedLayout>
</template>

<style scoped>
.animate-in {
  animation-fill-mode: both;
}

@keyframes slide-in {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.no-scrollbar::-webkit-scrollbar {
  display: none;
}

.custom-scrollbar::-webkit-scrollbar {
  width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: #f1f5f9;
  border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: #e2e8f0;
}

.animate-pulse {
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
@keyframes pulse {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.7;
  }
}
</style>
