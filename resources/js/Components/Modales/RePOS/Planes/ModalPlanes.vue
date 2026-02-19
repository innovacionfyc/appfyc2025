<script setup>
import { ref, watch } from "vue";
import axios from "axios";
import { useAuthStore } from "@/stores/auth";
import BaseModalSteps from "@/Components/Shared/Modales/BaseModalSteps.vue";
import { route } from "ziggy-js";
import ModalConfirmarPlan from "./ModalConfirmarPlan.vue";

const props = defineProps({
  isOpen: { type: Boolean, required: true },
});

const emit = defineEmits(["close", "select-plan", "view-details"]);
const authStore = useAuthStore();
const aplicacion = authStore.aplicacion;
const rol = authStore.rol;

const currentStep = ref(1);
const totalSteps = 1;
const isSubmitting = ref(false);
const isLoading = ref(true);
const plans = ref([]);
const selectedPlanId = ref(null);
const currentActivePlanId = ref(null);
const isConfirmModalOpen = ref(false);
const planToConfirm = ref(null);

// --- VISUAL CONFIG (Igual que antes) ---
const visualConfig = {
  free: {
    theme: "gray",
    bg: "bg-white dark:bg-gray-800",
    border: "border-gray-200 dark:border-gray-700",
    priceColor: "text-gray-900 dark:text-white",
    badgeName: "bg-gray-100 text-gray-500",
    button: "bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200",
    iconCheck: "text-gray-400",
    iconCross: "text-gray-300",
  },
  basic: {
    theme: "blue",
    bg: "bg-white dark:bg-gray-800",
    border: "border-blue-100 dark:border-blue-900/30",
    priceColor: "text-gray-900 dark:text-white",
    badgeName: "bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-300",
    button: "bg-blue-500 text-white hover:bg-blue-600 shadow-lg shadow-blue-500/20",
    iconCheck: "text-blue-500",
    iconCross: "text-gray-300",
  },
  pro: {
    theme: "violet",
    popular: true,
    bg: "bg-white dark:bg-gray-800",
    border: "border-violet-200 dark:border-violet-900/50",
    priceColor: "text-gray-900 dark:text-white",
    badgeName: "bg-violet-50 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300",
    button: "bg-violet-600 text-white hover:bg-violet-700 shadow-xl shadow-violet-500/30",
    iconCheck: "text-violet-500",
    iconCross: "text-gray-300",
  },
  estelar: {
    theme: "estelar",
    popular: false,
    button: "bg-gradient-to-r from-rose-600 to-pink-600 text-white shadow-lg shadow-rose-500/40 hover:shadow-rose-500/60 border border-white/10",
  },
  default: { theme: "gray", bg: "bg-white", border: "border-gray-200", button: "bg-gray-200", iconCheck: "text-gray-400" },
};

const formatCurrency = (value) => {
  if (value === null || value === undefined) return "$ 0";
  return new Intl.NumberFormat("es-CO", {
    style: "currency",
    currency: "COP",
    maximumFractionDigits: 0,
  }).format(value);
};

const cleanPriceDisplay = (priceString) => {
  if (!priceString || priceString === '$ 0') return "0";
  return String(priceString).replace("$", "").replace(",00", "").trim();
};

const getVisuals = (slug) => {
  const key = slug ? slug.toLowerCase() : "default";
  return { ...visualConfig["default"], ...(visualConfig[key] || {}) };
};

const parseFeature = (pivot, featureName, featureType) => {
  const isActive = (featureType === "boleano")
    ? (pivot.valor == "1" || pivot.valor === "true" || pivot.valor === true)
    : true;

  let displayValue = pivot.valor;
  if (pivot.valor == -1 || pivot.valor == 9999) displayValue = "Ilimitados";

  return {
    label: featureName,
    value: pivot.valor,
    isBoolean: featureType === "boleano",
    isActive: isActive,
    display: featureType === "boleano" ? featureName : `${displayValue} ${featureName}`
  };
};

const fetchPlanes = async () => {
  try {
    isLoading.value = true;
    const response = await axios.get(route("api.planes.index", { aplicacion, rol }));

    if (response.data) {
      currentActivePlanId.value = Number(response.data.plan_actual_id);

      plans.value = response.data.planes.map((plan) => {
        const visuals = getVisuals(plan.slug);
        
        // Lógica especial para Plan Gratis (ID 1)
        const isFree = Number(plan.id) === 1;
        const displayPrice = isFree ? "$ 0" : formatCurrency(plan.precio_mensual);
        const displayPeriod = isFree ? "Semanal" : "Mensual";

        return {
          id: Number(plan.id),
          db_slug: plan.slug,
          name: plan.nombre,
          price: displayPrice, // Usamos precio formateado correcto
          prices: plan.prices, // Precios crudos para el modal de confirmación
          period: displayPeriod,
          description: visuals.description, // Asegúrate de tener descripciones en tu config o DB
          visuals: visuals,
          features: (plan.caracteristicas || []).map((c) =>
            parseFeature(c.pivot, c.nombre, c.tipo)
          ),
        };
      });

      selectedPlanId.value = currentActivePlanId.value || plans.value[0]?.id;
    }
  } catch (error) {
    console.error(error);
  } finally {
    isLoading.value = false;
  }
};

watch(() => props.isOpen, (newVal) => {
  if (newVal) fetchPlanes();
});

const handleSelectPlan = (planId) => {
  selectedPlanId.value = planId;
};

const handlePreSubmit = () => {
  if (selectedPlanId.value === currentActivePlanId.value) {
    emit("close");
    return;
  }
  
  const plan = plans.value.find(p => p.id === selectedPlanId.value);
  if (plan) {
     planToConfirm.value = plan;
     isConfirmModalOpen.value = true; 
  }
};

const handleFinalConfirm = ({ planId, duration }) => {
   isConfirmModalOpen.value = false;
   emit("select-plan", { planId, duration });
   emit("close");
};

const getActiveBorderClass = (plan) => {
  if (plan.visuals.theme === "estelar") return "";
  const borderClass = plan.visuals.border || "";
  return `shadow-2xl ring-4 ring-primary/20 ${borderClass.replace("100", "500").replace("200", "500")}`;
};
</script>

<template>
  <BaseModalSteps
    :is-open="isOpen"
    :current-step="currentStep"
    :total-steps="totalSteps"
    :is-submitting="isSubmitting"
    title="Escala tu Negocio"
    description="Elige el plan que mejor se adapte a tus necesidades actuales."
    :final-button-text="selectedPlanId === currentActivePlanId ? 'Tu Plan Actual' : 'Confirmar Cambio'"
    @close="$emit('close')"
    @submit="handlePreSubmit"
    class="max-w-[95rem] w-full mx-auto"
  >
    <div v-if="isLoading" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 p-6">
      <div v-for="i in 4" :key="i" class="h-[550px] rounded-[2.5rem] bg-gray-50 dark:bg-gray-800 animate-pulse border border-gray-100 dark:border-gray-700 p-8"></div>
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 lg:gap-8 p-4 pt-10 pb-10 items-end">
      
      <div
        v-for="plan in plans"
        :key="plan.id"
        @click="handleSelectPlan(plan.id)"
        class="group relative flex flex-col cursor-pointer transition-all duration-500 outline-none"
        :class="[
          selectedPlanId === plan.id ? 'z-20 scale-100' : 'hover:scale-[1.02] opacity-95 hover:opacity-100 hover:z-10',
        ]"
      >
        <div v-if="plan.visuals.popular" class="absolute -top-5 left-0 right-0 flex justify-center z-50 transition-transform duration-300"
             :class="selectedPlanId === plan.id ? '-translate-y-2' : 'group-hover:-translate-y-2'">
          <div class="bg-gradient-to-r from-violet-600 via-fuchsia-600 to-violet-600 bg-[length:200%_auto] animate-gradient text-white text-[10px] font-black px-5 py-1.5 rounded-full shadow-lg uppercase tracking-[0.2em] border-2 border-white dark:border-gray-900">
            Más Popular
          </div>
        </div>

        <div class="relative w-full rounded-[2.5rem] overflow-hidden border transition-all duration-300 flex flex-col h-full"
             :class="[
               plan.visuals.theme === 'estelar' ? 'bg-[#0B1120] border-gray-800 shadow-2xl' : `${plan.visuals.bg} ${plan.visuals.border}`,
               selectedPlanId === plan.id && plan.visuals.theme !== 'estelar' ? getActiveBorderClass(plan) : '',
               plan.visuals.theme !== 'estelar' && selectedPlanId !== plan.id ? 'hover:shadow-xl hover:border-gray-300 dark:hover:border-gray-600' : ''
             ]">
          
          <template v-if="plan.visuals.theme === 'estelar'">
            <div class="absolute inset-0 bg-[#0B1120] z-0"></div>
            <div class="absolute inset-0 bg-gradient-to-br from-rose-900/20 via-transparent to-primary/10 z-0"></div>
            <div class="absolute inset-0 opacity-[0.07] bg-[url('https://grainy-gradients.vercel.app/noise.svg')] mix-blend-overlay z-0"></div>
            <div class="absolute -right-16 -top-10 opacity-[0.1] transform rotate-12 group-hover:rotate-[20deg] transition-transform duration-1000 z-0">
              <span class="material-symbols-rounded text-[280px] text-white">diamond</span>
            </div>
            <div v-if="selectedPlanId === plan.id" class="absolute inset-0 border-2 border-rose-500/30 rounded-[2.5rem] z-20 pointer-events-none"></div>
          </template>

          <template v-if="plan.visuals.theme === 'violet'">
             <div class="absolute top-0 inset-x-0 h-40 bg-gradient-to-b from-violet-50/80 to-transparent dark:from-violet-900/10 pointer-events-none"></div>
          </template>

          <div class="relative z-10 p-8 flex flex-col h-full">
            <div class="text-center mb-8">
              <span class="inline-block text-[10px] font-black uppercase tracking-[0.2em] py-2 px-4 rounded-xl mb-5 transition-colors"
                    :class="plan.visuals.theme === 'estelar' ? 'bg-white/5 text-rose-300 border border-white/10 shadow-inner' : 'bg-gray-50 dark:bg-gray-700/50 text-gray-500 dark:text-gray-400'">
                {{ plan.name }}
              </span>

              <div class="flex items-start justify-center gap-1 mb-2"
                   :class="plan.visuals.theme === 'estelar' ? 'text-white' : plan.visuals.priceColor">
                <span class="text-xl font-bold mt-2 opacity-60">$</span>
                <span class="text-6xl font-black tracking-tighter">{{ cleanPriceDisplay(plan.price) }}</span>
              </div>
              
              <p class="text-xs font-bold uppercase tracking-widest opacity-40 mb-4"
                 :class="plan.visuals.theme === 'estelar' ? 'text-gray-400' : 'text-gray-500'">
                Facturación {{ plan.period }}
              </p>

              <p class="text-sm font-medium leading-relaxed px-2 min-h-[45px] flex items-center justify-center opacity-80"
                 :class="plan.visuals.theme === 'estelar' ? 'text-gray-300' : 'text-gray-600 dark:text-gray-400'">
                {{ plan.description }}
              </p>
            </div>

            <div class="w-full px-4 mb-6">
               <div class="h-px w-full" :class="plan.visuals.theme === 'estelar' ? 'bg-white/10' : 'bg-gray-200 dark:bg-gray-700'"></div>
            </div>

            <ul class="flex-1 space-y-4 px-2">
              <li v-for="(feature, idx) in plan.features" :key="idx" class="flex items-start gap-3 text-xs"
                  :class="[plan.visuals.theme === 'estelar' ? 'text-gray-300' : 'text-gray-600 dark:text-gray-300', !feature.isActive ? 'opacity-40 grayscale' : '']">
                <div class="shrink-0 mt-0.5">
                  <span v-if="feature.isActive" class="material-symbols-rounded text-[20px]"
                        :class="plan.visuals.theme === 'estelar' ? 'text-rose-500 drop-shadow' : plan.visuals.iconCheck">check_circle</span>
                  <span v-else class="material-symbols-rounded text-[20px]"
                        :class="plan.visuals.theme === 'estelar' ? 'text-gray-600' : plan.visuals.iconCross">cancel</span>
                </div>
                <div class="flex flex-col">
                  <span class="font-bold text-[13px] leading-tight">{{ feature.display }}</span>
                  <span v-if="feature.isBoolean && feature.isActive" class="text-[10px] opacity-50 font-medium mt-0.5 uppercase">Incluido</span>
                </div>
              </li>
            </ul>

            <div class="mt-10">
              <div v-if="Number(currentActivePlanId) === Number(plan.id)" class="flex gap-3">
                <button class="flex-1 py-4 rounded-2xl text-xs font-black uppercase tracking-widest flex items-center justify-center gap-2 bg-emerald-500 text-white shadow-lg cursor-default">
                  <span>Tu Plan Actual</span>
                  <span class="material-symbols-rounded text-lg">verified_user</span>
                </button>
                <button @click.stop="$emit('view-details')" class="w-[60px] flex items-center justify-center rounded-2xl transition-all hover:scale-105 active:scale-95 group/info"
                        :class="plan.visuals.theme === 'estelar' ? 'bg-white/5 text-white border border-white/10' : 'bg-emerald-50 text-emerald-600 border border-emerald-100'">
                  <span class="material-symbols-rounded text-2xl group-hover/info:text-emerald-400">visibility</span>
                </button>
              </div>

              <button v-else class="relative w-full py-4 rounded-2xl text-xs font-black uppercase tracking-widest transition-all duration-300 flex items-center justify-center gap-3 group/btn overflow-hidden"
                      :class="[
                        selectedPlanId === plan.id ? 'bg-primary text-white shadow-xl scale-[1.02]' : 
                        plan.visuals.theme === 'estelar' ? 'bg-gradient-to-r from-rose-600 to-rose-500 text-white hover:shadow-lg' : plan.visuals.button
                      ]">
                <div class="absolute inset-0 -translate-x-full group-hover/btn:translate-x-full transition-transform duration-700 bg-gradient-to-r from-transparent via-white/20 to-transparent z-0"></div>
                <span class="relative z-10">{{ selectedPlanId === plan.id ? "Seleccionado" : "Elegir Plan" }}</span>
                <span v-if="selectedPlanId !== plan.id" class="material-symbols-rounded text-lg relative z-10 group-hover/btn:translate-x-1 transition-transform">arrow_forward</span>
                <span v-else class="material-symbols-rounded text-lg relative z-10">check</span>
              </button>
            </div>

          </div>
        </div>
      </div>
    </div>
  </BaseModalSteps>

  <ModalConfirmarPlan 
     :is-open="isConfirmModalOpen"
     :plan="planToConfirm || {}"
     :is-estelar="planToConfirm?.visuals?.theme === 'estelar'"
     @close="isConfirmModalOpen = false"
     @back="isConfirmModalOpen = false"
     @confirm="handleFinalConfirm"
  />
</template>