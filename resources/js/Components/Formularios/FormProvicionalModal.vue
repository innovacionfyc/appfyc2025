<script setup>
import { ref, computed } from "vue";
import { ClipboardType, Loader2, ExternalLink } from "lucide-vue-next";
import BaseStepperModal from "../Modales/BaseStepperModal.vue";

const props = defineProps({
  show: Boolean,
  evento: Object,
});

const emit = defineEmits(["close"]);

const isIframeLoading = ref(true);

const activeColor = computed(
  () => props.evento?.area_formacion?.color_hex_principal || "#f97316"
);

const externalUrl = computed(() => 
  props.evento?.url_formulario_inscripcion || 
  "https://fycconsultores.com"
);

const handleIframeLoad = () => {
  isIframeLoading.value = false;
};

const stepFields = [[]];

const openInNewTab = () => {
  window.open(externalUrl.value, '_blank');
};
</script>

<template>
  <BaseStepperModal
    :show="show"
    title="Formulario de Inscripción Oficial"
    :totalSteps="0"
    :icon="ClipboardType"
    :activeColor="activeColor"
    :stepFields="stepFields"
    class=""
    @close="emit('close')"
    hide-footer 
  >
    <template #default>
      <div class="relative w-full h-[75vh] min-h-[500px] bg-slate-50 rounded-2xl overflow-hidden border border-slate-100">
        
        <div 
          v-if="isIframeLoading" 
          class="absolute inset-0 flex flex-col items-center justify-center bg-white z-10"
        >
          <Loader2 class="w-10 h-10 animate-spin mb-4" :style="{ color: activeColor }" />
          <p class="text-sm font-bold text-slate-400 animate-pulse uppercase tracking-widest">
            Cargando Formulario de Registro...
          </p>
        </div>

        <div class="absolute top-4 right-4 z-20 flex gap-2">
          <button 
            @click="openInNewTab"
            class="p-2 bg-white/80 backdrop-blur-md border border-slate-200 rounded-xl shadow-sm hover:bg-white transition-all text-slate-500 hover:text-slate-900 group"
            title="Abrir en pestaña nueva"
          >
            <ExternalLink class="w-4 h-4 group-hover:scale-110 transition-transform" />
          </button>
        </div>

        <iframe
          :src="externalUrl"
          class="w-full h-full border-none animate-in"
          @load="handleIframeLoad"
          allow="payment; geolocation"
          title="Registro F&C Consultores"
        ></iframe>

        <div class="absolute bottom-4 left-6 pointer-events-none opacity-20 hidden md:block">
           <img src="/images/buho_fyc.png" class="h-10 grayscale opacity-50" />
        </div>
      </div>
    </template>
  </BaseStepperModal>
</template>

<style scoped>
/* Estilo para que el iframe se vea fluido */
iframe {
  display: block;
  background: white;
}

.custom-scroll::-webkit-scrollbar {
  width: 4px;
}
.custom-scroll::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 10px;
}

.animate-in {
  animation: slideIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateY(15px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>