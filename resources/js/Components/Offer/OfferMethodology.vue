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

const steps = [
  { n: '01', icon: 'search', title: 'Diagnóstico', desc: 'Entendemos el contexto, la necesidad y los objetivos específicos de la entidad para enfocar la solución.' },
  { n: '02', icon: 'edit_square', title: 'Diseño', desc: 'Estructuramos contenidos, actividades y recursos aplicables al rol y nivel de los participantes.' },
  { n: '03', icon: 'school', title: 'Implementación', desc: 'Sesiones dinámicas, conferencistas expertos y guía metodológica para una apropiación real del conocimiento.' },
  { n: '04', icon: 'track_changes', title: 'Seguimiento', desc: 'Reforzamos lo aprendido con recomendaciones y acompañamiento según la modalidad y los objetivos trazados.' },
]
</script>

<template>
  <section ref="rootEl" class="relative w-full py-20 lg:py-32 overflow-hidden bg-white">
    <div class="absolute inset-0 z-0 pointer-events-none">
      <div class="absolute top-0 right-0 w-[520px] h-[520px] bg-primary-vinotinto/5 rounded-full blur-[110px] translate-x-1/3 -translate-y-1/3"></div>
      <div class="absolute bottom-0 left-0 w-[620px] h-[620px] bg-primary-naranja/5 rounded-full blur-[130px] -translate-x-1/3 translate-y-1/3"></div>
      <div class="absolute inset-0 bg-[url('/images/grid-pattern.svg')] opacity-[0.03]"></div>
    </div>

    <div class="relative z-10 max-w-7xl mx-auto px-6 lg:px-8">
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-start">

        <!-- Header -->
        <div class="lg:col-span-5">
          <div class="transition-all duration-1000 transform ease-out"
               :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'">
            <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-primary-vinotinto/5 text-primary-vinotinto text-sm font-bold uppercase tracking-widest mb-6">
              <span class="w-2 h-2 rounded-full bg-primary-vinotinto"></span>
              Metodología
            </span>

            <h2 class="text-4xl lg:text-5xl font-black text-gray-900 leading-[1.1] mb-6 tracking-tight">
              Apropiación del
              <span class="text-transparent bg-clip-text bg-gradient-to-r from-primary-vinotinto to-primary-naranja">conocimiento</span>,
              no solo teoría.
            </h2>

            <p class="text-lg text-gray-600 leading-relaxed max-w-xl">
              Diseñamos experiencias aplicables al día a día para que el aprendizaje se traduzca en decisiones más sólidas,
              mejores procesos y resultados institucionales.
            </p>
          </div>
        </div>

        <!-- Cards -->
        <div class="lg:col-span-7 grid grid-cols-1 sm:grid-cols-2 gap-6 lg:gap-8">
          <div
            v-for="(s, index) in steps"
            :key="s.n"
            class="relative group p-8 rounded-[2rem] bg-white border border-gray-100 overflow-hidden
                   shadow-[0_10px_40px_-10px_rgba(0,0,0,0.06)]
                   hover:shadow-[0_20px_60px_-15px_rgba(0,0,0,0.1)]
                   hover:-translate-y-1 transition-all duration-500 ease-out"
            :class="[
              inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-16',
              index % 2 !== 0 ? 'lg:translate-y-12' : ''
            ]"
            :style="{ transitionDelay: `${index * 150}ms` }"
          >
            <!-- “Imagen” abstracta flotante -->
            <div
              class="absolute -top-12 -right-12 w-48 h-48
                     opacity-0 scale-95
                     group-hover:opacity-100 group-hover:scale-100
                     group-hover:translate-x-2 group-hover:-translate-y-2
                     transition-all duration-700 ease-out
                     pointer-events-none z-10"
            >
              <svg viewBox="0 0 200 200" class="w-full h-full blur-[1px]">
                <defs>
                  <linearGradient :id="`g-${s.n}`" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#D32F57" stop-opacity="0.28" />
                    <stop offset="55%" stop-color="#E96510" stop-opacity="0.22" />
                    <stop offset="100%" stop-color="#A08E43" stop-opacity="0.22" />
                  </linearGradient>
                </defs>

                <path
                  :fill="`url(#g-${s.n})`"
                  d="M45.5,-58.6C58.7,-52.2,69.5,-39.3,75.3,-24.4C81.1,-9.5,81.9,7.4,77.2,24.1C72.6,40.9,62.4,57.6,48,66.7C33.6,75.8,16.8,77.4,1.2,75.8C-14.4,74.1,-28.8,69.2,-41.8,60.5C-54.7,51.9,-66.2,39.5,-71,24.7C-75.9,10,-74.1,-7.1,-68.1,-22C-62.1,-36.9,-51.8,-49.5,-38.9,-56.6C-26,-63.8,-10.5,-65.4,3.7,-70.4C17.9,-75.4,35.8,-83.9,45.5,-58.6Z"
                  transform="translate(100 100)"
                />
              </svg>
            </div>


            <div class="flex items-start justify-between gap-4 mb-6 relative z-10">
              <div class="w-14 h-14 rounded-2xl bg-gray-50 flex items-center justify-center text-3xl
                          group-hover:scale-110 group-hover:bg-primary-vinotinto/10 group-hover:text-primary-vinotinto
                          transition-all duration-300 text-gray-400">
                <span class="material-symbols-rounded">{{ s.icon }}</span>
              </div>
              <div class="text-right">
                <p class="text-xs font-bold uppercase tracking-widest text-gray-400">Paso</p>
                <p class="text-2xl font-black tracking-tighter text-gray-900 tabular-nums">{{ s.n }}</p>
              </div>
            </div>

            <h3 class="text-xl font-black text-gray-900 mb-2 leading-tight relative z-10">{{ s.title }}</h3>
            <p class="text-gray-500 leading-relaxed group-hover:text-gray-600 transition-colors relative z-10">{{ s.desc }}</p>

            <div class="mt-6 h-1 w-12 rounded-full bg-gray-200 transition-all duration-500 group-hover:w-full group-hover:bg-gradient-to-r from-primary-vinotinto to-primary-naranja relative z-10"></div>
          </div>
        </div>

      </div>
    </div>
  </section>
</template>
