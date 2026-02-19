<script setup>
import { defineProps, defineEmits } from "vue";
import BaseModalSteps from '@/Components/Shared/Modales/BaseModalSteps.vue';

// --- PROPS ---
const props = defineProps({
    isOpen: { type: Boolean, required: true },
    // Esta prop recibe el array JSON que devuelve tu controlador Laravel
    reports: { type: Array, default: () => [] }, 
    isLoading: { type: Boolean, default: false } // Opcional: para mostrar esqueleto de carga
});

const emit = defineEmits(['close']);

// --- MÉTODOS ---
const downloadReport = (reportUrl) => {
    // Abrir en nueva pestaña (S3 URL)
    window.open(reportUrl, '_blank');
};
</script>

<template>
  <BaseModalSteps 
    :isOpen="isOpen" 
    :currentStep="1" 
    :totalSteps="1"
    title="Historial de Cierres"
    description="Repositorio de documentos generados en PDF."
    :show-buttons="false" 
    @close="emit('close')"
  >

    <div class="py-2 flex flex-col">
        
        <div class="flex justify-between items-center px-1 mb-4">
            <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <span class="material-symbols-rounded text-primary">folder_open</span>
                <span>Archivos encontrados: <strong class="text-gray-900 dark:text-white">{{ reports.length }}</strong></span>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto pr-2 scrollbar-thin max-h-[500px]">
            
            <div v-if="isLoading" class="space-y-3">
                <div v-for="i in 3" :key="i" class="h-20 bg-gray-100 dark:bg-gray-800 rounded-xl animate-pulse"></div>
            </div>

            <div v-else-if="reports.length === 0" class="flex flex-col items-center justify-center py-16 border-2 border-dashed border-gray-200 dark:border-gray-700 rounded-2xl bg-gray-50/50 dark:bg-gray-800/30">
                <div class="w-16 h-16 bg-gray-200 dark:bg-gray-700 rounded-full flex items-center justify-center mb-4 text-gray-400">
                    <span class="material-symbols-rounded text-3xl">folder_off</span>
                </div>
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">Sin reportes</h3>
                <p class="text-xs text-gray-500 mt-1">No se han generado cierres de caja aún.</p>
            </div>

            <div v-else class="space-y-3">
                <div v-for="(report, index) in reports" :key="index" 
                     class="group relative flex items-center gap-4 p-4 bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800 rounded-2xl shadow-sm hover:shadow-md hover:border-primary/30 transition-all duration-200">
                    
                    <div class="shrink-0 w-12 h-12 rounded-xl bg-red-50 dark:bg-red-900/20 flex items-center justify-center text-red-500 border border-red-100 dark:border-red-900/50 group-hover:scale-110 transition-transform">
                        <span class="material-symbols-rounded text-2xl">picture_as_pdf</span>
                    </div>

                    <div class="flex-1 min-w-0">
                        <h4 class="font-bold text-gray-900 dark:text-white text-sm truncate" :title="report.name">
                            {{ report.name }}
                        </h4>
                        <div class="flex items-center gap-3 mt-1">
                            <span class="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 px-2 py-0.5 rounded-md font-mono">
                                <span class="material-symbols-rounded text-[10px]">calendar_today</span>
                                {{ report.date }}
                            </span>
                        </div>
                    </div>

                    <button @click="downloadReport(report.url)" 
                            class="shrink-0 flex items-center gap-2 px-4 py-2 bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-primary hover:text-white rounded-xl text-xs font-bold transition-all border border-gray-200 dark:border-gray-700 hover:border-primary group/btn">
                        <span>Ver PDF</span>
                        <span class="material-symbols-rounded text-base group-hover/btn:translate-x-0.5 transition-transform">open_in_new</span>
                    </button>
                </div>
            </div>

        </div>

        

    </div>

  </BaseModalSteps>
</template>

<style scoped>
/* Scrollbar bonita */
.scrollbar-thin::-webkit-scrollbar { width: 5px; }
.scrollbar-thin::-webkit-scrollbar-track { background: transparent; }
.scrollbar-thin::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 20px; }
.dark .scrollbar-thin::-webkit-scrollbar-thumb { background-color: #4b5563; }
</style>