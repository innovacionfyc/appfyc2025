<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
  label: { type: String, default: '' },
  icon: { type: String, default: null },
  process: { type: String, default: 'Cargando...' },
  type: { type: String, default: 'button' },
  href: { type: String, default: null },
  target: { type: String, default: null },
  download: { type: Boolean, default: false },
  size: { 
    type: String, 
    default: 'md', 
    validator: (value) => ['sm', 'md', 'lg'].includes(value) 
  },
  iconPosition: { type: String, default: 'left' },
  activeColor: { type: String, default: '#f97316' },
  loading: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
});

const isVisualLoading = computed(() => props.loading || props.disabled);

// Lógica de etiqueta dinámica
const componentTag = computed(() => {
  if (!props.href) return 'button';
  if (props.target === '_blank' || props.download || props.href.startsWith('/storage')) {
    return 'a';
  }
  return Link;
});

const sizeClasses = computed(() => ({
    'px-3 py-1.5 text-xs rounded-lg': props.size === 'sm',
    'px-5 py-2.5 text-sm rounded-xl': props.size === 'md',
    'px-6 py-3 text-base rounded-2xl': props.size === 'lg',
}));

// Estilos dinámicos para la "Cajita"
const boxedStyle = computed(() => {
    const color = props.activeColor || '#f97316';
    return {
        backgroundColor: color + '10', // Fondo con 6% de opacidad
        borderColor: color + '30',     // Borde con 20% de opacidad
        color: color,                  // Texto con el color original
        '--hover-bg': color + '20',    // Variable para el hover
    };
});
</script>

<template>
  <component
    :is="componentTag"
    :href="href"
    :target="target"
    :download="download ? '' : null"
    :disabled="disabled || loading"
    class="relative inline-flex items-center justify-center font-bold border-2 transition-all duration-300 group outline-none active:scale-95 disabled:opacity-50 disabled:grayscale disabled:cursor-not-allowed select-none overflow-hidden"
    :class="sizeClasses"
    :style="boxedStyle"
  >
    <div class="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-300"
         :style="{ backgroundColor: activeColor + '10' }"></div>

    <span 
      class="relative z-10 flex items-center gap-2.5 transition-all duration-300"
      :class="[isVisualLoading ? 'opacity-0 scale-90' : 'opacity-100']"
    >
      <span v-if="icon && iconPosition === 'left'" 
            class="material-symbols-rounded transition-transform duration-300 group-hover:-translate-x-1"
            :style="{ fontSize: '1.3em' }">
        {{ icon }}
      </span>

      <slot>{{ label }}</slot>

      <span v-if="icon && iconPosition === 'right'" 
            class="material-symbols-rounded transition-transform duration-300 group-hover:translate-x-1"
            :style="{ fontSize: '1.3em' }">
        {{ icon }}
      </span>
    </span>

    <div v-if="isVisualLoading" class="absolute inset-0 flex items-center justify-center gap-2 z-20">
      <svg class="animate-spin h-5 w-5" viewBox="0 0 24 24" :style="{ color: activeColor }">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
      </svg>
    </div>
  </component>
</template>

<style scoped>
/* Estilo para que el botón se sienta como una cajita física */
component {
  -webkit-tap-highlight-color: transparent;
}
</style>