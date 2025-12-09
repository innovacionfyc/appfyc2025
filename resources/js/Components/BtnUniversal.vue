<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
  // Contenido
  label: { type: String, default: '' },
  icon: { type: String, default: null }, // Nombre del icono Material Symbols
  
  // Configuración
  type: { type: String, default: 'button' }, // button, submit, reset
  href: { type: String, default: null },     // Si existe, se convierte en <Link>
  
  // Estilos
  variant: { 
    type: String, 
    default: 'primary', 
    validator: (value) => ['primary', 'secondary', 'outline', 'ghost', 'danger', 'white'].includes(value) 
  },
  size: { 
    type: String, 
    default: 'md', 
    validator: (value) => ['sm', 'md', 'lg', 'xl'].includes(value) 
  },
  iconPosition: { type: String, default: 'left' }, // left, right
  
  // Estado
  loading: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
});

// --- LÓGICA DE ESTILOS ---

const baseClasses = "relative inline-flex items-center justify-center font-bold tracking-wide transition-all duration-300 ease-out rounded-2xl overflow-hidden group focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900 disabled:opacity-70 disabled:cursor-not-allowed disabled:transform-none active:scale-[0.96]";

const variants = {
  primary: "bg-gradient-to-br from-primary-naranja to-orange-600 text-white shadow-[0_10px_20px_-10px_rgba(249,115,22,0.6)] hover:shadow-[0_20px_30px_-10px_rgba(249,115,22,0.7)] hover:brightness-110 border border-white/10",
  secondary: "bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-gray-700 hover:bg-gray-200 dark:hover:bg-gray-700 hover:shadow-lg",
  outline: "bg-transparent border-2 border-primary-naranja text-primary-naranja hover:bg-primary-naranja hover:text-white hover:shadow-lg hover:shadow-primary-naranja/30",
  ghost: "bg-transparent text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-primary-naranja",
  danger: "bg-gradient-to-br from-red-500 to-red-600 text-white shadow-lg shadow-red-500/30 hover:shadow-red-500/50 hover:brightness-110",
  white: "bg-white text-gray-900 shadow-xl hover:bg-gray-50 border border-gray-100"
};

const sizes = {
  sm: "px-4 py-1.5 text-xs",
  md: "px-6 py-2.5 text-sm",
  lg: "px-8 py-3.5 text-base",
  xl: "px-10 py-4 text-lg"
};

const iconSizes = {
  sm: "text-base",
  md: "text-lg",
  lg: "text-xl",
  xl: "text-2xl"
};

const classes = computed(() => {
  return `${baseClasses} ${variants[props.variant]} ${sizes[props.size]} ${props.loading ? 'cursor-wait' : ''}`;
});

// Determinar qué componente renderizar
const isLink = computed(() => !!props.href);
const componentTag = computed(() => isLink.value ? Link : 'button');
</script>

<template>
  <component
    :is="componentTag"
    :href="isLink ? href : null"
    :type="!isLink ? type : null"
    :disabled="disabled || loading"
    :class="classes"
  >
    
    <div v-if="['primary', 'danger'].includes(variant) && !loading && !disabled" 
         class="absolute inset-0 -translate-x-full group-hover:animate-[shimmer_1.5s_infinite] bg-gradient-to-r from-transparent via-white/20 to-transparent z-0 pointer-events-none">
    </div>

    <div v-if="['primary'].includes(variant)" 
         class="absolute inset-0 rounded-2xl ring-1 ring-inset ring-white/20 pointer-events-none">
    </div>

    <span class="relative z-10 flex items-center gap-2" :class="{ 'opacity-0': loading }">
      
      <span v-if="icon && iconPosition === 'left'" 
            class="material-symbols-rounded transition-transform group-hover:-translate-x-0.5"
            :class="iconSizes[size]">
        {{ icon }}
      </span>

      <slot>
        {{ label }}
      </slot>

      <span v-if="icon && iconPosition === 'right'" 
            class="material-symbols-rounded transition-transform group-hover:translate-x-0.5"
            :class="iconSizes[size]">
        {{ icon }}
      </span>
    </span>

    <div v-if="loading" class="absolute inset-0 flex items-center justify-center z-20">
      <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
      </svg>
    </div>

  </component>
</template>

<style scoped>
/* Animación del destello de luz */
@keyframes shimmer {
  100% {
    transform: translateX(100%);
  }
}
</style>