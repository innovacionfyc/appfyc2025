<script setup>
import { defineProps, defineEmits, ref, computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import BaseModalSteps from '@/Components/Shared/Modales/BaseModalSteps.vue';
import NumberInput from "@/Components/Shared/inputs/NumberInput.vue";
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";

const props = defineProps({
  isOpen: Boolean,
  aplicacion: String,
  rol: String,
  montoCalculadoCaja: { type: Number, default: 0 }
});

const emit = defineEmits(['close']);
const currentStep = ref(1);

const form = useForm({
  monto_final_real: "",
  notas_cierre: "",
});

// --- LÓGICA DE CÁLCULO ---
const diferencia = computed(() => {
  const contado = Number(form.monto_final_real) || 0;
  const esperado = props.montoCalculadoCaja || 0;
  return contado - esperado;
});

const estadoDiferencia = computed(() => {
  if (form.monto_final_real === "" || form.monto_final_real === null) return 'waiting';
  const diff = diferencia.value;
  if (Math.abs(diff) < 50) return 'perfecto'; // Margen de error ínfimo de 50 pesos
  if (diff < 0) return 'falta';
  return 'sobra';
});

// Formateador de moneda rápido
const formatCurrency = (val) => {
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(val);
};

watch(() => props.isOpen, (val) => {
  if (val) { form.reset(); form.clearErrors(); }
});

const submit = () => {
  // Validación de seguridad (UX)
  const diffAbs = Math.abs(diferencia.value);

  // Si la diferencia es grande (ej: más de 50.000), pedimos doble confirmación visual
  if (diffAbs > 50000 && estadoDiferencia.value !== 'waiting') {
    if (!confirm(`⚠️ ATENCIÓN ⚠️\n\nHay una diferencia GRANDE de ${formatCurrency(diferencia.value)}.\n\n¿Estás realmente seguro de cerrar así?`)) {
      return;
    }
  } else if (diffAbs > 1000) {
    if (!confirm(`Hay una diferencia de ${formatCurrency(diferencia.value)}. ¿Confirmar cierre?`)) {
      return;
    }
  }

  form.post(route('repos.caja.cerrar', { aplicacion: props.aplicacion, rol: props.rol }), {
    onSuccess: () => emit('close'),
  });
};
</script>

<template>
  <BaseModalSteps :isOpen="isOpen" v-model:currentStep="currentStep" :totalSteps="1" :isSubmitting="form.processing"
    title="Cierre de Caja" description="Finaliza el turno y cuadra el efectivo." finalButtonText="Confirmar Cierre"
    @close="emit('close')" @submit="submit">

    <div class="space-y-6 pt-2 pb-4">

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

        <div
          class="bg-gray-50 dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 flex flex-col justify-between">
          <div class="flex items-center gap-2 mb-2">
            <div class="p-1.5 bg-blue-100 dark:bg-blue-900/30 rounded-lg text-blue-600 dark:text-blue-400">
              <span class="material-symbols-rounded text-lg">dns</span>
            </div>
            <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Esperado en Sistema</span>
          </div>
          <div class="text-2xl sm:text-3xl font-mono font-bold text-gray-900 dark:text-white tracking-tight">
            {{ formatCurrency(props.montoCalculadoCaja) }}
          </div>
        </div>

        <div
          class="relative bg-white dark:bg-gray-900 rounded-xl p-4 border-2 transition-colors duration-300 flex flex-col justify-between"
          :class="[
            estadoDiferencia === 'perfecto' ? 'border-emerald-500 ring-4 ring-emerald-500/10' :
              estadoDiferencia === 'falta' ? 'border-red-300 ring-4 ring-red-500/10' :
                estadoDiferencia === 'sobra' ? 'border-amber-300 ring-4 ring-amber-500/10' :
                  'border-gray-200 dark:border-gray-700 focus-within:border-primary'
          ]">

          <div class="flex items-center gap-2 mb-1">
            <div class="p-1.5 rounded-lg text-white transition-colors duration-300" :class="[
              estadoDiferencia === 'perfecto' ? 'bg-emerald-500' :
                estadoDiferencia === 'falta' ? 'bg-red-500' :
                  estadoDiferencia === 'sobra' ? 'bg-amber-500' :
                    'bg-gray-400'
            ]">
              <span class="material-symbols-rounded text-lg">payments</span>
            </div>
            <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Tu Conteo Físico</span>
          </div>

          <div class="relative">
            <NumberInput v-model="form.monto_final_real" placeholder="Ej: 350000" icon="attach_money"
              label="Monto Final Contado ($)" class="text-lg" />
          </div>
        </div>
      </div>

      <div v-if="estadoDiferencia !== 'waiting'"
        class="rounded-xl p-4 flex items-center gap-4 transition-all duration-500 animate-[fadeIn_0.3s_ease-out]"
        :class="[
          estadoDiferencia === 'perfecto' ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-800 dark:text-emerald-200' :
            estadoDiferencia === 'falta' ? 'bg-red-50 dark:bg-red-900/20 text-red-800 dark:text-red-200' :
              'bg-amber-50 dark:bg-amber-900/20 text-amber-800 dark:text-amber-200'
        ]">

        <div class="flex-shrink-0">
          <span v-if="estadoDiferencia === 'perfecto'"
            class="material-symbols-rounded text-4xl animate-bounce">check_circle</span>
          <span v-else-if="estadoDiferencia === 'falta'" class="material-symbols-rounded text-4xl">trending_down</span>
          <span v-else class="material-symbols-rounded text-4xl">trending_up</span>
        </div>

        <div class="flex-1">
          <h4 class="font-bold text-lg leading-tight">
            <span v-if="estadoDiferencia === 'perfecto'">¡Caja Cuadrada!</span>
            <span v-else-if="estadoDiferencia === 'falta'">Faltante de dinero</span>
            <span v-else>Sobrante de dinero</span>
          </h4>
          <div class="text-sm opacity-90 mt-1 flex items-center gap-2 font-mono">
            <span v-if="estadoDiferencia !== 'perfecto'">Diferencia:</span>
            <span class="font-bold text-lg">
              {{ diferencia > 0 ? '+' : '' }}{{ formatCurrency(diferencia) }}
            </span>
          </div>
        </div>
      </div>

      <div class="pt-2">
        <InputTexto v-model="form.notas_cierre" label="Notas / Observaciones" icon="edit_note"
          placeholder="Ej. Se dejó la base en sencillo..." />
      </div>
    </div>

  </BaseModalSteps>
</template>

<style scoped>
@keyframes fadeIn {
  from {
    opacity: 0;
    transform: translateY(5px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>