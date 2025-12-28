<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'

const inView = ref(false)
const rootEl = ref(null)
let observer = null

onMounted(() => {
  observer = new IntersectionObserver(
    ([entry]) => {
      if (entry.isIntersecting) {
        inView.value = true
        observer?.disconnect()
      }
    },
    { threshold: 0.25, rootMargin: '0px 0px -10% 0px' }
  )

  if (rootEl.value) observer.observe(rootEl.value)
})

onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <section
    ref="rootEl"
    class="relative w-full py-14 lg:py-18 px-4 sm:px-6 lg:px-8 overflow-hidden"
  >
    <div
      class="relative mx-auto max-w-7xl overflow-hidden rounded-[2rem]
             shadow-[0_20px_60px_-20px_rgba(0,0,0,0.25)]
             transition-all duration-700 ease-out"
      :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
    >
      <!-- Fondo -->
      <div class="absolute inset-0 bg-primary-vinotinto">
        <div class="absolute inset-0 animated-gradient opacity-85"></div>
        <div class="absolute inset-0 bg-noise opacity-[0.03] mix-blend-overlay"></div>
      </div>

      <!-- Contenido -->
      <div
        class="relative z-10 flex flex-col lg:flex-row items-start lg:items-center
               justify-between gap-8 px-8 py-10 lg:px-12 lg:py-12"
      >
        <!-- Texto -->
        <div class="max-w-2xl">
          <span
            class="inline-flex items-center gap-2 px-3 py-1 rounded-full
                   bg-white/10 border border-white/20 backdrop-blur
                   text-xs font-bold tracking-widest text-white uppercase mb-4"
          >
            <span class="w-2 h-2 rounded-full bg-orange-400"></span>
            Programas a la medida
          </span>

          <h2 class="text-2xl md:text-3xl font-black text-white leading-tight">
            ¿Buscas una solución adaptada a tu entidad?
          </h2>

          <p class="mt-3 text-base text-white/90 leading-relaxed max-w-xl">
            Conversemos y diseñemos una propuesta alineada a tus objetivos,
            tiempos y realidad institucional.
          </p>
        </div>

        <!-- CTAs -->
        <div class="flex flex-col sm:flex-row gap-3">
          <a
            href="/contacto"
            class="group relative inline-flex items-center justify-center gap-2
                   bg-white text-primary-vinotinto px-7 py-3.5 rounded-xl
                   font-bold shadow-md hover:-translate-y-0.5
                   transition-all duration-300 overflow-hidden"
          >
            <span class="relative z-10">Contáctenos</span>
            <span class="material-symbols-rounded relative z-10 text-lg group-hover:translate-x-1 transition-transform">
              arrow_forward
            </span>
            <div
              class="absolute inset-0 bg-gradient-to-r from-white via-orange-50 to-white
                     opacity-0 group-hover:opacity-100 transition-opacity"
            ></div>
          </a>

          <a
            href="/oferta"
            class="inline-flex items-center justify-center px-7 py-3.5 rounded-xl
                   font-bold text-white border border-white/30
                   hover:bg-white/10 hover:border-white/60 transition-all duration-300"
          >
            Ver oferta
          </a>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
/* reutilizamos exactamente los mismos estilos del ProgramCtaStrip */
.animated-gradient {
  background: linear-gradient(
    135deg,
    #4a0415 0%,
    #7f1d2a 25%,
    #b91c39 50%,
    #d95d18 75%,
    #cfa42e 100%
  );
  background-size: 300% 300%;
  animation: gradientMove 18s ease-in-out infinite;
}

@keyframes gradientMove {
  0% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
  100% { background-position: 0% 50%; }
}

.bg-noise {
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.8' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)' opacity='0.5'/%3E%3C/svg%3E");
}
</style>
