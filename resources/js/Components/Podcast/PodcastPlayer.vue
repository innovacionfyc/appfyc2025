<script setup>
import { computed, ref, watch } from "vue";

const props = defineProps({
  // Iframe de youtube-nocookie.com ya construido en el servidor; null si el video no permite reproductor.
  embedUrl: { type: String, default: null },
  miniatura: { type: String, default: null },
  titulo: { type: String, required: true },
  numero: { type: Number, required: true },
  duracion: { type: String, default: null },
  canalUrl: { type: String, required: true },
});

// El iframe se crea únicamente cuando el visitante pulsa Play: antes no se contacta a YouTube.
const reproduciendo = ref(false);
const imagenRota = ref(false);

watch(
  () => props.embedUrl,
  () => {
    reproduciendo.value = false;
    imagenRota.value = false;
  }
);

const numeroFormateado = computed(() => String(props.numero).padStart(2, "0"));

const srcReproductor = computed(() =>
  props.embedUrl ? `${props.embedUrl}${props.embedUrl.includes("?") ? "&" : "?"}autoplay=1` : null
);

const reproducir = () => {
  if (props.embedUrl) reproduciendo.value = true;
};
</script>

<template>
  <div
    class="relative aspect-video rounded-[2rem] md:rounded-[2.5rem] overflow-hidden bg-podcast-oscuro shadow-xl shadow-slate-300/40 ring-1 ring-slate-200/60"
  >
    <!-- Reproductor: solo existe tras pulsar Play -->
    <iframe
      v-if="reproduciendo && srcReproductor"
      :src="srcReproductor"
      :title="`Reproductor: ${titulo}`"
      class="absolute inset-0 w-full h-full"
      frameborder="0"
      allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
      referrerpolicy="strict-origin-when-cross-origin"
      allowfullscreen
    ></iframe>

    <template v-else>
      <img
        v-if="miniatura && !imagenRota"
        :src="miniatura"
        :alt="titulo"
        class="absolute inset-0 w-full h-full object-cover"
        @error="imagenRota = true"
      />
      <template v-else>
        <div
          class="absolute inset-0 bg-gradient-to-br from-podcast-oscuro via-[#34424a] to-slate-900"
        ></div>
        <div
          class="absolute inset-0 opacity-[0.08]"
          style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 28px 28px"
        ></div>
        <span
          aria-hidden="true"
          class="absolute -bottom-10 -right-4 text-[12rem] lg:text-[20rem] leading-none font-black text-white/[0.06] select-none"
          >{{ numeroFormateado }}</span
        >
      </template>

      <!-- Con video válido: botón Play. Sin video: estado seguro con salida al canal. -->
      <button
        v-if="embedUrl"
        type="button"
        :aria-label="`Reproducir episodio ${numeroFormateado}: ${titulo}`"
        class="group absolute inset-0 flex items-center justify-center w-full h-full bg-black/10 hover:bg-black/20 transition-colors focus:outline-none focus-visible:ring-4 focus-visible:ring-inset focus-visible:ring-podcast-acento/60"
        @click="reproducir"
      >
        <span
          class="w-20 h-20 sm:w-24 sm:h-24 rounded-full bg-podcast-acento text-podcast-oscuro flex items-center justify-center shadow-2xl shadow-black/30 ring-8 ring-white/10 transition-transform duration-500 group-hover:scale-110"
        >
          <span class="material-symbols-rounded text-5xl sm:text-6xl translate-x-[2px]"
            >play_arrow</span
          >
        </span>
      </button>

      <div
        v-else
        class="absolute inset-0 flex flex-col items-center justify-center gap-3 px-6 text-center bg-black/30"
      >
        <span class="material-symbols-rounded text-5xl text-white/70">videocam_off</span>
        <p class="text-white font-bold text-base sm:text-lg">Video no disponible por ahora</p>
        <p class="text-slate-300 text-sm max-w-sm">
          Estamos actualizando este episodio. Mientras tanto, encuentra todas las conversaciones
          en el canal de YouTube.
        </p>
        <a
          :href="canalUrl"
          target="_blank"
          rel="noopener noreferrer"
          class="mt-2 inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-white/10 border border-white/25 text-white text-sm font-bold hover:bg-white/20 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">smart_display</span>
          Ir al canal
        </a>
      </div>

      <span
        v-if="duracion && embedUrl"
        class="absolute bottom-4 right-4 px-2.5 py-1 rounded-lg bg-black/50 backdrop-blur-md text-white text-xs font-bold tabular-nums pointer-events-none"
      >
        {{ duracion }}
      </span>
    </template>
  </div>
</template>
