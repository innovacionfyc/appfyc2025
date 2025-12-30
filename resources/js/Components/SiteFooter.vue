<script setup>
import { Link } from '@inertiajs/vue3'

const year = new Date().getFullYear()

const navLinks = [
  { label: 'Inicio', href: '/' },
  { label: 'Oferta académica', href: '/oferta' },
  { label: 'Nosotros', href: '/nosotros' },
  { label: 'Responsabilidad social', href: '/responsabilidad-social' },
  { label: 'Blog', href: '/blog' },
]

const areas = [
  'Formación jurídica',
  'Talento humano',
  'Gestión y políticas públicas',
  'Finanzas y hacienda pública',
  'Enfoques misionales'
]

const policies = [
  { label: 'Política de tratamiento de datos', href: '/politica-datos' },
  { label: 'Términos y condiciones', href: '/terminos' },
]
</script>

<template>
  <footer class="bg-mono-negro text-mono-blanco/90">
    <!-- Línea superior -->
    <div class="h-[3px] w-full bg-gradient-to-r from-primary-vinotinto via-primary-naranja to-primary-verde"></div>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 lg:py-8">
      <div class="grid gap-6 lg:grid-cols-12">

        <!-- LOGO + DESCRIPCIÓN -->
        <div class="lg:col-span-5 space-y-3">
          <div class="flex items-center gap-3">
            <img
              src="/images/logo-fyc.png"
              alt="F&C Consultores"
              class="h-9 w-auto"
              loading="lazy"
            />
            <span class="inline-flex items-center rounded-full bg-primary-vinotinto/15 px-3 py-1 text-xs font-semibold text-primary-naranja">
              Formación especializada
            </span>
          </div>

          <p class="text-sm text-mono-blanco/75 leading-relaxed max-w-md">
            Somos aliados estratégicos en la generación y gestión del conocimiento,
            diseñando programas académicos para fortalecer el sector público.
          </p>

          <div class="flex flex-wrap gap-2 text-[11px] text-mono-blanco/65">
            <span class="inline-flex items-center gap-1 rounded-full border border-mono-blanco/15 px-3 py-1">
              🎓 +160.000 servidores formados
            </span>
            <span class="inline-flex items-center gap-1 rounded-full border border-mono-blanco/15 px-3 py-1">
              🏛️ Entidades públicas y mixtas
            </span>
          </div>
        </div>

        <!-- NAVEGACIÓN -->
        <div class="lg:col-span-3 space-y-3">
          <h3 class="text-xs font-semibold tracking-widest uppercase text-mono-blanco/70">
            Navegación
          </h3>
          <ul class="space-y-1.5 text-sm">
            <li v-for="item in navLinks" :key="item.href">
              <Link
                :href="item.href"
                class="inline-flex items-center gap-2 text-mono-blanco/70 hover:text-primary-naranja transition-colors"
              >
                <span class="h-1 w-1 rounded-full bg-mono-blanco/40"></span>
                {{ item.label }}
              </Link>
            </li>
          </ul>
        </div>

        <!-- ÁREAS -->
        <div class="lg:col-span-2 space-y-3">
          <h3 class="text-xs font-semibold tracking-widest uppercase text-mono-blanco/70">
            Áreas 2026
          </h3>
          <ul class="space-y-1.5 text-sm text-mono-blanco/70">
            <li v-for="area in areas" :key="area" class="flex items-start gap-2">
              <span class="mt-[6px] h-1 w-3 rounded-full bg-primary-naranja/70"></span>
              <span>{{ area }}</span>
            </li>
          </ul>
        </div>

        <!-- CONTACTO -->
        <div class="lg:col-span-2 space-y-3">
          <h3 class="text-xs font-semibold tracking-widest uppercase text-mono-blanco/70">
            Contáctenos
          </h3>

          <ul class="space-y-1.5 text-sm text-mono-blanco/75">
            <li class="flex gap-2">
              <span>🌐</span>
              <span>www.fycconsultores.com</span>
            </li>
            <li class="flex gap-2">
              <span>✉️</span>
              <span>contactenos@fycconsultores.com</span>
            </li>
            <li class="flex gap-2">
              <span>📞</span>
              <span>(57) 316 386 1932<br />(57) 317 635 3574</span>
            </li>
            <li class="flex gap-2">
              <span>📍</span>
              <span>
                Carrera 46 #22B-20, Of. 602<br />
                Bogotá D.C., Colombia
              </span>
            </li>
          </ul>
        </div>

      </div>

      <!-- FOOTER BOTTOM -->
      <div class="mt-5 border-t border-mono-blanco/10 pt-3 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <p class="text-xs text-mono-blanco/50">
          © {{ year }} F&amp;C Consultores. Todos los derechos reservados.
        </p>

        <div class="flex flex-wrap gap-3 text-[11px] text-mono-blanco/60">
          <Link
            v-for="p in policies"
            :key="p.href"
            :href="p.href"
            class="hover:text-primary-naranja transition-colors"
          >
            {{ p.label }}
          </Link>
          <span class="hidden md:inline text-mono-blanco/40">•</span>
          <span>Hecho con 💛 desde Colombia</span>
        </div>
      </div>
    </div>
  </footer>
</template>
