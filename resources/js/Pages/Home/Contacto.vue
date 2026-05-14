<script setup>
import { Head } from "@inertiajs/vue3"; // Importamos Head para el título
import HeaderNav from "@/Components/HeaderNav.vue";
import RevealSection from "@/Components/RevealSection.vue";
import SiteFooter from "@/Components/SiteFooter.vue";
import GuestLayout from "@/Layouts/GuestLayout.vue";

const contacto = {
  whatsapp: "https://wa.me/573163861932",
  celular: "tel:3163861932",
  pbx: "tel:6013202662",
  email: "mailto:contactenos@fycconsultores.com",
};
</script>

<template>
  <Head title="Contacto - F&C Consultores" />

  <GuestLayout>
    <div class="h-screen w-full overflow-y-scroll snap-y snap-mandatory scroll-smooth bg-white flex flex-col">
      
      <HeaderNav />

      <main class="flex-grow">
        
        <RevealSection class="snap-start min-h-dvh w-full flex items-center">
          <div class="max-w-7xl mx-auto px-6 lg:px-8 w-full">
            <div class="mb-12">
              <h1 class="text-4xl md:text-6xl font-black text-gray-900 mb-4 tracking-tight">
                Comience su proyecto <span class="text-rose-500 italic">hoy</span>
              </h1>
              <p class="text-xl text-gray-500 max-w-2xl">
                Estamos listos para atenderte. Haz clic en cualquiera de nuestros canales para comunicarte directamente.
              </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-16">
              <a :href="contacto.whatsapp" target="_blank" class="group p-8 rounded-3xl bg-emerald-50 border-2 border-emerald-100 hover:border-emerald-500 hover:bg-white transition-all duration-300 shadow-sm hover:shadow-xl">
                <div class="flex flex-col h-full">
                  <div class="bg-emerald-500 text-white p-4 rounded-2xl w-fit mb-6 group-hover:scale-110 transition-transform">
                    <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24">
                      <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                  </div>
                  <h3 class="text-2xl font-bold text-gray-900 mb-2 ">Escríbenos</h3>
                  <p class="text-emerald-700 font-medium mb-4">Chat directo por whatsapp</p>
                  <p class="text-xl font-black text-emerald-600 mt-auto">+57 316 3861932</p>
                </div>
              </a>

              <a :href="contacto.pbx" class="group p-8 rounded-3xl bg-rose-50 border-2 border-rose-100 hover:border-rose-500 hover:bg-white transition-all duration-300 shadow-sm hover:shadow-xl">
                <div class="flex flex-col h-full">
                  <div class="bg-rose-500 text-white p-4 rounded-2xl w-fit mb-6 group-hover:scale-110 transition-transform">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                  </div>
                  <h3 class="text-2xl font-bold text-gray-900 mb-2 ">Llamadas pbx</h3>
                  <p class="text-rose-700 font-medium mb-4 italic">Bogotá, Colombia</p>
                  <p class="text-xl font-black text-rose-500 mt-auto">(601) 3202662</p>
                </div>
              </a>

              <a :href="contacto.email" class="group p-8 rounded-3xl bg-gray-50 border-2 border-gray-100 hover:border-gray-900 hover:bg-white transition-all duration-300 shadow-sm hover:shadow-xl">
                <div class="flex flex-col h-full">
                  <div class="bg-gray-900 text-white p-4 rounded-2xl w-fit mb-6 group-hover:scale-110 transition-transform">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                  </div>
                  <h3 class="text-2xl font-bold text-gray-900 mb-2 ">E-mail</h3>
                  <p class="text-gray-500 font-medium mb-4">Atención corporativa</p>
                  <p class="text-lg font-bold text-gray-900 mt-auto break-all ">contactenos@fycconsultores.com</p>
                </div>
              </a>
            </div>
          </div>
        </RevealSection>

        <!-- SECCIÓN 2: Mapas -->
        <RevealSection :repeat="true" class="snap-start min-h-dvh w-full flex items-center">
          <div class="max-w-7xl mx-auto px-6 lg:px-8 w-full">
            <h2 class="text-3xl font-black text-gray-900 flex items-center gap-3 mb-12 ">
              <span class="p-2 bg-red-100 rounded-lg text-red-600 material-symbols-rounded">map</span>
              ¿Cómo llegar?
            </h2>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12">
              <!-- Sede Principal -->
              <div class="space-y-4">
                <div class="flex justify-between items-start">
                  <div>
                    <h4 class="text-xl font-bold text-gray-900 ">Única sede - fyc consultores</h4>
                    <p class="text-gray-600  text-sm">Cra. 46 #22b-20, teusaquillo, bogotá</p>
                    <p class="text-rose-500 font-bold text-xs uppercase tracking-widest">oficina 602</p>
                  </div>
                  <a href="https://maps.app.goo.gl/3A6r9vGvVzY9m5Kk9" target="_blank" class="bg-gray-100 p-2 rounded-full hover:bg-rose-100 text-rose-500 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                  </a>
                </div>
                <div class="rounded-3xl overflow-hidden shadow-lg border-4 border-white h-80 ring-1 ring-gray-100">
                  <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3976.7722858356365!2d-74.0958241!3d4.6346692!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x8e3f9a5cdf40c7cb%3A0xde9b74cd704a87aa!2sFYC%20Consultores!5e0!3m2!1ses-419!2sco!4v1778765984200!5m2!1ses-419!2sco" class="w-full h-full" allowfullscreen="" loading="lazy"></iframe>
                </div>
              </div>

              <!-- Cafam -->
              <div class="space-y-4">
                <div class="flex justify-between items-start">
                  <div>
                    <h4 class="text-xl font-bold text-gray-900 ">Cafam la floresta</h4>
                    <p class="text-gray-600  text-sm">Av. 68 #90-88, barrios unidos, bogotá</p>
                    <p class="text-rose-500 font-bold text-xs uppercase tracking-widest">Centro de Convenciones</p>
                  </div>
                  <a href="https://maps.app.goo.gl/uX3L3U3U3U3U3U3U8" target="_blank" class="bg-gray-100 p-2 rounded-full hover:bg-rose-100 text-rose-500 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                  </a>
                </div>
                <div class="rounded-3xl overflow-hidden shadow-lg border-4 border-white h-80 ring-1 ring-gray-100">
                  <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3976.4785842791707!2d-74.0745663!3d4.6865771!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x8e3f9ae08bc5c16f%3A0x1f8f45475eceba3b!2sCentro%20de%20Convenciones%20Cafam%20La%20Floresta!5e0!3m2!1ses!2sco!4v1778765995539!5m2!1ses!2sco" class="w-full h-full" allowfullscreen="" loading="lazy"></iframe>
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
  </GuestLayout>
</template>

<style scoped>
.h-screen::-webkit-scrollbar {
  display: none;
}
.h-screen {
  -ms-overflow-style: none;
  scrollbar-width: none;
}

iframe {
  filter: grayscale(100%);
  transition: all 0.7s ease;
}
iframe:hover {
  filter: grayscale(0%);
}
</style>