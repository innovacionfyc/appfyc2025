<script setup>
import { ref, computed, nextTick } from "vue";
import { Head, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import {
  Archive,
  Calendar,
  MapPin,
  Edit3,
  Trash2,
  Search,
  Users,
  Copy,
  LayoutGrid,
} from "lucide-vue-next";

// Componentes Reutilizables
import BtnUniversal from "@/Components/BtnUniversal.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import PreviewEventModal from "@/Components/Eventos/PreviewEventModal.vue";
import CreateEventModal from "@/Components/Eventos/CreateEventModal.vue";

const props = defineProps({
  eventos: Array,
  areas: Array,
  estados: Array,
  conferencistas: Array,
  organizador: Array,
  formularios: Array,
});

const searchQuery = ref("");
const isPreviewOpen = ref(false);
const selectedEventForPreview = ref(null);
const isModalOpen = ref(false);
const modalMode = ref("edit");
const selectedEvento = ref(null);

// Filtrado de búsqueda
const filteredEventos = computed(() => {
  return props.eventos.filter((e) =>
    e.titulo.toLowerCase().includes(searchQuery.value.toLowerCase())
  );
});

// Stats para el Header
const headerStats = computed(() => [
  {
    label: "Total Archivados",
    value: props.eventos.length,
    icon: "archive",
    color: "text-indigo-500",
    bg: "bg-indigo-50",
  }
]);

// Lógica de eliminación definitiva
const deleteEvent = (id) => {
  if (confirm("¿Eliminar permanentemente? Esta acción es irreversible y borrará archivos y registros.")) {
    router.delete(route("eventos.destroy", id), { 
        preserveScroll: true,
        onSuccess: () => alert("Evento eliminado del sistema.") 
    });
  }
};

const openPreview = (evento) => {
  selectedEventForPreview.value = evento;
  isPreviewOpen.value = true;
};

const openModal = (evento, mode) => {
  selectedEvento.value = evento;
  modalMode.value = mode;
  isModalOpen.value = true;
};

const formatPrice = (val) => new Intl.NumberFormat("es-CO", { style: "currency", currency: "COP", maximumFractionDigits: 0 }).format(val);

const getAreaTagImage = (areaNombre) => {
  const imagenesPorArea = {
    Jurídica: "/images/areasFormacion/juridica_web.png",
    "Talento Humano": "/images/areasFormacion/talento_humano_web.png",
    "Gestión y Políticas Públicas": "/images/areasFormacion/gestion_publica_web.png",
    "Enfoques Misionales": "/images/areasFormacion/enfoque_misional_web.png",
    "Finanzas y Hacienda Pública": "/images/areasFormacion/finanzas_publicas_web.png",
  };
  return imagenesPorArea[areaNombre] || "/images/areasFormacion/formacion_defecto_web.png";
};
</script>

<template>
  <Head title="Archivo Histórico" />

  <AuthenticatedLayout>
    <Sidebar>
      <DashboardHeader
        title="Archivo Histórico"
        subtitle="Gestión de eventos finalizados o fuera de circulación"
        :stats="headerStats"
      />

      <div class="p-8 max-w-[1700px] mx-auto space-y-10">
        <div class="flex flex-col lg:flex-row justify-between items-center gap-6 bg-slate-900 p-8 rounded-[3rem] shadow-2xl relative overflow-hidden">
          <div class="relative z-10 flex items-center gap-6">
            <div class="w-16 h-16 bg-indigo-500/20 backdrop-blur-xl rounded-[2rem] flex items-center justify-center border border-white/10">
              <Archive class="w-8 h-8 text-indigo-400" />
            </div>
            <div>
              <h2 class="text-3xl font-black text-white">Eventos Archivados</h2>
              <p class="text-slate-400 text-xs font-bold uppercase tracking-[0.3em]">Estado_ID: 5 (Historial)</p>
            </div>
          </div>

          <div class="relative z-10 w-full lg:w-[450px]">
            <Search class="absolute left-5 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-500" />
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Buscar en el archivo..."
              class="w-full pl-14 pr-6 py-5 bg-white/5 border-none rounded-full text-white placeholder:text-slate-600 focus:ring-2 focus:ring-indigo-500/50 focus:bg-white/10 transition-all"
            />
          </div>
        </div>

        <div v-if="filteredEventos.length > 0" class="grid grid-cols-1 md:grid-cols-2 2xl:grid-cols-3 gap-10">
          <div
            v-for="evento in filteredEventos"
            :key="evento.id"
            class="group bg-white rounded-[3.5rem] border border-slate-100 shadow-sm hover:shadow-xl transition-all duration-500 flex flex-col overflow-hidden"
          >
            <div class="relative h-48 overflow-hidden">
              <img
                :src="evento.imagen_relacionada ? '/storage/' + evento.imagen_relacionada : '/images/default-bg.webp'"
                class="w-full h-full object-cover grayscale opacity-60 group-hover:grayscale-0 group-hover:opacity-100 transition-all duration-700"
              />
              <div class="absolute inset-0 bg-slate-900/40 flex items-center justify-center opacity-100 group-hover:opacity-0 transition-opacity">
                 <span class="px-4 py-2 bg-white/10 backdrop-blur-md rounded-full text-white text-[10px] font-black uppercase tracking-widest border border-white/20">archivado</span>
              </div>
            </div>

            <div class="p-8 space-y-6 flex-1 flex flex-col">
              <div class="flex items-center gap-4">
                <img :src="getAreaTagImage(evento.area_formacion?.nombre)" class="h-12 w-auto object-contain opacity-50" />
                <div>
                    <h3 class="font-black text-slate-800 text-xl leading-tight line-clamp-2 lowercase">{{ evento.titulo }}</h3>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">{{ evento.area_formacion?.nombre }}</p>
                </div>
              </div>

              <div class="space-y-3 pt-4 border-t border-slate-50">
                <div class="flex items-center gap-3 text-slate-500">
                    <Calendar class="w-4 h-4" />
                    <span class="text-xs font-bold">{{ new Date(evento.fecha_hora_inicio).toLocaleDateString() }}</span>
                </div>
                <div class="flex items-center gap-3 text-slate-500">
                    <Users class="w-4 h-4" />
                    <span class="text-xs font-bold">{{ evento.conferencistas?.length }} Conferencistas</span>
                </div>
              </div>

              <div class="flex items-center gap-3 pt-6 mt-auto">
                <button @click="openPreview(evento)" class="flex-grow py-4 bg-slate-100 hover:bg-indigo-600 hover:text-white text-slate-600 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all">
                    ver detalles
                </button>
                
                <div class="flex gap-2 bg-slate-50 p-1.5 rounded-2xl border border-slate-100">
                  <button @click="openModal(evento, 'edit')" class="w-10 h-10 flex items-center justify-center bg-white text-slate-400 rounded-xl hover:text-blue-600 transition-colors shadow-sm">
                    <Edit3 class="w-4 h-4" />
                  </button>
                  <button @click="deleteEvent(evento.id)" class="w-10 h-10 flex items-center justify-center bg-white text-slate-400 rounded-xl hover:text-red-600 transition-colors shadow-sm">
                    <Trash2 class="w-4 h-4" />
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-else class="py-40 flex flex-col items-center justify-center text-center">
            <div class="w-24 h-24 bg-slate-50 rounded-full flex items-center justify-center mb-6">
                <Archive class="w-10 h-10 text-slate-200" />
            </div>
            <h3 class="text-xl font-black text-slate-900 lowercase">archivo vacío</h3>
            <p class="text-slate-400 text-sm max-w-xs mx-auto">no se han encontrado eventos con el estado de archivado actualmente.</p>
        </div>
      </div>

      <PreviewEventModal :show="isPreviewOpen" :evento="selectedEventForPreview" @close="isPreviewOpen = false" />
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
      />
    </Sidebar>
  </AuthenticatedLayout>
</template>