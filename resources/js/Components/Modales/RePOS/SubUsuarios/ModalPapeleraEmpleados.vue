<script setup>
import { router } from "@inertiajs/vue3";
import Modal from "@/Components/Shared/Modales/BaseModalSteps.vue"; // Asegúrate que este sea el NUEVO componente

const props = defineProps({
    isOpen: Boolean,
    papelera: { type: Array, default: () => [] },
    aplicacion: String,
    rol: String
});

const emit = defineEmits(["close"]);

// Función Restaurar
const restaurarUsuario = (usuario) => {
    if (confirm(`¿Restaurar a ${usuario.nombre_completo}?`)) {
        router.patch(route('repos.subUsuarios.restoreEmpleado', {
            aplicacion: props.aplicacion,
            rol: props.rol,
            id: usuario.id
        }), {}, { preserveScroll: true });
    }
};

// Función Eliminar Permanente
const eliminarPermanente = (usuario) => {
    if (confirm(`⚠️ IRREVERSIBLE\n\n¿Eliminar definitivamente a "${usuario.nombre_completo}"?`)) {
        router.delete(route('repos.subUsuarios.forceDeleteEmpleado', {
            aplicacion: props.aplicacion,
            rol: props.rol,
            id: usuario.id
        }), { preserveScroll: true });
    }
};
</script>

<template>
    <Modal 
        :isOpen="isOpen" 
        :currentStep="1" 
        :totalSteps="1"
        title="Papelera de Reciclaje"
        description="Gestiona los registros eliminados. Las acciones son inmediatas."
        finalButtonText="Cerrar Panel"
        @close="emit('close')" 
        @submit="emit('close')"
    >
        <div class="space-y-4">
            
            <div v-if="papelera.length === 0" 
                 class="flex flex-col items-center justify-center py-12 px-4 border-2 border-dashed border-gray-200 dark:border-gray-700 rounded-xl bg-gray-50/50 dark:bg-gray-800/30">
                <div class="p-4 bg-white dark:bg-gray-800 rounded-full shadow-sm mb-3">
                    <span class="material-symbols-rounded text-3xl text-gray-400">recycling</span>
                </div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Todo limpio por aquí</h3>
                <p class="text-xs text-gray-500 mt-1 text-center max-w-[200px]">No hay usuarios en la papelera de reciclaje en este momento.</p>
            </div>

            <div v-else class="border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Usuario</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Eliminado</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-for="user in papelera" :key="user.id" class="group hover:bg-gray-50 dark:hover:bg-gray-800/60 transition-colors duration-150">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium text-gray-900 dark:text-white group-hover:text-primary transition-colors">
                                        {{ user.nombre_completo }}
                                    </span>
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                            {{ user.rol }}
                                        </span>
                                        <span class="text-xs text-gray-400">{{ user.documento }}</span>
                                    </div>
                                </div>
                            </td>

                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center gap-1.5 text-xs text-gray-500 font-mono">
                                    <span class="material-symbols-rounded text-[14px]">calendar_today</span>
                                    {{ user.fecha_eliminacion }}
                                </div>
                            </td>

                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end gap-2 opacity-100 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity duration-200">
                                    
                                    <button @click="restaurarUsuario(user)" 
                                        class="group/btn relative p-2 rounded-lg text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/20 transition-all"
                                        title="Restaurar">
                                        <span class="material-symbols-rounded text-xl">settings_backup_restore</span>
                                        </button>

                                    <button @click="eliminarPermanente(user)" 
                                        class="p-2 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 transition-all"
                                        title="Eliminar permanentemente">
                                        <span class="material-symbols-rounded text-xl">delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </Modal>
</template>