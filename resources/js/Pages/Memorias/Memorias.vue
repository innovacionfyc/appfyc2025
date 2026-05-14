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
    label: "total memorias",
    value: 0,
    icon: "calendar_today",
    color: "text-red-500",
    bg: "bg-red-50",
  }
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
  <Head title="Memorias de eventos" />

  <AuthenticatedLayout>
    <Sidebar>
      <DashboardHeader
        title="Memorias de eventos"
        subtitle="Apartado para la visualizacion de memorias web por evento"
        :stats="headerStats"
      />

      
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
