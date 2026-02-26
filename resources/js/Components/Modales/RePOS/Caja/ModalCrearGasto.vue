<script setup>
import { defineProps, defineEmits, ref, computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { formatCOP } from '@/Utils/formateoMoneda';
import Modal from '@/Components/Shared/Modales/BaseModalSteps.vue';
import NumberInput from "@/Components/Shared/inputs/NumberInput.vue";
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";

const props = defineProps({
    isOpen: { type: Boolean, required: true },
    currentBalance: { type: Number, default: 0 },
    gastosSesionActual: { type: Array, default: () => [] },
    aplicacion: String,
    rol: String,
});
const emit = defineEmits(['close']);

const activeTab = ref(0);
const tabs = [
    { label: "Historial de Gastos" },
    { label: "Registrar Nuevo" },
];

const form = useForm({
    monto: "",
    descripcion: "",
});

// Computed para saber si estamos en modo formulario
const isFormMode = computed(() => activeTab.value === 1);

// Computed para validación en tiempo real (Feedback visual)
const montoExcedeSaldo = computed(() => {
    const monto = Number(form.monto) || 0;
    return monto > props.currentBalance;
});

function submit() {
    if (montoExcedeSaldo.value) {
        form.setError('monto', `Saldo insuficiente. Tienes ${formatCOP(props.currentBalance)}.`);
        return;
    }
    
    form.post(route('repos.caja.storeGasto', { aplicacion: props.aplicacion, rol: props.rol }), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            // Truco UX: Volvemos al historial para ver el gasto recién creado
            activeTab.value = 0; 
        }
    });
}

function closeModal() {
    emit('close');
    setTimeout(() => {
        form.reset();
        activeTab.value = 0;
    }, 300);
}
</script>

<template>
  <Modal 
    :isOpen="isOpen" 
    :currentStep="1" 
    :totalSteps="1"
    :title="activeTab === 0 ? 'Salidas de Efectivo' : 'Registrar Gasto'"
    description="Gestiona los retiros de dinero de la caja menor."
    :isSubmitting="form.processing" 
    :finalButtonText="activeTab === 0 ? 'Cerrar' : 'Confirmar Gasto'" 
    :tabs="tabs"
    :activeTab="activeTab"
    @update:activeTab="val => activeTab = val"
    @close="closeModal" 
    @submit="isFormMode ? submit() : closeModal()" 
    :isFormStep="isFormMode" 
  >

    <div class="py-2">
        
        <Transition name="fade-slide" mode="out-in">
            <div v-if="activeTab === 0" key="history" class="space-y-4">
                
                <div class="bg-gray-50 dark:bg-gray-800 rounded-xl p-4 flex justify-between items-center border border-gray-100 dark:border-gray-700">
                    <span class="text-sm font-medium text-gray-500">Total Gastado (Hoy)</span>
                    <span class="text-xl font-bold text-gray-900 dark:text-white font-mono">
                        {{ formatCOP(gastosSesionActual.reduce((acc, curr) => acc + Number(curr.monto), 0)) }}
                    </span>
                </div>

                <div class="max-h-[50vh] overflow-y-auto pr-2 scrollbar-thin space-y-3">
                    
                    <div v-if="gastosSesionActual.length === 0" class="flex flex-col items-center justify-center py-10 opacity-60">
                         <div class="w-16 h-16 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mb-3">
                            <span class="material-symbols-rounded text-3xl text-gray-400">account_balance_wallet</span>
                         </div>
                         <p class="text-sm text-gray-500">No hay gastos registrados en esta sesión.</p>
                    </div>

                    <div v-for="gasto in gastosSesionActual" :key="gasto.id" 
                         class="group flex items-center justify-between p-3 rounded-xl bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800 hover:border-gray-300 dark:hover:border-gray-600 transition-colors">
                        
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-red-50 dark:bg-red-900/20 flex items-center justify-center text-red-500 flex-shrink-0">
                                <span class="material-symbols-rounded text-xl">trending_down</span>
                            </div>
                            
                            <div>
                                <p class="text-sm font-bold text-gray-900 dark:text-white leading-tight">
                                    {{ gasto.descripcion }}
                                </p>
                                <p class="text-xs text-gray-400 mt-0.5 flex items-center gap-1">
                                    <span class="material-symbols-rounded text-[10px]">schedule</span>
                                    {{ new Date(gasto.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) }}
                                </p>
                            </div>
                        </div>

                        <div class="text-right">
                            <span class="block font-mono font-bold text-gray-900 dark:text-white">
                                -{{ formatCOP(gasto.monto) }}
                            </span>
                        </div>
                    </div>

                </div>
            </div>

            <div v-else key="form" class="space-y-6 pt-2">
                
                <div class="relative overflow-hidden rounded-xl p-5 text-white transition-all duration-300"
                     :class="montoExcedeSaldo ? 'bg-red-600 shadow-red-500/30 shadow-lg' : 'bg-gray-900 dark:bg-gray-800 shadow-lg'">
                    
                    <div class="relative z-10 flex justify-between items-end">
                        <div>
                            <p class="text-xs font-medium opacity-70 uppercase tracking-wider mb-1">Disponible en Caja</p>
                            <h3 class="text-3xl font-mono font-bold">{{ formatCOP(currentBalance) }}</h3>
                        </div>
                        <div class="p-2 bg-white/10 rounded-lg backdrop-blur-sm">
                             <span class="material-symbols-rounded text-2xl">savings</span>
                        </div>
                    </div>
                    
                    <div class="absolute -right-6 -bottom-10 w-32 h-32 bg-white/10 rounded-full blur-2xl"></div>
                </div>

                <div class="space-y-5">
                    <NumberInput 
                        v-model="form.monto" 
                        label="Monto a Retirar" 
                        icon="payments" 
                        placeholder="Ej: 20000"
                        class="text-lg"
                        :error="form.errors.monto"
                        
                    />

                    <InputTexto 
                        v-model="form.descripcion" 
                        label="Motivo del Gasto" 
                        icon="edit_note" 
                        placeholder="Ej: Compra de insumos de aseo" 
                        :error="form.errors.descripcion"
                        
                    />
                </div>
            </div>
        </Transition>

    </div>
  </Modal>
</template>

<style scoped>
.fade-slide-enter-active,
.fade-slide-leave-active {
  transition: all 0.3s ease;
}
.fade-slide-enter-from {
  opacity: 0;
  transform: translateX(10px);
}
.fade-slide-leave-to {
  opacity: 0;
  transform: translateX(-10px);
}
</style>