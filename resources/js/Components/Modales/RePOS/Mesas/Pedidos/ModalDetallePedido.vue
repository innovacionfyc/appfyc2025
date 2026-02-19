<script setup>
import { computed } from "vue";
import BaseModalSteps from "@/Components/Shared/Modales/BaseModalSteps.vue";

const props = defineProps({
  isOpen: Boolean,
  pedido: Object, // Este es el array que retorna transformarOrdenParaVista
  mesa: Object,
});

const emit = defineEmits(["close", "cobrar"]);

// Formateo de dinero para pesos colombianos
const money = (val) =>
  new Intl.NumberFormat("es-CO", {
    style: "currency",
    currency: "COP",
    maximumFractionDigits: 0,
  }).format(val || 0);
</script>

<template>
  <BaseModalSteps
    :isOpen="isOpen"
    title="Detalle de la Cuenta"
    @close="emit('close')"
finalButtonText="Cerrar"
    :currentStep="1"
    :totalSteps="1"
>
<div class="py-2 space-y-5">
      <div
        class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-800/50 rounded-3xl border border-gray-100 dark:border-gray-700"
      >
        <div class="flex items-center gap-3">
          <div>
            <p class="text-[10px] font-black uppercase text-gray-400 leading-none mb-1">
              Atendido por
            </p>
            <p class="text-sm font-bold text-gray-800 dark:text-white leading-none">
              {{ pedido?.empleado }}
            </p>
            <p class="text-[10px] text-primary font-bold mt-1">
              {{ pedido?.rol_empleado }}
            </p>
          </div>
</div>
        <div class="text-right">
          <p class="text-[10px] font-black uppercase text-gray-400 mb-1">Mesa</p>
          <span class="px-3 py-1 bg-primary text-white rounded-lg font-black text-sm">
            {{ pedido?.mesa_nombre }}
          </span>
</div>
      </div>

      <div
        class="overflow-hidden rounded-3xl border border-gray-100 dark:border-gray-700"
      >
        <table class="w-full text-left">
          <thead
            class="bg-gray-50 dark:bg-gray-800 text-[10px] font-black uppercase text-gray-400"
          >
            <tr>
              <th class="px-4 py-2">Cant.</th>
              <th class="px-4 py-2">Producto</th>
              <th class="px-4 py-2 text-right">Total</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
            <tr v-for="item in pedido?.items" :key="item.id" class="text-xs">
              <td class="px-4 py-3 font-black text-primary">{{ item.cantidad }}x</td>
              <td class="px-4 py-3">
                <p class="font-bold text-gray-800 dark:text-gray-200 uppercase">
                  {{ item.producto_nombre }}
                </p>
                <p v-if="item.notas_item" class="text-[10px] text-amber-500 italic">
                  {{ item.notas_item }}
</p>
              </td>
              <td class="px-4 py-3 font-black text-right text-gray-900 dark:text-white">
                {{ money(item.cantidad * item.precio_unitario) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        class="p-5 bg-gray-900 dark:bg-primary/10 rounded-[1rem] text-white space-y-3 shadow-xl"
      >
        <div class="flex justify-between text-xs opacity-60 font-bold">
          <span>Subtotal</span>
          <span>{{ money(pedido?.subtotal) }}</span>
        </div>
        <div
          v-if="pedido?.inc > 0"
          class="flex justify-between text-xs opacity-60 font-bold"
        >
          <span>Impuesto (INC)</span>
          <span>{{ money(pedido?.inc) }}</span>
        </div>
        <div
          v-if="pedido?.propinas > 0"
          class="flex justify-between text-xs text-emerald-400 font-bold"
        >
          <span>Propina ({{ pedido?.porcentaje_propina }}%)</span>
          <span>{{ money(pedido?.propinas) }}</span>
        </div>
        <div
          v-if="pedido?.descuentos > 0"
          class="flex justify-between text-xs text-rose-400 font-bold"
        >
          <span>Descuentos</span>
          <span>- {{ money(pedido?.descuentos) }}</span>
</div>

        <div class="h-px bg-white/10 my-2"></div>

        <div class="flex justify-between items-center">
          <div>
            <p class="text-[10px] font-black uppercase opacity-40">Total a Pagar</p>
            <p class="text-xs opacity-60 italic">{{ pedido?.consecutivo_completo }}</p>
          </div>
          <p class="text-3xl font-black text-primary-light">
            {{ money(pedido?.total) }}
          </p>
        </div>
      </div>
</div>
</BaseModalSteps>
</template>