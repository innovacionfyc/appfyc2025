<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'

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

const bullets = [
  {
    icon: 'diversity_3',
    title: 'Equipo interdisciplinario',
    desc: 'Más de 140 conferencistas, consultores y expertos.',
    accent: '#942934',
    grad: 'from-[#942934] to-[#d32f57]'
  },
  {
    icon: 'workspace_premium',
    title: 'Reconocimiento nacional',
    desc: 'Trayectoria académica y profesional destacada.',
    accent: '#e96510',
    grad: 'from-[#e96510] to-[#f39322]'
  },
  {
    icon: 'verified_user',
    title: 'Transparencia y calidad',
    desc: 'Informamos previamente los perfiles de quienes liderarán cada programa.',
    accent: '#685f2f',
    grad: 'from-[#685f2f] to-[#a08e43]'
  },
]

const cards = [
  {
    icon: 'badge',
    label: 'Perfil',
    value: 'Confirmado',
    desc: 'Trayectoria académica y profesional',
    grad: 'from-[#942934] to-[#d32f57]'
  },
  {
    icon: 'assignment_turned_in',
    label: 'Asignación',
    value: 'Informada',
    desc: 'Previo al inicio del programa',
    grad: 'from-[#e96510] to-[#f39322]'
  },
  {
    icon: 'history_edu',
    label: 'Experiencia',
    value: 'Comprobable',
    desc: 'Especialistas según temática y nivel',
    grad: 'from-[#685f2f] to-[#a08e43]'
  },
  {
    icon: 'shield',
    label: 'Calidad',
    value: 'Garantizada',
    desc: 'Estándares consistentes en cada sesión',
    grad: 'from-[#942934] via-[#e96510] to-[#685f2f]'
  },
]
</script>

<template>
  <section ref="rootEl" class="flex items-center min-h-dvh relative overflow-hidden">
    <!-- Fondo dinámico suave -->
    <div class="absolute inset-0 pointer-events-none">
      <div class="absolute inset-0 bg-gradient-to-br from-[#942934]/8 via-white to-[#e96510]/8"></div>

      <div class="absolute -top-52 -left-52 w-[720px] h-[720px] rounded-full blur-3xl opacity-20 bg-[#942934]"></div>
      <div class="absolute -bottom-56 -right-56 w-[760px] h-[760px] rounded-full blur-3xl opacity-18 bg-[#e96510]"></div>
      <div class="absolute top-1/4 right-1/4 w-[640px] h-[640px] rounded-full blur-3xl opacity-12 bg-[#685f2f]"></div>

      <div class="absolute inset-0 bg-[url('/images/grid-pattern.png')] opacity-[0.02]"></div>
      <div class="absolute inset-0 bg-gradient-to-b from-white via-white/85 to-white"></div>
    </div>

    <div class="relative max-w-7xl mx-auto px-6 py-16">
      <div class="grid lg:grid-cols-12 gap-12 items-start">
        <!-- Col izquierda -->
        <div class="lg:col-span-6">
          <div
            class="transition-all duration-1000 ease-out"
            :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-10'"
          >
            <span
              class="inline-flex items-center gap-2 px-3 py-1 rounded-full
                     bg-[#942934]/5 text-[#942934]
                     text-sm font-bold uppercase tracking-widest"
            >
              <span class="w-2 h-2 rounded-full bg-[#942934]"></span>
              Nuestro equipo
            </span>

            <h2
              class="mt-6 text-3xl md:text-4xl font-black tracking-tight
                     bg-clip-text text-transparent bg-gradient-to-r
                     from-[#942934] via-[#e96510] to-[#685f2f]"
            >
              Nuestro equipo académico
            </h2>

            <p class="mt-4 text-lg text-gray-600 leading-relaxed">
              Contamos con un equipo interdisciplinario de
              <span
                class="font-black text-transparent bg-clip-text bg-gradient-to-r from-[#942934] to-[#e96510]"
              >
                más de 140
              </span>
              conferencistas, consultores y expertos, reconocidos a nivel nacional por su trayectoria académica y profesional.
            </p>
          </div>

          <!-- Lista con iconos -->
          <div
            class="mt-8 space-y-3 transition-all duration-1000 ease-out"
            :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'"
          >
            <div
              v-for="(b, i) in bullets"
              :key="i"
              class="group flex gap-4 p-4 rounded-2xl border border-gray-100 bg-white/75 backdrop-blur
                     shadow-[0_10px_30px_-18px_rgba(0,0,0,0.12)]
                     transition-all duration-300 hover:-translate-y-0.5 hover:bg-white"
            >
              <div
                class="w-12 h-12 rounded-2xl flex items-center justify-center text-white shadow-lg
                       bg-gradient-to-br transition-transform duration-500 group-hover:scale-110 group-hover:rotate-3"
                :class="b.grad"
              >
                <span class="material-symbols-rounded text-2xl">{{ b.icon }}</span>
              </div>

              <div class="flex-1">
                <p class="font-black text-gray-900">{{ b.title }}</p>
                <p class="mt-1 text-gray-600">{{ b.desc }}</p>
              </div>

              <div class="w-10 h-10 rounded-full border border-gray-100 flex items-center justify-center text-gray-300
                          transition-all duration-300 group-hover:bg-black group-hover:text-white">
                <span class="material-symbols-rounded text-xl -rotate-45 group-hover:rotate-0 transition-transform duration-300">
                  arrow_forward
                </span>
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
                  <span class="material-symbols-rounded text-3xl">groups</span>
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
                       from-[#942934] to-[#e96510]"
              >
                Compromiso con la transparencia
              </h3>

              <p class="mt-4 text-gray-600 leading-relaxed">
                Antes de iniciar cada programa, compartimos los perfiles de los conferencistas asignados, garantizando claridad
                y calidad en el proceso.
              </p>

              <!-- Grid cards con iconos -->
              <div class="mt-7 grid sm:grid-cols-2 gap-4">
                <div
                  v-for="(c, i) in cards"
                  :key="i"
                  class="group/card rounded-2xl border border-gray-100 bg-white/75 backdrop-blur p-5
                         transition-all duration-300 hover:-translate-y-0.5 hover:bg-white hover:shadow-sm"
                >
                  <div class="flex items-start justify-between gap-4">
                    <div>
                      <p class="text-sm font-bold text-gray-500">{{ c.label }}</p>
                      <p class="mt-2 font-black text-gray-900">{{ c.value }}</p>
                      <p class="mt-1 text-sm text-gray-600">{{ c.desc }}</p>
                    </div>

                    <div
                      class="w-11 h-11 rounded-2xl flex items-center justify-center text-white shadow-md
                             bg-gradient-to-br transition-transform duration-500 group-hover/card:scale-110 group-hover/card:rotate-3"
                      :class="c.grad"
                    >
                      <span class="material-symbols-rounded">{{ c.icon }}</span>
                    </div>
                  </div>

                  <div
                    class="mt-5 h-1 w-10 rounded-full bg-gray-200 transition-all duration-500
                           group-hover/card:w-full group-hover/card:bg-gradient-to-r"
                    :class="c.grad"
                  ></div>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>
</template>
