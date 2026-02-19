<script setup>
import { useForm } from "@inertiajs/vue3";
import { ref, computed, watch } from "vue";
import BaseModalSteps from "@/Components/Shared/Modales/BaseModalSteps.vue";
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";
import Selects from "@/Components/Shared/inputs/Selects.vue";
import { handleInput } from "@/Utils/formateoInputs";

const props = defineProps({
  isOpen: { type: Boolean, required: true },
  cliente: { type: Object, required: true },
  aplicacion: String,
  rol: String,
  tipoDocumentoDisponibles: { type: Array, default: () => [] },
});

const emit = defineEmits(["close"]);

const currentStep = ref(1);

const activeTab = ref(0);
const totalSteps = 2;

watch(activeTab, (val) => (currentStep.value = val + 1));

const form = useForm({
  tipo_documento_id: 1,
  numero_documento: "",
  primer_nombre: "",
  segundo_nombre: "",
  primer_apellido: "",
  segundo_apellido: "",
  email: "",
  telefono: "",
  direccion: "",
  barrio: "",
  ciudad: "",
  fecha_nacimiento: "",
  indicativo_id: 1,
});

const handleNext = () => {
  if (currentStep.value < totalSteps) activeTab.value++;
};
const handlePrev = () => {
  if (currentStep.value > 1) activeTab.value--;
};

// WATCHER INTELIGENTE:
// Rellena el formulario apenas cambia el cliente o se abre el modal.
watch(
  () => props.cliente,
  (newCliente) => {
    if (newCliente) {
      form.tipo_documento_id = newCliente.tipo_documento_id || "";
      form.numero_documento = newCliente.numero_documento || "";
      form.primer_nombre = newCliente.primer_nombre || "";
      form.segundo_nombre = newCliente.segundo_nombre || "";
      form.primer_apellido = newCliente.primer_apellido || "";
      form.segundo_apellido = newCliente.segundo_apellido || "";
      form.email = newCliente.email || "";
      form.telefono = newCliente.telefono || "";
      form.direccion = newCliente.direccion || "";
      form.barrio = newCliente.barrio || "";
      form.ciudad = newCliente.ciudad || "";
      form.fecha_nacimiento = newCliente.fecha_nacimiento || "";
    }
  },
  { immediate: true, deep: true }
);

function submit() {
  form.put(
    route("repos.sub-usuarios.update", {
      aplicacion: props.aplicacion,
      rol: props.rol,
      id: props.cliente.id,
    }),
    {
      preserveScroll: true,
      onSuccess: () => closeModal(true),
    }
  );
}

const closeModal = (force = false) => {
  if (!force && form.isDirty && !confirm("¿Descartar cambios?")) return;
  form.reset();
  form.clearErrors();
  activeTab.value = 0;

  emit("close");
};

const iniciales = computed(() => {
  return (
    ((form.primer_nombre?.[0] || "") + (form.primer_apellido?.[0] || "")).toUpperCase() ||
    "??"
  );
});
</script>

<template>
  <BaseModalSteps
    :isOpen="isOpen"
    v-model:currentStep="currentStep"
    :totalSteps="totalSteps"
    :isSubmitting="form.processing"
    finalButtonText="Editar Cliente"
    @close="closeModal"
    @next="handleNext"
    @prev="handlePrev"
    @submit="submit"
  >
    <template #header>
      <div
        class="relative overflow-hidden bg-slate-900 rounded-3xl p-6 mb-6 border border-white/10 shadow-2xl"
      >
        <div
          class="absolute top-0 right-0 w-32 h-32 bg-primary/20 blur-[50px] rounded-full -mr-16 -mt-16"
        ></div>

        <div class="relative z-10 flex items-center gap-5">
          <div
            class="w-16 h-16 rounded-2xl bg-gradient-to-br from-primary to-indigo-600 flex items-center justify-center text-white text-2xl font-black shadow-lg ring-4 ring-white/5 uppercase"
          >
            {{ iniciales }}
          </div>
          <div class="flex-grow min-w-0">
            <p
              class="text-[10px] font-black text-primary uppercase tracking-[0.3em] mb-1"
            >
              Ficha de Cliente
            </p>
            <h3 class="text-xl font-bold text-white truncate leading-tight">
              {{ form.primer_nombre || "Nuevo" }} {{ form.primer_apellido || "Cliente" }}
            </h3>
            <div class="flex items-center gap-3 mt-1.5">
              <span
                class="flex items-center gap-1 text-[11px] text-slate-400 font-medium"
              >
                <span class="material-symbols-rounded text-sm text-primary"
                  >fingerprint</span
                >
                {{ form.numero_documento || "Sin ID" }}
              </span>
              <span class="w-1 h-1 rounded-full bg-slate-700"></span>
              <span
                class="flex items-center gap-1 text-[11px] text-slate-400 font-medium"
              >
                <span class="material-symbols-rounded text-sm text-primary"
                  >location_on</span
                >
                {{ form.ciudad || "Colombia" }}
              </span>
            </div>
          </div>
        </div>

        <div class="mt-6 h-1 w-full bg-white/5 rounded-full overflow-hidden">
          <div
            class="h-full bg-primary transition-all duration-500"
            :style="{ width: `${(currentStep / totalSteps) * 100}%` }"
          ></div>
        </div>
      </div>
    </template>

    <div class="min-h-[420px] px-1">
      <Transition name="fade-slide" mode="out-in">
        <div v-if="activeTab === 0" key="step1" class="space-y-6">
          <div
            class="bg-slate-50 dark:bg-slate-900/50 p-5 rounded-[2rem] border border-slate-100 dark:border-slate-800 space-y-4"
          >
            <div class="flex items-center gap-2 mb-2">
              <div class="w-1.5 h-4 bg-primary rounded-full"></div>
              <h4 class="text-[10px] font-black uppercase tracking-widest text-slate-500">
                Identificación Oficial
              </h4>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-12 gap-4">
              <div class="sm:col-span-5">
                <Selects
                  v-model="form.tipo_documento_id"
                  :options="props.tipoDocumentoDisponibles"
                  label="Tipo"
                  :error="form.errors.tipo_documento_id"
                />
              </div>
              <div class="sm:col-span-7">
                <InputTexto
                  v-model="form.numero_documento"
                  label="Nº de Documento"
                  placeholder="Ej: 1020..."
                  icon="badge"
                  :error="form.errors.numero_documento"
                  @input="(e) => handleInput(e, form, 'numero_documento')"
                />
              </div>
            </div>
          </div>

          <div
            class="bg-slate-50 dark:bg-slate-900/50 p-5 rounded-[2rem] border border-slate-100 dark:border-slate-800 space-y-4"
          >
            <div class="flex items-center gap-2 mb-2">
              <div class="w-1.5 h-4 bg-indigo-500 rounded-full"></div>
              <h4 class="text-[10px] font-black uppercase tracking-widest text-slate-500">
                Nombres y Apellidos
              </h4>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <InputTexto
                v-model="form.primer_nombre"
                label="Primer Nombre"
                placeholder="Obligatorio"
                icon="person"
                :error="form.errors.primer_nombre"
              />
              <InputTexto
                v-model="form.segundo_nombre"
                label="Segundo Nombre"
                placeholder="Opcional"
                icon="person"
              />
              <InputTexto
                v-model="form.primer_apellido"
                label="Primer Apellido"
                placeholder="Obligatorio"
                icon="person"
                :error="form.errors.primer_apellido"
              />
              <InputTexto
                v-model="form.segundo_apellido"
                label="Segundo Apellido"
                placeholder="Opcional"
                icon="person"
              />
            </div>
          </div>

          <InputTexto
            v-model="form.telefono"
            label="Teléfono Celular"
            icon="smartphone"
            type="tel"
            placeholder="300 000 0000"
            :error="form.errors.telefono"
          />
        </div>

        <div v-else key="step2" class="space-y-6">
          <div
            class="bg-blue-50/50 dark:bg-primary/5 p-5 rounded-[2rem] border border-blue-100/50 dark:border-primary/10"
          >
            <div class="flex items-center gap-3 mb-4">
              <div class="p-2 bg-white dark:bg-slate-900 rounded-xl shadow-sm">
                <span class="material-symbols-rounded text-primary">contact_mail</span>
              </div>
              <div>
                <h4
                  class="text-xs font-bold text-slate-800 dark:text-white uppercase tracking-tight"
                >
                  Correo Electrónico
                </h4>
                <p class="text-[10px] text-slate-500">
                  Vital para Facturación Electrónica
                </p>
              </div>
            </div>
            <InputTexto
              v-model="form.email"
              label="Email"
              icon="alternate_email"
              type="email"
              :error="form.errors.email"
              placeholder="ejemplo@correo.com"
            />
          </div>

          <div
            class="bg-slate-50 dark:bg-slate-900/50 p-5 rounded-[2rem] border border-slate-100 dark:border-slate-800 space-y-4"
          >
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <InputTexto
                v-model="form.ciudad"
                label="Ciudad"
                icon="location_city"
                placeholder="Bogotá, Medellín..."
                :error="form.errors.ciudad"
              />
              <InputTexto
                v-model="form.barrio"
                label="Barrio"
                icon="map"
                placeholder="Nombre del barrio"
              />
            </div>
            <InputTexto
              v-model="form.direccion"
              label="Dirección de Residencia"
              icon="home"
              placeholder="Calle 00 # 00 - 00"
              :error="form.errors.direccion"
            />
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-end">
            <InputTexto
              v-model="form.fecha_nacimiento"
              label="Fecha de Nacimiento"
              icon="cake"
              type="date"
              :error="form.errors.fecha_nacimiento"
            />
            <div
              class="p-4 bg-amber-50 dark:bg-amber-900/10 rounded-2xl border border-amber-100 dark:border-amber-900/20 flex gap-3 h-fit"
            >
              <span class="material-symbols-rounded text-amber-500 text-sm"
                >verified</span
              >
              <p
                class="text-[10px] text-amber-700 dark:text-amber-400 font-medium leading-tight"
              >
                Estos datos permiten aplicar descuentos por cumpleaños automáticamente.
              </p>
            </div>
          </div>
        </div>
      </Transition>
    </div>
  </BaseModalSteps>
</template>

<style scoped>
.fade-slide-enter-active,
.fade-slide-leave-active {
  transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}
.fade-slide-enter-from {
  opacity: 0;
  transform: translateX(20px);
  filter: blur(4px);
}
.fade-slide-leave-to {
  opacity: 0;
  transform: translateX(-20px);
  filter: blur(4px);
}

/* Quitar flechas de input number */
input[type="number"]::-webkit-inner-spin-button,
input[type="number"]::-webkit-outer-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
</style>
