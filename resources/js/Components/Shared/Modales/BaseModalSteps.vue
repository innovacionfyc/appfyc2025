<script setup>
import { computed } from 'vue';
import BtnPrimario from '@/Components/Shared/buttons/btnPrimario.vue';
import BtnSecundario from '@/Components/Shared/buttons/btnSecundario.vue';

const props = defineProps({
  isOpen: { type: Boolean, required: true },
  currentStep: { type: Number, required: true },
  totalSteps: { type: Number, required: true },
  isSubmitting: { type: Boolean, default: false },
  finalButtonText: { type: String, default: 'Finalizar' },
  tabs: { type: Array, default: () => [] },
  activeTab: { type: Number, default: 0 },
  isFormStep: { type: Boolean, default: true },
  title: { type: String, default: 'Proceso' },
  description: { type: String, default: '' } // Nueva prop opcional
});

const emit = defineEmits(['close', 'next', 'prev', 'submit', 'update:activeTab']);

// Calculamos el porcentaje de progreso para la barra visual
const progressPercentage = computed(() => {
  if (props.totalSteps <= 1) return 100;
  return ((props.currentStep - 1) / (props.totalSteps - 1)) * 100;
});
</script>

<template>
  <Transition name="modal-fade">
    <div v-if="isOpen"
      class="fixed inset-0 z-50 flex items-center justify-center sm:p-4 overflow-y-auto overflow-x-hidden">

      <div class="fixed inset-0 backdrop-blur-sm bg-gray-900/50 transition-opacity" @click="$emit('close')"></div>

      <Transition name="modal-scale">
        <form @submit.prevent="$emit('submit')" v-if="isOpen"
          class="relative w-auto bg-white dark:bg-gray-900 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-800 flex flex-col max-h-[95vh] sm:max-h-[90vh] z-50 overflow-hidden transform transition-all">

          <div
            class="flex-shrink-0 px-8 py-6 border-b border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900 flex justify-between items-center">
            <div class="pr-8">
              <h3 class="text-2xl font-bold text-gray-900 dark:text-white tracking-tight">{{ title }}</h3>
              <p v-if="description" class="mt-1 text-sm text-gray-500">{{ description }}</p>
            </div>

            <button type="button" @click="$emit('close')"
              class="text-gray-400 hover:text-gray-800 dark:hover:text-white transition-colors p-1 rounded-md hover:bg-gray-100 dark:hover:bg-gray-800">
              <span class="material-symbols-rounded text-2xl">close</span>
            </button>
          </div>

          <div v-if="totalSteps > 1" class="w-full px-8 pt-6 pb-2">
            <div class="flex items-center gap-2">
              <template v-for="step in totalSteps" :key="step">
                <div class="h-1.5 rounded-full flex-1 transition-all duration-500 relative overflow-hidden" :class="[
                  step <= currentStep ? 'bg-primary shadow-[0_0_8px_rgba(var(--color-primary),0.4)]' : 'bg-gray-100 dark:bg-gray-800'
                ]">
                  <div v-if="step === currentStep"
                    class="absolute inset-0 bg-white/30 w-full animate-[shimmer_2s_infinite]"></div>
                </div>
              </template>
            </div>
          </div>

          <div v-if="totalSteps > 1"
            class="flex-shrink-0 px-8 py-3 bg-gray-50/50 dark:bg-gray-900/50 border-b border-gray-100 dark:border-gray-800 flex justify-between items-center text-xs font-medium uppercase tracking-wider text-gray-500">
            <span>Paso {{ currentStep }} de {{ totalSteps }}</span>
            <span class="text-primary font-semibold" v-if="currentStep < totalSteps">Siguiente: Paso {{ currentStep + 1
            }}</span>
            <span class="text-primary font-semibold" v-else>Confirmación</span>
          </div>

          <div v-if="tabs.length > 0"
            class="flex-shrink-0 px-8 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
            <nav class="flex space-x-8" aria-label="Tabs">
              <button v-for="(tab, index) in tabs" :key="index" @click="$emit('update:activeTab', index)" type="button"
                :class="[
                  activeTab === index
                    ? 'border-primary text-primary'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                  'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-all'
                ]">
                {{ tab.label }}
              </button>
            </nav>
          </div>

          <div class="flex-grow overflow-y-auto px-8 py-8 scrollbar-thin">
            <slot name="header"></slot>

            <Transition name="fade-fast" mode="out-in">
              <div :key="currentStep">
                <slot></slot>
              </div>
            </Transition>
          </div>

          <div
            class="flex-shrink-0 px-8 py-5 bg-gray-50 dark:bg-gray-800/50 border-t border-gray-200 dark:border-gray-800 flex justify-between items-center">
            <div>
              <button v-if="currentStep > 1" type="button" @click="$emit('prev')"
                class="text-sm font-medium text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors flex items-center gap-1 px-2 py-2 rounded-lg hover:bg-gray-200/50 dark:hover:bg-gray-700/50">
                <span class="material-symbols-rounded text-lg">arrow_back</span>
                <span>Atrás</span>
              </button>
            </div>

            <div class="flex gap-3">

              <BtnPrimario v-if="currentStep < totalSteps" label="Siguiente Paso" @click.prevent="$emit('next')"
                type="button" class="rounded-lg shadow-none hover:shadow-lg transition-all" />

              <BtnPrimario v-if="currentStep === totalSteps && isFormStep" :disabled="isSubmitting" type="submit"
                :label="isSubmitting ? 'Guardando...' : finalButtonText"
                class="rounded-lg min-w-[120px] shadow-none hover:shadow-md transition-all"
                :class="{ 'opacity-70': isSubmitting }">
              </BtnPrimario>
            </div>
          </div>

        </form>
      </Transition>
    </div>
  </Transition>
</template>

<style scoped>
/* Scrollbar fina y discreta */
.scrollbar-thin::-webkit-scrollbar {
  width: 6px;
}

.scrollbar-thin::-webkit-scrollbar-track {
  background: transparent;
}

.scrollbar-thin::-webkit-scrollbar-thumb {
  background-color: #e2e8f0;
  border-radius: 20px;
}

.dark .scrollbar-thin::-webkit-scrollbar-thumb {
  background-color: #475569;
}

/* Transiciones Rápidas y Profesionales (No "bouncy") */
.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.2s ease;
}

.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}

.modal-scale-enter-active {
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.modal-scale-leave-active {
  transition: all 0.15s ease-in;
}

.modal-scale-enter-from {
  opacity: 0;
  transform: scale(0.98) translateY(4px);
}

.modal-scale-leave-to {
  opacity: 0;
  transform: scale(0.98) translateY(4px);
}

.fade-fast-enter-active,
.fade-fast-leave-active {
  transition: opacity 0.15s ease;
}

.fade-fast-enter-from,
.fade-fast-leave-to {
  opacity: 0;
}
</style>