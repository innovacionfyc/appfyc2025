<script setup>
import { computed } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import GuestLayout from "@/Layouts/GuestLayout.vue";
import HeaderNav from "@/Components/HeaderNav.vue";
import SiteFooter from "@/Components/SiteFooter.vue";
import RevealSection from "@/Components/RevealSection.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";
import PodcastPlayer from "@/Components/Podcast/PodcastPlayer.vue";
import PodcastEpisodeCard from "@/Components/Podcast/PodcastEpisodeCard.vue";

// Datos reales desde PodcastPublicController@show: el episodio ya viene validado como elegible.
const props = defineProps({
  episodio: { type: Object, required: true },
  relacionados: { type: Array, default: () => [] },
  listadoUrl: { type: String, default: "/podcast" },
  canalUrl: { type: String, default: "https://www.youtube.com/FYCConsultores" },
});

const numeroFormateado = computed(() => String(props.episodio.numero).padStart(2, "0"));

const fecha = computed(() =>
  props.episodio.fecha
    ? new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "long", year: "numeric" }).format(
        new Date(props.episodio.fecha + "T12:00:00")
      )
    : null
);

const iniciales = computed(() =>
  (props.episodio.invitado || "")
    .split(" ")
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0])
    .join("")
    .toUpperCase()
);

const ficha = computed(() =>
  [
    { etiqueta: "Temporada", valor: `Temporada ${props.episodio.temporada}` },
    { etiqueta: "Episodio", valor: `EP ${numeroFormateado.value}` },
    fecha.value ? { etiqueta: "Publicado", valor: fecha.value } : null,
    props.episodio.duracion ? { etiqueta: "Duración", valor: props.episodio.duracion } : null,
  ].filter(Boolean)
);
</script>

<template>
  <Head :title="`${episodio.titulo} · Íntimamente Hablando`" />
  <GuestLayout>
    <div class="min-h-screen bg-slate-50 flex flex-col overflow-x-clip">
      <HeaderNav />

      <main class="flex-grow pt-28 md:pt-36 pb-12">
        <div class="max-w-7xl mx-auto px-6 lg:px-8 space-y-16 lg:space-y-24">
          <!-- A. Cabecera + reproductor -->
          <RevealSection>
            <section>
              <Link
                :href="listadoUrl"
                class="group inline-flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-podcast-oscuro transition-colors mb-8 rounded-lg focus:outline-none focus-visible:ring-4 focus-visible:ring-podcast-oscuro/20"
              >
                <span
                  class="material-symbols-rounded text-xl transition-transform group-hover:-translate-x-0.5"
                  >arrow_back</span
                >
                Volver al podcast
              </Link>

              <div class="max-w-4xl mb-8 lg:mb-10">
                <p
                  class="inline-flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] sm:text-xs font-black uppercase tracking-widest text-slate-400 mb-4"
                >
                  <span class="inline-flex items-center gap-2.5">
                    <span class="w-2 h-2 rounded-full bg-podcast-acento"></span>
                    Íntimamente Hablando
                  </span>
                  <span class="text-slate-300">·</span>
                  <span>Temporada {{ episodio.temporada }}</span>
                  <span class="text-slate-300">·</span>
                  <span>Episodio {{ numeroFormateado }}</span>
                  <template v-if="fecha">
                    <span class="text-slate-300">·</span>
                    <time :datetime="episodio.fecha">{{ fecha }}</time>
                  </template>
                </p>

                <h1
                  class="text-3xl sm:text-4xl lg:text-5xl font-black text-slate-900 leading-[1.08] tracking-tight"
                >
                  {{ episodio.titulo }}
                </h1>

                <p class="mt-4 text-base sm:text-lg text-slate-600">
                  con
                  <span class="font-bold text-slate-900">{{ episodio.invitado }}</span>
                  <template v-if="episodio.cargo">
                    <span class="mx-1.5 text-slate-300">·</span>
                    <span class="text-slate-500">{{ episodio.cargo }}</span>
                  </template>
                </p>
              </div>

              <PodcastPlayer
                :embed-url="episodio.embed_url"
                :miniatura="episodio.miniatura"
                :titulo="episodio.titulo"
                :numero="episodio.numero"
                :duracion="episodio.duracion"
                :canal-url="canalUrl"
              />
            </section>
          </RevealSection>

          <!-- B. Descripción + ficha del invitado -->
          <RevealSection>
            <section class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14">
              <div class="lg:col-span-8">
                <h2
                  class="text-[11px] font-black uppercase tracking-widest text-slate-400 mb-5 flex items-center gap-2.5"
                >
                  <span class="w-2 h-2 rounded-full bg-podcast-acento"></span>
                  Sobre este episodio
                </h2>
                <div class="text-base lg:text-lg text-slate-600 leading-relaxed whitespace-pre-line">
                  {{ episodio.descripcion }}
                </div>
              </div>

              <aside class="lg:col-span-4 space-y-6">
                <!-- Invitado -->
                <div class="bg-white rounded-[2rem] border border-slate-200/60 shadow-sm p-6 lg:p-7">
                  <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-4">
                    Invitado
                  </p>
                  <div class="flex items-center gap-4">
                    <img
                      v-if="episodio.invitado_foto"
                      :src="episodio.invitado_foto"
                      :alt="episodio.invitado"
                      class="w-16 h-16 rounded-full object-cover shrink-0 ring-4 ring-slate-100"
                    />
                    <span
                      v-else
                      class="w-16 h-16 rounded-full bg-podcast-oscuro text-white text-lg font-black flex items-center justify-center shrink-0"
                    >
                      {{ iniciales }}
                    </span>
                    <div class="min-w-0">
                      <p class="text-base font-bold text-slate-900 leading-tight">
                        {{ episodio.invitado }}
                      </p>
                      <p v-if="episodio.cargo" class="text-sm text-slate-500 mt-1 leading-snug">
                        {{ episodio.cargo }}
                      </p>
                    </div>
                  </div>
                </div>

                <!-- Ficha -->
                <dl
                  class="grid grid-cols-2 gap-px bg-slate-200/70 rounded-[2rem] overflow-hidden border border-slate-200/60"
                >
                  <div v-for="item in ficha" :key="item.etiqueta" class="bg-white px-5 py-4">
                    <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                      {{ item.etiqueta }}
                    </dt>
                    <dd class="mt-1 text-sm font-bold text-slate-900 tabular-nums">
                      {{ item.valor }}
                    </dd>
                  </div>
                </dl>

                <a
                  v-if="episodio.youtube_watch_url"
                  :href="episodio.youtube_watch_url"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="inline-flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-podcast-oscuro transition-colors group"
                >
                  <span class="material-symbols-rounded text-lg">smart_display</span>
                  Ver en YouTube
                  <span
                    class="material-symbols-rounded text-lg transition-transform group-hover:translate-x-0.5"
                    >arrow_forward</span
                  >
                </a>
              </aside>
            </section>
          </RevealSection>

          <!-- C. Episodios relacionados -->
          <RevealSection v-if="relacionados.length">
            <section>
              <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-8">
                <div>
                  <span
                    class="inline-flex items-center gap-2.5 text-[11px] sm:text-xs font-bold uppercase tracking-widest text-slate-400 mb-3"
                  >
                    <span class="w-2 h-2 rounded-full bg-podcast-acento"></span>
                    Sigue escuchando
                  </span>
                  <h2
                    class="text-2xl sm:text-3xl lg:text-4xl font-black text-slate-900 leading-tight tracking-tight"
                  >
                    Más episodios
                  </h2>
                </div>
                <Link
                  :href="listadoUrl"
                  class="inline-flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-podcast-oscuro transition-colors group"
                >
                  Ver todos los episodios
                  <span
                    class="material-symbols-rounded text-lg transition-transform group-hover:translate-x-0.5"
                    >arrow_forward</span
                  >
                </Link>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8">
                <PodcastEpisodeCard
                  v-for="relacionado in relacionados"
                  :key="relacionado.id"
                  :episodio="relacionado"
                />
              </div>
            </section>
          </RevealSection>

          <!-- D. CTA YouTube -->
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
                    Suscríbete al canal de F&amp;C Consultores para recibir cada nuevo episodio y
                    ver las conversaciones completas.
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
