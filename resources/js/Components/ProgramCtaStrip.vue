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
    {
      threshold: 0.3,
      rootMargin: '0px 0px -10% 0px',
    }
  )

  if (rootEl.value) {
    observer.observe(rootEl.value)
  }
})

onBeforeUnmount(() => {
  if (observer) observer.disconnect()
})
</script>

<template>
  <section
    ref="rootEl"
    class="relative px-4 sm:px-6 lg:px-8 py-16 md:py-20 transition-all duration-700 ease-out"
    :class="inView ? 'opacity-100 translate-y-0 blur-0' : 'opacity-0 translate-y-10 blur-[2px]'"
  >
    <!-- Contenedor principal con gradiente animado -->
    <div
      class="relative mx-auto max-w-7xl overflow-hidden rounded-[2.4rem]
             animated-gradient shadow-2xl text-mono-blanco"
    >
      <!-- Burbujas que suben -->
      <div class="bubbles">
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
      </div>

      <!-- Glow superior suave -->
      <div class="pointer-events-none absolute inset-0 z-[1]">
        <div class="absolute -inset-40 bg-[radial-gradient(circle_at_top,#ffffff33,transparent_60%)]"></div>
      </div>

      <!-- Contenido con efecto glass -->
      <div
        class="relative z-[2] m-4 md:m-6 rounded-[2rem] bg-white/6
               border border-white/10 backdrop-blur-[18px]
               shadow-[0_24px_70px_rgba(0,0,0,0.45)]
               flex flex-col md:flex-row items-center gap-10 md:gap-12
               px-8 sm:px-10 lg:px-14 py-10"
      >
        <!-- Texto -->
        <div class="w-full md:w-7/12 space-y-6">
          <p
            class="inline-flex items-center gap-2 rounded-full bg-mono-blanco/10
                   px-4 py-1.5 text-xs font-semibold tracking-[0.18em] uppercase"
          >
            Programas a la medida
            <span class="h-1 w-1 rounded-full bg-mono-blanco/70"></span>
            Sector público
          </p>

          <h3 class="text-3xl lg:text-4xl font-extrabold leading-tight">
            ¿Quieres llevar un programa especializado de F&amp;C a tu entidad?
          </h3>

          <p class="text-base text-mono-blanco/85 max-w-xl">
            Diseñamos experiencias de formación exclusivas para tu entidad:
            temáticas, intensidad horaria y modalidad se construyen contigo.
            Nuestro equipo académico y el búho de F&amp;C se encarga del resto.
          </p>

          <div class="flex flex-col sm:flex-row gap-4 pt-2">
            <a
              href="/contacto"
              class="inline-flex items-center justify-center gap-2 rounded-xl px-7 py-3.5
                     font-semibold bg-mono-blanco text-primary-vinotinto
                     shadow-lg hover:shadow-xl hover:-translate-y-0.5
                     transition-all duration-300"
            >
              Diseñar un programa
              <span class="text-lg">→</span>
            </a>

            <a
              href="/oferta"
              class="inline-flex items-center justify-center rounded-xl px-6 py-3.5
                     font-semibold border border-mono-blanco/45
                     text-mono-blanco hover:bg-mono-blanco/10
                     hover:border-mono-blanco/80 transition-all duration-300"
            >
              Ver líneas de formación
            </a>
          </div>

          <p class="text-xs text-mono-blanco/70 pt-1">
            También adaptamos congresos y diplomados como eventos privados para tu entidad.
          </p>
        </div>

        <!-- Búho -->
        <div class="w-full md:w-5/12 flex justify-center md:justify-end">
          <div class="relative h-56 w-44 sm:h-64 sm:w-52 flex items-center justify-center">
            <!-- Tarjeta glass del búho -->
            <div
              class="absolute inset-0 rounded-[2.2rem] bg-white/8 backdrop-blur-[22px]
                     border border-white/18 shadow-[0_18px_40px_rgba(0,0,0,0.5)]"
            ></div>

            <img
              src="/images/aliado-8.png"
              alt="Búho F&C"
              class="relative z-10 h-auto w-auto
                     drop-shadow-[0_20px_40px_rgba(0,0,0,0.6)]
                     animate-[floatBuho_5.5s_ease-in-out_infinite]"
            />

            <!-- Sombra elíptica -->
            <div
              class="pointer-events-none absolute -bottom-4 left-1/2 -translate-x-1/2
                     h-7 w-28 rounded-full bg-black/45 blur-xl opacity-80"
            ></div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
/* Búho flotando */
@keyframes floatBuho {
  0%   { transform: translateY(0px); }
  50%  { transform: translateY(-10px); }
  100% { transform: translateY(0px); }
}

/* ⚡ Gradiente más rápido con colores separados */
.animated-gradient {
  background: linear-gradient(
    120deg,
    #4c1020 0%,
    #942934 18%,
    #d32f57 35%,
    #e96510 55%,
    #f39322 75%,
    #a08e43 100%
  );
  background-size: 380% 380%;
  animation: gradientFlow 10s ease-in-out infinite;
}

@keyframes gradientFlow {
  0%   { background-position: 0% 50%; }
  50%  { background-position: 100% 50%; }
  100% { background-position: 0% 50%; }
}

/* 🫧 Burbujas que suben */
.bubbles {
  position: absolute;
  inset: 0;
  overflow: hidden;
  pointer-events: none;
  z-index: 0;
}

.bubbles span {
  position: absolute;
  bottom: -5rem;
  width: 90px;
  height: 90px;
  background: radial-gradient(circle, rgba(255, 255, 255, 0.4), transparent 60%);
  border-radius: 9999px;
  filter: blur(2px);
  opacity: 0;
  animation: bubbleUp 18s linear infinite;
}

.bubbles span:nth-child(1) {
  left: 8%;
  animation-duration: 17s;
  animation-delay: 0s;
  transform: scale(0.9);
}

.bubbles span:nth-child(2) {
  left: 28%;
  animation-duration: 19s;
  animation-delay: 2s;
  transform: scale(1.1);
}

.bubbles span:nth-child(3) {
  left: 48%;
  animation-duration: 16s;
  animation-delay: 4s;
  transform: scale(0.8);
}

.bubbles span:nth-child(4) {
  left: 68%;
  animation-duration: 20s;
  animation-delay: 1s;
  transform: scale(1.15);
}

.bubbles span:nth-child(5) {
  left: 82%;
  animation-duration: 18s;
  animation-delay: 3s;
  transform: scale(0.95);
}

.bubbles span:nth-child(6) {
  left: 92%;
  animation-duration: 22s;
  animation-delay: 5s;
  transform: scale(0.75);
}

@keyframes bubbleUp {
  0% {
    transform: translateY(0) scale(0.8);
    opacity: 0;
  }
  10% {
    opacity: 0.45;
  }
  70% {
    opacity: 0.45;
  }
  100% {
    transform: translateY(-130%) scale(1.15);
    opacity: 0;
  }
}
</style>
