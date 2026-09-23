<script setup>
import { computed, ref } from "vue";
import { Head } from "@inertiajs/vue3";
import GuestLayout from "@/Layouts/GuestLayout.vue";
import HeaderNav from "@/Components/HeaderNav.vue";
import SiteFooter from "@/Components/SiteFooter.vue";
import RevealSection from "@/Components/RevealSection.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";
import PodcastHero from "@/Components/Podcast/PodcastHero.vue";
import PodcastFeaturedEpisode from "@/Components/Podcast/PodcastFeaturedEpisode.vue";
import PodcastEpisodeCard from "@/Components/Podcast/PodcastEpisodeCard.vue";
import PodcastSeasonSelector from "@/Components/Podcast/PodcastSeasonSelector.vue";

// ------------------------------------------------------------------
// DATOS MOCK — Fase 0 (prototipo visual). Se reemplazan por props del
// controlador en Fase 3. Los invitados de los episodios 02 a 04 son
// ficticios y existen solo para probar el diseño.
// ------------------------------------------------------------------
const CANAL_YOUTUBE = "https://www.youtube.com/FYCConsultores";

const temporadas = [
  {
    numero: 1,
    titulo: "Quienes construyen lo público",
    descripcion:
      "Una primera temporada dedicada a las trayectorias que han dado forma a la administración pública colombiana: presupuesto, contratación, control y talento humano.",
    disponible: true,
  },
  { numero: 2, titulo: "Próximamente", descripcion: "", disponible: false },
];

const episodios = [
  {
    id: 1,
    temporada: 1,
    numero: 1,
    slug: "t1-e01-tres-decadas-presupuesto-publico",
    invitado: "Ezequiel Lenis Ramírez",
    cargo: null,
    titulo: "Tres décadas moldeando el presupuesto público de Colombia",
    descripcion:
      "Una conversación sobre cómo se construye, se negocia y se defiende el presupuesto de la Nación, y sobre las decisiones que dejan huella mucho después de aprobarse.",
    fecha: "2026-08-14",
    duracion: "47 min",
    miniatura: null,
    destacado: true,
  },
  {
    id: 2,
    temporada: 1,
    numero: 2,
    slug: "t1-e02-contratacion-estatal",
    invitado: "Ana Lucía Gómez",
    cargo: "Consultora en contratación pública",
    titulo: "Contratación estatal: lo que la norma no explica",
    descripcion:
      "De los pliegos a la ejecución: los criterios prácticos que separan un proceso bien estructurado de uno que termina en controversia.",
    fecha: "2026-08-28",
    duracion: "39 min",
    miniatura: null,
    destacado: false,
  },
  {
    id: 3,
    temporada: 1,
    numero: 3,
    slug: "t1-e03-control-fiscal",
    invitado: "Julián Restrepo",
    cargo: "Auditor y docente universitario",
    titulo: "Control fiscal: prevenir antes que sancionar",
    descripcion:
      "Cómo entender el control fiscal como una herramienta de gestión y no solo como una amenaza, desde la mirada de quien lo ejerce y lo enseña.",
    fecha: "2026-09-11",
    duracion: "52 min",
    miniatura: null,
    destacado: false,
  },
  {
    id: 4,
    temporada: 1,
    numero: 4,
    slug: "t1-e04-talento-humano-estado",
    invitado: "María Fernanda Ortiz",
    cargo: "Directora de talento humano, sector público",
    titulo: "Liderar equipos en el Estado sin perder la vocación",
    descripcion:
      "Rotación, mérito y motivación: una charla franca sobre lo que significa dirigir personas dentro de una entidad pública hoy.",
    fecha: "2026-09-18",
    duracion: "44 min",
    miniatura: null,
    destacado: false,
  },
];

const temporadaSeleccionada = ref(1);

const temporadaActual = computed(() =>
  temporadas.find((t) => t.numero === temporadaSeleccionada.value)
);

const episodioDestacado = computed(
  () => episodios.find((e) => e.destacado) ?? episodiosOrdenados.value[0]
);

const episodiosOrdenados = computed(() => [...episodios].sort((a, b) => b.numero - a.numero));

const ultimosEpisodios = computed(() =>
  episodiosOrdenados.value.filter((e) => e.id !== episodioDestacado.value.id).slice(0, 3)
);

const episodiosTemporada = computed(() =>
  episodiosOrdenados.value.filter((e) => e.temporada === temporadaSeleccionada.value)
);

const formatoCorto = (fecha) =>
  new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "short" }).format(
    new Date(fecha + "T12:00:00")
  );
</script>

<template>
  <Head title="Podcast Íntimamente Hablando" />
  <GuestLayout>
    <!-- overflow-x-clip: RevealSection ensancha las secciones ocultas (rotateX) sin crear scroll container -->
    <div class="min-h-screen bg-slate-50 flex flex-col overflow-x-clip">
      <HeaderNav />

      <main class="flex-grow pt-28 md:pt-36 pb-12">
        <div class="max-w-7xl mx-auto px-6 lg:px-8 space-y-20 lg:space-y-28">
          <!-- A. Hero -->
          <RevealSection>
            <PodcastHero
              :temporada-actual="temporadas[0]"
              :total-episodios="episodios.length"
              :canal-url="CANAL_YOUTUBE"
            />
          </RevealSection>

          <!-- B + C. Episodio destacado y últimos episodios -->
          <RevealSection id="episodio-destacado" class="scroll-mt-32">
            <section>
              <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 lg:gap-10">
                <div class="lg:col-span-2">
                  <PodcastFeaturedEpisode :episodio="episodioDestacado" />
                </div>

                <aside class="flex flex-col">
                  <h2
                    class="text-[11px] font-black uppercase tracking-widest text-slate-400 mb-5 flex items-center gap-2.5"
                  >
                    <span class="w-2 h-2 rounded-full bg-podcast-acento"></span>
                    Últimos episodios
                  </h2>

                  <!-- Los ítems se reparten a lo alto para acompañar la altura del destacado -->
                  <ul class="flex-1 flex flex-col divide-y divide-slate-200/70">
                    <li
                      v-for="episodio in ultimosEpisodios"
                      :key="episodio.id"
                      class="flex-1 flex items-center"
                    >
                      <!-- Fase 3: enlazar al detalle -->
                      <a
                        href="#"
                        @click.prevent
                        class="group flex items-center gap-4 w-full py-4 rounded-xl focus:outline-none focus-visible:ring-4 focus-visible:ring-podcast-oscuro/20"
                      >
                        <div
                          class="relative w-28 h-[4.5rem] shrink-0 rounded-xl overflow-hidden bg-podcast-oscuro"
                        >
                          <span
                            aria-hidden="true"
                            class="absolute -bottom-2 -right-1 text-4xl font-black text-white/10 select-none leading-none"
                            >{{ String(episodio.numero).padStart(2, "0") }}</span
                          >
                          <span
                            class="absolute inset-0 flex items-center justify-center text-podcast-acento opacity-90 group-hover:opacity-100 transition-opacity"
                          >
                            <span class="material-symbols-rounded text-2xl">play_arrow</span>
                          </span>
                        </div>
                        <div class="min-w-0 flex-1">
                          <p
                            class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1"
                          >
                            EP {{ String(episodio.numero).padStart(2, "0") }} ·
                            {{ formatoCorto(episodio.fecha) }}
                          </p>
                          <h3
                            class="text-sm font-bold text-slate-900 leading-snug line-clamp-2 group-hover:text-podcast-oscuro transition-colors"
                          >
                            {{ episodio.titulo }}
                          </h3>
                          <p class="text-xs text-slate-500 mt-1 truncate">
                            {{ episodio.invitado }}
                          </p>
                        </div>
                      </a>
                    </li>
                  </ul>

                  <a
                    :href="CANAL_YOUTUBE"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-auto pt-5 inline-flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-podcast-oscuro transition-colors group"
                  >
                    Ver todos en YouTube
                    <span
                      class="material-symbols-rounded text-lg transition-transform group-hover:translate-x-0.5"
                      >arrow_forward</span
                    >
                  </a>
                </aside>
              </div>
            </section>
          </RevealSection>

          <!-- D + E. Temporadas y grid de episodios -->
          <RevealSection :repeat="true">
            <section>
              <div
                class="flex flex-col xl:flex-row xl:items-end justify-between gap-6 mb-10"
              >
                <div class="max-w-2xl">
                  <span
                    class="inline-flex items-center gap-2.5 text-[11px] sm:text-xs font-bold uppercase tracking-widest text-slate-400 mb-4"
                  >
                    <span class="w-2 h-2 rounded-full bg-podcast-acento"></span>
                    Temporadas
                  </span>
                  <h2
                    class="text-3xl sm:text-4xl lg:text-5xl font-black text-slate-900 leading-[1.08] tracking-tight mb-4"
                  >
                    Temporada {{ temporadaActual.numero }}
                    <span class="text-podcast-oscuro">· {{ temporadaActual.titulo }}</span>
                  </h2>
                  <p class="text-base lg:text-lg text-slate-600 leading-relaxed">
                    {{ temporadaActual.descripcion }}
                  </p>
                </div>

                <PodcastSeasonSelector
                  v-model="temporadaSeleccionada"
                  :temporadas="temporadas"
                />
              </div>

              <div
                v-if="episodiosTemporada.length"
                class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8"
              >
                <PodcastEpisodeCard
                  v-for="episodio in episodiosTemporada"
                  :key="episodio.id"
                  :episodio="episodio"
                />
              </div>

              <div
                v-else
                class="rounded-[2rem] border border-dashed border-slate-300 bg-white/60 px-8 py-16 text-center"
              >
                <span
                  class="material-symbols-rounded text-4xl text-slate-300 mb-3 inline-block"
                  >mic</span
                >
                <p class="text-lg font-bold text-slate-700">Temporada en producción</p>
                <p class="text-sm text-slate-500 mt-1">
                  Los episodios aparecerán aquí a medida que se publiquen.
                </p>
              </div>
            </section>
          </RevealSection>

          <!-- F. CTA YouTube -->
          <RevealSection>
            <section
              class="relative overflow-hidden bg-podcast-oscuro rounded-[2rem] md:rounded-[2.5rem] px-6 py-10 sm:px-10 sm:py-12 lg:px-16 lg:py-14"
            >
              <div
                class="absolute inset-0 opacity-[0.06] pointer-events-none"
                style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 28px 28px"
              ></div>
              <div
                class="absolute -top-24 -right-24 w-72 h-72 rounded-full bg-podcast-acento/10 blur-3xl pointer-events-none"
              ></div>

              <div
                class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-8"
              >
                <div class="max-w-2xl">
                  <h2
                    class="text-2xl sm:text-3xl lg:text-4xl font-black text-white leading-tight tracking-tight mb-3"
                  >
                    Todos los episodios, también en YouTube.
                  </h2>
                  <p class="text-slate-300 text-base lg:text-lg leading-relaxed">
                    Suscríbete al canal de F&amp;C Consultores para recibir cada nuevo
                    episodio y ver las conversaciones completas.
                  </p>
                </div>
                <a
                  :href="CANAL_YOUTUBE"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="w-full sm:w-auto shrink-0"
                >
                  <BtnUniversal
                    label="Ir al canal"
                    icon="smart_display"
                    variant="white"
                    size="lg"
                    class="sm:w-auto"
                  />
                </a>
              </div>
            </section>
          </RevealSection>
        </div>
      </main>

      <RevealSection>
        <SiteFooter />
      </RevealSection>
    </div>
  </GuestLayout>
</template>
