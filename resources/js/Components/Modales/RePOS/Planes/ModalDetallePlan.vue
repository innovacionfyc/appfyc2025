<script setup>
import { ref, watch, computed } from "vue";
import axios from "axios";
import { useAuthStore } from "@/stores/auth";
import BaseModalSteps from "@/Components/Shared/Modales/BaseModalSteps.vue";
import { route } from "ziggy-js";

const props = defineProps({
  isOpen: { type: Boolean, required: true },
});

const emit = defineEmits(["close"]);
const authStore = useAuthStore();
const aplicacion = authStore.aplicacion;
const rol = authStore.rol;

const isLoading = ref(true);
const usageData = ref(null);

const formatCurrency = (value) => {
  return new Intl.NumberFormat("es-CO", {
    style: "currency",
    currency: "COP",
    maximumFractionDigits: 0,
  }).format(value);
};

const fetchUsage = async () => {
  try {
    isLoading.value = true;
    const response = await axios.get(route("api.planes.usage", { aplicacion, rol }));
    usageData.value = response.data;
  } catch (error) {
    console.error("Error cargando consumo:", error);
  } finally {
    isLoading.value = false;
  }
};

watch(
  () => props.isOpen,
  (newVal) => {
    if (newVal) fetchUsage();
  }
);

const iseStelar = computed(() => usageData.value?.plan_slug?.toLowerCase() === "estelar");

</script>

<template>
  <BaseModalSteps
    :is-open="isOpen"
    :current-step="1"
    :total-steps="1"
    title="Estado de tu Suscripción"
    description="Resumen en tiempo real de tus recursos y funcionalidades."
    final-button-text="Cerrar Panel"
    @close="$emit('close')"
    @submit="$emit('close')"
  >
    <div v-if="isLoading" class="p-4 md:p-8 space-y-6 md:space-y-8" >
      <div class="h-48 md:h-40 bg-gray-50 dark:bg-gray-800 rounded-3xl animate-pulse"></div>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6">
        <div v-for="i in 3" :key="i" class="h-32 bg-gray-50 dark:bg-gray-800 rounded-2xl animate-pulse"></div>
      </div>
    </div>

    <div v-else-if="usageData" class="p-4 md:p-8 space-y-6 md:space-y-8 relative">
      
      <div
        class="relative overflow-hidden rounded-[2rem] p-6 md:p-10 flex flex-col md:flex-row justify-between items-center gap-6 md:gap-8 shadow-2xl transition-all duration-500 hover:shadow-[0_20px_60px_-15px_rgba(0,0,0,0.3)] group"
        :class="
          iseStelar
            ? 'bg-[#0B1120] text-white ring-1 ring-white/10'
            : 'bg-white text-gray-900 ring-1 ring-gray-100 shadow-[0_10px_40px_-10px_rgba(0,0,0,0.08)]'
        "
      >
        <template v-if="iseStelar">
          <div class="absolute inset-0 bg-gradient-to-br from-indigo-500/10 via-purple-500/5 to-rose-500/10 z-0"></div>
          <div class="absolute -top-24 -right-24 w-64 h-64 bg-rose-500/20 rounded-full blur-[80px] group-hover:bg-rose-500/30 transition-colors duration-700"></div>
          <div class="absolute bottom-0 left-0 w-full h-px bg-gradient-to-r from-transparent via-rose-500/50 to-transparent"></div>
          <div class="absolute top-10 left-10 w-1 h-1 bg-white rounded-full opacity-50 animate-pulse"></div>
          <div class="absolute bottom-10 right-20 w-1.5 h-1.5 bg-rose-400 rounded-full opacity-40 animate-pulse delay-700"></div>
        </template>
        <template v-else>
           <div class="absolute inset-0 bg-gradient-to-br from-blue-50/50 via-white to-white z-0"></div>
           <div class="absolute -top-24 -right-24 w-64 h-64 bg-blue-400/10 rounded-full blur-[60px]"></div>
        </template>

        <div class="relative z-10 text-center md:text-left space-y-3 w-full md:w-auto">
          <div class="flex items-center justify-center md:justify-start gap-3 mb-1">
             <span class="text-[10px] md:text-[11px] font-black tracking-[0.2em] uppercase opacity-60">Tu Plan Actual</span>
             <span v-if="iseStelar" class="flex h-2 w-2 relative">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-rose-500"></span>
             </span>
          </div>
          
          <h2 class="text-4xl sm:text-5xl md:text-6xl font-black tracking-tighter leading-none transition-transform duration-300 group-hover:scale-[1.01]"
              :class="iseStelar ? 'text-transparent bg-clip-text bg-gradient-to-br from-white via-white to-gray-400' : 'text-gray-900'">
            {{ usageData.plan_nombre }}
          </h2>

          <div class="flex flex-wrap items-center justify-center md:justify-start gap-2 pt-2">
             <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-[10px] md:text-xs font-bold bg-opacity-10 backdrop-blur-md border border-opacity-10 shadow-sm"
                  :class="iseStelar ? 'bg-white border-white text-rose-200' : 'bg-blue-500 border-blue-500 text-blue-600'">
                <span class="material-symbols-rounded text-sm">event</span>
                Renueva: {{ usageData.fin }}
             </div>
             
             <div v-if="usageData.dias_restantes !== null" 
                  class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-[10px] md:text-xs font-bold bg-opacity-10 backdrop-blur-md border border-opacity-10 shadow-sm"
                  :class="iseStelar ? 'bg-emerald-400 border-emerald-400 text-emerald-300' : 'bg-emerald-500 border-emerald-500 text-emerald-600'">
                <span class="material-symbols-rounded text-sm">schedule</span>
                {{ Math.ceil(usageData.dias_restantes) }} días restantes
             </div>
          </div>
        </div>

        <div class="relative z-10 text-center md:text-right w-full md:w-auto border-t md:border-t-0 border-white/5 md:border-none pt-4 md:pt-0 mt-2 md:mt-0">
          <p class="text-[10px] md:text-xs font-bold uppercase tracking-widest opacity-50 mb-1">Inversión {{ usageData.periodo_facturacion }}</p>
          <div class="flex items-start justify-center md:justify-end gap-1">
             <span class="text-lg md:text-xl font-bold mt-1 opacity-60">$</span>
             <span class="text-4xl sm:text-5xl font-black tracking-tight" :class="iseStelar ? 'text-white' : 'text-gray-900'">
                {{ formatCurrency(usageData.precio).replace('$','').trim() }}
             </span>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6">
        
        <div
          v-for="(stat, idx) in usageData.stats"
          :key="idx"
          class="relative group rounded-[1.5rem] p-5 md:p-6 transition-all duration-300 hover:-translate-y-1 overflow-hidden"
          :class="[
             iseStelar 
               ? 'bg-[#0f172a] border border-white/5 shadow-lg' // Fondo Slate-900 sólido para contraste
               : 'bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 shadow-sm hover:shadow-lg'
          ]"
        >
          <div v-if="iseStelar" class="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none bg-gradient-to-br from-rose-500/5 to-transparent"></div>

          <div class="relative z-10 flex justify-between items-start mb-6">
            <div class="flex items-center gap-3 md:gap-4">
              <div
                class="w-10 h-10 md:w-12 md:h-12 rounded-2xl flex items-center justify-center transition-transform group-hover:scale-110 duration-300 shadow-sm"
                :class="[
                  iseStelar
                    ? 'bg-gray-900 text-rose-400 border border-white/10 shadow-[0_0_15px_rgba(244,63,94,0.1)]'
                    : 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400',
                ]"
              >
                <span class="material-symbols-rounded text-xl md:text-2xl">
                  {{
                    stat.label.toLowerCase().includes("usu") ? "group" : 
                    stat.label.toLowerCase().includes("prod") ? "inventory_2" : 
                    stat.label.toLowerCase().includes("fact") ? "receipt_long" : 
                    stat.tipo === 'boleano' ? 'extension' : 'analytics'
                  }}
                </span>
              </div>
              <div>
                 <h4 class="font-bold text-sm md:text-base leading-tight" :class="iseStelar ? 'text-white' : 'text-gray-900 dark:text-white'">{{ stat.label }}</h4>
                 <p class="text-[9px] md:text-[10px] font-medium uppercase tracking-wider opacity-50 mt-0.5" :class="iseStelar ? 'text-gray-400' : 'text-gray-500'">
                    {{ stat.tipo === 'boleano' ? 'Feature' : 'Recurso' }}
                 </p>
              </div>
            </div>
          </div>

          <div v-if="stat.tipo !== 'boleano'" class="relative z-10">
              <div class="flex items-end justify-between mb-3">
                <span class="text-2xl md:text-3xl font-black tracking-tight" :class="iseStelar ? 'text-white' : 'text-gray-900 dark:text-white'">
                  {{ stat.consumido }}
                </span>
                <span class="text-[10px] md:text-xs font-bold opacity-50 mb-1.5" :class="iseStelar ? 'text-gray-400' : 'text-gray-500'">
                   / {{ stat.limite }}
                </span>
              </div>

              <div class="relative w-full h-1.5 md:h-2 rounded-full overflow-hidden" 
                   :class="iseStelar ? 'bg-gray-800' : 'bg-gray-100 dark:bg-gray-700'">
                 
                 <div 
                    class="h-full rounded-full transition-all duration-1000 ease-out relative"
                    :class="[
                       stat.is_unlimited ? 'w-full bg-emerald-500' :
                       stat.porcentaje > 90 ? 'bg-rose-500 shadow-[0_0_8px_rgba(244,63,94,0.6)]' : 
                       stat.porcentaje > 75 ? 'bg-orange-400' :
                       iseStelar ? 'bg-white shadow-[0_0_8px_rgba(255,255,255,0.4)]' : 'bg-blue-600'
                    ]"
                    :style="{ width: stat.is_unlimited ? '100%' : `${stat.porcentaje}%` }"
                 ></div>
              </div>

              <div class="mt-3 flex justify-end">
                 <span v-if="stat.is_unlimited" class="text-[10px] font-bold text-emerald-500 flex items-center gap-1 bg-emerald-500/10 px-2 py-0.5 rounded-full">
                    <span class="material-symbols-rounded text-sm">all_inclusive</span> Ilimitado
                 </span>
                 <span v-else class="text-[10px] font-bold" :class="stat.porcentaje > 90 ? 'text-rose-500' : 'opacity-50'">
                    {{ stat.porcentaje }}% Ocupado
                 </span>
              </div>
          </div>

          <div v-else class="mt-4 relative z-10">
             <div class="flex items-center gap-3 p-3 rounded-2xl border border-dashed transition-colors"
                  :class="iseStelar ? 'border-white/10 bg-white/5' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/20'">
                 <div class="h-8 w-8 rounded-full flex items-center justify-center shrink-0"
                      :class="stat.consumido ? 'bg-emerald-500/10 text-emerald-500' : 'bg-gray-200 dark:bg-gray-600 text-gray-400'">
                     <span class="material-symbols-rounded text-lg">{{ stat.consumido ? 'check' : 'close' }}</span>
                 </div>
                 <div class="flex flex-col">
                     <span class="text-xs font-bold" :class="iseStelar ? 'text-white' : 'text-gray-900 dark:text-white'">
                        {{ stat.consumido ? 'Habilitado' : 'No incluido' }}
                     </span>
                     <span class="text-[10px] opacity-50">  {{ stat.consumido ? 'Disponible en tu plan' : 'Sube de nivel para más control' }} </span>
                 </div>
             </div>
          </div>
         
        </div>
      </div>

      <div class="rounded-2xl p-5 flex flex-col sm:flex-row gap-4 items-start shadow-sm"
           :class="iseStelar ? 'bg-[#0f172a] border border-white/5' : 'bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800'">
        <div class="p-2 rounded-xl shrink-0" :class="iseStelar ? 'bg-white/5 text-gray-300' : 'bg-blue-500/10 text-blue-500 dark:text-blue-400'">
           <span class="material-symbols-rounded">info</span>
        </div>
        <div>
          <h4 class="text-sm font-bold mb-1" :class="iseStelar ? 'text-white' : 'text-blue-900 dark:text-blue-200'">
            Ciclo de Facturación
          </h4>
          <p class="text-xs leading-relaxed opacity-80" :class="iseStelar ? 'text-gray-400' : 'text-blue-700 dark:text-blue-300'">
            Tus contadores mensuales se reinician automáticamente el día 
            <strong>{{ usageData.inicio.split("/")[0] }}</strong> de cada mes. 
            <span class="block mt-1 opacity-70">Si necesitas aumentar tus límites antes de la fecha de corte, dirígete a la sección de actualización de plan.</span>
          </p>
        </div>
      </div>

    </div>
  </BaseModalSteps>
</template>