<script setup>
import { defineProps, defineEmits, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { formatFecha } from "@/Utils/date";
import { formatCOP } from "@/Utils/formateoMoneda";
import BaseModalSteps from '@/Components/Shared/Modales/BaseModalSteps.vue';
import ConfirmacionesPop from "../../Confirmaciones/ConfirmacionesPop.vue";
import { useConfirmationModal } from "@/Composables/useConfirmationModal";

// --- PROPS ---
const props = defineProps({
    isOpen: { type: Boolean, required: true },
    productos: { type: Array, default: () => [] },
    // Helpers recibidos del padre
    getPhotoProductUrlFull: Function,
    getInitialProduct: Function,
    getStockInfo: Function,
    openConfirm: Function
});


const emit = defineEmits(['close']);

// --- COMPOSABLES ---
const {
    confirmationState,
    openConfirmationModal,
    closeConfirmationModal,
    handleConfirm,
} = useConfirmationModal();

// --- LOGICA DE ESTADO ---
// Función auxiliar para el badge de stock visual
const getStockBadge = (producto) => {
    const info = props.getStockInfo(producto);
    if (info.stock_actual <= 0) return { label: 'Agotado', classes: 'bg-gray-100 text-gray-500 border-gray-200' };
    if (info.stock_actual <= 5) return { label: `Bajo (${info.stock_actual})`, classes: 'bg-amber-50 text-amber-700 border-amber-100' };
    return { label: `Stock: ${info.stock_actual}`, classes: 'bg-blue-50 text-blue-700 border-blue-100' };
};

const isProcessing = ref(false);

function handleActionWithBuffer(callback) {
  // 1. ACTIVAMOS EL CARGANDO AL INSTANTE
  isProcessing.value = true;

  // 2. ESPERAMOS LOS 2 SEGUNDOS DE "SEGURIDAD"
  setTimeout(() => {
    callback();
  }, 2000);
}
// --- ACCIONES ---
function restaurarProducto(producto) {
    openConfirmationModal({
        title: "Restaurar Ítem",
        message: `¿Deseas reintegrar "${producto.nombre}" al inventario?`,
        icon: "settings_backup_restore",
        iconBgClass: "bg-emerald-500/10 text-emerald-500",
        confirmText: "Restaurar",
        onConfirm: () => {
            handleActionWithBuffer(() => {
                router.post(route("productos.restore", { id: producto.id }), {}, {
                    preserveScroll: true,
                    preserveState: true,
                    onFinish: () => { 
                        isProcessing.value = false;
                    },
                });
            });
        },
    });
}

function eliminarPermanentemente(producto) {
    openConfirmationModal({
        title: "Destrucción Final",
        message: `Esta acción eliminará a "${producto.nombre}" de forma irreversible.`,
        icon: "warning",
        iconBgClass: "bg-red-500/10 text-red-500",
        confirmText: "Eliminar",
        onConfirm: () => {
            handleActionWithBuffer(() => {
                router.delete(route("productos.forceDestroy", { id: producto.id }), {
                    preserveScroll: true,
                    preserveState: true,
                    onFinish: () => { 
                        isProcessing.value = false;
                    },
                });
            });
        },
    });
}
</script>

<template>
  <BaseModalSteps 
    :isOpen="isOpen" 
    :currentStep="1" 
    :totalSteps="1"
    title="Papelera de Reciclaje"
    description="Gestiona los productos eliminados. Puedes restaurarlos o borrarlos definitivamente."
    finalButtonText="Cerrar Papelera" 
    @close="emit('close')" 
    @submit="emit('close')"
  >
    
    <div class="py-2 min-h-[400px]">
        
        <div v-if="productos.length === 0" class="flex flex-col items-center justify-center py-20 border-2 border-dashed border-gray-200 dark:border-gray-700 rounded-2xl bg-gray-50/50 dark:bg-gray-800/30">
            <div class="w-20 h-20 bg-emerald-100 dark:bg-emerald-900/30 rounded-full flex items-center justify-center mb-4 animate-pulse">
                <span class="material-symbols-rounded text-4xl text-emerald-500">check_circle</span>
            </div>
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Todo Limpio</h3>
            <p class="text-sm text-gray-500 mb-6">No hay productos en la papelera.</p>
            <button @click="emit('close')" class="text-primary font-bold hover:underline">Volver al Inventario</button>
        </div>

        <div v-else class="flex flex-col h-full">
            
            <div class="flex justify-between items-center mb-4 px-1">
                <span class="text-sm text-gray-500">
                    <strong class="text-gray-900 dark:text-white">{{ productos.length }}</strong> ítems archivados
                </span>
            </div>

            <div class="border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm bg-white dark:bg-gray-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 dark:bg-gray-800/80 border-b border-gray-200 dark:border-gray-700 text-xs text-gray-500 uppercase font-bold tracking-wider">
                            <tr>
                                <th class="px-6 py-4">Producto</th>
                                <th class="px-6 py-4 text-center">Info. Stock</th>
                                <th class="px-6 py-4">Fecha Eliminación</th>
                                <th class="px-6 py-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-for="producto in productos" :key="producto.id" 
                                class="group hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                                
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-lg bg-gray-100 dark:bg-gray-800 flex-shrink-0 overflow-hidden relative border border-gray-200 dark:border-gray-700">
                                            <img v-if="getPhotoProductUrlFull(producto)" 
                                                 :src="getPhotoProductUrlFull(producto)" 
                                                 class="w-full h-full object-cover">
                                            <div v-else class="w-full h-full flex items-center justify-center text-xs font-bold text-gray-400">
                                                {{ getInitialProduct(producto) }}
                                            </div>
                                        </div>
                                        <div>
                                            <p class="font-bold text-gray-900 dark:text-white line-clamp-1 max-w-[180px]">
                                                {{ producto.nombre }}
                                            </p>
                                            <p class="text-xs text-gray-500 font-mono">
                                                {{ formatCOP(producto.precio) }}
                                            </p>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-6 py-4 text-center">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase border"
                                          :class="getStockBadge(producto).classes">
                                        {{ getStockBadge(producto).label }}
                                    </span>
                                </td>

                                <td class="px-6 py-4 text-gray-500 text-xs">
                                    <div class="flex items-center gap-1.5">
                                        <span class="material-symbols-rounded text-sm text-gray-400">calendar_month</span>
                                        {{ formatFecha(producto.deleted_at) }}
                                    </div>
                                </td>

                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-2 opacity-100 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity">
                                        
                                        <button type="button" @click.stop="restaurarProducto(producto)" 
                                                class="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900/20 dark:text-emerald-400 dark:hover:bg-emerald-900/40 rounded-lg text-xs font-bold transition-colors">
                                            <span class="material-symbols-rounded text-base">restore_from_trash</span>
                                            <span class="hidden lg:inline">Restaurar</span>
                                        </button>

                                        <button type="button" @click.stop="eliminarPermanentemente(producto)" 
                                                class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors"
                                                title="Eliminar permanentemente">
                                            <span class="material-symbols-rounded text-xl">delete_forever</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <ConfirmacionesPop 
        :is-open="confirmationState.isOpen" 
        :title="confirmationState.title"
        :message="confirmationState.message" 
        :icon="confirmationState.icon"
        :icon-bg-class="confirmationState.iconBgClass" 
        :confirm-text="confirmationState.confirmText"
        @close="closeConfirmationModal" 
        @confirm="handleConfirm" 
    />

  </BaseModalSteps>
</template>