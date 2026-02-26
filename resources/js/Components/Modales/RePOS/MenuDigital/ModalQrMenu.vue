<script setup>
import { ref } from 'vue';
import BaseModalSteps from '@/Components/Shared/Modales/BaseModalSteps.vue';

const props = defineProps({
    isOpen: { type: Boolean, required: true },
    qrUrl: { type: String, required: true },
    menuLink: { type: String, required: true },
});

const emit = defineEmits(['close']);

const isCopied = ref(false);
const copyError = ref(false);

// --- 1. FUNCIÓN DE DESCARGA ---
const handleDownload = () => {
    const link = document.createElement('a');
    link.download = `menu-digital-${new Date().getTime()}.png`; // Nombre único
    link.href = props.qrUrl;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    emit('close');
};

// --- 2. FUNCIÓN DE COPIADO A PRUEBA DE FALLOS ---
const copyLink = async () => {
    const textToCopy = props.menuLink;

    try {
        // Intento A: API Moderna (Requiere HTTPS)
        await navigator.clipboard.writeText(textToCopy);
        triggerCopySuccess();
    } catch (err) {
        // Intento B: Fallback Clásico (Funciona en HTTP/Local)
        try {
            const textArea = document.createElement("textarea");
            textArea.value = textToCopy;
            
            // Asegurar que no sea visible pero sea seleccionable
            textArea.style.position = "fixed";
            textArea.style.left = "-9999px";
            textArea.style.top = "0";
            document.body.appendChild(textArea);
            
            textArea.focus();
            textArea.select();
            
            const successful = document.execCommand('copy');
            document.body.removeChild(textArea);
            
            if (successful) {
                triggerCopySuccess();
            } else {
                throw new Error("Fallback failed");
            }
        } catch (fallbackErr) {
            console.error('Error al copiar:', fallbackErr);
            copyError.value = true;
            setTimeout(() => copyError.value = false, 2000);
        }
    }
};

const triggerCopySuccess = () => {
    isCopied.value = true;
    setTimeout(() => isCopied.value = false, 2500);
};

const openPreview = () => {
    window.open(props.menuLink, '_blank');
};
</script>

<template>
    <BaseModalSteps 
        :is-open="isOpen" 
        :current-step="1" 
        :total-steps="1" 
        title="Menú Digital"
        description="Comparte la experiencia" 
        final-button-text="Descargar Arte QR"
        :is-form-step="true" 
        @close="$emit('close')" 
        @submit="handleDownload"
    >
        
        <div class="flex flex-col items-center justify-center space-y-8 py-4 animate-in fade-in zoom-in-95 duration-500">
            
            <div class="relative group cursor-pointer perspective-1000" @click="handleDownload">

                <div class="relative bg-white dark:bg-[#0F172A] p-6 rounded-[22px] shadow-sm border border-white/20 dark:border-gray-700/50 flex flex-col items-center transform transition-transform duration-500 group-hover:scale-[1.02] group-hover:-translate-y-1 group-hover:opacity-30">
                    
                    <div class="bg-white rounded-xl">
                        <img :src="qrUrl" alt="QR" class="w-h-64 h-64 object-contain rounded-lg mix-blend-plus-darker" />
                    </div>

                   
                </div>

                <div class="absolute top-1/2 w-auto left-1/2 -translate-x-1/2 -translate-y-1/2 opacity-0 group-hover:opacity-100 transition-all duration-300 scale-90 group-hover:scale-100 z-20 pointer-events-none">
                    <div class="bg-black/80 text-white backdrop-blur-md px-4 py-2 rounded-full font-bold text-xs shadow-xl flex items-center gap-2">
                        <span class="material-symbols-rounded text-sm">download</span>
                        Guardar QR  
                    </div>
                </div>
            </div>

            <div class="w-full max-auto space-y-3">
                
                <div class="flex justify-between items-end px-2">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Enlace Público</p>
                    <button @click="openPreview" class="group flex items-center gap-1 text-[10px] font-bold text-primary hover:text-orange-600 transition-colors">
                        Vista Previa 
                        <span class="material-symbols-rounded text-sm group-hover:translate-x-0.5 transition-transform">open_in_new</span>
                    </button>
                </div>
                
                <div class="relative group/input">
                    <div class="absolute -inset-[1px] bg-gradient-to-r from-primary to-orange-400 rounded-xl opacity-0 group-focus-within/input:opacity-100 transition-opacity duration-300 blur-[1px]"></div>
                    
                    <div class="relative flex items-center bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-1.5 shadow-inner transition-all">
                        
                        <div class="p-2 text-gray-400">
                            <span class="material-symbols-rounded text-xl">link</span>
                        </div>
                        
                        <div class="flex-1 min-w-0 px-2 cursor-text" @click="copyLink">
                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate font-mono select-all">
                                {{ menuLink }}
                            </p>
                        </div>

                        <button 
                            type="button"
                            @click="copyLink"
                            class="flex items-center gap-2 px-4 py-2 rounded-lg text-xs font-bold transition-all duration-300 shadow-sm border"
                            :class="[
                                isCopied 
                                    ? 'bg-semaforo-verde text-white border-green-600 scale-105' 
                                    : copyError
                                        ? 'bg-red-500 text-white border-red-600'
                                        : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'
                            ]"
                        >
                            <span class="material-symbols-rounded text-base" v-if="!isCopied && !copyError">content_copy</span>
                            <span class="material-symbols-rounded text-base animate-bounce" v-else-if="isCopied">check</span>
                            <span class="material-symbols-rounded text-base" v-else>error</span>
                            
                            <span v-if="isCopied">¡Listo!</span>
                            <span v-else-if="copyError">Error</span>
                            <span v-else>Copiar</span>
                        </button>
                    </div>
                </div>

                <p class="text-[10px] text-center text-gray-400 leading-relaxed px-4">
                    Este código nunca expira. Puedes imprimirlo en tus mesas o compartirlo en redes sociales.
                </p>
            </div>

        </div>

    </BaseModalSteps>
</template>

