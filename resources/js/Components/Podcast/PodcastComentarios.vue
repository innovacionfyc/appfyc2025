<script setup>
import { computed, ref } from "vue";
import axios from "axios";
import PodcastComentarioForm from "@/Components/Podcast/PodcastComentarioForm.vue";

const props = defineProps({
  // Primera página calculada en el servidor: { total, pagina, hay_mas, comentarios[] }
  inicial: { type: Object, required: true },
  listarUrl: { type: String, required: true },
  enviarUrl: { type: String, required: true },
});

const comentarios = ref([...props.inicial.comentarios]);
const total = ref(props.inicial.total);
const pagina = ref(props.inicial.pagina);
const hayMas = ref(props.inicial.hay_mas);
const cargando = ref(false);
const errorCarga = ref("");

const titulo = computed(() =>
  total.value === 0 ? "Comentarios" : total.value === 1 ? "1 comentario" : `${total.value} comentarios`
);

const formatoFecha = (iso) =>
  iso
    ? new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "long", year: "numeric" }).format(new Date(iso))
    : "";

const iniciales = (nombre) =>
  (nombre || "")
    .split(" ")
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0])
    .join("")
    .toUpperCase();

const cargarMas = async () => {
  if (cargando.value || !hayMas.value) return;

  cargando.value = true;
  errorCarga.value = "";

  try {
    const { data } = await axios.get(props.listarUrl, { params: { pagina: pagina.value + 1 } });
    // Evita repetidos si se aprobó un comentario entre una página y otra
    const conocidos = new Set(comentarios.value.map((c) => c.id));
    comentarios.value.push(...data.comentarios.filter((c) => !conocidos.has(c.id)));
    total.value = data.total;
    pagina.value = data.pagina;
    hayMas.value = data.hay_mas;
  } catch {
    errorCarga.value = "No pudimos cargar más comentarios. Inténtalo de nuevo.";
  } finally {
    cargando.value = false;
  }
};
</script>

<template>
  <section id="comentarios" class="scroll-mt-32" aria-labelledby="comentarios-titulo">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14">
      <!-- Lista -->
      <div class="lg:col-span-7">
        <h2
          id="comentarios-titulo"
          class="text-[11px] font-black uppercase tracking-widest text-slate-400 mb-6 flex items-center gap-2.5"
        >
          <span class="w-2 h-2 rounded-full bg-podcast-acento"></span>
          {{ titulo }}
        </h2>

        <ul v-if="comentarios.length" class="space-y-4">
          <li
            v-for="comentario in comentarios"
            :key="comentario.id"
            class="bg-white rounded-[1.5rem] border border-slate-200/60 shadow-sm p-5 sm:p-6"
          >
            <div class="flex items-center gap-3 mb-3">
              <span
                class="w-10 h-10 rounded-full bg-podcast-oscuro text-white text-xs font-black flex items-center justify-center shrink-0"
                aria-hidden="true"
                >{{ iniciales(comentario.nombre) }}</span
              >
              <div class="min-w-0">
                <p class="text-sm font-bold text-slate-900 leading-tight truncate">{{ comentario.nombre }}</p>
                <time :datetime="comentario.fecha" class="text-xs text-slate-500">{{
                  formatoFecha(comentario.fecha)
                }}</time>
              </div>
            </div>
            <!-- Interpolación de texto: el contenido se escapa; los saltos de línea se respetan -->
            <p class="text-sm sm:text-[15px] text-slate-700 leading-relaxed whitespace-pre-line break-words">
              {{ comentario.contenido }}
            </p>
          </li>
        </ul>

        <div
          v-else
          class="rounded-[1.5rem] border border-dashed border-slate-300 bg-white/60 px-6 py-10 text-center"
        >
          <span class="material-symbols-rounded text-4xl text-slate-300 mb-2 inline-block" aria-hidden="true"
            >forum</span
          >
          <p class="text-base font-bold text-slate-700">Aún no hay comentarios</p>
          <p class="text-sm text-slate-500 mt-1">Sé la primera persona en compartir lo que te dejó este episodio.</p>
        </div>

        <div v-if="hayMas || errorCarga" class="mt-6 flex flex-col items-start gap-2">
          <button
            v-if="hayMas"
            type="button"
            :disabled="cargando"
            :aria-busy="cargando"
            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full text-sm font-bold border border-slate-200 bg-white text-slate-700 hover:border-podcast-oscuro/40 hover:text-podcast-oscuro transition-colors focus:outline-none focus-visible:ring-4 focus-visible:ring-podcast-oscuro/20 disabled:cursor-wait disabled:opacity-70"
            @click="cargarMas"
          >
            <span class="material-symbols-rounded text-lg" aria-hidden="true">expand_more</span>
            {{ cargando ? "Cargando…" : "Ver más comentarios" }}
          </button>
          <p v-if="errorCarga" role="alert" class="text-xs font-semibold text-rose-600">{{ errorCarga }}</p>
        </div>
      </div>

      <!-- Formulario -->
      <div class="lg:col-span-5">
        <div class="bg-slate-100/70 rounded-[2rem] border border-slate-200/60 p-6 sm:p-7 lg:sticky lg:top-32">
          <h3 class="text-lg font-black text-slate-900 tracking-tight mb-1">Deja tu comentario</h3>
          <p class="text-sm text-slate-500 mb-6">
            Cuéntanos qué te pareció la conversación. Lo leemos todo antes de publicarlo.
          </p>
          <PodcastComentarioForm :url="enviarUrl" />
        </div>
      </div>
    </div>
  </section>
</template>
