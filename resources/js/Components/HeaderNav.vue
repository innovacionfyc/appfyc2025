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

// Normaliza la URL actual (sin querystring y sin slash final)
const currentPath = () => {
  const raw = page.url || '/'
  const path = raw.split('?')[0]
  return path !== '/' ? path.replace(/\/+$/, '') : '/'
}

// Activo robusto:
// - Home solo activo en "/"
// - Resto activo si estás en la ruta exacta o dentro de ella (/blog/loquesea)
const isActive = (item) => {
  const cur = currentPath()
  const base = (item.match || item.href)
  const normalizedBase = base !== '/' ? base.replace(/\/+$/, '') : '/'

  if (normalizedBase === '/') return cur === '/'
  return cur === normalizedBase || cur.startsWith(normalizedBase + '/')
}

onMounted(() => {
  window.addEventListener('scroll', handleScroll)
})

onUnmounted(() => {
  window.removeEventListener('scroll', handleScroll)
})
</script>

<template>
  <header
    class="fixed top-0 left-0 right-0 z-50 w-full transition-all duration-300 ease-in-out border-b"
    :class="[
      isScrolled || isMenuOpen
        ? 'bg-white shadow-sm border-gray-100 py-2'
        : 'bg-transparent border-transparent py-4'
    ]"
  >
    <div class="max-w-[1920px] mx-auto px-4 sm:px-6 lg:px-12">
      <div class="flex items-center justify-between">

        <Link href="/" class="shrink-0 flex items-center z-50">
          <img
            src="/images/logo-fyc.png"
            alt="F&C Consultores"
            class="w-auto object-contain transition-all duration-300"
            :class="isScrolled ? 'h-10 md:h-12' : 'h-12 md:h-16'"
          />
        </Link>

        <nav class="hidden md:flex items-center gap-8">
          <Link
            v-for="item in nav"
            :key="item.href"
            :href="item.href"
            class="font-bold transition-colors duration-200 relative group"
            :class="isActive(item)
              ? 'bg-primary-vinotinto py-2 px-4 rounded-full text-mono-blanco font-bold'
              : isScrolled ? 'text-gray-900 hover:text-primary-vinotinto' : 'text-mono-blanco hover:text-white/90'"
          >
            {{ item.label }}
            <span
              class="absolute -bottom-1 left-0 w-0 h-0.5 bg-primary-vinotinto transition-all duration-300 group-hover:w-full"
            ></span>
          </Link>
        </nav>

        <div class="hidden md:flex items-center">
          <Link href="/contacto">
            <BtnUniversal label="Contáctenos" icon="rocket_launch" icon-position="right" size="md" />
          </Link>
        </div>

        <div class="md:hidden flex items-center z-50">
          <button
            @click="toggleMenu"
            class="p-2 text-gray-600 hover:bg-gray-100 rounded-lg focus:outline-none transition-colors"
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
        class="md:hidden absolute top-full left-0 w-full bg-white border-b border-gray-100 shadow-xl"
      >
        <div class="px-5 py-6 space-y-4">
          <nav class="flex flex-col gap-2">
            <Link
              v-for="item in nav"
              :key="item.href"
              :href="item.href"
              @click="isMenuOpen = false"
              class="block px-4 py-3 rounded-lg text-lg font-medium transition-colors"
              :class="isActive(item)
                ? 'bg-primary-vinotinto/10 text-primary-vinotinto font-bold'
                : 'text-gray-600 hover:bg-gray-50'"
            >
              {{ item.label }}
            </Link>
          </nav>

          <div class="pt-4 border-t border-gray-100">
            <Link
              href="/contacto"
              @click="isMenuOpen = false"
              class="flex w-full items-center justify-center px-4 py-3 text-base font-bold text-white bg-primary-naranja rounded-lg shadow hover:bg-secondary-naranja2 transition-all"
            >
              Contáctenos
            </Link>
          </div>
        </div>
      </div>
    </Transition>

  </header>
</template>
