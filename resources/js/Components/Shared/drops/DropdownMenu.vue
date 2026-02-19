<script setup>
import { ref, watchEffect } from 'vue';
import { onClickOutside } from '@vueuse/core';

const props = defineProps({
    isOpen: {
        type: Boolean,
        default: false,
    },
    align: {
        type: String,
        default: 'right', // 'left', 'right'
    },
    width: {
        type: String,
        default: 'w-72', // Tailwind CSS width classes like 'w-48', 'w-full', 'w-72'
    },
    showToggleIcon: {
        type: Boolean,
        default: true,
    },
    toggleIcon: {
        type: String,
        default: 'more_horiz', // Default Material Symbols icon
    }
});

const emit = defineEmits(['update:isOpen', 'close']);

const internalIsOpen = ref(props.isOpen);
const dropdownContainer = ref(null);
const toggleButtonRef = ref(null); // Referencia al botón de toggle

watchEffect(() => {
    internalIsOpen.value = props.isOpen;
});

const closeDropdown = () => {
    internalIsOpen.value = false;
    emit('update:isOpen', false);
    emit('close');
};

const toggleDropdown = () => {
    internalIsOpen.value = !internalIsOpen.value;
    emit('update:isOpen', internalIsOpen.value);
};

onClickOutside(dropdownContainer, (event) => {
    // Solo cierra si el clic no fue en el botón de toggle, ya que el @click del botón
    // maneja su propio toggle. Esto evita doble disparo o cierre inesperado.
    if (internalIsOpen.value && toggleButtonRef.value && !toggleButtonRef.value.contains(event.target)) {
        closeDropdown();
    }
});
</script>

<template>
    <div class="relative" ref="dropdownContainer">
        <div @click="toggleDropdown" ref="toggleButtonRef">
            <slot name="trigger">
                <button
                    class="w-10 h-10 rounded-full bg-mono-blanco dark:bg-gray-700 text-gray-600 dark:text-gray-300
                           hover:bg-gray-200 dark:hover:bg-gray-600 active:bg-gray-300 dark:active:bg-gray-500
                           flex items-center justify-center transition-all duration-200 ease-in-out
                           focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 dark:focus:ring-offset-gray-900">
                    <span
                        class="material-symbols-rounded text-xl transition-transform duration-300 ease-in-out"
                        :class="internalIsOpen && showToggleIcon ? 'rotate-90' : ''">
                        {{ toggleIcon }}
                    </span>
                </button>
            </slot>
        </div>

        <Transition name="dropdown-fade">
            <div v-show="internalIsOpen"
                :class="[
                    'absolute mt-2 p-2 bg-mono-blanco/90 backdrop-blur dark:bg-mono-negro_opacity_full rounded-xl shadow-2xl border border-secundary-light dark:border-gray-700',
                    'transform origin-top transition-all duration-200 ease-out', // Transiciones de escala y opacidad
                    'flex flex-col space-y-1 z-20', // Flexbox para los items y z-index
                    align === 'left' ? 'left-0' : 'right-0',
                    width
                ]">
                <slot />
            </div>
        </Transition>
    </div>
</template>

<style scoped>
/* Las transiciones se han ajustado ligeramente para un efecto más suave y moderno */
.dropdown-fade-enter-active,
.dropdown-fade-leave-active {
    transition: transform 0.2s ease-out, opacity 0.2s ease-out;
}

.dropdown-fade-enter-from,
.dropdown-fade-leave-to {
    transform: translateY(-10px) scale(0.95);
    opacity: 0;
}
</style>