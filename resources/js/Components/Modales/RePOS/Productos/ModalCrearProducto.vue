<script setup>
import { defineProps, defineEmits, ref, computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { handleInput, limitesCaracteres } from "@/Utils/formateoInputs";
import { formatCOP } from "@/Utils/formateoMoneda";
import { getFotoProductoUrlCompleta } from "@/Utils/ImagenUsuarios";
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";
import Selects from "@/Components/Shared/inputs/Selects.vue";
import NumberInput from "@/Components/Shared/inputs/NumberInput.vue";
import Checkbox from "@/Components/Shared/checkBox/Checkbox.vue";
import BaseModalSteps from "@/Components/Shared/Modales/BaseModalSteps.vue";

const props = defineProps({
  isOpen: { type: Boolean, required: true },
  categoriasDisponibles: { type: Array, default: () => [] },
  TipoProductoDisponibles: { type: Array, default: () => [] },
  unidadesMedidaDispobibles: { type: Array, default: () => [] },
  ingredientesDisponibles: { type: Array, default: () => [] },
  areasProduccionDisponibles: { type: Array, default: () => [] },
});

const emit = defineEmits(["close"]);

// --- ESTADO LOCAL ---
const currentStep = ref(1);
const totalSteps = 2;
const activeIngredientIndex = ref(null);
const hasDiscount = ref(false); // Toggle para mostrar input descuento
const hasTax = ref(false); // Toggle para mostrar input IVA

const form = useForm({
  nombre: "",
  categoria_id: "",
  tipo: "SIMPLE",
  plu_item: "",
  descripcion: "",
  precio: 0,
  costo_base: 0,
  porcentaje_impuesto: 0,
  precio_incluye_impuesto: true,
  porcentaje_descuento: 0,
  imagen_url: null,
  quitar_imagen: false,
  area_produccion_id: "",
  maneja_inventario: true,
  stock_actual: 0,
  stock_minimo: 0,
  ingredientes: [],
});

const isRecipe = computed(() => form.tipo === "COMPUESTO");

// --- LÓGICA DE IMAGEN ---
function onFileChange(event) {
  const file = event.target.files[0];
  if (file) {
    form.imagen_url = file;
    form.quitar_imagen = false;
  }
}

function quitarImagen() {
  form.imagen_url = null;
  form.quitar_imagen = true;
  const input = document.getElementById("imagen_producto_crear");
  if (input) input.value = "";
}

const imagePreviewUrl = computed(() => {
  if (form.quitar_imagen) return null;
  if (form.imagen_url instanceof File) return URL.createObjectURL(form.imagen_url);
  if (typeof form.imagen_url === "string" && form.imagen_url)
    return getFotoProductoUrlCompleta(form.imagen_url);
  return null;
});

// --- 🚀 LÓGICA MAESTRA DE INGREDIENTES ---

// Función para mostrar máximo 2 decimales de forma limpia
const formatNumber = (val) => {
  const n = parseFloat(val);
  if (isNaN(n)) return "0.00";
  // Esto quita decimales innecesarios (ej: 1.20 en lugar de 1.20000)
  return n.toLocaleString("en-US", {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3,
  });
};

function handleIngredientChange(index) {
  const item = form.ingredientes[index];
  const master = props.ingredientesDisponibles.find(
    (i) => Number(i.id) === Number(item.ingrediente_id)
  );

  if (master) {
    // 1. Buscamos la unidad que tiene el ingrediente en BODEGA (ej. Litro ID 19)
    const unitBodega = props.unidadesMedidaDispobibles.find(
      (u) => Number(u.id) === Number(master.unidad_medida_id)
    );

    if (unitBodega) {
      // 2. FORZAR CAMBIO A UNIDAD BASE (ML / G)
      // Si seleccionas Litro (19), baseId será 18 (Mililitro).
      const baseId = unitBodega.base_unidad_id
        ? Number(unitBodega.base_unidad_id)
        : Number(unitBodega.id);

      // Esto cambia el Select de la interfaz a 'ml' o 'g' automáticamente
      item.unidad_medida_id = baseId;

      // 3. OBTENEMOS LA UNIDAD DE LA RECETA PARA EL CÁLCULO
      const unitReceta = props.unidadesMedidaDispobibles.find(
        (u) => Number(u.id) === baseId
      );

      if (unitReceta) {
        // Leemos los factores directamente de la base de datos
        const fBodega = Number(unitBodega.factor_conversion); // Ej: 1000.00
        const fReceta = Number(unitReceta.factor_conversion); // Ej: 1.00
        const stockBodega = Number(master.stock_actual); // Ej: 80.00
        const cantDigitada = Number(item.cantidad) || 0; // Ej: 200

        // 4. MATEMÁTICA: ¿Cuánto consumen esos 200ml en la unidad de bodega (L)?
        // Fórmula: (Cantidad * Factor Unidad Receta) / Factor Unidad Bodega
        // Ejemplo: (200 * 1) / 1000 = 0.2 Litros
        const consumoEnBodega = (cantDigitada * fReceta) / fBodega;

        // 5. VALIDACIÓN DE STOCK
        if (consumoEnBodega > stockBodega) {
          // Si intenta usar más de 80.000 ml, lo limitamos al stock máximo
          const maximoPermitido = (stockBodega * fBodega) / fReceta;
          item.cantidad = maximoPermitido;
          item.stock_restante_texto = "0.000";
        } else {
          // Calculamos lo que queda: 80 - 0.2 = 79.800
          const restante = stockBodega - consumoEnBodega;
          item.stock_restante_texto = restante.toFixed(3);
        }
        const restante = stockBodega - consumoEnBodega;
        item.stock_restante_texto = restante.toFixed(3); // Cambiado a 2 decimales
        item.unidad_bodega_abrev = unitBodega.abreviatura; // "L"
      }
    }
  }
}

// 3. COSTO BASE AUTOMÁTICO
const costoRecetaCalculado = computed(() => {
  return form.ingredientes.reduce((total, item) => {
    const master = props.ingredientesDisponibles.find(
      (i) => i.id === item.ingrediente_id
    );
    const costo = master ? Number(master.costo_promedio) || 0 : 0;
    return total + costo * (Number(item.cantidad) || 0);
  }, 0);
});

// 9. CANTIDADES QUE SALEN (STOCK CALCULADO)
const maxProduction = computed(() => {
  if (!isRecipe.value || form.ingredientes.length === 0) return 0;
  const capacidades = form.ingredientes.map((item) => {
    const master = props.ingredientesDisponibles.find(
      (i) => i.id === item.ingrediente_id
    );
    if (!master || !item.cantidad || item.cantidad <= 0) return 999999;
    return Math.floor(master.stock_actual / item.cantidad);
  });
  const final = Math.min(...capacidades);
  return final === 999999 ? 0 : final;
});

// Sincronización
watch(
  [costoRecetaCalculado, maxProduction, isRecipe],
  ([newCosto, newStock, recipeMode]) => {
    if (recipeMode) {
      form.costo_base = newCosto;
      form.stock_actual = newStock;
    }
  }
);

// --- FINANZAS ---
const valorAhorro = computed(() =>
  Math.round((form.precio || 0) * ((form.porcentaje_descuento || 0) / 100))
);
const precioFinal = computed(() => (form.precio || 0) - valorAhorro.value);
const utilidad = computed(() => precioFinal.value - (form.costo_base || 0));
const margen = computed(() =>
  precioFinal.value <= 0 ? 0 : Math.round((utilidad.value / precioFinal.value) * 100)
);

// --- UI HELPERS ---
const initialName = computed(() => (form.nombre || "NP").substring(0, 2).toUpperCase());
const getMasterData = (id) => props.ingredientesDisponibles.find((i) => i.id === id);
const getUnitAbrev = (id) =>
  props.unidadesMedidaDispobibles.find((u) => u.id === id)?.abreviatura || "ud";

watch(
  () => form.tipo,
  (newVal) => {
    if (newVal === "COMPUESTO" && form.ingredientes.length === 0) {
      agregarIngrediente();
    }
  }
);

function agregarIngrediente() {
  form.ingredientes.push({ ingrediente_id: null, cantidad: "", unidad_medida_id: null });
  activeIngredientIndex.value = form.ingredientes.length - 1;
}

function handleNext() {
  if (currentStep.value === 1 && (!form.nombre || !form.precio || !form.categoria_id)) {
    alert("Datos incompletos.");
    return;
  }
  currentStep.value++;
}

function submit() {
  form.post(route("repos.productos.create"), {
    onSuccess: () => {
      closeModal(true), form.reset();
    },
  });
}

function closeModal(force = false) {
  if (form.isDirty && !force && !confirm("¿Cerrar sin guardar?")) return;
  emit("close");
  setTimeout(() => {
    form.reset();
    currentStep.value = 1;
  }, 300);
}

function calculatePercentage(ing) {
  const master = getMasterData(ing.ingrediente_id);
  if (!master || !master.stock_actual || master.stock_actual <= 0) return 0;

  const unitBodega = props.unidadesMedidaDispobibles.find(
    (u) => Number(u.id) === Number(master.unidad_medida_id)
  );
  const unitReceta = props.unidadesMedidaDispobibles.find(
    (u) => Number(u.id) === Number(ing.unidad_medida_id)
  );

  if (!unitBodega || !unitReceta) return 0;

  // Convertimos la cantidad digitada a la unidad de bodega para comparar
  const cantEnUnidadBodega =
    (Number(ing.cantidad) * Number(unitReceta.factor_conversion)) /
    Number(unitBodega.factor_conversion);

  // Calculamos el porcentaje de consumo
  const porcentaje = (cantEnUnidadBodega / Number(master.stock_actual)) * 100;

  // Retornamos el valor limitado entre 0 y 100
  return Math.min(porcentaje, 100);
}
</script>

<template>
  <BaseModalSteps
    :isOpen="isOpen"
    :currentStep="currentStep"
    :totalSteps="totalSteps"
    :isSubmitting="form.processing"
    title="Estudio de Creación de Producto"
    @close="closeModal"
    @next="handleNext"
    @prev="currentStep--"
    @submit="submit"
  >
    <div class="flex flex-col lg:flex-row gap-8 py-4">
      <div class="flex-1 min-w-0">
        <Transition name="page-fade" mode="out-in">
          <div v-if="currentStep === 1" key="step1" class="space-y-8">
            <section
              class="bg-white dark:bg-slate-900/50 rounded-3xl p-1 border border-slate-200/60 dark:border-slate-800 shadow-sm overflow-hidden"
            >
              <div class="p-6 space-y-6">
                <header class="flex items-center gap-3">
                  <div
                    class="w-1.5 h-6 bg-primary rounded-full shadow-[0_0_12px_rgba(var(--color-primary),0.4)]"
                  ></div>
                  <div class="space-y-0.5">
                    <h4
                      class="text-xs font-bold uppercase tracking-widest text-slate-500"
                    >
                      Identidad del Producto
                    </h4>
                    <p class="text-[10px] text-slate-400 font-medium">
                      Información básica y presencia visual
                    </p>
                  </div>
                </header>

                <div class="flex flex-col md:flex-row gap-8">
                  <div class="relative group shrink-0 mx-auto md:mx-0">
                    <label
                      class="relative w-44 h-44 rounded-[2.5rem] bg-slate-50 dark:bg-slate-950 border-2 border-dashed border-slate-200 dark:border-slate-800 flex flex-col items-center justify-center cursor-pointer hover:border-primary/50 hover:bg-primary/[0.02] transition-all duration-300 overflow-hidden ring-offset-4 ring-offset-white dark:ring-offset-slate-900 focus-within:ring-2 ring-primary"
                    >
                      <Transition name="fade">
                        <img
                          v-if="imagePreviewUrl"
                          :src="imagePreviewUrl"
                          class="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
                        />
                      </Transition>

                      <div
                        v-if="!imagePreviewUrl"
                        class="text-center space-y-2 group-hover:scale-105 transition-transform"
                      >
                        <div
                          class="w-12 h-12 rounded-2xl bg-white dark:bg-slate-900 shadow-sm flex items-center justify-center mx-auto text-slate-400 group-hover:text-primary transition-colors"
                        >
                          <span class="material-symbols-rounded text-2xl"
                            >add_a_photo</span
                          >
                        </div>
                        <p
                          class="text-[10px] font-bold uppercase tracking-tight text-slate-400"
                        >
                          Imagen 1:1
                        </p>
                      </div>

                      <input
                        type="file"
                        class="hidden"
                        @change="onFileChange"
                        accept="image/*"
                      />
                    </label>

                    <button
                      v-if="imagePreviewUrl"
                      @click.prevent="$emit('remove:image')"
                      class="absolute -top-2 -right-2 bg-white dark:bg-slate-800 text-red-500 rounded-full p-2 shadow-xl hover:bg-red-500 hover:text-white transition-all duration-300 border border-slate-100 dark:border-slate-700 active:scale-90"
                    >
                      <span class="material-symbols-rounded text-base"
                        >delete_forever</span
                      >
                    </button>
                  </div>

                  <div class="flex-1 grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-5">
                    <div class="md:col-span-2">
                      <InputTexto
                        v-model="form.nombre"
                        label="Nombre Comercial"
                        placeholder="Ej: Pizza Napolitana Especial"
                        :error="form.errors.nombre"
                        icon="label"
                      />
                    </div>

                    <Selects
                      v-model="form.categoria_id"
                      :options="categoriasDisponibles"
                      label="Categoría"
                      icon="category"
                    />

                    <InputTexto
                      v-model="form.plu_item"
                      label="SKU / Código"
                      placeholder="SKU-000"
                      icon="barcode"
                    />

                    <div class="md:col-span-2">
                      <InputTexto
                        v-model="form.descripcion"
                        label="Descripción en Menú"
                        placeholder="Breve detalle para tus clientes..."
                        icon="description"
                      />
                    </div>
                  </div>
                </div>
              </div>
            </section>

            <section
              class="dark:bg-primary/5 rounded-[2.5rem] p-8 shadow-2xl relative overflow-hidden border border-white/5"
            >
              <div
                class="absolute -top-24 -right-24 w-64 h-64 bg-primary/20 blur-[100px] pointer-events-none"
              ></div>

              <div class="relative z-10 space-y-8">
                <header
                  class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4"
                >
                  <div class="space-y-1">
                    <h4 class="text-md font-bold uppercase text-primary-300">
                      Configuración Comercial
                    </h4>
                    <p class="text-slate-400 text-xs">
                      Define el valor y beneficios fiscales
                    </p>
                  </div>

                  <div
                    class="flex p-1.5 bg-white/5 backdrop-blur-md rounded-2xl border border-white/10 gap-1"
                  >
                    <button
                      type="button"
                      @click="hasTax = !hasTax"
                      :class="
                        hasTax
                          ? 'bg-primary text-white shadow-lg'
                          : 'text-slate-400 bg-gray-100 border hover:text-primary'
                      "
                      class="px-4 py-2 rounded-xl text-[10px] font-bold uppercase transition-all flex items-center gap-2"
                    >
                      <span class="material-symbols-rounded text-sm">percent</span> IVA
                    </button>
                    <button
                      type="button"
                      @click="hasDiscount = !hasDiscount"
                      :class="
                        hasDiscount
                          ? 'bg-emerald-500 text-white shadow-lg'
                          : 'text-slate-400 bg-gray-100 border hover:text-primary'
                      "
                      class="px-4 py-2 rounded-xl text-[10px] font-bold uppercase transition-all flex items-center gap-2"
                    >
                      <span class="material-symbols-rounded text-sm">sell</span> Descuento
                    </button>
                  </div>
                </header>

                <div class="grid grid-cols-1 md:grid-cols-6 gap-6">
                  <div class="md:col-span-6">
                    <NumberInput v-model="form.precio" label="Precio de Venta Sugerido" />
                  </div>
                  <div class="md:col-span-3">
                    <Transition name="expand-fade">
                      <NumberInput
                        v-if="hasTax"
                        v-model="form.porcentaje_impuesto"
                        label="% IVA"
                      />
                    </Transition>
                  </div>
                  <div class="md:col-span-3">
                    <Transition name="expand-fade">
                      <NumberInput
                        v-if="hasDiscount"
                        v-model="form.porcentaje_descuento"
                        label="% Descuento"
                      />
                    </Transition>
                  </div>
                </div>

                <div
                  class="pt-6 border-t border-white/10 flex items-center justify-between"
                >
                  <Checkbox
                    v-model="form.precio_incluye_impuesto"
                    label="Los precios ya incluyen impuestos"
                    class="dark-checkbox"
                  />
                  <div class="flex items-center gap-2 text-primary-300">
                    <span class="material-symbols-rounded text-base">info</span>
                    <span class="text-[10px] font-medium uppercase tracking-tight"
                      >Cálculo automático según región</span
                    >
                  </div>
                </div>
              </div>
            </section>
          </div>

          <div v-else key="step2" class="space-y-6">
            <div
              class="grid grid-cols-2 p-1.5 bg-slate-100 dark:bg-slate-950 rounded-[2rem] gap-1 border border-slate-200 dark:border-slate-800"
            >
              <button
                v-for="t in TipoProductoDisponibles"
                :key="t.id"
                type="button"
                @click="form.tipo = t.id"
                :class="
                  form.tipo === t.id
                    ? 'bg-white dark:bg-slate-800 shadow-md text-primary'
                    : 'text-slate-500 hover:text-slate-700'
                "
                class="py-4 rounded-[1.5rem] font-bold text-[11px] uppercase tracking-widest transition-all flex items-center justify-center gap-3"
              >
                <span class="material-symbols-rounded text-xl">{{
                  t.id === "COMPUESTO" ? "outdoor_grill" : "inventory_2"
                }}</span>
                {{ t.text }}
              </button>
            </div>

            <Transition name="fade-slide" mode="out-in">
              <div
                v-if="isRecipe"
                key="recipe"
                class="space-y-6 animate-in fade-in duration-500"
              >
                <header class="flex justify-between items-end px-1">
                  <div class="space-y-1">
                    <div class="flex items-center gap-2 text-primary">
                      <span class="material-symbols-rounded text-xl">account_tree</span>
                      <h4 class="text-[11px] font-black uppercase tracking-[0.15em]">
                        Estructura de Composición
                      </h4>
                    </div>
                    <p class="text-[10px] text-slate-400 font-medium">
                      Define los insumos y cantidades para este producto
                    </p>
                  </div>

                  <div class="flex items-center gap-4">
                    <div
                      class="hidden md:flex flex-col items-end border-r border-slate-200 dark:border-slate-800 pr-4"
                    >
                      <span class="text-[9px] font-bold text-slate-400 uppercase"
                        >Total Insumos</span
                      >
                      <span
                        class="text-sm font-black text-slate-700 dark:text-slate-200"
                        >{{ form.ingredientes.length }}</span
                      >
                    </div>
                    <button
                      @click="agregarIngrediente"
                      type="button"
                      class="group bg-slate-900 dark:bg-primary text-white hover:bg-primary dark:hover:bg-primary-600 px-5 py-3 rounded-2xl text-[10px] font-black uppercase transition-all flex items-center gap-3 shadow-lg shadow-slate-200 dark:shadow-primary/20 active:scale-95"
                    >
                      <span
                        class="material-symbols-rounded text-sm group-hover:rotate-90 transition-transform"
                        >add</span
                      >
                      Añadir Insumo
                    </button>
                  </div>
                </header>

                <div
                  class="space-y-3 max-h-[520px] overflow-y-auto pr-2 scrollbar-style pb-4"
                >
                  <div
                    v-if="form.ingredientes.length === 0"
                    class="border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-[2.5rem] p-12 text-center space-y-4"
                  >
                    <div
                      class="w-16 h-16 bg-slate-50 dark:bg-slate-800 rounded-full flex items-center justify-center mx-auto text-slate-300"
                    >
                      <span class="material-symbols-rounded text-3xl">Inbox</span>
                    </div>
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-widest">
                      No hay insumos agregados
                    </p>
                  </div>

                  <div
                    v-for="(ing, index) in form.ingredientes"
                    :key="index"
                    class="group relative rounded-[2rem] border transition-all duration-300 overflow-hidden"
                    :class="
                      activeIngredientIndex === index
                        ? 'bg-white dark:bg-slate-900 border-primary shadow-2xl shadow-primary/10 ring-4 ring-primary/5'
                        : 'bg-slate-50/50 dark:bg-slate-950/50 border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700'
                    "
                    @click="activeIngredientIndex = index"
                  >
                    <div
                      v-if="activeIngredientIndex === index"
                      class="p-6 space-y-6 animate-in zoom-in-95 duration-300"
                    >
                      <div class="flex justify-between items-start">
                        <span
                          class="bg-primary/10 text-primary text-[9px] font-black px-3 py-1 rounded-full uppercase"
                          >Editando Insumo #{{ index + 1 }}</span
                        >
                        <button
                          type="button"
                          @click.stop="form.ingredientes.splice(index, 1)"
                          class="text-slate-300 hover:text-red-500 transition-colors"
                        >
                          <span class="material-symbols-rounded text-xl">cancel</span>
                        </button>
                      </div>

                      <div class="grid grid-cols-12 gap-5 items-end">
                        <div class="col-span-12 md:col-span-7">
                          <Selects
                            v-model="ing.ingrediente_id"
                            :options="ingredientesDisponibles"
                            label="Buscar Insumo en Inventario"
                            @update:modelValue="handleIngredientChange(index)"
                          />
                        </div>
                        <div class="col-span-8 md:col-span-3">
                          <div class="relative group/input">
                            <InputTexto
                              type="number"
                              v-model="ing.cantidad"
                              label="Cantidad"
                              @input="handleIngredientChange(index)"
                            />
                            <div
                              class="absolute right-6 bottom-3 text-[10px] font-black text-slate-400 uppercase pointer-events-none"
                            >
                              {{ getUnitAbrev(ing.unidad_medida_id) || "u." }}
                            </div>
                          </div>
                        </div>
                        <div class="col-span-4 md:col-span-2">
                          <button
                            type="button"
                            @click.stop="activeIngredientIndex = null"
                            class="w-full h-12 flex items-center justify-center bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 rounded-2xl hover:bg-primary hover:text-white transition-all shadow-sm group"
                          >
                            <span
                              class="material-symbols-rounded group-hover:scale-110 transition-transform"
                              >check_circle</span
                            >
                          </button>
                        </div>
                      </div>

                      <div
                        v-if="getMasterData(ing.ingrediente_id)"
                        class="bg-slate-900 rounded-[1.5rem] p-5 relative overflow-hidden group/stock"
                      >
                        <div
                          class="absolute inset-0 bg-gradient-to-r from-primary/10 to-transparent pointer-events-none"
                        ></div>
                        <div class="flex justify-between items-end">
                          <div class="space-y-1">
                            <p
                              class="text-[8px] font-black text-slate-500 uppercase tracking-[0.2em]"
                            >
                              Disponibilidad en Bodega
                            </p>
                            <p class="text-xs font-bold text-white">
                              {{
                                formatNumber(
                                  getMasterData(ing.ingrediente_id).stock_actual
                                )
                              }}
                              <span class="text-slate-500 font-medium lowercase">
                                {{
                                  ing.unidad_bodega_abrev ||
                                  getUnitAbrev(
                                    getMasterData(ing.ingrediente_id).unidad_medida_id
                                  )
                                }}
                              </span>
                            </p>
                          </div>

                          <div class="text-right">
                            <p
                              class="text-[8px] font-black text-slate-500 uppercase tracking-[0.2em]"
                            >
                              Tras Producir (Proyección)
                            </p>
                            <p
                              class="text-xs font-bold transition-colors duration-500"
                              :class="
                                parseFloat(ing.stock_restante_texto) < 0
                                  ? 'text-red-400'
                                  : 'text-emerald-400'
                              "
                            >
                              {{ ing.stock_restante_texto || "0.000" }}
                              <span class="text-slate-500 font-medium lowercase">
                                {{ ing.unidad_bodega_abrev }}
                              </span>
                            </p>
                          </div>
                        </div>
                      </div>
                    </div>

                    <div
                      v-else
                      class="flex items-center justify-between p-5 cursor-pointer group/item"
                    >
                      <div class="flex items-center gap-5">
                        <span
                          class="text-[10px] font-black text-slate-300 dark:text-slate-700 group-hover/item:text-primary transition-colors"
                        >
                          {{ (index + 1).toString().padStart(2, "0") }}
                        </span>

                        <div
                          class="w-12 h-12 rounded-2xl bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 flex items-center justify-center text-slate-400 group-hover/item:text-primary group-hover/item:border-primary/30 group-hover/item:scale-110 transition-all duration-300"
                        >
                          <span class="material-symbols-rounded text-2xl">
                            {{
                              getMasterData(ing.ingrediente_id)
                                ? "restaurant"
                                : "ads_click"
                            }}
                          </span>
                        </div>

                        <div class="space-y-0.5">
                          <h5
                            class="text-sm font-bold text-slate-700 dark:text-slate-200 group-hover/item:text-slate-900 dark:group-hover/item:text-white transition-colors"
                          >
                            {{
                              getMasterData(ing.ingrediente_id)?.text ||
                              "Configurar este insumo..."
                            }}
                          </h5>
                          <div
                            v-if="getMasterData(ing.ingrediente_id)"
                            class="flex items-center gap-2"
                          >
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            <p
                              class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter"
                            >
                              Insumo Vinculado Correctamente
                            </p>
                          </div>
                        </div>
                      </div>

                      <div class="flex items-center gap-6">
                        <div class="text-right">
                          <p
                            class="text-[10px] font-black text-slate-800 dark:text-slate-200"
                          >
                            {{ ing.cantidad || 0 }}
                            <span class="text-slate-400 font-bold ml-1">{{
                              getUnitAbrev(ing.unidad_medida_id)
                            }}</span>
                          </p>
                          <p
                            class="text-[8px] font-black text-slate-400 uppercase tracking-widest mt-0.5"
                          >
                            Cantidad
                          </p>
                        </div>
                        <div
                          class="w-8 h-8 rounded-full flex items-center justify-center bg-slate-100 dark:bg-slate-800 text-slate-400 group-hover/item:bg-primary group-hover/item:text-white transition-all"
                        >
                          <span class="material-symbols-rounded text-lg">edit</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div
                v-else
                key="stock"
                class="bg-blue-50/30 dark:bg-blue-900/10 p-8 rounded-[3rem] border border-blue-100/50 dark:border-blue-900/30 space-y-8"
              >
                <header
                  class="flex justify-between items-center border-b border-blue-100 dark:border-blue-900/30 pb-6"
                >
                  <div class="flex items-center gap-4">
                    <div
                      class="w-12 h-12 bg-blue-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-blue-600/20"
                    >
                      <span class="material-symbols-rounded text-2xl">inventory</span>
                    </div>
                    <div>
                      <h4 class="text-sm font-bold text-slate-800 dark:text-blue-100">
                        Control de Almacén
                      </h4>
                      <p class="text-xs text-slate-500">
                        Gestione existencias y alertas de reposición
                      </p>
                    </div>
                  </div>
                  <Checkbox v-model="form.maneja_inventario" label="Activar" />
                </header>

                <div
                  v-if="form.maneja_inventario"
                  class="grid grid-cols-1 gap-8 animate-in fade-in zoom-in-95"
                >
                  <InputTexto
                    type="number"
                    v-model="form.stock_actual"
                    label="Existencia Inicial"
                    placeholder="0.00"
                  />
                </div>
              </div>
            </Transition>

            <div class="pt-6 border-t border-slate-100 dark:border-slate-800">
              <div
                class="bg-blue-50/50 dark:bg-blue-900/10 rounded-[2rem] p-6 border flex items-center gap-6 border-blue-100/50 dark:border-blue-900/20"
              >
                <Selects
                  v-model="form.area_produccion_id"
                  :options="areasProduccionDisponibles"
                  label="Destino de Producción"
                  icon="print"
                  description="Define a qué impresora o pantalla llegará este pedido"
                />
                <InputTexto
                  type="number"
                  v-model="form.stock_minimo"
                  label="Alerta Stock Mínimo"
                  placeholder="0.00"
                />
              </div>
            </div>
          </div>
        </Transition>
      </div>

      <aside class="w-full lg:w-[380px] shrink-0">
        <div class="sticky top-6">
          <div
            class="bg-white dark:bg-slate-900 rounded-[2.5rem] shadow-2xl border border-slate-200/60 dark:border-slate-800 overflow-hidden relative transition-all duration-500"
          >
            <div
              class="h-52 bg-slate-100 dark:bg-slate-950 relative overflow-hidden group"
            >
              <div
                class="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/40 to-transparent z-10"
              ></div>

              <div class="absolute top-4 left-4 z-20">
                <span
                  class="px-3 py-1 rounded-lg bg-black/20 backdrop-blur-md text-[9px] font-black text-white/90 border border-white/10 uppercase tracking-widest"
                >
                  {{
                    props.categoriasDisponibles.find((c) => c.id === form.categoria_id)
                      ?.text || "General"
                  }}
                </span>
              </div>

              <img
                v-if="imagePreviewUrl"
                :src="imagePreviewUrl"
                class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-[1.5s]"
              />
              <div
                v-else
                class="w-full h-full flex items-center justify-center text-6xl font-black text-slate-200 dark:text-slate-800 select-none uppercase"
              >
                {{ initialName }}
              </div>

              <div
                class="absolute bottom-4 left-6 right-6 z-20 flex justify-between items-end"
              >
                <div class="space-y-0">
                  <p
                    class="text-[9px] font-black uppercase text-white/60 tracking-widest"
                  >
                    Precio Final
                  </p>
                  <div class="flex items-center gap-2">
                    <span class="text-3xl font-black text-white tracking-tighter">{{
                      formatCOP(precioFinal)
                    }}</span>
                    <span
                      v-if="form.porcentaje_descuento > 0"
                      class="bg-emerald-500 text-white px-1.5 py-0.5 rounded-md text-[9px] font-black"
                      >-{{ form.porcentaje_descuento }}%</span
                    >
                  </div>
                </div>
              </div>
            </div>

            <div class="p-6 space-y-5">
              <div>
                <h3
                  class="text-xl font-black text-slate-800 dark:text-white leading-tight line-clamp-1"
                >
                  {{ form.nombre || "Nombre del Plato" }}
                </h3>
                <p class="text-xs text-slate-400 mt-1 line-clamp-1 italic">
                  {{ form.descripcion || "Sin descripción..." }}
                </p>
              </div>

              <div class="grid grid-cols-2 gap-3">
                <div
                  class="col-span-2 p-3 bg-emerald-50 dark:bg-emerald-500/5 rounded-2xl border border-emerald-100 dark:border-emerald-500/10 flex justify-between items-center"
                >
                  <span
                    class="text-[10px] font-bold text-emerald-600 uppercase tracking-wider"
                    >Utilidad</span
                  >
                  <span
                    class="text-lg font-black text-emerald-700 dark:text-emerald-400"
                    >{{ formatCOP(utilidad) }}</span
                  >
                </div>
                <div
                  class="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-2xl border border-slate-100 dark:border-slate-800"
                >
                  <p class="text-[8px] font-bold text-slate-400 uppercase mb-0.5">
                    Costo
                  </p>
                  <p class="text-xs font-black text-slate-700 dark:text-slate-200">
                    {{ formatCOP(form.costo_base) }}
                  </p>
                </div>
                <div
                  class="p-3 bg-primary/5 dark:bg-primary/10 rounded-2xl border border-primary/10 flex flex-col items-center"
                >
                  <p class="text-[8px] font-bold text-primary uppercase mb-0.5">Margen</p>
                  <p class="text-xs font-black text-primary">{{ margen }}%</p>
                </div>
              </div>

              <div v-if="isRecipe" class="space-y-3">
                <div
                  class="flex justify-between items-center border-b border-slate-100 dark:border-slate-800 pb-2"
                >
                  <h4
                    class="text-[10px] font-black text-slate-400 uppercase tracking-widest"
                  >
                    Composición ({{ form.ingredientes.length }})
                  </h4>
                  <span
                    class="text-[9px] font-bold text-indigo-500 bg-indigo-50 dark:bg-indigo-500/10 px-2 py-0.5 rounded-md"
                    >Máx: {{ maxProduction }} uds</span
                  >
                </div>

                <div class="max-h-40 overflow-y-auto pr-1 scrollbar-style space-y-1.5">
                  <div
                    v-for="(ing, idx) in form.ingredientes"
                    :key="idx"
                    class="flex justify-between items-center p-2 rounded-xl bg-slate-50/50 dark:bg-slate-800/30 border border-slate-100 dark:border-slate-800/50"
                  >
                    <div class="flex items-center gap-2 min-w-0">
                      <span
                        class="w-1.5 h-1.5 rounded-full bg-slate-300 dark:bg-slate-600"
                      ></span>
                      <p
                        class="text-[11px] font-medium text-slate-600 dark:text-slate-300 truncate"
                      >
                        {{
                          getMasterData(ing.ingrediente_id)?.text ||
                          "Insumo no seleccionado"
                        }}
                      </p>
                    </div>
                    <span class="text-[10px] font-black text-slate-400 shrink-0 ml-2">
                      {{ ing.cantidad || 0 }} {{ getUnitAbrev(ing.unidad_medida_id) }}
                    </span>
                  </div>
                  <div v-if="form.ingredientes.length === 0" class="py-4 text-center">
                    <p class="text-[10px] text-slate-400 italic">
                      No has añadido ingredientes aún
                    </p>
                  </div>
                </div>
              </div>

              <div
                v-else-if="form.maneja_inventario"
                class="p-4 bg-blue-50/50 dark:bg-blue-900/10 rounded-2xl border border-blue-100 dark:border-blue-900/20 flex justify-between items-center"
              >
                <div>
                  <p class="text-[9px] font-bold text-blue-500 uppercase">
                    Stock Inicial
                  </p>
                  <p class="text-sm font-black text-slate-700 dark:text-slate-200">
                    {{ form.stock_actual || 0 }}
                    <span class="text-[10px] text-slate-400">Uds</span>
                  </p>
                </div>
                <div class="text-right">
                  <p class="text-[9px] font-bold text-slate-400 uppercase">
                    Punto Crítico
                  </p>
                  <p class="text-sm font-black text-red-400">
                    {{ form.stock_minimo || 0 }}
                  </p>
                </div>
              </div>
            </div>

            <div
              class="px-6 py-4 bg-slate-50 dark:bg-slate-950/50 border-t border-slate-100 dark:border-slate-800 flex justify-between items-center"
            >
              <div
                class="flex items-center gap-2 text-[9px] font-bold text-slate-400 uppercase tracking-tighter"
              >
                <span class="material-symbols-rounded text-sm">print</span>
                {{
                  areasProduccionDisponibles.find((a) => a.id === form.area_produccion_id)
                    ?.text || "Sin Destino"
                }}
              </div>
              <div class="flex items-center gap-1.5">
                <div
                  class="w-1.5 h-1.5 rounded-full"
                  :class="form.nombre ? 'bg-emerald-500' : 'bg-slate-300'"
                ></div>
                <span class="text-[9px] font-black text-slate-400 uppercase">{{
                  form.nombre ? "Listo" : "Incompleto"
                }}</span>
              </div>
            </div>
          </div>
        </div>
      </aside>
    </div>
  </BaseModalSteps>
</template>

<style scoped>
/* Transición de páginas fluida */
.page-fade-enter-active,
.page-fade-leave-active {
  transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
}
.page-fade-enter-from {
  opacity: 0;
  transform: scale(0.98) translateY(10px);
}
.page-fade-leave-to {
  opacity: 0;
  transform: scale(1.02) translateY(-10px);
}

/* Transición para expansiones (IVA/Descuento) */
.expand-fade-enter-active {
  transition: all 0.3s ease-out;
}
.expand-fade-leave-active {
  transition: all 0.2s ease-in;
}
.expand-fade-enter-from,
.expand-fade-leave-to {
  opacity: 0;
  transform: translateY(-5px);
  max-height: 0;
}

.scrollbar-style::-webkit-scrollbar {
  width: 5px;
}
.scrollbar-style::-webkit-scrollbar-track {
  background: transparent;
}
.scrollbar-style::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 20px;
}
.dark .scrollbar-style::-webkit-scrollbar-thumb {
  background: #334155;
}

/* Estilo para Inputs en fondos oscuros */
.dark-input :deep(input) {
  background-color: rgba(255, 255, 255, 0.05) !important;
  border-color: rgba(255, 255, 255, 0.1) !important;
  color: white !important;
}

.dark-checkbox :deep(label) {
  color: #94a3b8 !important;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.025em;
}
</style>
