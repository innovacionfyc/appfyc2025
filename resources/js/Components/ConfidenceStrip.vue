<script setup>
import { ref, onMounted, onBeforeUnmount, watchEffect } from 'vue'

const props = defineProps({
  stats: {
    type: Array,
    default: () => [
      { label: 'Participantes capacitados', value: 12000, suffix: '+', icon: '👥' },
      { label: 'Entidades atendidas',      value: 200,   suffix: '+', icon: '🏛️' },
      { label: 'Eventos organizados',      value: 350,   suffix: '+', icon: '📚' },
      { label: 'Ciudades de presencia',    value: 5,     suffix: '',  icon: '🌎' },
    ]
  },
  logos: {
    type: Array,
    default: () => [
      '/images/logos/aliado-1.png',
      '/images/logos/aliado-2.png',
      '/images/logos/aliado-3.png',
      '/images/logos/aliado-4.png',
      '/images/logos/aliado-5.png',
      '/images/logos/aliado-6.png',
      '/images/logos/aliado-7.png',
      '/images/logos/aliado-8.png',
    ]
  },
})

/* ---- fade-up al entrar en viewport ---- */
const inView = ref(false)
let observer
const rootEl = ref(null)

onMounted(() => {
  observer = new IntersectionObserver(
    ([entry]) => {
      if (entry.isIntersecting) {
        inView.value = true
        observer?.disconnect()
      }
    },
    { rootMargin: '0px 0px -15% 0px', threshold: 0.2 }
  )
  if (rootEl.value) observer.observe(rootEl.value)
})

onBeforeUnmount(() => observer?.disconnect())

/* ---- count-up de cifras ---- */
const animatedValues = props.stats.map(() => ref(0))
let rafId

function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3) }

function startCounters() {
  const duration = 1200
  const start = performance.now()
  cancelAnimationFrame(rafId)
  rafId = requestAnimationFrame(function tick(now) {
    const p = Math.min(1, (now - start) / duration)
    const e = easeOutCubic(p)
    props.stats.forEach((s, i) => {
      animatedValues[i].value = Math.round(s.value * e)
    })
    if (p < 1) rafId = requestAnimationFrame(tick)
  })
}
watchEffect(() => { if (inView.value) startCounters() })
</script>

<template>
  <section ref="rootEl" class="relative w-full py-12 md:py-16 lg:py-20 bg-mono-blanco">
    <!-- fondo suave -->
    <div class="pointer-events-none absolute inset-0">
      <div class="absolute inset-0 bg-gradient-to-b from-extra-opacity/30 to-transparent"></div>
      <div class="absolute inset-x-0 bottom-0 h-24 bg-gradient-to-t from-extra-opacity/40 to-transparent"></div>
    </div>

    <div class="relative z-10 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <!-- encabezado -->
      <div
        class="text-center mb-10 md:mb-12"
        :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
        style="transition: all .6s ease"
      >
        <span class="inline-block rounded-full bg-primary-vinotinto/10 text-primary-vinotinto text-xl font-semibold px-3 py-1">
          Somos F&C Consultores
        </span>
        <h2 class="mt-3 text-3xl md:text-2xl font-extrabold text-mono-negro">
            Una empresa privada con una trayectoria de más de 15 años, experta en el diseño y ejecución de programas académicos especializados para el sector público, que le apuesta a la generación y gestión del conocimiento de los servidores públicos como pilar fundamental para la construcción de un mejor país.
        </h2>
        <p class="mt-2 text-mono-negro/70">
          Confían en nosotros servidores, entidades y aliados en todo el país.
        </p>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
        <!-- Cifras -->
        <div class="lg:col-span-6">
          <div class="grid grid-cols-2 gap-4 md:gap-6">
            <div
              v-for="(s, i) in props.stats"
              :key="s.label"
              class="rounded-2xl bg-white/70 backdrop-blur ring-1 ring-black/5 shadow-sm p-5 md:p-6 transition-all duration-500"
              :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-3'"
              :style="{'--i': i, 'transition-delay': `calc(${i} * 70ms)`}"
            >
              <div class="flex items-center gap-3">
                <div class="h-10 w-10 md:h-11 md:w-11 rounded-xl bg-primary-vinotinto/10 flex items-center justify-center text-xl">
                  {{ s.icon }}
                </div>
                <div>
                  <div class="leading-none">
                    <span class="text-3xl md:text-4xl font-extrabold text-primary-vinotinto">
                      {{ animatedValues[i].value.toLocaleString('es-CO') }}
                    </span>
                    <span class="text-2xl md:text-3xl font-extrabold text-primary-vinotinto">
                      {{ s.suffix }}
                    </span>
                  </div>
                  <div class="text-sm md:text-base text-mono-negro/70 mt-1">
                    {{ s.label }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Logos de aliados -->
        <div class="lg:col-span-6">
          <div
            class="rounded-2xl bg-white/70 backdrop-blur ring-1 ring-black/5 shadow-sm p-4 md:p-6"
            :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-3'"
            style="transition: all .6s ease .15s"
          >
            <!-- fila scrollable en mobile, grid en desktop -->
            <div class="no-scrollbar -mx-2 px-2 overflow-x-auto">
              <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 gap-4 md:gap-6 min-w-[560px] md:min-w-0">
                <div
                  v-for="(src, idx) in props.logos"
                  :key="src + idx"
                  class="flex items-center justify-center h-16 rounded-xl ring-1 ring-black/5 bg-white/80"
                >
                  <img
                    :src="src"
                    :alt="'Logo ' + (idx+1)"
                    class="max-h-10 md:max-h-12 w-auto opacity-70 grayscale hover:opacity-100 hover:grayscale-0 transition"
                    loading="lazy"
                  />
                </div>
              </div>
            </div>
            <p class="mt-4 text-center text-xs text-mono-negro/60">
              * Los logos son de referencia; cada aliado mantiene sus respectivos derechos.
            </p>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>
