<script setup>
import BaseModalSteps from "@/Components/Shared/Modales/BaseModalSteps.vue";
import { ref, computed, watch } from "vue";

const props = defineProps({
  isOpen: { type: Boolean, required: true },
  plan: { type: Object, required: true },
  isEstelar: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "confirm", "back"]);

const selectedDuration = ref("mensual");
const isSubmitting = ref(false);

const formatCurrency = (value) => {
  if (value === undefined || value === null) return "$ 0";
  return new Intl.NumberFormat("es-CO", {
    style: "currency",
    currency: "COP",
    maximumFractionDigits: 0,
  }).format(value);
};

// Generar opciones de duración dinámicamente
const durationOptions = computed(() => {
  if (!props.plan) return [];

  // Caso 1: Plan Gratis (ID 1) -> Solo Semanal
  if (props.plan.id === 1) {
    return [
      {
        id: "semanal",
        label: "Semanal",
        subtitle: "Facturación cada 7 días",
        price: 0,
        totalPrice: 0,
        discount: null,
      },
    ];
  }

  // Precios base del plan
  const prices = props.plan.prices || {};
  const baseMonthlyPrice = Number(prices.mensual) || 0;

  const calculateDiscount = (periodPrice, months) => {
    const totalPeriodPrice = Number(periodPrice);
    if (!baseMonthlyPrice || !totalPeriodPrice) return null;

    // Precio si pagara mes a mes durante ese periodo
    const standardCost = baseMonthlyPrice * months;

    // Si el precio del periodo es menor, hay descuento
    if (totalPeriodPrice < standardCost) {
      const saving = standardCost - totalPeriodPrice;
      const percent = Math.round((saving / standardCost) * 100);
      return { percent, amount: saving };
    }
    return null;
  };

  return [
    {
      id: "mensual",
      label: "Mensual",
      subtitle: "Facturación cada mes",
      price: baseMonthlyPrice,
      totalPrice: baseMonthlyPrice, // Precio a pagar ahora
      discount: null,
    },
    {
      id: "trimestral",
      label: "Trimestral",
      subtitle: "Facturación cada 3 meses",
      price: prices.trimestral,
      totalPrice: prices.trimestral,
      discount: calculateDiscount(prices.trimestral, 3),
    },
    {
      id: "anual",
      label: "Anual",
      subtitle: "Facturación cada año",
      price: prices.anual,
      totalPrice: prices.anual,
      discount: calculateDiscount(prices.anual, 12),
      bestValue: true,
    },
    {
      id: "quinquenio",
      label: "Quinquenal",
      subtitle: "Un solo pago por 5 años",
      price: prices.quinquenio,
      totalPrice: prices.quinquenio,
      discount: calculateDiscount(prices.quinquenio, 60),
    },
  ].filter((opt) => opt.price !== undefined);
});

// Resetear selección al abrir
watch(
  () => props.isOpen,
  (newVal) => {
    if (newVal) {
      selectedDuration.value = props.plan.id === 1 ? "semanal" : "mensual";
    }
  }
);

const handleConfirm = () => {
  isSubmitting.value = true;
  setTimeout(() => {
    isSubmitting.value = false;
    emit("confirm", { planId: props.plan.id, duration: selectedDuration.value });
  }, 800);
};
</script>

<template>
  <BaseModalSteps
    :is-open="isOpen"
    :current-step="2"
    :total-steps="2"
    title="Configura tu Plan"
    description="Elige el ciclo de facturación que mejor se adapte a ti."
    final-button-text="Ir al Resumen de Pago"
    @close="$emit('close')"
    @submit="handleConfirm"
    @prev="$emit('back')"
  >
    <div
      class="space-y-5"
      :class="isEstelar ? 'bg-[#020617] text-white' : 'bg-white text-gray-900'"
    >
      <div
        class="relative overflow-hidden rounded-2xl transition-all duration-300 group"
        :class="
          isEstelar
            ? 'bg-[#0B1120] border border-white/10 shadow-[0_0_30px_-10px_rgba(244,63,94,0.15)]'
            : 'bg-white border border-gray-100 shadow-xl shadow-gray-200/40'
        "
      >
        <div
          v-if="isEstelar"
          class="absolute -top-10 -right-10 w-40 h-40 bg-rose-500/10 blur-[50px] rounded-full pointer-events-none"
        ></div>

        <div class="relative z-10 flex items-center justify-between p-5">
          <div class="flex items-center gap-5">
            <div
              class="w-14 h-14 rounded-2xl flex items-center justify-center text-2xl font-black shadow-inner transition-transform duration-300 group-hover:scale-105"
              :class="
                isEstelar
                  ? 'bg-gradient-to-br from-gray-800 to-gray-900 border border-white/10 text-rose-400'
                  : 'bg-gradient-to-br from-blue-50 to-white border border-blue-100 text-blue-600'
              "
            >
              {{ plan.name ? plan.name.charAt(0) : "P" }}
            </div>

            <div class="flex flex-col">
              <span
                class="text-[10px] font-bold uppercase tracking-widest mb-0.5 opacity-60"
                :class="isEstelar ? 'text-gray-300' : 'text-gray-500'"
              >
                Plan Seleccionado
              </span>
              <h3
                class="text-2xl font-black tracking-tight leading-none"
                :class="isEstelar ? 'text-white' : 'text-gray-900'"
              >
                {{ plan.name }}
              </h3>
            </div>
          </div>

          <button
            @click="$emit('back')"
            class="px-4 py-2 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 group/btn border"
            :class="
              isEstelar
                ? 'bg-white/5 hover:bg-white/10 text-white border-white/10'
                : 'bg-gray-50 hover:bg-gray-100 text-gray-600 border-gray-200'
            "
          >
            Cambiar
            <span
              class="material-symbols-rounded text-[14px] group-hover/btn:rotate-180 transition-transform duration-500"
              >sync</span
            >
          </button>
        </div>
      </div>

      <div class="space-y-4">
        <label class="text-sm font-bold block mb-2 opacity-80">
          Ciclo de Facturación
        </label>

        <div class="grid grid-cols-2 gap-3">
          <div
            v-for="option in durationOptions"
            :key="option.id"
            @click="selectedDuration = option.id"
            class="relative group cursor-pointer p-4 rounded-xl border-2 transition-all duration-200 flex items-center justify-between"
            :class="[
              selectedDuration === option.id
                ? isEstelar
                  ? 'border-rose-500 bg-rose-900/10 shadow-[0_0_15px_-3px_rgba(244,63,94,0.2)]'
                  : 'border-blue-600 bg-blue-50 shadow-sm'
                : isEstelar
                ? 'border-gray-800 hover:border-gray-600 bg-gray-900/30'
                : 'border-gray-200 hover:border-gray-300 bg-white hover:bg-gray-50',
            ]"
          >
            <div class="flex items-center gap-4">
              <div
                class="w-5 h-5 rounded-full border-[1.5px] flex items-center justify-center transition-colors shrink-0"
                :class="
                  selectedDuration === option.id
                    ? isEstelar
                      ? 'border-rose-500'
                      : 'border-blue-600'
                    : isEstelar
                    ? 'border-gray-600'
                    : 'border-gray-300'
                "
              >
                <div
                  class="w-2.5 h-2.5 rounded-full transition-transform duration-200"
                  :class="[
                    selectedDuration === option.id
                      ? isEstelar
                        ? 'bg-rose-500 scale-100'
                        : 'bg-blue-600 scale-100'
                      : 'scale-0',
                  ]"
                ></div>
              </div>

              <div class="flex flex-col">
                <div class="flex items-center gap-2">
                  <span class="font-bold text-sm">{{ option.label }}</span>
                  <span
                    v-if="option.bestValue"
                    class="text-[9px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full bg-gradient-to-r from-amber-400 to-orange-500 text-white shadow-sm"
                  >
                    Recomendado
                  </span>
                </div>
                <span class="text-xs opacity-60">{{ option.subtitle }}</span>
              </div>
            </div>

            <div class="text-right">
              <span class="block font-black text-lg tracking-tight">
                {{ formatCurrency(option.totalPrice) }}
              </span>

              <div
                v-if="option.discount"
                class="text-[10px] font-bold inline-flex items-center gap-1 mt-0.5"
                :class="isEstelar ? 'text-emerald-400' : 'text-emerald-600'"
              >
                <span>Ahorras {{ option.discount.percent }}%</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="isEstelar" class="text-center">
        <p class="text-[10px] text-gray-500 uppercase tracking-widest">
          Experiencia Premium Activa
        </p>
      </div>
    </div>
  </BaseModalSteps>
</template>
