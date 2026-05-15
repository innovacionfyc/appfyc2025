<script setup>
import { Head, usePage, Link } from "@inertiajs/vue3";
import { ref, onMounted, onUnmounted } from "vue";
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
  <Head>
    <title>{{ evento?.titulo }}</title>

    <meta name="description" :content="evento?.subtitulo + ': ' + evento?.titulo" />
    <meta name="theme-color" :content="activeColor" key="theme-color" />
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
            ? '-bottom-14 right-0 w-40 md:w-[280px] rotate-[-10deg] -translate-x-2'
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
                class="h-20 md:h-28 w-auto object-contain drop-shadow-lg hover:scale-105 transition-transform duration-300"
              />
            </div>

            <p
              v-if="evento?.modo_evento"
              class="text-lg md:text-3xl text-white font-medium max-w-3xl mx-auto md:mb-5"
            >
              · {{ evento.modo_evento }} ·
            </p>
            <h1
              class="text-5xl sm:text-5xl md:text-[80px] font-black text-white leading-tight mb-2 drop-shadow-2xl"
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
            class="absolute bottom-8 left-1/2 -translate-x-1/2 flex flex-col items-center animate-bounce text-white/50 hidden sm:flex"
          >
            <span class="text-xs uppercase tracking-widest font-bold mb-2">Desliza</span>
            <ChevronDown class="w-6 h-6" />
          </div>
        </SectionPlantilla>
      </RevealSection>

      <RevealSection>
        <SectionPlantilla
          class="snap-start min-h-dvh lg:h-dvh w-full flex items-center justify-center bg-slate-50 relative py-20 lg:py-16"
        >
          <div
            class="max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-12 grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8 w-full h-full lg:h-[85dvh]"
          >
            <div
              class="lg:col-span-7 flex flex-col bg-white border border-slate-200 rounded-[2rem] md:rounded-[2.5rem] shadow-xl overflow-hidden h-fit lg:h-full"
            >
              <div
                class="px-6 py-4 md:px-8 md:py-6 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between shrink-0"
              >
                <div class="">
                  <h3
                    class="text-xl md:text-2xl font-black text-slate-900 flex items-center gap-3"
                  >
                    <div
                      class="w-9 h-9 md:w-10 md:h-10 rounded-xl flex items-center justify-center shadow-inner"
                      :style="{
                        background: evento?.area_formacion?.color_hex_principal + '15',
                        color: evento?.area_formacion?.color_hex_principal || '#f97316',
                      }"
                    >
                      <FileText class="w-5 h-5" />
                    </div>
                    Contenido Temático
                  </h3>
                </div>

                <BtnSecundario
                  v-if="evento?.url_folleto"
                  label="Folleto informativo"
                  icon="download"
                  icon-position="left"
                  target="_blank"
                  :href="'/storage/' + evento.url_folleto"
                  :activeColor="evento?.area_formacion?.color_hex_principal"
                />
              </div>

              <div class="flex-1 overflow-y-auto p-6 md:p-8 custom-scroll bg-white">
                <div
                  v-if="evento?.contenido_tematico?.modulos"
                  class="space-y-6 md:space-y-8"
                >
                  <div
                    v-for="(modulo, index) in evento.contenido_tematico.modulos"
                    :key="index"
                    class="relative"
                  >
                    <div class="flex items-center gap-4 mb-4">
                      <span
                        class="text-3xl md:text-4xl font-black"
                        :style="{
                          background: evento?.area_formacion?.color_hex_principal + '15',
                          color: evento?.area_formacion?.color_hex_principal || '#f97316',
                        }"
                      >
                        {{ index + 1 < 10 ? "0" + (index + 1) : index + 1 }}.
                      </span>
                      <h4
                        class="text-lg md:text-xl font-bold text-slate-800 leading-tight border-b-2 pb-1"
                        :style="{
                          borderBlockColor:
                            evento?.area_formacion?.color_hex_principal || '#f97316',
                        }"
                      >
                        {{ modulo.tema }}
                      </h4>
                    </div>
                    <ul
                      class="grid gap-3 bg-slate-50/50 rounded-2xl p-5 border border-slate-100"
                      :class="
                        modulo.subtemas.length === 1
                          ? 'grid-cols-1'
                          : 'grid-cols-1 md:grid-cols-2'
                      "
                    >
                      <li
                        v-for="(subtema, subIndex) in modulo.subtemas"
                        :key="subIndex"
                        class="flex items-start text-slate-600 text-sm group"
                        :class="{
                          'col-span-1 md:col-span-2': modulo.subtemas.length === 1,
                        }"
                      >
                        <CheckCircle
                          class="w-4 h-4 mr-2 shrink-0 mt-0.5 transition-transform group-hover:scale-110"
                          :style="{
                            color:
                              evento?.area_formacion?.color_hex_principal || '#10b981',
                          }"
                        />
                        <span class="font-medium leading-relaxed">{{ subtema }}</span>
                      </li>
                    </ul>
                  </div>
                </div>
              </div>
            </div>

            <div class="lg:col-span-5 flex flex-col gap-6 h-full min-h-0">
              <div
                class="bg-white border border-slate-200 rounded-[2rem] md:rounded-[2.5rem] p-5 md:p-6 shadow-lg flex-1 flex flex-col min-h-0 overflow-hidden group/box"
              >
                <div class="mb-4 md:mb-6 shrink-0 px-2">
                  <div class="flex items-center justify-between mb-2">
                    <h3
                      class="text-xs md:text-sm font-black text-slate-900 uppercase tracking-widest flex items-center gap-2"
                    >
                      <Users
                        class="w-4 h-4"
                        :style="{
                          color: evento?.area_formacion?.color_hex_principal || '#f97316',
                        }"
                      />
                      Equipo Académico
                    </h3>
                    <span class="text-[10px] font-bold text-slate-400"
                      >{{ evento.conferencistas?.length }} Expertos</span
                    >
                  </div>
                  <div class="w-full h-1 bg-slate-100 rounded-full overflow-hidden">
                    <div
                      class="h-full rounded-full"
                      :style="{
                        background:
                          evento?.area_formacion?.color_hex_principal || '#f97316',
                        width: '35%',
                      }"
                    ></div>
                  </div>
                </div>

                <div class="flex-1 overflow-y-auto custom-scroll pr-1">
                  <div class="space-y-3 pb-4">
                    <div
                      v-for="speaker in evento.conferencistas"
                      :key="speaker.id"
                      class="group relative bg-slate-50/50 hover:bg-white border border-transparent hover:border-slate-200 p-3 md:p-4 rounded-[1.2rem] md:rounded-[1.5rem] transition-all flex items-center gap-4 overflow-hidden shadow-sm"
                    >
                      <div class="relative shrink-0">
                        <img
                          :src="
                            speaker.foto
                              ? '/storage/' + speaker.foto
                              : `https://ui-avatars.com/api/?name=${speaker.primer_nombre}+${speaker.primer_apellido}&background=0B192C&color=fff`
                          "
                          class="relative w-16 h-16 md:w-20 md:h-20 rounded-2xl object-cover border-2 border-white shadow-sm z-10"
                        />
                      </div>
                      <div class="min-w-0 flex-1">
                        <span
                          class="text-[10px] font-bold"
                          :style="{
                            color:
                              evento?.area_formacion?.color_hex_principal || '#f97316',
                          }"
                          >Consultor experto</span
                        >
                        <h4
                          class="font-bold text-slate-900 text-sm md:text-base leading-tight truncate"
                        >
                          {{ speaker.primer_nombre }} {{ speaker.segundo_nombre }}
                          {{ speaker.primer_apellido }}
                        </h4>
                        <p
                          class="text-[11px] text-slate-500 line-clamp-2 italic font-medium"
                        >
                          "{{ speaker.biografia || "Especialista consultor." }}"
                        </p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div
                class="relative overflow-hidden bg-gradient-to-br from-[#0B192C] to-[#081121] rounded-[2rem] p-6 md:p-7 shadow-xl shrink-0 border border-white/10 isolate"
              >
                <div
                  class="absolute -top-10 -right-10 w-48 h-48 opacity-20 blur-[60px] pointer-events-none"
                  :style="{
                    background: evento?.area_formacion?.color_hex_principal || '#f97316',
                  }"
                ></div>

                <div class="relative z-10 flex flex-col gap-4">
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
                      class="flex items-center gap-2 bg-white/5 px-3 py-1.5 rounded-full border border-white/10"
                    >
                      <MapPin
                        class="w-3 h-3"
                        :style="{
                          color: evento?.area_formacion?.color_hex_principal || '#f97316',
                        }"
                      />
                      <span class="text-[10px] font-bold text-slate-300 uppercase">{{
                        evento?.modalidad
                      }}</span>
                    </div>
                  </div>

                  <div
                    class="flex items-center justify-between py-4 border-y border-white/5"
                  >
                    <div class="flex flex-col">
                      <p class="text-white text-3xl font-black tracking-tighter">
                        {{ formatPrice(evento?.precio_jornada) }}
                      </p>
                      <p class="text-[9px] text-slate-500 font-bold uppercase">
                        Jornada Completa • IVA Incl.
                      </p>
                    </div>
                    <div
                      v-if="evento?.precio_modulo > 0"
                      class="text-right border-l border-white/10 pl-4"
                    >
                      <p class="text-slate-300 text-lg font-bold">
                        {{ formatPrice(evento.precio_modulo) }}
                      </p>
                      <p class="text-[9px] text-slate-500 font-bold uppercase">
                        Por Módulo
                      </p>
                    </div>
                  </div>

                  <BtnUniversal
                    @click="showModal = true"
                    label="Inscribirme ahora"
                    icon="send"
                    icon-position="right"
                    size="lg"
                    :activeColor="
                      evento?.area_formacion?.color_hex_principal || '#f97316'
                    "
                  />
                </div>
              </div>
            </div>
            <div
              v-if="evento?.organizador?.perfil_organizador"
              class="fixed right-0 top-1/2 -translate-y-1/2 z-[60] flex items-center group"
            >
              <div
                class="bg-white border-2 border-r-0 p-2.5 rounded-l-2xl shadow-2xl cursor-pointer transition-all duration-500 group-hover:-translate-x-[340px]"
                :style="{
                  borderColor: evento?.area_formacion?.color_hex_principal + '30',
                }"
              >
                <div class="relative">
                  <img
                    :src="
                      evento.organizador.perfil_organizador.foto
                        ? '/storage/' + evento.organizador.perfil_organizador.foto
                        : `https://ui-avatars.com/api/?name=${evento.organizador.perfil_organizador.primer_nombre}&background=0B192C&color=fff`
                    "
                    class="w-9 h-9 rounded-full object-cover shadow-sm transition-all group-hover:scale-110"
                  />
                  <span
                    class="absolute -bottom-1 -right-1 w-3.5 h-3.5 border-2 border-white rounded-full bg-emerald-500"
                  ></span>
                </div>
              </div>

              <div
                class="absolute left-full w-[340px] bg-white border border-slate-100 shadow-[0_30px_70px_rgba(0,0,0,0.2)] rounded-[2.5rem] p-7 transition-all duration-500 ease-[cubic-bezier(0.175,0.885,0.32,1.275)] group-hover:-translate-x-[340px] opacity-0 group-hover:opacity-100 backdrop-blur-xl"
              >
                <div class="relative z-10">
                  <div class="text-center border-b border-slate-50 pb-6">
                    <div class="relative w-24 h-24 mx-auto mb-4">
                      <div
                        class="absolute inset-[-4px] rounded-[2rem] opacity-20 animate-spin-slow"
                        :style="{
                          border: `2px dashed ${
                            evento?.area_formacion?.color_hex_principal || '#f97316'
                          }`,
                        }"
                      ></div>
                      <img
                        :src="
                          evento.organizador.perfil_organizador.foto
                            ? '/storage/' + evento.organizador.perfil_organizador.foto
                            : `https://ui-avatars.com/api/?name=${evento.organizador.perfil_organizador.primer_nombre}&background=0B192C&color=fff`
                        "
                        class="relative w-full h-full rounded-[1.8rem] object-cover border-4 border-white shadow-md"
                      />
                    </div>
                    <h4 class="text-xl font-bold text-slate-900 leading-tight">
                      {{ evento.organizador.perfil_organizador.primer_nombre }}
                      {{ evento.organizador.perfil_organizador.segundo_nombre }}
                      {{ evento.organizador.perfil_organizador.primer_apellido }}
                    </h4>
                    <p
                      class="text-sm font-semibold text-slate-400 mt-1 uppercase tracking-tighter"
                    >
                      {{
                        evento.organizador.perfil_organizador.cargo ||
                        "Consultor Académico"
                      }}
                    </p>
                  </div>

                  <div class="space-y-3 mb-8">
                    <div
                      class="flex items-center gap-3 px-4 py-2 bg-slate-50 rounded-xl border border-slate-100/50"
                    >
                      <Mail class="w-4 h-4 text-slate-400" />
                      <span class="text-xs font-medium text-slate-600 truncate">{{
                        evento.organizador.perfil_organizador.correo_corporativo
                      }}</span>
                    </div>
                    <div
                      class="flex items-center gap-3 px-4 py-2 bg-slate-50 rounded-xl border border-slate-100/50"
                    >
                      <Phone class="w-4 h-4 text-slate-400" />
                      <span class="text-xs font-medium text-slate-600">{{
                        evento.organizador.perfil_organizador.telefono_corporativo ||
                        evento.organizador.perfil_organizador.telefono_personal
                      }}</span>
                    </div>
                  </div>

                  <div class="grid grid-cols-2 gap-3">
                    <a
                      :href="`https://wa.me/${
                        evento.organizador.perfil_organizador.telefono_corporativo?.replace(
                          /\s+/g,
                          ''
                        ) ||
                        evento.organizador.perfil_organizador.telefono_personal?.replace(
                          /\s+/g,
                          ''
                        )
                      }`"
                      target="_blank"
                      class="flex flex-col items-center justify-center gap-2 p-4 rounded-[1.5rem] bg-emerald-50 text-emerald-700 hover:bg-emerald-500 hover:text-white transition-all duration-300 group/btn shadow-sm hover:shadow-emerald-200"
                    >
                      <MessageCircle
                        class="w-5 h-5 transition-transform group-hover/btn:scale-110"
                      />
                      <span class="text-[10px] font-bold uppercase tracking-wider"
                        >WhatsApp</span
                      >
                    </a>

                    <a
                      :href="`mailto:${evento.organizador.perfil_organizador.correo_corporativo}`"
                      class="flex flex-col items-center justify-center gap-2 p-4 rounded-[1.5rem] bg-slate-50 text-slate-700 hover:text-white transition-all duration-300 group/btn shadow-sm"
                      :style="{
                        '--hover-bg':
                          evento?.area_formacion?.color_hex_principal || '#f97316',
                      }"
                      @mouseenter="$el.style.backgroundColor = activeColor"
                      onmouseover="this.style.backgroundColor=this.getAttribute('data-color'); this.style.color='white'"
                      onmouseout="this.style.backgroundColor=''; this.style.color=''"
                      :data-color="
                        evento?.area_formacion?.color_hex_principal || '#f97316'
                      "
                    >
                      <Mail
                        class="w-5 h-5 transition-transform group-hover/btn:scale-110"
                      />
                      <span class="text-[10px] font-bold uppercase tracking-wider"
                        >Enviar Email</span
                      >
                    </a>
                  </div>
                </div>

                <div
                  class="absolute -bottom-12 -right-12 w-40 h-40 opacity-10 blur-[50px] pointer-events-none rounded-full"
                  :style="{
                    background: evento?.area_formacion?.color_hex_principal || '#f97316',
                  }"
                ></div>
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
</style>
