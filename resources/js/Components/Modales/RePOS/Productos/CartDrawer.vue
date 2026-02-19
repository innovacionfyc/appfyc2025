<script setup>
import { ref } from "vue";
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";
import Selects from "@/Components/Shared/inputs/Selects.vue";
import BtnPrimario from "@/Components/Shared/buttons/btnPrimario.vue";
import CartItem from "@/Components/RePOS/Menú/CartItem.vue";
import OrderSummary from "@/Components/RePOS/Menú/OrderSummary.vue";

const props = defineProps({
  isOpen: { type: Boolean, required: true },
  cart: { type: Array, default: () => [] },
  subtotal: { type: Number, default: 0 },
  total: { type: Number, default: 0 },

  tipoOrdenesDisponibles: { type: Array, default: () => [] },
  mesasDisponibles: { type: Array, default: () => [] },
  clientesDisponibles: { type: Array, default: () => [] },

  // Formulario reactivo (v-model)
  form: { type: Object, required: true },

  // Helpers
  getPhotoProductUrlFull: { type: Function, default: () => "" },
  isProcessing: { type: Boolean, default: false },
});

const emit = defineEmits([
  "close",
  "update-quantity",
  "remove-item",
  "clear-cart",
  "create-order",
  "update-tip",
]);

// Estado interno para colapsar config
const isOrderConfigCollapsed = ref(false);
const tipPercentage = ref(0); // Estado local para propina, se emite al padre

// Helper local
const formatCurrency = (value) => {
  return new Intl.NumberFormat("es-CO", {
    style: "currency",
    currency: "COP",
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(value);
};

const getOptionLabel = (options, value) => {
  if (!value || !options) return "";
  const option = options.find((o) => o.id == value || o.value == value);
  return option ? option.nombre || option.label || option.numero || value : "";
};

// Emitir cambios de propina
const handleTipUpdate = (val) => {
  tipPercentage.value = val;
  const tipAmount = props.subtotal * (val / 100);
  emit("update-tip", { percentage: val, amount: tipAmount });
};
</script>

<template>
  <div>
    <Transition name="fade">
      <div
        v-if="isOpen"
        @click="$emit('close')"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[998] transition-opacity"
      ></div>
    </Transition>

    <Transition name="slide-right">
      <div
        v-if="isOpen"
        class="fixed inset-y-0 right-0 z-[999] w-full sm:w-[500px] md:w-[700px] bg-white dark:bg-[#0f172a] shadow-2xl flex flex-col h-full border-l border-gray-200/50 dark:border-white/10"
      >
        <div
          class="px-4 sm:px-6 py-4 border-b border-gray-100 dark:border-white/5 flex justify-between items-center dark:bg-[#0f172a]/80 backdrop-blur-xl z-20 shrink-0"
        >
          <div class="flex items-center gap-3">
            <div
              class="relative flex items-center justify-center w-10 h-10 rounded-xl bg-primary/10 text-primary"
            >
              <span class="material-symbols-rounded text-xl">receipt_long</span>
            </div>
            <div class="flex flex-col">
              <h2 class="text-base font-black text-gray-900 dark:text-white leading-none">
                Orden Actual
              </h2>
              <p class="text-[11px] text-gray-400 mt-1">Detalle de consumo</p>
            </div>
          </div>
          <button
            @click="$emit('close')"
            class="p-2 rounded-full hover:bg-gray-100 dark:hover:bg-white/10 transition-colors"
          >
            <span class="material-symbols-rounded text-gray-500">close</span>
          </button>
        </div>

        <div
          class="flex-1 overflow-y-auto scrollbar-custom p-4 sm:p-5 space-y-4 sm:space-y-6 bg-gray-50/50 dark:bg-transparent"
        >
          <div class="transition-all duration-500 ease-in-out relative z-30">
            <Transition name="switch-view" mode="out-in">
              <div
                v-if="!isOrderConfigCollapsed"
                key="form"
                class="bg-white/50 dark:bg-white/[0.03] backdrop-blur-xl rounded-[28px] p-6 border border-gray-200/50 dark:border-white/10 space-y-5 shadow-2xl shadow-gray-200/50 dark:shadow-black/20"
              >
                <div class="flex items-center justify-between">
                  <div class="flex items-center gap-3">
                    <div
                      class="w-10 h-10 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center shadow-lg shadow-indigo-500/20"
                    >
                      <span class="material-symbols-rounded text-xl"
                        >settings_accessibility</span
                      >
                    </div>
                    <div class="flex flex-col">
                      <span
                        class="text-[10px] font-black uppercase tracking-[0.2em] text-indigo-500 dark:text-indigo-400"
                        >Paso 1</span
                      >
                      <h2
                        class="text-sm font-black text-gray-800 dark:text-white leading-none"
                      >
                        Detalles de la Orden
                      </h2>
                    </div>
                  </div>
                  <button
                    @click="isOrderConfigCollapsed = true"
                    class="w-8 h-8 flex items-center justify-center rounded-full bg-gray-100 dark:bg-white/5 text-gray-400 hover:text-indigo-500 hover:bg-white transition-all shadow-sm"
                  >
                    <span class="material-symbols-rounded text-lg">expand_less</span>
                  </button>
                </div>

                <div class="grid grid-cols-1 gap-4">
                  <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                      <Selects
                        v-model="form.tipo_orden"
                        :options="tipoOrdenesDisponibles"
                        label="Modalidad"
                        icon="restaurant"
                      />
                    </div>

                    <div class="space-y-1.5">
                      <Transition name="pop">
                        <Selects
                          v-if="form.tipo_orden === 'Comer aquí'"
                          v-model="form.mesa_id"
                          :options="mesasDisponibles"
                          label="Mesa / Ubicación"
                          icon="table_restaurant"
                        />
                        <InputTexto
                          v-else-if="form.tipo_orden === 'Domicilio'"
                          v-model="form.plataforma_nombre"
                          label="Plataforma"
                          icon="delivery_dining"
                          placeholder="Ej: Rappi"
                        />
                        <div
                          v-else
                          class="h-[58px] bg-gray-50/50 dark:bg-white/[0.02] rounded-2xl border border-dashed border-gray-200 dark:border-white/10 flex items-center justify-center"
                        >
                          <span
                            class="text-[10px] font-bold text-gray-400 uppercase tracking-widest"
                            >Para llevar</span
                          >
                        </div>
                      </Transition>
                    </div>
                  </div>

                  <div class="space-y-1.5">
                    <Selects
                      v-model="form.sub_usuario_id"
                      :options="clientesDisponibles"
                      label="Asignar Cliente"
                      icon="person_search"
                    />
                  </div>
                </div>
              </div>

              <div
                v-else
                key="summary"
                @click="isOrderConfigCollapsed = false"
                class="group relative bg-white dark:bg-[#1e293b] border border-gray-200 dark:border-white/10 rounded-[22px] p-3 shadow-sm hover:shadow-xl hover:border-indigo-500/50 cursor-pointer transition-all duration-500 overflow-hidden select-none"
              >
                <div
                  class="absolute left-0 top-0 bottom-0 w-1.5 bg-gradient-to-b from-indigo-500 to-purple-600 rounded-l-full opacity-80 group-hover:w-2 transition-all"
                ></div>

                <div class="flex items-center justify-between pl-4 pr-2">
                  <div class="flex flex-wrap items-center gap-3">
                    <div
                      class="flex items-center gap-2 py-1.5 px-3 rounded-xl bg-gray-50 dark:bg-white/[0.03] border border-gray-100 dark:border-white/5 transition-colors group-hover:bg-indigo-50 dark:group-hover:bg-indigo-500/10"
                    >
                      <span class="material-symbols-rounded text-base text-indigo-500">
                        {{
                          form.tipo_orden === "Comer aquí"
                            ? "restaurant"
                            : form.tipo_orden === "Domicilio"
                            ? "delivery_dining"
                            : "takeout_dining"
                        }}
                      </span>
                      <div class="flex flex-col">
                        <span
                          class="text-[11px] font-black text-gray-800 dark:text-gray-100"
                        >
                          {{
                            getOptionLabel(tipoOrdenesDisponibles, form.tipo_orden) ||
                            "Sin tipo"
                          }}
                        </span>
                      </div>
                    </div>

                    <div
                      v-if="form.mesa_id || form.plataforma_nombre"
                      class="flex items-center gap-2 py-1.5 px-3 rounded-xl bg-orange-50 dark:bg-orange-500/10 border border-orange-100 dark:border-orange-500/20"
                    >
                      <span class="material-symbols-rounded text-base text-orange-500">
                        {{ form.tipo_orden === "Comer aquí" ? "table_bar" : "layers" }}
                      </span>
                      <span
                        class="text-[11px] font-black text-orange-700 dark:text-orange-400"
                      >
                        {{
                          form.mesa_id
                            ? getOptionLabel(mesasDisponibles, form.mesa_id)
                            : form.plataforma_nombre
                        }}
                      </span>
                    </div>

                    <div
                      class="flex items-center gap-2 py-1.5 px-3 rounded-xl bg-blue-50 dark:bg-blue-500/10 border border-blue-100 dark:border-blue-500/20"
                    >
                      <span class="material-symbols-rounded text-base text-blue-500"
                        >person</span
                      >
                      <span
                        class="text-[11px] font-black text-blue-700 dark:text-blue-400 max-w-[120px] truncate"
                      >
                        {{
                          getOptionLabel(clientesDisponibles, form.sub_usuario_id) ||
                          "Consumidor Final"
                        }}
                      </span>
                    </div>
                  </div>

                  <div
                    class="flex items-center gap-2 text-gray-300 dark:text-gray-600 group-hover:text-indigo-500 transition-all transform group-hover:translate-x-1"
                  >
                    <span
                      class="text-[10px] font-black uppercase tracking-widest hidden sm:block"
                      >Editar</span
                    >
                    <span class="material-symbols-rounded text-xl"
                      >arrow_forward_ios</span
                    >
                  </div>
                </div>
              </div>
            </Transition>
          </div>

          <div class="space-y-4">
            <div
              class="flex justify-between items-end border-b border-gray-200 dark:border-gray-700/50 pb-2"
            >
              <h3
                class="text-xs font-bold text-gray-800 dark:text-gray-200 uppercase tracking-widest"
              >
                Items ({{ cart.length }})
              </h3>
              <button
                v-if="cart.length > 0"
                @click="$emit('clear-cart')"
                class="text-[10px] font-bold text-red-500 hover:bg-red-50 px-2 py-1 rounded transition-colors flex items-center gap-1"
              >
                <span class="material-symbols-rounded text-sm">delete</span> Limpiar
              </button>
            </div>

            <div v-if="cart.length > 0" class="space-y-3">
              <CartItem
                v-for="item in cart"
                :key="item.cartItemId"
                :item="item"
                @update-quantity="
                  (amount) => $emit('update-quantity', item.cartItemId, amount)
                "
                @remove-from-cart="$emit('remove-item', item.cartItemId)"
                :get-photo-product-url-full="getPhotoProductUrlFull"
              />
            </div>

            <div
              v-else
              class="py-10 text-center flex flex-col items-center justify-center text-gray-400 border-2 border-dashed border-gray-200 dark:border-gray-700 rounded-xl"
            >
              <span class="material-symbols-rounded text-4xl mb-2 opacity-50"
                >remove_shopping_cart</span
              >
              <p class="text-sm">Agrega productos del menú</p>
            </div>
          </div>
        </div>

        <div
          class="p-4 sm:p-5 bg-white dark:bg-[#0f172a] border-t border-gray-100 dark:border-white/5 shadow-[0_-4px_20px_rgba(0,0,0,0.02)] z-20 space-y-3"
        >
          <InputTexto
            v-model="form.notas_generales"
            label="Notas de Cocina"
            icon="edit_note"
            placeholder="Ej: Sin cebolla..."
          />

          <OrderSummary
            :subtotal="subtotal"
            :total-savings="totalSavings"
            :tip-amount="subtotal * (tipPercentage / 100)"
            :total="total"
            :tip-percentage="tipPercentage"
            @update-tip-percentage="handleTipUpdate"
            :format-currency="formatCurrency"
          />

          <BtnPrimario
            @click="$emit('create-order')"
            :disabled="isProcessing || cart.length === 0"
            :label="isProcessing ? 'Enviando...' : `Confirmar Orden`"
            :icon="isProcessing ? 'progress_activity' : 'check_circle'"
            class="w-full py-3 sm:py-4 text-sm sm:text-base shadow-xl shadow-primary/20 hover:shadow-primary/30 transition-all hover:-translate-y-0.5"
            :class="{ 'animate-pulse': isProcessing }"
          />
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.slide-right-enter-active,
.slide-right-leave-active {
  transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.slide-right-enter-from,
.slide-right-leave-to {
  transform: translateX(100%);
}

.switch-view-enter-active,
.switch-view-leave-active {
  transition: all 0.25s ease;
}
.switch-view-enter-from,
.switch-view-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}

.scrollbar-custom::-webkit-scrollbar {
  width: 4px;
}
.scrollbar-custom::-webkit-scrollbar-track {
  background: transparent;
}
.scrollbar-custom::-webkit-scrollbar-thumb {
  background-color: #cbd5e1;
  border-radius: 20px;
}
.dark .scrollbar-custom::-webkit-scrollbar-thumb {
  background-color: #4b5563;
}
</style>
