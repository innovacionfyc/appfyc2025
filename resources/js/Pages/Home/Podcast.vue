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

// Datos reales desde PodcastPublicController: solo temporadas y episodios activos y no eliminados.
// `episodios` llega ordenado del más reciente al más antiguo.
const props = defineProps({
  temporadas: { type: Array, default: () => [] },
  episodios: { type: Array, default: () => [] },
  destacadoId: { type: Number, default: null },
  temporadaInicial: { type: Number, default: null },
  canalUrl: { type: String, default: "https://www.youtube.com/FYCConsultores" },
});

const hayContenido = computed(() => props.episodios.length > 0);

const temporadaSeleccionada = ref(props.temporadaInicial ?? props.temporadas[0]?.numero ?? null);

const temporadaActual = computed(
  () => props.temporadas.find((t) => t.numero === temporadaSeleccionada.value) ?? null
);

// Destacado elegible; si no existe, el backend ya dejó el más reciente al inicio.
const episodioDestacado = computed(
  () => props.episodios.find((e) => e.id === props.destacadoId) ?? props.episodios[0] ?? null
);

const ultimosEpisodios = computed(() =>
  props.episodios.filter((e) => e.id !== episodioDestacado.value?.id).slice(0, 3)
);

const episodiosTemporada = computed(() =>
  props.episodios
    .filter((e) => e.temporada === temporadaSeleccionada.value)
    .sort((a, b) => b.numero - a.numero)
);

const formatoCorto = (fecha) =>
  fecha
    ? new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "short" }).format(
        new Date(fecha + "T12:00:00")
      )
    : "Próximamente";
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
              :temporada-actual="temporadaActual"
              :total-episodios="episodios.length"
              :canal-url="canalUrl"
            />
          </RevealSection>

          <!-- Estado vacío: sin temporadas o episodios elegibles -->
          <RevealSection v-if="!hayContenido" id="episodio-destacado" class="scroll-mt-32">
            <section
              class="rounded-[2rem] md:rounded-[2.5rem] border border-dashed border-slate-300 bg-white/70 px-8 py-16 sm:py-20 text-center"
            >
              <span
                class="material-symbols-rounded text-5xl text-podcast-oscuro/40 mb-4 inline-block"
                >mic</span
              >
              <h2 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight mb-3">
                Primer episodio en producción
              </h2>
              <p class="text-base text-slate-500 leading-relaxed max-w-md mx-auto mb-8">
                Estamos grabando las primeras conversaciones de Íntimamente Hablando. Muy pronto
                encontrarás aquí los episodios; mientras tanto, síguenos en el canal de YouTube.
              </p>
              <a :href="canalUrl" target="_blank" rel="noopener noreferrer" class="inline-block">
                <BtnUniversal
                  label="Ir al canal de YouTube"
                  icon="smart_display"
                  icon-position="right"
                  size="md"
                  activeColor="#3f4e54"
                  class="w-auto"
                />
              </a>
            </section>
          </RevealSection>

          <!-- B + C. Episodio destacado y últimos episodios -->
          <RevealSection v-if="hayContenido" id="episodio-destacado" class="scroll-mt-32">
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
                    :href="canalUrl"
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
          <RevealSection v-if="hayContenido && temporadaActual" :repeat="true">
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
                  :href="canalUrl"
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
