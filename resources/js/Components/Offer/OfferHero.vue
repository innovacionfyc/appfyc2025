<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'

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
    { threshold: 0.15, rootMargin: '0px 0px -10% 0px' }
  )
  if (rootEl.value) observer.observe(rootEl.value)
})

onBeforeUnmount(() => observer?.disconnect())


const hovered = ref(null)
const pinned = ref(null)
const activeKey = computed(() => pinned.value ?? hovered.value ?? 'abiertos') 

function pin(key) {
  pinned.value = (pinned.value === key) ? null : key
}


const bgPresets = {
  abiertos: {
    backgroundImage: `
      radial-gradient(1000px 560px at 15% 20%, rgba(243,147,34,0.40), transparent 60%),
      radial-gradient(900px 560px at 80% 20%, rgba(204,199,21,0.22), transparent 62%),
      radial-gradient(800px 520px at 60% 95%, rgba(233,101,16,0.22), transparent 60%),
      linear-gradient(135deg, rgba(18,18,18,0.06), rgba(18,18,18,0.0))
    `
  },
  incompany: {
    backgroundImage: `
      radial-gradient(1000px 560px at 20% 15%, rgba(233,101,16,0.35), transparent 60%),
      radial-gradient(900px 600px at 80% 25%, rgba(148,41,52,0.35), transparent 65%),
      radial-gradient(800px 520px at 55% 92%, rgba(211,47,87,0.20), transparent 60%),
      linear-gradient(135deg, rgba(18,18,18,0.08), rgba(18,18,18,0.0))
    `
  },
  consultoria: {
    backgroundImage: `
      radial-gradient(1000px 560px at 20% 20%, rgba(160,142,67,0.35), transparent 62%),
      radial-gradient(900px 600px at 85% 25%, rgba(104,95,47,0.25), transparent 65%),
      radial-gradient(800px 520px at 55% 92%, rgba(148,41,52,0.18), transparent 60%),
      linear-gradient(135deg, rgba(18,18,18,0.07), rgba(18,18,18,0.0))
    `
  }
}

const activeBgStyle = computed(() => bgPresets[activeKey.value] || bgPresets.abiertos)
</script>

<template>
  <section
    ref="rootEl"
    class="flex items-center w-full min-h-dvh py-20 lg:py-28 overflow-hidden bg-white"
  >
    <!-- ✅ Fondo dinámico EXTENDIDO por todo el Hero -->
    <div class="absolute inset-0 z-0 pointer-events-none">
      <div class="absolute inset-0 transition-opacity duration-500" :style="activeBgStyle"></div>

      <!-- overlay de legibilidad para TODO el hero -->
      <div class="absolute inset-0 bg-white/82 backdrop-blur-[1px]"></div>

      <!-- blobs + grid (se mantienen, pero suaves para no pelear con la “imagen”) -->
      <div
        class="absolute top-0 right-0 w-[700px] h-[700px] bg-primary-vinotinto/4 rounded-full blur-[120px] -translate-y-1/2 translate-x-1/3">
      </div>
      <div
        class="absolute bottom-0 left-0 w-[700px] h-[700px] bg-primary-naranja/4 rounded-full blur-[120px] translate-y-1/2 -translate-x-1/3">
      </div>
      <div class="absolute inset-0 bg-[url('/images/grid-pattern.svg')] opacity-[0.025]"></div>
    </div>

    <div class="relative z-10 max-w-7xl mx-auto px-6 lg:px-8">
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-14 items-center">

        <!-- TEXTO -->
        <div class="lg:col-span-7">
          <div
            class="transition-all duration-1000 ease-out"
            :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-10'"
          >
            <span
              class="inline-flex items-center gap-2 px-3 py-1 rounded-full
                     bg-primary-vinotinto/5 text-primary-vinotinto
                     text-sm font-bold uppercase tracking-widest mb-6"
            >
              <span class="w-2 h-2 rounded-full bg-primary-vinotinto"></span>
              Oferta Académica
            </span>

            <h1
              class="text-5xl lg:text-6xl font-black text-gray-900
                     leading-[1.05] tracking-tight"
            >
              Soluciones de
              <span
                class="text-transparent bg-clip-text
                       bg-gradient-to-r from-primary-vinotinto to-primary-naranja"
              >
                Alto Impacto
              </span>
              para tu entidad.
            </h1>

            <p class="mt-6 text-lg text-gray-600 leading-relaxed max-w-2xl">
              Unificamos la oferta académica con una metodología práctica de
              apropiación del conocimiento. Programas
              <strong class="text-gray-900 font-semibold">In-company</strong>,
              <strong class="text-gray-900 font-semibold">Abiertos</strong> y
              <strong class="text-gray-900 font-semibold">Asesorías / Consultorías</strong>,
              diseñados para la realidad del sector público y privado.
            </p>
          </div>
        </div>

        <!-- TARJETA DERECHA (misma estructura) -->
        <div class="lg:col-span-5">
          <div
            class="relative group bg-white/85 rounded-[2.5rem] p-8 lg:p-10
                   border border-gray-100 overflow-hidden
                   shadow-[0_10px_40px_-10px_rgba(0,0,0,0.06)]
                   transition-all duration-1000 ease-out"
            :class="inView
              ? 'opacity-100 translate-y-0 scale-100'
              : 'opacity-0 translate-y-12 scale-95'"
          >
            <!-- overlay sutil interno para que no compita con el fondo general -->
            <div class="absolute inset-0 bg-white/60 backdrop-blur-[2px]"></div>

            <div class="relative z-10 flex flex-col h-full">
              <div class="flex justify-between items-start mb-8">
                <div
                  class="w-16 h-16 rounded-2xl flex items-center justify-center
                         text-3xl text-white shadow-lg
                         transform transition-transform duration-500
                         group-hover:scale-110 group-hover:rotate-3
                         bg-gradient-to-br from-primary-vinotinto to-primary-naranja"
                >
                  <span class="material-symbols-rounded">school</span>
                </div>

                <div
                  class="w-10 h-10 rounded-full border border-gray-100
                         flex items-center justify-center
                         text-gray-300 transition-all duration-300
                         group-hover:bg-black group-hover:text-white"
                >
                  <span class="material-symbols-rounded text-xl
                               -rotate-45 group-hover:rotate-0
                               transition-transform duration-300">
                    arrow_forward
                  </span>
                </div>
              </div>

              <h3 class="text-2xl font-black text-gray-900 leading-tight mb-4">
                Tres modalidades, un mismo estándar.
              </h3>

              <p class="text-gray-600 leading-relaxed mb-6">
                Nos adaptamos a tu contexto operativo sin sacrificar
                calidad académica, metodología ni resultados.
              </p>

              <!-- ✅ ORDEN COMO TU IMAGEN: Abiertos / In-company / Asesorías -->
              <div class="space-y-3">

                <!-- Abiertos -->
                <button
                  type="button"
                  class="w-full text-left flex items-center gap-3 p-4 rounded-2xl bg-gray-50/70 border border-gray-100
                         transition-all duration-300 hover:-translate-y-0.5 hover:bg-white
                         focus:outline-none focus:ring-4 focus:ring-primary-vinotinto/15"
                  :class="activeKey === 'abiertos' ? 'ring-2 ring-primary-naranja/15 bg-white' : ''"
                  @mouseenter="hovered = 'abiertos'"
                  @mouseleave="hovered = null"
                  @focus="hovered = 'abiertos'"
                  @blur="hovered = null"
                  @click="pin('abiertos')"
                >
                  <span class="material-symbols-rounded text-primary-naranja">groups</span>
                  <span class="font-bold text-gray-800">Abiertos</span>
                </button>

                <!-- In-company -->
                <button
                  type="button"
                  class="w-full text-left flex items-center gap-3 p-4 rounded-2xl bg-gray-50/70 border border-gray-100
                         transition-all duration-300 hover:-translate-y-0.5 hover:bg-white
                         focus:outline-none focus:ring-4 focus:ring-primary-vinotinto/15"
                  :class="activeKey === 'incompany' ? 'ring-2 ring-primary-vinotinto/15 bg-white' : ''"
                  @mouseenter="hovered = 'incompany'"
                  @mouseleave="hovered = null"
                  @focus="hovered = 'incompany'"
                  @blur="hovered = null"
                  @click="pin('incompany')"
                >
                  <span class="material-symbols-rounded text-primary-vinotinto">domain</span>
                  <span class="font-bold text-gray-800">In-company</span>
                </button>

                <!-- Asesorías / Consultorías -->
                <button
                  type="button"
                  class="w-full text-left flex items-center gap-3 p-4 rounded-2xl bg-gray-50/70 border border-gray-100
                         transition-all duration-300 hover:-translate-y-0.5 hover:bg-white
                         focus:outline-none focus:ring-4 focus:ring-primary-vinotinto/15"
                  :class="activeKey === 'consultoria' ? 'ring-2 ring-secondary-verde2/15 bg-white' : ''"
                  @mouseenter="hovered = 'consultoria'"
                  @mouseleave="hovered = null"
                  @focus="hovered = 'consultoria'"
                  @blur="hovered = null"
                  @click="pin('consultoria')"
                >
                  <span class="material-symbols-rounded text-secondary-verde2">handshake</span>
                  <span class="font-bold text-gray-800">Asesorías / Consultorías</span>
                </button>

              </div>

              <div
                class="mt-8 h-1 w-12 rounded-full bg-gray-200
                       transition-all duration-500 group-hover:w-full
                       group-hover:bg-gradient-to-r
                       group-hover:from-primary-vinotinto group-hover:to-primary-naranja">
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>
</template>
