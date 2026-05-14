<script setup>
import HeaderNav from "@/Components/HeaderNav.vue";
import RevealSection from "@/Components/RevealSection.vue";
import SiteFooter from "@/Components/SiteFooter.vue";
import GuestLayout from "@/Layouts/GuestLayout.vue";
import { Head } from "@inertiajs/vue3";

const futurosRecursos = [
  {
    titulo: "Guías Exclusivas",
    icon:
      "M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253",
  },
  {
    titulo: "Webinars y Videos",
    icon:
      "M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z",
  },
  {
    titulo: "Plantillas de Gestión",
    icon:
      "M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z",
  },
];
</script>

<template>
  <Head title="Rincón del cliente" />

  <GuestLayout>
    <div
      class="h-screen w-full overflow-y-scroll snap-y snap-mandatory scroll-smooth bg-white flex flex-col"
    >
      <div class="min-h-screen bg-slate-50">
        <HeaderNav />

        <main class="min-h-screen pt-24 pb-12">
          <RevealSection class="flex-grow flex items-center min-h-dvh snap-start w-full">
            <div class="max-w-7xl mx-auto px-6 lg:px-8">
              <div class="mb-12 text-center lg:text-left">
                <h1 class="text-4xl md:text-5xl font-black text-slate-900 mb-6">
                  Rincón del <span class="text-rose-600">Cliente</span>
                </h1>
                <p
                  class="text-lg text-slate-600 leading-relaxed max-w-3xl text-justify lg:text-left"
                >
                  Bienvenido a tu espacio exclusivo. Estamos preparando una selección de
                  contenidos, recursos y herramientas diseñadas para potenciar tu
                  experiencia con
                  <span class="font-bold text-slate-800 text-nowrap">F&C Consultores</span
                  >.
                </p>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mb-16">
                <div
                  v-for="(item, index) in futurosRecursos"
                  :key="index"
                  class="group relative bg-white p-8 rounded-2xl shadow-sm border border-slate-100 hover:shadow-xl transition-all duration-500 hover:-translate-y-2 overflow-hidden"
                >
                  <div
                    class="absolute -right-4 -top-4 w-24 h-24 bg-rose-50 rounded-full transition-transform group-hover:scale-150 duration-700"
                  ></div>

                  <div class="relative">
                    <div
                      class="w-12 h-12 bg-rose-600 rounded-xl flex items-center justify-center mb-6 shadow-lg shadow-rose-200"
                    >
                      <svg
                        class="w-6 h-6 text-white"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                      >
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          :d="item.icon"
                        />
                      </svg>
                    </div>

                    <h3 class="text-xl font-bold text-slate-900 mb-3">
                      {{ item.titulo }}
                    </h3>

                    <div class="space-y-3">
                      <div
                        class="h-2 w-full bg-slate-100 rounded-full animate-pulse"
                      ></div>
                      <div
                        class="h-2 w-5/6 bg-slate-100 rounded-full animate-pulse delay-75"
                      ></div>
                      <div
                        class="h-2 w-4/6 bg-slate-100 rounded-full animate-pulse delay-150"
                      ></div>
                    </div>

                    <div
                      class="mt-6 flex items-center text-rose-600 font-semibold text-sm"
                    >
                      Próximamente
                      <svg
                        class="w-4 h-4 ml-2 animate-bounce-x"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                      >
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M13 7l5 5m0 0l-5 5m5-5H6"
                        />
                      </svg>
                    </div>
                  </div>
                </div>
              </div>

              <div
                class="bg-rose-900 rounded-3xl p-8 md:p-12 text-white relative overflow-hidden shadow-2xl"
              >
                <div
                  class="relative z-10 flex flex-col md:flex-row items-center justify-between gap-8"
                >
                  <div class="text-center md:text-left">
                    <h2 class="text-2xl md:text-3xl font-bold mb-4">
                      Estamos personalizando tu acceso
                    </h2>
                    <p class="text-rose-200 max-w-md italic">
                      "La excelencia no es un acto, sino un hábito. Estamos puliendo cada
                      detalle de este portal para ti."
                    </p>
                  </div>
                  <div class="flex -space-x-4 overflow-hidden">
                    <div
                      v-for="i in 3"
                      :key="i"
                      class="inline-block h-12 w-12 rounded-full ring-4 ring-rose-900 bg-rose-700 flex items-center justify-center"
                    >
                      <svg
                        class="w-6 h-6 text-rose-300"
                        fill="currentColor"
                        viewBox="0 0 20 20"
                      >
                        <path
                          fill-rule="evenodd"
                          d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"
                          clip-rule="evenodd"
                        />
                      </svg>
                    </div>
                  </div>
                </div>

                <div
                  class="absolute top-0 right-0 -mr-20 -mt-20 w-64 h-64 bg-rose-800 rounded-full opacity-50"
                ></div>
                <div
                  class="absolute bottom-0 left-0 -ml-10 -mb-10 w-40 h-40 bg-rose-500 rounded-full opacity-20"
                ></div>
              </div>
            </div>
          </RevealSection>
        </main>
        <RevealSection class="snap-end w-full z-40 relative mt-auto">
          <SiteFooter />
        </RevealSection>
      </div>
    </div>
  </GuestLayout>
</template>

<style scoped>
@keyframes bounce-x {
  0%,
  100% {
    transform: translateX(0);
  }
  50% {
    transform: translateX(5px);
  }
}
.animate-bounce-x {
  animation: bounce-x 1s infinite;
}
</style>
