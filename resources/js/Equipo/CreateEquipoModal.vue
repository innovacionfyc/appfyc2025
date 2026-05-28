<script setup>
import { ref, computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import {
  UserPlus,
  UserCog,
  X,
  Camera,
  Mail,
  Briefcase,
  IdCard,
  ShieldCheck,
  Lock,
} from "lucide-vue-next";
import FormInput from "@/Components/Shared/inputs/FormInput.vue";
import BtnSecundario from "@/Components/Shared/buttons/btnSecundario.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";

const props = defineProps({
  show: { type: Boolean, default: false },
  areas: { type: Array, default: () => [] },
  roles: { type: Array, default: () => [] },
  equipos: { type: Array, default: () => [] },
  tiposDocumento: { type: Array, default: () => [] },
  member: { type: Object, default: null }, // Recibe el miembro a editar
});

const emit = defineEmits(["close", "success"]);

const currentStep = ref(1);
const totalSteps = 3;
const fotoPreview = ref(null);

const isEditing = computed(() => !!props.member);

const form = useForm({
  // Datos Perfil
  primer_nombre: "",
  segundo_nombre: "",
  primer_apellido: "",
  segundo_apellido: "",
  tipo_documento_id: "",
  numero_documento: "",
  foto: null,

  // Datos Contacto
  telefono_personal: "",
  telefono_corporativo: "",
  correo_corporativo: "",
  correo_principal: "", // Va a la tabla usuarios

  // Datos Corporativos
  cargo: "",
  rol_id: "",
  equipo_id: "",
  area_encargada_id: "",

  // Datos de Acceso (Usuario)
  estado_id: 1, // Por defecto Activo
  contrasena: "", // Solo requerida al crear

  _method: "post",
});

watch(
  () => props.show,
  (isOpen) => {
    if (isOpen) {
      if (isEditing.value) {
        // Llenar datos de edición
        form.primer_nombre = props.member.primer_nombre || "";
        form.segundo_nombre = props.member.segundo_nombre || "";
        form.primer_apellido = props.member.primer_apellido || "";
        form.segundo_apellido = props.member.segundo_apellido || "";
        form.tipo_documento_id = props.member.tipo_documento_id || "";
        form.numero_documento = props.member.numero_documento || "";

        form.telefono_personal = props.member.telefono_personal || "";
        form.telefono_corporativo = props.member.telefono_corporativo || "";
        form.correo_corporativo = props.member.correo_corporativo || "";

        // Datos de la tabla usuarios (relación)
        form.correo_principal = props.member.usuario?.correo_principal || "";
        form.estado_id = props.member.usuario?.estado_id || 1;

        form.cargo = props.member.cargo || "";
        form.rol_id = props.member.rol_id || "";
        form.equipo_id = props.member.equipo_id || "";
        form.area_encargada_id = props.member.area_encargada_id || "";

        form.contrasena = ""; // Al editar, la dejamos vacía a menos que quiera cambiarla

        if (props.member.foto) {
          fotoPreview.value = "/storage/" + props.member.foto;
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
  const routeName = isEditing.value ? "equipo.update" : "equipo.store";
  const routeParams = isEditing.value ? props.member.id : undefined;

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
  if (currentStep.value === 1)
    return (
      form.primer_nombre &&
      form.primer_apellido &&
      form.tipo_documento_id &&
      form.numero_documento
    );

  if (currentStep.value === 2) return form.correo_principal && form.telefono_personal;

  if (currentStep.value === 3) {
    const isBasicValid =
      form.rol_id && form.equipo_id && form.area_encargada_id && form.cargo;

    if (!isEditing.value) return isBasicValid && form.contrasena.length >= 8;
    return isBasicValid;
  }
  return false;
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
                  class="w-12 h-12 bg-blue-600 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-blue-200/50"
                >
                  <UserCog v-if="isEditing" class="w-6 h-6 text-white" />
                  <UserPlus v-else class="w-6 h-6 text-white" />
                </div>
                <div>
                  <h2 class="text-2xl font-black text-slate-900 tracking-tight">
                    {{ isEditing ? "Editar Miembro del Equipo" : "Nuevo Miembro FYC" }}
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
                :class="step <= currentStep ? 'bg-blue-600 w-full' : 'bg-transparent w-1'"
              ></div>
            </div>
          </div>

          <div class="p-10 bg-slate-50/30 overflow-y-auto max-h-[60vh] custom-scrollbar">
            <form @submit.prevent="submit" id="teamForm">
              <transition name="fade-step" mode="out-in">
                <div v-if="currentStep === 1" class="space-y-6">
                  <div class="flex flex-col items-center mb-6">
                    <div class="relative group cursor-pointer">
                      <div
                        class="w-32 h-32 rounded-[2rem] border-4 border-white shadow-xl bg-blue-50 flex items-center justify-center overflow-hidden transition-transform group-hover:scale-105"
                      >
                        <img
                          v-if="fotoPreview"
                          :src="fotoPreview"
                          class="w-full h-full object-cover"
                        />
                        <Camera v-else class="w-10 h-10 text-blue-200" />
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
                      class="mt-4 text-[10px] font-black text-blue-600 uppercase tracking-widest"
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
                      v-model="form.primer_nombre"
                      icon="format_italic"
                      activeColor="#2563eb"
                      required
                      :max="30"
                      :error="form.errors.primer_nombre"
                    />
                    <FormInput
                      label="Segundo nombre"
                      v-model="form.segundo_nombre"
                      placeholder="Opcional"
                      icon="format_italic"
                      activeColor="#2563eb"
                      :max="30"
                      :error="form.errors.segundo_nombre"
                    />
                    <FormInput
                      label="Primer apellido"
                      v-model="form.primer_apellido"
                      icon="format_italic"
                      activeColor="#2563eb"
                      required
                      :max="30"
                      :error="form.errors.primer_apellido"
                    />
                    <FormInput
                      label="Segundo apellido"
                      v-model="form.segundo_apellido"
                      placeholder="Opcional"
                      icon="format_italic"
                      activeColor="#2563eb"
                      :max="30"
                      :error="form.errors.segundo_apellido"
                    />
                  </div>

                  <div
                    class="bg-white p-5 rounded-2xl border border-slate-200/60 shadow-sm grid grid-cols-1 sm:grid-cols-2 gap-5"
                  >
                    <FormInput
                      label="Tipo Documento"
                      type="select"
                      v-model="form.tipo_documento_id"
                      :options="tiposDocumento"
                      icon="badge"
                      activeColor="#2563eb"
                      required
                      :error="form.errors.tipo_documento_id"
                    />
                    <FormInput
                      label="N° de Documento"
                      v-model="form.numero_documento"
                      type="number"
                      icon="pin"
                      activeColor="#2563eb"
                      required
                      :error="form.errors.numero_documento"
                    />
                  </div>
                </div>

                <div v-else-if="currentStep === 2" class="space-y-6">
                  <div
                    class="bg-white p-6 rounded-[2rem] border border-slate-200/60 shadow-sm space-y-5"
                  >
                    <div
                      class="flex items-center gap-2 text-blue-600 mb-2 border-b border-slate-50 pb-3"
                    >
                      <IdCard class="w-4 h-4" />
                      <span class="text-[10px] font-black uppercase tracking-widest"
                        >Accesos y Principal</span
                      >
                    </div>
                    <FormInput
                      label="Correo Coorporativo (Acceso)"
                      v-model="form.correo_principal"
                      type="email"
                      placeholder="correo@fycconsultores.com"
                      icon="email"
                      activeColor="#2563eb"
                      required
                      :error="form.errors.correo_principal"
                    />
                    <FormInput
                      label="Teléfono Personal"
                      v-model="form.telefono_personal"
                      type="number"
                      placeholder="Celular principal"
                      icon="phone"
                      activeColor="#2563eb"
                      required
                      :max="10"
                      :error="form.errors.telefono_personal"
                    />
                  </div>

                  <div
                    class="bg-white p-6 rounded-[2rem] border border-slate-200/60 shadow-sm space-y-5"
                  >
                    <div
                      class="flex items-center gap-2 text-blue-600 mb-2 border-b border-slate-50 pb-3"
                    >
                      <Briefcase class="w-4 h-4" />
                      <span class="text-[10px] font-black uppercase tracking-widest"
                        >Contacto Corporativo</span
                      >
                    </div>
                    <FormInput
                      label="Correo Corporativo"
                      v-model="form.correo_corporativo"
                      type="email"
                      placeholder="nombre@fycconsultores.com"
                      icon="business"
                      activeColor="#2563eb"
                      :error="form.errors.correo_corporativo"
                      required
                    />
                    <FormInput
                      label="Teléfono Corporativo"
                      v-model="form.telefono_corporativo"
                      type="number"
                      placeholder="Opcional"
                      icon="deskphone"
                      activeColor="#2563eb"
                      :max="10"
                      :error="form.errors.telefono_corporativo"
                      required
                    />
                  </div>
                </div>

                <div v-else-if="currentStep === 3" class="space-y-6">
                  <div
                    class="bg-white p-6 rounded-[2rem] border border-slate-200/60 shadow-sm space-y-5"
                  >
                    <div
                      class="flex items-center gap-2 text-blue-600 mb-2 border-b border-slate-50 pb-3"
                    >
                      <ShieldCheck class="w-4 h-4" />
                      <span class="text-[10px] font-black uppercase tracking-widest"
                        >Estructura Organizacional</span
                      >
                    </div>

                    <FormInput
                      label="Cargo (Ej: Desarrollador Backend)"
                      v-model="form.cargo"
                      type="text"
                      icon="work"
                      activeColor="#2563eb"
                      required
                      :error="form.errors.cargo"
                    />

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 pt-2">
                      <FormInput
                        label="Equipo"
                        type="select"
                        v-model="form.equipo_id"
                        :options="equipos"
                        icon="groups"
                        activeColor="#2563eb"
                        required
                        :error="form.errors.equipo_id"
                      />
                      <FormInput
                        label="Área"
                        type="select"
                        v-model="form.area_encargada_id"
                        :options="areas"
                        icon="category"
                        activeColor="#2563eb"
                        required
                        :error="form.errors.area_encargada_id"
                      />
                    </div>

                    <div class="pt-2">
                      <FormInput
                        label="Nivel de Acceso (Rol)"
                        type="select"
                        v-model="form.rol_id"
                        :options="roles"
                        icon="admin_panel_settings"
                        activeColor="#2563eb"
                        required
                        :error="form.errors.rol_id"
                      />
                    </div>
                  </div>

                  <div
                    class="bg-white p-6 rounded-[2rem] border border-slate-200/60 shadow-sm space-y-5"
                  >
                    <div
                      class="flex items-center justify-between border-b border-slate-50 pb-3"
                    >
                      <div class="flex items-center gap-2 text-blue-600">
                        <Lock class="w-4 h-4" />
                        <span class="text-[10px] font-black uppercase tracking-widest"
                          >Seguridad</span
                        >
                      </div>

                      <label
                        v-if="isEditing"
                        class="flex items-center gap-2 cursor-pointer"
                      >
                        <span class="text-[10px] font-bold text-slate-400">ESTADO</span>
                        <select
                          v-model="form.estado_id"
                          class="text-xs font-bold bg-slate-50 border-slate-200 rounded-lg py-1 px-2 focus:ring-blue-500 focus:border-blue-500"
                        >
                          <option :value="1">Activo</option>
                          <option :value="2">Inactivo</option>
                        </select>
                      </label>
                    </div>

                    <FormInput
                      :label="
                        isEditing
                          ? 'Nueva Contraseña (Dejar en blanco para conservar)'
                          : 'Contraseña de Acceso'
                      "
                      v-model="form.contrasena"
                      type="password"
                      icon="key"
                      placeholder="Mínimo 8 dígitos"
                      activeColor="#2563eb"
                      :required="!isEditing"
                      :error="form.errors.contrasena"
                    />
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

          <div class="p-6 md:px-10 md:py-6 bg-slate-50/50 border-t border-slate-100 flex items-center justify-between shrink-0">
            <div class="flex-1">
              <BtnSecundario 
                v-if="currentStep > 1" 
                label="Regresar" 
                icon="chevron_left" 
                icon-position="left" 
                activeColor="#2563eb" 
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
                activeColor="#2563eb" 
                :disabled="!isStepValid" 
                @click="nextStep" 
                class="!py-3 !px-6 !rounded-2xl" 
              />

              <BtnUniversal 
                v-if="currentStep === totalSteps" 
                :label="isEditing ? 'Guardar Cambios' : 'Registrar Miembro'" 
                :icon="isEditing ? 'save' : 'add'" 
                icon-position="right" 
                size="md" 
                activeColor="#2563eb" 
                :disabled="form.processing || !isStepValid" 
                :loading="form.processing" 
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
