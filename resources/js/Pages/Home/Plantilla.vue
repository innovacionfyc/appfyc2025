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
    "flex flex-col lg:flex-row gap-6 lg:gap-8 w-full h-auto lg:h-[85dvh]";
  let temarioContainer = "w-full lg:w-7/12 flex flex-col h-auto lg:h-full";
  let sidebarContainer = "w-full lg:w-5/12 flex flex-col gap-6 h-auto lg:h-full min-h-0";

  if (estiloP === "invertido") {
    wrapperClass =
      "flex flex-col lg:flex-row-reverse gap-6 lg:gap-8 w-full h-auto lg:h-[85dvh]";
  } else if (estiloP === "minimalista") {
    wrapperClass =
      "flex flex-col gap-8 w-full h-auto lg:h-[85dvh] overflow-y-auto custom-scroll pr-0 lg:pr-4";
    temarioContainer = "w-full flex flex-col shrink-0 h-auto lg:h-fit";
    sidebarContainer =
      "w-full grid grid-cols-1 lg:grid-cols-2 gap-6 lg:gap-8 items-stretch shrink-0 pb-12";
  } else if (estiloP === "moderno") {
    wrapperClass = "flex flex-col xl:flex-row gap-6 lg:gap-10 w-full h-auto items-start";
    temarioContainer = "w-full xl:w-8/12 flex flex-col shrink-0 h-auto mb-6 lg:mb-12";
    sidebarContainer =
      "w-full xl:w-4/12 flex flex-col gap-6 lg:sticky lg:top-24 shrink-0";
  }

  // 2. Estilos del Temario (Módulos)
  let temarioGridClass = "flex flex-col gap-6 md:gap-8";
  if (estiloT === "cuadricula") {
    temarioGridClass = "grid grid-cols-1 md:grid-cols-2 gap-6 items-stretch";
  } else if (estiloT === "tarjetas") {
    temarioGridClass =
      "grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 items-stretch";
  } else if (estiloT === "compacto") {
    temarioGridClass = "flex flex-col gap-3";
  }

  // 3. Estilos de los Expertos
  // 3. Estilos de los Expertos
  let expertosGridClass = "space-y-3 pb-4 h-full";
  let expertoCardClass =
    "group relative bg-slate-50/50 hover:bg-white border border-transparent hover:border-slate-200 p-3 md:p-4 rounded-[1.2rem] md:rounded-[1.5rem] transition-all flex items-center gap-4 overflow-visible shadow-sm w-full";

  if (estiloE === "tarjetas") {
    expertoCardClass =
      "group relative bg-slate-50/50 hover:bg-white border border-transparent hover:border-slate-200 p-5 rounded-2xl transition-all flex flex-col items-center text-center shadow-sm w-full overflow-visible";
    if (totalExpertos === 1) {
      expertosGridClass =
        "flex flex-col items-center justify-center pb-4 flex-1 h-full max-h-[200px]";
      expertoCardClass += " max-w-sm mx-auto shadow-md ring-1 ring-slate-100";
    } else if (totalExpertos === 2) {
      expertosGridClass =
        "grid grid-cols-1 sm:grid-cols-2 gap-4 pb-4 flex-1 content-center max-h-[200px]";
    } else {
      expertosGridClass =
        "grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-2 xl:grid-cols-3 gap-3 pb-4 flex-1";
    }
  } else if (estiloE === "pildoras") {
    expertosGridClass = "flex flex-wrap gap-2 pb-4 flex-1";
    expertoCardClass =
      "group relative flex items-center gap-3 bg-white border border-slate-200 pr-4 p-1.5 rounded-full shadow-sm hover:shadow-md transition-all w-auto overflow-visible";
  } else if (estiloE === "minimalista") {
    expertosGridClass = "divide-y divide-slate-100 pb-4 flex-1";
    expertoCardClass =
      "group relative flex items-center gap-4 py-3 bg-transparent transition-all w-full hover:px-2 hover:bg-slate-50 rounded-lg overflow-visible";
  } else {
    if (totalExpertos === 1) {
      expertosGridClass = "flex flex-col justify-center pb-4 flex-1 h-full min-h-[150px]";
      expertoCardClass += " p-5 md:p-6 shadow-md ring-1 ring-slate-100";
    } else {
      expertosGridClass = "space-y-3 pb-8 flex-1";
    }
  }
  let cardPrecioClass = "";

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
  const mapaPrecios = [
    { label: "Jornada Completa", value: parseFloat(evento?.precio_jornada) || 0 },
    { label: "Seminario", value: parseFloat(evento?.precio_seminario) || 0 },
    { label: "Módulo Presencial", value: parseFloat(evento?.precio_modulo) || 0 },
    { label: "Módulo Virtual", value: parseFloat(evento?.precio_modulo_virtual) || 0 },
    { label: "Congreso Presencial", value: parseFloat(evento?.precio_cng) || 0 },
    { label: "Congreso Virtual", value: parseFloat(evento?.precio_cng_virtual) || 0 },
    { label: "Curso Intensivo Híbrido", value: parseFloat(evento?.precio_curso_intensivo_hibrido) || 0 },
    { label: "Curso Intensivo Virtual", value: parseFloat(evento?.precio_curso_intensivo_virtual) || 0 },
    { label: "Diplomado Híbrido", value: parseFloat(evento?.precio_diplomado_hibrido) || 0 },
    { label: "Diplomado Virtual", value: parseFloat(evento?.precio_diplomado_virtual) || 0 },
  ];

  return mapaPrecios.filter((item) => item.value > 0);
});

const selectedSpeaker = ref(null);

const openSpeakerModal = (speaker) => {
  selectedSpeaker.value = speaker;
  document.body.style.overflow = "hidden";
};

const closeSpeakerModal = () => {
  selectedSpeaker.value = null;
  document.body.style.overflow = "auto";
};
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
                class="flex flex-col bg-white border border-slate-200/60 rounded-[2rem] md:rounded-[2.5rem] shadow-xl shadow-slate-200/40 overflow-hidden h-full"
              >
                <div
                  class="px-6 py-5 md:px-8 md:py-6 border-b border-slate-100 bg-slate-50 flex flex-col md:flex-row items-start md:items-center justify-between shrink-0 gap-5 z-10"
                >
                  <h3
                    class="text-xl md:text-2xl font-black text-slate-800 flex items-center gap-3.5"
                  >
                    <div
                      class="w-10 h-10 md:w-12 md:h-12 rounded-2xl flex items-center justify-center shrink-0 bg-white shadow-sm ring-1 ring-slate-200/50"
                      :style="{
                        color: evento?.area_formacion?.color_hex_principal || '#3b82f6',
                      }"
                    >
                      <FileText class="w-5 h-5 md:w-6 md:h-6" />
                    </div>
                    Contenido temático
                  </h3>

                  <div class="flex items-center gap-3 flex-wrap">
                    <BtnSecundario
                      v-if="evento?.url_folleto && evento?.tipo_evento !== 'CI_CNG'"
                      :label="'Folleto informativo'"
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

                <div
                  class="flex-1 overflow-y-visible lg:overflow-y-auto p-6 md:p-8 custom-scroll bg-slate-50/50"
                >
                  <div
                    v-if="evento?.contenido_tematico?.modulos"
                    class="w-full"
                    :class="{
                      'flex flex-col gap-6':
                        evento?.estilo_temario === 'lista' || !evento?.estilo_temario,
                      'grid grid-cols-1 md:grid-cols-2 gap-6 items-stretch':
                        evento?.estilo_temario === 'cuadricula',
                      'grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-2 gap-6 items-stretch':
                        evento?.estilo_temario === 'tarjetas',
                      'flex flex-col gap-4': evento?.estilo_temario === 'compacto',
                    }"
                  >
                    <div
                      v-for="(modulo, index) in evento.contenido_tematico.modulos"
                      :key="index"
                      class="group relative flex flex-col bg-white border border-slate-200/70 rounded-3xl shadow-sm hover:shadow-md transition-all duration-300 hover:-translate-y-1"
                      :class="
                        evento?.estilo_temario === 'compacto' ? 'p-5' : 'p-6 md:p-7'
                      "
                    >
                      <div
                        class="absolute top-6 left-0 w-1 h-8 rounded-r-md opacity-50 transition-opacity duration-300 group-hover:opacity-100"
                        :style="{
                          background:
                            evento?.area_formacion?.color_hex_principal || '#3b82f6',
                        }"
                      ></div>

                      <div class="flex items-center gap-4 mb-4">
                        <div
                          class="flex flex-col items-center justify-center font-black rounded-xl shrink-0 border"
                          :class="
                            evento?.estilo_temario === 'compacto'
                              ? 'w-10 h-10 text-lg'
                              : 'w-12 h-12 text-xl'
                          "
                          :style="{
                            background:
                              evento?.area_formacion?.color_hex_principal + '10',
                            color:
                              evento?.area_formacion?.color_hex_principal || '#3b82f6',
                            borderColor:
                              evento?.area_formacion?.color_hex_principal + '30',
                          }"
                        >
                          {{ index + 1 < 10 ? "0" + (index + 1) : index + 1 }}
                        </div>

                        <div class="flex justify-center items-center pt-0.5">
                          <h4
                            class="font-extrabold text-slate-800 leading-snug w-full"
                            :class="
                              evento?.estilo_temario === 'compacto'
                                ? 'text-[15px]'
                                : 'text-[17px] md:text-[18px]'
                            "
                          >
                            {{ modulo.tema }} 
                          </h4>
                        </div>
                      </div>

                      <div
                        v-if="modulo.subtemas?.length"
                        class="h-px w-full bg-slate-100 mb-4"
                      ></div>

                      <ul
                        v-if="modulo.subtemas?.length"
                        class="flex flex-col flex-1 w-full"
                        :class="
                          evento?.estilo_temario === 'compacto'
                            ? 'space-y-2'
                            : 'space-y-3'
                        "
                      >
                        <li
                          v-for="(subtema, subIndex) in modulo.subtemas"
                          :key="subIndex"
                          class="flex items-start text-slate-600 transition-colors group/item hover:text-slate-900"
                          :class="
                            evento?.estilo_temario === 'compacto'
                              ? 'text-[13px] leading-snug'
                              : 'text-[14px] leading-relaxed'
                          "
                        >
                          <CheckCircle
                            class="shrink-0 transition-transform group-hover/item:scale-110"
                            :class="
                              evento?.estilo_temario === 'compacto'
                                ? 'w-4 h-4 mr-2.5 mt-[2px]'
                                : 'w-5 h-5 mr-3 mt-[1px]'
                            "
                            :style="{
                              color:
                                evento?.area_formacion?.color_hex_principal || '#3b82f6',
                            }"
                          />
                          <span class="w-full">{{ subtema }}</span>
                        </li>
                      </ul>
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
                  <div :class="[layoutConfig.expertosGrid, 'gap-3']">
                    <div
                      v-for="speaker in evento.conferencistas"
                      :key="speaker.id"
                      :class="[
                        layoutConfig.expertoCard,
                        'group relative cursor-pointer transition-all duration-200 hover:shadow-md border border-slate-100 bg-white',
                        {
                          'flex items-center gap-3 p-3 rounded-2xl':
                            evento.estilo_expertos === 'lista',
                          'flex flex-col items-center justify-center p-4 text-center rounded-2xl':
                            evento.estilo_expertos === 'tarjetas',
                          'flex items-center gap-2.5 px-3 py-2 rounded-xl':
                            evento.estilo_expertos === 'pildoras',
                          'flex items-center gap-2 p-2 rounded-lg':
                            evento.estilo_expertos === 'minimalista',
                        },
                      ]"
                      style="will-change: transform"
                      @click="openSpeakerModal(speaker)"
                    >
                      <!-- Avatar / Imagen condicional -->
                      <img
                        :src="
                          speaker.foto
                            ? '/storage/' + speaker.foto
                            : `https://ui-avatars.com/api/?name=${speaker.primer_nombre}`
                        "
                        :class="[
                          'object-cover transition-transform duration-300 group-hover:scale-105',
                          {
                            'w-14 h-14 rounded-2xl shadow-sm':
                              evento.estilo_expertos === 'lista',
                            'w-20 h-22 rounded-full shadow-md mb-2':
                              evento.estilo_expertos === 'tarjetas',
                            'w-9 h-9 rounded-full shadow-sm':
                              evento.estilo_expertos === 'pildoras',
                            'w-7 h-7 rounded-md':
                              evento.estilo_expertos === 'minimalista',
                          },
                        ]"
                      />

                      <!-- Información del conferencista -->
                      <div class="min-w-0 flex-1">
                        <h4
                          :class="[
                            'font-bold text-slate-900 ',
                            evento.estilo_expertos === 'tarjetas'
                              ? 'text-sm text-center'
                              : 'text-xs sm:text-sm',
                          ]"
                        >
                          {{ speaker.primer_nombre }} {{ speaker.segundo_nombre }}
                          {{ speaker.primer_apellido }} {{ speaker.segundo_apellido }}
                        </h4>

                        <p
                          v-if="evento.estilo_expertos === 'lista'"
                          class="text-[11px] text-slate-500 italic line-clamp-2 mt-0.5"
                        >
                          {{ speaker.biografia || "Consultor experto" }}
                        </p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

             <div :class="layoutConfig.cardPrecio">
                <!-- 1. MODO: MINIMALISTA (Gris Oscuro - Sobrio y profesional) -->
                <template
                  v-if="evento?.estilo_card === 'minimalista' || !evento?.estilo_card"
                >
                  <div
                    class="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-8 flex flex-col justify-between min-h-[350px] shadow-xl"
                  >
                    <div class="flex flex-col gap-5 h-full justify-between">
                      <div class="flex items-center justify-between">
                        <div>
                          <h3 class="text-xl font-black text-white tracking-tight">
                            Asegure su cupo
                          </h3>
                          <p
                            class="text-[10px] text-slate-500 font-bold uppercase tracking-widest mt-1"
                          >
                            Inversión para esta fecha
                          </p>
                        </div>
                        <div
                          class="flex items-center gap-2 bg-slate-800 px-3 py-1.5 rounded-full border border-slate-700 shrink-0"
                        >
                          <MapPin
                            class="w-3.5 h-3.5"
                            :style="{
                              color:
                                evento?.area_formacion?.color_hex_principal || '#fff',
                            }"
                          />
                          <span class="text-[12px] font-bold text-slate-300 uppercase">{{
                            evento?.modalidad
                          }}</span>
                        </div>
                      </div>

                      <!-- PRECIOS UNIFORMES EN COLUMNA -->
                      <div class="py-2 border-y border-slate-800 my-1">
                        <div v-if="preciosVisibles.length > 1" class="flex flex-col gap-2.5 max-h-[150px] overflow-y-auto custom-scroll pr-1">
                          <div
                            v-for="(item, idx) in preciosVisibles"
                            :key="idx"
                            class="flex w-full items-center justify-between bg-slate-800/50 border border-slate-700/50 px-4 py-2.5 rounded-2xl"
                          >
                            <div class="flex flex-col">
                              <span
                                class="text-[10px] text-slate-400 font-extrabold uppercase tracking-widest"
                                >{{ item.label }}</span
                              >
                              <span class="text-white text-lg font-black">{{
                                formatPrice(item.value)
                              }}</span>
                            </div>
                            <span class="text-[9px] text-slate-500 font-bold uppercase">IVA Incl.</span>
                          </div>
                        </div>
                        <div
                          v-else-if="preciosVisibles.length === 1"
                          class="flex flex-col py-2"
                        >
                          <p class="text-white text-4xl font-black">
                            {{ formatPrice(preciosVisibles[0].value) }}
                          </p>
                          <p
                            class="text-[10px] text-slate-500 font-black uppercase tracking-widest mt-1"
                          >
                            Inversión Total • IVA Incl.
                          </p>
                        </div>
                        <div v-else class="flex flex-col py-2">
                          <p class="text-white text-4xl font-black">Gratuito</p>
                          <p
                            class="text-[10px] text-slate-500 font-black uppercase tracking-widest mt-1"
                          >
                            Acceso Libre
                          </p>
                        </div>
                      </div>

                      <div class="space-y-4">
                        <div
                          v-if="evento?.ubicacion"
                          class="flex items-center gap-2 text-slate-400"
                        >
                          <Pin
                            class="w-4 h-4 shrink-0"
                            :style="{
                              color:
                                evento?.area_formacion?.color_hex_principal || '#fff',
                            }"
                          />
                          <p class="text-[15px] font-medium">
                            {{ evento.modalidad }} · {{ evento.ubicacion }}
                          </p>
                        </div>
                        <BtnUniversal
                          @click="showModal = true"
                          label="Inscribirme ahora"
                          icon="send"
                          icon-position="right"
                          size="lg"
                          class="w-full"
                          :activeColor="evento?.area_formacion?.color_hex_principal"
                        />
                      </div>
                    </div>
                  </div>
                </template>

                <!-- 2. MODO: DESTACADO (Gradiente con el color del evento) -->
                <template v-else-if="evento?.estilo_card === 'destacado'">
                  <div
                    class="rounded-3xl p-6 sm:p-8 flex flex-col justify-between min-h-[350px] shadow-2xl relative overflow-hidden"
                    :style="{
                      background: `linear-gradient(135deg, ${
                        evento?.area_formacion?.color_hex_principal || '#f97316'
                      }, #1e293b)`,
                    }"
                  >
                    <div
                      class="absolute top-0 right-0 w-full h-full opacity-10 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PGNpcmNsZSBjeD0iMiIgY3k9IjIiIHI9IjIiIGZpbGw9IiNmZmYiLz48L3N2Zz4=')]"
                    ></div>

                    <div class="relative z-10 flex flex-col gap-5 h-full justify-between">
                      <div class="flex items-center justify-between gap-2">
                        <div>
                          <h3 class="text-xl font-bold text-white tracking-tight">
                            Asegure su cupo
                          </h3>
                          <p
                            class="text-[10px] text-slate-50 font-bold uppercase tracking-widest mt-1"
                          >
                            Inversión para esta fecha
                          </p>
                        </div>
                        
                        <div
                          class="flex items-center gap-2 bg-black/20 px-4 py-1.5 rounded-full backdrop-blur-sm shrink-0"
                        >
                          <MapPin class="w-3.5 h-3.5 text-white" />
                          <span class="text-[12px] font-bold text-white uppercase">{{
                            evento?.modalidad
                          }}</span>
                        </div>
                      </div>

                      <!-- PRECIOS UNIFORMES EN COLUMNA -->
                      <div
                        class="bg-black/10 backdrop-blur-md rounded-2xl p-4 border border-white/20 my-1"
                      >
                        <div v-if="preciosVisibles.length > 1" class="flex flex-col gap-2.5 max-h-[150px] overflow-y-auto custom-scroll pr-1">
                          <div
                            v-for="(item, idx) in preciosVisibles"
                            :key="idx"
                            class="flex items-center justify-between border-b border-white/10 pb-2.5 last:border-0 last:pb-0"
                          >
                            <span
                              class="text-[11px] text-white/80 font-bold uppercase tracking-widest pr-2"
                              >{{ item.label }}</span
                            >
                            <div class="flex flex-col text-right shrink-0">
                              <span class="text-white text-lg font-black"
                                >{{ formatPrice(item.value) }}
                              </span>
                              <span class="text-[9px] text-white/70 font-light">IVA Incl.</span>
                            </div>
                          </div>
                        </div>
                        <div v-else-if="preciosVisibles.length === 1" class="text-center py-1">
                          <p class="text-white text-4xl font-black">
                            {{ formatPrice(preciosVisibles[0].value) }}
                          </p>
                          <p
                            class="text-[10px] text-white/70 font-black uppercase tracking-widest mt-1"
                          >
                            Inversión Total • IVA Incl.
                          </p>
                        </div>
                        <div v-else class="text-center py-1">
                          <p class="text-white text-4xl font-black">Gratuito</p>
                        </div>
                      </div>

                      <div class="space-y-4">
                        <div
                          v-if="evento?.ubicacion"
                          class="flex items-center justify-center gap-2 text-white/90"
                        >
                          <Pin class="w-4 h-4 shrink-0" />
                          <p class="text-[16px] font-medium text-center">
                            {{ evento.ubicacion }}
                          </p>
                        </div>
                        <BtnUniversal
                          @click="showModal = true"
                          label="Inscribirme ahora"
                          icon="send"
                          icon-position="right"
                          size="lg"
                          class="w-full shadow-xl bg-white text-slate-900 hover:bg-slate-100"
                        />
                      </div>
                    </div>
                  </div>
                </template>

                <!-- 3. MODO: GLASSMORPHISM (Claro, Transparente y Elegante) -->
                <template v-else-if="evento?.estilo_card === 'glass'">
                  <div
                    class="relative w-full rounded-3xl min-h-[350px] overflow-hidden bg-slate-50 border border-slate-200"
                  >
                    <div
                      class="absolute -bottom-20 -left-20 w-72 h-72 opacity-40 blur-[70px] rounded-full pointer-events-none"
                      :style="{
                        background:
                          evento?.area_formacion?.color_hex_principal || '#3b82f6',
                      }"
                    ></div>

                    <div
                      class="relative z-10 p-6 sm:p-8 h-full flex flex-col justify-between bg-white/40 backdrop-blur-xl shadow-[inset_0_1px_0_rgba(255,255,255,0.6)] border border-white/60"
                    >
                      <div class="flex items-center justify-between">
                        <div>
                          <h3 class="text-2xl font-black text-slate-800 tracking-tight">
                            Asegure su cupo
                          </h3>
                          <p
                            class="text-[10px] text-slate-500 font-bold uppercase tracking-widest mt-1"
                          >
                            Inversión para esta fecha
                          </p>
                        </div>
                        <div
                          class="flex items-center gap-2 bg-white/60 px-3 py-1.5 rounded-full border border-slate-200 shadow-sm shrink-0"
                        >
                          <MapPin
                            class="w-3.5 h-3.5"
                            :style="{
                              color:
                                evento?.area_formacion?.color_hex_principal || '#3b82f6',
                            }"
                          />
                          <span class="text-[12px] font-bold text-slate-700 uppercase">{{
                            evento?.modalidad
                          }}</span>
                        </div>
                      </div>

                      <!-- PRECIOS UNIFORMES EN COLUMNA -->
                      <div class="py-2 border-y border-slate-200/60 my-1">
                      <!-- Indicador visual flotante si hay más de 2 precios para guiar al usuario -->
  <div v-if="preciosVisibles.length > 2" class="absolute top-3 right-2 bg-slate-800 text-slate-300 text-[9px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wider shadow-sm border border-slate-700 animate-pulse pointer-events-none z-10">
    ↓ Desliza para ver más
  </div>
                        <div v-if="preciosVisibles.length > 1" class="flex flex-col gap-2.5 max-h-[150px] overflow-y-auto custom-scroll pr-1">
                          <div
                            v-for="(item, idx) in preciosVisibles"
                            :key="idx"
                            class="flex w-full items-center justify-between bg-white/50 border border-white px-4 py-2.5 rounded-2xl shadow-sm"
                          >
                            <div class="flex flex-col">
                              <span
                                class="text-[10px] text-slate-500 font-extrabold uppercase tracking-widest"
                                >{{ item.label }}</span
                              >
                              <span class="text-slate-800 text-lg font-black">{{
                                formatPrice(item.value)
                              }}</span>
                            </div>
                            <span class="text-[9px] text-slate-400 font-bold uppercase">IVA Incl.</span>
                          </div>
                        </div>
                        <div
                          v-else-if="preciosVisibles.length === 1"
                          class="flex flex-col py-2"
                        >
                          <p class="text-slate-800 text-4xl font-black">
                            {{ formatPrice(preciosVisibles[0].value) }}
                          </p>
                          <p
                            class="text-[10px] text-slate-500 font-black uppercase tracking-widest mt-1"
                          >
                            Inversión Total • IVA Incl.
                          </p>
                        </div>
                        <div v-else class="flex flex-col py-2">
                          <p class="text-slate-800 text-4xl font-black">Gratuito</p>
                        </div>
                      </div>

                      <div class="space-y-4">
                        <div
                          v-if="evento?.ubicacion"
                          class="flex items-center gap-2 text-slate-600"
                        >
                          <Pin
                            class="w-4 h-4 shrink-0"
                            :style="{
                              color:
                                evento?.area_formacion?.color_hex_principal || '#3b82f6',
                            }"
                          />
                          <p class="text-[16px] font-medium">
                            {{ evento.modalidad }} · {{ evento.ubicacion }}
                          </p>
                        </div>
                        <BtnUniversal
                          @click="showModal = true"
                          label="Inscribirme ahora"
                          icon="send"
                          icon-position="right"
                          size="lg"
                          class="w-full shadow-lg"
                          :activeColor="evento?.area_formacion?.color_hex_principal"
                        />
                      </div>
                    </div>
                  </div>
                </template>

                <!-- 4. MODO: NEÓN (Negro profundo con bordes y sombras brillantes) -->
                <template v-else-if="evento?.estilo_card === 'neon'">
                  <div
                    class="bg-slate-950 rounded-3xl p-6 sm:p-8 flex flex-col justify-between min-h-[350px] relative"
                    :style="{
                      border: `3px solid ${
                        evento?.area_formacion?.color_hex_principal || '#0ea5e9'
                      }`,
                      boxShadow: `5px 10px 25px ${
                        evento?.area_formacion?.color_hex_principal || '#0ea5e9'
                      }40, inset 0 0 15px ${
                        evento?.area_formacion?.color_hex_principal || '#0ea5e9'
                      }60`,
                    }"
                  >
                    <div class="flex flex-col gap-5 h-full justify-between relative z-10">
                      <div class="flex items-center justify-between">
                        <div>
                          <h3 class="text-2xl font-bold text-white tracking-tight">
                            Asegure su cupo
                          </h3>
                          <p
                            class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-1"
                          >
                            Inversión para esta fecha
                          </p>
                        </div>
                        <div
                          class="flex items-center gap-2 bg-black px-3 py-1.5 rounded-full shrink-0"
                          :style="{
                            border: `1px solid ${
                              evento?.area_formacion?.color_hex_principal || '#0ea5e9'
                            }50`,
                          }"
                        >
                          <MapPin
                            class="w-3.5 h-3.5"
                            :style="{
                              color:
                                evento?.area_formacion?.color_hex_principal || '#0ea5e9',
                            }"
                          />
                          <span class="text-[12px] font-bold text-white uppercase">{{
                            evento?.modalidad
                          }}</span>
                        </div>
                      </div>

                      <!-- PRECIOS UNIFORMES EN COLUMNA -->
                      <div
                        class="py-2 my-1"
                        :style="{
                          borderTop: `1px dashed ${
                            evento?.area_formacion?.color_hex_principal || '#0ea5e9'
                          }50`,
                          borderBottom: `1px dashed ${
                            evento?.area_formacion?.color_hex_principal || '#0ea5e9'
                          }50`,
                        }"
                      >
                        <div v-if="preciosVisibles.length > 1" class="flex flex-col gap-2.5 max-h-[150px] overflow-y-auto custom-scroll pr-1">
                          <div
                            v-for="(item, idx) in preciosVisibles"
                            :key="idx"
                            class="flex w-full items-center justify-between px-4 py-2.5 rounded-2xl bg-white/5"
                            :style="{
                              border: `1px solid ${
                                evento?.area_formacion?.color_hex_principal || '#0ea5e9'
                              }30`,
                            }"
                          >
                            <div class="flex flex-col">
                              <span
                                class="text-[10px] font-extrabold uppercase tracking-widest"
                                :style="{
                                  color:
                                    evento?.area_formacion?.color_hex_principal ||
                                    '#0ea5e9',
                                }"
                                >{{ item.label }}</span
                              >
                              <span class="text-white text-lg font-black">{{
                                formatPrice(item.value)
                              }}</span>
                            </div>
                            <span class="text-[9px] text-slate-400 font-bold uppercase">IVA Incl.</span>
                          </div>
                        </div>
                        <div v-else-if="preciosVisibles.length === 1" class="flex flex-col py-2">
                          <p
                            class="text-white text-4xl font-black"
                            :style="{
                              textShadow: `0 0 15px ${
                                evento?.area_formacion?.color_hex_principal || '#0ea5e9'
                              }80`,
                            }"
                          >
                            {{ formatPrice(preciosVisibles[0].value) }}
                          </p>
                          <p
                            class="text-[10px] text-slate-400 font-black uppercase tracking-widest mt-1"
                          >
                            Inversión Total • IVA Incl.
                          </p>
                        </div>
                        <div v-else class="flex flex-col py-2">
                          <p
                            class="text-white text-4xl font-black"
                            :style="{
                              textShadow: `0 0 15px ${
                                evento?.area_formacion?.color_hex_principal || '#0ea5e9'
                              }80`,
                            }"
                          >
                            Gratuito
                          </p>
                        </div>
                      </div>

                      <div class="space-y-4">
                        <div
                          v-if="evento?.ubicacion"
                          class="flex items-center gap-2 text-slate-300"
                        >
                          <Pin
                            class="w-4 h-4 shrink-0"
                            :style="{
                              color:
                                evento?.area_formacion?.color_hex_principal || '#0ea5e9',
                            }"
                          />
                          <p class="text-[16px] font-medium">
                            {{ evento.modalidad }} · {{ evento.ubicacion }}
                          </p>
                        </div>
                        <BtnUniversal
                          @click="showModal = true"
                          label="Inscribirme ahora"
                          icon="send"
                          icon-position="right"
                          size="lg"
                          class="w-full"
                          :activeColor="evento?.area_formacion?.color_hex_principal"
                        />
                      </div>
                    </div>
                  </div>
                </template>
              </div>
            </div>
          </div>
        </SectionPlantilla>
      </RevealSection>
    </div>

    <FormProvicionalModal :show="showModal" :evento="evento" @close="showModal = false" />
    <Teleport to="body">
      <Transition
        enter-active-class="transition ease-out duration-300"
        enter-from-class="opacity-0 translate-y-full sm:translate-y-4 sm:scale-95"
        enter-to-class="opacity-100 translate-y-0 sm:scale-100"
        leave-active-class="transition ease-in duration-200"
        leave-from-class="opacity-100 translate-y-0 sm:scale-100"
        leave-to-class="opacity-0 translate-y-full sm:translate-y-4 sm:scale-95"
      >
        <div
          v-if="selectedSpeaker"
          class="fixed inset-0 flex items-end sm:items-center justify-center p-0 sm:p-4"
          style="z-index: 99999"
        >
          <div
            class="absolute inset-0 bg-slate-900/60 backdrop-blur-md transition-opacity"
            @click="closeSpeakerModal"
          ></div>

          <div
            class="relative bg-white w-full max-w-[450px] max-h-[90vh] sm:max-h-[85vh] rounded-t-[2.5rem] sm:rounded-[2.5rem] shadow-2xl flex flex-col transform transition-all overflow-hidden"
          >
            <button
              @click="closeSpeakerModal"
              class="absolute top-4 right-4 sm:top-5 sm:right-5 p-2 bg-black/10 hover:bg-black/20 backdrop-blur-md border border-white/20 rounded-full text-white transition-all z-50"
            >
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2.5"
                  d="M6 18L18 6M6 6l12 12"
                ></path>
              </svg>
            </button>

            <div class="flex-1 w-full overflow-y-auto custom-scroll pb-8">
              <div
                class="h-32 sm:h-36 w-full relative shrink-0"
                :style="{
                  background: `linear-gradient(135deg, ${
                    evento?.area_formacion?.color_hex_principal || '#f97316'
                  } 0%, #0f172a 100%)`,
                }"
              >
                <div
                  class="absolute -bottom-10 -right-10 w-40 h-40 bg-white/10 rounded-full blur-2xl"
                ></div>
                <div
                  class="absolute top-[-20%] left-[-10%] w-32 h-32 bg-white/5 rounded-full blur-xl"
                ></div>
              </div>

              <div class="px-6 sm:px-8 flex flex-col items-center relative">
                <div class="relative -mt-16 sm:-mt-20 mb-4 shrink-0 z-10">
                  <img
                    :src="
                      selectedSpeaker.foto
                        ? '/storage/' + selectedSpeaker.foto
                        : `https://ui-avatars.com/api/?name=${selectedSpeaker.primer_nombre}`
                    "
                    class="w-32 h-32 sm:w-40 sm:h-40 rounded-[2rem] sm:rounded-[2.5rem] object-cover shadow-2xl ring-[6px] ring-white bg-white"
                  />
                  <div
                    class="absolute -bottom-2 -right-2 bg-white rounded-full p-1.5 shadow-md border border-slate-50"
                  >
                    <ShieldCheck
                      class="w-6 h-6"
                      :style="{
                        color: evento?.area_formacion?.color_hex_principal || '#f97316',
                      }"
                    />
                  </div>
                </div>

                <div class="text-center mb-6 shrink-0 w-full">
                  <h4
                    class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight leading-none mb-3"
                  >
                    {{ selectedSpeaker.primer_nombre }}
                    {{ selectedSpeaker.segundo_nombre }} <br>
                    {{ selectedSpeaker.primer_apellido }}
                    {{ selectedSpeaker.segundo_apellido }}
                  </h4>
                  <div class="flex items-center justify-center gap-2">
                    <span
                      class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-slate-100 border border-slate-200 text-[10px] sm:text-[11px] font-black text-slate-600 uppercase tracking-widest"
                    >
                      <span
                        class="w-2 h-2 rounded-full animate-pulse"
                        :style="{
                          background:
                            evento?.area_formacion?.color_hex_principal || '#f97316',
                        }"
                      ></span>
                      Consultor Experto
                    </span>
                  </div>
                </div>

                <div
                  class="w-full bg-slate-50 border border-slate-100 rounded-[1.5rem] p-5 sm:p-6 relative shrink-0"
                >
                  <svg
                    class="absolute -top-4 -left-2 w-10 h-10 text-slate-200/80 transform -rotate-6"
                    fill="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z"
                    />
                  </svg>

                  <div
                    class="relative z-10 text-slate-600 text-[13px] sm:text-[14px] leading-relaxed text-center sm:text-justify font-medium"
                  >
                    {{
                      selectedSpeaker.biografia ||
                      "Perfil profesional detallado en proceso de actualización por nuestro equipo académico."
                    }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
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
