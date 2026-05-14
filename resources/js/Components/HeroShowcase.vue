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
        date: "2026-08-21",
        city: "Bogotá D.C.",
        imageThumb: "/images/eventos/1.webp",
        imageBg: "/images/eventos/1-hero.webp",
        cta_text: "Inscribirme",
        cta_url: "/inscripcion?e=regimenes-especiales",
        area: "Derecho",
        hex_principal: "#2563eb",
        mode: "Presencial"
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
let isScrolling = false;

const current = computed(() => props.events?.[active.value] ?? props.events?.[0] ?? null);

// Sincroniza el índice activo cuando el usuario scrollea manualmente
function handleScroll() {
  if (!rowRef.value || isScrolling) return;
  
  const container = rowRef.value;
  const center = container.scrollLeft + container.clientWidth / 2;
  
  // Encontramos la tarjeta que está más cerca del centro
  const children = Array.from(container.children);
  const closestIndex = children.reduce((closest, child, index) => {
    const childCenter = child.offsetLeft + child.clientWidth / 2;
    if (Math.abs(childCenter - center) < Math.abs(children[closest].offsetLeft + children[closest].clientWidth / 2 - center)) {
      return index;
    }
    return closest;
  }, 0);

  if (active.value !== closestIndex && closestIndex < props.events.length) {
    active.value = closestIndex;
    progressKey.value++;
  }
}

function setActive(i) {
  if (!props.events?.length) return;
  isScrolling = true;
  active.value = (i + props.events.length) % props.events.length;
  progressKey.value++;

  nextTick(() => {
    if (!rowRef.value) return;
    const activeCard = rowRef.value.children[active.value];
    if (!activeCard) return;

    const scrollLeft = activeCard.offsetLeft - rowRef.value.clientWidth / 2 + activeCard.clientWidth / 2;
    rowRef.value.scrollTo({ left: scrollLeft, behavior: "smooth" });
    
    setTimeout(() => { isScrolling = false; }, 600);
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

const formatEventRange = (inicio) => {
  if (!inicio) return "fecha por definir";
  const start = new Date(inicio);
  return start.toLocaleDateString('es-ES', { day: 'numeric', month: 'long', year: 'numeric' });
};
</script>

<template>
  <section class="relative isolate w-full h-dvh flex flex-col justify-end overflow-hidden bg-[#0B192C]">
    <div class="absolute inset-0 z-0">
  <transition-group name="hero-crossfade">
    <div
      v-for="(event, index) in events"
      :key="event.id"
      v-show="index === active"
      class="absolute inset-0 bg-cover bg-center will-change-[opacity,transform]"
      :style="{ 
        backgroundImage: `url('${event.imageBg}')`,
        zIndex: index === active ? 1 : 0 
      }"
    >
      <div 
        class="absolute inset-0 bg-cover bg-center"
        :class="{ 'animate-ken-burns': index === active }"
        :style="{ backgroundImage: `url('${event.imageBg}')` }"
      ></div>
    </div>
  </transition-group>
  
<!-- Gradiente Adaptativo e Inteligente -->
<div 
  class="absolute inset-0 z-10 transition-all duration-1000 ease-in-out"
  :style="{
    background: `linear-gradient(var(--gradient-dir, to bottom), 
      ${current?.hex_principal ?? '#0B192C'} 0%, 
      ${(current?.hex_principal ?? '#0B192C')}E6 10%, 
      ${(current?.hex_principal ?? '#0B192C')}66 40%, 
      transparent 100%)`
  }"
></div>
</div>

    <div class="relative z-10 w-full h-full max-w-[1920px] mx-auto px-6 lg:px-12 pt-24 pb-8 lg:pb-16 flex flex-col lg:flex-row items-start lg:items-end justify-between lg:justify-end gap-8">
      
      <aside class="w-full lg:w-5/12 mb-4 lg:mb-20">
        <transition name="content-slide" mode="out-in">
          <div :key="active" class="flex flex-col items-start space-y-4 lg:space-y-6">
            <div class="flex items-center px-3 py-1 gap-2 rounded-full bg-white/10 border border-white/20 backdrop-blur-md">
              <span class="relative flex h-2.5 w-2.5">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75" :style="{ background: current?.hex_principal }"></span>
                <span class="relative inline-flex rounded-full h-2.5 w-2.5" :style="{ background: current?.hex_principal }"></span>
              </span>
              <span class="text-[10px] font-black text-white tracking-[0.2em] uppercase">{{ current?.area }}</span>
            </div>

            <h1 class="text-3xl sm:text-5xl lg:text-7xl font-black text-white leading-[1.1] tracking-tight drop-shadow-[0_2px_10px_rgba(0,0,0,0.5)]">
              {{ current?.title }}
            </h1>

            <div class="space-y-2 border-l-4 pl-5 animate-fade-in-up" :style="{ borderLeftColor: current?.hex_principal }">
              <p class="text-base lg:text-xl text-white/90 font-medium leading-snug ">{{ current?.mode }} | {{ current?.subtitle }}</p>
              <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-gray-300 text-sm">
                <span class="flex items-center gap-2 drop-shadow-[0_2px_10px_rgba(0,0,0,0.5)]">
                  <span class="material-symbols-rounded text-lg">calendar_today</span>
                  {{ formatEventRange(current?.date_in) }}
                </span>
                <span class="flex items-center gap-2 drop-shadow-[0_2px_10px_rgba(0,0,0,0.5)]">
                  <span class="material-symbols-rounded text-lg">location_on</span>
                  {{ current?.city }}
                </span>
              </div>
            </div>

            <div class="pt-4 w-full sm:w-auto">
              <Link :href="current?.cta_url || '#'">
                <BtnUniversal :label="current?.cta_text" icon="arrow_forward_ios" icon-position="right" size="lg" class="w-full sm:w-auto justify-center" :activeColor="current?.hex_principal" />
              </Link>
            </div>
          </div>
        </transition>
      </aside>

      <aside class="w-full lg:w-7/12 relative">
        <div class="lg:hidden absolute -top-8 right-0 text-white/40 text-[10px] font-bold uppercase tracking-widest flex items-center gap-2 animate-pulse">
          <span>desliza</span>
          <span class="material-symbols-rounded text-sm">swipe_left</span>
        </div>

        <div
          ref="rowRef"
          @scroll="handleScroll"
          @touchstart="userInteracted"
          class="flex gap-4 lg:gap-6 overflow-x-auto no-scrollbar scroll-smooth py-6 lg:py-10 px-4 lg:px-0 -mx-6 lg:mx-0 perspective-container snap-x snap-mandatory"
        >
          <button
            v-for="(ev, i) in events"
            :key="ev.id || i"
            @click="setActive(i)"
            class="relative flex-none rounded-[2rem] overflow-hidden transition-all duration-700 ease-[cubic-bezier(0.23,1,0.32,1)] card-3d-wrapper outline-none snap-center"
            :class="[
              i === active
                ? 'w-[75vw] sm:w-[400px] lg:w-[360px] h-[350px] lg:h-[480px] z-20 scale-100 opacity-100 shadow-2xl ring-2 ring-white/20 card-active'
                : 'w-[60vw] sm:w-[300px] lg:w-[300px] h-[300px] lg:h-[420px] z-0 scale-90 opacity-40 grayscale card-inactive'
            ]"
          >
            <img :src="ev.imageThumb" class="absolute inset-0 h-full w-full object-cover" />
            <div class="absolute inset-0 bg-gradient-to-t from-[#0B192C] via-transparent opacity-80"></div>
            
            <div class="absolute bottom-0 inset-x-0 p-6 z-20">
               <div class="p-4 rounded-2xl bg-black/20 backdrop-blur-xl border border-white/10" :class="i === active ? 'opacity-100' : 'opacity-0'">
                  <p class="text-[10px] font-black text-blue-400 uppercase mb-1">{{ ev.mode_event }}</p>
                  <h3 class="text-lg lg:text-xl font-bold text-white leading-tight line-clamp-2 ">{{ ev.title }}</h3>
               </div>
            </div>

            <div v-if="i === active && autoplay" class="absolute bottom-0 left-0 h-1 bg-white shadow-[0_0_15px_rgba(255,255,255,0.5)] z-30" :key="progressKey" :style="{ animation: `progress ${intervalMs}ms linear forwards` }"></div>
          </button>
          <div class="w-[10vw] lg:w-8 flex-none"></div>
        </div>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

.perspective-container {
  perspective: 1200px;
}

.card-3d-wrapper {
  transform-style: preserve-3d;
  backface-visibility: hidden;
}

.card-inactive {
  transform: rotateY(12deg) translateZ(-60px);
}

@media (min-width: 1024px) {
  .card-inactive { transform: rotateY(25deg) translateZ(-100px); }
}

.card-active { transform: rotateY(0deg) translateZ(0); }

@keyframes progress { from { width: 0%; } to { width: 100%; } }

@keyframes ken-burns {
  0% { transform: scale(1); }
  100% { transform: scale(1.1) translate(-1%, -1%); }
}

.animate-ken-burns { animation: ken-burns 25s ease-out infinite alternate; }

.content-slide-enter-active, .content-slide-leave-active { transition: all 0.6s ease; }
.content-slide-enter-from { opacity: 0; transform: translateX(-30px); }
.content-slide-leave-to { opacity: 0; transform: translateX(30px); }

@keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
.animate-fade-in-up { animation: fadeInUp 0.8s forwards; }


.hero-crossfade-enter-active,
.hero-crossfade-leave-active {
  transition: opacity 2000ms cubic-bezier(0.4, 0, 0.2, 1);
}

.hero-crossfade-enter-from,
.hero-crossfade-leave-to {
  opacity: 0;
}

@keyframes ken-burns {
  0% {
    transform: scale(1);
  }
  100% {
    transform: scale(1.12);
  }
}

.animate-ken-burns {
  animation: ken-burns 12s ease-out forwards;
}

.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

/* Dirección por defecto: Móvil y Tablet (Arriba hacia Abajo) */
section {
  --gradient-dir: to bottom;
}

/* Dirección para Escritorio (Abajo hacia Arriba) */
@media (min-width: 1024px) {
  section {
    --gradient-dir: to top;
  }
}
</style>