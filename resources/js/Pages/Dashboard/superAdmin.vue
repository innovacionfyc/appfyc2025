<script setup>
import { ref } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import { useAuthStore } from "@/stores/auth";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";

// IMPORTAMOS EL NUEVO COMPONENTE
import CreateEventModal from "@/Components/Eventos/CreateEventModal.vue";
import {
  Users,
  Calendar,
  TrendingUp,
  ShieldCheck,
  LogOut,
  ChevronRight,
  Activity,
} from "lucide-vue-next";
import CreateConferencistaModal from "@/Components/Conferencistas/CreateConferencistaModal.vue";
import CreateFormularioModal from "@/Components/Formularios/CreateFormularioModal.vue";

const props = defineProps({
    auth: Object,
    estados: { type: Array, default: () => [] },
    areas: { type: Array, default: () => [] },
    formularios: { type: Array, default: () => [] },
    conferencistas: { type: Array, default: () => [] },
});
const authStore = useAuthStore();

// KPIs simulados
const stats = [
  {
    name: "Eventos Activos",
    value: "12",
    icon: Calendar,
    color: "text-blue-600",
    bg: "bg-blue-100",
  },
  {
    name: "Conferencistas",
    value: "48",
    icon: Users,
    color: "text-indigo-600",
    bg: "bg-indigo-100",
  },
  {
    name: "Inscripciones Mes",
    value: "1,240",
    icon: TrendingUp,
    color: "text-emerald-600",
    bg: "bg-emerald-100",
  },
  {
    name: "Ingresos Estimados",
    value: "$45.2M",
    icon: Activity,
    color: "text-purple-600",
    bg: "bg-purple-100",
  },
];

// LÓGICA REDUCIDA AL MÍNIMO
const isCreateModalOpen = ref(false);
const isSpeakerModalOpen = ref(false);
const isFormularioModalOpen = ref(false);

// Cuando se hace click en "Crear ahora" dentro del modal de Evento
const handleOpenDependency = (dependencyType) => {
  isCreateModalOpen.value = false; // Cerramos el de evento

  // Abrimos el que corresponda
  if (dependencyType === "conferencistas") {
    setTimeout(() => (isSpeakerModalOpen.value = true), 300); // Pequeño delay para transición suave
  }
   if (dependencyType === 'formularios') isFormularioModalOpen.value = true;
  // if (dependencyType === 'areas') { isAreaModalOpen.value = true; } // Futuro
};


</script>

<template>
  <Head title="Panel de Control | F&C Consultores" />


  <AuthenticatedLayout>

  <div class="min-h-screen bg-slate-100/50 flex">
    <Sidebar />

    <main class="flex-1 w-full md:pl-[320px] p-4 md:p-8 overflow-y-auto">
      <div
        class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4 mt-12 md:mt-0"
      >
        <div>
          <h2 class="text-2xl font-bold text-slate-900 tracking-tight">
            Bienvenido, {{ authStore.primerNombre }}
          </h2>
          <p class="text-slate-500 mt-1 flex items-center gap-2 text-sm font-medium">
            <ShieldCheck class="w-4 h-4 text-indigo-500" /> Sesión iniciada como
            {{ authStore.rolDescripcion }}
          </p>
        </div>
        <Link
          :href="route('logout')"
          method="post"
          as="button"
          class="inline-flex items-center justify-center gap-2 px-4 py-2 bg-white border border-slate-200 text-sm font-semibold text-red-600 hover:bg-red-50 hover:border-red-100 rounded-xl transition-all shadow-sm group"
        >
          <LogOut
            class="w-4 h-4 text-red-400 group-hover:text-red-600 transition-colors"
          />
          Finalizar Sesión
        </Link>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div
          v-for="stat in stats"
          :key="stat.name"
          class="bg-white p-6 rounded-3xl shadow-sm border border-slate-100 flex items-center gap-4"
        >
          <div
            :class="[
              stat.bg,
              stat.color,
              'w-14 h-14 rounded-2xl flex items-center justify-center shrink-0',
            ]"
          >
            <component :is="stat.icon" class="w-7 h-7" />
          </div>
          <div>
            <p class="text-sm font-semibold text-slate-500">{{ stat.name }}</p>
            <h3 class="text-2xl font-bold text-slate-900 mt-1">{{ stat.value }}</h3>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div
          class="lg:col-span-2 bg-white rounded-3xl shadow-sm border border-slate-100 p-6 flex flex-col justify-center items-center min-h-[300px] text-center"
        >
          <Calendar class="w-12 h-12 text-slate-200 mb-4" />
          <h4 class="text-lg font-bold text-slate-900">Gestión de Eventos Recientes</h4>
          <p class="text-slate-500 text-sm mt-2 max-w-sm mb-6">
            Aún no hay eventos registrados en esta área corporativa.
          </p>

          <button
            @click="isCreateModalOpen = true"
            class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2.5 px-6 rounded-xl shadow-sm transition-colors flex items-center gap-2"
          >
            Crear Nuevo Evento <ChevronRight class="w-4 h-4" />
          </button>
        </div>

        <div class="bg-white rounded-3xl shadow-sm border border-slate-100 p-6">
          <h4 class="text-base font-bold text-slate-900 mb-6">Detalles de la Cuenta</h4>
          <div class="space-y-4">
            <div>
              <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">
                Correo Corporativo
              </p>
              <p class="text-sm font-medium text-slate-900 mt-1">
                {{ authStore.correoPrincipal }}
              </p>
            </div>
            <div class="w-full h-px bg-slate-100"></div>
            <div>
              <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">
                Documento
              </p>
              <p class="text-sm font-medium text-slate-900 mt-1">
                {{ authStore.tipoDocumento }} - {{ authStore.numeroDocumentoUsuario }}
              </p>
            </div>
          </div>
        </div>
      </div>
    </main>

    <CreateEventModal
      :show="isCreateModalOpen"
      :estados="estados"
      :areas="areas"
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
    @success="isFormularioModalOpen = false; isCreateModalOpen = true" 
  />
  </div>

  </AuthenticatedLayout>

</template>
