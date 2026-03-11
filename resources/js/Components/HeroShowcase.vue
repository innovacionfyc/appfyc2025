<script setup>
import { computed, ref, onMounted, onBeforeUnmount, watch, nextTick } from "vue";
import { Link } from "@inertiajs/vue3";
import BtnUniversal from "./BtnUniversal.vue";

const props = defineProps({
  events: {
    type: Array,
    default: () => [
      {
        id: 1,
        title: "Contratación para regímenes especiales",
        subtitle: "Seminario de actualización",
        date: "21–22 Agosto 2026",
        city: "Bogotá D.C.",
        imageThumb: "/images/eventos/1.webp",
        imageBg: "/images/eventos/1-hero.webp",
        cta_text: "Inscribirme",
        cta_url: "/inscripcion?e=regimenes-especiales",
        badge: "Presencial",
        rating: 5,
      },
    ],
  },
  autoplay: { type: Boolean, default: true },
  intervalMs: { type: Number, default: 6000 },
});

const active = ref(0);
const progressKey = ref(0);
const rowRef = ref(null);
let timer = null;

const current = computed(() => props.events?.[active.value] ?? props.events?.[0] ?? null);

function setActive(i) {
  if (!props.events?.length) return;
  active.value = (i + props.events.length) % props.events.length;
  progressKey.value++;

  nextTick(() => {
    if (!rowRef.value) return;
    const activeCard = rowRef.value.children[active.value];
    if (!activeCard) return;

    const scrollLeft =
      activeCard.offsetLeft - rowRef.value.clientWidth / 2 + activeCard.clientWidth / 2;
    rowRef.value.scrollTo({ left: scrollLeft, behavior: "smooth" });
  });
}

function startAutoplay() {
  stopAutoplay();
  if (!props.autoplay || !props.events?.length) return;
  timer = setInterval(() => {
    setActive(active.value + 1);
  }, props.intervalMs);
}

function stopAutoplay() {
  if (timer) {
    clearInterval(timer);
    timer = null;
  }
}

const userInteracted = () => {
  stopAutoplay();
  setTimeout(() => {
    if (!timer && props.autoplay) startAutoplay();
  }, 8000);
};

onMounted(() => {
  startAutoplay();
});
onBeforeUnmount(stopAutoplay);
watch(
  () => props.autoplay,
  (v) => (v ? startAutoplay() : stopAutoplay())
);

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
  <section
    class="relative isolate w-full h-dvh flex flex-col justify-end overflow-hidden bg-[#0B192C] font-sans"
  >
    <div class="absolute inset-0 z-0">
      <transition-group name="hero-fade">
        <template v-if="events.length > 0">
          <div
            v-for="(event, index) in events"
            :key="event.id"
            v-show="index === active"
            class="absolute inset-0 bg-cover bg-center will-change-transform opacity-60"
            :class="{ 'animate-ken-burns': index === active }"
            :style="{ backgroundImage: `url('${event.imageBg}')` }"
          />
          <div
            class="absolute inset-0 bg-gradient-to-t from-[#0B192C] via-[#0B192C]/30 to-transparent"
          ></div>
        </template>

        <div
          v-else
          class="absolute inset-0 bg-cover bg-center opacity-50"
          style="background-image: url('/images/eventos/default-bg.webp')"
        ></div>
      </transition-group>
    </div>

    <div
      class="relative z-10 w-full h-full max-w-[1920px] mx-auto px-6 lg:px-12 pb-10 lg:pb-16 flex flex-col lg:flex-row items-end gap-12"
    >
      <aside class="w-full lg:w-5/12 mb-8 lg:mb-20">
        <transition name="content-slide" mode="out-in">
          <div
            v-if="events.length > 0"
            :key="active"
            class="flex flex-col items-start text-left space-y-6"
          >
            <div
              class="flex items-center px-2 py-1 gap-2 rounded-full bg-white/5 border border-white/20 backdrop-blur-md animate-fade-in-up"
            >
              <span class="relative flex h-3 w-3">
                <span
                  class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75"
                  :style="{
                    background: current?.hex_principal,
                  }"
                ></span>
                <span
                  class="relative inline-flex rounded-full h-3 w-3"
                  :style="{
                    background: current?.hex_principal,
                  }"
                ></span>
              </span>
              <span class="text-sm font-bold text-white tracking-wide uppercase">{{
                current?.area
              }}</span>
            </div>

            <h1
              class="text-5xl lg:text-7xl font-black text-white leading-none tracking-tight drop-shadow-xl animate-fade-in-up delay-100"
            >
              {{ current?.title }}
            </h1>

            <div
              class="space-y-2 border-l-2 pl-6 animate-fade-in-up delay-200"
              :style="{
                borderLeftColor: current?.hex_principal,
              }"
            >
              <p class="text-xl text-white font-normal leading-snug">
                {{ current?.mode }}
              </p>
              <p class="text-2xl text-white font-medium leading-snug">
                {{ current?.subtitle }}
              </p>
              <div class="flex items-center gap-4 text-gray-300">
                <span class="flex items-center gap-2">
                  <span
                    class="material-symbols-rounded"
                    :style="{
                      color: current?.hex_principal,
                    }"
                    >calendar_today</span
                  >

                  {{ formatEventRange(current?.date_in, current?.date_on) }}
                </span>
                <span class="flex items-center gap-2">
                  <span
                    class="material-symbols-rounded"
                    :style="{
                      color: current?.hex_principal,
                    }"
                    >location_on</span
                  >
                  {{ current?.city }}
                </span>
              </div>
            </div>

            <div class="pt-4 animate-fade-in-up delay-300">
              <Link :href="current?.cta_url || '#'">
                <BtnUniversal
                  :label="current?.cta_text"
                  icon="arrow_forward_ios"
                  icon-position="right"
                  size="lg"
                  :activeColor="current?.hex_principal"
                />
              </Link>
            </div>
          </div>
          <div v-else key="empty" class="flex flex-col items-start text-left space-y-6">
            <div
              class="px-4 py-1 rounded-full bg-white/10 border border-white/20 backdrop-blur-md"
            >
              <span class="text-sm font-bold text-white uppercase tracking-widest"
                >Próximamente</span
              >
            </div>
            <h1 class="text-5xl lg:text-7xl font-black text-white leading-none">
              Nuevos Eventos <br />
              en camino
            </h1>
            <p class="text-2xl text-gray-300 border-l-2 border-primary-naranja pl-6">
              Estamos preparando nuestras próximas experiencias formativas. ¡Vuelve
              pronto!
            </p>
          </div>
        </transition>
      </aside>

      <aside class="w-full lg:w-7/12 relative">
        <template v-if="events.length > 0">
          <div
            class="lg:hidden absolute -top-10 right-0 text-white/50 text-sm flex items-center gap-2 animate-pulse"
          >
            <span class="material-symbols-rounded">swipe_left</span> Desliza
          </div>

          <div
            ref="rowRef"
            @mousedown="userInteracted"
            @touchstart="userInteracted"
            @wheel="userInteracted"
            class="flex gap-6 overflow-x-auto no-scrollbar scroll-smooth py-10 px-4 lg:px-0 -mx-4 lg:mx-0 perspective-container"
          >
            <button
              v-for="(ev, i) in props.events"
              :key="ev.id || i"
              @click="setActive(i)"
              class="relative flex-none group rounded-[2rem] overflow-hidden transition-all duration-700 ease-[cubic-bezier(0.23,1,0.32,1)] card-3d-wrapper outline-none"
              :class="
                i === active
                  ? 'w-[300px] lg:w-[360px] h-[420px] lg:h-[480px] z-20 scale-100 opacity-100 shadow-[0_20px_50px_rgba(0,0,0,0.5)] ring-2 ring-rose-800/80 card-active'
                  : 'w-[260px] lg:w-[300px] h-[380px] lg:h-[420px] z-0 scale-90 opacity-50 grayscale-[30%] hover:opacity-80 hover:scale-95 hover:grayscale-0 card-inactive cursor-pointer'
              "
            >
              <img
                :src="ev.imageThumb"
                :alt="ev.title"
                class="absolute inset-0 h-full w-full object-cover transition-transform duration-1000"
                :class="i === active ? 'scale-105 group-hover:scale-110' : 'scale-100'"
                loading="lazy"
              />

              <div
                class="absolute inset-0 bg-gradient-to-t from-[#0B192C] via-[#0B192C]/50 to-transparent opacity-90"
              ></div>

              <div class="absolute bottom-0 inset-x-0 lg:p-6 z-20">
                <div
                  class="relative overflow-hidden rounded-2xl transition-all duration-500 ease-[cubic-bezier(0.25,0.8,0.25,1)]"
                  :class="
                    i === active
                      ? 'bg-[#0B192C]/10 backdrop-blur-xl shadow-2xl translate-y-0'
                      : 'bg-black/40 backdrop-blur-md border-white/5 translate-y-2'
                  "
                >
                  <div
                    class="absolute -top-10 -right-10 w-32 h-32 bg-primary-naranja/30 blur-[50px] rounded-full pointer-events-none transition-opacity duration-700"
                    :class="i === active ? 'opacity-100' : 'opacity-0'"
                  ></div>

                  <div class="relative p-5">
                    <div class="flex items-center justify-center mb-3">
                      <div class="flex items-center gap-1.5">
                        <span
                          class="material-symbols-rounded text-sm"
                          :style="{
                            color: current?.hex_principal,
                          }"
                          >location_on</span
                        >
                        <span
                          class="text-xs font-bold uppercase tracking-widest text-gray-200"
                        >
                          {{ ev.mode_event }}
                        </span>
                      </div>
                    </div>

                    <p
                      class="text-xs lg:text-sm text-gray-300 font-medium leading-relaxed"
                    >
                      · {{ ev.mode }} ·
                    </p>
                    <h3
                      class="text-lg lg:text-3xl font-bold text-white leading-snug tracking-tight transition-all duration-300"
                      :class="
                        i !== active
                          ? 'line-clamp-2 opacity-90'
                          : 'line-clamp-none opacity-100'
                      "
                    >
                      {{ ev.title }}
                    </h3>

                    <div
                      class="grid transition-all duration-500 ease-[cubic-bezier(0.25,0.8,0.25,1)]"
                      :class="
                        i === active
                          ? 'grid-rows-[1fr] opacity-100 mt-3 pt-3 border-t border-white/10'
                          : 'grid-rows-[0fr] opacity-0 mt-0'
                      "
                    >
                      <div class="overflow-hidden">
                        <p
                          class="text-xs lg:text-sm text-gray-300 font-medium leading-relaxed"
                        >
                          {{ ev.subtitle }}
                        </p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div
                v-if="i === active && autoplay"
                class="absolute bottom-0 left-0 h-[3px] bg-primary-naranja shadow-[0_0_10px_rgba(240,82,53,0.8)] z-30"
                :key="progressKey"
                :style="{ animation: `progress ${intervalMs}ms linear forwards` }"
              ></div>
            </button>

            <div class="w-8 flex-none"></div>
          </div>
        </template>
        <div
          v-else
          class="h-[60dvh] flex items-center justify-center border-2 border-dashed border-white/10 rounded-[2rem]"
        >
          <p class="text-white/30 font-medium italic">
            No hay eventos programados por ahora...
          </p>
        </div>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.hero-fade-enter-active,
.hero-fade-leave-active {
  transition: opacity 1.5s ease-in-out;
}

.hero-fade-enter-from,
.hero-fade-leave-to {
  opacity: 0;
}

@keyframes ken-burns {
  0% {
    transform: scale(1) translate(0, 0);
  }

  100% {
    transform: scale(1.1) translate(-1%, -1%);
  }
}

.animate-ken-burns {
  animation: ken-burns 25s ease-out infinite alternate;
}

.content-slide-enter-active,
.content-slide-leave-active {
  transition: all 0.8s cubic-bezier(0.16, 1, 0.3, 1);
}

.content-slide-enter-from {
  opacity: 0;
  transform: translateX(-50px);
}

.content-slide-leave-to {
  opacity: 0;
  transform: translateX(50px);
}

.animate-fade-in-up {
  opacity: 0;
  animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

.delay-100 {
  animation-delay: 100ms;
}

.delay-200 {
  animation-delay: 200ms;
}

.delay-300 {
  animation-delay: 300ms;
}

@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(30px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.perspective-container {
  perspective: 1000px;
}

.card-3d-wrapper {
  transform-style: preserve-3d;
  backface-visibility: hidden;
}

.card-inactive {
  transform: rotateY(15deg) translateZ(-50px) scale(0.9);
}

.card-active {
  transform: rotateY(0deg) translateZ(0) scale(1);
}

.card-inactive:hover {
  transform: rotateY(5deg) translateZ(-20px) scale(0.95);
}

@keyframes progress {
  from {
    width: 0%;
  }

  to {
    width: 100%;
  }
}

@keyframes shimmer {
  100% {
    transform: translateX(100%);
  }
}

.no-scrollbar::-webkit-scrollbar {
  display: none;
}

.no-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}

.bg-noise {
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)'/%3E%3C/svg%3E");
}
</style>
