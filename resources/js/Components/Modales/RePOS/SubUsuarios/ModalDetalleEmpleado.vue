<script setup>
import BaseModalSteps from '@/Components/Shared/Modales/BaseModalSteps.vue';
import { formatCOP } from '@/Utils/formateoMoneda';
import { computed } from 'vue';

const props = defineProps({
    isOpen: Boolean,
    empleado: { type: Object, default: () => ({}) }, // Valor por defecto vacío
});

const emit = defineEmits(['close']);

// --- COMPUTADAS SEGURAS (Safety First) ---
// Si props.empleado es null/undefined, usamos {} para evitar errores.
const empleado = computed(() => props.empleado || {});

  const iniciales = computed(() => {
      const n = empleado.value.perfil_usuario.primer_nombre || '';
      const a = empleado.value.perfil_usuario.primer_apellido || '';
      return (n.charAt(0) + a.charAt(0)).toUpperCase();
  });

// Métricas (con validación de existencia)
const ventasNetas = computed(() => empleado.value.total_venta_neta ?? 0);
const propinas = computed(() => empleado.value.total_propinas ?? 0);
const tieneMetricas = computed(() => empleado.value.total_ventas_brutas !== undefined);

</script>

<template>
    <BaseModalSteps :isOpen="isOpen" :totalSteps="1" :showFooter="false" @close="emit('close')">

        <div class="relative overflow-hidden bg-white dark:bg-[#1e293b] rounded-t-2xl w-[600px]">
            <div class="h-32 bg-gradient-to-r from-primary to-rose-700 relative">
                <div class="absolute top-0 right-0 w-40 h-40 bg-white/10 rounded-full blur-3xl -mr-10 -mt-10"></div>
            </div>

            <div class="px-6 relative">
                <div class="absolute -top-12 left-6">
                    <div class="w-24 h-24 rounded-2xl bg-white dark:bg-[#1e293b] p-1 shadow-lg">
                        <img v-if="empleado.perfil_usuario.ruta_foto"
                            :src="'/storage/' + empleado.perfil_usuario.ruta_foto"
                            class="w-full h-full object-cover rounded-xl">
                        <div v-else
                            class="w-full h-full rounded-xl bg-gradient-to-r from-primary to-rose-700 flex items-center justify-center text-2xl font-black text-mono-blanco">
                            {{ iniciales }}
                        </div>
                    </div>
                </div>

                <div class="flex justify-end pt-4 gap-2">
                    <span
                        class="px-3 py-1 rounded-full bg-green-100 text-semaforo-verde text-xs font-bold border border-green-200">
                        {{ empleado.perfil_usuario.estado?.tipo_estado }}
                    </span>
                </div>

                <div class="mt-4 pb-6 border-b border-gray-100 dark:border-gray-700">
                    <h2 class="text-2xl font-black text-gray-900 dark:text-white leading-tight">
                        {{ empleado.perfil_usuario.primer_nombre }} {{ empleado.perfil_usuario.segundo_nombre }} {{
                            empleado.perfil_usuario.primer_apellido }}
                    </h2>
                    <p class="text-sm text-indigo-600 dark:text-indigo-400 font-bold mt-1">
                        {{ empleado.perfil_empleado.cargo || 'Sin Cargo' }}
                    </p>
                    <div class="flex items-center gap-4 mt-3 text-xs text-gray-500 dark:text-gray-400">
                        <span class="flex items-center gap-1"><span class="material-symbols-rounded text-sm">mail</span>
                            {{ empleado.perfil_usuario.correo }}</span>
                        <span class="flex items-center gap-1"><span class="material-symbols-rounded text-sm">call</span>
                            {{ empleado.perfil_usuario.telefono }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="p-6 space-y-6 max-h-[500px] overflow-y-auto scrollbar-custom">

            <div v-if="tieneMetricas" class="grid grid-cols-2 gap-4">
                <div
                    class="bg-emerald-50 dark:bg-emerald-900/10 p-4 rounded-xl border border-emerald-100 dark:border-emerald-800/30">
                    <p class="text-[10px] font-bold text-emerald-600 uppercase mb-1">Ventas Netas</p>
                    <p class="text-xl font-black text-emerald-700 dark:text-emerald-400 font-mono">
                        {{ formatCOP(ventasNetas) }}
                    </p>
                </div>
                <div
                    class="bg-amber-50 dark:bg-amber-900/10 p-4 rounded-xl border border-amber-100 dark:border-amber-800/30">
                    <p class="text-[10px] font-bold text-amber-600 uppercase mb-1">Propinas</p>
                    <p class="text-xl font-black text-amber-700 dark:text-amber-400 font-mono">
                        {{ formatCOP(propinas) }}
                    </p>
                </div>
            </div>

            <div>
                <h4 class="text-sm font-bold text-gray-900 dark:text-white mb-3 border-l-4 border-indigo-500 pl-2">
                    Información Contractual</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-y-4 gap-x-8 text-sm">
                    <div>
                        <p class="text-xs text-gray-400">Tipo de Contrato</p>
                        <p class="font-medium text-gray-700 dark:text-gray-300">{{
                            empleado.perfil_empleado.tipo_contrato }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400">Salario Base</p>
                        <p class="font-medium text-gray-700 dark:text-gray-300 font-mono">{{
                            formatCOP(empleado.perfil_empleado.salario_base) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400">Fecha Ingreso</p>
                        <p class="font-medium text-gray-700 dark:text-gray-300">{{
                            empleado.perfil_empleado.fecha_ingreso }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400">Horario</p>
                        <p class="font-medium text-gray-700 dark:text-gray-300">{{ empleado.perfil_empleado.horario  }}</p>
                    </div>
                </div>
            </div>

            <div>
                <h4 class="text-sm font-bold text-gray-900 dark:text-white mb-3 border-l-4 border-blue-500 pl-2">Datos
                    Bancarios</h4>
                <div
                    class="bg-gray-50 dark:bg-gray-800 p-3 rounded-lg border border-gray-100 dark:border-gray-700 flex items-center gap-4">
                    <div
                        class="w-10 h-10 rounded-full bg-white dark:bg-gray-700 flex items-center justify-center shadow-sm">
                        <span class="material-symbols-rounded text-gray-400">account_balance</span>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-500">{{ empleado.perfil_empleado.banco}}</p>
                        <p class="text-sm font-mono font-bold text-gray-800 dark:text-white">{{
                            empleado.perfil_empleado.cuenta_ahorros }}</p>
                    </div>
                </div>
            </div>

            <div>
                <h4 class="text-sm font-bold text-gray-900 dark:text-white mb-3 border-l-4 border-gray-400 pl-2">Datos
                    Personales</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-y-4 gap-x-8 text-sm">
                    <div>
                        <p class="text-xs text-gray-400">Documento</p>
                        <p class="font-medium text-gray-700 dark:text-gray-300">
                            {{ empleado.perfil_usuario.tipo_documento.documento_legal }}: {{ empleado.numero_documento
                            }}


                        </p>
                    </div>
                    <div class="md:col-span-2">
                        <p class="text-xs text-gray-400">Residencia</p>
                        <p class="font-medium text-gray-700 dark:text-gray-300">
                            {{ empleado.perfil_usuario.direccion_residencia }}, {{
                                empleado.perfil_usuario.barrio_residencia }} - {{ empleado.perfil_usuario.ciudad_residencia
                            }}
                        </p>
                    </div>
                </div>
            </div>

        </div>
    </BaseModalSteps>
</template>