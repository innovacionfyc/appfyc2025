<script setup>
import { defineProps, defineEmits, ref, computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { handleInput, limitesCaracteres } from "@/Utils/formateoInputs";
import { formatCOP } from "@/Utils/formateoMoneda";
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";
import Selects from "@/Components/Shared/inputs/Selects.vue";
import NumberInput from "@/Components/Shared/inputs/NumberInput.vue";
import BaseModalSteps from "@/Components/Shared/Modales/BaseModalSteps.vue";

// --- PROPS ---
const props = defineProps({
  isOpen: { type: Boolean, required: true },
  ingrediente: { type: Object, default: null },
  unidadesMedidaDispobibles: { type: Array, default: () => [] },
  categoriasIngredientesDisponibles: { type: Array, default: () => [] },
});
const emit = defineEmits(["close"]);

const currentStep = ref(1);
const totalSteps = 1;

const form = useForm({
  categoria_id: "",
  unidad_medida_id: "",
  nombre: "",
  sku: "",
  stock_actual: 0,
  stock_minimo: 0,
  costo_promedio: 0.0,
  ultimo_costo: 0.0,
  porcentaje_merma: 0,
});
// --- WATCHER ---
watch(
  () => props.ingrediente,
  (newVal) => {
    if (newVal) {
      form.nombre = newVal.nombre ?? "";
      form.unidad_medida_id = newVal.unidad_medida_id;
      form.sku = newVal.sku ?? "";
      form.stock_actual = String(newVal.stock_actual ?? "");
      form.stock_minimo = String(newVal.stock_minimo ?? "");
      form.costo_promedio = newVal.costo_promedio
        ? parseFloat(newVal.costo_promedio)
        : null;
      form.clearErrors();
    }
  },
  { deep: true, immediate: true }
);

// --- COMPUTED VISUAL ---
// Helper para determinar salud del stock
const getStockStatus = (actual, minimo) => {
  if (actual <= 0) return "agotado";
  if (actual <= minimo) return "bajo";
  return "bien";
};

// Color dinámico para la barra de merma
const mermaColor = computed(() => {
  const val = form.porcentaje_merma || 0;
  if (val < 10) return "bg-emerald-500";
  if (val < 30) return "bg-amber-500";
  return "bg-rose-500";
});

// --- ACCIONES ---
function submit() {
  if (!props.ingrediente) return;
  form.post(route("repos.ingredientes.update", props.ingrediente.id), {
    preserveScroll: true,
    onSuccess: () => closeModal(true),
    onError: (e) => console.error(e),
  });
}

// Watcher para UX: Copiar Costo Promedio a Último Costo si está vacío
watch(
  () => form.costo_promedio,
  (newVal) => {
    if (newVal && !form.ultimo_costo) {
      form.ultimo_costo = newVal;
    }
  }
);

function closeModal(force = false) {
  if (form.isDirty && !force) {
    if (confirm("¿Descartar cambios?")) {
      emit("close");
      setTimeout(() => form.reset(), 300);
    }
  } else {
    emit("close");
    setTimeout(() => form.reset(), 300);
  }
}
</script>

<template>
  <BaseModalSteps
    :isOpen="isOpen"
    :currentStep="currentStep"
    :totalSteps="totalSteps"
    :isSubmitting="form.processing"
    title="Editar Ingrediente"
    description="Actualiza el inventario y costos."
    finalButtonText="Guardar Cambios"
    @close="closeModal"
    @submit="submit"
  >
    <div key="create" class="flex flex-col h-full max-w-5xl mx-auto py-4 px-2">
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8">
        <div class="lg:col-span-7 space-y-6">
          <div
            class="bg-white dark:bg-[#0f172a] rounded-[2rem] p-6 shadow-xl shadow-gray-100/50 dark:shadow-none border border-gray-100 dark:border-gray-800"
          >
            <div class="flex items-center gap-3 mb-6">
              <div
                class="p-2 bg-indigo-50 dark:bg-indigo-900/20 rounded-xl text-indigo-500"
              >
                <span class="material-symbols-rounded">fingerprint</span>
              </div>
              <h3 class="font-bold text-gray-800 dark:text-white">Ficha Técnica</h3>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
              <div class="col-span-2">
                <InputTexto
                  v-model="form.nombre"
                  label="Nombre de ingrediente / insumo"
                  icon="hand_meal"
                  placeholder="Papa criolla"
                  class="bg-gray-50 dark:bg-gray-900/50"
                  :error="form.errors.nombre"
                  @input="(e) => handleInput(e, form, 'nombre')"
                />
              </div>

              <div class="col-span-2 sm:col-span-1">
                <InputTexto
                  v-model="form.sku"
                  label="SKU / Código de Barras"
                  icon="qr_code_2"
                  placeholder="Generar Automático"
                  class="bg-gray-50 dark:bg-gray-900/50"
                  :error="form.errors.sku"
                  @input="(e) => handleInput(e, form, 'sku')"
                />
              </div>
              <div class="col-span-2 sm:col-span-1">
                <Selects
                  v-model="form.categoria_id"
                  :options="categoriasIngredientesDisponibles"
                  label="Categoría"
                  icon="category"
                  :error="form.errors.categoria_id"
                />
              </div>
              <div class="col-span-2">
                <Selects
                  v-model="form.unidad_medida_id"
                  :options="unidadesMedidaDispobibles"
                  label="Unidad de Medida Base"
                  icon="scale"
                  :error="form.errors.unidad_medida_id"
                />
                <p class="text-[10px] text-gray-400 mt-1.5 ml-1">
                  * Esta unidad se usará para calcular costos y recetas.
                </p>
              </div>
            </div>
          </div>

          <div
            class="bg-gradient-to-br from-gray-50 to-white dark:from-gray-800 dark:to-gray-900 rounded-[2rem] p-6 border border-gray-200 dark:border-gray-700/50 relative overflow-hidden"
          >
            <div
              class="absolute -right-10 -top-10 w-32 h-32 bg-gray-200/50 dark:bg-black/20 rounded-full blur-2xl"
            ></div>

            <div class="relative z-10">
              <div class="flex justify-between items-end mb-4">
                <div>
                  <label
                    class="text-xs font-black text-gray-500 uppercase tracking-widest"
                    >Factor de Merma</label
                  >
                  <p class="text-xs text-gray-400 mt-1 max-w-[250px]">
                    Porcentaje del insumo que se desperdicia (cáscaras, huesos, etc).
                  </p>
                </div>
                <div
                  class="text-3xl font-black"
                  :class="
                    form.porcentaje_merma > 0
                      ? 'text-gray-800 dark:text-white'
                      : 'text-gray-300'
                  "
                >
                  {{ form.porcentaje_merma || 0
                  }}<span class="text-lg text-gray-400">%</span>
                </div>
              </div>

              <div
                class="h-4 bg-gray-200 dark:bg-gray-700 rounded-full p-1 cursor-pointer group"
              >
                <div
                  class="h-full rounded-full transition-all duration-300 relative"
                  :class="mermaColor"
                  :style="{ width: `${form.porcentaje_merma}%` }"
                >
                  <div
                    class="absolute right-0 top-1/2 -translate-y-1/2 w-4 h-4 bg-white shadow-md rounded-full scale-0 group-hover:scale-125 transition-transform"
                  ></div>
                </div>
                <input
                  type="range"
                  v-model="form.porcentaje_merma"
                  min="0"
                  max="100"
                  class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                />
              </div>
            </div>
          </div>
        </div>

        <div class="lg:col-span-5 space-y-6">
          <div
            class="text-mono-negro rounded-[2rem] p-6 shadow-2xl shadow-blue-900/10 relative overflow-hidden"
          >
            <div
              class="absolute top-0 right-0 w-full h-1 bg-gradient-to-r from-blue-400 to-emerald-400"
            ></div>

            <h4
              class="text-sm font-bold text-secondary uppercase tracking-widest mb-6 flex items-center gap-2"
            >
              <span class="material-symbols-rounded">inventory</span> Inventario
            </h4>

            <div class="space-y-5">
              <div class="flex items-center justify-between gap-2">
                <InputTexto
                  v-model="form.stock_actual"
                  label="Stock actual"
                  icon="pin"
                  placeholder="100"
                  :error="form.errors.stock_actual"
                  @input="(e) => handleInput(e, form, 'stock_actual')"
                />
              </div>

              <div>
                <InputTexto
                  v-model="form.stock_minimo"
                  label="Stock mínimo para informar"
                  icon="info"
                  placeholder="5"
                  :error="form.errors.stock_minimo"
                  @input="(e) => handleInput(e, form, 'stock_minimo')"
                />
              </div>
            </div>
          </div>

          <div
            class="bg-white dark:bg-gray-800 rounded-[2rem] p-6 border border-gray-100 dark:border-gray-700 shadow-lg"
          >
            <h4
              class="text-sm font-bold text-gray-400 uppercase tracking-widest mb-5 flex items-center gap-2"
            >
              <span class="material-symbols-rounded">monetization_on</span> Valoración
            </h4>

            <div class="space-y-4">
              <div class="relative group">
                <NumberInput
                  v-model="form.costo_promedio"
                  label="Costo Promedio (COP)"
                  icon="paid"
                  placeholder="0"
                  class="bg-amber-50/50 dark:bg-amber-900/10 border-amber-100 dark:border-amber-800/30"
                  :error="form.errors.costo_promedio"
                  @input="(e) => handleInput(e, form, 'costo_promedio')"
                />
              </div>

              <NumberInput
                v-model="form.ultimo_costo"
                label="Último Costo de Compra"
                icon="receipt"
                placeholder="0"
                :error="form.errors.ultimo_costo"
                @input="(e) => handleInput(e, form, 'ultimo_costo')"
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  </BaseModalSteps>
</template>
