<script setup>
import { ref, computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { UserPlus, UserCog, X, Camera, Mail, Briefcase } from "lucide-vue-next";
import FormInput from "../Shared/inputs/FormInput.vue";
import BtnSecundario from "../Shared/buttons/btnSecundario.vue";
import BtnUniversal from "../BtnUniversal.vue";

const props = defineProps({
  show: { type: Boolean, default: false },
  areas: { type: Array, default: () => [] },
  speaker: { type: Object, default: null },
});

const emit = defineEmits(["close", "success"]);

const currentStep = ref(1);
const totalSteps = 3;

const fotoPreview = ref(null);

const isEditing = computed(() => !!props.speaker);

const form = useForm({
  primer_nombre: "",
  segundo_nombre: "",
  primer_apellido: "",
  segundo_apellido: "",
  telefono: "",
  correo: "",
  areas_encargadas: [],
  biografia: "",
  foto: null,
  url_hv: null,
  _method: "post",
});

watch(
  () => props.show,
  (isOpen) => {
    if (isOpen) {
      if (isEditing.value) {
        form.primer_nombre = props.speaker.primer_nombre || "";
        form.segundo_nombre = props.speaker.segundo_nombre || "";
        form.primer_apellido = props.speaker.primer_apellido || "";
        form.segundo_apellido = props.speaker.segundo_apellido || "";
        form.telefono = props.speaker.telefono || "";
        form.correo = props.speaker.correo || "";
        let areas = props.speaker.areas_encargadas;

        if (typeof areas === "string") {
          try {
            areas = JSON.parse(areas);
          } catch (e) {
            areas = [];
          }
        }

        form.areas_encargadas = Array.isArray(areas) ? areas.map((id) => Number(id)) : [];
        form.biografia = props.speaker.biografia || "";
        form.url_hv = props.speaker.url_hv || "";

        if (props.speaker.foto) {
          fotoPreview.value = "/storage/" + props.speaker.foto;
        } else {
          fotoPreview.value = null;
        }

        form._method = "put";
      } else {
        form.reset();
        form._method = "post";
        fotoPreview.value = null;
      }
      currentStep.value = 1;
      form.clearErrors();
    }
  }
);

const closeModal = () => {
  if (form.isDirty) {
    const confirmacion = confirm(
      "Tienes cambios sin guardar. ¿Estás seguro de que quieres salir?"
    );
    if (!confirmacion) return;
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

const nextStep = () => {
  if (currentStep.value < totalSteps) currentStep.value++;
};

const prevStep = () => {
  if (currentStep.value > 1) currentStep.value--;
};

const handleFotoUpload = (event) => {
  const file = event.target.files[0];
  if (!file) return;
  form.foto = file;
  fotoPreview.value = URL.createObjectURL(file);
};

const submit = () => {
  const routeName = isEditing.value ? "conferencistas.update" : "conferencistas.store";
  const routeParams = isEditing.value ? props.speaker.id : undefined;

  form.post(route(routeName, routeParams), {
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
  if (currentStep.value === 1) return form.primer_nombre && form.primer_apellido;

  if (currentStep.value === 2)
    return form.correo && form.telefono && form.areas_encargadas;

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
                  class="w-12 h-12 bg-primary-naranja text-white rounded-2xl flex items-center justify-center shadow-lg shadow-orange-200/50"
                >
                  <UserCog v-if="isEditing" class="w-6 h-6 text-white" />
                  <UserPlus v-else class="w-6 h-6 text-white" />
                </div>
                <div>
                  <h2 class="text-2xl font-black text-slate-900 tracking-tight">
                    {{ isEditing ? "Editar Conferencista" : "Nuevo Conferencista" }}
                  </h2>
                  <p class="text-slate-500 text-sm font-medium mt-1">
                    Paso {{ currentStep }} de {{ totalSteps }}
                  </p>
                </div>
              </div>
              <button
                @click="closeModal"
                class="p-2.5 hover:bg-slate-100 rounded-full transition-colors text-slate-400"
              >
                <X class="w-5 h-5" />
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
                  step <= currentStep ? 'bg-primary-naranja w-full' : 'bg-transparent w-1'
                "
              ></div>
            </div>
          </div>

          <div class="p-10 bg-slate-50/30 overflow-y-auto max-h-[60vh] custom-scrollbar">
            <form @submit.prevent="submit" id="speakerForm">
              <transition name="fade-step" mode="out-in">
                <div v-if="currentStep === 1" class="space-y-8">
                  <div class="flex flex-col items-center">
                    <div class="relative group cursor-pointer">
                      <div
                        class="w-32 h-32 rounded-[2rem] border-4 border-white shadow-xl bg-orange-50 flex items-center justify-center overflow-hidden transition-transform group-hover:scale-105"
                      >
                        <img
                          v-if="fotoPreview"
                          :src="fotoPreview"
                          class="w-full h-full object-cover"
                        />
                        <Camera v-else class="w-10 h-10 text-orange-200" />
                        <label
                          class="absolute inset-0 bg-black/50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer"
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
                      class="mt-4 text-[10px] font-black text-primary-naranja uppercase tracking-widest"
                    >
                      {{
                        isEditing && form.foto === null
                          ? "Cambiar Foto de Perfil"
                          : "Foto de Perfil"
                      }}
                    </p>
                  </div>

                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
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
                    class="bg-white p-6 rounded-[2rem] border border-slate-200/60 shadow-sm space-y-5"
                  >
                    <div
                      class="flex items-center gap-2 text-primary-naranja mb-2 border-b border-slate-50 pb-3"
                    >
                      <Mail class="w-4 h-4" />
                      <span class="text-[10px] font-black uppercase tracking-widest"
                        >Datos de contacto</span
                      >
                    </div>

                    <FormInput
                      label="Correo electrónico"
                      v-model="form.correo"
                      placeholder="Ej: conferencista@fyc.com"
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

                  <div
                    class="bg-white p-6 rounded-[2rem] border border-slate-200/60 shadow-sm space-y-5"
                  >
                    <div
                      class="flex items-center gap-2 text-primary-naranja mb-2 border-b border-slate-50 pb-3"
                    >
                      <Briefcase class="w-4 h-4" />
                      <span class="text-[10px] font-black uppercase tracking-widest"
                        >Asignación Académica</span
                      >
                    </div>

                    <div>
                      <label
                        class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3"
                      >
                        ÁREAS DE EXPERTISE *
                      </label>

                      <div class="flex flex-wrap gap-2">
                        <label
                          v-for="area in areas"
                          :key="area.id"
                          class="cursor-pointer inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[11px] font-bold border transition-all duration-200 select-none"
                          :class="
                            form.areas_encargadas.includes(area.id)
                              ? 'bg-orange-500 text-white border-orange-600 shadow-md shadow-orange-200'
                              : 'bg-slate-50 text-slate-500 border-slate-200 hover:bg-slate-100'
                          "
                        >
                          <input
                            type="checkbox"
                            :value="Number(area.id)"
                            v-model="form.areas_encargadas"
                            class="hidden"
                          />
                          {{ area.nombre }}
                        </label>
                      </div>
                      <p
                        v-if="form.errors.areas_encargadas"
                        class="text-xs text-red-500 mt-2 font-medium"
                      >
                        {{ form.errors.areas_encargadas }}
                      </p>
                    </div>
                  </div>
                </div>

                <div v-else-if="currentStep === 3" class="space-y-6">
                  <div
                    class="bg-white p-6 rounded-[2rem] border border-slate-200/60 shadow-sm space-y-5"
                  >
                    <FormInput
                      label="Biografía Profesional"
                      type="textarea"
                      v-model="form.biografia"
                      :max="500"
                      :rows="6"
                      icon="description"
                      activeColor="#E96510"
                      placeholder="Cuéntanos un poco sobre la trayectoria, estudios y experiencia del experto..."
                      :error="form.errors.biografia"
                      required
                    />

                    <div class="pt-4 border-t border-slate-100">
                      <FormInput
                        label="Url de Hoja de Vida (PDF o Video)"
                        v-model="form.url_hv"
                        placeholder="Ej: Enlace de Google Drive, OneDrive o YouTube"
                        type="text"
                        icon="link"
                        activeColor="#E96510"
                        :max="200"
                        :error="form.errors.url_hv"
                      />
                    </div>
                  </div>
                </div>
              </transition>
            </form>
          </div>

           <div v-if="form.hasErrors" class="px-6 md:px-10 py-4 bg-rose-50 border-t border-rose-100 flex items-start gap-3 shrink-0">
            <div class="p-1.5 bg-rose-100 text-rose-600 rounded-lg shrink-0 mt-0.5">
               <X :size="16" />
            </div>
            <div>
               <p class="text-[10px] font-black text-rose-700 uppercase tracking-widest mb-0.5">
                 No se pudo guardar
               </p>
               <p class="text-xs font-medium text-rose-600">
                 {{ form.errors.error || Object.values(form.errors)[0] }}
               </p>
            </div>
          </div>

          <div
            class="p-6 md:px-10 md:py-6 bg-slate-50/50 border-t border-slate-100 flex items-center justify-between shrink-0"
          >
            <div class="flex-1">
              <BtnSecundario
                v-if="currentStep > 1"
                label="Regresar"
                icon="chevron_left"
                icon-position="left"
                activeColor="#E96510"
                @click="prevStep"
                class="!py-3 !px-5"
              />
            </div>

            <div class="flex-[2] flex justify-end gap-4">
              <BtnUniversal
                v-if="currentStep < totalSteps"
                label="Siguiente Paso"
                icon="chevron_right"
                icon-position="right"
                size="md"
                activeColor="#E96510"
                :disabled="!isStepValid"
                @click="nextStep"
                process="Validando..."
                class="!py-3 !px-6 !rounded-2xl"
              />

              <BtnUniversal
                v-if="currentStep === totalSteps"
                :label="isEditing ? 'Guardar Cambios' : 'Registrar Experto'"
                :icon="isEditing ? 'save' : 'add'"
                icon-position="right"
                size="md"
                activeColor="#E96510"
                :disabled="form.processing || !isStepValid"
                :loading="form.processing"
                :process="isEditing ? 'Guardando...' : 'Registrando...'"
                @click="submit"
                class="!py-3 !px-6 !rounded-2xl"
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
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.fade-step-enter-from {
  opacity: 0;
  transform: translateX(15px);
}
.fade-step-leave-to {
  opacity: 0;
  transform: translateX(-15px);
}

.custom-scrollbar::-webkit-scrollbar {
  width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}

@keyframes slide-up {
  from {
    opacity: 0;
    transform: translateY(30px) scale(0.98);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}
.animate-in {
  animation: slide-up 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
</style>
