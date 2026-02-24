<script setup>
import { ref, computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import {
  X,
  Loader2,
  Send,
  User,
  Building2,
  Mail,
  Phone,
  MapPin,
  ChevronRight,
  ChevronLeft,
  Check,
  ShieldCheck,
  Sparkles,
  Smartphone,
} from "lucide-vue-next";
import FormInput from "../Shared/inputs/FormInput.vue";

const props = defineProps({
  show: Boolean,
  evento: Object,
});

const emit = defineEmits(["close"]);

const currentStep = ref(1);
const totalSteps = 3;

// Etiquetas y descripciones para el Sidebar de progreso
const steps = [
  { id: 1, title: "Identidad", desc: "Tus datos básicos", icon: User },
  { id: 2, title: "Perfil", desc: "Entorno profesional", icon: Building2 },
  { id: 3, title: "Finalizar", desc: "Preferencias de cupo", icon: Sparkles },
];

const form = useForm({
  evento_id: props.evento?.id,
  tipo_persona: "Natural",
  nombres: "",
  apellidos: "",
  cedula: "",
  cargo: "",
  entidad_empresa: "",
  celular: "",
  ciudad: "",
  correo_personal: "",
  correo_corporativo: "",
  modo_asistencia: props.evento?.modalidad || "Presencial",
  soporte_asistencia: "",
  politica_datos: false,
  medio_reconocimiento: "",
});

const activeColor = computed(
  () => props.evento?.area_formacion?.color_hex_principal || "#f97316"
);

const nextStep = () => {
  if (currentStep.value < totalSteps) currentStep.value++;
};
const prevStep = () => {
  if (currentStep.value > 1) currentStep.value--;
};

const submit = () => {
  form.post(route("inscripciones.store"), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      currentStep.value = 1;
      emit("close");
    },
  });
};

const isStepValid = computed(() => {
  if (currentStep.value === 1)
    return form.nombres && form.apellidos && form.cedula && form.celular;
  if (currentStep.value === 2) return form.correo_personal && form.ciudad;
  return form.politica_datos;
});
</script>

<template>
  <Teleport to="body">
    <transition
      enter-active-class="duration-500 ease-out"
      enter-from-class="opacity-0 scale-95"
      enter-to-class="opacity-100 scale-100"
      leave-active-class="duration-300 ease-in"
      leave-from-class="opacity-100 scale-100"
      leave-to-class="opacity-0 scale-95"
    >
      <div
        v-if="show"
        class="fixed inset-0 z-[100] flex items-center justify-center p-0 md:p-6 bg-slate-950/40 backdrop-blur-xl"
      >
        <div
          class="bg-white w-full max-w-5xl md:h-[700px] flex flex-col md:flex-row shadow-[0_40px_100px_-20px_rgba(0,0,0,0.3)] md:rounded-[3rem] overflow-hidden relative"
        >
          <div
            class="hidden md:flex w-80 flex-col justify-between p-12 relative overflow-hidden shrink-0 text-white"
            :style="{ background: activeColor }"
          >
            <div class="absolute top-0 left-0 w-full h-full opacity-10">
              <svg width="100%" height="100%">
                <pattern
                  id="pattern"
                  x="0"
                  y="0"
                  width="40"
                  height="40"
                  patternUnits="userSpaceOnUse"
                >
                  <circle cx="2" cy="2" r="1" fill="currentColor" />
                </pattern>
                <rect width="100%" height="100%" fill="url(#pattern)" />
              </svg>
            </div>

            <div class="relative z-10 space-y-12">
              <div class="space-y-2">
                <h2 class="text-3xl font-black leading-tight">
                  Únete a la <br />Jornada
                </h2>
                <p class="text-white/70 text-sm font-medium">
                  Completa estos tres pasos para asegurar tu lugar en el evento.
                </p>
              </div>

              <div class="space-y-8">
                <div
                  v-for="step in steps"
                  :key="step.id"
                  class="flex items-center gap-4 transition-all duration-500"
                  :class="currentStep === step.id ? 'translate-x-2' : 'opacity-50'"
                >
                  <div
                    class="w-10 h-10 rounded-2xl flex items-center justify-center border-2"
                    :class="
                      currentStep >= step.id ? 'bg-white border-white' : 'border-white/30'
                    "
                  >
                    <component
                      :is="step.icon"
                      class="w-5 h-5"
                      :style="{ color: currentStep >= step.id ? activeColor : '#fff' }"
                    />
                  </div>
                  <div>
                    <h4 class="font-bold text-sm">{{ step.title }}</h4>
                    <p class="text-[11px] font-medium opacity-80">{{ step.desc }}</p>
                  </div>
                </div>
              </div>
            </div>

            <div
              class="relative z-10 flex items-center gap-2 text-xs font-bold bg-black/10 w-fit px-4 py-2 rounded-full border border-white/10"
            >
              <ShieldCheck class="w-4 h-4" /> Registro Seguro
            </div>
          </div>

          <div class="flex-1 flex flex-col bg-white h-full overflow-hidden">
            <div
              class="px-8 py-6 flex justify-between items-center md:justify-end shrink-0"
            >
              <div class="md:hidden">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-widest"
                  >Paso {{ currentStep }} de 3</span
                >
              </div>
              <button
                @click="$emit('close')"
                class="p-3 bg-slate-50 hover:bg-slate-100 rounded-2xl transition-all"
              >
                <X class="w-5 h-5 text-slate-400" />
              </button>
            </div>

            <div class="flex-1 overflow-y-auto px-8 md:px-16 py-4 custom-scroll">
              <div class="max-w-xl mx-auto">
                <form @submit.prevent="submit" class="space-y-8">
                  <div
                    v-if="currentStep === 1"
                    class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700"
                  >
                    <div class="space-y-2">
                      <h3 class="text-2xl font-bold text-slate-900">
                        Empecemos con lo básico
                      </h3>
                      <p class="text-slate-500 text-sm">
                        Ingresa tus datos tal como quieres que aparezcan en tu
                        certificado.
                      </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                      <FormInput
                        label="Nombres"
                        type="text"
                        v-model="form.nombres"
                        icon="format_italic"
                        :activeColor="activeColor"
                        placeholder="Ej: Juan Andrés"
                        required
                        :max="30"
                      />
                      <FormInput
                        label="Apellidos"
                        type="text"
                        v-model="form.apellidos"
                        icon="format_italic"
                        :activeColor="activeColor"
                        placeholder="Ej: Gómez Polanco"
                        required
                        :max="30"
                      />

                      <FormInput
                        label="Documento de identidad"
                        type="number"
                        v-model="form.cedula"
                        icon="pin"
                        :activeColor="activeColor"
                        placeholder="Cédula o NIT"
                        required
                        :max="24"
                      />
                      <FormInput
                        label="Número de celular"
                        type="number"
                        v-model="form.celular"
                        icon="phone"
                        :activeColor="activeColor"
                        placeholder="3XX XXX XXXX"
                        required
                        :max="10"
                      />
                    </div>
                  </div>

                  <div
                    v-if="currentStep === 2"
                    class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700"
                  >
                    <div class="space-y-2">
                      <h3 class="text-2xl font-bold text-slate-900">
                        Cuéntanos sobre tu labor
                      </h3>
                      <p class="text-slate-500 text-sm">
                        Esta información nos ayuda a personalizar la experiencia
                        académica.
                      </p>
                    </div>

                    <div class="grid grid-cols-1 gap-6">
                      <FormInput
                        label="Correo electrónico principal"
                        type="email"
                        v-model="form.correo_personal"
                        icon="email"
                        :activeColor="activeColor"
                        placeholder="tu@correo.com"
                        required
                        :max="60"
                      />
                    </div>
                    <div class="grid md:grid-cols-2 grid-cols-1 gap-6">
                      <FormInput
                        label="Ciudad"
                        type="text"
                        v-model="form.ciudad"
                        icon="add_location"
                        :activeColor="activeColor"
                        placeholder="¿Dónde resides?"
                        required
                        :max="60"
                      />

                      <FormInput
                        label="Cargo / Puesto"
                        type="text"
                        v-model="form.cargo"
                        icon="id_card"
                        :activeColor="activeColor"
                        placeholder="Ej: Consultor"
                        required
                        :max="50"
                      />
                    </div>
                  </div>

                  <div
                    v-if="currentStep === 3"
                    class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700"
                  >
                    <div class="space-y-2">
                      <h3 class="text-2xl font-bold text-slate-900">¡Casi terminamos!</h3>
                      <p class="text-slate-500 text-sm">
                        Confirma los detalles finales de tu participación.
                      </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                     
                      <FormInput
                        label="Modo de Asistencia"
                        type="select"
                        v-model="form.modo_asistencia"
                        icon="category"
                        :activeColor="activeColor"
                        :options="['Presencial', 'Virtual', 'Híbrido']"
                        required
                      />
                     
                      <div class="space-y-2">
                        <label class="text-xs font-bold text-slate-400 uppercase ml-1"
                          >¿Cómo nos conociste?</label
                        >
                        <select
                          v-model="form.medio_reconocimiento"
                          class="custom-input appearance-none cursor-pointer"
                          :style="{ '--focus-color': activeColor }"
                        >
                          <option value="">Selecciona una opción</option>
                          <option value="WhatsApp">WhatsApp</option>
                          <option value="Redes Sociales">Redes Sociales</option>
                          <option value="Correo">Correo Electrónico</option>
                        </select>
                      </div>
                    </div>

                    <div
                      class="p-8 bg-slate-50 rounded-[2.5rem] border border-slate-100 mt-10"
                    >
                      <label class="flex items-start gap-4 cursor-pointer group">
                        <input
                          v-model="form.politica_datos"
                          type="checkbox"
                          class="mt-1 w-6 h-6 rounded-lg transition-all cursor-pointer accent-slate-900"
                        />
                        <span class="text-xs text-slate-600 leading-relaxed">
                          Acepto la
                          <strong class="text-slate-900 underline"
                            >Política de Tratamiento de Datos Personales</strong
                          >
                          de F&C Consultores para efectos de registro y comunicaciones
                          académicas.
                        </span>
                      </label>
                    </div>
                  </div>
                </form>
              </div>
            </div>

            <div
              class="p-8 md:px-16 md:py-10 bg-white border-t border-slate-50 flex items-center justify-between shrink-0"
            >
              <button
                v-if="currentStep > 1"
                @click="prevStep"
                class="flex items-center gap-2 font-bold text-slate-400 hover:text-slate-900 transition-colors"
              >
                <ChevronLeft class="w-5 h-5" /> Anterior
              </button>
              <div v-else></div>

              <div class="flex gap-4">
                <button
                  v-if="currentStep < totalSteps"
                  @click="nextStep"
                  :disabled="!isStepValid"
                  class="px-10 py-4 rounded-2xl font-bold text-white transition-all shadow-xl disabled:opacity-30 disabled:grayscale flex items-center gap-3 active:scale-95"
                  :style="{
                    background: activeColor,
                    boxShadow: `0 20px 40px -10px ${activeColor}40`,
                  }"
                >
                  Siguiente <ChevronRight class="w-5 h-5" />
                </button>

                <button
                  v-if="currentStep === totalSteps"
                  @click="submit"
                  :disabled="form.processing || !isStepValid"
                  class="px-10 py-4 rounded-2xl font-bold text-white transition-all shadow-xl disabled:opacity-30 flex items-center gap-3 active:scale-95"
                  :style="{
                    background: activeColor,
                    boxShadow: `0 20px 40px -10px ${activeColor}40`,
                  }"
                >
                  <Loader2 v-if="form.processing" class="w-5 h-5 animate-spin" />
                  <span v-else class="flex items-center gap-3"
                    ><Send class="w-5 h-5" /> Confirmar Cupo</span
                  >
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<style scoped>
.custom-input {
  @apply w-full px-6 py-4 bg-slate-50 border-2 border-transparent rounded-2xl outline-none font-semibold transition-all duration-300;
}
.custom-input:focus {
  @apply bg-white shadow-xl;
  border-color: var(--focus-color);
}
.custom-input::placeholder {
  @apply text-slate-300 font-medium;
}

.custom-scroll::-webkit-scrollbar {
  width: 5px;
}
.custom-scroll::-webkit-scrollbar-thumb {
  background: #f1f5f9;
  border-radius: 10px;
}
</style>
