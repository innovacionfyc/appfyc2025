<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
  // Contenido
  label: { type: String, default: '' },
  icon: { type: String, default: null },
  process: { type: String, default: 'Procesando...' }, // NUEVO: Prop para el texto de carga
  
  // Configuración
  type: { type: String, default: 'button' },
  href: { type: String, default: null },
  
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
  iconPosition: { type: String, default: 'left' },
  
  // DINÁMICO
  activeColor: { type: String, default: null },

  // Estado
  loading: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
});

// --- LÓGICA DE ESTILOS ---

// Se añadió 'w-full' para que siempre ocupe el ancho disponible
const baseClasses = `
  relative w-full inline-flex items-center justify-center font-bold tracking-wide 
  transition-all duration-300 ease-[cubic-bezier(0.4,0,0.2,1)]
  rounded-2xl overflow-hidden group focus:outline-none focus:ring-4 
  disabled:cursor-not-allowed disabled:transform-none
  active:scale-[0.98] hover:-translate-y-0.5
`;

const variants = {
  primary: "bg-gradient-to-br from-primary-naranja to-orange-600 text-white shadow-md shadow-orange-500/20 hover:shadow-lg hover:shadow-orange-500/30 border border-white/10",
  secondary: "bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 hover:bg-slate-200 dark:hover:bg-slate-700",
  outline: "bg-transparent border-2 border-primary-naranja text-primary-naranja hover:bg-primary-naranja hover:text-white hover:shadow-md hover:shadow-primary-naranja/20",
  ghost: "bg-transparent text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-white/5 hover:text-primary-naranja",
  danger: "bg-gradient-to-br from-red-500 to-red-600 text-white shadow-md shadow-red-500/20 hover:shadow-lg hover:shadow-red-500/30",
  white: "bg-white text-slate-900 shadow-sm hover:shadow-md border border-slate-100 hover:bg-slate-50"
};

const sizes = {
  sm: "px-4 py-1.5 text-xs",
  md: "px-6 py-2.5 text-sm",
  lg: "px-8 py-3.5 text-base",
  xl: "px-10 py-4 text-lg"
};

const iconSizes = { sm: "text-base", md: "text-lg", lg: "text-xl", xl: "text-2xl" };

// Detectamos si el botón está en estado de "espera" (ya sea por prop loading o disabled)
const isVisualLoading = computed(() => props.loading || props.disabled);

const classes = computed(() => {
  let variantClass = variants[props.variant];
  if (props.activeColor && props.variant === 'primary') {
    variantClass = variantClass
      .replace(/bg-gradient-to-br|from-primary-naranja|to-orange-600/g, '')
      .replace(/shadow-.*?/g, ''); 
  }
  // Añadimos opacidad reducida si está cargando
  return `${baseClasses} ${variantClass} ${sizes[props.size]} ${isVisualLoading.value ? 'cursor-wait opacity-80' : ''}`;
});

const dynamicStyle = computed(() => {
  if (!props.activeColor || props.variant !== 'primary') return {};
  return {
    background: props.activeColor,
    boxShadow: props.disabled ? 'none' : `0 8px 20px -6px ${props.activeColor}70`,
    '--tw-ring-color': `${props.activeColor}50`,
  };
});

const isLink = computed(() => !!props.href);
</script>

<template>
  <component
    :is="isLink ? Link : 'button'"
    :href="isLink ? href : null"
    :type="!isLink ? type : null"
    :disabled="disabled || loading"
    :class="classes"
    :style="dynamicStyle"
  >
    <div class="absolute inset-0 bg-white opacity-0 group-hover:opacity-[0.15] transition-opacity duration-300 ease-out pointer-events-none z-0"></div>

    <div v-if="['primary'].includes(variant)" 
         class="absolute inset-0 rounded-2xl ring-1 ring-inset ring-white/20 pointer-events-none z-10">
    </div>

    <span class="relative z-20 flex items-center gap-2.5 transition-all duration-300" 
          :class="{ 'opacity-0 scale-95': isVisualLoading }">
      
      <span v-if="icon && iconPosition === 'left'" 
            class="material-symbols-rounded transition-transform duration-300 group-hover:-translate-x-0.5"
            :class="iconSizes[size]">
        {{ icon }}
      </span>

      <slot>
        <span class="font-bold tracking-wide drop-shadow-sm">{{ label }}</span>
      </slot>

      <span v-if="icon && iconPosition === 'right'" 
            class="material-symbols-rounded transition-transform duration-300 group-hover:translate-x-0.5"
            :class="iconSizes[size]">
        {{ icon }}
      </span>
    </span>

    <div v-if="isVisualLoading" class="absolute inset-0 flex items-center justify-center gap-3 z-30 animate-in fade-in zoom-in duration-300">
      <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" 
           :class="['primary', 'danger'].includes(variant) ? 'text-white' : 'text-primary-naranja'">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
      </svg>
      <span class="text-sm font-bold tracking-wide" :class="['primary', 'danger'].includes(variant) ? 'text-white' : 'text-slate-700'">
        {{ process }}
      </span>
    </div>

  </component>
</template>