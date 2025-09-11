<script setup>
import { ref, watch, onMounted } from 'vue';

const props = defineProps({
    message: String,
    type: {
        type: String,
        default: 'success',
    },
});

const isVisible = ref(false);

// Mapeo de clases de Tailwind para cada tipo de mensaje
const typeClasses = {
    success: 'bg-green-500 border-green-600',
    error: 'bg-red-500 border-red-600',
};

// Función para mostrar el mensaje
const show = () => {
    isVisible.value = true;
    // Ocultar automáticamente después de 5 segundos
    setTimeout(() => {
        isVisible.value = false;
    }, 5000);
};

// Función para ocultar el mensaje al hacer clic en la X
const hide = () => {
    isVisible.value = false;
};

// Observa si llega un nuevo mensaje para mostrarlo
watch(() => props.message, (newMessage) => {
    if (newMessage) {
        show();
    }
});

// Si el componente se monta con un mensaje, muéstralo
onMounted(() => {
    if(props.message) {
        show();
    }
});

</script>

<template>
    <transition
        enter-active-class="ease-out duration-300"
        enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
        enter-to-class="opacity-100 translate-y-0 sm:scale-100"
        leave-active-class="ease-in duration-200"
        leave-from-class="opacity-100 translate-y-0 sm:scale-100"
        leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
    >
        <div
            v-if="isVisible && message"
            :class="['fixed', 'top-5', 'right-5', 'p-4', 'rounded-lg', 'text-white', 'shadow-lg', 'border-l-4', typeClasses[type]]"
            role="alert"
        >
            <div class="flex items-center">
                <p class="font-bold mr-4">{{ message }}</p>
                <button @click="hide" class="text-xl font-semibold">&times;</button>
            </div>
        </div>
    </transition>
</template>