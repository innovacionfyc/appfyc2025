<script setup>
import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";

const props = defineProps({
  usuario: { type: Object, default: null },
  label: { type: String, required: true },
  icon: { type: String, default: "arrow_forward" },
  process: { type: String, default: "Procesando..." },
  disabled: { type: Boolean, default: false },
  activeColor: { type: String, default: null }, // Recibe el hexadecimal
  size: { type: String, default: 'md' },
  type: { type: String, default: 'button' }
});

const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user || !!props.usuario);

// Calculamos el color base con fallbacks
const buttonColor = computed(() => {
  if (props.activeColor) return props.activeColor;
  return isAuthenticated.value ? '#E11D48' : '#F97316'; 
});

const sizeClasses = computed(() => {
  return props.size === 'lg' ? 'px-10 py-4 text-base' : 'px-5 py-2.5 text-sm';
});
</script>

<template>
  <div class="flex justify-center items-center w-full group/btn-container">
    <button 
      :type="type"
      :disabled="disabled" 
      class="
        relative w-full flex items-center justify-center gap-3
        rounded-2xl font-bold uppercase tracking-wider
        transition-all duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)]
        focus:outline-none focus:ring-4
        active:scale-95
        disabled:cursor-not-allowed disabled:opacity-50 disabled:grayscale
        text-white overflow-hidden
      " 
      :class="sizeClasses"
      :style="{ 
        /* Forzamos el background para sobreescribir cualquier degradado o clase previa */
        background: buttonColor,
        '--tw-ring-color': buttonColor + '40',
        boxShadow: disabled ? 'none' : `0 15px 30px -10px ${buttonColor}80`
      }"
    >
      <div class="absolute inset-0 bg-white/10 opacity-0 group-hover/btn-container:opacity-100 transition-opacity pointer-events-none"></div>

      <Transition name="btn-fade" mode="out-in">
        <div v-if="disabled" class="flex items-center gap-3" key="processing">
          <svg class="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-100" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span class="font-bold">{{ process }}</span>
        </div>

        <div v-else class="flex items-center gap-2" key="active">
          <span class="relative z-10">{{ label }}</span>
          <span v-if="icon" class="material-symbols-rounded text-[22px] leading-none transition-transform duration-300 group-hover/btn-container:translate-x-1">
            {{ icon }}
          </span>
        </div>
      </Transition>
    </button>
  </div>
</template>

<style scoped>
.btn-fade-enter-active, .btn-fade-leave-active { transition: all 0.2s ease; }
.btn-fade-enter-from { opacity: 0; transform: translateY(5px); }
.btn-fade-leave-to { opacity: 0; transform: translateY(-5px); }
</style>