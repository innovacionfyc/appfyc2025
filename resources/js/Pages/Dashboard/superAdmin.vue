<script setup>
import { ref, computed } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import { useAuthStore } from "@/stores/auth";

import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";
import CreateEventModal from "@/Components/Eventos/CreateEventModal.vue";
import CreateConferencistaModal from "@/Components/Conferencistas/CreateConferencistaModal.vue";
import CreateFormularioModal from "@/Components/Formularios/CreateFormularioModal.vue";

// ICONOS
import {
  Users,
  Calendar,
  ShieldCheck,
  ChevronRight,
  Plus,
  ArrowUpRight,
  Activity,
  Zap,
  Clock,
  MapPin,
  Monitor,
  Star,
  LayoutGrid,
} from "lucide-vue-next";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";

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

const authStore = useAuthStore();

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
    color: "text-rose-600",
    bg: "bg-rose-50",
    trend: "Registrados",
  },
  {
    name: "Inscripciones",
    value:
      props.stats_counts?.inscripciones >= 1000
        ? (props.stats_counts.inscripciones / 1000).toFixed(1) + "k"
        : props.stats_counts?.inscripciones || 0,
    icon: Zap,
    color: "text-amber-600",
    bg: "bg-amber-50",
    trend: "Total acumulado",
  },
]);

const recentActivity = [
  {
    id: 1,
    user: "Admin",
    action: "creó el evento",
    target: "Seminario de Hacienda",
    time: "hace 2 min",
  },
  {
    id: 2,
    user: "Comercial",
    action: "registró nuevo",
    target: "conferencista",
    time: "hace 15 min",
  },
  {
    id: 3,
    user: "Sistema",
    action: "envió correo a",
    target: "45 inscritos",
    time: "hace 1 hora",
  },
];

const headerStats = [
  {
    label: "Perfil",
    value: props.auth?.user?.perfil_organizador?.rol?.tipo_rol,
    icon: "verified_user",
    color: "text-emerald-500",
    bg: "bg-emerald-50",
  },
  {
    label: "Equipo",
    value: props.auth?.user?.perfil_organizador?.equipo?.nombre,
    icon: "check_circle",
    color: "text-blue-500",
    bg: "bg-blue-50",
  },
];

const isCreateModalOpen = ref(false);
const isSpeakerModalOpen = ref(false);
const isFormularioModalOpen = ref(false);

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
        class="mx-auto space-y-8 pb-12 animate-in fade-in slide-in-from-bottom-4 duration-1000"
      >
        <section
          class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10 animate-in fade-in slide-in-from-bottom-4 duration-700"
        >
          <div
            v-for="stat in stats"
            :key="stat.name"
            class="group relative bg-white/60 backdrop-blur-2xl p-7 rounded-[2.5rem] border border-white shadow-[0_10px_40px_rgba(0,0,0,0.03)] hover:shadow-xl hover:-translate-y-1 transition-all duration-500 overflow-hidden"
          >
            <div
              class="absolute -top-10 -right-10 w-32 h-32 bg-gradient-to-br from-white/40 to-transparent rounded-full blur-2xl"
            ></div>

            <div class="flex items-start justify-between relative z-10">
              <div
                :class="[
                  stat.bg,
                  stat.color,
                  'w-14 h-14 rounded-2xl flex items-center justify-center shadow-sm border border-white/50 transition-transform group-hover:scale-110 duration-500',
                ]"
              >
                <component :is="stat.icon" class="w-7 h-7" />
              </div>

              <div
                class="px-3 py-1.5 rounded-full bg-white/80 border border-white shadow-sm flex items-center gap-1.5"
              >
                <div class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></div>
                <span class="text-[11px] font-bold text-slate-500 leading-none">
                  {{ stat.trend }}
                </span>
              </div>
            </div>

            <div class="mt-6 relative z-10">
              <p class="text-sm font-semibold text-slate-400 mb-1 leading-none">
                {{ stat.name }}
              </p>
              <h3 class="text-4xl font-black text-slate-900 tracking-tight">
                {{ stat.value }}
              </h3>
            </div>
          </div>
        </section>

        <section class="grid grid-cols-1 lg:grid-cols-3 gap-8">
          <div class="lg:col-span-2 space-y-8">
            <div
              class="bg-gradient-to-br from-rose-600 to-rose-700 rounded-[3rem] p-10 text-white relative overflow-hidden shadow-2xl shadow-rose-200"
            >
              <div
                class="absolute -top-10 -right-10 w-64 h-64 bg-white/10 rounded-full blur-3xl"
              ></div>
              <div class="relative z-10 flex flex-col md:flex-row items-center gap-8">
                <div class="flex-1 text-center md:text-left">
                  <h3 class="text-3xl font-black mb-3">Control de Eventos</h3>
                  <p class="text-rose-100 font-medium mb-8 leading-relaxed">
                    Tienes {{ contadorEventos }} eventos próximos a iniciar esta semana.
                  </p>
                  <div class="flex flex-wrap justify-center md:justify-start gap-4">
                    <button
                      @click="isCreateModalOpen = true"
                      class="bg-white text-rose-600 font-bold py-3.5 px-8 rounded-2xl shadow-lg hover:bg-rose-50 transition-all active:scale-95 flex items-center gap-2"
                    >
                      <Plus class="w-5 h-5" /> Crear ahora
                    </button>
                    <Link
                      href="/admin/eventos/data"
                      class="bg-rose-500/30 backdrop-blur-md text-white border border-rose-400/30 font-bold py-3.5 px-8 rounded-2xl hover:bg-rose-500/50 transition-all"
                    >
                      Ver agenda
                    </Link>
                  </div>
                </div>
                <div
                  class="w-48 h-48 bg-white/10 backdrop-blur-sm rounded-[2.5rem] border border-white/20 flex items-center justify-center"
                >
                  <LayoutGrid class="w-20 h-20 text-white/50" />
                </div>
              </div>
            </div>

            <div
              class="bg-white rounded-[3rem] border border-slate-100 p-8 shadow-sm h-full flex flex-col"
            >
              <div class="flex items-center justify-between mb-8">
                <div>
                  <h4 class="text-xl font-black text-slate-900">
                    Agenda activa
                  </h4>
                  <p class="text-xs text-slate-400 font-medium">
                    Esta semana + 4 posteriores
                  </p>
                </div>
                <Link
                  href="/admin/eventos/calendario"
                  class="p-3 bg-slate-50 hover:bg-rose-50 text-slate-400 hover:text-rose-600 rounded-2xl transition-all flex items-center gap-3"
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
                      class="flex-none text-[16px] font-bold text-rose-600 bg-rose-50 px-3 py-1 rounded-full"
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
                      class="flex-none text-[16px] font-medium text-slate-400 bg-slate-50 px-3 py-1 rounded-full"
                      >Siguientes semanas</span
                    >
                    <div class="h-px bg-slate-100 flex-grow"></div>
                  </div>

                  <div
                    class="group relative flex items-start gap-4 p-4 rounded-[2rem] transition-all duration-300 border border-transparent hover:border-slate-100 hover:bg-slate-50/50"
                    :class="{ 'bg-rose-50/30 border-rose-100': evento.es_hoy }"
                  >
                    <div
                      class="flex-none w-14 h-16 rounded-2xl flex flex-col items-center justify-center transition-all"
                      :class="
                        evento.es_hoy
                          ? 'bg-rose-600 text-white shadow-lg shadow-rose-200'
                          : 'bg-slate-100 text-slate-900'
                      "
                    >
                      <span
                        class="text-[9px] font-black uppercase tracking-tighter opacity-70"
                        >{{ formatDate(evento.fecha).diaNombre }}</span
                      >
                      <span class="text-xl font-black leading-none">{{
                        formatDate(evento.fecha).diaNum
                      }}</span>
                    </div>

                    <div class="flex-grow min-w-0 pt-1">
                      <div class="flex items-center gap-2 mb-1">
                        <div
                          v-if="evento.es_hoy"
                          class="flex items-center gap-1 bg-rose-600 text-[8px] text-white px-1.5 py-0.5 rounded-md font-black uppercase animate-pulse"
                        >
                          <Star class="w-2 h-2 fill-current" /> hoy
                        </div>
                        <span
                          class="text-[9px] font-bold text-slate-400 uppercase tracking-widest truncate"
                          >{{ evento.area }}</span
                        >
                      </div>

                      <h5
                        class="text-sm font-bold text-slate-900 mb-2 truncate group-hover:text-rose-600 transition-colors"
                      >
                        {{ evento.titulo }}
                      </h5>

                      <div class="flex items-center gap-4">
                        <div class="flex items-center gap-1 text-slate-400">
                          <Clock class="w-3 h-3" />
                          <span class="text-[10px] font-bold">{{
                            formatDate(evento.fecha).hora
                          }}</span>
                        </div>
                        <div class="flex items-center gap-1 text-slate-400">
                          <MapPin
                            v-if="evento.modalidad === 'Presencial'"
                            class="w-3 h-3"
                          />
                          <Monitor v-else class="w-3 h-3" />
                          <span class="text-[10px] font-bold">{{
                            evento.modalidad === "Presencial"
                              ? evento.ubicacion
                              : "virtual"
                          }}</span>
                        </div>
                      </div>
                    </div>

                    <div
                      class="absolute right-4 top-1/2 -translate-y-1/2 w-1.5 h-8 rounded-full opacity-20 group-hover:opacity-100 transition-all"
                      :style="{ backgroundColor: evento.color }"
                    ></div>
                  </div>
                </div>
              </div>

              <div
                v-else
                class="flex flex-col items-center justify-center py-16 text-center flex-grow"
              >
                <div
                  class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mb-6"
                >
                  <Clock class="w-10 h-10 text-slate-100" />
                </div>
                <p
                  class="text-slate-400 font-medium text-sm max-w-[220px] mx-auto"
                >
                  no hay eventos programados para este periodo.
                </p>
              </div>

              <div class="mt-auto pt-6 border-t border-slate-50 flex justify-center">
                <p
                  class="text-[10px] font-black text-slate-300 uppercase tracking-[0.2em]"
                >
                  f&c consultores • gestión de agenda
                </p>
              </div>
            </div>
          </div>

          <div class="space-y-8">
            <div
              class="bg-white/70 backdrop-blur-xl rounded-[3rem] border border-white p-8 shadow-sm"
            >
              <h4 class="text-lg font-black text-slate-900 mb-6 flex items-center gap-2">
                <Activity class="w-5 h-5 text-rose-500" /> Actividad
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
                          ? 'bg-emerald-50 text-emerald-500'
                          : 'bg-blue-50 text-blue-500',
                      ]"
                    >
                      <span class="text-xs font-bold">{{ mov.user[0] }}</span>
                    </div>

                    <div class="flex-1 min-w-0">
                      <p class="text-[14px] text-slate-600 leading-snug">
                        <span class="font-bold text-slate-900">{{ mov.user }}</span>
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

            <div
              class="bg-slate-900 rounded-[3rem] p-8 text-white relative overflow-hidden group"
            >
              <div
                class="absolute inset-0 bg-gradient-to-tr from-rose-600/20 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"
              ></div>
              <h4 class="text-base font-bold mb-6 relative z-10">Herramientas</h4>
              <div class="grid grid-cols-2 gap-3 relative z-10">
                <button
                  class="p-4 rounded-2xl bg-white/10 hover:bg-white/20 transition-all text-center"
                >
                  <Users class="w-5 h-5 mx-auto mb-2 text-rose-400" />
                  <span class="text-[10px] font-bold block">Personal</span>
                </button>
                <button
                  class="p-4 rounded-2xl bg-white/10 hover:bg-white/20 transition-all text-center"
                >
                  <ShieldCheck class="w-5 h-5 mx-auto mb-2 text-emerald-400" />
                  <span class="text-[10px] font-bold block">Seguridad</span>
                </button>
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
      <CreateConferencistaModal
        :show="isSpeakerModalOpen"
        :areas="areas"
        @close="isSpeakerModalOpen = false"
        @success="
          isSpeakerModalOpen = false;
          isCreateModalOpen = true;
        "
      />
      <CreateFormularioModal
        :show="isFormularioModalOpen"
        @close="isFormularioModalOpen = false"
        @success="
          isFormularioModalOpen = false;
          isCreateModalOpen = true;
        "
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
