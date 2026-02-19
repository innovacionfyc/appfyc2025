<script setup>
import { computed } from 'vue';
import BaseModalSteps from '@/Components/Shared/Modales/BaseModalSteps.vue';

const props = defineProps({
    isOpen: { type: Boolean, required: true },
    producto: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const formatoDinero = (valor) => {
    if (!valor) return '$ 0';
    return new Intl.NumberFormat('es-CO', { 
        style: 'currency', currency: 'COP', maximumFractionDigits: 0 
    }).format(valor);
};

// Título y subtítulo para el BaseModal (aunque el visual principal estará dentro)
const modalTitle = computed(() => props.producto?.nombre || 'Detalle');
const modalDesc = computed(() => 'Explora los detalles de este plato');

</script>

<template>
    <BaseModalSteps
        :is-open="isOpen"
        :current-step="1"
        :total-steps="1"
        :title="modalTitle"
        :description="modalDesc"
        final-button-text="Cerrar"
        :is-form-step="true"
        @close="$emit('close')"
        @submit="$emit('close')"
    >
        <div v-if="producto" class="font-sans">
            
            <div class="relative -mx-8 -mt-8 mb-8 h-72 sm:h-80 bg-gray-100 overflow-hidden border-b border-gray-100 dark:border-gray-800 group">
                
                <img 
                    v-if="producto.imagen_url" 
                    :src="producto.imagen_url" 
                    class="w-full h-full object-cover transition-transform duration-1000 group-hover:scale-110" 
                />
                
                <div v-else class="w-full h-full flex flex-col items-center justify-center text-gray-300 dark:text-gray-600 bg-gray-50 dark:bg-gray-800 relative overflow-hidden">
                    <div class="absolute inset-0 opacity-10 bg-[url('https://www.transparenttextures.com/patterns/food.png')]"></div>
                    <span class="material-symbols-rounded text-7xl relative z-10">restaurant_menu</span>
                </div>
                
                <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-black/30 pointer-events-none"></div>
                
                <div class="absolute top-4 left-4 backdrop-blur-md bg-white/20 border border-white/30 text-white px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider shadow-lg">
                    {{ producto.categoria_nombre || 'Exclusivo' }}
                </div>

                <div class="absolute bottom-4 right-4 backdrop-blur-xl bg-white/90 dark:bg-black/80 text-gray-900 dark:text-white px-5 py-2.5 rounded-2xl shadow-xl border border-white/40 dark:border-gray-700 flex flex-col items-end">
                    <span class="text-[10px] text-gray-500 dark:text-gray-400 font-bold uppercase tracking-widest">Precio</span>
                    <span class="text-2xl font-black tracking-tighter leading-none">
                        {{ formatoDinero(producto.precio) }}
                    </span>
                </div>
            </div>

            <div class="space-y-8 px-2 pb-2">
                
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-orange-50 dark:bg-orange-900/20 p-3 rounded-2xl border border-orange-100 dark:border-orange-800/30 flex items-center gap-3">
                        <div class="bg-white dark:bg-orange-800 p-2 rounded-xl shadow-sm text-orange-500">
                            <span class="material-symbols-rounded text-xl block">schedule</span>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase font-bold text-orange-400 tracking-wide">Tiempo</p>
                            <p class="text-sm font-bold text-gray-800 dark:text-orange-100">~15 min</p>
                        </div>
                    </div>
                    <div class="bg-rose-50 dark:bg-rose-900/20 p-3 rounded-2xl border border-rose-100 dark:border-rose-800/30 flex items-center gap-3">
                        <div class="bg-white dark:bg-rose-800 p-2 rounded-xl shadow-sm text-rose-500">
                            <span class="material-symbols-rounded text-xl block">local_fire_department</span>
                        </div>
                        <div>
                            <p class="text-[10px] uppercase font-bold text-rose-400 tracking-wide">Estado</p>
                            <p class="text-sm font-bold text-gray-800 dark:text-rose-100">Recién hecho</p>
                        </div>
                    </div>
                </div>

                <div class="relative">
                    <h3 class="flex items-center gap-2 text-lg font-bold text-gray-900 dark:text-white mb-3">
                        <span class="w-1 h-6 rounded-full bg-primary"></span>
                        Sobre este plato
                    </h3>
                    <p class="text-gray-600 dark:text-gray-300 text-sm leading-7 font-medium text-justify">
                        {{ producto.descripcion || 'Una deliciosa opción preparada con los mejores ingredientes de la casa. Ideal para disfrutar en cualquier momento.' }}
                    </p>
                </div>

                <div v-if="producto.receta && producto.receta.length > 0">
                    <h3 class="flex items-center gap-2 text-lg font-bold text-gray-900 dark:text-white mb-4">
                        <span class="w-1 h-6 rounded-full bg-teal-500"></span>
                        Lo que lleva dentro
                    </h3>
                    
                    <div class="flex flex-wrap gap-2">
                        <div v-for="ing in producto.receta" :key="ing.id" 
                             class="group flex items-center gap-2 px-3 py-2 rounded-xl bg-gray-50 dark:bg-gray-800 border border-gray-100 dark:border-gray-700 hover:border-teal-200 dark:hover:border-teal-800 hover:bg-teal-50 dark:hover:bg-teal-900/30 transition-all duration-300 cursor-default">
                            <div class="w-1.5 h-1.5 rounded-full bg-teal-400 group-hover:scale-125 transition-transform"></div>
                            <span class="text-xs font-semibold text-gray-600 dark:text-gray-300 group-hover:text-teal-700 dark:group-hover:text-teal-200">
                                {{ ing.nombre }}
                            </span>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </BaseModalSteps>
</template>