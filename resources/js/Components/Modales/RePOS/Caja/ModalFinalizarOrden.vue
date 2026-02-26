<script setup>
import { computed, watch, ref, nextTick } from "vue";
import { useForm } from "@inertiajs/vue3";
import BaseModalSteps from '@/Components/Shared/Modales/BaseModalSteps.vue';
import { formatCOP } from '@/Utils/formateoMoneda';
import NumberInput from "@/Components/Shared/inputs/NumberInput.vue";
import { limitesCaracteres } from "@/Utils/formateoInputs";
import Selects from "@/Components/Shared/inputs/Selects.vue";
import Checkbox from "@/Components/Shared/checkBox/Checkbox.vue";

const props = defineProps({
    isOpen: { type: Boolean, required: true },
    order: { type: Object, default: null },
    mediosDePagoDisponibles: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'confirm-payment']);

// --- CONFIGURACIÓN DE NEGOCIO ---
const EFECTIVO_ID = 1;
const IDS_CON_RECARGO = [2, 3]; // Tarjeta Débito y Crédito
const PORCENTAJE_RECARGO = 0.05; // 5%

// --- Refs de Interfaz ---
const metodoSeleccionado = ref(null);
const montoIngresado = ref(0);
const selectedTipPercentage = ref(0);
const tipOptions = [0, 5, 10, 15];

const form = useForm({
    payments: [],
    monto_pagado_cliente: 0,
    wantsInvoice: false,
    quiere_imprimir: true,
    propinas: 0,
});

// --- LÓGICA DE CÁLCULO FINANCIERO (Viene de la base de datos) ---

// 1. Valor Original (Precio de lista sin descuentos)
const precioBruto = computed(() => props.order ? (Number(props.order.subtotal) || 0) : 0);

// 2. Descuento ya aplicado en la orden (Ese 5% del producto que calculamos en el store)
const ahorroDescuento = computed(() => props.order ? (Number(props.order.descuentos) || 0) : 0);

// 3. Valor Neto (Lo que realmente vale la comida/productos tras el descuento)
const valorNeto = computed(() => props.order ? (Number(props.order.total) || 0) : 0);

// 4. Recargos por pagos con tarjeta (Se calculan dinámicamente según los pagos agregados)
const totalRecargosAcumulados = computed(() => {
    return form.payments.reduce((acc, pago) => acc + (Number(pago.recargo) || 0), 0);
});

// 5. TOTAL GENERAL (Neto + Propina + Recargos)
const totalGeneralFinal = computed(() => {
    return valorNeto.value + (Number(form.propinas) || 0) + totalRecargosAcumulados.value;
});

// --- GESTIÓN DE PAGOS ---

const totalPagadoDeuda = computed(() => form.payments.reduce((acc, pago) => acc + (Number(pago.monto) || 0), 0));

const montoRestante = computed(() => {
    // La deuda que el cliente debe cubrir con sus métodos de pago es Neto + Propina
    const deudaBase = valorNeto.value + (Number(form.propinas) || 0);
    const restante = deudaBase - totalPagadoDeuda.value;
    return Math.max(0, restante);
});

const porcentajePagado = computed(() => {
    const deudaBase = valorNeto.value + (Number(form.propinas) || 0);
    if (deudaBase === 0) return 0;
    return Math.min((totalPagadoDeuda.value / deudaBase) * 100, 100);
});

// Simulación de recargo para el método seleccionado actualmente
const recargoActualSimulado = computed(() => {
    if (!metodoSeleccionado.value) return 0;
    if (IDS_CON_RECARGO.includes(metodoSeleccionado.value) && montoIngresado.value > 0) {
        return Math.round(montoIngresado.value * PORCENTAJE_RECARGO);
    }
    return 0;
});

const totalCobrarDatafono = computed(() => {
    return (Number(montoIngresado.value) || 0) + recargoActualSimulado.value;
});

const totalPagadoEnEfectivo = computed(() => {
    return form.payments
        .filter(p => p.metodo_pago_id === EFECTIVO_ID)
        .reduce((acc, pago) => acc + (Number(pago.monto) || 0), 0);
});

const change = computed(() => {
    const recibido = Number(form.monto_pagado_cliente) || 0;
    if (recibido < totalPagadoEnEfectivo.value) return 0;
    return recibido - totalPagadoEnEfectivo.value;
});

// --- WATCHERS PARA AUTOMATIZACIÓN ---

watch(metodoSeleccionado, (newVal) => {
    if (newVal && montoRestante.value > 0) {
        montoIngresado.value = montoRestante.value;
    }
});

watch(montoRestante, (newVal) => {
    if (newVal > 0 && metodoSeleccionado.value) {
        montoIngresado.value = newVal;
    } else {
        montoIngresado.value = 0;
    }
});

watch(() => props.isOpen, (newVal) => {
    if (newVal && props.order) {
        form.reset();
        selectedTipPercentage.value = 0;
        form.propinas = 0;
        form.payments = [];
        if (props.mediosDePagoDisponibles.length > 0) {
            metodoSeleccionado.value = props.mediosDePagoDisponibles[0].id;
        }
        nextTick(() => {
            montoIngresado.value = valorNeto.value;
        });
    }
});

// --- ACCIONES ---

function addPayment() {
    if (!metodoSeleccionado.value || montoIngresado.value <= 0) return;

    const recargo = recargoActualSimulado.value;
    const nombreMetodo = props.mediosDePagoDisponibles.find(m => m.id === metodoSeleccionado.value)?.text ?? 'Otro';

    form.payments.push({
        metodo_pago_id: metodoSeleccionado.value,
        monto: montoIngresado.value,
        recargo: recargo,
        nombre_metodo: nombreMetodo
    });

    if (metodoSeleccionado.value === EFECTIVO_ID) {
        const totalEfectivo = form.payments
            .filter(p => p.metodo_pago_id === EFECTIVO_ID)
            .reduce((acc, pago) => acc + (Number(pago.monto) || 0), 0);
        form.monto_pagado_cliente = totalEfectivo;
    }
}

function removePayment(index) {
    const removido = form.payments.splice(index, 1)[0];
    if (removido.metodo_pago_id === EFECTIVO_ID) {
        const totalEfectivo = form.payments
            .filter(p => p.metodo_pago_id === EFECTIVO_ID)
            .reduce((acc, pago) => acc + (Number(pago.monto) || 0), 0);
        form.monto_pagado_cliente = totalEfectivo;
    }
}

function aplicarPropina(percentage) {
    selectedTipPercentage.value = percentage;
    form.propinas = Math.round(valorNeto.value * percentage / 100);
}

function handleSubmit() {
    if (montoRestante.value > 100) {
        alert('Falta cubrir ' + formatCOP(montoRestante.value));
        return;
    }
    if (form.monto_pagado_cliente < totalPagadoEnEfectivo.value) {
        alert('Efectivo insuficiente.');
        return;
    }
    
    emit('confirm-payment', {
        payments: form.payments.map(p => ({
            metodo_pago_id: p.metodo_pago_id,
            monto: p.monto,
            recargo: p.recargo
        })),
        monto_pagado_cliente: Number(form.monto_pagado_cliente) || 0,
        solicita_factura: form.wantsInvoice,
        quiere_imprimir: form.quiere_imprimir,
        propinas: Number(form.propinas) || 0,
    });
}
</script>

<template>
    <BaseModalSteps :isOpen="isOpen" :currentStep="1" :totalSteps="1" finalButtonText="Finalizar Cobro"
        :isSubmitting="form.processing" @close="emit('close')" @submit="handleSubmit" title="Finalizar Orden">

        <div v-if="order" class="text-secondary">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h2 class="text-2xl font-black text-gray-900 tracking-tight">Cerrar Cuenta</h2>
                    <p class="text-sm text-gray-400 font-medium">Factura #{{ order.consecutivo_completo }}</p>
                </div>
                <div class="bg-emerald-100 text-emerald-700 rounded-full px-4 py-1 flex items-center gap-2">
                    <span class="text-xs font-bold uppercase">Mesa: {{ order.mesa?.nombre_o_numero ?? 'Barra' }}</span>
                </div>
            </div>

            <div class="relative bg-slate-900 rounded-2xl p-6 mb-8 text-white shadow-2xl overflow-hidden border border-slate-800">
                <div class="absolute top-0 right-0 w-48 h-48 bg-emerald-500/10 rounded-full blur-3xl -mr-10 -mt-10"></div>

                <div class="relative z-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
                    <div>
                        <p class="text-slate-400 text-xs font-bold uppercase tracking-widest mb-1">Total a Pagar</p>
                        <p class="text-5xl font-black tracking-tighter text-emerald-400">
                            {{ formatCOP(totalGeneralFinal) }}
                        </p>
                    </div>
                    
                    <div class="w-full md:w-auto text-right text-xs space-y-1.5 border-t md:border-t-0 border-slate-800 pt-4 md:pt-0">
                        <div class="flex justify-between md:justify-end gap-6">
                            <span class="text-slate-500">Valor Bruto:</span>
                            <span class="font-mono">{{ formatCOP(precioBruto) }}</span>
                        </div>
                        <div v-if="ahorroDescuento > 0" class="flex justify-between md:justify-end gap-6 text-emerald-400 font-bold">
                            <span>Descuento Aplicado:</span>
                            <span class="font-mono">- {{ formatCOP(ahorroDescuento) }}</span>
                        </div>
                        <div class="flex justify-between md:justify-end gap-6 text-slate-200 font-bold pt-1 border-t border-slate-800">
                            <span>Valor Neto:</span>
                            <span class="font-mono text-sm">{{ formatCOP(valorNeto) }}</span>
                        </div>
                        <div v-if="form.propinas > 0" class="flex justify-between md:justify-end gap-6 text-yellow-500">
                            <span>Propina Voluntaria:</span>
                            <span class="font-mono">+ {{ formatCOP(form.propinas) }}</span>
                        </div>
                        <div v-if="totalRecargosAcumulados > 0" class="flex justify-between md:justify-end gap-6 text-orange-400 font-bold">
                            <span>Recargos Tarjeta (5%):</span>
                            <span class="font-mono">+ {{ formatCOP(totalRecargosAcumulados) }}</span>
                        </div>
                    </div>
                </div>

                <div class="mt-6">
                    <div class="flex justify-between text-[10px] font-bold mb-2 uppercase tracking-widest">
                        <span class="text-emerald-400">{{ porcentajePagado.toFixed(0) }}% Cubierto</span>
                        <span :class="montoRestante > 0 ? 'text-orange-400' : 'text-emerald-400'">
                            {{ montoRestante > 0 ? `Falta: ${formatCOP(montoRestante)}` : 'Cubierto ✓' }}
                        </span>
                    </div>
                    <div class="h-1.5 w-full bg-slate-800 rounded-full overflow-hidden">
                        <div class="h-full bg-emerald-500 transition-all duration-700" :style="{ width: `${porcentajePagado}%` }"></div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                <div class="lg:col-span-7 space-y-6">
                    <div v-if="montoRestante > 0" class="bg-gray-50 rounded-2xl p-5 border border-gray-200">
                        <h3 class="text-sm font-black text-gray-800 mb-4 flex items-center gap-2">
                            <span class="material-symbols-rounded text-emerald-600">add_circle</span>
                            Registrar Abono
                        </h3>
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <Selects v-model="metodoSeleccionado" :options="props.mediosDePagoDisponibles" label="Medio de Pago" />
                            <NumberInput v-model="montoIngresado" label="Monto" icon="attach_money" />
                        </div>

                        <div v-if="recargoActualSimulado > 0" class="bg-orange-50 border border-orange-100 p-3 rounded-xl mb-4 text-xs">
                            <div class="flex justify-between text-orange-800">
                                <span>Recargo por transacción (5%):</span>
                                <span class="font-bold">+ {{ formatCOP(recargoActualSimulado) }}</span>
                            </div>
                            <div class="flex justify-between font-black text-sm mt-1 border-t border-orange-200 pt-1">
                                <span>Cobrar en Datáfono:</span>
                                <span>{{ formatCOP(totalCobrarDatafono) }}</span>
                            </div>
                        </div>

                        <button @click="addPayment" type="button" class="w-full bg-gray-900 text-white font-bold py-3 rounded-xl hover:bg-black transition-all">
                            Confirmar este Pago
                        </button>
                    </div>

                    <div class="space-y-3">
                        <div v-for="(pago, index) in form.payments" :key="index" class="flex justify-between items-center p-3 bg-white border rounded-xl shadow-sm">
                            <div class="flex items-center gap-3">
                                <span class="material-symbols-rounded text-gray-400">{{ pago.metodo_pago_id === EFECTIVO_ID ? 'payments' : 'credit_card' }}</span>
                                <div>
                                    <p class="text-sm font-bold text-gray-900">{{ pago.nombre_metodo }}</p>
                                    <p class="text-[10px] text-gray-400" v-if="pago.recargo > 0">+ {{ formatCOP(pago.recargo) }} recargo</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-4">
                                <span class="font-mono font-bold">{{ formatCOP(pago.monto + pago.recargo) }}</span>
                                <button @click="removePayment(index)" class="text-red-400 hover:text-red-600"><span class="material-symbols-rounded">delete</span></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-5 space-y-6">
                    <div class="bg-white border rounded-2xl p-5 shadow-sm">
                        <h3 class="text-sm font-black text-gray-800 mb-4 flex items-center gap-2">
                            <span class="material-symbols-rounded text-yellow-500">volunteer_activism</span>
                            Propina
                        </h3>
                        <div class="flex gap-2 mb-4">
                            <button v-for="p in tipOptions" :key="p" @click="aplicarPropina(p)" 
                                :class="selectedTipPercentage === p ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600'"
                                class="flex-1 py-2 rounded-lg text-xs font-bold transition-all">
                                {{ p }}%
                            </button>
                        </div>
                        <NumberInput v-model="form.propinas" label="Monto Manual" icon="currency_exchange" />
                    </div>

                    <div v-if="totalPagadoEnEfectivo > 0" class="bg-emerald-50 border border-emerald-100 rounded-2xl p-5">
                        <NumberInput v-model="form.monto_pagado_cliente" label="Efectivo Recibido" icon="hand_gesture" helpText="Dinero que el cliente entrega" />
                        <div v-if="change > 0" class="mt-4 flex justify-between items-center">
                            <span class="text-xs font-bold text-emerald-700 uppercase">Cambio a Entregar:</span>
                            <span class="text-2xl font-black text-emerald-600">{{ formatCOP(change) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-8 pt-6 border-t flex flex-wrap gap-6">
                <Checkbox v-model="form.quiere_imprimir" label="Imprimir Recibo Térmico" />
                <Checkbox v-model="form.wantsInvoice" label="Factura Electrónica (DIAN)" class="opacity-50 pointer-events-none" />
            </div>
        </div>
    </BaseModalSteps>
</template>