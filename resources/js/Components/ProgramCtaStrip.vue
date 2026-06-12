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
        observer && observer.disconnect()
      }
    },
    { threshold: 0.2, rootMargin: '0px 0px -10% 0px' }
  )

  if (rootEl.value) observer.observe(rootEl.value)
})

onBeforeUnmount(() => {
  if (observer) observer.disconnect()
})
</script>

<template>
  <section
    ref="rootEl"
    class="relative w-full py-20 lg:py-28 px-4 sm:px-6 lg:px-8 overflow-hidden"
  >
    <div
      class="relative mx-auto max-w-7xl overflow-hidden rounded-[2.5rem] shadow-2xl transition-all duration-1000 ease-out"
      :class="inView ? 'opacity-100 translate-y-0 scale-100' : 'opacity-0 translate-y-12 scale-95'"
    >
      <!-- Fondo -->
      <div class="absolute inset-0 bg-primary-vinotinto">
        <div class="absolute inset-0 animated-gradient opacity-90"></div>
        <div class="absolute inset-0 bg-noise opacity-[0.04] mix-blend-overlay"></div>
      </div>

      <!-- Burbujas -->
      <div class="bubbles absolute inset-0 z-0 pointer-events-none">
        <span v-for="n in 6" :key="n"></span>
      </div>

      <div class="relative z-10 grid grid-cols-1 lg:grid-cols-12 items-center lg:min-h-[500px]">
        <!-- Columna texto -->
        <div class="lg:col-span-7 p-8 md:p-12 lg:p-16 flex flex-col justify-center h-full relative">
          <div class="hidden lg:block absolute right-0 top-10 bottom-10 w-px bg-gradient-to-b from-transparent via-white/20 to-transparent"></div>

          <div class="space-y-8 relative z-20">
            <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/10 border border-white/20 backdrop-blur-md shadow-lg w-fit">
              <span class="relative flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-orange-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-orange-500"></span>
              </span>
                <span class="text-[11px] font-bold tracking-widest text-white uppercase">
                Línea de formación institucional
              </span>
            </div>

              <h2 class="text-2xl md:text-3xl lg:text-3xl font-black text-white leading-tight drop-shadow-sm">
              ¿Cuál de nuestras líneas de negocio se ajusta mejor a las necesidades de su entidad
              <span class="text-transparent bg-clip-text bg-gradient-to-r from-orange-200 to-white">
                y de su equipo de trabajo
              </span>?
            </h2>

              <p class="text-base text-white/90 font-medium leading-relaxed max-w-xl text-justify">
              Diseñamos programas académicos que se ajustan a la realidad institucional de cada entidad,
              considerando su cobertura, tiempos y presupuesto.
              Con el acompañamiento de nuestro equipo académico y de Ovi, la experiencia formativa
              se consolida como sólida, práctica y pertinente.
            </p>

            <!-- Lista de modalidades -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 max-w-2xl">
              <div class="rounded-2xl bg-white/10 border border-white/20 backdrop-blur-md p-4">
                <div class="flex items-center gap-2 mb-2">
                  <span class="material-symbols-rounded text-white text-xl">location_city</span>
                  <span class="text-white font-bold text-sm">Presencial</span>
                </div>
                <p class="text-white/85 text-sm leading-relaxed">
                  Principalmente en Bogotá D.C. y otras ciudades capitales del país.
                </p>
              </div>

              <div class="rounded-2xl bg-white/10 border border-white/20 backdrop-blur-md p-4">
                <div class="flex items-center gap-2 mb-2">
                  <span class="material-symbols-rounded text-white text-xl">videocam</span>
                  <span class="text-white font-bold text-sm">Virtual</span>
                </div>
                  <p class="text-white/85 text-[13px] leading-relaxed">
                  Programas sincrónicos y en vivo que promueven la participación, la interacción
                  y la optimización de costos.
                </p>
              </div>

              <div class="rounded-2xl bg-white/10 border border-white/20 backdrop-blur-md p-4">
                <div class="flex items-center gap-2 mb-2">
                  <span class="material-symbols-rounded text-white text-xl">merge</span>
                  <span class="text-white font-bold text-sm">Híbrida</span>
                </div>
                  <p class="text-white/85 text-[13px] leading-relaxed">
                  Alternativa eficiente para diplomados, cursos de mayor duración
                  o entidades con presencia regional.
                </p>
              </div>
            </div>

            <div class="flex flex-col sm:flex-row gap-4 pt-2">
              <a
                href="/contacto"
                class="group relative inline-flex items-center justify-center gap-3 bg-white text-primary-vinotinto px-8 py-4 rounded-xl font-bold shadow-[0_0_20px_rgba(255,255,255,0.3)] hover:shadow-[0_0_30px_rgba(255,255,255,0.5)] hover:-translate-y-1 transition-all duration-300 overflow-hidden"
              >
                <span class="relative z-10">Solicitar propuesta</span>
                <span class="material-symbols-rounded relative z-10 text-xl group-hover:translate-x-1 transition-transform">arrow_forward</span>
                <div class="absolute inset-0 bg-gradient-to-r from-white via-orange-50 to-white opacity-0 group-hover:opacity-100 transition-opacity"></div>
              </a>

              <a
                href="/oferta"
                class="inline-flex items-center justify-center px-8 py-4 rounded-xl font-bold text-white border border-white/30 hover:bg-white/10 hover:border-white/60 transition-all duration-300"
              >
                Ver catálogo
              </a>
            </div>
          </div>
        </div>

        <!-- Columna imagen -->
        <div class="lg:col-span-5 relative h-full min-h-[300px] lg:min-h-full flex items-center justify-center p-8 overflow-hidden">
          <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[300px] h-[300px] bg-primary-naranja/40 rounded-full blur-[80px] animate-pulse-slow"></div>

          <div class="relative z-10 animate-float">
            <div class="absolute -bottom-8 left-1/2 -translate-x-1/2 w-32 h-4 bg-black/30 blur-xl rounded-[100%] animate-shadow"></div>

            <img
              src="/images/aliado-8.png"
              alt="Ovi — Búho F&C"
              class="w-48 md:w-56 lg:w-64 h-auto object-contain drop-shadow-2xl filter brightness-110"
            />

            <div class="absolute top-0 right-0 w-2 h-2 bg-white rounded-full blur-[1px] animate-sparkle delay-75"></div>
            <div class="absolute bottom-10 left-0 w-1.5 h-1.5 bg-orange-300 rounded-full blur-[1px] animate-sparkle delay-300"></div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
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
  animation: gradientMove 15s ease-in-out infinite;
}
@keyframes gradientMove {
  0% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
  100% { background-position: 0% 50%; }
}

.bg-noise {
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.8' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)' opacity='0.5'/%3E%3C/svg%3E");
}

.bubbles span {
  position: absolute;
  bottom: -10%;
  border-radius: 50%;
  background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, 0.1), transparent);
  box-shadow: 0 0 10px rgba(255,255,255,0.1);
  animation: rise 15s infinite ease-in;
  opacity: 0;
}
.bubbles span:nth-child(1) { width: 80px; height: 80px; left: 10%; animation-duration: 14s; }
.bubbles span:nth-child(2) { width: 40px; height: 40px; left: 20%; animation-duration: 18s; animation-delay: 2s; }
.bubbles span:nth-child(3) { width: 100px; height: 100px; left: 35%; animation-duration: 22s; animation-delay: 4s; }
.bubbles span:nth-child(4) { width: 60px; height: 60px; left: 50%; animation-duration: 16s; animation-delay: 0s; }
.bubbles span:nth-child(5) { width: 30px; height: 30px; left: 70%; animation-duration: 19s; animation-delay: 3s; }
.bubbles span:nth-child(6) { width: 90px; height: 90px; left: 85%; animation-duration: 25s; animation-delay: 5s; }

@keyframes rise {
  0% { transform: translateY(0) scale(1); opacity: 0; }
  20% { opacity: 0.3; }
  80% { opacity: 0.3; }
  100% { transform: translateY(-120vh) scale(1.5); opacity: 0; }
}

.animate-float { animation: float 6s ease-in-out infinite; }
.animate-shadow { animation: shadowScale 6s ease-in-out infinite; }
.animate-pulse-slow { animation: pulseGlow 4s ease-in-out infinite; }

@keyframes float {
  0%, 100% { transform: translateY(0px); }
  50% { transform: translateY(-15px); }
}
@keyframes shadowScale {
  0%, 100% { transform: translateX(-50%) scale(1); opacity: 0.3; }
  50% { transform: translateX(-50%) scale(0.8); opacity: 0.2; }
}
@keyframes pulseGlow {
  0%, 100% { opacity: 0.4; transform: translate(-50%, -50%) scale(1); }
  50% { opacity: 0.6; transform: translate(-50%, -50%) scale(1.1); }
}

@keyframes sparkle {
  0%, 100% { opacity: 0; transform: scale(0); }
  50% { opacity: 1; transform: scale(1); }
}
.animate-sparkle { animation: sparkle 3s ease-in-out infinite; }
</style>
