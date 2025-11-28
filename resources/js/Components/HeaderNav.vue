<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

const isMenuOpen = ref(false)
const isScrolled = ref(false)

const page = usePage()

const nav = [
  { label: 'Inicio', href: '/' },
  { label: 'Oferta', href: '/oferta' },
  { label: 'Nosotros', href: '/nosotros' },
  { label: 'Responsabilidad social', href: '/responsabilidad-social' },
  { label: 'Blog', href: '/blog' },
]

const handleScroll = () => {
  isScrolled.value = window.scrollY > 20
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
    class="sticky top-5 z-50 transition-all duration-300 ease-in-out"
    :class="isScrolled
      ? 'mx-4 rounded-2xl bg-mono-blanco/95 backdrop-blur-lg shadow-lg ring-1 ring-black/5'
      : 'mx-1 bg-transparent'"
  >
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="flex h-20 items-center justify-between">
        <!-- Logo -->
        <Link href="/" class="shrink-0">
          <img
            src="/images/logo-fyc.png"
            alt="F&C Consultores"
            :class="[
              'w-auto select-none transition-all duration-300 ease-in-out hover:scale-105',
              isScrolled ? 'h-12' : 'h-16'
            ]"
          />
        </Link>

        <!-- Navegación desktop -->
        <nav class="hidden md:flex">
          <ul class="flex items-center space-x-2 lg:space-x-4">
            <li v-for="item in nav" :key="item.href">
              <Link
                :href="item.href"
                class="rounded-md px-3 py-2 text-[16px] font-medium transition-all duration-300 ease-out"
                :class="[
                  page.url === item.href
                    ? (
                        isScrolled
                          ? 'bg-primary-vinotinto text-white font-semibold tracking-wide'
                          : 'bg-mono-blanco/20 text-mono-blanco font-medium tracking-normal'
                      )
                    : (
                        isScrolled
                          ? 'text-primary-vinotinto hover:bg-gray-100 hover:text-gray-900 font-semibold tracking-wide'
                          : 'text-mono-blanco hover:bg-mono-blanco/10 font-medium tracking-normal'
                      ),
                  isScrolled ? 'text-[14px]' : 'text-[16px]'
                ]"
              >
                {{ item.label }}
              </Link>
            </li>
          </ul>
        </nav>

        <!-- Botón + menú móvil -->
        <div class="flex items-center gap-4">
          <!-- Botón contacto desktop -->
          <Link
            href="/contacto"
            class="hidden md:inline-flex items-center gap-2 rounded-xl bg-primary-naranja px-5 py-3 font-semibold text-white shadow-md transition-all duration-200 hover:-translate-y-0.5 hover:bg-secondary-naranja2 hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2"
          >
            Contáctenos
            <span class="text-lg transition-transform duration-200 group-hover:translate-x-1">→</span>
          </Link>

          <!-- Botón hamburguesa -->
          <div class="md:hidden">
            <button
              @click="isMenuOpen = !isMenuOpen"
              class="inline-flex items-center justify-center rounded-md p-2 text-gray-700 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary-vinotinto"
              aria-expanded="false"
            >
              <span class="sr-only">Abrir menú principal</span>
              <svg
                v-if="!isMenuOpen"
                class="h-6 w-6"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                aria-hidden="true"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
              </svg>
              <svg
                v-else
                class="h-6 w-6"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                aria-hidden="true"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Menú móvil -->
    <transition
      enter-active-class="transition ease-out duration-200"
      enter-from-class="opacity-0 translate-y-1"
      enter-to-class="opacity-100 translate-y-0"
      leave-active-class="transition ease-in duration-150"
      leave-from-class="opacity-100 translate-y-0"
      leave-to-class="opacity-0 translate-y-1"
    >
      <div v-if="isMenuOpen" class="md:hidden" id="mobile-menu">
        <div class="space-y-1 px-2 pb-3 pt-2 sm:px-3 bg-mono-blanco/95 backdrop-blur">
          <Link
            v-for="item in nav"
            :key="'m-' + item.href"
            :href="item.href"
            @click="isMenuOpen = false"
            class="block rounded-md px-3 py-2 text-base font-medium"
            :class="{
              'bg-primary-vinotinto/10 text-primary-vinotinto': page.url === item.href,
              'text-gray-700 hover:bg-gray-100 hover:text-gray-900': page.url !== item.href
            }"
          >
            {{ item.label }}
          </Link>

          <div class="pt-4">
            <Link
              href="/contacto"
              @click="isMenuOpen = false"
              class="block w-full text-center rounded-xl bg-primary-naranja px-5 py-3 font-semibold text-white shadow-md transition-all duration-200 hover:bg-secondary-naranja2 hover:shadow-lg"
            >
              Contáctenos →
            </Link>
          </div>
        </div>
      </div>
    </transition>
  </header>
</template>
