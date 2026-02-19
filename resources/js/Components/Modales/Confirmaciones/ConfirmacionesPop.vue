<script setup>
import { ref, watch } from "vue";

const props = defineProps({
  isOpen: { type: Boolean, required: true },
  title: { type: String, required: true },
  message: { type: String, required: true },
  icon: { type: String, default: "warning" },
  iconBgClass: String,
  confirmText: { type: String, default: "Confirmar" },
  isLoading: { type: Boolean, default: false },
  confirmButtonClass: {
    // 👈 Declara la prop aquí
    type: String,
    default: "bg-rose-600 text-white",
  },
});

const emit = defineEmits(["close", "confirm"]);

// Lógica de barra de progreso para los 2 segundos
const progress = ref(0);
watch(
  () => props.isLoading,
  (loading) => {
    if (loading) {
      progress.value = 0;
      const interval = setInterval(() => {
        if (progress.value < 100) progress.value += 1;
        // Sube en 2 segundos
        else clearInterval(interval);
      }, 40);
    } else {
      progress.value = 0;
    }
  }
);
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="isOpen"
        class="fixed inset-0 z-[100] flex items-center justify-center p-4"
      >
        <div
          class="absolute inset-0 bg-black/20 backdrop-blur-sm"
          @click="!isLoading && $emit('close')"
        ></div>

        <Transition name="zoom" appear>
          <div
            class="relative w-full max-w-[340px] bg-white dark:bg-[#0D0F14] border border-gray-200 dark:border-white/10 rounded-3xl shadow-[0_32px_64px_-16px_rgba(0,0,0,0.2)] overflow-hidden"
          >
            <div
              v-if="isLoading"
              class="absolute top-0 left-0 w-full h-1 bg-gray-100 dark:bg-white/5"
            >
              <div
                class="h-full bg-primary transition-all duration-100"
                :style="{ width: progress + '%' }"
              ></div>
            </div>

            <div class="p-8">
              <div class="flex items-center gap-4 mb-6">
                <div
                  class="w-12 h-12 rounded-2xl bg-gray-50 dark:bg-white/5 flex items-center justify-center border border-gray-100 dark:border-white/10"
                >
                  <span
                    class="material-symbols-rounded text-2xl text-gray-400"
                    :class="{ 'text-primary animate-pulse': isLoading }"
                  >
                    {{ isLoading ? "sync" : icon }}
                  </span>
                </div>
                <div class="flex-1">
                  <h4
                    class="text-sm font-black text-gray-900 dark:text-white uppercase tracking-tighter italic"
                  >
                    {{ isLoading ? "Procesando..." : title }}
                  </h4>
                  <p
                    class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mt-0.5"
                  >
                    Confirmación de Acción
                  </p>
                </div>
              </div>

              <p
                class="text-[13px] text-gray-500 dark:text-gray-400 leading-relaxed font-medium mb-8"
              >
                {{ message }}
              </p>

              <div class="flex flex-col gap-2">
                <button
                  type="button"
                  @click="$emit('confirm')"
                  :disabled="isLoading"
                  class="w-full py-3 bg-gray-900 dark:bg-white text-white dark:text-black rounded-xl text-[11px] font-black uppercase tracking-[0.2em] transition-all flex items-center justify-center gap-3 disabled:opacity-50 shadow-lg shadow-black/10"
                >
                  <div
                    v-if="isLoading"
                    class="w-4 h-4 border-2 border-white/30 border-t-white dark:border-black/20 dark:border-t-black rounded-full animate-spin"
                  ></div>
                  <span>{{ isLoading ? "Validando" : confirmText }}</span>
                </button>

                <button
                  v-if="!isLoading"
                  type="button"
                  @click="$emit('close')"
                  class="w-full py-3 text-[10px] font-black uppercase tracking-[0.2em] text-gray-400 hover:text-gray-900 dark:hover:text-white transition-all"
                >
                  Cancelar
                </button>
              </div>
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.zoom-enter-active {
  transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}
.zoom-leave-active {
  transition: all 0.2s ease-in;
}
.zoom-enter-from {
  opacity: 0;
  transform: scale(0.95) translateY(10px);
}
.zoom-leave-to {
  opacity: 0;
  transform: scale(0.95);
}
</style>
