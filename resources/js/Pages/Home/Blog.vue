<script setup>
import HeaderNav from "@/Components/HeaderNav.vue";
import RevealSection from "@/Components/RevealSection.vue";
import SiteFooter from "@/Components/SiteFooter.vue";
import GuestLayout from "@/Layouts/GuestLayout.vue";
import { Head } from "@inertiajs/vue3";

const categorias = ["Consultoría", "Innovación", "Legal", "Finanzas", "Tendencias"];
</script>

<template>
  <Head title="Nuestro Blog" />
  <GuestLayout>
    <div
      class="h-screen w-full overflow-y-scroll snap-y snap-mandatory scroll-smooth bg-white flex flex-col"
    >
       <div class="min-h-screen bg-slate-50">
        <HeaderNav />

        <main class="min-h-screen pt-24 pb-12">
          <RevealSection class="">
            <div class="max-w-7xl mx-auto px-6 lg:px-8">
              <div
                class="flex flex-col md:flex-row md:items-end justify-between mb-12 gap-6"
              >
                <div class="max-w-2xl">
                  <span
                    class="text-orange-500 font-bold tracking-widest uppercase text-sm mb-2 block"
                    >Insights & Actualidad</span
                  >
                  <h1 class="text-5xl font-black text-gray-900 mb-4">
                    Nuestro
                    <span class="relative"
                      >Blog<span
                        class="absolute bottom-2 left-0 w-full h-3 bg-orange-200 -z-10"
                      ></span
                    ></span>
                  </h1>
                  <p class="text-lg text-gray-600 leading-relaxed text-justify">
                    Estamos curando los mejores artículos, análisis y noticias de interés
                    para mantenerte siempre a la vanguardia. Muy pronto, este espacio será
                    tu fuente principal de conocimiento.
                  </p>
                </div>

                <div class="flex flex-wrap gap-2">
                  <span
                    v-for="cat in categorias"
                    :key="cat"
                    class="px-4 py-2 rounded-full border border-gray-200 text-gray-400 text-sm font-medium animate-pulse"
                  >
                    {{ cat }}
                  </span>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-10">
                <div class="lg:col-span-2 group cursor-wait">
                  <div
                    class="relative overflow-hidden rounded-3xl bg-gray-100 aspect-video mb-6"
                  >
                    <div
                      class="absolute inset-0 bg-gradient-to-r from-transparent via-white/50 to-transparent -translate-x-full animate-shimmer"
                    ></div>
                    <div class="absolute inset-0 flex items-center justify-center">
                      <svg
                        class="w-16 h-16 text-gray-200 animate-pulse"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                      >
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="1"
                          d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
                        />
                      </svg>
                    </div>
                  </div>
                  <div class="space-y-4">
                    <div class="h-4 w-32 bg-orange-100 rounded animate-pulse"></div>
                    <div class="h-8 w-3/4 bg-gray-200 rounded-lg animate-pulse"></div>
                    <div class="h-4 w-full bg-gray-100 rounded animate-pulse"></div>
                    <div class="h-4 w-5/6 bg-gray-100 rounded animate-pulse"></div>
                  </div>
                </div>

                <div v-for="n in 2" :key="n" class="space-y-6">
                  <div v-for="i in 2" :key="i" class="flex gap-4 group">
                    <div
                      class="w-24 h-24 shrink-0 bg-gray-100 rounded-2xl relative overflow-hidden"
                    >
                      <div
                        class="absolute inset-0 bg-gradient-to-r from-transparent via-white/40 to-transparent -translate-x-full animate-shimmer"
                      ></div>
                    </div>
                    <div class="flex-1 space-y-3 py-2">
                      <div class="h-3 w-20 bg-gray-200 rounded animate-pulse"></div>
                      <div class="h-5 w-full bg-gray-100 rounded animate-pulse"></div>
                      <div class="h-3 w-16 bg-orange-50 rounded animate-pulse"></div>
                    </div>
                  </div>
                </div>
              </div>

              <div
                class="mt-20 p-8 md:p-12 bg-gray-900 rounded-[2.5rem] relative overflow-hidden"
              >
                <div class="relative z-10 max-w-xl">
                  <h3 class="text-2xl md:text-3xl font-bold text-white mb-4">
                    ¿Quieres ser el primero en leerlo?
                  </h3>
                  <p class="text-gray-400 mb-8">
                    Suscríbete para recibir una notificación apenas lancemos nuestro
                    primer artículo exclusivo.
                  </p>

                  <div class="flex flex-col sm:flex-row gap-3">
                    <input
                      type="email"
                      placeholder="tu@correo.com"
                      class="bg-gray-800 border-none rounded-2xl px-6 py-4 text-white focus:ring-2 focus:ring-orange-500 flex-1"
                      disabled
                    />
                    <button
                      class="bg-orange-500 hover:bg-orange-600 text-white font-bold px-8 py-4 rounded-2xl transition-all opacity-80 cursor-not-allowed"
                    >
                      Notificarme
                    </button>
                  </div>
                </div>
                <div
                  class="absolute top-0 right-0 w-64 h-64 bg-orange-500/10 rounded-full blur-3xl -mr-20 -mt-20"
                ></div>
              </div>
            </div>
          </RevealSection>
        </main>
        <RevealSection>
          <SiteFooter />
        </RevealSection>
      </div>
    </div>
  </GuestLayout>
</template>

<style scoped>
@keyframes shimmer {
  100% {
    transform: translateX(100%);
  }
}
.animate-shimmer {
  animation: shimmer 2s infinite;
}
</style>
