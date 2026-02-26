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
import BtnUniversal from "../BtnUniversal.vue";
import BtnSecundario from "../Shared/buttons/btnSecundario.vue";

const props = defineProps({
  show: Boolean,
  evento: Object,
});

const emit = defineEmits(["close"]);

const currentStep = ref(1);
const totalSteps = 3;

// Etiquetas y descripciones para el Sidebar de progreso
const steps = [
  { id: 1, title: "Identidad", desc: "Sus datos básicos", icon: User },
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
  medio_reconocimiento_otro: "",
});

const activeColor = computed(
  () => props.evento?.area_formacion?.color_hex_principal || "#f97316"
);

const areaEvento = computed(() => props.evento?.area_formacion?.nombre || "");

const tituloEvento = computed(() => props.evento?.titulo || "");

const subtituloEvento = computed(() => props.evento?.subtitulo || "");

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
    
  if (currentStep.value === 2) 
    return form.correo_corporativo && form.ciudad && form.cargo && form.entidad_empresa;
    
  return form.politica_datos && form.modo_asistencia && form.medio_reconocimiento;
});

const getAreaTagImage = () => {
  const areaName = areaEvento.value;

  const imagenesPorArea = {
    Jurídica: "/images/areasFormacion/form/j_form.png",
    "Talento Humano": "/images/areasFormacion/form/th_form.png",
    "Gestión y Políticas Públicas": "/images/areasFormacion/form/gpp_form.png",
    "Enfoques Misionales": "/images/areasFormacion/form/em_form.png",
    "Finanzas y Hacienda Pública": "/images/areasFormacion/form/fhp_form.png",
  };

  return imagenesPorArea[areaName] || "/images/areasFormacion/formacion_defecto_web.png";
};
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
          class="bg-white w-full max-w-6xl h-auto flex flex-col md:flex-row shadow-[0_40px_100px_-20px_rgba(0,0,0,0.3)] md:rounded-[3rem] overflow-hidden relative"
        >
          <div
            class="hidden md:flex w-[35%] gap-10 flex-col justify-between px-12 py-8 relative overflow-hidden shrink-0"
          >
            <div class="flex justify-center">
              <img
                :src="getAreaTagImage()"
                :alt="areaEvento || 'Área de formación'"
                class="h-20 md:h-28 w-auto object-contain drop-shadow-lg hover:scale-105 transition-transform duration-300"
              />
            </div>

            <div class="relative z-10 space-y-12">
              <div class="space-y-1">
                <h2 class="text-[30px] font-extrabold">Inscripción al evento:</h2>
                <p class="text-[16px] font-regular">
                  {{ subtituloEvento }}
                </p>
                <h1
                  class="text-[18px] leading-[1.1] uppercase font-extrabold"
                  :style="{ color: activeColor }"
                >
                  {{ tituloEvento }}
                </h1>
              </div>

              <div class="space-y-5">
                <div
                  v-for="step in steps"
                  :key="step.id"
                  class="flex items-center gap-4 transition-all duration-500"
                  :class="currentStep === step.id ? 'translate-x-2' : 'opacity-50'"
                >
                  <div
                    class="w-10 h-10 rounded-xl flex items-center justify-center border-2"
                     :style="{ borderBlockColor: currentStep === step.id ? activeColor : '' }"
                  >
                    <component
                      :is="step.icon"
                      class="w-5 h-5"
                      :style="{ color: currentStep >= step.id ? activeColor : '' }"
                    />
                  </div>
                  <div>
                    <h4 class="font-bold text-sm"  :style="{ color: currentStep >= step.id ? activeColor : '' }">{{ step.title }}</h4>
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

          <div class="flex-1 flex flex-col h-auto overflow-hidden">
            <div
              class="px-8 py-6 flex justify-between items-center md:justify-end shrink-0"
            >
              <button
                @click="$emit('close')"
                class="p-3 bg-slate-50 hover:bg-slate-100 rounded-2xl transition-all"
              >
                <X class="w-5 h-5 text-slate-400" />
              </button>
            </div>

            <div class="flex-1 overflow-y-auto px-8 md:px-10 py-4 custom-scroll">
              <div class="max-w-auto mx-auto">
                <form @submit.prevent="submit" class="space-y-8">
                  <div class="">
                    <span
                      class="text-xs font-bold text-slate-400 uppercase tracking-widest"
                      >Paso {{ currentStep }} de 3</span
                    >
                  </div>
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
                        :error="form.errors.nombres"
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
                        :error="form.errors.apellidos"
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
                        :error="form.errors.cedula"
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
                        :error="form.errors.celular"
                      />
                    </div>
                    <FormInput
                        label="Correo electrónico principal"
                        type="email"
                        v-model="form.correo_personal"
                        icon="email"
                        :activeColor="activeColor"
                        placeholder="tu@correo.com"
                      :error="form.errors.correo_personal"
                        :max="60"
                      />
                  </div>

                  <div
                    v-if="currentStep === 2"
                    class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700"
                  >
                    <div class="space-y-2">
                      <h3 class="text-2xl font-bold text-slate-900">
                        Cuéntenos sobre su labor
                      </h3>
                      <p class="text-slate-500 text-sm">
                        Esta información nos ayuda a personalizar la experiencia
                        académica.
                      </p>
                    </div>

                    <div class="grid grid-cols-1 gap-6">
                      <FormInput
                        label="Correo electrónico corporativo"
                        type="email"
                        v-model="form.correo_corporativo"
                        icon="email"
                        :activeColor="activeColor"
                        placeholder="su@dominio-empresa.com"
                        required
                        :max="60"
                        :error="form.errors.correo_corporativo"
                      />
                    </div>
                     <FormInput
                        label="Ciudad"
                        type="text"
                        v-model="form.ciudad"
                        icon="add_location"
                        :activeColor="activeColor"
                        placeholder="¿Dónde reside?"
                        required
                        :max="60"
                        :error="form.errors.ciudad"
                      />
                    <div class="grid md:grid-cols-2 grid-cols-1 gap-6">
                      <FormInput
                        label="Entidad / Empresa"
                        type="text"
                        v-model="form.entidad_empresa"
                        icon="store"
                        :activeColor="activeColor"
                        placeholder="Ingrese donde labora"
                        required
                        :max="60"
                        :error="form.errors.entidad_empresa"
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
                        :error="form.errors.cargo"
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
                        Confirme los detalles finales de su participación.
                      </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                      <FormInput
                        label="Modo de Asistencia"
                        type="select"
                        v-model="form.modo_asistencia"
                        :error="form.errors.modo_asistencia"
                        icon="category"
                        :activeColor="activeColor"
                        :options="['Presencial', 'Virtual', 'Híbrido']"
                        required
                      />

                      <FormInput
                        label="¿Cómo no conoció?"
                        type="select"
                        v-model="form.medio_reconocimiento"
                        icon="globe_book"
                        :activeColor="activeColor"
                        :options="[
                          'Correo Eléctronico',
                          'WhatsApp',
                          'Redes Sociales',
                          'Llamada por ejecutivo de cuenta',
                          'Otro',
                        ]"
                        required
                        :error="form.errors.medio_reconocimiento"
                      />

                      <FormInput
                        v-if="form.medio_reconocimiento === 'Otro'"
                        label="¿Cual?"
                        type="text"
                        v-model="form.medio_reconocimiento_otro"
                        icon="info"
                        :activeColor="activeColor"
                        placeholder="Ej: Consultor"
                   
                        :max="50"
                        :error="form.errors.medio_reconocimiento_otro"
                      />
                    </div>

                    <div class="my-10">
                      <label class="flex items-start gap-4 cursor-pointer group">
                        <input
                          v-model="form.politica_datos"
                          type="checkbox"
                          class="mt-1 w-6 h-6 rounded-lg transition-all cursor-pointer accent-slate-900"
                        />
                        <span class="text-xs text-slate-600 leading-relaxed">
                          Acepto la
                          <strong class="text-slate-900 underline"
                            >vinculación del número celular aquí registrado</strong
                          >
                          al grupo de WhatsApp que tendrá como única finalidad socializar
                          toda la información relacionada con el evento, lo que incluye
                          programación, recordatorios, capacitaciones y
                          <strong class="text-slate-900 underline">
                            demás comunicaciones pertinentes.
                          </strong>
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
                  size="lg"
                  :activeColor="activeColor"
                  :disabled="!isStepValid"
                  @click="nextStep"
                  process="Esperando campos..."

                />

                <BtnUniversal
                  v-if="currentStep === totalSteps"
                  label="Confirmar Cupo"
                  icon="send"
                  icon-position="right"
                  size="lg"
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
