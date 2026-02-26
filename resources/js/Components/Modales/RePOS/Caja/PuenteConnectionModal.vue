<script setup>
import { ref, onUnmounted } from 'vue';
import axios from 'axios';

const props = defineProps({
    ip_puente: { type: String, default: '' },
});

// --- ESTADO ---
const showModal = ref(false);
const status = ref('idle'); 
const loadingText = ref('Iniciando...');
const errorDetails = ref('');
let textInterval = null;

const loadingMessages = [
    'Localizando servidor...',
    'Verificando puerto 4000...',
    'Estableciendo handshake...',
    'Sincronizando...'
];

// --- LÓGICA CORE ---
async function testPuenteConnection() {
    // CORRECCIÓN: Abrimos el modal INMEDIATAMENTE al hacer clic
    showModal.value = true;
    
    // 1. Validación inicial
    if (!props.ip_puente) {
        // Pequeño delay para que la animación de entrada se vea suave antes de mostrar el error
        status.value = 'scanning'; // Mostramos el radar un milisegundo
        setTimeout(() => {
            handleError({ message: 'No hay IP configurada.' });
        }, 2500);
        return;
    }

    status.value = 'scanning';
    errorDetails.value = '';
    
    let msgIndex = 0;
    loadingText.value = loadingMessages[0];
    textInterval = setInterval(() => {
        msgIndex = (msgIndex + 1) % loadingMessages.length;
        loadingText.value = loadingMessages[msgIndex];
    }, 600);

    try {
        const [response] = await Promise.all([
            axios.get(props.ip_puente, { timeout: 5000 }),
            new Promise(resolve => setTimeout(resolve, 2500)) 
        ]);

        if (response.data.success) {
            handleSuccess();
        } else {
            throw new Error('El puente respondió con error.');
        }

    } catch (error) {
        setTimeout(() => handleError(error), 1000);
    } finally {
        clearInterval(textInterval);
    }
}

function handleSuccess() {
    status.value = 'success';
    setTimeout(() => {
        showModal.value = false;
        setTimeout(() => status.value = 'idle', 300);
    }, 2000);
}

function handleError(error) {
    status.value = 'error';
    if (error.code === 'ERR_NETWORK') errorDetails.value = 'Sin conexión con la IP. Verifica que el servidor esté encendido.';
    else if (error.code === 'ECONNABORTED') errorDetails.value = 'Tiempo de espera agotado. La red está lenta.';
    else errorDetails.value = error.message || 'Error desconocido.';
}

function retry() {
    testPuenteConnection();
}

onUnmounted(() => clearInterval(textInterval));
</script>

<template>
    <button @click="testPuenteConnection" type="button"
        class="group relative px-3 h-10 flex items-center gap-2 rounded-xl text-gray-500 dark:text-gray-400 hover:bg-white dark:hover:bg-gray-700 hover:text-blue-600 dark:hover:text-blue-400 hover:shadow-sm transition-all duration-200 focus:outline-none"
        title="Estado del Puente">
        <div class="relative flex h-2.5 w-2.5">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-blue-500 shadow-sm"></span>
        </div>
        <span class="text-xs font-bold hidden sm:block">Puente</span>
    </button>

    <Teleport to="body">
        <Transition name="ios-zoom">
            <div v-if="showModal" class="fixed inset-0 z-[9999] flex items-center justify-center p-4">
                
                <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-md transition-opacity" @click="status === 'error' ? showModal = false : null"></div>

                <div class="relative w-[320px] bg-white/95 dark:bg-gray-900/95 backdrop-blur-2xl rounded-[32px] shadow-2xl p-8 flex flex-col items-center text-center overflow-hidden border border-white/20 ring-1 ring-black/5">
                    
                    <div v-if="status === 'scanning'" class="flex flex-col items-center py-4">
                        <div class="relative w-24 h-24 mb-6">
                            <svg class="w-full h-full transform -rotate-90" viewBox="0 0 100 100">
                                <circle cx="50" cy="50" r="40" fill="none" stroke="#E5E7EB" stroke-width="6" class="dark:stroke-gray-700" />
                                <circle cx="50" cy="50" r="40" fill="none" stroke="#3B82F6" stroke-width="6" stroke-linecap="round"
                                    class="animate-dash" stroke-dasharray="251" stroke-dashoffset="251" />
                            </svg>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <span class="material-symbols-rounded text-3xl text-blue-500 animate-pulse">wifi_tethering</span>
                            </div>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2 tracking-tight">Conectando...</h3>
                        <p class="text-sm font-medium text-blue-500 h-5 transition-all duration-300">{{ loadingText }}</p>
                    </div>

                    <div v-else-if="status === 'success'" class="flex flex-col items-center py-2">
                        <div class="w-24 h-24 bg-green-500 rounded-full flex items-center justify-center mb-6 shadow-lg shadow-green-500/30 animate-pop-in">
                            <svg class="w-12 h-12 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" class="animate-draw-check" />
                            </svg>
                        </div>
                        <h3 class="text-xl font-black text-gray-900 dark:text-white mb-1">¡Conectado!</h3>
                        <p class="text-sm text-gray-500">Sistema listo para operar.</p>
                    </div>

                    <div v-else-if="status === 'error'" class="flex flex-col items-center w-full pt-2">
                        <div class="w-20 h-20 bg-red-100 dark:bg-red-500/20 rounded-full flex items-center justify-center mb-5 animate-shake">
                            <span class="material-symbols-rounded text-4xl text-red-500">cloud_off</span>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Sin Conexión</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-8 px-2 leading-relaxed">
                            {{ errorDetails }}
                        </p>
                        <div class="grid grid-cols-2 gap-3 w-full">
                            <button @click="showModal = false" class="py-3.5 text-sm font-bold text-gray-500 bg-gray-100 dark:bg-gray-800 rounded-2xl hover:bg-gray-200 transition-colors">Cerrar</button>
                            <button @click="retry" class="py-3.5 text-sm font-bold text-white bg-gray-900 dark:bg-blue-600 rounded-2xl hover:scale-[1.02] active:scale-[0.98] transition-all shadow-lg">Reintentar</button>
                        </div>
                    </div>

                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.ios-zoom-enter-active { transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); }
.ios-zoom-leave-active { transition: all 0.2s ease-in; }
.ios-zoom-enter-from, .ios-zoom-leave-to { opacity: 0; transform: scale(0.85); }

@keyframes dash { 0% { stroke-dashoffset: 251; } 100% { stroke-dashoffset: 0; } }
.animate-dash { animation: dash 2.5s ease-in-out forwards; }

@keyframes pop-in { 0% { transform: scale(0); opacity: 0; } 50% { transform: scale(1.2); } 100% { transform: scale(1); opacity: 1; } }
.animate-pop-in { animation: pop-in 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards; }

@keyframes draw-check { from { stroke-dasharray: 50; stroke-dashoffset: 50; } to { stroke-dashoffset: 0; } }
.animate-draw-check { stroke-dasharray: 50; stroke-dashoffset: 50; animation: draw-check 0.5s 0.3s ease-out forwards; }

@keyframes shake { 0%, 100% { transform: translateX(0); } 20% { transform: translateX(-8px); } 40% { transform: translateX(8px); } 60% { transform: translateX(-4px); } 80% { transform: translateX(4px); } }
.animate-shake { animation: shake 0.5s cubic-bezier(.36,.07,.19,.97) both; }
</style>