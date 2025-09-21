<script setup>
import { Link } from '@inertiajs/vue3'

const nav = [
  { label: 'Inicio', href: '/' },
  { label: 'Oferta', href: '/oferta' },
  { label: 'Nosotros', href: '/nosotros' },
  { label: 'Responsabilidad social', href: '/responsabilidad-social' },
  { label: 'Blog', href: '/blog' },
]
</script>

<template>
  <!-- Conservamos tu ajuste: pt-1 en móvil y md:pt-0 en desktop -->
  <header class="sticky top-0 z-40 bg-transparent pt-1 md:pt-0">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-0">
      <div class="flex items-center justify-between gap-4">
        <Link href="/" class="shrink-0 inline-flex items-center">
          <img
            src="/images/logo-fyc.png"
            alt="F&C Consultores"
            class="h-16 md:h-20 w-auto select-none
                   brightness-110 contrast-110
                   drop-shadow-[0_2px_10px_rgba(255,255,255,0.99)]" />
        </Link>

        <div class="flex-1 flex justify-center px-2 md:px-4">
          <nav 
            class="hidden md:flex items-center justify-center
                   rounded-full overflow-hidden
                   bg-mono-blanco/90 backdrop-blur
                   shadow-[0_10px_30px_rgba(0,0,0,0.08)]
                   border-0 ring-0
                   h-14
                   w-full max-w-[1100px]
                   px-10 lg:px-14 xl:px-20">
            <div class="flex items-center gap-8">
              <Link href="/" class="px-3 py-2 rounded-lg
                   text-[16px] md:text-[17px] font-semibold md:font-bold tracking-wide
                   text-primary-vinotinto
                   transition-colors duration-200
                   hover:text-mono-blanco hover:bg-secondary-vinotinto2/90
                   focus:outline-none focus-visible:ring-2 focus-visible:ring-extra-light">Inicio</Link>
              <Link href="/oferta" class="px-3 py-2 rounded-lg
                   text-[16px] md:text-[17px] font-semibold md:font-bold tracking-wide
                   text-primary-vinotinto
                   transition-colors duration-200
                   hover:text-mono-blanco hover:bg-secondary-vinotinto2/90
                   focus:outline-none focus-visible:ring-2 focus-visible:ring-extra-light">Oferta</Link>
              <Link href="/nosotros" class="px-3 py-2 rounded-lg
                   text-[16px] md:text-[17px] font-semibold md:font-bold tracking-wide
                   text-primary-vinotinto
                   transition-colors duration-200
                   hover:text-mono-blanco hover:bg-secondary-vinotinto2/90
                   focus:outline-none focus-visible:ring-2 focus-visible:ring-extra-light">Nosotros</Link>
              <Link href="/responsabilidad-social" class="px-3 py-2 rounded-lg
                   text-[16px] md:text-[17px] font-semibold md:font-bold tracking-wide
                   text-primary-vinotinto
                   transition-colors duration-200
                   hover:text-mono-blanco hover:bg-secondary-vinotinto2/90
                   focus:outline-none focus-visible:ring-2 focus-visible:ring-extra-light">Responsabilidad social</Link>
              <Link href="/blog" class="px-3 py-2 rounded-lg
                   text-[16px] md:text-[17px] font-semibold md:font-bold tracking-wide
                   text-primary-vinotinto
                   transition-colors duration-200
                   hover:text-mono-blanco hover:bg-secondary-vinotinto2/90
                   focus:outline-none focus-visible:ring-2 focus-visible:ring-extra-light">Blog</Link>
            </div>
          </nav>
        </div>

        <div class="shrink-0">
          <Link
            href="/contacto"
            class="inline-flex items-center gap-2 rounded-2xl px-5 py-3
                   bg-primary-naranja text-mono-blanco font-semibold
                   shadow-2xl hover:bg-secondary-naranja2
                   ring-1 ring-black/10 md:ring-black/10
                   transition hover:translate-y-[-1px] active:translate-y-0
                   focus:outline-none focus:ring-2 focus:ring-extra-light"
          >
            <span class="text-lg">↗</span>
            <span>Contáctenos</span>
          </Link>
        </div>
      </div>

      <!-- Móvil -->
      <div class="mt-2 md:hidden">
        <nav
          class="flex items-center gap-2 overflow-x-auto no-scrollbar
                 rounded-2xl bg-white/90 shadow-xl backdrop-blur
                 px-2 py-2 border border-gray-200"
        >
          <template v-for="item in nav" :key="'m-'+item.href">
            <Link
              :href="item.href"
              class="whitespace-nowrap px-3 py-2 rounded-lg text-sm font-semibold
                     text-[#942934] hover:text-white hover:bg-[#d32f57]
                     transition"
            >
              {{ item.label }}
            </Link>
          </template>
        </nav>
      </div>
    </div>
  </header>
</template>
