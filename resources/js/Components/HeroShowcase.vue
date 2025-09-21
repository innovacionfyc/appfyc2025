<script setup>
import { computed, ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
  events: {
    type: Array,
    default: () => ([
      { id: 1, title: 'Contratación para regímenes especiales', subtitle: 'Seminario de actualización', date: '21–22 Agosto 2025', city: 'Bogotá D.C.', imageThumb: '/images/eventos/1.jpg', imageBg: '/images/eventos/1-hero.jpg', cta_text: 'Inscribirme', cta_url: '/inscripcion?e=regimenes-especiales', badge: 'Presencial', rating: 5 },
      { id: 2, title: 'Gestión de riesgos en el sector público', subtitle: 'Congreso Nacional', date: '4–6 Septiembre 2025', city: 'Bogotá D.C.', imageThumb: '/images/eventos/2.jpg', imageBg: '/images/eventos/2-hero.jpg', cta_text: 'Ver detalles', cta_url: '/eventos/gestion-riesgos', badge: 'Destacado', rating: 4 },
      { id: 3, title: 'Archivo y transparencia', subtitle: 'Workshop intensivo', date: 'Octubre 2025', city: 'Híbrido', imageThumb: '/images/eventos/3.jpg', imageBg: '/images/eventos/3-hero.jpg', cta_text: 'Inscribirme', cta_url: '/inscripcion?e=archivo-transparencia', badge: 'Híbrido', rating: 4 },
      { id: 4, title: 'Archivo y transparencia', subtitle: 'Workshop intensivo', date: 'Octubre 2025', city: 'Híbrido', imageThumb: '/images/eventos/4.jpg', imageBg: '/images/eventos/4-hero.jpg', cta_text: 'Inscribirme', cta_url: '/inscripcion?e=archivo-transparencia', badge: 'Híbrido', rating: 4 },
      { id: 5, title: 'Archivo y transparencia', subtitle: 'Workshop intensivo', date: 'Octubre 2025', city: 'Híbrido', imageThumb: '/images/eventos/5.jpg', imageBg: '/images/eventos/5-hero.jpg', cta_text: 'Inscribirme', cta_url: '/inscripcion?e=archivo-transparencia', badge: 'Híbrido', rating: 4 },
    ])
  },
  autoplay: { type: Boolean, default: false },
  intervalMs: { type: Number, default: 6500 }
})

const active = ref(0)
const fadingKey = ref(0)
const current = computed(() => props.events?.[active.value] ?? props.events?.[0] ?? null)

/* carrusel */
const rowRef = ref(null)
const CARD_W = 280
const GAP = 24

function setActive(i) {
  if (!props.events?.length) return
  const clamped = Math.max(0, Math.min(i, props.events.length - 1))
  if (clamped === active.value) return
  active.value = clamped
  fadingKey.value++
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
  timer = setInterval(() => setActive((active.value + 1) % props.events.length), props.intervalMs)
}
function stopAutoplay() { if (timer) { clearInterval(timer); timer = null } }
onMounted(startAutoplay)
onBeforeUnmount(stopAutoplay)
watch(() => props.autoplay, v => (v ? startAutoplay() : stopAutoplay()))
watch(() => props.intervalMs, () => { if (props.autoplay) startAutoplay() })
</script>

<template>
  <section
    class="relative isolate w-full overflow-hidden
           -mt-12 md:-mt-16 lg:-mt-20 -mt-px -mb-px
           pt-10 sm:pt-12 md:pt-16 lg:pt-20
           h-[68vh] sm:h-[72vh] md:h-[76vh]">

    <!-- Fondo -->
    <div class="absolute -inset-px">
      <transition name="fade" mode="out-in">
        <div
          :key="fadingKey"
          class="absolute inset-0 bg-center bg-cover will-change-transform"
          :style="current ? `background-image:url('${current.imageBg || current.imageThumb}')` : ''"
        />
      </transition>
      <div class="absolute inset-0 bg-gradient-to-r from-black/55 via-black/35 to-black/10"></div>
      <div class="absolute inset-x-0 top-0 h-24 sm:h-28 md:h-32 bg-gradient-to-b from-black/35 via-black/15 to-transparent pointer-events-none"></div>
      <div class="absolute inset-0 pointer-events-none" style="box-shadow: inset 0 -80px 120px rgba(0,0,0,0.25)"></div>
    </div>

    <!-- Contenido -->
    <div class="relative z-10 px-4 sm:px-6 lg:px-8 py-8 sm:py-10 lg:py-16">
      <!-- ✅ Volvemos a colocar el contenedor con grid -->
      <div class="max-w-full mx-auto grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
        <!-- Texto -->
        <aside class="lg:col-span-6">
          <div class="max-w-2xl mx-auto lg:mx-0 text-center lg:text-left">
            <div class="inline-flex items-center gap-2 rounded-full bg-mono-blanco/90 text-primary-vinotinto px-3 py-1 text-xs font-semibold shadow">
              <span>{{ current?.badge || 'Evento' }}</span>
              <span class="opacity-60">•</span>
              <span class="opacity-80">{{ current?.city }}</span>
            </div>

            <h1 class="mt-4 text-3xl sm:text-4xl md:text-5xl xl:text-6xl font-extrabold leading-tight text-mono-blanco">
              {{ current?.title }}
            </h1>

            <p class="mt-3 text-mono-blanco/90 text-base md:text-lg">
              {{ current?.subtitle }} — {{ current?.date }}
            </p>

            <div class="mt-3 flex items-center justify-center lg:justify-start gap-1" v-if="current?.rating">
              <span v-for="i in 5" :key="i" :class="i <= (current?.rating || 0) ? 'text-extra-light' : 'text-mono-blanco/40'">★</span>
            </div>

            <div class="mt-6 flex flex-wrap justify-center lg:justify-start gap-4">
              <Link
                :href="current?.cta_url || '#'"
                class="inline-flex items-center gap-2 rounded-xl px-6 py-3
                       bg-primary-vinotinto text-mono-blanco font-bold
                       shadow-2xl hover:bg-secondary-vinotinto2
                       transition hover:translate-y-[-1px] active:translate-y-0
                       focus:outline-none focus:ring-2 focus:ring-extra-light"
              >
                {{ current?.cta_text || 'Inscribirme' }}
              </Link>

              <Link
                href="/oferta"
                class="inline-flex items-center gap-2 rounded-xl px-6 py-3
                       bg-mono-blanco/90 text-primary-vinotinto font-semibold
                       shadow hover:bg-mono-blanco
                       transition focus:outline-none focus:ring-2 focus:ring-extra-light"
              >
                Ver todos los eventos
              </Link>
            </div>
          </div>
        </aside>

        <!-- Carrusel (derecha) -->
        <aside class="hidden lg:block lg:col-span-6">
          <!-- ✅ Sangrado solo del carrusel, manteniendo el grid -->
          <div class="relative w-full pb-2 bleed-right">
            <div
              ref="rowRef"
              class="no-scrollbar overflow-x-auto overflow-y-visible whitespace-nowrap scroll-smooth w-full"
            >
                <div class="inline-flex gap-6 pr-24 pl-8 pt-8">
                <button
                  v-for="(ev, i) in props.events"
                  :key="ev.id || i"
                  @click="setActive(i)"
                  class="group relative inline-block flex-none rounded-2xl overflow-hidden text-left
                         transition will-change-transform align-top duration-200 whitespace-normal
                         transform-gpu origin-bottom hover:z-10"
                  :class="i === active
                    ? 'ring-2 ring-mono-blanco/70 shadow-2xl scale-[1.05]'
                    : 'ring-0 shadow-lg scale-100 hover:scale-[1.04]'"
                  style="width: 280px; height: 360px;"
                >
                  <img
                    :src="ev.imageThumb"
                    :alt="ev.title"
                    class="h-full w-full object-cover transition group-hover:brightness-105"
                    loading="lazy"
                  />
                  <div class="absolute inset-0 bg-gradient-to-t from-black/55 via-black/15 to-transparent"></div>

                  <div class="absolute bottom-0 left-0 right-0 p-4 text-mono-blanco whitespace-normal">
                    <div class="text-xs opacity-90 line-clamp-1">{{ ev.subtitle }}</div>
                    <div class="text-lg font-bold leading-snug line-clamp-2 break-words">{{ ev.title }}</div>
                    <div class="mt-1 text-[11px] opacity-80 line-clamp-1">{{ ev.city }} • {{ ev.date }}</div>
                  </div>

                  <div
                    class="absolute top-3 right-3 h-3 w-3 rounded-full"
                    :class="i === active ? 'bg-primary-naranja shadow' : 'bg-mono-blanco/70 group-hover:bg-primary-naranja/80'"
                  />
                </button>
              </div>
            </div>
          </div>
            <div class="flex justify-center gap-2 mt-4">
              <button
                @click="scrollBy(-480)"
                class="h-12 w-12 flex items-center justify-center rounded-full
                       bg-mono-blanco/50 backdrop-blur ring-1 ring-white/40
                       hover:bg-mono-blanco/70 transition"
                aria-label="Anterior"
              >‹</button>
              <button
                @click="scrollBy(480)"
                class="h-12 w-12 flex items-center justify-center rounded-full
                       bg-mono-blanco/50 backdrop-blur ring-1 ring-white/40
                       hover:bg-mono-blanco/70 transition"
                aria-label="Siguiente"
              >›</button>
            </div>
        </aside>
      </div>
    </div>
  </section>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .6s ease; }
.fade-enter-from, .fade-leave-to       { opacity: 0; }
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

/* ✅ Sangrar a la derecha compensando el padding del contenedor (px-4 / sm:px-6 / lg:px-8) */
.bleed-right { margin-right: calc(50% - 50vw + 1rem); }      /* base: px-4 = 1rem */
@media (min-width: 640px) { /* sm */
  .bleed-right { margin-right: calc(50% - 50vw + 1.5rem); }  /* sm:px-6 = 1.5rem */
}
@media (min-width: 1024px) { /* lg */
  .bleed-right { margin-right: calc(50% - 50vw + 2rem); }    /* lg:px-8 = 2rem */
}
</style>
