<script setup>
import { ref, computed, watch } from "vue";
import { X, Loader2, ExternalLink, Eye } from "lucide-vue-next";
import BtnPrimario from "../Shared/buttons/btnPrimario.vue";
import BtnUniversal from "../BtnUniversal.vue";

const props = defineProps({
  show: Boolean,
  evento: Object,
});

const emit = defineEmits(["close"]);

const isIframeLoading = ref(true);

const activeColor = computed(
  () => props.evento?.area_formacion?.color_hex_principal || "#f97316"
);

const externalUrl = computed(() => {
  if (!props.evento?.id) return "";
  return `${window.location.origin}/evento/${props.evento.id}`;
});

watch(
  () => props.show,
  (newVal) => {
    if (newVal) isIframeLoading.value = true;
  }
);

const handleIframeLoad = () => {
  isIframeLoading.value = false;
};

const openInNewTab = () => {
  window.open(externalUrl.value, "_blank");
};
</script>

<template>
  <div
    v-if="show"
    class="fixed inset-0 z-[150] flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-md"
  >
    <div
      class="bg-mono-blanco w-full max-w-[1850px] h-[90vh] rounded-[3rem] overflow-hidden flex flex-col shadow-2xl relative animate-in zoom-in duration-300"
    >
      <div
        class="px-8 py-5 border-b border-slate-100 flex justify-between items-center bg-mono-blanco z-10"
      >
        <div class="flex items-center gap-4">
          <div
            class="w-10 h-10 rounded-2xl flex items-center justify-center text-monobg-mono-blanco shadow-lg"
            :style="{ background: activeColor }"
          >
            <Eye class="w-5 h-5" />
          </div>
          <div>
            <h2 class="text-lg font-black text-slate-900 leading-none">
              Previsualización pública
            </h2>
            <p class="text-[15px] font-bold text-slate-400 mt-1">
              vista real del usuario final
            </p>
          </div>
        </div>

        <div class="flex items-center gap-2">
         

          <BtnUniversal
            label="Abrir link real"
            icon="link"
            icon-position="right"
            :activeColor="activeColor"
            @click="openInNewTab"
          />

          <button
            @click="emit('close')"
            class="p-2 hover:bg-slate-100 rounded-full transition-all text-slate-400"
          >
            <X class="w-6 h-6" />
          </button>
        </div>
      </div>

      <div class="flex-1 bg-slate-50 relative overflow-hidden">
        <div
          v-if="isIframeLoading"
          class="absolute inset-0 flex flex-col items-center justify-center bg-mono-blanco z-20"
        >
          <div class="relative flex items-center justify-center">
            <div class="absolute w-20 h-20 border-4 border-slate-100 rounded-full"></div>
            <Loader2
              class="w-10 h-10 animate-spin text-slate-200"
              :style="{ color: activeColor }"
            />
          </div>
          <p
            class="mt-6 text-[10px] font-black text-slate-400 animate-pulse uppercase tracking-[0.3em]"
          >
            Sincronizando con el servidor de F&C...
          </p>
        </div>

        <iframe
          v-if="externalUrl"
          :src="externalUrl"
          class="w-full h-full border-none"
          @load="handleIframeLoad"
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
          allowfullscreen
        ></iframe>
      </div>

      <div
        class="px-8 py-3 bg-mono-blanco border-t border-slate-100 flex justify-between items-center"
      >
        <p class="text-[12px] font-bold text-slate-300">Modo Espejo: {{ externalUrl }}</p>
        <img src="/images/buho_fyc.png" class="h-6 opacity-20 grayscale" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.custom-scroll::-webkit-scrollbar {
  width: 4px;
}
.custom-scroll::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 10px;
}

iframe {
  background-color: monobg-mono-blanco;
  transition: opacity 0.5s ease;
}

.animate-in {
  animation: modal-in 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes modal-in {
  from {
    opacity: 0;
    transform: scale(0.95) translateY(10px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}
</style>
