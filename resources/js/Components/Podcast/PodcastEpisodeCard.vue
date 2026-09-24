<script setup>
import { computed, ref, watch } from "vue";

const props = defineProps({
  episodio: { type: Object, required: true },
});

// Si la miniatura (p. ej. la de YouTube) no carga, se muestra el placeholder
const imagenRota = ref(false);
watch(() => props.episodio.miniatura, () => (imagenRota.value = false));

const numeroFormateado = computed(() => String(props.episodio.numero).padStart(2, "0"));

const fecha = computed(() =>
  props.episodio.fecha
    ? new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "short", year: "numeric" }).format(
        new Date(props.episodio.fecha + "T12:00:00")
      )
    : null
);
</script>

<template>
  <article
    class="group relative h-full flex flex-col bg-white rounded-[2rem] border border-slate-200/60 overflow-hidden shadow-sm hover:shadow-xl hover:shadow-slate-300/40 hover:-translate-y-1.5 transition-all duration-500"
  >
    <!-- Miniatura 16:9. Cuando exista video_id se reemplaza por la miniatura de YouTube. -->
    <div class="relative aspect-video overflow-hidden bg-podcast-oscuro">
      <img
        v-if="episodio.miniatura && !imagenRota"
        :src="episodio.miniatura"
        :alt="episodio.titulo"
        loading="lazy"
        class="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover:scale-105"
        @error="imagenRota = true"
      />
      <template v-else>
        <div
          class="absolute inset-0 bg-gradient-to-br from-podcast-oscuro via-podcast-oscuro to-slate-900"
        ></div>
        <div
          class="absolute inset-0 opacity-[0.08]"
          style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 24px 24px"
        ></div>
        <span
          aria-hidden="true"
          class="absolute -bottom-6 -right-2 text-[7rem] leading-none font-black text-white/[0.07] select-none"
          >{{ numeroFormateado }}</span
        >
      </template>

      <div
        class="absolute inset-0 flex items-center justify-center"
      >
        <span
          class="w-14 h-14 rounded-full bg-podcast-acento text-podcast-oscuro flex items-center justify-center shadow-lg shadow-black/20 transition-transform duration-500 group-hover:scale-110"
        >
          <span class="material-symbols-rounded text-3xl translate-x-[1px]">play_arrow</span>
        </span>
      </div>

      <span
        v-if="episodio.duracion"
        class="absolute bottom-3 right-3 px-2 py-1 rounded-lg bg-black/50 backdrop-blur-md text-white text-[11px] font-bold tabular-nums"
      >
        {{ episodio.duracion }}
      </span>
    </div>

    <div class="flex flex-col flex-1 p-6 lg:p-7">
      <p class="text-[11px] font-black uppercase tracking-widest text-slate-400 mb-3">
        Episodio {{ numeroFormateado }}
        <span class="mx-1.5 text-slate-300">·</span>
        <time v-if="fecha" :datetime="episodio.fecha">{{ fecha }}</time>
        <span v-else>Próximamente</span>
      </p>

      <h3 class="text-lg lg:text-xl font-bold text-slate-900 leading-snug line-clamp-2 mb-2">
        {{ episodio.titulo }}
      </h3>

      <p class="text-sm text-slate-500 mb-6">
        con <span class="font-semibold text-slate-700">{{ episodio.invitado }}</span>
      </p>

      <div
        class="h-1 w-10 rounded-full bg-slate-200 mt-auto transition-all duration-500 group-hover:w-full group-hover:bg-podcast-acento"
      ></div>
    </div>

    <!-- Fase 3: enlazar al detalle /podcast/{slug} -->
    <a
      href="#"
      @click.prevent
      :aria-label="`Ver episodio ${numeroFormateado}: ${episodio.titulo}`"
      class="absolute inset-0 z-20 rounded-[2rem] focus:outline-none focus-visible:ring-4 focus-visible:ring-podcast-oscuro/20"
    ></a>
  </article>
</template>
