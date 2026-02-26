<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
  blocks: {
    type: Array,
    default: () => ([
      {
        id: 'juridica',
        icon: 'gavel',
        badge: 'Formación Jurídica',
        title: 'Programas Jurídicos',
        summary: 'Capacitación especializada en contratación estatal, normatividad pública y seguridad jurídica para las entidades.',
        gradient: 'from-blue-600 to-indigo-600',
        shadow: 'shadow-blue-500/20',
        text: 'text-blue-600',
        bg: 'bg-blue-50',
        ctaUrl: '/oferta/formacion-juridica'
      },
      {
        id: 'talento-humano',
        icon: 'groups',
        badge: 'Talento Humano',
        title: 'Gestión del Talento',
        summary: 'Programas orientados al fortalecimiento de competencias, liderazgo y gestión estratégica del talento humano.',
        gradient: 'from-emerald-500 to-teal-500',
        shadow: 'shadow-emerald-500/20',
        text: 'text-emerald-600',
        bg: 'bg-emerald-50',
        ctaUrl: '/oferta/talento-humano'
      },
      {
        id: 'misionales',
        icon: 'hub',
        badge: 'Enfoques Misionales',
        title: 'Programas Misionales',
        summary: 'Formación alineada con la naturaleza jurídica y las funciones misionales de cada entidad pública.',
        gradient: 'from-orange-500 to-red-500',
        shadow: 'shadow-orange-500/20',
        text: 'text-orange-600',
        bg: 'bg-orange-50',
        ctaUrl: '/oferta/enfoques-misionales'
      },
      {
        id: 'gestion-publica',
        icon: 'policy',
        badge: 'Gestión Pública',
        title: 'Gestión y Políticas Públicas',
        summary: 'Programas estratégicos para la planeación, ejecución y evaluación de políticas públicas con impacto real.',
        gradient: 'from-purple-600 to-fuchsia-600',
        shadow: 'shadow-purple-500/20',
        text: 'text-purple-600',
        bg: 'bg-purple-50',
        ctaUrl: '/oferta/gestion-publica'
      },
      {
        id: 'finanzas',
        icon: 'account_balance_wallet',
        badge: 'Hacienda Pública',
        title: 'Finanzas Públicas',
        summary: 'Capacitación en finanzas, presupuesto y hacienda pública para una gestión fiscal eficiente y transparente.',
        gradient: 'from-amber-500 to-yellow-500',
        shadow: 'shadow-amber-500/20',
        text: 'text-amber-600',
        bg: 'bg-amber-50',
        ctaUrl: '/oferta/finanzas-publicas'
      },
    ])
  }
})

const rootEl = ref(null)
const inView = ref(false)
let observer = null

onMounted(() => {
  observer = new IntersectionObserver(([entry]) => {
    if (entry.isIntersecting) {
      inView.value = true
      observer?.disconnect()
    }
  }, { threshold: 0.1 })
  if (rootEl.value) observer.observe(rootEl.value)
})
onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <section ref="rootEl" class="relative w-full py-14 lg:py-20 bg-[#FAFAFA] overflow-hidden">
    <!-- fondos decorativos (un poquito más compactos) -->
    <div class="absolute top-0 left-0 w-full h-full overflow-hidden pointer-events-none">
      <div class="absolute top-0 right-0 w-[650px] h-[650px] bg-primary-vinotinto/5 rounded-full blur-[110px] -translate-y-1/2 translate-x-1/3"></div>
      <div class="absolute bottom-0 left-0 w-[520px] h-[520px] bg-primary-naranja/5 rounded-full blur-[95px] translate-y-1/3 -translate-x-1/3"></div>
    </div>

    <div class="relative z-10 mx-auto max-w-7xl px-6 lg:px-8">
      <!-- encabezado (menos aire) -->
      <div class="mb-10 lg:mb-12">
        <span
          class="inline-block py-1 px-3 rounded-full bg-white border border-gray-200 shadow-sm text-xs font-bold uppercase tracking-widest text-gray-500 mb-4 transition-all duration-700"
          :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-3'"
        >
          Oferta Académica 2026
        </span>

        <h2
          class="text-4xl lg:text-5xl font-black text-gray-900 leading-[1.08] mb-4 transition-all duration-700 delay-100"
          :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'"
        >
          Programas de
          <span class="text-transparent bg-clip-text bg-gradient-to-r from-primary-vinotinto to-primary-naranja">
            formación especializada
          </span>.
        </h2>

        <p
          class="text-base lg:text-lg text-gray-600 leading-relaxed max-w-3xl transition-all duration-700 delay-200 text-justify"
          :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'"
        >
          Para 2026, segmentamos nuestra oferta académica de acuerdo con la naturaleza jurídica de las entidades
          y sus funciones misionales, integrando temas transversales, especializados y estratégicos.
        </p>
      </div>

      <!-- grid (3 columnas en desktop para bajar altura total) -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 lg:gap-6">
        <article
          v-for="(block, index) in props.blocks"
          :key="block.id"
          class="group relative h-full bg-white rounded-[2.25rem] p-7 lg:p-8 border border-gray-100 overflow-hidden transition-all duration-500 hover:border-transparent hover:-translate-y-1.5"
          :class="[
            inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-10',
            block.shadow
          ]"
          :style="{ transitionDelay: `${index * 120}ms` }"
        >
          <!-- hover glow más sutil -->
          <div
            class="absolute inset-0 bg-gradient-to-br opacity-0 group-hover:opacity-[0.035] transition-opacity duration-500"
            :class="block.gradient"
          ></div>

          <div class="relative z-10 flex flex-col h-full">
            <div class="flex justify-between items-start mb-6">
              <div
                class="w-14 h-14 rounded-2xl flex items-center justify-center text-3xl text-white shadow-lg transform transition-transform duration-500 group-hover:scale-110 group-hover:rotate-3"
                :class="`bg-gradient-to-br ${block.gradient}`"
              >
                <span class="material-symbols-rounded">{{ block.icon }}</span>
              </div>

              <div
                class="w-10 h-10 rounded-full border border-gray-100 flex items-center justify-center text-gray-300 transition-all duration-300 group-hover:bg-black group-hover:border-black group-hover:text-white"
              >
                <span class="material-symbols-rounded text-xl -rotate-45 group-hover:rotate-0 transition-transform duration-300">
                  arrow_forward
                </span>
              </div>
            </div>

            <div class="flex-grow">
              <span
                class="inline-block px-3 py-1 rounded-lg text-xs font-bold uppercase tracking-wider mb-3"
                :class="[block.bg, block.text]"
              >
                {{ block.badge }}
              </span>

              <h3 class="text-xl lg:text-2xl font-bold text-gray-900 mb-3 leading-tight">
                {{ block.title }}
              </h3>

              <p class="text-gray-500 leading-relaxed text-[15px] mb-5 text-justify">
                {{ block.summary }}
              </p>
            </div>

            <div
              class="h-1 w-10 rounded-full bg-gray-200 mt-auto transition-all duration-500 group-hover:w-full"
              :class="`group-hover:bg-gradient-to-r ${block.gradient}`"
            ></div>

            <Link
              :href="block.ctaUrl"
              class="absolute inset-0 z-20 focus:outline-none focus:ring-4 focus:ring-primary-vinotinto/20 rounded-[2.25rem]"
            />
          </div>
        </article>
      </div>
    </div>
  </section>
</template>
