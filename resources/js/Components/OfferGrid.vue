<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
  blocks: {
    type: Array,
    default: () => ([
      {
        id: 'contratacion',
        icon: 'gavel',
        badge: 'Legal & Normativa',
        title: 'Contratación Estatal',
        summary: 'Domina el SECOP II y blinda jurídicamente tus procesos de compra pública.',
       
        gradient: 'from-blue-600 to-indigo-600',
        shadow: 'shadow-blue-500/20',
        text: 'text-blue-600',
        bg: 'bg-blue-50',
        ctaUrl: '/oferta/contratacion'
      },
      {
        id: 'riesgo',
        icon: 'shield_lock', 
        badge: 'Control Interno',
        title: 'Gestión del Riesgo',
        summary: 'Metodologías MECI y MIPG para entidades seguras y eficientes.',
        gradient: 'from-orange-500 to-red-500',
        shadow: 'shadow-orange-500/20',
        text: 'text-orange-600',
        bg: 'bg-orange-50',
        ctaUrl: '/oferta/control-interno-riesgo'
      },
      {
        id: 'archivo',
        icon: 'inventory_2',
        badge: 'Transparencia',
        title: 'Gestión Documental',
        summary: 'Organización de archivos y estrategias de gobierno abierto.',
        gradient: 'from-emerald-500 to-teal-500',
        shadow: 'shadow-emerald-500/20',
        text: 'text-emerald-600',
        bg: 'bg-emerald-50',
        ctaUrl: '/oferta/archivo'
      },
      {
        id: 'finanzas',
        icon: 'account_balance_wallet',
        badge: 'Hacienda Pública',
        title: 'Finanzas y Presupuesto',
        summary: 'Control fiscal y manejo eficiente de recursos territoriales.',
        gradient: 'from-purple-600 to-fuchsia-600',
        shadow: 'shadow-purple-500/20',
        text: 'text-purple-600',
        bg: 'bg-purple-50',
        ctaUrl: '/oferta/finanzas'
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
  <section ref="rootEl" class="relative w-full py-20 lg:py-32 bg-[#FAFAFA] overflow-hidden">
    
    <div class="absolute top-0 left-0 w-full h-full overflow-hidden pointer-events-none">
      <div class="absolute top-0 right-0 w-[800px] h-[800px] bg-primary-vinotinto/5 rounded-full blur-[120px] -translate-y-1/2 translate-x-1/3"></div>
      <div class="absolute bottom-0 left-0 w-[600px] h-[600px] bg-primary-naranja/5 rounded-full blur-[100px] translate-y-1/3 -translate-x-1/3"></div>
    </div>

    <div class="relative z-10 mx-auto max-w-7xl px-6 lg:px-8">
      
      <div class=" mb-16 lg:mb-24">
        <span 
          class="inline-block py-1 px-3 rounded-full bg-white border border-gray-200 shadow-sm text-xs font-bold uppercase tracking-widest text-gray-500 mb-6 transition-all duration-700 transform"
          :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
        >
          Nuestra Especialidad
        </span>
        <h2 
          class="text-5xl lg:text-6xl font-black text-gray-900 leading-[1.1] mb-6 transition-all duration-700 delay-100 transform"
          :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
        >
          Formación de <span class="text-transparent bg-clip-text bg-gradient-to-r from-primary-vinotinto to-primary-naranja">Alto Impacto</span>.
        </h2>
        <p 
          class="text-lg text-gray-600 leading-relaxed transition-all duration-700 delay-200 transform"
          :class="inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
        >
          Eleva el nivel de tu entidad con programas diseñados para los retos reales del sector público moderno.
        </p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8">
        
        <article 
          v-for="(block, index) in props.blocks" 
          :key="block.id"
          class="group relative h-full bg-white rounded-[2.5rem] p-8 lg:p-10 border border-gray-100 overflow-hidden transition-all duration-500 hover:border-transparent hover:-translate-y-2"
          :class="[
            inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12',
            block.shadow
          ]"
          :style="{ transitionDelay: `${index * 150}ms` }"
        >
          <div 
            class="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500 bg-gradient-to-br"
            :class="[block.gradient, 'bg-opacity-5']"
            style="opacity: 0;" 
          ></div>
          <div class="absolute inset-0 bg-white transition-opacity duration-300 group-hover:opacity-95 z-0"></div>
          <div class="absolute inset-0 bg-gradient-to-br opacity-0 group-hover:opacity-[0.03] transition-opacity duration-500 z-0" :class="block.gradient"></div>


          <div class="relative z-10 flex flex-col h-full">
            
            <div class="flex justify-between items-start mb-8">
              <div 
                class="w-16 h-16 rounded-2xl flex items-center justify-center text-3xl text-white shadow-lg transform transition-transform duration-500 group-hover:scale-110 group-hover:rotate-3"
                :class="`bg-gradient-to-br ${block.gradient}`"
              >
                <span class="material-symbols-rounded">{{ block.icon }}</span>
              </div>

              <div 
                class="w-10 h-10 rounded-full border border-gray-100 flex items-center justify-center text-gray-300 transition-all duration-300 group-hover:bg-black group-hover:border-black group-hover:text-white"
              >
                <span class="material-symbols-rounded text-xl -rotate-45 group-hover:rotate-0 transition-transform duration-300">arrow_forward</span>
              </div>
            </div>

            <div class="flex-grow">
              <span 
                class="inline-block px-3 py-1 rounded-lg text-xs font-bold uppercase tracking-wider mb-4 transition-colors duration-300"
                :class="[block.bg, block.text]"
              >
                {{ block.badge }}
              </span>
              
              <h3 class="text-2xl lg:text-3xl font-bold text-gray-900 mb-4 leading-tight group-hover:text-black transition-colors">
                {{ block.title }}
              </h3>
              
              <p class="text-gray-500 leading-relaxed mb-6 group-hover:text-gray-600">
                {{ block.summary }}
              </p>
            </div>

            <div class="h-1 w-12 rounded-full bg-gray-200 mt-auto transition-all duration-500 group-hover:w-full"
                 :class="`group-hover:bg-gradient-to-r ${block.gradient}`">
            </div>

            <Link :href="block.ctaUrl" class="absolute inset-0 z-20 focus:outline-none focus:ring-4 focus:ring-primary-vinotinto/20 rounded-[2.5rem]"></Link>
          </div>
        </article>

      </div>

    </div>
  </section>
</template>
