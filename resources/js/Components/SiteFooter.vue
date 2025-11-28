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
  'Contratación y compras públicas',
  'Gestión del riesgo y control interno',
  'Archivo y gestión documental',
  'Finanzas públicas y otros temas'
]

const policies = [
  { label: 'Política de tratamiento de datos', href: '/politica-datos' },
  { label: 'Términos y condiciones', href: '/terminos' },
]
</script>

<template>
  <footer class="mt-0 bg-mono-negro text-mono-blanco/90">
    <!-- línea degradada superior -->
    <div class="h-1 w-full bg-gradient-to-r from-primary-vinotinto via-primary-naranja to-primary-verde"></div>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-10 lg:py-12">
      <div class="grid gap-10 lg:gap-8 lg:grid-cols-12">
        <!-- Columna 1: logo + texto -->
        <div class="lg:col-span-5 space-y-5">
          <div class="flex items-center gap-3">
            <img
              src="/images/logo-fyc.png"
              alt="F&C Consultores"
              class="h-10 w-auto select-none"
              loading="lazy"
            />
            <span class="inline-flex items-center rounded-full bg-primary-vinotinto/15 px-3 py-1 text-xs font-semibold text-primary-naranja">
              Formación y consultoría especializada
            </span>
          </div>

          <p class="text-sm text-mono-blanco/80 leading-relaxed max-w-md">
            Diseñamos y ejecutamos programas académicos especializados para el sector público,
            fortaleciendo las capacidades de los servidores como pilar para construir un mejor país.
          </p>

          <div class="flex flex-wrap gap-3 text-[11px] text-mono-blanco/70">
            <span class="inline-flex items-center gap-1 rounded-full border border-mono-blanco/15 px-3 py-1">
              🎓 Más de 12.000 participantes capacitados
            </span>
            <span class="inline-flex items-center gap-1 rounded-full border border-mono-blanco/15 px-3 py-1">
              🏛️ 200+ entidades atendidas
            </span>
          </div>
        </div>

        <!-- Columna 2: navegación -->
        <div class="lg:col-span-3 space-y-4">
          <h3 class="text-sm font-semibold tracking-wide text-mono-blanco/80 uppercase">
            Navegación
          </h3>
          <ul class="space-y-2 text-sm">
            <li v-for="item in navLinks" :key="item.href">
              <Link
                :href="item.href"
                class="inline-flex items-center gap-2 text-mono-blanco/70 hover:text-mono-blanco transition-colors duration-150"
              >
                <span class="h-1 w-1 rounded-full bg-mono-blanco/40"></span>
                <span>{{ item.label }}</span>
              </Link>
            </li>
          </ul>
        </div>

        <!-- Columna 3: áreas -->
        <div class="lg:col-span-2 space-y-4">
          <h3 class="text-sm font-semibold tracking-wide text-mono-blanco/80 uppercase">
            Áreas de formación
          </h3>
          <ul class="space-y-2 text-sm text-mono-blanco/70">
            <li v-for="area in areas" :key="area" class="flex items-start gap-2">
              <span class="mt-[6px] h-1 w-3 rounded-full bg-primary-naranja/70"></span>
              <span>{{ area }}</span>
            </li>
          </ul>
        </div>

        <!-- Columna 4: contacto -->
        <div class="lg:col-span-2 space-y-4">
          <h3 class="text-sm font-semibold tracking-wide text-mono-blanco/80 uppercase">
            Contáctanos
          </h3>

          <ul class="space-y-2 text-sm text-mono-blanco/75">
            <li class="flex gap-2">
              <span class="mt-[2px]">📞</span>
              <span>+57 (XXX) XXX XX XX</span>
            </li>
            <li class="flex gap-2">
              <span class="mt-[2px]">✉️</span>
              <span>contacto@fycconsultores.com</span>
            </li>
            <li class="flex gap-2">
              <span class="mt-[2px]">📍</span>
              <span>Bogotá D.C. – Colombia</span>
            </li>
          </ul>

          <div class="pt-2 space-y-2">
            <p class="text-xs text-mono-blanco/60">Síguenos</p>
            <div class="flex gap-3 text-lg">
              <!-- Aquí luego puedes cambiar por íconos reales -->
              <a href="#" class="hover:text-primary-naranja transition-colors duration-150">📘</a>
              <a href="#" class="hover:text-primary-naranja transition-colors duration-150">📷</a>
              <a href="#" class="hover:text-primary-naranja transition-colors duration-150">▶️</a>
            </div>
          </div>
        </div>
      </div>

      <!-- Línea divisoria -->
      <div class="mt-8 border-t border-mono-blanco/10 pt-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <p class="text-xs text-mono-blanco/50">
          © {{ year }} F&amp;C Consultores. Todos los derechos reservados.
        </p>

        <div class="flex flex-wrap gap-4 text-[11px] text-mono-blanco/60">
          <Link
            v-for="p in policies"
            :key="p.href"
            :href="p.href"
            class="hover:text-primary-naranja transition-colors duration-150"
          >
            {{ p.label }}
          </Link>
          <span class="hidden md:inline text-mono-blanco/40">•</span>
          <span class="text-mono-blanco/60">
            Hecho con 💛 desde Colombia.
          </span>
        </div>
      </div>
    </div>
  </footer>
</template>
