<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'

/** Animación al entrar (igual que tu patrón) */
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

/** Tabs + iconografía + degradados */
const tabs = [
  {
    key: 'formacion',
    label: 'Formación',
    icon: 'school',
    accent: '#942934',
    gradient: 'from-[#942934] to-[#d32f57]',
    title: 'Formación con enfoque práctico',
    text: 'Diseñamos experiencias formativas orientadas al fortalecimiento de capacidades técnicas y a la mejora de la toma de decisiones.',
  },
  {
    key: 'consultoria',
    label: 'Consultoría',
    icon: 'handshake',
    accent: '#e96510',
    gradient: 'from-[#e96510] to-[#f39322]',
    title: 'Consultoría orientada a resultados',
    text: 'Acompañamos organizaciones con claridad conceptual y aplicabilidad, priorizando el cumplimiento normativo y la generación de valor.',
  },
  {
    key: 'enfoque',
    label: 'Enfoque',
    icon: 'model_training',
    accent: '#685f2f',
    gradient: 'from-[#685f2f] to-[#a08e43]',
    title: 'Metodologías y acompañamiento',
    text: 'Nuestro enfoque se basa en metodologías prácticas, contenidos actualizados y acompañamiento especializado, alineados con necesidades reales.',
  },
]

/** Hover preview + click fijo (como tu offer hero) */
const hovered = ref(null)
const pinned = ref(null)
const activeKey = computed(() => pinned.value ?? hovered.value ?? 'formacion')
const activeTab = computed(() => tabs.find(t => t.key === activeKey.value) || tabs[0])

function pin(key) {
  pinned.value = (pinned.value === key) ? null : key
}

/** Fondo dinámico por tab (gradiente tipo “foto”) */
const bgPresets = {
  formacion: {
    backgroundImage: `
      radial-gradient(1000px 560px at 18% 20%, rgba(211,47,87,0.26), transparent 62%),
      radial-gradient(900px 560px at 80% 25%, rgba(148,41,52,0.22), transparent 65%),
      radial-gradient(800px 520px at 60% 95%, rgba(233,101,16,0.10), transparent 60%),
      linear-gradient(135deg, rgba(18,18,18,0.06), rgba(18,18,18,0.0))
    `
  },
  consultoria: {
    backgroundImage: `
      radial-gradient(1000px 560px at 20% 15%, rgba(233,101,16,0.26), transparent 62%),
      radial-gradient(900px 600px at 82% 22%, rgba(243,147,34,0.22), transparent 66%),
      radial-gradient(800px 520px at 55% 92%, rgba(148,41,52,0.10), transparent 60%),
      linear-gradient(135deg, rgba(18,18,18,0.06), rgba(18,18,18,0.0))
    `
  },
  enfoque: {
    backgroundImage: `
      radial-gradient(1000px 560px at 20% 20%, rgba(160,142,67,0.26), transparent 62%),
      radial-gradient(900px 600px at 85% 25%, rgba(104,95,47,0.20), transparent 66%),
      radial-gradient(800px 520px at 55% 92%, rgba(233,101,16,0.08), transparent 60%),
      linear-gradient(135deg, rgba(18,18,18,0.06), rgba(18,18,18,0.0))
    `
  }
}
const activeBgStyle = computed(() => bgPresets[activeKey.value] || bgPresets.formacion)
</script>

<template>
  <section ref="rootEl" class="relative w-full overflow-hidden bg-white py-16 md:py-20">
    <!-- Fondo dinámico extendido -->
    <div class="absolute inset-0 z-0 pointer-events-none">
      <div class="absolute inset-0 transition-opacity duration-500" :style="activeBgStyle"></div>
      <div class="absolute inset-0 bg-white/82 backdrop-blur-[1px]"></div>

      <!-- blobs corporativos -->
      <div class="absolute top-0 right-0 w-[700px] h-[700px] bg-[#942934]/4 rounded-full blur-[120px] -translate-y-1/2 translate-x-1/3"></div>
      <div class="absolute bottom-0 left-0 w-[700px] h-[700px] bg-[#e96510]/4 rounded-full blur-[120px] translate-y-1/2 -translate-x-1/3"></div>

      <!-- grid pattern (si existe) -->
      <div class="absolute inset-0 bg-[url('/images/grid-pattern.svg')] opacity-[0.025]"></div>
    </div>

    <div class="relative z-10 max-w-7xl mx-auto px-6">
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-start">
        <!-- TEXTO -->
        <div class="lg:col-span-7">
          <div
            class="transition-all duration-1000 ease-out"
            :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-10'"
          >
            <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-[#942934]/5 text-[#942934] text-sm font-bold uppercase tracking-widest mb-6">
              <span class="w-2 h-2 rounded-full bg-[#942934]"></span>
              Nosotros
            </span>

            <h1 class="text-4xl md:text-5xl font-black tracking-tight leading-[1.05] text-gray-900">
              Más claridad,
              <span class="text-transparent bg-clip-text bg-gradient-to-r from-[#942934] via-[#e96510] to-[#685f2f]">
                mejor decisión
              </span>
              y ejecución real.
            </h1>

            <p class="mt-6 text-lg text-gray-600 leading-relaxed max-w-2xl">
              En F&amp;C Consultores desarrollamos programas de formación y consultoría orientados al fortalecimiento
              de capacidades técnicas y a la mejora de la toma de decisiones en entidades públicas y privadas.
            </p>

            <p class="mt-4 text-lg text-gray-600 leading-relaxed max-w-2xl">
              Nuestro enfoque se basa en metodologías prácticas, contenidos actualizados y acompañamiento especializado,
              asegurando procesos formativos de alta calidad y alineados con las necesidades reales de cada organización.
            </p>
          </div>

          <!-- Pills (hover preview + pin) -->
          <div
            class="mt-10 flex flex-wrap gap-3 transition-all duration-1000 ease-out"
            :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'"
          >
            <button
              v-for="t in tabs"
              :key="t.key"
              type="button"
              class="group inline-flex items-center gap-2 px-4 py-2 rounded-full border bg-white/70 backdrop-blur
                     font-bold text-sm transition-all duration-300 hover:-translate-y-0.5 hover:bg-white
                     focus:outline-none focus:ring-4 focus:ring-[#942934]/10"
              :class="activeKey === t.key ? 'border-gray-300 shadow' : 'border-gray-200'"
              @mouseenter="hovered = t.key"
              @mouseleave="hovered = null"
              @focus="hovered = t.key"
              @blur="hovered = null"
              @click="pin(t.key)"
              :style="activeKey === t.key ? { color: t.accent } : { color: '#111827' }"
            >
              <span class="material-symbols-rounded text-[18px]" :style="{ color: t.accent }">
                {{ t.icon }}
              </span>
              {{ t.label }}
              <span class="ml-1 text-gray-300 group-hover:text-gray-500 transition">↗</span>
            </button>
          </div>
        </div>

        <!-- TARJETA DERECHA -->
        <div class="lg:col-span-5">
          <div
            class="relative group bg-white/85 rounded-[2.5rem] p-8 md:p-10 border border-gray-100 overflow-hidden
                   shadow-[0_10px_40px_-10px_rgba(0,0,0,0.08)]
                   transition-all duration-1000 ease-out"
            :class="inView ? 'opacity-100 translate-y-0 scale-100' : 'opacity-0 translate-y-12 scale-95'"
          >
            <!-- overlay sutil -->
            <div class="absolute inset-0 bg-white/55 backdrop-blur-[2px]"></div>

            <div class="relative z-10">
              <div class="flex justify-between items-start mb-8">
                <div
                  class="w-16 h-16 rounded-2xl flex items-center justify-center text-white shadow-lg
                         transform transition-transform duration-500 group-hover:scale-110 group-hover:rotate-3
                         bg-gradient-to-br"
                  :class="activeTab.gradient"
                >
                  <span class="material-symbols-rounded text-3xl">{{ activeTab.icon }}</span>
                </div>

                <div
                  class="w-10 h-10 rounded-full border border-gray-100 flex items-center justify-center
                         text-gray-300 transition-all duration-300 group-hover:bg-black group-hover:text-white"
                >
                  <span class="material-symbols-rounded text-xl -rotate-45 group-hover:rotate-0 transition-transform duration-300">
                    arrow_forward
                  </span>
                </div>
              </div>

              <p
                class="text-sm font-black tracking-wide bg-clip-text text-transparent bg-gradient-to-r"
                :class="activeTab.gradient"
              >
                {{ activeTab.label.toUpperCase() }}
              </p>

              <h2
                class="mt-2 text-2xl font-black tracking-tight bg-clip-text text-transparent bg-gradient-to-r"
                :class="activeTab.gradient"
              >
                {{ activeTab.title }}
              </h2>

              <p class="mt-4 text-gray-600 leading-relaxed">
                {{ activeTab.text }}
              </p>

              <div class="mt-7 space-y-3">
                <div class="flex items-start gap-3 p-4 rounded-2xl bg-gray-50/70 border border-gray-100 transition-all duration-300 hover:bg-white hover:-translate-y-0.5">
                  <span class="material-symbols-rounded" :style="{ color: activeTab.accent }">verified</span>
                  <div>
                    <p class="font-black text-gray-900">Enfoque institucional</p>
                    <p class="text-sm text-gray-600 mt-1">Rigor técnico, claridad conceptual y aplicabilidad práctica.</p>
                  </div>
                </div>

                <div class="flex items-start gap-3 p-4 rounded-2xl bg-gray-50/70 border border-gray-100 transition-all duration-300 hover:bg-white hover:-translate-y-0.5">
                  <span class="material-symbols-rounded" :style="{ color: activeTab.accent }">policy</span>
                  <div>
                    <p class="font-black text-gray-900">Cumplimiento y valor</p>
                    <p class="text-sm text-gray-600 mt-1">Priorizamos cumplimiento normativo y generación de valor.</p>
                  </div>
                </div>

                <div class="flex items-start gap-3 p-4 rounded-2xl bg-gray-50/70 border border-gray-100 transition-all duration-300 hover:bg-white hover:-translate-y-0.5">
                  <span class="material-symbols-rounded" :style="{ color: activeTab.accent }">insights</span>
                  <div>
                    <p class="font-black text-gray-900">Resultados defendibles</p>
                    <p class="text-sm text-gray-600 mt-1">Decisiones con sustento, herramientas aplicables.</p>
                  </div>
                </div>
              </div>

              <div
                class="mt-8 h-1 w-12 rounded-full bg-gray-200 transition-all duration-500 group-hover:w-full
                       group-hover:bg-gradient-to-r"
                :class="activeTab.gradient"
              ></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
