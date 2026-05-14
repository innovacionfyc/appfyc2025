<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'

/** Reveal on view */
const rootEl = ref(null)
const inView = ref(false)
let observer = null

onMounted(() => {
  observer = new IntersectionObserver(
    ([entry]) => {
      if (entry.isIntersecting) {
        inView.value = true
        observer?.disconnect()
      }
    },
    { threshold: 0.14, rootMargin: '0px 0px -10% 0px' }
  )
  if (rootEl.value) observer.observe(rootEl.value)
})

onBeforeUnmount(() => observer?.disconnect())

const items = [
  {
    icon: 'fact_check',
    title: 'Evaluaciones formativas y sumativas',
    desc: 'Seguimiento del progreso y evaluación de resultados.',
    accent: '#e96510',
    grad: 'from-[#e96510] to-[#f39322]',
  },
  {
    icon: 'dynamic_form',
    title: 'Análisis de casos reales',
    desc: 'Aplicación práctica con situaciones del entorno institucional.',
    accent: '#942934',
    grad: 'from-[#942934] to-[#d32f57]',
  },
  {
    icon: 'psychology',
    title: 'Toma de decisiones y solución de problemas',
    desc: 'Desarrollo de habilidades aplicables al rol y al contexto.',
    accent: '#685f2f',
    grad: 'from-[#685f2f] to-[#a08e43]',
  },
]

const blocks = [
  {
    icon: 'monitoring',
    title: 'Formativas',
    desc: 'Seguimiento del progreso durante el proceso.',
    grad: 'from-[#e96510] to-[#f39322]'
  },
  {
    icon: 'grading',
    title: 'Sumativas',
    desc: 'Evaluación de resultados y cierre del aprendizaje.',
    grad: 'from-[#942934] to-[#d32f57]'
  },
]
</script>

<template>
  <section ref="rootEl" class="flex items-center min-h-dvh relative w-full overflow-hidden bg-white py-16">
    <!-- Fondo dinámico suave -->
    <div class="absolute inset-0 z-0 pointer-events-none">
      <div class="absolute inset-0 bg-gradient-to-br from-[#e96510]/10 via-white to-[#685f2f]/10"></div>

      <div class="absolute -top-56 left-1/4 w-[760px] h-[760px] rounded-full blur-3xl opacity-18 bg-[#e96510]"></div>
      <div class="absolute -bottom-56 -right-56 w-[760px] h-[760px] rounded-full blur-3xl opacity-14 bg-[#942934]"></div>
      <div class="absolute top-1/4 -left-56 w-[700px] h-[700px] rounded-full blur-3xl opacity-12 bg-[#685f2f]"></div>

      <div class="absolute inset-0 bg-[url('/images/grid-pattern.png')] opacity-[0.02]"></div>
      <div class="absolute inset-0 bg-gradient-to-b from-white via-white/85 to-white"></div>
    </div>

    <div class="relative z-10 max-w-7xl mx-auto px-6">
      <div class="grid lg:grid-cols-12 gap-12 items-start">
        <!-- Col izquierda -->
        <div class="lg:col-span-6">
          <div
            class="transition-all duration-1000 ease-out"
            :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-10'"
          >
            <span
              class="inline-flex items-center gap-2 px-3 py-1 rounded-full
                     bg-[#685f2f]/5 text-[#685f2f]
                     text-sm font-bold uppercase tracking-widest"
            >
              <span class="w-2 h-2 rounded-full bg-[#685f2f]"></span>
              Metodologías
            </span>

            <h2
              class="mt-6 text-3xl md:text-4xl font-black tracking-tight
                     bg-clip-text text-transparent bg-gradient-to-r
                     from-[#942934] via-[#e96510] to-[#685f2f]"
            >
              Metodologías de aprendizaje
            </h2>

            <p class="mt-4 text-lg text-gray-600 leading-relaxed max-w-2xl">
              Desde nuestra Dirección de Innovación y TI, implementamos metodologías activas que fortalecen el aprendizaje práctico.
            </p>
          </div>

          <!-- Lista viva con iconos -->
          <div
            class="mt-10 space-y-4 transition-all duration-1000 ease-out"
            :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
          >
            <div
              v-for="(it, i) in items"
              :key="i"
              class="group flex gap-4 p-4 rounded-2xl border border-gray-100 bg-white/75 backdrop-blur
                     shadow-[0_10px_40px_-18px_rgba(0,0,0,0.12)]
                     transition-all duration-300 hover:-translate-y-0.5 hover:bg-white"
            >
              <div
                class="w-12 h-12 rounded-2xl flex items-center justify-center text-white shadow-lg
                       bg-gradient-to-br transition-transform duration-500 group-hover:scale-110 group-hover:rotate-3"
                :class="it.grad"
              >
                <span class="material-symbols-rounded text-2xl">{{ it.icon }}</span>
              </div>

              <div class="flex-1">
                <p class="font-black text-gray-900">{{ it.title }}</p>
                <p class="mt-1 text-gray-600">{{ it.desc }}</p>
              </div>

              <div
                class="w-10 h-10 rounded-full border border-gray-100 flex items-center justify-center
                       text-gray-300 transition-all duration-300
                       group-hover:bg-black group-hover:text-white"
              >
                <span class="material-symbols-rounded text-xl">done</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Col derecha -->
        <div class="lg:col-span-6">
          <div
            class="relative group bg-white/85 rounded-[2.5rem] p-8 md:p-10 border border-gray-100 overflow-hidden
                   shadow-[0_10px_40px_-10px_rgba(0,0,0,0.08)]
                   transition-all duration-1000 ease-out"
            :class="inView ? 'opacity-100 translate-y-0 scale-100' : 'opacity-0 translate-y-12 scale-95'"
          >
            <div class="absolute inset-0 bg-white/55 backdrop-blur-[2px]"></div>

            <div class="relative z-10">
              <div class="flex justify-between items-start mb-8">
                <div
                  class="w-16 h-16 rounded-2xl flex items-center justify-center text-white shadow-lg
                         transform transition-transform duration-500 group-hover:scale-110 group-hover:rotate-3
                         bg-gradient-to-br from-[#942934] via-[#e96510] to-[#685f2f]"
                >
                  <span class="material-symbols-rounded text-3xl">insights</span>
                </div>

                <div
                  class="w-10 h-10 rounded-full border border-gray-100 flex items-center justify-center
                         text-gray-300 transition-all duration-300
                         group-hover:bg-black group-hover:text-white"
                >
                  <span class="material-symbols-rounded text-xl -rotate-45 group-hover:rotate-0 transition-transform duration-300">
                    arrow_forward
                  </span>
                </div>
              </div>

              <h3
                class="text-2xl font-black tracking-tight bg-clip-text text-transparent bg-gradient-to-r
                       from-[#e96510] to-[#942934]"
              >
                Aprendizaje práctico, medible y aplicable
              </h3>

              <p class="mt-4 text-gray-600 leading-relaxed">
                El objetivo es que cada participante se lleve herramientas reales para su rol: comprensión, criterio y capacidad
                de acción.
              </p>

              <!-- Bloques formativas/sumativas -->
              <div class="mt-7 space-y-4">
                <div
                  v-for="(b, i) in blocks"
                  :key="i"
                  class="group/block rounded-2xl border border-gray-100 bg-white/75 backdrop-blur p-5
                         transition-all duration-300 hover:-translate-y-0.5 hover:bg-white hover:shadow-sm"
                >
                  <div class="flex items-start justify-between gap-4">
                    <div>
                      <p class="font-black text-gray-900">{{ b.title }}</p>
                      <p class="mt-1 text-sm text-gray-600">{{ b.desc }}</p>
                    </div>

                    <div
                      class="w-11 h-11 rounded-2xl flex items-center justify-center text-white shadow-md
                             bg-gradient-to-br transition-transform duration-500 group-hover/block:scale-110 group-hover/block:rotate-3"
                      :class="b.grad"
                    >
                      <span class="material-symbols-rounded">{{ b.icon }}</span>
                    </div>
                  </div>

                  <div
                    class="mt-5 h-1 w-12 rounded-full bg-gray-200 transition-all duration-500
                           group-hover/block:w-full group-hover/block:bg-gradient-to-r"
                    :class="b.grad"
                  ></div>
                </div>
              </div>

              <!-- Barra final -->
              <div
                class="mt-8 h-1 w-12 rounded-full bg-gray-200 transition-all duration-500 group-hover:w-full
                       group-hover:bg-gradient-to-r from-[#942934] via-[#e96510] to-[#685f2f]"
              ></div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>
</template>
