<script setup>
import { computed } from 'vue';

const props = defineProps({
  cart: { type: Array, default: () => [] },
  total: { type: Number, default: 0 },
  isAnimating: { type: Boolean, default: false }
});

const emit = defineEmits(['open-cart']);

const formatCurrency = (value) => {
  return new Intl.NumberFormat('es-CO', { 
    style: 'currency', 
    currency: 'COP', 
    minimumFractionDigits: 0, 
    maximumFractionDigits: 0 
  }).format(value);
};
</script>

<template>
  <Transition name="pop">
    <div v-if="cart.length > 0"
         class="fixed bottom-4 sm:bottom-6 left-4 right-4 sm:left-auto sm:right-8 flex justify-center sm:justify-end z-[999] pointer-events-none">
      <button 
        @click="$emit('open-cart')"
        class="pointer-events-auto w-full sm:w-auto group flex items-center justify-between sm:justify-start gap-4 bg-gray-900 dark:bg-white text-white dark:text-gray-900 py-3 px-4 sm:pr-6 rounded-full shadow-[0_8px_30px_rgb(0,0,0,0.3)] hover:shadow-[0_8px_40px_rgb(0,0,0,0.4)] hover:-translate-y-1 transition-all duration-300 ease-out cursor-pointer active:scale-95 border border-gray-700/50 dark:border-gray-200/50"
        :class="{ 'animate-bump': isAnimating }"
      >
        <div class="flex items-center justify-center bg-primary text-white w-8 h-8 rounded-full font-bold text-sm shadow-md shrink-0">
          {{ cart.length }}
        </div>

        <div class="flex flex-col items-start mr-2 flex-grow sm:flex-grow-0">
          <span class="text-[10px] uppercase font-bold tracking-wider opacity-80">Ver Orden</span>
          <span class="text-base font-black leading-none">{{ formatCurrency(total) }}</span>
        </div>

        <span class="material-symbols-rounded bg-white/20 dark:bg-black/10 rounded-full p-1 group-hover:bg-primary group-hover:text-white transition-colors shrink-0">
          keyboard_arrow_up
        </span>
      </button>
    </div>
  </Transition>
</template>

<style scoped>
.pop-enter-active, .pop-leave-active {
  transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
.pop-enter-from, .pop-leave-to {
  opacity: 0;
  transform: translateY(20px) scale(0.9);
}
@keyframes bump {
  0% { transform: scale(1); }
  50% { transform: scale(1.05); }
  100% { transform: scale(1); }
}
.animate-bump {
  animation: bump 0.3s ease-in-out;
}
</style>