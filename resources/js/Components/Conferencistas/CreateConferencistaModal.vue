<script setup>
import { ref, computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import {
  UserPlus,
  X,
  Loader2,
  Save,
  FileText,
  Camera,
  Phone,
  Mail,
  AlertCircle,
  ChevronRight,
  ChevronLeft,
  Briefcase,
  CheckCircle2,
} from "lucide-vue-next";
import FormInput from "../Shared/inputs/FormInput.vue";
import BtnSecundario from "../Shared/buttons/btnSecundario.vue";
import BtnUniversal from "../BtnUniversal.vue";

const props = defineProps({
  show: { type: Boolean, default: false },
  areas: { type: Array, default: () => [] },
});

const emit = defineEmits(["close", "success"]);

// --- ESTADO DE PASOS ---
const currentStep = ref(1);
const totalSteps = 3;

const fotoPreview = ref(null);

const form = useForm({
  primer_nombre: "",
  segundo_nombre: "",
  primer_apellido: "",
  segundo_apellido: "",
  telefono: "",
  correo: "",
  area_encargada_id: "",
  biografia: "",
  foto: null,
  url_hv: null,
});

// --- LÓGICA DE CIERRE SEGURO ---
const closeModal = () => {
  if (form.isDirty) {
    const confirmación = confirm(
      "Tienes cambios sin guardar. ¿Estás seguro de que quieres salir?"
    );
    if (!confirmación) return;
  }
  forceClose();
};

const forceClose = () => {
  emit("close");
  setTimeout(() => {
    form.reset();
    form.clearErrors();
    fotoPreview.value = null;
    currentStep.value = 1;
  }, 300);
};

// --- GESTIÓN DE PASOS ---
const nextStep = () => {
  if (currentStep.value < totalSteps) currentStep.value++;
};

const prevStep = () => {
  if (currentStep.value > 1) currentStep.value--;
};

// --- ARCHIVOS ---
const handleFotoUpload = (event) => {
  const file = event.target.files[0];
  if (!file) return;
  form.foto = file;
  fotoPreview.value = URL.createObjectURL(file);
};

const submit = () => {
  form.post(route("conferencistas.store"), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => {
      forceClose();
      form.reset();
      emit("success");
    },
  });
};

const isStepValid = computed(() => {
  if (currentStep.value === 1)

    return form.primer_nombre && form.primer_apellido;
    
  if (currentStep.value === 2) 
    return form.correo && form.telefono && form.area_encargada_id;
    
  return form.biografia;
});
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
        <div class="absolute inset-0" @click="closeModal"></div>

        <div
          class="relative bg-white rounded-[2.5rem] shadow-2xl w-full max-w-2xl overflow-hidden flex flex-col animate-in slide-in-from-bottom-4 duration-500"
          @click.stop
        >
          <div class="px-10 pt-8 pb-6 border-b border-slate-100">
            <div class="flex justify-between items-start mb-6">
              <div class="flex items-center gap-4">
                <div
                  class="w-12 h-12 bg-primary-naranja text-primary-naranja rounded-2xl flex items-center justify-center shadow-lg shadow-indigo-200"
                >
                  <UserPlus class="w-6 h-6 text-white" />
                </div>
                <div>
                  <h2 class="text-2xl font-black text-slate-900 tracking-tight">
                    Nuevo Conferencista
                  </h2>
                  <p class="text-slate-500 text-sm">
                    Paso {{ currentStep }} de {{ totalSteps }}
                  </p>
                </div>
              </div>
              <button
                @click="closeModal"
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
                :class="
                  step <= currentStep
                    ? 'bg-primary-naranja text-primary-naranja w-full'
                    : 'bg-transparent w-1'
                "
              ></div>
            </div>
          </div>

          <div class="p-10 bg-slate-50/30 overflow-y-auto max-h-[60vh] custom-scrollbar">
            <form @submit.prevent="submit" id="speakerForm">
              <transition name="fade-step" mode="out-in">
                <div v-if="currentStep === 1" class="space-y-8">
                  <div class="flex flex-col items-center">
                    <div class="relative group">
                      <div
                        class="w-32 h-32 rounded-full border-4 border-white shadow-xl bg-indigo-50 flex items-center justify-center overflow-hidden"
                      >
                        <img
                          v-if="fotoPreview"
                          :src="fotoPreview"
                          class="w-full h-full object-cover"
                        />
                        <Camera v-else class="w-10 h-10 text-indigo-200" />
                        <label
                          class="absolute inset-0 bg-black/40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer"
                        >
                          <Camera class="w-8 h-8 text-white" />
                          <input
                            type="file"
                            @change="handleFotoUpload"
                            accept="image/*"
                            class="hidden"
                          />
                        </label>
                      </div>
                    </div>
                    <p
                      class="mt-3 text-xs font-bold text-primary-naranja uppercase tracking-widest"
                    >
                      Foto de Perfil
                    </p>
                  </div>

                  <div class="grid grid-cols-2 gap-4">
                    <FormInput
                      label="Primer nombre"
                      type="text"
                      v-model="form.primer_nombre"
                      icon="format_italic"
                      activeColor="#E96510"
                      placeholder="Ej: Juan"
                      required
                      :max="30"
                      :error="form.errors.primer_nombre"
                    />
                    <FormInput
                      label="Segundo nombre"
                      v-model="form.segundo_nombre"
                      placeholder="Opcional"
                      type="text"
                      icon="format_italic"
                      activeColor="#E96510"
                      :max="30"
                      :error="form.errors.segundo_nombre"
                    />
                    <FormInput
                      label="Primer apellido"
                      v-model="form.primer_apellido"
                      placeholder="Ej: Martínez"
                      type="text"
                      icon="format_italic"
                      activeColor="#E96510"
                      :max="30"
                      :error="form.errors.primer_apellido"
                      required
                    />

                    <FormInput
                      label="Segundo apellido"
                      v-model="form.segundo_apellido"
                      placeholder="Opcional"
                      type="text"
                      icon="format_italic"
                      activeColor="#E96510"
                      :max="30"
                      :error="form.errors.segundo_apellido"
                    />
                  </div>
                </div>

                <div v-else-if="currentStep === 2" class="space-y-6">
                  <div
                    class="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4"
                  >
                    <div class="flex items-center gap-2 text-primary-naranja mb-2">
                      <Mail class="w-4 h-4" />
                      <span class="text-xs font-bold uppercase">Datos de contacto</span>
                    </div>

                    <FormInput
                      label="Correo electrónico"
                      v-model="form.correo"
                      placeholder="Ej: tucorreo@fyc.com"
                      type="email"
                      icon="email"
                      activeColor="#E96510"
                      :max="60"
                      :error="form.errors.correo"
                      required
                    />

                    <FormInput
                      label="Teléfono"
                      v-model="form.telefono"
                      placeholder="Ej: 300 000 0000"
                      type="number"
                      icon="phone"
                      activeColor="#E96510"
                      :max="10"
                      :error="form.errors.telefono"
                      required
                    />
                  </div>

                  <div class="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm">
                    <div class="flex items-center gap-2 text-primary-naranja mb-4">
                      <Briefcase class="w-4 h-4" />
                      <span class="text-xs font-bold uppercase"
                        >Asignación Académica</span
                      >
                    </div>
                    <label class="block text-xs font-bold text-slate-500 mb-2"
                      >ÁREA ENCARGADA *</label
                    >
                    <FormInput
                      label="Área encargada"
                      type="select"
                      v-model="form.area_encargada_id"
                      :error="form.errors.area_encargada_id"
                      icon="category"
                      activeColor="#E96510"
                      :options="areas"
                      required
                    />
                  </div>
                </div>

                <div v-else-if="currentStep === 3" class="space-y-6">
                  

                  <FormInput
                    label="Biografía Profesional"
                    type="textarea"
                    v-model="form.biografia"
                    :max="500"
                    :rows="10"
                    icon="description"
                    activeColor="#E96510"
                    placeholder="Cuéntanos un poco sobre la trayectoria del conferencista..."
                    :error="form.errors.biografia"
                    required
                  />

                  <FormInput
                    label="Url de hv en video"
                    v-model="form.url_hv"
                    placeholder="Ej: Copie el archivo de Drive o donde lo tenga alojado."
                    type="text"
                    icon="link"
                    activeColor="#E96510"
                    :max="200"
                    :error="form.errors.url_hv"
                  />
                </div>
              </transition>
            </form>
          </div>

         <div
              class="p-8 md:px-16 md:py-10 bg-white border-t border-slate-50 flex items-center justify-between shrink-0"
            >
              <div class="flex-1">
                <BtnSecundario
                  v-if="currentStep > 1"
                  label="Anterior"
                  icon="chevron_left"
                  icon-position="left"
                  :activeColor="activeColor"
                  @click="prevStep"
                />
              </div>

              <div class="flex-[2] flex justify-end gap-4">
                <BtnUniversal
                  v-if="currentStep < totalSteps"
                  label="Siguiente"
                  icon="chevron_right"
                  icon-position="right"
                  size="md"
                  :activeColor="activeColor"
                  :disabled="!isStepValid"
                  @click="nextStep"
                  process="Esperando campos..."

                />

                <BtnUniversal
                  v-if="currentStep === totalSteps"
                  label="Añadir conferencista"
                  icon="add"
                  icon-position="right"
                  size="md"
                  :activeColor="activeColor"
                  :disabled="form.processing || !isStepValid"
                  :loading="form.processing"
                  process="Inscribiendo..."
                  @click="submit"
                />
              </div>
            </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<style scoped>
.fade-step-enter-active,
.fade-step-leave-active {
  transition: all 0.3s ease;
}
.fade-step-enter-from {
  opacity: 0;
  transform: translateX(20px);
}
.fade-step-leave-to {
  opacity: 0;
  transform: translateX(-20px);
}

.custom-scrollbar::-webkit-scrollbar {
  width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: #e2e8f0;
  border-radius: 10px;
}

@keyframes slide-up {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
.animate-in {
  animation: slide-up 0.5s cubic-bezier(0.16, 1, 0.3, 1);
}
</style>
