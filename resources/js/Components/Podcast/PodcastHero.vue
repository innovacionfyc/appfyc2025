<script setup>
import { ref } from "vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";

defineProps({
  temporadaActual: { type: Object, default: null },
  totalEpisodios: { type: Number, default: 0 },
  canalUrl: { type: String, required: true },
});

// Logo oficial: SVG primero, PNG como respaldo si el navegador no lo carga.
const logoSrc = ref("/images/podcast/intimamente-hablando.svg");
const usarPng = () => {
  logoSrc.value = "/images/podcast/intimamente-hablando.png";
};
</script>

<template>
  <section
    class="relative overflow-hidden bg-podcast-oscuro rounded-[2rem] md:rounded-[2.5rem] px-6 py-10 sm:px-10 sm:py-14 lg:px-16 lg:py-20 shadow-xl shadow-slate-300/40"
  >
    <!-- Textura de puntos, misma receta que AccesoVirtual.vue pero más tenue -->
    <div
      class="absolute inset-0 opacity-[0.06] pointer-events-none"
      style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 28px 28px"
    ></div>

    <!-- Comilla de apertura: la marca de la conversación -->
    <span
      aria-hidden="true"
      class="hidden lg:block absolute -top-8 right-10 text-[22rem] leading-none font-black text-podcast-acento/10 select-none pointer-events-none"
      >“</span
    >

    <div class="relative z-10 grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-8 items-end">
      <div class="lg:col-span-8">
        <span
          class="inline-flex items-center gap-2.5 text-[11px] sm:text-xs font-bold uppercase tracking-[0.2em] text-slate-300 mb-6"
        >
          <span class="w-2 h-2 rounded-full bg-podcast-acento"></span>
          F&amp;C Consultores presenta
        </span>

        <!--
          El logo trae partes en #3f4e54 (icono y "Íntimamente") que se perderían sobre el
          hero del mismo color: va sobre una placa blanca, sin recolorear el SVG.
        -->
        <h1 class="m-0">
          <span
            class="inline-block bg-white rounded-2xl sm:rounded-3xl p-3 sm:p-4 shadow-xl shadow-black/20 ring-1 ring-white/20"
          >
            <img
              :src="logoSrc"
              alt="Íntimamente Hablando"
              width="1488"
              height="1080"
              class="block w-[15rem] xs:w-[16.5rem] sm:w-[19rem] lg:w-[21rem] h-auto object-contain select-none"
              draggable="false"
              @error="usarPng"
            />
          </span>
        </h1>
        <p class="mt-5 text-sm font-bold uppercase tracking-[0.2em] text-slate-400">
          Podcast
        </p>

        <p class="mt-8 max-w-xl text-base sm:text-lg text-slate-300 leading-relaxed">
          Conversaciones pausadas con las personas que han construido lo público en
          Colombia: su oficio, sus decisiones y lo que aprendieron en el camino.
        </p>

        <div class="mt-10 flex flex-col sm:flex-row gap-3 sm:gap-4">
          <a href="#episodio-destacado" class="w-full sm:w-auto">
            <BtnUniversal
              label="Ver último episodio"
              icon="play_arrow"
              variant="white"
              size="lg"
              class="sm:w-auto"
            />
          </a>
          <a
            :href="canalUrl"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-flex items-center justify-center gap-2.5 w-full sm:w-auto px-8 py-3.5 rounded-2xl text-base font-bold text-white border border-white/25 hover:bg-white/10 hover:border-white/50 transition-all duration-300 focus:outline-none focus-visible:ring-4 focus-visible:ring-white/30"
          >
            <span class="material-symbols-rounded text-xl">smart_display</span>
            Canal de YouTube
          </a>
        </div>
      </div>

      <!-- Ficha de emisión -->
      <dl
        class="lg:col-span-4 grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-1 gap-px bg-white/10 rounded-2xl overflow-hidden border border-white/10"
      >
        <div class="bg-podcast-oscuro px-5 py-4">
          <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">
            En emisión
          </dt>
          <dd class="mt-1 flex items-center gap-2 text-white font-bold">
            <span class="relative flex h-2 w-2">
              <span
                class="animate-ping absolute inline-flex h-full w-full rounded-full bg-podcast-acento opacity-75"
              ></span>
              <span class="relative inline-flex rounded-full h-2 w-2 bg-podcast-acento"></span>
            </span>
            {{ temporadaActual ? `Temporada ${temporadaActual.numero}` : "Próximamente" }}
          </dd>
        </div>
        <div class="bg-podcast-oscuro px-5 py-4">
          <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">
            Publicados
          </dt>
          <dd class="mt-1 text-white font-bold tabular-nums">
            {{ totalEpisodios }} {{ totalEpisodios === 1 ? "episodio" : "episodios" }}
          </dd>
        </div>
        <div class="bg-podcast-oscuro px-5 py-4">
          <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">
            Frecuencia
          </dt>
          <dd class="mt-1 text-white font-bold">Cada dos semanas</dd>
        </div>
      </dl>
    </div>
  </section>
</template>
