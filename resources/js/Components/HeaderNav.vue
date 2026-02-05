<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import BtnUniversal from './BtnUniversal.vue'

const isMenuOpen = ref(false)
const isScrolled = ref(false)
const page = usePage()

const nav = [
  { label: 'Inicio', href: '/', match: '/' },
  { label: 'Oferta', href: '/oferta', match: '/oferta' },
  { label: 'Nosotros', href: '/nosotros', match: '/nosotros' },
  { label: 'Responsabilidad social', href: '/responsabilidad-social', match: '/responsabilidad-social' },
  { label: 'Blog', href: '/blog', match: '/blog' },
]

const handleScroll = () => {
  isScrolled.value = window.scrollY > 20
}

const toggleMenu = () => {
  isMenuOpen.value = !isMenuOpen.value
}

const currentPath = () => {
  const raw = page.url || '/'
  const path = raw.split('?')[0]
  return path !== '/' ? path.replace(/\/+$/, '') : '/'
}

const isActive = (item) => {
  const cur = currentPath()
  const base = (item.match || item.href)
  const normalizedBase = base !== '/' ? base.replace(/\/+$/, '') : '/'

  if (normalizedBase === '/') return cur === '/'
  return cur === normalizedBase || cur.startsWith(normalizedBase + '/')
}

onMounted(() => {
  window.addEventListener('scroll', handleScroll, { passive: true })
})

onUnmounted(() => {
  window.removeEventListener('scroll', handleScroll)
})
</script>

<template>
  <header
    class="fixed top-0 left-0 right-0 z-50 w-full border-b transition-all duration-300 ease-in-out"
    :class="[
      (isScrolled || isMenuOpen)
        ? 'py-2 border-gray-200/60 shadow-sm'
        : 'py-3 border-white/10'
    ]"
  >
    <!-- Fondo animado (siempre) -->
    <div
      class="header-bg absolute inset-0 pointer-events-none"
      :class="(isScrolled || isMenuOpen) ? 'header-bg--solid' : 'header-bg--float'"
    ></div>

    <div class="max-w-[1920px] mx-auto px-4 sm:px-6 lg:px-12 relative">

      <!-- Logo grande flotando -->
      <Link
        href="/"
        class="absolute left-4 sm:left-6 lg:left-12 top-12 -translate-y-1/2 z-[80]"
      >
        <div class="relative inline-block">
          <!-- Glow blanco fuerte (corregido el span que estaba roto) -->
          <span
            class="absolute inset-[-10px] -z-10 rounded-3xl bg-white blur-3xl opacity-100"
          ></span>

          <img
            src="/images/logo-fyc.png"
            alt="F&C Consultores"
            class="w-auto object-contain transition-all duration-300 drop-shadow-lg"
            :class="(isScrolled || isMenuOpen)
              ? 'h-16 md:h-18'
              : 'h-20 md:h-24'"
          />
        </div>
      </Link>

      <!-- Contenido navbar: 3 columnas (izq vacío para el logo / centro menú / der botón) -->
      <div class="grid grid-cols-[1fr_auto_1fr] items-center">
        <!-- Columna izquierda (espacio del logo para no montar) -->
        <div class="hidden md:block"></div>

        <!-- Menú centrado real -->
        <nav class="hidden md:flex items-center justify-center gap-8">
          <Link
            v-for="item in nav"
            :key="item.href"
            :href="item.href"
            class="nav-pill font-extrabold tracking-wide transition-colors duration-200 relative group text-justify"
            :class="isActive(item)
              ? 'bg-primary-vinotinto py-2 px-4 rounded-full text-white'
              : ((isScrolled || isMenuOpen)
                  ? 'text-gray-900 hover:text-primary-vinotinto'
                  : 'text-gray-900/90 hover:text-gray-900')"
          >
          
              <span class="nav-pill__inner inline-flex items-center gap-0">
              <span>{{ item.label }}</span>
              <img
                src="/images/aliado-8.png"
                alt=""
                class="ovi-hover"
                aria-hidden="true"
              />
            </span>

            <span
              class="absolute -bottom-1 left-0 w-0 h-0.5 bg-primary-vinotinto transition-all duration-300 group-hover:w-full"
            ></span>
          </Link>
        </nav>

        <!-- Botón contacto a la derecha -->
        <div class="hidden md:flex items-center justify-end">
          <Link href="/contacto">
            <BtnUniversal label="Contáctenos" icon="rocket_launch" icon-position="right" size="md" />
          </Link>
        </div>

        <!-- Menú móvil botón -->
        <div class="md:hidden flex items-center justify-between">
          <!-- Espacio para que el botón no se monte raro en móvil -->
          <div class="w-1"></div>

          <button
            @click="toggleMenu"
            class="p-2 rounded-lg focus:outline-none transition-colors text-gray-800 hover:bg-black/5 z-50"
          >
            <span class="sr-only">Menú</span>
            <div class="w-6 h-5 relative flex flex-col justify-between">
              <span
                class="w-full h-0.5 bg-current rounded-full transition-transform duration-300 origin-center"
                :class="isMenuOpen ? 'rotate-45 translate-y-2.5' : ''"
              ></span>
              <span
                class="w-full h-0.5 bg-current rounded-full transition-opacity duration-300"
                :class="isMenuOpen ? 'opacity-0' : 'opacity-100'"
              ></span>
              <span
                class="w-full h-0.5 bg-current rounded-full transition-transform duration-300 origin-center"
                :class="isMenuOpen ? '-rotate-45 -translate-y-2' : ''"
              ></span>
            </div>
          </button>
        </div>

      </div>
    </div>

    <!-- Menú móvil -->
    <Transition
      enter-active-class="transition duration-300 ease-out"
      enter-from-class="-translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-200 ease-in"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="-translate-y-full opacity-0"
    >
      <div
        v-if="isMenuOpen"
        class="md:hidden absolute top-full left-0 w-full bg-white/95 backdrop-blur-md border-b border-gray-100 shadow-xl"
      >
        <div class="px-5 py-6 space-y-4">
          <nav class="flex flex-col gap-2">
            <Link
              v-for="item in nav"
              :key="item.href"
              :href="item.href"
              @click="isMenuOpen = false"
              class="block px-4 py-3 rounded-lg text-lg font-semibold tracking-wide transition-colors text-justify"
              :class="isActive(item)
                ? 'bg-primary-vinotinto/10 text-primary-vinotinto font-extrabold'
                : 'text-gray-700 hover:bg-gray-50'"
            >
              {{ item.label }}
            </Link>
          </nav>

          <div class="pt-4 border-t border-gray-100">
            <Link
              href="/contacto"
              @click="isMenuOpen = false"
              class="flex w-full items-center justify-center px-4 py-3 text-base font-extrabold tracking-wide text-white bg-primary-naranja rounded-lg shadow hover:bg-secondary-naranja2 transition-all text-justify"
            >
              Contáctenos
            </Link>
          </div>
        </div>
      </div>
    </Transition>
  </header>
</template>

<style scoped>
/* Capa de fondo: animación sutil + glass */
.header-bg{
  z-index: 0;
  background:
    radial-gradient(900px 220px at 15% 0%, rgba(211,47,87,0.12), transparent 55%),
    radial-gradient(700px 220px at 85% 0%, rgba(243,147,34,0.12), transparent 55%),
    linear-gradient(90deg,
      rgba(255,255,255,0.55),
      rgba(255,255,255,0.35),
      rgba(255,255,255,0.55)
    );
  background-size: 140% 140%, 140% 140%, 220% 100%;
  animation: headerGlow 18s ease-in-out infinite;
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  transition: opacity .25s ease, background .25s ease, backdrop-filter .25s ease;
}

/* Modo flotante (arriba): un toque más suave */
.header-bg--float{ opacity: 0.55; }

/* Modo sólido (scroll o menú abierto): más legible */
.header-bg--solid{ opacity: 0.88; }

/* Hover: “enfoque” para resaltar texto */
header:hover .header-bg{
  opacity: 0.95;
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  background:
    radial-gradient(900px 220px at 15% 0%, rgba(211,47,87,0.16), transparent 55%),
    radial-gradient(700px 220px at 85% 0%, rgba(243,147,34,0.16), transparent 55%),
    linear-gradient(90deg,
      rgba(255,255,255,0.72),
      rgba(255,255,255,0.52),
      rgba(255,255,255,0.72)
    );
}

@keyframes headerGlow{
  0%   { background-position: 0% 0%,   100% 0%, 0% 0%; }
  50%  { background-position: 20% 20%, 80% 10%, 100% 0%; }
  100% { background-position: 0% 0%,   100% 0%, 0% 0%; }
}

/* Importantísimo: el contenido del header por encima */
header > *:not(.header-bg){
  position: relative;
  z-index: 10;
}

  /* Ovi NO ocupa espacio cuando está oculto */
  .ovi-hover{
    width: 0px;
    height: 26px;            /* tamaño final */
    margin-left: 0px;
    opacity: 0;
    overflow: hidden;
    object-fit: contain;
    pointer-events: none;

    transform: translateX(6px) scale(0.9);
    transition:
      width .18s ease,
      margin-left .18s ease,
      opacity .18s ease,
      transform .18s ease;
  }

  /* En hover: aparece y “abre” espacio a la derecha */
  .group:hover .ovi-hover{
    width: 26px;
    margin-left: 8px;        /* separación respecto al texto */
    opacity: 1;
    transform: translateX(0) scale(1);
  }
</style>
