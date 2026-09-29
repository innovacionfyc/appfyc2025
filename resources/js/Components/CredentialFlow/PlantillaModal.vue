<script setup>
import { watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { X, LayoutTemplate } from "lucide-vue-next";
import FormInput from "@/Components/Shared/inputs/FormInput.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";
import PdfDropzone from "@/Components/CredentialFlow/PdfDropzone.vue";

const props = defineProps({
  show: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "success"]);

const COLOR = "#942934";

const form = useForm({
  nombre: "",
  descripcion: "",
  pdf: null,
});

// Al abrir, siempre se parte de un formulario limpio
watch(
  () => props.show,
  (show) => {
    if (show) {
      form.reset();
      form.clearErrors();
    }
  }
);

const cerrar = () => {
  if (form.processing) return;
  emit("close");
};

const submit = () => {
  if (form.processing) return;

  form.post(route("credential-flow.plantillas.store"), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      emit("success");
      emit("close");
    },
  });
};
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      leave-active-class="transition duration-150 ease-in"
      leave-to-class="opacity-0"
    >
      <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" @click="cerrar" />

        <Transition
          enter-active-class="transition duration-200 ease-out"
          enter-from-class="opacity-0 scale-95 translate-y-2"
          enter-to-class="opacity-100 scale-100 translate-y-0"
          leave-active-class="transition duration-150 ease-in"
          leave-from-class="opacity-100 scale-100 translate-y-0"
          leave-to-class="opacity-0 scale-95 translate-y-2"
          appear
        >
          <div
            v-if="show"
            role="dialog"
            aria-modal="true"
            aria-labelledby="cf-plantilla-titulo"
            class="relative z-50 w-full max-w-xl bg-white rounded-[2rem] sm:rounded-[2.5rem] shadow-2xl overflow-hidden flex flex-col max-h-[92dvh]"
          >
            <div class="px-6 sm:px-8 py-5 sm:py-6 border-b border-slate-100 flex items-center justify-between shrink-0">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-gradient-to-tr to-primary-vinotinto from-secondary-vinotinto2 rounded-2xl flex items-center justify-center shadow-md">
                  <LayoutTemplate class="w-5 h-5 text-white" />
                </div>
                <div>
                  <h3 id="cf-plantilla-titulo" class="text-lg sm:text-xl font-black text-slate-900">Nueva plantilla</h3>
                  <p class="text-xs font-medium text-slate-400">Credential Flow</p>
                </div>
              </div>
              <button
                type="button"
                class="w-9 h-9 flex items-center justify-center rounded-2xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-all"
                aria-label="Cerrar"
                :disabled="form.processing"
                @click="cerrar"
              >
                <X class="w-5 h-5" />
              </button>
            </div>

            <form class="flex flex-col min-h-0" @submit.prevent="submit">
              <div class="flex-grow overflow-y-auto px-6 sm:px-8 py-6 space-y-5">
                <FormInput
                  v-model="form.nombre"
                  label="Nombre"
                  type="text"
                  icon="badge"
                  placeholder="Ej: Certificado de asistencia 2026"
                  :max="200"
                  :required="true"
                  :activeColor="COLOR"
                  :error="form.errors.nombre"
                  @clearError="form.clearErrors('nombre')"
                />

                <FormInput
                  v-model="form.descripcion"
                  label="Descripción (opcional)"
                  type="textarea"
                  icon="notes"
                  placeholder="Para qué eventos se usa esta plantilla..."
                  :max="1000"
                  :rows="3"
                  :activeColor="COLOR"
                  :error="form.errors.descripcion"
                  @clearError="form.clearErrors('descripcion')"
                />

                <PdfDropzone
                  v-model="form.pdf"
                  :error="form.errors.pdf"
                  :disabled="form.processing"
                  @clearError="form.clearErrors('pdf')"
                />

                <p v-if="form.errors.general" class="text-[13px] text-red-600 font-medium bg-red-50 rounded-xl p-3">
                  {{ form.errors.general }}
                </p>
              </div>

              <div class="px-6 sm:px-8 py-4 sm:py-5 border-t border-slate-100 bg-slate-50/50 flex items-center justify-between gap-4 shrink-0">
                <button
                  type="button"
                  :disabled="form.processing"
                  class="text-sm font-bold text-slate-500 hover:text-slate-800 transition-colors px-4 py-2 rounded-xl hover:bg-slate-100 disabled:opacity-50"
                  @click="cerrar"
                >
                  Cancelar
                </button>
                <BtnUniversal
                  type="submit"
                  label="Crear plantilla"
                  icon="save"
                  icon-position="right"
                  size="md"
                  :activeColor="COLOR"
                  :loading="form.processing"
                  :disabled="form.processing"
                  process="Guardando..."
                  class="sm:w-auto"
                />
              </div>
            </form>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>
