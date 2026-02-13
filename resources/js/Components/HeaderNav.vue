<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import BtnUniversal from './BtnUniversal.vue'

const isMenuOpen = ref(false)
const isScrolled = ref(false)
const page = usePage()

const nav = [
  { label: 'Inicio', href: '/', match: '/' },
  { label: 'Líneas de negocio', href: '/oferta', match: '/oferta' },
  { label: 'Nosotros', href: '/nosotros', match: '/nosotros' },
  { label: 'Publicaciones', href: '/responsabilidad-social', match: '/responsabilidad-social' },
  { label: 'Rincón del cliente', href: '/rincon-del-cliente', match: '/rincon-del-cliente' },
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
    class="fixed top-0 left-0 right-0 z-50 w-full transition-all duration-500 ease-out"
    :class="[
      (isScrolled || isMenuOpen)
        ? 'py-3 bg-white/70 backdrop-blur-xl border-b border-white/50 shadow-[0_4px_30px_rgba(0,0,0,0.04)]'
        : 'py-5 bg-transparent border-b border-transparent'
    ]"
  >
    <div
      class="header-bg absolute inset-0 pointer-events-none transition-opacity duration-500"
      :class="(isScrolled || isMenuOpen) ? 'opacity-100' : 'opacity-40'"
    ></div>

    <div class="max-w-[1920px] mx-auto px-4 sm:px-6 lg:px-8 xl:px-12 relative flex items-center justify-between">
      
      <div class="relative z-20 flex-shrink-0">
        <Link href="/" class="group flex items-center outline-none">
          <div class="relative inline-block transition-transform duration-300 group-active:scale-95">
            <div class="absolute inset-[-15px] -z-10 rounded-full bg-white/90 blur-2xl transition-opacity duration-300" 
                 :class="(isScrolled || isMenuOpen) ? 'opacity-100' : 'opacity-60'"></div>
            <img
              src="/images/logo-fyc.png"
              alt="F&C Consultores"
              class="w-auto object-contain transition-all duration-500 drop-shadow-sm"
              :class="(isScrolled || isMenuOpen) ? 'h-12 md:h-16' : 'h-16 md:h-20'"
            />
          </div>
        </Link>
      </div>

      <nav 
        class="hidden xl:flex absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 items-center gap-1.5 p-1.5 rounded-2xl transition-all duration-500 z-20"
        :class="isScrolled 
          ? 'bg-white/50 backdrop-blur-md border border-white/60 shadow-sm' 
          : 'bg-white/30 backdrop-blur-sm border border-white/40'"
      >
        <Link
          v-for="item in nav"
          :key="item.href"
          :href="item.href"
          class="nav-pill group relative flex items-center px-4 py-2.5 rounded-xl text-sm 2xl:text-base font-bold tracking-wide transition-all duration-300 overflow-hidden outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50"
          :class="isActive(item)
            ? 'bg-primary-vinotinto text-mono-blanco shadow-md shadow-primary-vinotinto/20 scale-100'
            : 'text-gray-700 hover:bg-white/60 hover:text-primary-vinotinto hover:shadow-sm hover:scale-[1.02] active:scale-95'"
        >
          <span class="relative z-10 flex items-center justify-center">
            <span>{{ item.label }}</span>
            <img
              src="/images/aliado-8.png"
              alt=""
              class="ovi-hover drop-shadow-md"
              aria-hidden="true"
            />
          </span>
          <span v-if="isActive(item)" class="absolute inset-0 bg-gradient-to-t from-black/10 to-transparent pointer-events-none"></span>
        </Link>
      </nav>

      <div class="hidden md:flex items-center justify-end gap-6 z-20">
        <Link 
          href="/login" 
          class="text-sm font-bold text-gray-700 hover:text-primary-naranja transition-colors duration-300 outline-none focus-visible:text-primary-naranja relative group"
        >
          Iniciar Sesión
          <span class="absolute -bottom-1 left-0 w-0 h-[2px] rounded-full bg-primary-naranja transition-all duration-300 group-hover:w-full"></span>
        </Link>
        <Link href="/contacto" class="group transition-transform active:scale-95 outline-none">
          <BtnUniversal 
            label="Contáctenos" 
            icon="rocket_launch" 
            icon-position="right" 
            size="md" 
            class="shadow-lg shadow-primary-naranja/20 group-hover:shadow-primary-naranja/40 transition-shadow duration-300"
          />
        </Link>
      </div>

      <div class="xl:hidden flex items-center z-20">
        <button
          @click="toggleMenu"
          class="relative p-3 rounded-xl bg-white/50 backdrop-blur-sm border border-white/60 shadow-sm text-gray-800 hover:bg-white/80 transition-all duration-300 focus:outline-none"
          :aria-expanded="isMenuOpen"
        >
          <span class="sr-only">Menú</span>
          <div class="w-5 h-4 relative flex flex-col justify-between">
            <span class="w-full h-[2px] bg-current rounded-full transition-all duration-300 origin-left" :class="isMenuOpen ? 'rotate-45 translate-x-0.5 -translate-y-0.5' : ''"></span>
            <span class="w-full h-[2px] bg-current rounded-full transition-all duration-300" :class="isMenuOpen ? 'opacity-0 translate-x-3' : 'opacity-100'"></span>
            <span class="w-full h-[2px] bg-current rounded-full transition-all duration-300 origin-left" :class="isMenuOpen ? '-rotate-45 translate-x-0.5 translate-y-0.5' : ''"></span>
          </div>
        </button>
      </div>

    </div>

    <Transition
      enter-active-class="transition duration-400 ease-out"
      enter-from-class="-translate-y-4 opacity-0 scale-95"
      enter-to-class="translate-y-0 opacity-100 scale-100"
      leave-active-class="transition duration-300 ease-in"
      leave-from-class="translate-y-0 opacity-100 scale-100"
      leave-to-class="-translate-y-4 opacity-0 scale-95"
    >
      <div
        v-if="isMenuOpen"
        class="xl:hidden absolute top-full left-4 right-4 mt-2 bg-white/90 backdrop-blur-2xl border border-white/60 shadow-2xl rounded-2xl overflow-hidden z-40"
      >
        <div class="p-5 flex flex-col gap-2">
          <nav class="flex flex-col gap-1">
            <Link
              v-for="item in nav"
              :key="item.href"
              :href="item.href"
              @click="isMenuOpen = false"
              class="flex items-center px-4 py-3.5 rounded-xl text-base font-bold tracking-wide transition-all duration-200 active:scale-[0.98]"
              :class="isActive(item)
                ? 'bg-primary-vinotinto text-white shadow-md shadow-primary-vinotinto/20'
                : 'text-gray-700 hover:bg-gray-100/80'"
            >
              {{ item.label }}
            </Link>
          </nav>

          <div class="w-full h-px bg-gray-200/60 my-2"></div>

          <div class="flex flex-col gap-3">
            <Link
              href="/login"
              @click="isMenuOpen = false"
              class="flex items-center justify-center w-full px-4 py-3 rounded-xl text-base font-bold text-gray-700 bg-gray-100/50 hover:bg-gray-100 transition-colors active:scale-[0.98]"
            >
              Iniciar Sesión
            </Link>
            <Link
              href="/contacto"
              @click="isMenuOpen = false"
              class="flex items-center justify-center w-full px-4 py-3 rounded-xl text-base font-extrabold tracking-wide text-white bg-primary-naranja shadow-lg shadow-primary-naranja/20 hover:bg-secondary-naranja2 transition-all active:scale-[0.98]"
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
.header-bg {
  z-index: 0;
  background:
    radial-gradient(800px 300px at 15% -20%, rgba(211,47,87,0.08), transparent 60%),
    radial-gradient(800px 300px at 85% -20%, rgba(243,147,34,0.08), transparent 60%);
  background-size: 100% 100%;
  animation: headerPulse 15s ease-in-out infinite alternate;
}

@keyframes headerPulse {
  0%   { opacity: 0.6; transform: scale(1); }
  100% { opacity: 1; transform: scale(1.05); }
}

/* Ovi - Transición suave y rebotante */
.ovi-hover {
  width: 0px;
  height: 24px;
  margin-left: 0px;
  opacity: 0;
  overflow: hidden;
  object-fit: contain;
  pointer-events: none;
  transform: translateX(-10px) scale(0.8) rotate(-10deg);
  transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); /* Efecto spring/rebote */
}

/* En hover: aparece, empuja el texto y rota levemente a su posición original */
.group:hover .ovi-hover {
  width: 24px;
  margin-left: 8px;
  opacity: 1;
  transform: translateX(0) scale(1) rotate(0deg);
}

/* Anular efecto si el elemento ya está activo (opcional) */
.group.bg-primary-vinotinto .ovi-hover {
  filter: brightness(0) invert(1); /* Pone a Ovi blanco si el fondo es oscuro */
}
</style>