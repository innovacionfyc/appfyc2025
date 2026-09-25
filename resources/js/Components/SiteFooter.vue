<script setup>
import { Link } from "@inertiajs/vue3";

const year = new Date().getFullYear();

const navigation = {
  empresa: [
    { label: "Inicio", href: "/" },
    { label: "Líneas de negocio", href: "/" },
    { label: "Nosotros", href: "/nosotros" },
    { label: "Públicaciones", href: "/responsabilidad-social" },
    { label: "Participantes", href: "/rincon-del-cliente" },
    { label: "Blog", href: "/blog" },
    { label: "Podcast", href: "/podcast" },
  ],
  áreas: [
    { label: "Formación jurídica", href: "/oferta" },
    { label: "Talento humano", href: "/oferta" },
    { label: "Gestión pública", href: "/oferta" },
    { label: "Finanzas y hacienda", href: "/oferta" },
    { label: "Enfoques misionales", href: "/oferta" },
  ],
  soporte: [
    { label: "centro de ayuda", href: "/contacto" },
    { label: "preguntas frecuentes", href: "/faq" },
    { label: "canales de atención", href: "/contacto" },
    { label: "soporte técnico", href: "/contacto" },
  ],
};
</script>

<template>
  <footer class="relative mt-36 sm:mt-44 bg-white pt-48 sm:pt-56 lg:pt-48 pb-10">
    <div
      class="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[calc(100%-3rem)] max-w-6xl"
    >
      <div
        class="relative overflow-hidden bg-gradient-vinotinto rounded-[2.5rem] p-8 sm:p-10 md:p-16 shadow-[0_35px_60px_-15px_rgba(244,63,94,0.4)]"
      >
        <div
          class="absolute top-0 right-0 -mr-20 -mt-20 w-80 h-80 bg-white/10 rounded-full blur-3xl bg-rose-500"
        ></div>
        <div
          class="absolute bottom-0 left-0 -ml-20 -mb-20 w-64 h-64 bg-rose-400/20 rounded-full blur-2xl"
        ></div>

        <div
          class="relative z-10 flex flex-col lg:flex-row items-center justify-between gap-10"
        >
          <div class="text-center lg:text-left space-y-4 max-w-2xl">
            <h2
              class="text-2xl sm:text-3xl md:text-5xl font-black text-white leading-tight tracking-tighter"
            >
              Potenciamos el conocimiento <br />
              <span class="text-rose-200">del sector público.</span>
            </h2>
            <p class="text-rose-100 text-lg font-medium opacity-90">
              Únete a los líderes que ya están transformando sus entidades.
            </p>
          </div>

          <Link
            href="/contacto"
            class="group bg-white text-rose-800 w-full sm:w-auto justify-center px-8 py-4 text-lg md:px-12 md:py-5 md:text-xl rounded-2xl font-black hover:bg-rose-50 transition-all hover:-translate-y-1 shadow-xl flex items-center gap-3"
          >
            Contáctenos
            <svg
              class="w-5 h-5 transition-transform group-hover:translate-x-1"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2.5"
                d="M17 8l4 4m0 0l-4 4m4-4H3"
              />
            </svg>
          </Link>
        </div>
      </div>
    </div>

    <div class="mx-auto max-w-7xl px-6 lg:px-8">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-12 gap-16 lg:gap-8 mb-20">
        <div class="lg:col-span-4 space-y-8">
          <Link href="/" class="inline-block">
            <img
              src="/images/logo-fyc.png"
              alt="f&c consultores"
              class="h-20 md:h-32 w-auto transition-transform hover:scale-105 duration-500"
            />
          </Link>
          <p class="text-slate-500 leading-relaxed max-w-xs font-medium">
            Somos sus aliados estratégicos en la generación de conocimiento para el
            fortalecimiento institucional.
          </p>

          <div class="flex gap-5 items-center">
            <a
              href="https://www.facebook.com/fycconsultoressas"
              class="group relative flex items-center justify-center"
            >
              <div
                class="absolute bottom-full mb-3 px-3 py-1.5 bg-slate-900 text-white text-[13px] font-bold  rounded-lg opacity-0 translate-y-2 group-hover:opacity-100 group-hover:translate-y-0 transition-all duration-300 pointer-events-none shadow-xl"
              >
                @fycconsultoressas

                <div
                  class="absolute top-full left-1/2 -translate-x-1/2 -mt-1 border-4 border-transparent border-t-slate-900"
                ></div>
              </div>

              <div
                class="text-slate-400 group-hover:text-[#1877F2] transition-colors duration-300 transform group-hover:scale-110"
              >
                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                  <path
                    d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z"
                  />
                </svg>
              </div>
            </a>

            <a
              href="https://www.linkedin.com/company/f-c-consultores-s-a-s/?viewAsMember=true"
              class="group relative flex items-center justify-center"
            >
              <div
                class="absolute bottom-full mb-3 px-5 py-1.5 bg-slate-900 text-white text-[13px] font-bold  rounded-lg opacity-0 translate-y-2 group-hover:opacity-100 group-hover:translate-y-0 transition-all duration-300 pointer-events-none shadow-xl"
              >
                @FyCCapacita
                <div
                  class="absolute top-full left-1/2 -translate-x-1/2 -mt-1 border-4 border-transparent border-t-slate-900"
                ></div>
              </div>

              <div
                class="text-slate-400 group-hover:text-[#0A66C2] transition-colors duration-300 transform group-hover:scale-110"
              >
                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                  <path
                    d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.761 0 5-2.239 5-5v-14c0-2.761-2.239-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"
                  />
                </svg>
              </div>
            </a>

            <a
              href="https://twitter.com/FYC_Consultores"
              class="group relative flex items-center justify-center"
            >
              <div
                class="absolute bottom-full mb-3 px-3 py-1.5 bg-slate-900 text-white text-[13px] font-bold  rounded-lg opacity-0 translate-y-2 group-hover:opacity-100 group-hover:translate-y-0 transition-all duration-300 pointer-events-none shadow-xl"
              >
                @FYC_Consultores
                <div
                  class="absolute top-full left-1/2 -translate-x-1/2 -mt-1 border-4 border-transparent border-t-slate-900"
                ></div>
              </div>
              <div
                class="text-slate-400 group-hover:text-black transition-colors duration-300 transform group-hover:scale-110"
              >
                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                  <path
                    d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"
                  />
                </svg>
              </div>
            </a>

            <a
              href="https://www.instagram.com/fycconsultores/"
              class="group relative flex items-center justify-center"
            >
              <div
                class="absolute bottom-full mb-3 px-3 py-1.5 bg-slate-900 text-white text-[13px] font-bold  rounded-lg opacity-0 translate-y-2 group-hover:opacity-100 group-hover:translate-y-0 transition-all duration-300 pointer-events-none shadow-xl"
              >
                @fycconsultores
                <div
                  class="absolute top-full left-1/2 -translate-x-1/2 -mt-1 border-4 border-transparent border-t-slate-900"
                ></div>
              </div>
              <div
                class="text-slate-400 group-hover:text-[#E4405F] transition-colors duration-300 transform group-hover:scale-110"
              >
                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                  <path
                    d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"
                  />
                </svg>
              </div>
            </a>

            <a
              href="https://www.youtube.com/FYCConsultores"
              class="group relative flex items-center justify-center"
            >
              <div
                class="absolute bottom-full mb-3 px-3 py-1.5 bg-slate-900 text-white text-[10px] font-bold  rounded-lg opacity-0 translate-y-2 group-hover:opacity-100 group-hover:translate-y-0 transition-all duration-300 pointer-events-none shadow-xl"
              >
                @FYCConsultores
                <div
                  class="absolute top-full left-1/2 -translate-x-1/2 -mt-1 border-4 border-transparent border-t-slate-900"
                ></div>
              </div>
              <div
                class="text-slate-400 group-hover:text-[#FF0000] transition-colors duration-300 transform group-hover:scale-110"
              >
                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                  <path
                    d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.872.505 9.377.505 9.377.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"
                  />
                </svg>
              </div>
            </a>

            <a
              href="https://whatsapp.com/channel/0029Vb64b0tEFeXeEkwHMc2r"
              target="_blank"
              class="group relative flex items-center justify-center"
            >
              <div
                class="absolute bottom-full mb-3 px-3 py-1.5 bg-slate-900 text-white text-[13px] font-bold  rounded-lg opacity-0 translate-y-2 group-hover:opacity-100 group-hover:translate-y-0 transition-all duration-300 pointer-events-none shadow-xl"
              >
                #soyfycconsultores
                <div
                  class="absolute top-full left-1/2 -translate-x-1/2 -mt-1 border-4 border-transparent border-t-slate-900"
                ></div>
              </div>
              <div
                class="text-slate-400 group-hover:text-[#25D366] transition-colors duration-300 transform group-hover:scale-110"
              >
                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                  <path
                    d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"
                  />
                </svg>
              </div>
            </a>
          </div>
        </div>

        <div class="lg:col-span-2">
          <h4 class="font-black text-mono-negro mb-8">Empresa</h4>
          <ul class="space-y-4">
            <li v-for="item in navigation.empresa" :key="item.label">
              <Link
                :href="item.href"
                class="text-slate-500 hover:text-primary-vinotinto text-sm transition-all hover:translate-x-1 inline-block font-medium"
                >{{ item.label }}</Link
              >
            </li>
          </ul>
        </div>

        <div class="lg:col-span-2">
          <h4 class="font-black text-mono-negro mb-8">Áreas formación</h4>
          <ul class="space-y-4">
            <li v-for="item in navigation.áreas" :key="item.label">
              <Link
                :href="item.href"
                class="text-slate-500 hover:text-primary-vinotinto text-sm transition-all hover:translate-x-1 inline-block font-medium"
                >{{ item.label }}</Link
              >
            </li>
          </ul>
        </div>

        <div class="lg:col-span-4 lg:pl-8">
          <h4 class="font-black text-mono-negro mb-8">Contáctenos</h4>
          <div class="space-y-6">
            <a href="tel:3163861932" class="flex items-center gap-4 group">
              <div
                class="w-12 h-12 bg-slate-50 rounded-2xl flex items-center justify-center text-primary-vinotinto group-hover:bg-primatext-primary-vinotinto group-hover:text-white transition-all duration-300"
              >
                <svg
                  class="w-5 h-5"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"
                  />
                </svg>
              </div>
              <span
                class="text-slate-600 font-bold group-hover:text-primary-vinotinto transition-colors tracking-tight"
                >316 386 1932</span
              >
            </a>

            <a
              href="mailto:contactenos@fycconsultores.com"
              class="flex items-center gap-4 group"
            >
              <div
                class="w-12 h-12 shrink-0 bg-slate-50 rounded-2xl flex items-center justify-center text-primary-vinotinto group-hover:bg-primatext-primary-vinotinto group-hover:text-white transition-all duration-300"
              >
                <svg
                  class="w-5 h-5"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                  />
                </svg>
              </div>
              <span
                class="min-w-0 text-slate-600 font-bold group-hover:text-primary-vinotinto transition-colors tracking-tight truncate"
                >contactenos@fycconsultores.com</span
              >
            </a>
          </div>
        </div>
      </div>
      
    </div>
    

    <div class="border-t border-slate-100 pt-5">
    <div
        class="w-full bg-white flex flex-col items-center justify-center gap-2 "
      >
        <div class="flex items-center gap-1.5 group cursor-default">
          <span class="text-[11px] font-medium text-slate-400 ">
            Hecho con
          </span>

          <span class="material-symbols-rounded text-red-500 text-sm animate-pulse">
            favorite
          </span>

          <span class="text-[11px] font-medium text-slate-400 ">
            por el equipo
            <span class="text-slate-600 font-bold"
              >transformación digital y comunicaciones</span
            >
          </span>
        </div>

        <div class="h-1 w-12 bg-slate-100 rounded-full mt-2"></div>
      </div>
      <div
        class="mx-auto max-w-7xl px-6 lg:px-8 flex flex-col md:flex-row justify-between items-center gap-8"
      >
        <p class="text-slate-400 font-medium">
          © copyright {{ year }} F&C Consultores. todos los derechos reservados.
        </p>

        <div class="flex flex-wrap justify-center gap-8">
          <Link
            href="/politica-datos"
            class="text-slate-400 hover:text-slate-900 text-xs font-bold transition-colors"
            >política de privacidad</Link
          >
          <Link
            href="/terminos"
            class="text-slate-400 hover:text-slate-900 text-xs font-bold transition-colors"
            >términos de uso</Link
          >
        </div>
      </div>
      
      
    </div>
  </footer>
</template>

<style scoped>
footer {
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

.hover\:translate-x-1:hover {
  transform: translateX(4px);
}

.material-symbols-rounded {
  font-variation-settings:
    'FILL' 1,
    'wght' 400,
    'GRAD' 0,
    'opsz' 24;
}

@keyframes pulse-soft {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.15); }
}

.animate-pulse {
  animation: pulse-soft 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
</style>
