<script setup>
import { ref, onMounted, onBeforeUnmount, watchEffect } from 'vue'
import EyesGridBackground from '@/Components/EyesGridBackground.vue'
import LogosCarousel from '@/Components/LogosCarousel.vue'

const props = defineProps({
  stats: {
    type: Array,
    default: () => [
      { label: 'Servidores públicos formados', value: 160000, suffix: '+', icon: 'groups' },
      { label: 'Años de experiencia', value: 17, suffix: '+', icon: 'history_edu' },
      { label: 'Programas con impacto real', value: 350, suffix: '+', icon: 'event' },
      { label: 'Cobertura nacional', value: 32, suffix: '', icon: 'public' },
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
const isFeatured = (stat) => stat.label === 'Servidores públicos formados'
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
    <section ref="rootEl" class="relative w-full py-16 lg:py-24 overflow-hidden bg-white">

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

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 lg:gap-24 items-center mb-14 lg:mb-4">

        <div class="flex flex-col justify-center space-y-8">
          <div class="transition-all duration-1000 transform ease-out"
            :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'">

            <span
              class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-primary-vinotinto/5 text-primary-vinotinto text-sm font-bold uppercase tracking-widest mb-6">
              <span class="w-2 h-2 rounded-full bg-primary-vinotinto"></span>
              Nuestra experiencia
            </span>

            <h2 class="text-4xl lg:text-5xl font-black text-gray-900 leading-[1.1] mb-6 tracking-tight">
              Somos sus aliados estratégicos en la
              <span class="text-transparent bg-clip-text bg-gradient-to-r from-primary-vinotinto to-primary-naranja">
                generación y gestión del conocimiento
              </span>.
            </h2>

            <p class="text-lg text-gray-600 leading-relaxed max-w-xl text-justify">
              Durante más de <strong class="text-gray-900 font-semibold">16 años</strong>, F&amp;C Consultores ha acompañado a
              entidades públicas y mixtas del país en el fortalecimiento de capacidades, diseñando y ejecutando
              <strong class="text-gray-900 font-semibold">programas académicos especializados</strong> que generan impacto real
              en la gestión institucional.
              Hemos formado a más de <strong class="text-gray-900 font-semibold">160.000 servidores públicos</strong> en todo el territorio nacional,
              consolidándonos como un aliado estratégico <strong class="text-gray-900 font-semibold">confiable, pertinente</strong> y alineado con las
              políticas públicas de formación y capacitación del Estado.
            </p>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 lg:gap-8">
            <div
              v-for="(stat, index) in props.stats"
              :key="index"
              class="relative group rounded-[2rem] border transition-all duration-500 ease-out"
              :class="[
                inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-16',
                index % 2 !== 0 ? 'lg:translate-y-12' : '',
                isFeatured(stat)
                ? 'p-10 bg-gradient-to-br from-primary-vinotinto/10 to-primary-naranja/10 border-primary-naranja/30 shadow-[0_25px_80px_-30px_rgba(211,47,87,0.35)] hover:shadow-[0_30px_90px_-35px_rgba(211,47,87,0.45)] hover:-translate-y-1'
                : 'p-8 bg-white border-gray-100 shadow-[0_10px_40px_-10px_rgba(0,0,0,0.06)] hover:shadow-[0_20px_60px_-15px_rgba(0,0,0,0.1)] hover:-translate-y-1'
              ]"
              :style="{ transitionDelay: `${index * 150}ms` }"
            >


            <div
              class="w-14 h-14 rounded-2xl bg-gray-50 flex items-center justify-center text-3xl mb-6 group-hover:scale-110 group-hover:bg-primary-vinotinto/10 group-hover:text-primary-vinotinto transition-all duration-300 text-gray-400">
              <span class="material-symbols-rounded">{{ stat.icon }}</span>
            </div>

            <div class="space-y-1">
              <div class="flex items-baseline gap-1">
                <span
                  class="text-5xl font-black tracking-tighter tabular-nums"
                  :class="isFeatured(stat) ? 'text-primary-vinotinto' : 'text-gray-900'"
                >
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

        <div class="w-full pt-6 border-t border-gray-100 transition-all duration-1000 delay-300 transform ease-out"
        :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'">
        <div class="flex flex-col items-center">
          <p class="text-sm font-bold text-gray-400 uppercase tracking-[0.2em] mb-8 text-center">
            Confían en nosotros
          </p>

          <div class="w-full relative">
            <div
              class="absolute left-0 top-0 bottom-0 w-32 bg-gradient-to-r from-white to-transparent z-10 pointer-events-none">
            </div>

            <LogosCarousel
              :logos="props.logos"
              :rows="1"
              :speed="50"
              :itemHeight="140"
              :cardWidth="260"
              :gap="40"
              class="opacity-100"
            />

            <div
              class="absolute right-0 top-0 bottom-0 w-32 bg-gradient-to-l from-white to-transparent z-10 pointer-events-none">
            </div>
          </div>
        </div>
      </div>

    </div>
  </section>
</template>
