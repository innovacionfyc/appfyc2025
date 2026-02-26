<script setup>
import { defineProps, defineEmits, ref, computed, watch } from "vue";
import { useForm, router } from "@inertiajs/vue3";
import { handleInput, limitesCaracteres } from "@/Utils/formateoInputs";
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";
import Selects from "@/Components/Shared/inputs/Selects.vue";
import NumberInput from "@/Components/Shared/inputs/NumberInput.vue";
import BaseModalSteps from "@/Components/Shared/Modales/BaseModalSteps.vue";
import Pagination from "@/Components/Shared/Pagination.vue";
import { formatFecha } from "@/Utils/date";
import { formatCOP } from "@/Utils/formateoMoneda";
import BarraBusqueda from "@/Components/Shared/barraBusqueda/barraBusqueda.vue";

const props = defineProps({
  isOpen: { type: Boolean, required: true },
  unidadesMedidaDispobibles: { type: Array, default: () => [] },
  categoriasIngredientesDisponibles: { type: Array, default: () => [] },
  ingredientes: { type: Object, required: true },
  filters: { type: Object, default: () => ({}) },
});

const emit = defineEmits(["close", "edit"]);

// --- ESTADO ---
const activeTab = ref(0);
const search = ref(props.filters.search || "");
let searchTimeout = null;

const tabs = [{ label: "Estantería (Inventario)" }, { label: "Registrar Ingrediente" }];

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

const isFormMode = computed(() => activeTab.value === 1);

// --- LÓGICA ---

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

function submit() {
  form.post(route("repos.ingredientes.create"), {
    preserveScroll: true,
    onSuccess: () => {
      activeTab.value = 0;
      form.reset();
    },
  });
}

function closeModal(force = false) {
  if (form.isDirty && !force) {
    if (confirm("¿Descartar cambios?")) {
      emit("close");
      setTimeout(() => {
        form.reset();
        activeTab.value = 0;
      }, 300);
    }
  } else {
    emit("close");
    setTimeout(() => {
      form.reset();
      activeTab.value = 0;
    }, 300);
  }
}

// Paginación via Inertia
function handlePaginationClick(event) {
  const link = event.target.closest("a");
  if (!link || !link.href) return;

  router.get(
    link.href,
    {},
    {
      preserveState: true,
      only: ["ingredientes"],
      preserveScroll: true,
    }
  );
}

// Búsqueda Servidor (Debounce)
watch(search, (newValue) => {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    router.get(
      window.location.href,
      { search: newValue },
      { preserveState: true, preserveScroll: true, only: ["ingredientes"], replace: true }
    );
  }, 300);
});

// Watcher para UX: Copiar Costo Promedio a Último Costo si está vacío
watch(
  () => form.costo_promedio,
  (newVal) => {
    if (newVal && !form.ultimo_costo) {
      form.ultimo_costo = newVal;
    }
  }
);

const groupedIngredientes = computed(() => {
  if (!props.ingredientes.data) return {};
  return props.ingredientes.data.reduce((groups, item) => {
    const category = item.categoria || { nombre: 'Sin Clasificar', icono: 'inventory_2', color_hex: '#94a3b8' };
    const key = category.nombre;
    if (!groups[key]) {
      groups[key] = { info: category, items: [] };
    }
    groups[key].items.push(item);
    return groups;
  }, {});
});
</script>

<template>
  <BaseModalSteps
    :isOpen="isOpen"
    :currentStep="1"
    :totalSteps="1"
    :title="activeTab === 0 ? 'Gestión de Despensa' : 'Nuevo Ingrediente'"
    :description="
      activeTab === 0
        ? 'Controla el stock y costos de tus insumos.'
        : 'Añade un nuevo ítem a tu inventario.'
    "
    :isSubmitting="form.processing"
    :finalButtonText="activeTab === 0 ? 'Cerrar' : 'Guardar Ingrediente'"
    :tabs="tabs"
    :activeTab="activeTab"
    @update:activeTab="(val) => (activeTab = val)"
    @close="closeModal"
    @submit="isFormMode ? submit() : closeModal()"
    :isFormStep="isFormMode"
  >
    <div class="py-2">
      <Transition name="fade-slide" mode="out-in">
      <div v-if="activeTab === 0" key="inventory" class="space-y-8 animate-in fade-in duration-700">
    
    <div class="flex flex-col md:flex-row gap-6 justify-between items-center bg-white dark:bg-[#0B0F1A] p-8 rounded-[3rem] border border-gray-100 dark:border-gray-800 shadow-xl shadow-gray-200/20 dark:shadow-none">
      <div class="flex items-center gap-6">
        <div class="w-16 h-16 bg-primary/10 rounded-[2rem] flex items-center justify-center text-primary relative">
          <span class="material-symbols-rounded text-4xl">analytics</span>
          <span class="absolute -top-1 -right-1 flex h-4 w-4">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-primary opacity-75"></span>
            <span class="relative inline-flex rounded-full h-4 w-4 bg-primary"></span>
          </span>
        </div>
        <div>
          <h3 class="text-2xl font-black text-gray-900 dark:text-white tracking-tighter uppercase italic">Control de Insumos</h3>
          <div class="flex items-center gap-2 mt-1">
            <span class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">{{ ingredientes.total }} Ítems Registrados</span>
          </div>
        </div>
      </div>

      <div class="w-full md:w-96 relative group">
        <div class="absolute inset-0 bg-primary/5 blur-xl group-hover:bg-primary/10 transition-all rounded-full"></div>
        <BarraBusqueda
          v-model="search"
          placeholder="Buscar insumo, SKU o categoría..."
          class="relative z-10 w-full"
        />
      </div>
    </div>

    <div class="bg-white dark:bg-[#0B0F1A] rounded-[3rem] border border-gray-100 dark:border-gray-800 overflow-hidden shadow-2xl shadow-gray-200/10">
      <div class="overflow-x-auto scrollbar-hide">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-gray-50/50 dark:bg-gray-900/50">
              <th class="px-10 py-5 text-[10px] font-black text-gray-400 uppercase tracking-[0.3em]">Insumo</th>
              <th class="px-6 py-5 text-[10px] font-black text-gray-400 uppercase tracking-[0.3em] text-center">Disponibilidad</th>
              <th class="px-6 py-5 text-[10px] font-black text-gray-400 uppercase tracking-[0.3em] text-right">Costo Unitario</th>
              <th class="px-10 py-5 text-[10px] font-black text-gray-400 uppercase tracking-[0.3em] text-right">Acciones</th>
            </tr>
          </thead>

          <tbody v-if="Object.keys(groupedIngredientes).length > 0">
            <template v-for="(group, catName) in groupedIngredientes" :key="catName">
              
              <tr>
                <td colspan="4" class="px-8 py-4 bg-gray-50/30 dark:bg-white/[0.02] border-y border-gray-50 dark:border-gray-800/50">
                  <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                      <div 
                        class="w-10 h-10 rounded-2xl flex items-center justify-center text-white shadow-lg"
                        :style="{ backgroundColor: group.info.color_hex, boxShadow: `0 8px 20px ${group.info.color_hex}40` }"
                      >
                        <span class="material-symbols-rounded text-xl">{{ group.info.icono }}</span>
                      </div>
                      <div>
                        <span class="text-sm font-black text-gray-900 dark:text-white uppercase tracking-tighter italic">
                          {{ catName }}
                        </span>
                        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">{{ group.items.length }} Referencias vinculadas</p>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>

              <tr 
                v-for="item in group.items" 
                :key="item.id"
                class="group hover:bg-gray-50/50 dark:hover:bg-primary/[0.02] transition-all duration-300"
              >
                <td class="px-10 py-5">
                  <div class="flex items-center gap-4">
                    <div class="relative">
                      <div 
                        class="w-12 h-12 rounded-[1.2rem] bg-gray-100 dark:bg-gray-800 flex items-center justify-center font-black text-xs text-gray-500 border border-gray-100 dark:border-gray-700 group-hover:scale-110 transition-transform duration-500"
                      >
                        {{ item.nombre.substring(0, 2).toUpperCase() }}
                      </div>
                      <div class="absolute -bottom-1 -right-1 w-4 h-4 rounded-full border-2 border-white dark:border-gray-900" :style="{ backgroundColor: group.info.color_hex }"></div>
                    </div>
                    <div>
                      <p class="font-bold text-gray-900 dark:text-white text-base tracking-tight leading-none mb-1">{{ item.nombre }}</p>
                      <div class="flex items-center gap-2">
                        <span class="text-[10px] font-mono font-bold text-primary bg-primary/5 px-2 py-0.5 rounded-md">
                          {{ item.sku || 'N/A-REF' }}
                        </span>
                      </div>
                    </div>
                  </div>
                </td>

                <td class="px-6 py-5">
                  <div class="flex flex-col items-center">
                    <div 
                      class="px-4 py-1.5 rounded-xl text-[11px] font-black flex items-center gap-2 border transition-all"
                      :class="{
                        'bg-red-50 text-red-600 border-red-100 dark:bg-red-500/10 dark:text-red-400 dark:border-red-500/20': getStockStatus(item.stock_actual, item.stock_minimo) === 'agotado',
                        'bg-amber-50 text-amber-600 border-amber-100 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/20': getStockStatus(item.stock_actual, item.stock_minimo) === 'bajo',
                        'bg-emerald-50 text-emerald-600 border-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-400 dark:border-emerald-500/20': getStockStatus(item.stock_actual, item.stock_minimo) === 'bien',
                      }"
                    >
                      <span class="w-2 h-2 rounded-full animate-pulse" :style="{ backgroundColor: item.stock_actual <= item.stock_minimo ? '#f59e0b' : '#10b981' }"></span>
                      {{ item.stock_actual }} {{ item.unidad_medida?.abreviatura }}
                    </div>
                    <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mt-1.5">Mínimo: {{ item.stock_minimo }}</p>
                  </div>
                </td>

                <td class="px-6 py-5 text-right">
                  <p class="text-sm font-black text-gray-900 dark:text-gray-200 font-mono italic">
                    {{ formatCOP(item.costo_promedio) }}
                  </p>
                </td>

                <td class="px-10 py-5 text-right">
                  <div class="flex justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                    <button @click="emit('edit', item)" class="w-10 h-10 rounded-xl bg-gray-50 dark:bg-gray-800 text-gray-400 hover:text-primary hover:bg-primary/10 transition-all flex items-center justify-center border border-transparent hover:border-primary/20 shadow-sm">
                      <span class="material-symbols-rounded text-xl">edit_note</span>
                    </button>
                    <button type="button" @click="confirmarEliminar(item)" class="w-10 h-10 rounded-xl bg-red-50 dark:bg-red-900/10 text-red-400 hover:bg-red-500 hover:text-white transition-all flex items-center justify-center">
                      <span class="material-symbols-rounded text-xl">delete</span>
                    </button>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <div class="bg-gray-50/50 dark:bg-black/20 p-8 border-t border-gray-100 dark:border-gray-800 flex justify-center backdrop-blur-md">
        <Pagination
          :links="ingredientes.links"
          :total="ingredientes.total"
          @click.prevent="handlePaginationClick"
        />
      </div>
    </div>
  </div>

        <div v-else key="create" class="flex flex-col h-full max-w-5xl mx-auto py-4 px-2">
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
      </Transition>
    </div>
  </BaseModalSteps>
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

/* Remove spinner from number inputs */
input[type="number"]::-webkit-inner-spin-button,
input[type="number"]::-webkit-outer-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
</style>
