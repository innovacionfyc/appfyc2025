<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'

const rootEl = ref(null)
const inView = ref(false)
let observer = null

onMounted(() => {
  observer = new IntersectionObserver(([entry]) => {
    if (entry.isIntersecting) {
      inView.value = true
      observer?.disconnect()
    }
  }, { threshold: 0.12, rootMargin: '0px 0px -10% 0px' })

  if (rootEl.value) observer.observe(rootEl.value)
})
onBeforeUnmount(() => observer?.disconnect())

const axes = [
  { icon: 'public', label: 'Territorio, vida y ambiente' },
  { icon: 'psychology', label: 'Desarrollo de habilidades y competencias' },
  { icon: 'verified_user', label: 'Ética, probidad e identidad de lo público' },
  { icon: 'diversity_3', label: 'Paz total, memorias y derechos humanos' },
  { icon: 'female', label: 'Mujer, inclusión y diversidad' },
  { icon: 'shield_lock', label: 'Transformación digital y cibercultura' },
]

const note2026 =
  'Para 2026, segmentamos nuestra oferta académica de acuerdo con la naturaleza jurídica de las entidades y sus funciones misionales, integrando temas transversales, especializados y estratégicos.'
</script>

<template>
  <section ref="rootEl" class="relative w-full py-20 lg:py-32 overflow-hidden bg-white">
    <!-- Fondo -->
    <div class="absolute inset-0 z-0 pointer-events-none">
      <div class="absolute top-0 right-0 w-[520px] h-[520px] bg-primary-vinotinto/5 rounded-full blur-[110px] translate-x-1/3 -translate-y-1/3"></div>
      <div class="absolute bottom-0 left-0 w-[620px] h-[620px] bg-primary-naranja/5 rounded-full blur-[130px] -translate-x-1/3 translate-y-1/3"></div>
      <div class="absolute inset-0 bg-[url('/images/grid-pattern.png')] opacity-[0.03]"></div>
    </div>

    <div class="relative z-10 max-w-7xl mx-auto px-6 lg:px-8">
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-center">

        <!-- Header -->
        <div class="lg:col-span-5">
          <div
            class="transition-all duration-1000 transform ease-out"
            :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'"
          >
            <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-primary-vinotinto/5 text-primary-vinotinto text-sm font-bold uppercase tracking-widest mb-6">
              <span class="w-2 h-2 rounded-full bg-primary-vinotinto"></span>
              Ejes temáticos estratégicos
            </span>

            <h2 class="text-4xl lg:text-5xl font-black text-gray-900 leading-[1.1] mb-6 tracking-tight">
              Aprendizaje significativo y
              <span class="text-transparent bg-clip-text bg-gradient-to-r from-primary-vinotinto to-primary-naranja">formación continua</span>.
            </h2>

            <p class="text-lg text-gray-600 leading-relaxed max-w-xl">
              Nuestros programas se estructuran desde un enfoque de aprendizaje significativo y formación continua,
              alineados con los pilares de la gestión pública moderna:
            </p>

            <div class="mt-8 p-5 rounded-2xl border border-gray-100 bg-white shadow-[0_10px_40px_-10px_rgba(0,0,0,0.06)]">
              <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-xl bg-primary-naranja/10 text-primary-naranja flex items-center justify-center">
                  <span class="material-symbols-rounded">calendar_month</span>
                </div>
                <p class="text-sm text-gray-600 leading-relaxed">
                  <span class="font-bold text-gray-900">2026:</span> {{ note2026 }}
                </p>
              </div>
            </div>
          </div>
        </div>

        <!-- Cards / Bullets -->
        <div class="lg:col-span-7 grid grid-cols-1 sm:grid-cols-2 gap-6 lg:gap-8">
          <div
            v-for="(a, index) in axes"
            :key="a.label"
            class="relative group p-7 sm:p-8 rounded-[2rem] bg-white border border-gray-100 overflow-hidden
                   shadow-[0_10px_40px_-10px_rgba(0,0,0,0.06)]
                   hover:shadow-[0_20px_60px_-15px_rgba(0,0,0,0.1)]
                   hover:-translate-y-1 transition-all duration-500 ease-out"
            :class="[
              inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-16',
              index % 2 !== 0 ? 'lg:translate-y-10' : ''
            ]"
            :style="{ transitionDelay: `${index * 90}ms` }"
          >
            <!-- blob -->
            <div class="absolute -top-12 -right-12 w-44 h-44 opacity-0 scale-95
                        group-hover:opacity-100 group-hover:scale-100
                        group-hover:translate-x-2 group-hover:-translate-y-2
                        transition-all duration-700 ease-out pointer-events-none z-10">
              <svg viewBox="0 0 200 200" class="w-full h-full blur-[1px]">
                <defs>
                  <linearGradient :id="`g-${index}`" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#D32F57" stop-opacity="0.24" />
                    <stop offset="55%" stop-color="#E96510" stop-opacity="0.20" />
                    <stop offset="100%" stop-color="#A08E43" stop-opacity="0.20" />
                  </linearGradient>
                </defs>
                <path :fill="`url(#g-${index})`"
                  d="M45.5,-58.6C58.7,-52.2,69.5,-39.3,75.3,-24.4C81.1,-9.5,81.9,7.4,77.2,24.1C72.6,40.9,62.4,57.6,48,66.7C33.6,75.8,16.8,77.4,1.2,75.8C-14.4,74.1,-28.8,69.2,-41.8,60.5C-54.7,51.9,-66.2,39.5,-71,24.7C-75.9,10,-74.1,-7.1,-68.1,-22C-62.1,-36.9,-51.8,-49.5,-38.9,-56.6C-26,-63.8,-10.5,-65.4,3.7,-70.4C17.9,-75.4,35.8,-83.9,45.5,-58.6Z"
                  transform="translate(100 100)" />
              </svg>
            </div>

            <div class="relative z-10 flex items-start gap-4">
              <div class="w-14 h-14 rounded-2xl bg-gray-50 flex items-center justify-center text-3xl
                          group-hover:scale-110 group-hover:bg-primary-vinotinto/10 group-hover:text-primary-vinotinto
                          transition-all duration-300 text-gray-400 shrink-0">
                <span class="material-symbols-rounded">{{ a.icon }}</span>
              </div>

              <div>
                <p class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-2">
                  Pilar
                </p>
                <h3 class="text-lg sm:text-xl font-black text-gray-900 leading-snug">
                  {{ a.label }}
                </h3>

                <div class="mt-5 h-1 w-12 rounded-full bg-gray-200 transition-all duration-500 group-hover:w-full
                            group-hover:bg-gradient-to-r from-primary-vinotinto to-primary-naranja"></div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>
</template>
