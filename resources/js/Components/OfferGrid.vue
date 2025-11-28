<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
  blocks: {
    type: Array,
    default: () => ([
      {
        id: 'contratacion',
        badge: 'Contratación y compras públicas',
        title: 'Contratación estatal y régimen jurídico',
        level: 'Nivel intermedio / avanzado',
        summary: 'Seminarios, diplomados y talleres prácticos sobre SECOP, planeación contractual y manejo de riesgos en la contratación estatal.',
        color: 'from-primary-vinotinto via-primary-naranja to-primary-verde',
        ctaUrl: '/oferta/contratacion'
      },
      {
        id: 'riesgo',
        badge: 'Gestión del riesgo y control interno',
        title: 'Control interno y gestión de riesgos',
        level: 'Aplicado al sector público',
        summary: 'Programas orientados al fortalecimiento del MECI, MIPG, mapas de riesgos y control interno contable y auditoría.',
        color: 'from-primary-naranja via-primary-vinotinto to-primary-verde',
        ctaUrl: '/oferta/control-interno-riesgo'
      },
      {
        id: 'archivo',
        badge: 'Archivo y gestión documental',
        title: 'Archivo, transparencia y gobierno abierto',
        level: 'Normativa y práctica',
        summary: 'Capacitaciones en archivo, gestión documental, transparencia, acceso a la información y rendición de cuentas.',
        color: 'from-primary-verde via-primary-naranja to-primary-vinotinto',
        ctaUrl: '/oferta/archivo'
      },
      {
        id: 'finanzas',
        badge: 'Finanzas públicas y otros temas',
        title: 'Finanzas públicas y temática especializada',
        level: 'Programas a la medida',
        summary: 'Espacios académicos en finanzas públicas, presupuesto, control fiscal y temas especializados según las necesidades de cada entidad.',
        color: 'from-primary-vinotinto via-primary-verde to-primary-naranja',
        ctaUrl: '/oferta/finanzas'
      },
    ])
  }
})

// animación al entrar en viewport
const rootEl = ref(null)
const inView = ref(false)
let observer = null

// refs para mobile
const mobileScroll = ref(null)
const showScrollHint = ref(true)

onMounted(() => {
  // IntersectionObserver para fade-up
  observer = new IntersectionObserver(
    ([entry]) => {
      if (entry.isIntersecting) {
        inView.value = true
        if (observer) observer.disconnect()
      }
    },
    { threshold: 0.15, rootMargin: '0px 0px -10% 0px' }
  )

  if (rootEl.value) {
    observer.observe(rootEl.value)
  }

  // pequeño "nudge" al carrusel mobile para indicar que se puede deslizar
  if (mobileScroll.value) {
    setTimeout(() => {
      mobileScroll.value.scrollTo({ left: 24, behavior: 'smooth' })
      setTimeout(() => {
        mobileScroll.value.scrollTo({ left: 0, behavior: 'smooth' })
      }, 400)
    }, 500)
  }

  // ocultar el texto "desliza →" después de unos segundos
  if (showScrollHint.value) {
    setTimeout(() => {
      showScrollHint.value = false
    }, 3500)
  }
})

onBeforeUnmount(() => {
  if (observer) observer.disconnect()
})
</script>

<template>
  <section ref="rootEl" class="relative w-full py-10 md:py-14">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

      <!-- GRID DESKTOP / TABLET -->
      <div
        class="hidden md:grid md:grid-cols-2 gap-6 lg:gap-8"
        :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
        style="transition: all .6s ease"
      >
        <article
          v-for="block in props.blocks"
          :key="block.id"
          class="group relative rounded-3xl bg-white shadow-lg ring-1 ring-black/5 overflow-hidden transition-all duration-300 hover:-translate-y-1 hover:shadow-2xl"
        >
          <!-- borde superior degradado -->
          <div class="h-1 w-full bg-gradient-to-r" :class="block.color"></div>

          <div class="p-6 lg:p-7 space-y-3">
            <!-- categoría -->
            <div class="flex items-center justify-between text-[11px] uppercase tracking-wide">
              <p class="font-semibold text-primary-vinotinto/80">
                {{ block.badge }}
              </p>
              <span
                class="inline-flex h-8 w-8 items-center justify-center rounded-2xl bg-primary-vinotinto/5 text-primary-vinotinto text-xs font-semibold"
              >
                {{ (block.id || '').charAt(0).toUpperCase() || 'F' }}
              </span>
            </div>

            <!-- título -->
            <h3 class="text-xl lg:text-2xl font-extrabold text-mono-negro leading-snug">
              {{ block.title }}
            </h3>

            <!-- nivel -->
            <p class="text-xs font-semibold text-primary-vinotinto/80">
              {{ block.level }}
            </p>

            <!-- descripción -->
            <p class="text-sm text-mono-negro/70 leading-relaxed">
              {{ block.summary }}
            </p>

            <!-- footer -->
            <div class="pt-4 flex items-center justify-between gap-4">
              <Link
                :href="block.ctaUrl"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-vinotinto px-5 py-2.5 text-sm font-semibold text-white shadow-md transition-all duration-200 hover:bg-secondary-vinotinto2 hover:shadow-lg"
              >
                Ver programas
                <span class="text-base group-hover:translate-x-0.5 transition-transform">→</span>
              </Link>

              <button
                type="button"
                class="text-xs font-medium text-mono-negro/60 inline-flex items-center gap-1 hover:text-primary-vinotinto"
              >
                Ver detalle rápido
                <span>↗</span>
              </button>
            </div>
          </div>
        </article>
      </div>

      <!-- MOBILE: carrusel horizontal con indicador de scroll -->
      <div
        class="mt-4 md:hidden relative"
        :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-3'"
        style="transition: all .6s ease .1s"
      >
        <!-- Indicador "desliza →" -->
        <div
          v-if="showScrollHint"
          class="absolute right-4 top-4 z-20 pointer-events-none"
        >
          <div class="flex items-center gap-1 text-primary-vinotinto/70 text-xs animate-pulse">
            desliza
            <span class="text-lg">→</span>
          </div>
        </div>

        <!-- sombras laterales -->
        <div class="absolute left-0 top-0 h-full w-10 bg-gradient-to-r from-mono-blanco to-transparent pointer-events-none z-10"></div>
        <div class="absolute right-0 top-0 h-full w-10 bg-gradient-to-l from-mono-blanco to-transparent pointer-events-none z-10"></div>

        <div
          ref="mobileScroll"
          class="flex gap-4 overflow-x-auto no-scrollbar snap-x snap-mandatory px-1 pb-3"
          style="scroll-behavior: smooth;"
        >
          <article
            v-for="block in props.blocks"
            :key="'m-' + block.id"
            class="snap-center flex-shrink-0 w-[86%] rounded-2xl bg-white shadow-lg ring-1 ring-black/5 overflow-hidden transition-all duration-300 hover:-translate-y-1"
          >
            <!-- borde superior degradado -->
            <div class="h-1 w-full bg-gradient-to-r" :class="block.color"></div>

            <div class="p-5 space-y-3">
              <p class="text-[11px] uppercase font-semibold text-primary-vinotinto/80 tracking-wide">
                {{ block.badge }}
              </p>

              <h3 class="text-lg font-extrabold text-mono-negro leading-snug">
                {{ block.title }}
              </h3>

              <p class="text-xs font-semibold text-primary-vinotinto/80">
                {{ block.level }}
              </p>

              <p class="text-sm text-mono-negro/70 line-clamp-4">
                {{ block.summary }}
              </p>

              <div class="pt-3 flex items-center justify-between">
                <Link
                  :href="block.ctaUrl"
                  class="inline-flex items-center gap-2 rounded-xl bg-primary-vinotinto px-4 py-2 text-xs font-semibold text-white shadow-md hover:bg-secondary-vinotinto2 transition"
                >
                  Ver programas →
                </Link>
              </div>
            </div>
          </article>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar {
  display: none;
}
.no-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>
