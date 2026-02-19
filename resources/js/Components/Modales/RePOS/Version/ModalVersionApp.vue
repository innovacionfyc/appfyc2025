<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import axios from 'axios'; // Asegúrate de importar axios
import BaseModalSteps from '@/Components/Shared/Modales/BaseModalSteps.vue';

const props = defineProps({
    isOpen: { type: Boolean, required: true },
});

const emit = defineEmits(['close']);

// Estado
const currentStep = ref(1);
const totalSteps = 2;
const isLoading = ref(true);

// Datos Reactivos (Valores por defecto mientras carga)
const versionData = ref({
    currentVersion: '...',
    lastUpdateDate: '...',
    changelog: []
});

// Fetch de datos al abrir
const fetchVersionInfo = async () => {
    isLoading.value = true;
    try {
        const response = await axios.get(route('system.version'));
        versionData.value = response.data;
    } catch (error) {
        console.error("Error cargando versión:", error);
    } finally {
        // Un pequeño delay artificial de 300ms para que la animación de carga se aprecie (UX Premium)
        setTimeout(() => isLoading.value = false, 250);
    }
};

// Cargar cuando se monta o cuando se abre
watch(() => props.isOpen, (newVal) => {
    if (newVal) {
        currentStep.value = 1; // Reset paso
        fetchVersionInfo();
    }
});

// Títulos dinámicos
const modalTitle = computed(() => currentStep.value === 1 ? 'Información de la Versión' : 'Historial de Cambios');
const modalDesc = computed(() => currentStep.value === 1 ? 'Estado del sistema' : 'Novedades recientes desde la nube');

// Manejadores de navegación
const handleNext = () => { if (currentStep.value < totalSteps) currentStep.value++; };
const handlePrev = () => { if (currentStep.value > 1) currentStep.value--; };
const handleClose = () => emit('close');

// Helper visual
const getTypeStyle = (type) => {
    switch (type) {
        case 'major': return 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300 border-rose-200 dark:border-rose-800';
        case 'feature': return 'bg-primary text-mono-blanco dark:bg-primary/30 dark:text-mono-blanco border-primary dark:border-primary';
        default: return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 border-gray-200 dark:border-gray-700';
    }
};
</script>

<template>
    <BaseModalSteps :is-open="isOpen" :current-step="currentStep" :total-steps="totalSteps" :title="modalTitle"
        :description="modalDesc" final-button-text="¡Entendido!" :is-form-step="false" @close="handleClose"
        @next="handleNext" @prev="handlePrev" @submit="handleClose">

        <div v-if="isLoading" class="flex flex-col items-center space-y-6 animate-pulse px-4">
            <div class="w-24 h-24 bg-gray-200 dark:bg-gray-700 rounded-full"></div>
            <div class="space-y-2 w-full flex flex-col items-center">
                <div class="h-8 bg-gray-200 dark:bg-gray-700 rounded w-1/2"></div>
                <div class="h-6 bg-gray-200 dark:bg-gray-700 rounded w-1/4"></div>
            </div>
            <div class="grid grid-cols-2 gap-4 w-full max-w-md">
                <div class="h-20 bg-gray-200 dark:bg-gray-700 rounded-2xl"></div>
                <div class="h-20 bg-gray-200 dark:bg-gray-700 rounded-2xl"></div>
            </div>
        </div>

        <div v-else>

            <div v-if="currentStep === 1"
                class="flex flex-col items-center text-center space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">

                <div class="relative group mt-4">
                    <div
                        class="absolute -inset-1 bg-gradient-to-r from-primary to-rose-600 rounded-full blur opacity-25 group-hover:opacity-50 transition duration-1000">
                    </div>
                    <div
                        class="relative w-24 h-24 bg-white dark:bg-gray-800 rounded-full flex items-center justify-center border border-gray-200 dark:border-gray-700 shadow-xl">
                        <span
                            class="material-symbols-rounded text-5xl text-transparent bg-clip-text bg-gradient-to-br from-primary to-rose-600">
                            shield_with_house
                        </span>
                    </div>
                    <div
                        class="absolute bottom-0 right-0 bg-semaforo-verde w-6 h-6 rounded-full border-4 border-white dark:border-gray-900 flex items-center justify-center">
                        <span class="material-symbols-rounded text-[10px] text-white font-bold">check</span>
                    </div>
                </div>

                <div>
                    <h2 class="text-3xl font-black text-gray-900 dark:text-white tracking-tight mb-1">
                        RePOS
                    </h2>
                    <div
                        class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                        <span class="w-2 h-2 rounded-full bg-semaforo-verde animate-pulse"></span>
                        <span class="text-xs font-bold text-gray-600 dark:text-gray-300 font-mono tracking-wide">{{
                            versionData.currentVersion }}</span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 w-full max-w-md">
                    <div
                        class="p-4 rounded-2xl bg-gray-50 dark:bg-gray-800/50 border border-gray-100 dark:border-gray-700 flex flex-col items-center hover:shadow-md transition-shadow">
                        <span class="text-xs text-gray-400 uppercase font-bold tracking-wider mb-1">Actualizado
                            el</span>
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{
                            versionData.lastUpdateDate }}</span>
                    </div>
                    <div
                        class="p-4 rounded-2xl bg-gray-50 dark:bg-gray-800/50 border border-gray-100 dark:border-gray-700 flex flex-col items-center hover:shadow-md transition-shadow">
                        <span class="text-xs text-gray-400 uppercase font-bold tracking-wider mb-1">Estado app</span>
                        <span class="text-sm font-semibold text-semaforo-verde flex items-center gap-1">
                            <span class="material-symbols-rounded text-base">verified</span> Sincronizado
                        </span>
                    </div>
                </div>
            </div>

            <div v-else class="space-y-6 animate-in fade-in slide-in-from-right-8 duration-500 px-2">

                <div class="relative border-l-2 border-gray-200 dark:border-gray-700 ml-3 space-y-8 pb-4">

                    <div v-for="(log, index) in versionData.changelog" :key="index" class="relative pl-8 group">
                        <div class="absolute -left-[9px] top-0 w-4 h-4 rounded-full border-2 border-white dark:border-gray-900 bg-gray-300 dark:bg-gray-600 group-hover:bg-primary transition-colors shadow-sm"
                            :class="{ 'bg-primary ring-2 ring-rose-400': index === 0 }"></div>

                        <div
                            class="flex items-center flex-col sm:flex-row sm:items-center sm:justify-between mb-2 gap-10">
                            <div class="flex items-center gap-3">
                                <h4 class="text-lg font-bold text-gray-900 dark:text-white">{{ log.title }}</h4>
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider border"
                                    :class="getTypeStyle(log.type)">
                                    {{ log.type }}
                                </span>
                            </div>
                            <div class="flex items-center gap-2 mt-1 sm:mt-0">
                                <span class="text-xs font-mono font-bold text-gray-500">{{ log.version }}</span>
                                <span
                                    class="text-[10px] text-gray-400 bg-gray-100 dark:bg-gray-800 px-2 py-0.5 rounded-full">{{
                                    log.date }}</span>
                            </div>
                        </div>

                        <ul class="">
                            <li v-for="(change, cIndex) in log.changes" :key="cIndex"
                                class="text-sm text-gray-600 dark:text-gray-300 flex items-center">
                                <span v-if="cIndex === 0"
                                    class="material-symbols-rounded text-base text-gray-400 mt-0.5 mr-1 shrink-0">
                                    arrow_right
                                </span>
                                <span :class="{ 'pl-5': cIndex > 0 }">
                                    {{ change }}
                                </span>

                            </li>
                        </ul>
                    </div>

                </div>
            </div>

        </div>

    </BaseModalSteps>
</template>