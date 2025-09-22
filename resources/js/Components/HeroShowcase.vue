<script setup>
import { computed, ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import { Link } from '@inertiajs/vue3'

// Los props se mantienen igual que en tu versión original
const props = defineProps({
  events: {
    type: Array,
    default: () => ([
      { id: 1, title: 'Contratación para regímenes especiales', subtitle: 'Seminario de actualización', date: '21–22 Agosto 2025', city: 'Bogotá D.C.', imageThumb: '/images/eventos/1.webp', imageBg: '/images/eventos/1-hero.webp', cta_text: 'Inscribirme', cta_url: '/inscripcion?e=regimenes-especiales', badge: 'Presencial', rating: 5 },
      { id: 2, title: 'Gestión de riesgos en el sector público', subtitle: 'Congreso Nacional', date: '4–6 Septiembre 2025', city: 'Bogotá D.C.', imageThumb: '/images/eventos/2.webp', imageBg: '/images/eventos/2-hero.webp', cta_text: 'Ver detalles', cta_url: '/eventos/gestion-riesgos', badge: 'Destacado', rating: 4 },
      { id: 3, title: 'Archivo y transparencia', subtitle: 'Workshop intensivo', date: 'Octubre 2025', city: 'Híbrido', imageThumb: '/images/eventos/3.webp', imageBg: '/images/eventos/3-hero.webp', cta_text: 'Inscribirme', cta_url: '/inscripcion?e=archivo-transparencia', badge: 'Híbrido', rating: 4 },
      { id: 4, title: 'Control interno y auditoría', subtitle: 'Diplomado especializado', date: 'Noviembre 2025', city: 'Virtual', imageThumb: '/images/eventos/4.webp', imageBg: '/images/eventos/4-hero.webp', cta_text: 'Más información', cta_url: '/eventos/control-interno', badge: 'Virtual', rating: 5 },
      { id: 5, title: 'Innovación en la gestión pública', subtitle: 'Foro Internacional', date: 'Diciembre 2025', city: 'Cartagena', imageThumb: '/images/eventos/5.webp', imageBg: '/images/eventos/5-hero.webp', cta_text: 'Reservar cupo', cta_url: '/inscripcion?e=innovacion-publica', badge: 'Imperdible', rating: 5 },
    ])
  },
  autoplay: { type: Boolean, default: true },
  intervalMs: { type: Number, default: 6500 }
})


const active = ref(0)
const contentKey = ref(0) 
const progressKey = ref(0) 
const rowRef = ref(null)
const CARD_W = 280
const GAP = 24

const current = computed(() => props.events?.[active.value] ?? props.events?.[0] ?? null)

function setActive(i) {
  if (!props.events?.length) return
  const clamped = Math.max(0, Math.min(i, props.events.length - 1))
  if (clamped === active.value) return
  
  active.value = clamped
  contentKey.value++
  progressKey.value++

  nextTick(() => {
    const el = rowRef.value
    if (!el) return
    el.scrollTo({ left: clamped * (CARD_W + GAP), behavior: 'smooth' })
  })
}

function scrollBy(delta) {
  const el = rowRef.value
  if (!el) return
  el.scrollBy({ left: delta, behavior: 'smooth' })
}

let timer = null
function startAutoplay() {
  stopAutoplay()
  if (!props.autoplay || !props.events?.length) return
  timer = setInterval(() => {
    setActive((active.value + 1) % props.events.length)
  }, props.intervalMs)
}
function stopAutoplay() { if (timer) { clearInterval(timer); timer = null } }

onMounted(() => {
  startAutoplay()
})
onBeforeUnmount(stopAutoplay)
watch(() => props.autoplay, v => (v ? startAutoplay() : stopAutoplay()))
watch(() => props.intervalMs, () => { if (props.autoplay) startAutoplay() })
</script>

<template>
  <section class="relative isolate -mt-20 w-full min-h-[100dvh] py-5 flex items-end overflow-hidden bg-black">
    
    
    <div class="absolute inset-0">
  <div
    v-for="(event, index) in events"
    :key="event.id"
    class="absolute inset-0 bg-cover bg-center transition-opacity duration-1000 ease-in-out"
    :class="{
      'opacity-100': index === active,
      'opacity-0': index !== active,
      'ken-burns': index === active  // El efecto Ken Burns solo se aplica a la imagen activa
    }"
    :style="{ backgroundImage: `url('${event.imageBg}')` }"
  />
  
  <div class="absolute inset-0 bg-gradient-to-r from-black/80 via-black/50 to-transparent"></div>
  <div class="absolute inset-x-0 bottom-0 h-48 bg-gradient-to-t from-black/50 to-transparent"></div>
</div>

    <div class="relative z-10 w-full px-4 sm:px-6 lg:px-8">
      <div class="grid grid-cols-1 lg:grid-cols-12 lg:gap-8 items-center">
        
        <aside class="lg:col-span-6 text-center lg:text-left">
          <transition name="slide-up" mode="out-in">
            <div :key="contentKey">
              <div class="inline-flex items-center gap-2 rounded-full bg-white/10 backdrop-blur-sm px-4 py-2 text-xs font-semibold text-white shadow-lg ring-1 ring-white/20">
                <span>{{ current?.badge || 'Evento' }}</span>
                <span class="opacity-50">•</span>
                <span class="opacity-80">{{ current?.city }}</span>
              </div>

              <h1 class="mt-4 text-4xl sm:text-5xl md:text-6xl font-extrabold text-white" style="text-shadow: 0 4px 20px rgba(0,0,0,0.5)">
                {{ current?.title }}
              </h1>

              <p class="mt-3 max-w-lg mx-auto lg:mx-0 text-white/90 text-base md:text-lg">
                {{ current?.subtitle }} — {{ current?.date }}
              </p>

              <div class="mt-4 flex items-center justify-center lg:justify-start gap-1" v-if="current?.rating">
                 <svg v-for="i in 5" :key="i" class="h-5 w-5" :class="i <= (current?.rating || 0) ? 'text-yellow-400' : 'text-white/30'" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" /></svg>
              </div>

              <div class="mt-8 flex flex-wrap justify-center lg:justify-start gap-4">
                <Link :href="current?.cta_url || '#'" class="group inline-flex items-center gap-2 rounded-xl bg-primary-vinotinto px-6 py-3.5 text-base font-bold text-white shadow-xl transition-all duration-300 hover:bg-secondary-vinotinto2 hover:shadow-2xl hover:scale-105">
                  {{ current?.cta_text || 'Inscribirme' }}
                  <span class="transition-transform duration-300 group-hover:translate-x-1">→</span>
                </Link>
                <Link href="/oferta" class="group inline-flex items-center rounded-xl bg-white/90 px-6 py-3.5 text-base font-semibold text-primary-vinotinto shadow-lg backdrop-blur transition-all duration-300 hover:bg-white hover:shadow-xl hover:scale-105">
                  Ver todos
                </Link>
              </div>
            </div>
          </transition>
        </aside>

        <aside class="hidden lg:block lg:col-span-6">
          <div class="relative w-full pb-2 bleed-right">
            <div ref="rowRef" class="no-scrollbar overflow-x-auto overflow-y-visible whitespace-nowrap scroll-smooth w-full">
              <div class="inline-flex gap-6 pr-24 pl-8 pt-8">
                <button
                  v-for="(ev, i) in props.events"
                  :key="ev.id || i"
                  @click="setActive(i)"
                  @mouseenter="stopAutoplay"
                  @mouseleave="startAutoplay"
                  class="group relative inline-block flex-none rounded-2xl overflow-hidden text-left transition-all will-change-transform align-top duration-300 whitespace-normal transform-gpu origin-bottom hover:z-10"
                  :class="i === active
                    ? 'ring-2 ring-white/80 shadow-2xl scale-[1.05]'
                    : 'ring-0 shadow-lg scale-100 opacity-90 hover:opacity-100 hover:scale-[1.02]'"
                  style="width: 280px; height: 360px;"
                >
                  <img :src="ev.imageThumb" :alt="ev.title" class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-110" loading="lazy" />
                  <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/20 to-transparent"></div>
                  <div class="absolute bottom-0 left-0 right-0 p-4 text-white">
                    <div class="text-xs opacity-90 line-clamp-1">{{ ev.subtitle }}</div>
                    <div class="text-lg font-bold leading-snug line-clamp-2 break-words">{{ ev.title }}</div>
                  </div>
                  <div v-if="i === active && autoplay" class="absolute bottom-0 left-0 h-1 bg-white/80" :key="progressKey" :style="{ animation: `progress ${intervalMs}ms linear` }"></div>
                </button>
              </div>
            </div>
          </div>
          <div class="flex justify-center gap-3 mt-4">
            <button @click="scrollBy(-480)" aria-label="Anterior" class="h-12 w-12 flex items-center justify-center rounded-full bg-white/20 backdrop-blur text-white ring-1 ring-white/30 transition hover:bg-white/30">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
            </button>
            <button @click="scrollBy(480)" aria-label="Siguiente" class="h-12 w-12 flex items-center justify-center rounded-full bg-white/20 backdrop-blur text-white ring-1 ring-white/30 transition hover:bg-white/30">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
            </button>
          </div>
        </aside>
        
      </div>
    </div>
  </section>
</template>

<style scoped>
/* Transiciones y Animaciones */
.fade-enter-active, .fade-leave-active { transition: opacity .8s cubic-bezier(0.4, 0, 0.2, 1); }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.slide-up-enter-active, .slide-up-leave-active { transition: all .5s cubic-bezier(0.4, 0, 0.2, 1); }
.slide-up-enter-from { opacity: 0; transform: translateY(20px); }
.slide-up-leave-to { opacity: 0; transform: translateY(-20px); }

@keyframes kenburns {
  0% { transform: scale(1) translate(0, 0); }
  100% { transform: scale(1.1) translate(-1%, 2%); }
}
.ken-burns { animation: kenburns 15s ease-out infinite alternate both; }

@keyframes progress {
  from { width: 0%; } to { width: 100%; }
}

/* Ocultar scrollbar y efecto de "sangrado" restaurados de tu CSS original */
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

.bleed-right { margin-right: calc(50% - 50vw + 1rem); }
@media (min-width: 640px) { .bleed-right { margin-right: calc(50% - 50vw + 1.5rem); } }
@media (min-width: 1024px) { .bleed-right { margin-right: calc(50% - 50vw + 2rem); } }
</style>