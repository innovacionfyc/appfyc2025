<script setup>
import { computed, ref, watch } from "vue";
import BtnSecundario from "@/Components/Shared/buttons/btnSecundario.vue";

const props = defineProps({
  episodio: { type: Object, required: true },
});

const imagenRota = ref(false);
watch(() => props.episodio.miniatura, () => (imagenRota.value = false));

const numeroFormateado = computed(() => String(props.episodio.numero).padStart(2, "0"));

const iniciales = computed(() =>
  props.episodio.invitado
    .split(" ")
    .slice(0, 2)
    .map((p) => p[0])
    .join("")
    .toUpperCase()
);

const fecha = computed(() =>
  props.episodio.fecha
    ? new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "long", year: "numeric" }).format(
        new Date(props.episodio.fecha + "T12:00:00")
      )
    : null
);
</script>

<template>
  <article
    class="group relative h-full flex flex-col bg-white rounded-[2rem] md:rounded-[2.5rem] border border-slate-200/60 shadow-xl shadow-slate-200/40 overflow-hidden"
  >
    <!-- Miniatura 16:9. En Fase 3 se sustituye por la miniatura de YouTube y luego el reproductor. -->
    <div class="relative aspect-video bg-podcast-oscuro overflow-hidden">
      <img
        v-if="episodio.miniatura && !imagenRota"
        :src="episodio.miniatura"
        :alt="episodio.titulo"
        class="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.03]"
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
          class="absolute -bottom-10 -right-4 text-[12rem] lg:text-[18rem] leading-none font-black text-white/[0.06] select-none"
          >{{ numeroFormateado }}</span
        >
      </template>

      <div class="absolute inset-0 flex items-center justify-center">
        <span
          class="w-20 h-20 rounded-full bg-podcast-acento text-podcast-oscuro flex items-center justify-center shadow-2xl shadow-black/30 ring-8 ring-white/10 transition-transform duration-500 group-hover:scale-110"
        >
          <span class="material-symbols-rounded text-5xl translate-x-[2px]">play_arrow</span>
        </span>
      </div>

      <span
        class="absolute top-4 left-4 inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-black/40 backdrop-blur-md border border-white/10 text-white text-[10px] font-black uppercase tracking-widest"
      >
        <span class="w-1.5 h-1.5 rounded-full bg-podcast-acento"></span>
        Destacado
      </span>

      <span
        v-if="episodio.duracion"
        class="absolute bottom-4 right-4 px-2.5 py-1 rounded-lg bg-black/50 backdrop-blur-md text-white text-xs font-bold tabular-nums"
      >
        {{ episodio.duracion }}
      </span>
    </div>

    <div class="flex flex-col flex-1 p-7 sm:p-8 lg:p-10">
      <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        <div class="lg:col-span-8">
          <p class="text-[11px] font-black uppercase tracking-widest text-slate-400 mb-3">
            Temporada {{ episodio.temporada }}
            <span class="mx-1.5 text-slate-300">·</span>
            Episodio {{ numeroFormateado }}
            <span class="mx-1.5 text-slate-300">·</span>
            <time v-if="fecha" :datetime="episodio.fecha">{{ fecha }}</time>
            <span v-else>Próximamente</span>
          </p>

          <h2
            class="text-2xl sm:text-3xl lg:text-4xl font-black text-slate-900 leading-[1.1] tracking-tight mb-4"
          >
            {{ episodio.titulo }}
          </h2>

          <p class="text-[15px] lg:text-base text-slate-600 leading-relaxed line-clamp-3">
            {{ episodio.descripcion }}
          </p>
        </div>

        <div
          class="lg:col-span-4 flex flex-row lg:flex-col items-center lg:items-stretch justify-between lg:justify-end gap-5 mt-6 lg:mt-0 lg:border-l lg:border-slate-100 lg:pl-8"
        >
          <div class="flex items-center gap-3 min-w-0">
            <span
              class="w-11 h-11 rounded-full bg-podcast-oscuro text-white text-sm font-black flex items-center justify-center shrink-0"
            >
              {{ iniciales }}
            </span>
            <div class="min-w-0">
              <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                Invitado
              </p>
              <p class="text-sm font-bold text-slate-900 leading-tight truncate">
                {{ episodio.invitado }}
              </p>
              <p v-if="episodio.cargo" class="text-xs text-slate-500 truncate">
                {{ episodio.cargo }}
              </p>
            </div>
          </div>

          <BtnSecundario
            :href="episodio.url"
            label="Ver episodio"
            icon="arrow_forward"
            icon-position="right"
            size="md"
            activeColor="#3f4e54"
            class="shrink-0"
          />
        </div>
      </div>
    </div>
  </article>
</template>
