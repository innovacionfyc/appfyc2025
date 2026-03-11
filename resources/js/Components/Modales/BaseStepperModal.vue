<script>
export default {
  inheritAttrs: false
}
</script>
<script setup>
import { ref, computed, watch } from "vue";
import { X, ChevronRight, ChevronLeft, AlertCircle } from "lucide-vue-next";
import BtnUniversal from "../BtnUniversal.vue";
import BtnSecundario from "../Shared/buttons/btnSecundario.vue";

const props = defineProps({
  show: Boolean,
  title: String,
  totalSteps: { type: Number, default: 1 },
  icon: [Object, Function],
  activeColor: { type: String, default: "#E96510" },
  isDirty: Boolean,
  isValid: { type: Boolean, default: true }, 
  loading: Boolean,
  errors: { type: Object, default: () => ({}) }, 
  stepFields: { type: Array, default: () => [] },
  submitLabel: { type: String, default: "Guardar" },
  processLabel: { type: String, default: "Procesando..." },
});

const emit = defineEmits(["close", "submit", "step-change"]);
const currentStep = ref(1);

const hasServerErrorsInCurrentStep = computed(() => {
  if (!props.stepFields.length || !props.errors) return false;

  const fieldsInStep = props.stepFields[currentStep.value - 1] || [];

  return fieldsInStep.some((field) => Object.keys(props.errors).includes(field));
});

const canContinue = computed(() => props.isValid && !hasServerErrorsInCurrentStep.value);

const nextStep = () => {
  if (canContinue.value && currentStep.value < props.totalSteps) {
    currentStep.value++;
    emit("step-change", currentStep.value);
  }
};

const prevStep = () => {
  if (currentStep.value > 1) {
    currentStep.value--;
    emit("step-change", currentStep.value);
  }
};

const handleClose = () => {
  if (props.isDirty) {
    if (!confirm("Tienes cambios sin guardar. ¿Estás seguro de que quieres salir?"))
      return;
  }
  emit("close");
};

watch(
  () => props.show,
  (val) => {
    if (!val) setTimeout(() => (currentStep.value = 1), 300);
  }
);
</script>

<template>
  <Teleport to="body">
    <transition
      enter-active-class="transition ease-out duration-300"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition ease-in duration-200"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="show"
        class="fixed inset-0 z-[110] flex items-center justify-center p-4 bg-slate-900/80 backdrop-blur-sm overflow-y-auto"
      >
        <div class="absolute inset-0" @click="handleClose"></div>

        <div
          class="relative bg-white rounded-[2.5rem] shadow-2xl w-full max-w-7xl overflow-hidden flex flex-col animate-in slide-in-from-bottom-4 duration-500"
          @click.stop
        >
          <div class="px-10 pt-8 pb-6 border-b border-slate-100">
            <div class="flex justify-between items-start mb-6">
              <div class="flex items-center gap-4">
                <div
                  class="w-12 h-12 rounded-2xl flex items-center justify-center shadow-lg"
                  :style="{ backgroundColor: activeColor }"
                >
                  <component :is="icon" class="w-6 h-6 text-white" />
                </div>
                <div>
                  <h2 class="text-2xl font-black text-slate-900 tracking-tight">
                    {{ title }}
                  </h2>
                  <p class="text-slate-500 text-sm">
                    Paso {{ currentStep }} de {{ totalSteps }}
                  </p>
                </div>
              </div>
              <button
                @click="handleClose"
                class="p-2 hover:bg-slate-100 rounded-full transition-colors text-slate-400"
              >
                <X class="w-6 h-6" />
              </button>
            </div>

            <div
              class="flex gap-2 h-1.5 w-full bg-slate-100 rounded-full overflow-hidden"
            >
              <div
                v-for="step in totalSteps"
                :key="step"
                class="h-full transition-all duration-500 rounded-full"
                :class="step <= currentStep ? 'w-full' : 'w-1'"
                :style="{
                  backgroundColor: step <= currentStep ? activeColor : 'transparent',
                }"
              ></div>
            </div>
          </div>

          <div class="p-10 bg-slate-50/30 overflow-y-auto max-h-[60vh] custom-scrollbar">
            <div
              v-if="hasServerErrorsInCurrentStep"
              class="mb-6 p-4 bg-red-50 border border-red-100 rounded-2xl flex items-center gap-3 animate-in fade-in zoom-in duration-300"
            >
              <AlertCircle class="w-5 h-5 text-red-500" />
              <p class="text-xs font-bold text-red-700 uppercase tracking-tight">
                Por favor, corrige los campos marcados en rojo antes de continuar.
              </p>
            </div>

            <slot :currentStep="currentStep"></slot>
          </div>

          <div
            class="p-8 md:px-16 md:py-10 bg-white border-t border-slate-50 flex items-center justify-between"
          >
            <div class="flex-1">
              <BtnSecundario
                v-if="currentStep > 1"
                label="Anterior"
                icon="chevron_left"
                @click="prevStep"
              />
            </div>

            <div class="flex-[2] flex justify-end gap-4">
              <BtnUniversal
                v-if="currentStep < totalSteps"
                label="Siguiente"
                icon="chevron_right"
                icon-position="right"
                :activeColor="activeColor"
                :disabled="!canContinue"
                @click="nextStep"
              />
              

              <BtnUniversal
                v-if="currentStep === totalSteps"
                :label="submitLabel"
                icon="check"
                icon-position="right"
                :activeColor="activeColor"
                :disabled="loading || !canContinue"
                :loading="loading"
                :process="processLabel"
                @click="emit('submit')"
              />
            </div>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>
