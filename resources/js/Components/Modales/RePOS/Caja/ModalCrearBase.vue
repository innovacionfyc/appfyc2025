<script setup>
import { defineProps, defineEmits, watch } from "vue";
import { useForm, router } from "@inertiajs/vue3";
import {  handleInput, limitesCaracteres } from "@/Utils/formateoInputs";
import Modal from '@/Components/Shared/Modales/BaseModalSteps.vue';
import NumberInput from "@/Components/Shared/inputs/NumberInput.vue";

const props = defineProps({
    isOpen: { type: Boolean, required: true },
    aplicacion: { type: String, required: true },
    rol: { type: String, required: true },
});

const emit = defineEmits(['close']);

const form = useForm({
    monto_inicial: 0,
});

// Watcher para resetear al abrir
watch(() => props.isOpen, (newVal) => {
    if (newVal) {
        form.reset();
        form.clearErrors();
    }
});

// Lógica de cierre
function closeModal(force = false) {
    if (form.isDirty && !force && form.monto_inicial > 0) {
        if (confirm('¿Cancelar la apertura de caja?')) {
            emit('close');
            setTimeout(() => form.reset(), 300);
        }
    } else {
        emit('close');
        setTimeout(() => form.reset(), 300);
    }
}

// Lógica de envío
const submit = () => {
    form.post(
        route('repos.caja.abrir', { aplicacion: props.aplicacion, rol: props.rol }),
        {
            preserveScroll: true,
            onSuccess: () => {
                closeModal(true);
                // Recarga parcial inteligente
                router.reload({ only: ['currentBalance', 'hasOpenCashRegister'] });
            },
            onError: (errors) => {
                // Si hay error global, Inertia lo maneja, pero si quieres alertar:
                if (Object.keys(errors).length > 0) {
                    console.error("Error al abrir caja:", errors);
                }
            }
        }
    );
};
</script>

<template>
    <Modal 
        :isOpen="isOpen" 
        :currentStep="1" 
        :totalSteps="1"
        :isSubmitting="form.processing" 
        title="Apertura de Caja"
        description="Ingresa el dinero base en efectivo para iniciar operaciones."
        finalButtonText="Confirmar Apertura" 
        @close="closeModal" 
        @submit="submit"
    >
        <div class="flex flex-col items-center justify-center py-6 px-2 space-y-8">
            
            <div class="relative">
                <div class="w-20 h-20 bg-emerald-50 dark:bg-emerald-900/20 rounded-full flex items-center justify-center shadow-inner">
                    <span class="material-symbols-rounded text-4xl text-emerald-600 dark:text-emerald-400">payments</span>
                </div>
                <div class="absolute bottom-0 right-0 bg-white dark:bg-gray-800 rounded-full p-1 shadow-md">
                    <span class="material-symbols-rounded text-sm text-gray-400">lock_open</span>
                </div>
            </div>

            <div class="w-full max-w-sm space-y-4">
                <div class="relative group">
                    <NumberInput 
                        v-model="form.monto_inicial" 
                        label="Monto Inicial (Efectivo)" 
                        icon="attach_money" 
                        placeholder="Ej. 50.000" 
                        :maxLength="limitesCaracteres.precio_producto" 
                        :error="form.errors.monto_inicial"
                        class="text-lg"
                        @input="(e) => handleInput(e, form, 'monto_inicial')" 
                    />
                </div>
                
                <div class="text-center">
                   <p class="text-xs text-gray-500 dark:text-gray-400">
                       💡 Asegúrate de contar el dinero físico antes de ingresar el monto.
                   </p>
                </div>
            </div>

            <div v-if="form.monto_inicial" class="animate-pulse w-full max-w-xs bg-gray-50 dark:bg-gray-800 rounded-lg p-3 border border-gray-100 dark:border-gray-700 text-center">
                 <p class="text-xs text-gray-500 uppercase tracking-wide">Saldo Inicial Registrado</p>
                 <p class="text-xl font-bold text-gray-900 dark:text-white font-mono mt-1">
                     $ {{ form.monto_inicial }}
                 </p>
            </div>

        </div>
    </Modal>
</template>