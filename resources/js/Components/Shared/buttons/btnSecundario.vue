<script setup>
import { Head, usePage, Link } from "@inertiajs/vue3"; // <-- Añade Link
import { computed } from "vue";

const props = defineProps({
  usuario: {
    type: Object,
    default: null,
  },
  label: { type: String, default: "Atrás", required: true },
  icon: { type: String, default: "Keyboard_double_arrow_left" },
});

const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth.user);
</script>

<template class="">
  <div class="flex justify-center items-center">
    <button
      :class="[
        'w-full relative bottom-0 flex justify-center items-center gap-2 rounded-lg font-medium px-8 py-1 z-10 overflow-hidden ease-in-out duration-700 group hover:text-primary hover:bg-mono-blanco  shadow active:scale-95 active:duration-0 focus:bg-mono-blanco focus:text-mono-negro isolation-auto before:absolute before:w-full before:transition-all before:duration-700 before:hover:w-full before:-left-full before:hover:left-0 before:rounded-full before:bg-gray-200 dark:before:bg-mono-blanco before:-z-10 before:aspect-square before:hover:scale-150 before:hover:duration-700 ]',
        isAuthenticated
          ? 'bg-transparent text-secondary dark:hover:text-secondary dark:text-mono-blanco group-hover:shadow-secundary-default active:bg-transparent active:border active:text-primary'
          : 'bg-transparent dark:text-mono-blanco text-mono-negro hover:shadow-mono-blanco active:bg-transparent active:border active:text-universal-naranja',
      ]"
    >
    <i
        class="material-symbols-rounded"
        :class="[
          ' bg-gray-200 p-2 rounded-xl text-[18px] group-active:-translate-x-[1000px] group-focus:translate-x-[1000px]',
          isAuthenticated
            ? 'text-secondary'
            : 'text-mono-negro font-semibold group-hover:translate-x-3',
        ]"
        >{{ icon }}</i
      >
      <span
        class="truncate text-[17px] ease-in-out duration-300 group-active:-translate-x-[1000px] group-focus:translate-x-96"
        >{{ label }}</span
      >
      <div
        class="absolute flex flex-row justify-center items-center gap-3 -translate-x-[1000px] ease-in-out duration-300 group-active:translate-x-0 group-focus:translate-x-0"
      >
        <div
          class="animate-spin size-4 border-2 border-mono-negro border-t-transparent rounded-full"
        ></div>
        Cargando...
      </div>
      
    </button>
  </div>
</template>
