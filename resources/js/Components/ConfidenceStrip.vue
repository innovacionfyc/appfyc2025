<script setup>
import { ref, onMounted, onBeforeUnmount, watchEffect } from 'vue'
import EyesGridBackground from '@/Components/EyesGridBackground.vue'
import LogosCarousel from '@/Components/LogosCarousel.vue'

const props = defineProps({
  stats: {
    type: Array,
    default: () => [
      { label: 'Participantes capacitados', value: 12000, suffix: '+', icon: 'groups' },
      { label: 'Entidades atendidas', value: 200, suffix: '+', icon: 'account_balance' },
      { label: 'Eventos organizados', value: 350, suffix: '+', icon: 'event' },
      { label: 'Ciudades de presencia', value: 5, suffix: '', icon: 'public' },
    ]
  },
  logos: {
    type: Array,
    default: () => [
      '/images/logos/aliado-1.png', '/images/logos/aliado-2.png',
      '/images/logos/aliado-3.png', '/images/logos/aliado-4.png',
      '/images/logos/aliado-5.png', '/images/logos/aliado-6.png',
      '/images/logos/aliado-7.png', '/images/logos/aliado-8.png',
      '/images/logos/aliado-9.png', '/images/logos/aliado-10.png',
      '/images/logos/aliado-11.png', '/images/logos/aliado-12.png',
      '/images/logos/aliado-13.png', '/images/logos/aliado-14.png',
      '/images/logos/aliado-15.png', '/images/logos/aliado-16.png',
    ]
  },
  bgVariant: { type: String, default: 'none' },
  bgImage: { type: String, default: '' },
})

const inView = ref(false)
let observer
const rootEl = ref(null)

onMounted(() => {
  observer = new IntersectionObserver(([entry]) => {
    if (entry.isIntersecting) {
      inView.value = true
      observer?.disconnect()
    }
  }, { rootMargin: '0px 0px -10% 0px', threshold: 0.1 })

  if (rootEl.value) observer.observe(rootEl.value)
})

onBeforeUnmount(() => observer?.disconnect())

const animatedValues = props.stats.map(() => ref(0))
let rafId

function easeOutExpo(x) {
  return x === 1 ? 1 : 1 - Math.pow(2, -10 * x);
}

function startCounters() {
  const duration = 2000
  const start = performance.now()
  cancelAnimationFrame(rafId)
  rafId = requestAnimationFrame(function tick(now) {
    const elapsed = now - start
    const p = Math.min(1, elapsed / duration)
    const e = easeOutExpo(p)
    props.stats.forEach((s, i) => {
      animatedValues[i].value = Math.round(s.value * e)
    })
    if (p < 1) rafId = requestAnimationFrame(tick)
  })
}

watchEffect(() => { if (inView.value) startCounters() })
</script>

<template>
  <section ref="rootEl" class="relative w-full py-20 lg:py-32 overflow-hidden bg-white">

    <div class="absolute inset-0 z-0 pointer-events-none">
      <div
        class="absolute top-0 right-0 w-[500px] h-[500px] bg-primary-vinotinto/5 rounded-full blur-[100px] translate-x-1/3 -translate-y-1/3">
      </div>
      <div
        class="absolute bottom-0 left-0 w-[600px] h-[600px] bg-primary-naranja/5 rounded-full blur-[120px] -translate-x-1/3 translate-y-1/3">
      </div>
      <div class="absolute inset-0 bg-[url('/images/grid-pattern.svg')] opacity-[0.03]"></div>
    </div>

    <div class="relative z-10 max-w-7xl mx-auto px-6 lg:px-8 flex flex-col h-full justify-center">

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 lg:gap-24 items-center mb-20 lg:mb-32">

        <div class="flex flex-col justify-center space-y-8">
          <div class="transition-all duration-1000 transform ease-out"
            :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'">
            <span
              class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-primary-vinotinto/5 text-primary-vinotinto text-sm font-bold uppercase tracking-widest mb-6">
              <span class="w-2 h-2 rounded-full bg-primary-vinotinto"></span>
              Sobre Nosotros
            </span>

            <h2 class="text-4xl lg:text-5xl font-black text-gray-900 leading-[1.1] mb-6 tracking-tight">
              Más de <span
                class="text-transparent bg-clip-text bg-gradient-to-r from-primary-vinotinto to-primary-naranja">15
                años</span> construyendo un mejor país.
            </h2>

            <p class="text-lg text-gray-600 leading-relaxed max-w-xl">
              Somos una empresa privada experta en el diseño y ejecución de programas académicos especializados para el
              sector público.
              Apostamos por la <strong class="text-gray-900 font-semibold">generación y gestión del
                conocimiento</strong> de los servidores públicos como pilar fundamental.
            </p>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 lg:gap-8">
          <div v-for="(stat, index) in props.stats" :key="index"
            class="relative group p-8 rounded-[2rem] bg-white border border-gray-100 shadow-[0_10px_40px_-10px_rgba(0,0,0,0.06)] hover:shadow-[0_20px_60px_-15px_rgba(0,0,0,0.1)] hover:-translate-y-1 transition-all duration-500 ease-out"
            :class="[
              inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-16',
              index % 2 !== 0 ? 'lg:translate-y-12' : ''
            ]" :style="{ transitionDelay: `${index * 150}ms` }">
            <div
              class="w-14 h-14 rounded-2xl bg-gray-50 flex items-center justify-center text-3xl mb-6 group-hover:scale-110 group-hover:bg-primary-vinotinto/10 group-hover:text-primary-vinotinto transition-all duration-300 text-gray-400">
              <span class="material-symbols-rounded">{{ stat.icon }}</span>
            </div>

            <div class="space-y-1">
              <div class="flex items-baseline gap-1">
                <span class="text-5xl font-black text-gray-900 tracking-tighter tabular-nums">
                  {{ animatedValues[index].value.toLocaleString('es-CO') }}
                </span>
                <span class="text-3xl font-bold text-primary-naranja">
                  {{ stat.suffix }}
                </span>
              </div>
              <p class="text-base font-medium text-gray-500 group-hover:text-gray-900 transition-colors">
                {{ stat.label }}
              </p>
            </div>

            <div
              class="absolute top-0 right-0 w-24 h-24 bg-gradient-to-bl from-gray-50 to-transparent rounded-tr-[2rem] -z-10 group-hover:from-primary-naranja/5 transition-colors duration-500">
            </div>
          </div>
        </div>

      </div>

      <div class="w-full pt-10 border-t border-gray-100 transition-all duration-1000 delay-300 transform ease-out"
        :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'">
        <div class="flex flex-col items-center">
          <p class="text-sm font-bold text-gray-400 uppercase tracking-[0.2em] mb-8 text-center">
            Confían en nosotros
          </p>

          <div class="w-full relative">
            <div
              class="absolute left-0 top-0 bottom-0 w-32 bg-gradient-to-r from-white to-transparent z-10 pointer-events-none">
            </div>
            <LogosCarousel :logos="props.logos" :rows="1" :speed="50" :itemHeight="140" :cardWidth="260" :gap="40"
              class="opacity-100" />
            <div
              class="absolute right-0 top-0 bottom-0 w-32 bg-gradient-to-l from-white to-transparent z-10 pointer-events-none">
            </div>

            
          </div>
        </div>
      </div>

    </div>
  </section>
</template>
