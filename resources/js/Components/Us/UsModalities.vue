<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'

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

/** Hover preview + click fijo */
const hovered = ref(null)
const pinned = ref(null)
const activeKey = computed(() => pinned.value ?? hovered.value ?? 'presencial')

function pin(key) {
  pinned.value = (pinned.value === key) ? null : key
}

const modalities = [
  {
    key: 'presencial',
    title: 'Presencial',
    desc: 'Principalmente en Bogotá D.C. y otras ciudades capitales del país.',
    icon: 'location_city',
    accent: '#942934',
    grad: 'from-[#942934] to-[#d32f57]',
    pill: 'bg-[#942934]/10 text-[#942934] border-[#942934]/20',
  },
  {
    key: 'virtual',
    title: 'Virtual',
    desc: 'Programas sincrónicos y en vivo, que facilitan la participación, interacción y optimización de costos.',
    icon: 'videocam',
    accent: '#e96510',
    grad: 'from-[#e96510] to-[#f39322]',
    pill: 'bg-[#e96510]/10 text-[#e96510] border-[#e96510]/20',
  },
  {
    key: 'hibrida',
    title: 'Híbrida',
    desc: 'Alternativa eficiente para diplomados y cursos de mayor duración, o entidades que tienen sedes o regionales.',
    icon: 'hub',
    accent: '#685f2f',
    grad: 'from-[#685f2f] to-[#a08e43]',
    pill: 'bg-[#685f2f]/10 text-[#685f2f] border-[#685f2f]/20',
  },
]

/** Fondo dinámico por modalidad (tipo “foto”) */
const bgPresets = {
  presencial: {
    backgroundImage: `
      radial-gradient(1000px 560px at 15% 25%, rgba(148,41,52,0.28), transparent 62%),
      radial-gradient(900px 560px at 85% 25%, rgba(211,47,87,0.18), transparent 65%),
      radial-gradient(800px 520px at 60% 95%, rgba(233,101,16,0.08), transparent 60%),
      linear-gradient(135deg, rgba(18,18,18,0.06), rgba(18,18,18,0.0))
    `
  },
  virtual: {
    backgroundImage: `
      radial-gradient(1000px 560px at 18% 22%, rgba(233,101,16,0.28), transparent 62%),
      radial-gradient(900px 560px at 82% 25%, rgba(243,147,34,0.20), transparent 65%),
      radial-gradient(800px 520px at 55% 92%, rgba(148,41,52,0.08), transparent 60%),
      linear-gradient(135deg, rgba(18,18,18,0.06), rgba(18,18,18,0.0))
    `
  },
  hibrida: {
    backgroundImage: `
      radial-gradient(1000px 560px at 18% 24%, rgba(104,95,47,0.26), transparent 62%),
      radial-gradient(900px 560px at 85% 22%, rgba(160,142,67,0.22), transparent 65%),
      radial-gradient(800px 520px at 55% 92%, rgba(233,101,16,0.06), transparent 60%),
      linear-gradient(135deg, rgba(18,18,18,0.06), rgba(18,18,18,0.0))
    `
  },
}

const activeBgStyle = computed(() => bgPresets[activeKey.value] || bgPresets.presencial)
const activeModal = computed(() => modalities.find(m => m.key === activeKey.value) || modalities[0])
</script>

<template>
  <section ref="rootEl" class="flex items-center min-h-dvh relative w-full overflow-hidden bg-white py-16">
    <!-- Fondo dinámico extendido -->
    <div class="absolute inset-0 z-0 pointer-events-none">
      <div class="absolute inset-0 transition-opacity duration-500" :style="activeBgStyle"></div>
      <div class="absolute inset-0 bg-white/82 backdrop-blur-[1px]"></div>

      <div class="absolute top-0 right-0 w-[700px] h-[700px] bg-[#942934]/4 rounded-full blur-[120px] -translate-y-1/2 translate-x-1/3"></div>
      <div class="absolute bottom-0 left-0 w-[700px] h-[700px] bg-[#e96510]/4 rounded-full blur-[120px] translate-y-1/2 -translate-x-1/3"></div>
      <div class="absolute inset-0 bg-[url('/images/grid-pattern.png')] opacity-[0.02]"></div>
      <div class="absolute inset-0 bg-gradient-to-b from-white via-white/85 to-white"></div>
    </div>

    <div class="relative z-10 max-w-7xl mx-auto px-6">
      <!-- Header -->
      <div
        class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-6 transition-all duration-1000 ease-out"
        :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-10'"
      >
        <div>
          <span
            class="inline-flex items-center gap-2 px-3 py-1 rounded-full
                   bg-[#e96510]/5 text-[#e96510]
                   text-sm font-bold uppercase tracking-widest"
          >
            <span class="w-2 h-2 rounded-full bg-[#e96510]"></span>
            Modalidades
          </span>

          <h2
            class="mt-6 text-3xl md:text-4xl font-black tracking-tight
                   bg-clip-text text-transparent bg-gradient-to-r
                   from-[#942934] via-[#e96510] to-[#685f2f]"
          >
            Modalidades de formación
          </h2>

          <p class="mt-3 text-lg text-gray-600 max-w-2xl">
            Diseñamos experiencias de aprendizaje ajustadas al contexto, tiempos y alcance de cada entidad.
          </p>
        </div>

        <!-- Hint + estado -->
        <div class="flex items-center gap-3 text-sm text-gray-600">
          <span
            class="inline-flex items-center gap-2 px-3 py-2 rounded-full border bg-white/70 backdrop-blur font-bold"
            :style="{ borderColor: activeModal.accent + '33', color: activeModal.accent }"
          >
            <span class="material-symbols-rounded text-[18px]">{{ activeModal.icon }}</span>
            {{ activeModal.title }} activa
          </span>
        </div>
      </div>

      <!-- Cards -->
      <div
        class="mt-10 grid md:grid-cols-3 gap-6 transition-all duration-1000 ease-out"
        :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
      >
        <button
          v-for="m in modalities"
          :key="m.key"
          type="button"
          class="group text-left relative rounded-[28px] border border-gray-100 bg-white/75 backdrop-blur p-7
                 shadow-[0_10px_40px_-14px_rgba(0,0,0,0.10)]
                 transition-all duration-300 hover:-translate-y-1 hover:bg-white
                 focus:outline-none focus:ring-4 focus:ring-[#942934]/10"
          :class="activeKey === m.key ? 'ring-2 ring-black/5' : ''"
          @mouseenter="hovered = m.key"
          @mouseleave="hovered = null"
          @focus="hovered = m.key"
          @blur="hovered = null"
          @click="pin(m.key)"
        >
          <!-- top row -->
          <div class="flex items-start justify-between gap-4">
            <div class="flex items-center gap-3">
              <div
                class="w-12 h-12 rounded-2xl flex items-center justify-center text-white shadow-lg
                       bg-gradient-to-br transition-transform duration-500 group-hover:scale-110 group-hover:rotate-3"
                :class="m.grad"
              >
                <span class="material-symbols-rounded text-2xl">{{ m.icon }}</span>
              </div>

              <span class="inline-flex text-sm font-black px-3 py-1 rounded-full border" :class="m.pill">
                {{ m.title }}
              </span>
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

          <p class="mt-4 text-gray-700 leading-relaxed">
            {{ m.desc }}
          </p>

          <!-- subtle bottom progress -->
          <div
            class="mt-7 h-1 w-12 rounded-full bg-gray-200 transition-all duration-500
                   group-hover:w-full group-hover:bg-gradient-to-r"
            :class="m.grad"
          ></div>

          <!-- Active tag -->
          <div
            v-if="activeKey === m.key"
            class="absolute top-5 right-5 inline-flex items-center gap-2 px-3 py-1 rounded-full
                   bg-white/80 border border-gray-200 text-xs font-black text-gray-800 shadow-sm"
          >
            <span class="w-2 h-2 rounded-full" :style="{ background: m.accent }"></span>
            Seleccionada
          </div>
        </button>
      </div>
    </div>
  </section>
</template>
