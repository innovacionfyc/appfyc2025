<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { Link } from '@inertiajs/vue3'

const rootEl = ref(null)
const inView = ref(false)
let observer = null

onMounted(() => {
  observer = new IntersectionObserver(([entry]) => {
    if (entry.isIntersecting) {
      inView.value = true
      observer?.disconnect()
    }
  }, { threshold: 0.12, rootMargin: '0px 0px -10% 0px' })

  if (rootEl.value) observer.observe(rootEl.value)
})

onBeforeUnmount(() => observer?.disconnect())

/**
 * ✅ ORDEN: Abiertos -> In-company -> Asesorías / Consultorías
 */
const cards = [
  {
    id: 'abiertos',
    icon: 'groups',
    badge: 'Eventos y formación',
    title: 'Abiertos',
    summary:
      'Congresos, seminarios, diplomados, talleres y encuentros con contenidos especializados en ciudades principales.',
    detail:
      'Orientados a fortalecer conocimientos y habilidades, con enfoque en el servidor público y el impacto en productividad.',
    gradient: 'from-primary-naranja to-secondary-naranja2',
    shadow: 'shadow-[0_20px_60px_-15px_rgba(233,101,16,0.20)]',
    text: 'text-primary-naranja',
    bg: 'bg-primary-naranja/10',
    cta: 'Ver agenda',
    ctaUrl: '/contacto'
  },
  {
    id: 'incompany',
    icon: 'domain',
    badge: 'Capacitación a la medida',
    title: 'In-company',
    summary:
      'Capacitación laboral en la sede del cliente, con logística y calidad académica, diseñada exactamente para su necesidad.',
    detail:
      'Minimiza impactos por movilidad o carga laboral, y se enfoca en objetivos específicos definidos por cada entidad.',
    gradient: 'from-primary-vinotinto to-primary-naranja',
    shadow: 'shadow-[0_20px_60px_-15px_rgba(148,41,52,0.18)]',
    text: 'text-primary-vinotinto',
    bg: 'bg-primary-vinotinto/10',
    cta: 'Solicitar propuesta',
    ctaUrl: '/contacto'
  },
  {
    id: 'consultoria',
    icon: 'handshake',
    badge: 'Acompañamiento estratégico',
    title: 'Asesorías, consultorías y acompañamientos',
    summary:
      'Apoyo técnico y estratégico para fortalecer procesos institucionales, cumplimiento normativo y mejora continua.',
    detail:
      'Acompañamiento cercano adaptado a los retos de cada organización: decisiones, gestión del conocimiento y resultados.',
    gradient: 'from-secondary-verde2 to-primary-verde',
    shadow: 'shadow-[0_20px_60px_-15px_rgba(160,142,67,0.22)]',
    text: 'text-secondary-verde2',
    bg: 'bg-secondary-verde2/10',
    cta: 'Hablar con un asesor',
    ctaUrl: '/contacto'
  },
]
</script>

<template>
  <section ref="rootEl" class="relative w-full py-20 lg:py-28 bg-[#FAFAFA] overflow-hidden">

    <!-- Fondo blobs igual al Home -->
    <div class="absolute top-0 left-0 w-full h-full overflow-hidden pointer-events-none">
      <div class="absolute top-0 right-0 w-[800px] h-[800px] bg-primary-vinotinto/5 rounded-full blur-[120px] -translate-y-1/2 translate-x-1/3"></div>
      <div class="absolute bottom-0 left-0 w-[600px] h-[600px] bg-primary-naranja/5 rounded-full blur-[100px] translate-y-1/3 -translate-x-1/3"></div>
      <div class="absolute inset-0 bg-[url('/images/grid-pattern.svg')] opacity-[0.03]"></div>
    </div>

    <div class="relative z-10 mx-auto max-w-7xl px-6 lg:px-8">

      <!-- Título estilo OfferGrid -->
      <div class="mb-16 lg:mb-24">
        <span
          class="inline-block py-1 px-3 rounded-full bg-white border border-gray-200 shadow-sm text-xs font-bold uppercase tracking-widest text-gray-500 mb-6 transition-all duration-700 transform"
          :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
        >
          Oferta Académica
        </span>

        <h2
          class="text-5xl lg:text-6xl font-black text-gray-900 leading-[1.1] mb-6 transition-all duration-700 delay-100 transform"
          :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
        >
          Modalidades de <span class="text-transparent bg-clip-text bg-gradient-to-r from-primary-vinotinto to-primary-naranja">Alto Impacto</span>.
        </h2>

        <p
          class="text-lg text-gray-600 leading-relaxed transition-all duration-700 delay-200 transform max-w-3xl"
          :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
        >
          Programas diseñados para ajustarse a la realidad operativa de cada entidad: formación a la medida, eventos abiertos y acompañamiento especializado.
        </p>
      </div>

      <!-- Cards estilo OfferGrid -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">
        <article
          v-for="(card, index) in cards"
          :key="card.id"
          class="group relative h-full bg-white rounded-[2.5rem] p-8 lg:p-10 border border-gray-100
                 overflow-visible transition-all duration-500 hover:border-transparent hover:-translate-y-2"
          :class="[
            inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12',
            card.shadow
          ]"
          :style="{ transitionDelay: `${index * 150}ms` }"
        >
          <!-- overlays iguales -->
          <div
            class="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500 bg-gradient-to-br z-0"
            :class="[card.gradient, 'bg-opacity-5']"
            style="opacity: 0;"
          ></div>

          <div class="absolute inset-0 bg-white transition-opacity duration-300 group-hover:opacity-95 z-0"></div>
          <div class="absolute inset-0 bg-gradient-to-br opacity-0 group-hover:opacity-[0.03] transition-opacity duration-500 z-0" :class="card.gradient"></div>

          <!-- ✅ ICONO FLOTANTE (ahora sí encima y sin recorte) -->
          <div class="absolute -top-7 left-8 z-[60] pointer-events-none">
            <div
              class="w-16 h-16 rounded-2xl flex items-center justify-center text-3xl text-white shadow-lg
                     transform transition-transform duration-500
                     group-hover:scale-110 group-hover:rotate-3"
              :class="`bg-gradient-to-br ${card.gradient}`"
            >
              <span class="material-symbols-rounded">{{ card.icon }}</span>
            </div>
          </div>

          <div class="relative z-10 flex flex-col h-full">

            <!-- ✅ header con respiración arriba -->
            <div class="flex justify-end items-start mb-8 pt-6">
              <div
                class="w-10 h-10 rounded-full border border-gray-100 flex items-center justify-center
                       text-gray-300 transition-all duration-300
                       group-hover:bg-black group-hover:border-black group-hover:text-white"
              >
                <span class="material-symbols-rounded text-xl -rotate-45 group-hover:rotate-0 transition-transform duration-300">
                  arrow_forward
                </span>
              </div>
            </div>

            <div class="flex-grow">
              <span
                class="inline-block px-3 py-1 rounded-lg text-xs font-bold uppercase tracking-wider mb-4 transition-colors duration-300"
                :class="[card.bg, card.text]"
              >
                {{ card.badge }}
              </span>

              <h3 class="text-2xl lg:text-3xl font-bold text-gray-900 mb-4 leading-tight group-hover:text-black transition-colors">
                {{ card.title }}
              </h3>

              <p class="text-gray-500 leading-relaxed mb-4 group-hover:text-gray-600">
                {{ card.summary }}
              </p>

              <p class="text-gray-500 leading-relaxed mb-6 group-hover:text-gray-600">
                {{ card.detail }}
              </p>
            </div>

            <div
              class="h-1 w-12 rounded-full bg-gray-200 mt-auto transition-all duration-500 group-hover:w-full"
              :class="`group-hover:bg-gradient-to-r ${card.gradient}`"
            ></div>

            <!-- ✅ CTA textual: fuerza color y z-index -->
            <div class="pt-6 relative z-30">
              <span class="font-bold" :class="card.text">{{ card.cta }}</span>
            </div>

            <!-- ✅ overlay clickeable (queda arriba pero no rompe color del CTA por z-30) -->
            <Link
              :href="card.ctaUrl"
              class="absolute inset-0 z-20 focus:outline-none focus:ring-4 focus:ring-primary-vinotinto/20 rounded-[2.5rem]"
              aria-label="Ir"
            ></Link>

          </div>
        </article>
      </div>

    </div>
  </section>
</template>
