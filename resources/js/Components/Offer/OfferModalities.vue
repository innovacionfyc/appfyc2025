<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { Link } from '@inertiajs/vue3'

const rootEl = ref(null)
const inView = ref(false)
let observer = null

onMounted(() => {
  observer = new IntersectionObserver(
    ([entry]) => {
      if (entry.isIntersecting) {
        inView.value = true
        observer?.disconnect()
      }
    },
    { threshold: 0.12, rootMargin: '0px 0px -10% 0px' }
  )

  if (rootEl.value) observer.observe(rootEl.value)
})

onBeforeUnmount(() => observer?.disconnect())

/**
 * ORDEN:
 * 1. Formación abierta
 * 2. Formación In-Company
 * 3. Asesorías / Consultorías
 */
const cards = [
  {
    id: 'abiertos',
    icon: 'groups',
    badge: 'Nuestras líneas de negocio',
    title: 'Formación abierta',
    summary:
      'Cada año diseñamos y ejecutamos cerca de 100 procesos de formación para entidades y empresas públicas, en modalidades virtual, presencial e híbrida.',
    detail:
      'Ofrecemos diplomados, congresos, seminarios, cursos, jornadas y módulos de actualización, desarrollados con contenidos útiles, prácticos y actuales, orientados a cualificar a los servidores públicos y fortalecer sus competencias para el aprendizaje continuo.',
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
    badge: 'Nuestras líneas de negocio',
    title: 'Formación In-Company',
    summary:
      'Creamos programas a la medida que responden a las necesidades reales de cada entidad.',
    detail:
      'Optimizamos recursos y garantizamos soluciones formativas pertinentes, prácticas y efectivas, alineadas con los objetivos institucionales.',
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
    badge: 'Nuestras líneas de negocio',
    title: 'Asesorías, consultorías y acompañamientos técnicos',
    summary:
      'Nuestro equipo de 130 consultores cuenta con más de 15 años de experiencia en el sector público.',
    detail:
      'Incluye expertos que han participado en la creación de las principales políticas y lineamientos del país. Ofrecemos diagnóstico, acompañamiento e implementación para garantizar el cumplimiento de los resultados institucionales.',
    gradient: 'from-secondary-verde2 to-primary-verde',
    shadow: 'shadow-[0_20px_60px_-15px_rgba(160,142,67,0.22)]',
    text: 'text-secondary-verde2',
    bg: 'bg-secondary-verde2/10',
    cta: 'Hablar con un asesor',
    ctaUrl: '/contacto'
  }
]
</script>

<template>
  <section ref="rootEl" class="relative w-full py-20 lg:py-28 bg-[#FAFAFA] overflow-hidden">

    <!-- Fondo -->
    <div class="absolute inset-0 pointer-events-none">
      <div class="absolute top-0 right-0 w-[800px] h-[800px] bg-primary-vinotinto/5 rounded-full blur-[120px] -translate-y-1/2 translate-x-1/3"></div>
      <div class="absolute bottom-0 left-0 w-[600px] h-[600px] bg-primary-naranja/5 rounded-full blur-[100px] translate-y-1/3 -translate-x-1/3"></div>
      <div class="absolute inset-0 bg-[url('/images/grid-pattern.png')] opacity-[0.03]"></div>
    </div>

    <div class="relative z-10 mx-auto max-w-7xl px-6 lg:px-8">

      <!-- Header -->
      <div class="mb-16 lg:mb-24 max-w-3xl">
        <span
          class="inline-block py-1 px-3 rounded-full bg-white border border-gray-200 shadow-sm text-xs font-bold uppercase tracking-widest text-gray-500 mb-6 transition-all duration-700"
          :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
        >
          Nuestras líneas de negocio
        </span>

        <h2
          class="text-5xl lg:text-6xl font-black text-gray-900 leading-[1.1] mb-6 transition-all duration-700 delay-100"
          :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
        >
          Modalidades de
          <span class="text-transparent bg-clip-text bg-gradient-to-r from-primary-vinotinto to-primary-naranja">
            Alto Impacto
          </span>
        </h2>

        <p
          class="text-lg text-gray-600 leading-relaxed transition-all duration-700 delay-200"
          :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
        >
          Formación abierta, programas In-Company y acompañamiento especializado para entidades del sector público y mixto.
        </p>
      </div>

      <!-- Cards -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">
        <article
          v-for="(card, index) in cards"
          :key="card.id"
          class="group relative h-full bg-white rounded-[2.5rem] p-8 lg:p-10 border border-gray-100
                 overflow-visible transition-all duration-500 hover:-translate-y-2"
          :class="[
            inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12',
            card.shadow
          ]"
          :style="{ transitionDelay: `${index * 150}ms` }"
        >
          <!-- overlays -->
          <div class="absolute inset-0 bg-white transition-opacity duration-300 group-hover:opacity-95 z-0"></div>
          <div class="absolute inset-0 bg-gradient-to-br opacity-0 group-hover:opacity-[0.03] transition-opacity duration-500 z-0"
               :class="card.gradient"></div>

          <!-- Icono flotante -->
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

          <div class="relative z-10 flex flex-col h-full pt-6">

            <div class="flex justify-end mb-6">
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

            <span
              class="inline-block px-3 py-1 rounded-lg text-xs font-bold uppercase tracking-wider mb-4"
              :class="[card.bg, card.text]"
            >
              {{ card.badge }}
            </span>

            <h3 class="text-2xl lg:text-3xl font-bold text-gray-900 mb-4 leading-tight">
              {{ card.title }}
            </h3>

            <p class="text-gray-500 leading-relaxed mb-4">
              {{ card.summary }}
            </p>

            <p class="text-gray-500 leading-relaxed mb-6">
              {{ card.detail }}
            </p>

            <div class="h-1 w-12 rounded-full bg-gray-200 mt-auto transition-all duration-500 group-hover:w-full"
                 :class="`group-hover:bg-gradient-to-r ${card.gradient}`"></div>

            <div class="pt-6 relative z-30">
              <span class="font-bold" :class="card.text">{{ card.cta }}</span>
            </div>

            <Link
              :href="card.ctaUrl"
              class="absolute inset-0 z-20 rounded-[2.5rem]"
              aria-label="Ir"
            />
          </div>
        </article>
      </div>

    </div>
  </section>
</template>
