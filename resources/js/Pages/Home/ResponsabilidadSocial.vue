<script setup>
import HeaderNav from "@/Components/HeaderNav.vue";
import RevealSection from "@/Components/RevealSection.vue";
import SiteFooter from "@/Components/SiteFooter.vue";
import GuestLayout from "@/Layouts/GuestLayout.vue";
import { Head } from "@inertiajs/vue3";
</script>

<template>
  <Head title="Responsabilidad social" />
  <GuestLayout>
    <div
      class="h-screen w-full overflow-y-scroll snap-y snap-mandatory scroll-smooth bg-white flex flex-col"
    >
      <div class="min-h-screen bg-gray-50/50">
        <HeaderNav />

        <main class="min-h-dvh pt-24 pb-12 flex flex-col">
          <RevealSection class="flex-grow flex items-center min-h-dvh snap-start w-full">
            <div class="max-w-7xl mx-auto px-6 lg:px-8 w-full">
              <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <div class="text-center lg:text-left order-2 lg:order-1">
                  <span
                    class="inline-block px-4 py-1.5 mb-4 text-sm font-semibold tracking-wider text-rose-600 uppercase bg-rose-100 rounded-full animate-pulse"
                  >
                    Próximamente
                  </span>

                  <h1
                    class="text-4xl md:text-6xl font-black text-gray-900 mb-6 leading-tight"
                  >
                    Construyendo un <br />
                    <span
                      class="text-transparent bg-clip-text bg-gradient-to-r from-rose-600 to-orange-500"
                    >
                      futuro sostenible
                    </span>
                  </h1>

                  <p
                    class="text-lg text-gray-600 leading-relaxed max-w-xl mx-auto lg:mx-0 mb-8 text-justify lg:text-left"
                  >
                    Estamos diseñando un espacio dedicado a nuestras iniciativas de
                    <b>Responsabilidad Social</b>. Pronto podrás conocer cómo estamos
                    impactando positivamente en nuestra comunidad y el medio ambiente.
                  </p>

                  <div class="max-w-md">
                    <div class="flex justify-between mb-2">
                      <span class="text-sm font-medium text-rose-700"
                        >Progreso del apartado</span
                      >
                      <span class="text-sm font-medium text-rose-700">75%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                      <div
                        class="bg-rose-600 h-2.5 rounded-full shadow-[0_0_15px_rgba(225, 29, 72,0.5)] transition-all duration-1000 w-[75%]"
                      ></div>
                    </div>
                  </div>
                </div>

                <div class="order-1 lg:order-2 flex justify-center items-center">
                  <div class="relative w-64 h-64 md:w-80 md:h-80">
                    <div
                      class="absolute inset-0 bg-rose-200 rounded-full mix-blend-multiply filter blur-xl opacity-70 animate-blob"
                    ></div>
                    <div
                      class="absolute inset-0 bg-orange-200 rounded-full mix-blend-multiply filter blur-xl opacity-70 animate-blob animation-delay-2000"
                    ></div>

                    <div
                      class="relative bg-white p-8 rounded-3xl shadow-2xl flex items-center justify-center border border-gray-100"
                    >
                      <svg
                        class="w-32 h-32 text-rose-500 animate-bounce"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        xmlns="http://www.w3.org/2000/svg"
                      >
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="1.5"
                          d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"
                        ></path>
                      </svg>
                      <div
                        class="absolute -top-4 -right-4 bg-orange-400 p-3 rounded-2xl shadow-lg animate-spin-slow"
                      >
                        <svg
                          class="w-6 h-6 text-white"
                          fill="none"
                          stroke="currentColor"
                          viewBox="0 0 24 24"
                        >
                          <path
                            d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                          ></path>
                        </svg>
                      </div>
                    </div>
                  </div>
                </div>
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
.animate-blob {
  animation: blob 7s infinite;
}
.animation-delay-2000 {
  animation-delay: 2s;
}
.animate-spin-slow {
  animation: spin 8s linear infinite;
}

@keyframes blob {
  0% {
    transform: translate(0px, 0px) scale(1);
  }
  33% {
    transform: translate(30px, -50px) scale(1.1);
  }
  66% {
    transform: translate(-20px, 20px) scale(0.9);
  }
  100% {
    transform: translate(0px, 0px) scale(1);
  }
}

div::-webkit-scrollbar {
  display: none;
}
div {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>
