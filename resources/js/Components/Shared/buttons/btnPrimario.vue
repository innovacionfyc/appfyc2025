<script setup>
import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";

const props = defineProps({
  usuario: { type: Object, default: null },
  label: { type: String, default: "Finalizar", required: true },
  icon: { type: String, default: "arrow_forward" }, // Cambié el icono por uno más simple y minimalista
  process: { type: String, default: "Procesando..." },
  disabled: { type: Boolean, default: false },
  type: {
    type: String,
    default: 'button'
  }
});

const page = usePage();
// Detectar autenticación
const isAuthenticated = computed(() => !!page.props.auth?.user || !!props.usuario);
</script>

<template>
  <div class="flex justify-center items-center w-full">
    <button :disabled="disabled" class="
        relative w-full flex items-center justify-center gap-2
        px-5 py-2.5 rounded-lg
        text-sm font-medium tracking-wide
        transition-all duration-200 ease-in-out
        focus:outline-none focus:ring-2 focus:ring-offset-2
        active:scale-[0.99]
        disabled:cursor-not-allowed disabled:opacity-70
      " :class="[
        isAuthenticated
          ? 'bg-gradient-to-br from-primary to-rose-600 text-white hover:bg-primary/90 focus:ring-primary'
          : 'bg-universal-naranja text-white hover:bg-orange-600 focus:ring-universal-naranja'
      ]">

      <Transition enter-active-class="transition duration-150 ease-out" enter-from-class="opacity-0 scale-95"
        enter-to-class="opacity-100 scale-100" leave-active-class="transition duration-100 ease-in"
        leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95" mode="out-in">

        <div v-if="disabled" class="flex items-center gap-2">
          <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
            </path>
          </svg>
          <span>{{ process }}</span>
        </div>

        <div v-else class="flex items-center gap-2">
          <span>{{ label }}</span>
          <span
            class="material-symbols-rounded text-[18px] leading-none transition-transform duration-200 group-hover:translate-x-0.5">
            {{ icon }}
          </span>
        </div>

      </Transition>
    </button>
  </div>
</template>