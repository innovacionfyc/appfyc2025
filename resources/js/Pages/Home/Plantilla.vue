<script setup>
import { Head, usePage, Link } from "@inertiajs/vue3";
import { ref, onMounted, onUnmounted, computed } from "vue";
import HeaderNav from "@/Components/HeaderNav.vue";
import SectionPlantilla from "@/Components/SectionPlantilla.vue";
import {
  Calendar,
  MapPin,
  CheckCircle,
  FileText,
  Download,
  ChevronDown,
  Users,
  Plus,
  ShieldCheck,
  Info,
  Phone,
  Mail,
  MessageCircle,
  Pin,
} from "lucide-vue-next";
import InscripcionModal from "@/Components/Formularios/InscripcionModal.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";
import BtnSecundario from "@/Components/Shared/buttons/btnSecundario.vue";
import RevealSection from "@/Components/RevealSection.vue";
import MensajesLayout from "@/Layouts/MensajesLayout.vue";
import GuestLayout from "@/Layouts/GuestLayout.vue";
import FormProvicionalModal from "@/Components/Formularios/FormProvicionalModal.vue";

const { props } = usePage();
const evento = props.evento;

const formatPrice = (value) => {
  if (!value) return "Gratuito";
  return new Intl.NumberFormat("es-CO", {
    style: "currency",
    currency: "COP",
    maximumFractionDigits: 0,
  }).format(value);
};

const formatDate = (dateString) => {
  if (!dateString) return "Fecha por definir";
  const options = {
    weekday: "long",
    year: "numeric",
    month: "long",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  };
  const date = new Date(dateString).toLocaleDateString("es-CO", options);
  return date.charAt(0).toUpperCase() + date.slice(1);
};

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

const showModal = ref(false);

const timeLeft = ref({ days: 0, hours: 0, minutes: 0, seconds: 0 });
const isLive = ref(false);
let timer = null;

const startCountdown = () => {
  const targetDate = new Date(evento.fecha_hora_inicio).getTime();
  const endDate = new Date(evento.fecha_hora_fin).getTime();

  timer = setInterval(() => {
    const now = new Date().getTime();
    const distance = targetDate - now;
    const distanceEnd = endDate - now;

    if (distance <= 0 && distanceEnd > 0) {
      isLive.value = true;
      timeLeft.value = { days: 0, hours: 0, minutes: 0, seconds: 0 };
      return;
    }

    if (distanceEnd <= 0) {
      isLive.value = false;
      clearInterval(timer);
      return;
    }

    isLive.value = false;
    timeLeft.value.days = Math.floor(distance / (1000 * 60 * 60 * 24));
    timeLeft.value.hours = Math.floor(
      (distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60)
    );
    timeLeft.value.minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
    timeLeft.value.seconds = Math.floor((distance % (1000 * 60)) / 1000);
  }, 1000);
};

const isScrolled = ref(false);
const scrollContainer = ref(null);

const handleScroll = (e) => {
  isScrolled.value = e.target.scrollTop > 300;
};
onMounted(() => {
  if (evento?.fecha_hora_inicio) startCountdown();

  if (scrollContainer.value) {
    scrollContainer.value.addEventListener("scroll", handleScroll);
  }
});

onUnmounted(() => {
  clearInterval(timer);
  if (scrollContainer.value) {
    scrollContainer.value.removeEventListener("scroll", handleScroll);
  }
});

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
      return `${getDayName(start).toLowerCase()} ${getDayNum(
        start
      )} (${timeStartStr}) al ${getDayName(end).toLowerCase()} ${getDayNum(
        end
      )} (${timeEndStr}) de ${getMonth(start)} de ${getYear(start)}`;
    }
    return `${getDayName(start).toLowerCase()} ${getDayNum(start)} al ${getDayName(
      end
    ).toLowerCase()} ${getDayNum(end)} de ${getMonth(start)} de ${getYear(start)}`;
  }

  if (start.getFullYear() === end.getFullYear()) {
    return `${getDayNum(start)} de ${getMonth(start)} al ${getDayNum(end)} de ${getMonth(
      end
    )} de ${getYear(start)}`;
  }

  return `${getDayNum(start)} de ${getMonth(start)} de ${getYear(start)} al ${getDayNum(
    end
  )} de ${getMonth(end)} de ${getYear(end)}`;
};

const layoutConfig = computed(() => {
  const estiloP = evento?.estilo_plantilla || "clasico";
  const estiloT = evento?.estilo_temario || "lista";
  const estiloE = evento?.estilo_expertos || "lista";
  const estiloC = evento?.estilo_card || "minimalista";
  const totalExpertos = evento?.conferencistas?.length || 0;

  // ==========================================
  // 1. Estructura general de la Plantilla
  // ==========================================
  let wrapperClass =
    "flex flex-col lg:flex-row gap-6 lg:gap-8 w-full h-full lg:h-[85dvh]";
  let temarioContainer = "w-full lg:w-7/12 flex flex-col h-fit lg:h-full";
  let sidebarContainer = "w-full lg:w-5/12 flex flex-col gap-6 h-full min-h-0";

  if (estiloP === "invertido") {
    wrapperClass =
      "flex flex-col lg:flex-row-reverse gap-6 lg:gap-8 w-full h-full lg:h-[85dvh]";
  } else if (estiloP === "minimalista") {
    // Apilada
    wrapperClass =
      "flex flex-col gap-8 w-full h-full lg:h-[85dvh] overflow-y-auto custom-scroll pr-2 lg:pr-4";
    temarioContainer = "w-full flex flex-col shrink-0 h-fit";
    sidebarContainer =
      "w-full grid grid-cols-1 lg:grid-cols-2 gap-6 lg:gap-8 items-stretch shrink-0 pb-12";
  } else if (estiloP === "moderno") {
    // Moderna (Ancha)
    wrapperClass =
      "flex flex-col xl:flex-row gap-6 lg:gap-10 w-full h-full lg:h-auto items-start";
    temarioContainer = "w-full xl:w-8/12 flex flex-col shrink-0 h-fit mb-12";
    sidebarContainer = "w-full xl:w-4/12 flex flex-col gap-6 sticky top-24 shrink-0";
  }

  // ==========================================
  // 2. Estilos del Temario (Módulos)
  // ==========================================
  let temarioGridClass = "flex flex-col gap-6 md:gap-8";

  if (estiloT === "cuadricula") {
    temarioGridClass = "grid grid-cols-1 md:grid-cols-2 gap-6 items-stretch";
  } else if (estiloT === "tarjetas") {
    temarioGridClass =
      "grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 items-stretch";
  } else if (estiloT === "compacto") {
    // Compacto
    temarioGridClass = "flex flex-col gap-3";
  }

  // ==========================================
  // 3. Estilos de los Expertos
  // ==========================================
  let expertosGridClass = "space-y-3 pb-4 h-full";
  let expertoCardClass =
    "group relative bg-slate-50/50 hover:bg-white border border-transparent hover:border-slate-200 p-3 md:p-4 rounded-[1.2rem] md:rounded-[1.5rem] transition-all flex items-center gap-4 overflow-hidden shadow-sm w-full";

  if (estiloE === "tarjetas") {
    expertoCardClass =
      "group relative bg-slate-50/50 hover:bg-white border border-transparent hover:border-slate-200 p-5 rounded-2xl transition-all flex flex-col items-center text-center shadow-sm w-full";
    if (totalExpertos === 1) {
      expertosGridClass =
        "flex flex-col items-center justify-center pb-4 flex-1 h-full min-h-[200px]";
      expertoCardClass += " max-w-sm mx-auto shadow-md ring-1 ring-slate-100";
    } else if (totalExpertos === 2) {
      expertosGridClass =
        "grid grid-cols-1 sm:grid-cols-2 gap-4 pb-4 flex-1 content-center min-h-[200px]";
    } else {
      expertosGridClass =
        "grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-2 xl:grid-cols-3 gap-3 pb-4 flex-1";
    }
  } else if (estiloE === "pildoras") {
    // Píldoras
    expertosGridClass = "flex flex-wrap gap-2 pb-4 flex-1";
    expertoCardClass =
      "group flex items-center gap-3 bg-white border border-slate-200 pr-4 p-1.5 rounded-full shadow-sm hover:shadow-md transition-all w-auto";
  } else if (estiloE === "minimalista") {
    // Solo texto minimalista
    expertosGridClass = "divide-y divide-slate-100 pb-4 flex-1";
    expertoCardClass =
      "group flex items-center gap-4 py-3 bg-transparent transition-all w-full hover:px-2 hover:bg-slate-50 rounded-lg";
  } else {
    // Lista por defecto
    if (totalExpertos === 1) {
      expertosGridClass = "flex flex-col justify-center pb-4 flex-1 h-full min-h-[150px]";
      expertoCardClass += " p-5 md:p-6 shadow-md ring-1 ring-slate-100";
    } else {
      // Esto asegura que la lista tenga un tope visual incluso si hay scroll
      expertosGridClass = "space-y-3 pb-8 flex-1";
    }
  }

  // ==========================================
  // 4. Estilos de la Caja de Precios
  // ==========================================
  let cardPrecioClass =
    "relative overflow-hidden bg-gradient-to-br from-[#0B192C] to-[#081121] rounded-[2rem] p-6 md:p-7 shadow-xl shrink-0 border border-white/10 isolate";

  if (estiloC === "destacado") {
    cardPrecioClass =
      "relative overflow-hidden bg-gradient-to-br from-slate-900 via-slate-600 to-slate-950 rounded-[2.5rem] p-7 shadow-2xl shrink-0 border border-purple-500/30 isolate ring-1 ring-purple-500/20";
  } else if (estiloC === "glass") {
    // Glassmorphism
    cardPrecioClass =
      "relative overflow-hidden bg-slate-900/80 backdrop-blur-xl rounded-[2.5rem] p-7 shadow-2xl shrink-0 border border-white/20 isolate";
  } else if (estiloC === "neon") {
    // Neón
    cardPrecioClass =
      "relative overflow-hidden bg-slate-950 rounded-[2.5rem] p-7 shadow-[0_0_40px_-10px_rgba(var(--color-primary),0.5)] shrink-0 border-2 isolate border-slate-700 hover:border-slate-500 transition-colors duration-500";
  }

  return {
    wrapper: wrapperClass,
    temario: temarioContainer,
    sidebar: sidebarContainer,
    temarioGrid: temarioGridClass,
    expertosGrid: expertosGridClass,
    expertoCard: expertoCardClass,
    cardPrecio: cardPrecioClass,
  };
});

const preciosVisibles = computed(() => {
  const tipo = evento?.tipo_evento;

  const lista = [];

  const mapaPrecios = {
    jornada: {
      label: "Jornada Completa",
      value: parseFloat(evento?.precio_jornada) || 0,
    },

    seminario: { label: "Seminario", value: parseFloat(evento?.precio_seminario) || 0 },

    diplomado: { label: "Diplomado", value: parseFloat(evento?.precio_diplomado) || 0 },

    modulo: { label: "Por Módulo", value: parseFloat(evento?.precio_modulo) || 0 },

    cng: { label: "Congreso", value: parseFloat(evento?.precio_cng) || 0 },

    curso_intensivo: {
      label: "Curso Intensivo",
      value: parseFloat(evento?.precio_curso_intensivo) || 0,
    },
  };

  if (tipo === "CI_CNG") {
    if (mapaPrecios.curso_intensivo.value > 0) lista.push(mapaPrecios.curso_intensivo);

    if (mapaPrecios.cng.value > 0) lista.push(mapaPrecios.cng);
  } else if (tipo === "JOR_MOD") {
    if (mapaPrecios.jornada.value > 0) lista.push(mapaPrecios.jornada);
    if (mapaPrecios.modulo.value > 0) lista.push(mapaPrecios.modulo);
  } else {
    const campoDirecto = {
      SEMINARIO: "seminario",
      JORNADA: "jornada",
      MODULO: "modulo",
      CNG: "cng",
      CURSO_INTENSIVO: "curso_intensivo",
      DIPLOMADO: "diplomado",
    }[tipo];

    if (campoDirecto && mapaPrecios[campoDirecto].value > 0) {
      lista.push(mapaPrecios[campoDirecto]);
    } else {
      const fallbackValido = Object.values(mapaPrecios).find((p) => p.value > 0);

      if (fallbackValido) lista.push(fallbackValido);
    }
  }

  return lista;
});
</script>

<template>
  <Head>
    <title>{{ evento?.titulo }}</title>

    <meta name="description" :content="evento?.subtitulo + ': ' + evento?.titulo" />
  </Head>

  <GuestLayout>
    <div
      ref="scrollContainer"
      class="h-screen w-full overflow-y-scroll snap-y snap-mandatory scroll-smooth bg-white relative"
    >
      <div class="fixed top-0 w-full z-50">
        <HeaderNav />
      </div>

      <div
        class="fixed z-[55] pointer-events-none transition-all duration-1000 ease-[cubic-bezier(0.68,-0.55,0.27,1.55)]"
        :class="[
          isScrolled
            ? '-bottom-14 right-0 w-40 md:w-[295px] rotate-[-10deg] -translate-x-2'
            : 'bottom-[-200px] md:bottom-[-250px] left-4 md:left-10 w-[300px] md:w-[500px] rotate-0',
        ]"
      >
        <img
          src="/images/buho_fyc.png"
          alt="Búho F&C"
          class="w-full h-auto drop-shadow-2xl transition-transform duration-700"
          :class="{ 'scale-x-[-1]': !isScrolled }"
        />
      </div>

      <RevealSection>
        <SectionPlantilla
          class="snap-start h-dvh w-full relative flex items-center justify-center overflow-hidden bg-[#0B192C]"
        >
          <div class="absolute inset-0 z-0">
            <img
              :src="
                evento?.imagen_relacionada
                  ? '/storage/' + evento.imagen_relacionada
                  : '/images/default-bg.webp'
              "
              class="w-full h-full object-cover opacity-50 mix-blend-overlay"
              :alt="evento?.titulo"
            />
            <div
              class="absolute inset-0 bg-gradient-to-t from-[#0B192C] via-[#0B192C]/0 to-transparent"
            ></div>
          </div>

          <div class="relative z-10 text-center px-6 max-w-6xl mx-auto mt-12 md:mt-16">
            <div class="mb-6 md:mb-8 flex justify-center">
              <img
                :src="getAreaTagImage()"
                :alt="evento?.area_formacion?.nombre || 'Área de formación'"
                class="h-14 md:h-24 w-auto object-contain drop-shadow-lg hover:scale-105 transition-transform duration-300"
              />
            </div>

            <p
              v-if="evento?.modo_evento"
              class="text-lg md:text-3xl text-white font-medium max-w-3xl mx-auto md:mb-5"
            >
              · {{ evento.modo_evento }} ·
            </p>
            <h1
              class="text-[40px] sm:text-5xl md:text-[72px] font-black text-white leading-tight mb-5 drop-shadow-2xl"
            >
              {{ evento?.titulo }}
            </h1>
            <p
              v-if="evento?.subtitulo"
              class="text-lg md:text-3xl text-white font-medium max-w-3xl mx-auto md:mb-6"
            >
              {{ evento.subtitulo }}
            </p>
            <div
              class="flex flex-col sm:flex-row items-center justify-center gap-4 sm:gap-8 text-white font-medium bg-black/30 w-full sm:w-fit mx-auto px-6 py-4 rounded-3xl backdrop-blur-md border border-white/10"
            >
              <span class="flex items-center gap-2 text-sm md:text-base">
                <Calendar
                  class="w-5 h-5"
                  :style="{
                    color: evento?.area_formacion?.color_hex_principal || '#f97316',
                  }"
                />
                {{ formatEventRange(evento?.fecha_hora_inicio, evento?.fecha_hora_fin) }}
              </span>
              <span class="flex items-center gap-2 text-sm md:text-base">
                <MapPin
                  class="w-5 h-5"
                  :style="{
                    color: evento?.area_formacion?.color_hex_principal || '#f97316',
                  }"
                />
                Modalidad {{ evento?.modalidad }}
              </span>
            </div>
            <div class="my-10">
              <div
                v-if="isLive"
                class="flex flex-col items-center justify-center animate-in fade-in zoom-in duration-700"
              >
                <div class="relative flex items-center justify-center">
                  <div
                    class="absolute inset-0 rounded-full blur-xl opacity-20 animate-pulse"
                    :style="{
                      background:
                        evento?.area_formacion?.color_hex_principal || '#f97316',
                    }"
                  ></div>

                  <div
                    class="relative px-8 py-4 rounded-3xl border shadow-2xl flex items-center gap-4"
                    :style="{
                      background:
                        evento?.area_formacion?.color_hex_principal || '#f97316',
                      border: evento?.area_formacion?.color_hex_principal || '#f97316',
                    }"
                  >
                    <div class="flex gap-1">
                      <span class="w-2 h-2 bg-white rounded-full animate-bounce"></span>
                      <span
                        class="w-2 h-2 bg-white rounded-full animate-bounce [animation-delay:-0.15s]"
                      ></span>
                      <span
                        class="w-2 h-2 bg-white rounded-full animate-bounce [animation-delay:-0.3s]"
                      ></span>
                    </div>
                    <span class="text-2xl font-black text-white"> En ejecución </span>
                  </div>
                </div>
                <p class="mt-4 text-[18px] font-bold text-mono-blanco">
                  Jornada académica en curso
                </p>
              </div>

              <div v-else class="relative flex justify-center gap-6">
                <div
                  v-for="(val, unit) in timeLeft"
                  :key="unit"
                  class="flex flex-col items-center min-w-[70px]"
                >
                  <div class="relative flex items-center justify-center mb-1">
                    <span
                      class="text-4xl sm:text-6xl font-black text-mono-blanco tabular-nums tracking-tighter"
                    >
                      {{ val < 10 ? "0" + val : val }}
                    </span>
                  </div>

                  <span
                    class="text-[10px] font-black text-slate-400 uppercase tracking-widest"
                  >
                    {{
                      unit === "days"
                        ? "Días"
                        : unit === "hours"
                        ? "Horas"
                        : unit === "minutes"
                        ? "Min"
                        : "Seg"
                    }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <div
            class="absolute bottom-8 right-16 -translate-x-1/2 flex flex-col items-center animate-bounce text-white/50 hidden sm:flex"
          >
            <span class="text-xs uppercase tracking-widest font-bold mb-2">Desliza</span>
            <ChevronDown class="w-6 h-6" />
          </div>
        </SectionPlantilla>
      </RevealSection>

      <RevealSection>
        <SectionPlantilla
          class="snap-start min-h-dvh w-full flex items-center justify-center bg-slate-50 relative py-20 lg:py-24"
        >
          <div
            class="max-w-[1500px] mx-auto px-4 sm:px-6 lg:px-12 w-full"
            :class="layoutConfig.wrapper"
          >
            <div :class="layoutConfig.temario">
              <div
                class="flex flex-col bg-white border border-slate-200 rounded-[2rem] md:rounded-[2.5rem] shadow-xl overflow-hidden h-full"
              >
                <div
                  class="px-6 py-4 md:px-8 md:py-6 border-b border-slate-100 bg-slate-50/50 flex flex-col md:flex-row items-start md:items-center justify-between shrink-0 gap-4"
                >
                  <h3
                    class="text-xl md:text-2xl font-black text-slate-900 flex items-center gap-3"
                  >
                    <div
                      class="w-9 h-9 md:w-10 md:h-10 rounded-xl flex items-center justify-center shadow-inner shrink-0"
                      :style="{
                        background: evento?.area_formacion?.color_hex_principal + '15',
                        color: evento?.area_formacion?.color_hex_principal,
                      }"
                    >
                      <FileText class="w-5 h-5" />
                    </div>
                    Contenido Temático
                  </h3>

                  <div class="flex items-center gap-3 flex-wrap">
                    <BtnSecundario
                      v-if="evento?.url_folleto && evento?.tipo_evento !== 'CI_CNG'"
                      :label="'Folleto ' + evento.tipo_evento"
                      icon="download"
                      target="_blank"
                      :href="'/storage/' + evento.url_folleto"
                      :activeColor="evento?.area_formacion?.color_hex_principal"
                    />

                    <BtnSecundario
                      v-if="evento?.url_folleto && evento?.tipo_evento === 'CI_CNG'"
                      label="Folleto Curso"
                      icon="download"
                      target="_blank"
                      :href="'/storage/' + evento.url_folleto"
                      :activeColor="evento?.area_formacion?.color_hex_principal"
                    />

                    <BtnSecundario
                      v-if="
                        evento?.url_folleto_secundario && evento?.tipo_evento === 'CI_CNG'
                      "
                      label="Folleto Congreso"
                      icon="download"
                      target="_blank"
                      :href="'/storage/' + evento.url_folleto_secundario"
                      :activeColor="evento?.area_formacion?.color_hex_principal"
                    />
                  </div>
                </div>

                <div class="flex-1 overflow-y-auto p-6 md:p-8 custom-scroll">
                  <div
                    v-if="evento?.contenido_tematico?.modulos"
                    :class="layoutConfig.temarioGrid"
                  >
                    <div
                      v-for="(modulo, index) in evento.contenido_tematico.modulos"
                      :key="index"
                      :class="[
                        'relative flex flex-col h-full bg-white border border-slate-200 rounded-[2rem] p-6 shadow-sm hover:shadow-xl transition-all duration-300 overflow-hidden',
                        evento.estilo_temario === 'tarjetas' ? 'p-6' : '',
                      ]"
                    >
                      <div
                        :class="
                          modulo.subtemas?.length
                            ? ''
                            : 'flex-1 flex flex-col justify-center'
                        "
                      >
                        <div class="flex items-start gap-4 mb-2 shrink-0 min-h-[20px]">
                          <span
                            class="text-4xl font-black shrink-0 mt-[-4px]"
                            :style="{
                              color: evento?.area_formacion?.color_hex_principal,
                            }"
                          >
                            {{ index + 1 < 10 ? "0" + (index + 1) : index + 1 }}.
                          </span>
                        </div>

                        <div
                          :class="
                            modulo.subtemas?.length
                              ? 'mb-4 text-[17px]'
                              : 'mb-0  text-[23px]'
                          "
                        >
                          <h4
                            class="font-extrabold text-slate-900 leading-snug w-full text-justify uppercase"
                          >
                            {{ modulo.tema }}
                          </h4>
                        </div>
                      </div>

                      <ul
                        v-if="modulo.subtemas?.length"
                        class="flex flex-col gap-2 flex-1 w-full"
                      >
                        <li
                          v-for="(subtema, subIndex) in modulo.subtemas"
                          :key="subIndex"
                          class="flex items-start text-slate-600 text-[13px] leading-relaxed group"
                        >
                          <CheckCircle
                            class="w-4 h-4 mr-3 shrink-0 mt-0.5 opacity-70"
                            :style="{
                              color: evento?.area_formacion?.color_hex_principal,
                            }"
                          />
                          <span class="text-justify w-full">{{ subtema }}</span>
                        </li>
                      </ul>

                      <div
                        class="w-full h-1.5 mt-6 rounded-full opacity-80 shrink-0"
                        :style="{
                          background: evento?.area_formacion?.color_hex_principal,
                        }"
                      ></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div :class="layoutConfig.sidebar">
              <div
                class="bg-white border border-slate-200 rounded-[2rem] md:rounded-[2.5rem] p-5 md:p-6 shadow-lg flex-1 flex flex-col min-h-0 overflow-hidden"
              >
                <div class="mb-4 shrink-0">
                  <h3
                    class="text-md md:text-sm font-bold text-mono-negro uppercase flex items-center gap-2"
                  >
                    <Users
                      class="w-4 h-4"
                      :style="{ color: evento?.area_formacion?.color_hex_principal }"
                    />
                    Equipo Académico ({{ evento.conferencistas.length }})
                  </h3>
                </div>

                <div
                  class="flex-1 overflow-y-auto custom-scroll pr-1 mt-1 min-h-[350px] max-h-[400px] will-change-transform"
                >
                  <div :class="layoutConfig.expertosGrid">
                    <div
                      v-for="speaker in evento.conferencistas"
                      :key="speaker.id"
                      :class="[layoutConfig.expertoCard, 'group relative cursor-pointer']"
                      style="will-change: transform"
                    >
                      <template v-if="evento.estilo_expertos === 'pildoras'">
                        <img
                          :src="
                            speaker.foto
                              ? '/storage/' + speaker.foto
                              : `https://ui-avatars.com/api/?name=${speaker.primer_nombre}`
                          "
                          class="w-8 h-8 rounded-full object-cover"
                        />
                        <span class="text-xs font-bold truncate"
                          >{{ speaker.primer_nombre }} {{ speaker.segundo_nombre }}
                          {{ speaker.primer_apellido }}</span
                        >
                      </template>

                      <template v-else-if="evento.estilo_expertos === 'tarjetas'">
                        <img
                          :src="
                            speaker.foto
                              ? '/storage/' + speaker.foto
                              : `https://ui-avatars.com/api/?name=${speaker.primer_nombre}`
                          "
                          class="w-16 h-16 rounded-full object-cover mb-2"
                        />
                        <span class="text-xs font-black text-center"
                          >{{ speaker.primer_nombre }} {{ speaker.segundo_nombre }}
                          {{ speaker.primer_apellido }}</span
                        >
                      </template>

                      <template v-else-if="evento.estilo_expertos === 'minimalista'">
                        <img
                          :src="
                            speaker.foto
                              ? '/storage/' + speaker.foto
                              : `https://ui-avatars.com/api/?name=${speaker.primer_nombre}`
                          "
                          class="w-6 h-6 rounded-md object-cover"
                        />
                        <span class="text-sm font-semibold"
                          >{{ speaker.primer_nombre }} {{ speaker.segundo_nombre }}
                          {{ speaker.primer_apellido }}</span
                        >
                      </template>

                      <template v-else>
                        <img
                          :src="
                            speaker.foto
                              ? '/storage/' + speaker.foto
                              : `https://ui-avatars.com/api/?name=${speaker.primer_nombre}`
                          "
                          class="w-14 h-14 rounded-2xl object-cover"
                        />
                        <div class="min-w-0 flex-1">
                          <h4 class="text-sm font-black text-slate-900 truncate">
                            {{ speaker.primer_nombre }} {{ speaker.segundo_nombre }}
                            {{ speaker.primer_apellido }}
                          </h4>
                          <p class="text-[11px] text-slate-500 italic line-clamp-2">
                            {{ speaker.biografia || "Consultor experto" }}
                          </p>
                        </div>
                      </template>

                      <!-- <div class="absolute z-[100] left-0 top-full mt-2 w-80 p-5 bg-white border border-slate-100 rounded-3xl shadow-[0_20px_50px_rgba(0,0,0,0.15)] opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 translate-y-2 group-hover:translate-y-0 pointer-events-none">
                        <div
                          class="absolute -top-1 left-0 w-full h-2 bg-gradient-to-b from-slate-50 to-transparent rounded-t-3xl"
                        ></div>

                        <div class="flex items-center gap-4 mb-4">
                          <img
                            :src="
                              speaker.foto
                                ? '/storage/' + speaker.foto
                                : `https://ui-avatars.com/api/?name=${speaker.primer_nombre}`
                            "
                            class="w-14 h-14 rounded-2xl object-cover shadow-md ring-4 ring-slate-50"
                          />
                          <div>
                            <h5
                              class="text-sm font-black uppercase text-slate-900 tracking-wide"
                            >
                              {{ speaker.primer_nombre }} {{ speaker.primer_apellido }}
                            </h5>
                            <span
                              class="inline-block mt-1 px-2 py-0.5 rounded-md bg-orange-50 text-[9px] font-black text-orange-600 uppercase tracking-widest border border-orange-100"
                            >
                              Consultor Experto
                            </span>
                          </div>
                        </div>

                        <p
                          class="text-[12px] leading-relaxed text-slate-600 text-justify bg-slate-50 p-3 rounded-xl border border-slate-100"
                        >
                          {{
                            speaker.biografia ||
                            "Perfil profesional en actualización constante."
                          }}
                        </p>

                        <div
                          class="absolute -top-2 left-1/2 -translate-x-1/2 w-4 h-4 bg-white border-l border-t border-slate-100 rotate-45 shadow-[-2px_-2px_5px_rgba(0,0,0,0.02)]"
                        ></div>
                      </div> -->
                    </div>
                  </div>
                </div>
              </div>

              <div :class="layoutConfig.cardPrecio">
                <div
                  class="absolute -top-10 -right-10 w-48 h-48 opacity-20 blur-[60px] pointer-events-none rounded-full"
                  :style="{
                    background: evento?.area_formacion?.color_hex_principal || '#f97316',
                  }"
                ></div>

                <div class="relative z-10 flex flex-col gap-5 h-full justify-between">
                  <div class="flex items-center justify-between">
                    <div>
                      <h3 class="text-xl font-black text-white tracking-tight">
                        Asegura tu cupo
                      </h3>
                      <p
                        class="text-[10px] text-slate-500 font-bold uppercase tracking-widest"
                      >
                        Inversión para esta fecha
                      </p>
                    </div>
                    <div
                      class="flex items-center gap-2 bg-white/5 px-3 py-1.5 rounded-full border border-white/10 shrink-0"
                    >
                      <MapPin
                        class="w-3.5 h-3.5"
                        :style="{ color: evento?.area_formacion?.color_hex_principal }"
                      />
                      <span class="text-[10px] font-bold text-slate-300 uppercase">{{
                        evento?.modalidad
                      }}</span>
                    </div>
                  </div>

                  <div
                    class="flex items-center justify-between py-4 border-y border-white/5"
                  >
                    <div class="w-full">
                      <div
                        v-if="preciosVisibles.length > 1"
                        class="w-full space-y-3.5 py-1"
                      >
                        <div
                          v-for="(item, idx) in preciosVisibles"
                          :key="idx"
                          class="flex items-center justify-between bg-white/5 border border-white/10 px-4 py-3 rounded-2xl backdrop-blur-sm shadow-sm"
                        >
                          <div class="flex flex-col">
                            <span class="text-white text-2xl font-black tracking-tight">
                              {{ formatPrice(item.value) }}
                            </span>
                            <span
                              class="text-[10px] text-slate-400 font-extrabold uppercase tracking-widest mt-0.5"
                            >
                              {{ item.label }} • IVA Incl.
                            </span>
                          </div>

                          <div
                            class="w-2 h-2 rounded-full opacity-60 shrink-0"
                            :style="{
                              backgroundColor:
                                evento?.area_formacion?.color_hex_principal,
                            }"
                          ></div>
                        </div>
                      </div>

                      <div v-else-if="preciosVisibles.length === 1" class="flex flex-col">
                        <p class="text-white text-4xl font-black tracking-tighter">
                          {{ formatPrice(preciosVisibles[0].value) }}
                        </p>
                        <p
                          class="text-[10px] text-slate-500 font-black uppercase tracking-widest mt-1"
                        >
                          Inversión Total • IVA Incl.
                        </p>
                      </div>

                      <div v-else class="flex flex-col">
                        <p class="text-white text-3xl font-black tracking-tighter">
                          Gratuito
                        </p>
                        <p
                          class="text-[10px] text-slate-500 font-black uppercase tracking-widest mt-1"
                        >
                          Acceso Libre • Entrada Gratuita
                        </p>
                      </div>
                    </div>
                  </div>

                  <div
                    v-if="evento?.ubicacion"
                    class="flex items-center gap-2 text-slate-300"
                  >
                    <Pin
                      class="w-4 h-4 shrink-0"
                      :style="{ color: evento?.area_formacion?.color_hex_principal }"
                    />
                    <p class="text-[14px] font-medium italic">
                      {{ evento.modalidad }} · {{ evento.ubicacion }}
                    </p>
                  </div>

                  <BtnUniversal
                    @click="showModal = true"
                    label="Inscribirme ahora"
                    icon="send"
                    icon-position="right"
                    size="lg"
                    :activeColor="evento?.area_formacion?.color_hex_principal"
                  />
                </div>
              </div>
            </div>
          </div>
        </SectionPlantilla>
      </RevealSection>
    </div>

    <FormProvicionalModal :show="showModal" :evento="evento" @close="showModal = false" />
  </GuestLayout>
</template>

<style>
div::-webkit-scrollbar {
  display: none;
}
div {
  -ms-overflow-style: none;
  scrollbar-width: none;
}

.custom-scroll::-webkit-scrollbar {
  display: block;
  width: 5px;
}
.custom-scroll::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scroll::-webkit-scrollbar-thumb {
  background-color: #e2e8f0;
  border-radius: 20px;
}

@media (max-width: 1024px) {
  .snap-y {
    scroll-snap-type: none;
  }
}

@keyframes spin-slow {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}
.animate-spin-slow {
  animation: spin-slow 12s linear infinite;
}

@media (max-width: 1024px) {
  .snap-y {
    scroll-snap-type: none;
  }
}

/* Esto hace que el elemento ignore el overflow del padre sin romper el layout */
.group:hover .dropdown-content {
  display: block;
}

/* Y en tu configuración de tailwind, si el padre tiene overflow-hidden, 
   el dropdown debe ser: */
.dropdown-content {
  position: absolute;
  /* ... */
  z-index: 9999;
}
</style>
