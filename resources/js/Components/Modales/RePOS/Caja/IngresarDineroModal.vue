<script setup>
import { defineProps, defineEmits, watch } from "vue";
import { useForm, router } from "@inertiajs/vue3";
import {  handleInput, limitesCaracteres } from "@/Utils/formateoInputs";
import Modal from '@/Components/Shared/Modales/BaseModalSteps.vue';
import NumberInput from "@/Components/Shared/inputs/NumberInput.vue";
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";

const props = defineProps({
  isOpen: Boolean,
  aplicacion: String,
  rol: String,
});

const emit = defineEmits(['close']);

const form = useForm({
  monto: "",
  descripcion: "",
});

// Watcher para resetear form al abrir
watch(() => props.isOpen, (val) => {
  if (val) {
    form.reset();
    form.clearErrors();
  }
});

const submit = () => {
  form.post(route('repos.caja.ingreso', { aplicacion: props.aplicacion, rol: props.rol }), {
    preserveScroll: true,
    onSuccess: () => {
        emit('close');
        // Opcional: Recargar datos financieros si es necesario
        // router.reload({ only: ['currentBalance'] }); 
        form.reset();
    },
  });
};
</script>

<template>
  <Modal 
    :isOpen="isOpen" 
    :currentStep="1" 
    :totalSteps="1"
    :isSubmitting="form.processing" 
    title="Ingreso de Dinero"
    description="Registra entradas extraordinarias de efectivo."
    finalButtonText="Confirmar Ingreso" 
    @close="emit('close')"
    @submit="submit">

    <div class="flex flex-col items-center justify-center py-4 px-2 space-y-6">

        <div class="relative">
            <div class="w-16 h-16 bg-emerald-100 dark:bg-emerald-900/30 rounded-2xl flex items-center justify-center rotate-3 transform transition-transform hover:rotate-6">
                <span class="material-symbols-rounded text-3xl text-emerald-600 dark:text-emerald-400">add_card</span>
            </div>
            <div class="absolute -top-2 -right-2 bg-white dark:bg-gray-800 rounded-full p-1 shadow-sm border border-gray-100 dark:border-gray-700">
                <span class="material-symbols-rounded text-xs text-emerald-500 font-bold">arrow_upward</span>
            </div>
        </div>

        <div class="w-full max-w-md bg-amber-50/50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/30 rounded-xl p-4 flex gap-4">
            <div class="flex-shrink-0 mt-1">
                <span class="material-symbols-rounded text-amber-500 text-xl">info</span>
            </div>
            <div class="text-sm">
                <h4 class="font-bold text-amber-700 dark:text-amber-500 mb-1">Uso exclusivo para base o aportes</h4>
                <p class="text-amber-800/70 dark:text-amber-400/80 leading-relaxed">
                    No utilices esta opción para registrar ventas. Las ventas se calculan automáticamente en el sistema POS.
                </p>
            </div>
        </div>

        <div class="w-full max-w-sm space-y-5 pt-2">
            
            <NumberInput 
                v-model="form.monto" 
                label="Monto a Ingresar" 
                placeholder="Ej: 50.000"
                icon="attach_money"
                class="text-lg"
                :error="form.errors.monto"
              
                @input="(e) => handleInput(e, form, 'monto')"
            />

            <InputTexto 
                v-model="form.descripcion" 
                label="Motivo del ingreso" 
                placeholder="Ej: Base adicional, cambio para sencillo..." 
                icon="edit_note"
                :error="form.errors.descripcion"
               
                @input="(e) => handleInput(e, form, 'descripcion')" 
            />
        </div>

    </div>
  </Modal>
</template>