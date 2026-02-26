<script setup>
import { defineProps, defineEmits, ref, computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { handleInput, limitesCaracteres } from "@/Utils/formateoInputs";
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";
import BaseModalSteps from "@/Components/Shared/Modales/BaseModalSteps.vue";

const props = defineProps({
  isOpen: { type: Boolean, required: true },
  areaProduccion: { type: Object, default: null },
  esKds: { type: Object, required: true },
  aplicacion: String,
  rol: String,
});
const emit = defineEmits(["close"]);

const currentStep = ref(1);
const totalSteps = 1;

const form = useForm({
  nombre: "",
  slug: "",
  descripcion: "",
  impresora_nombre: "",
  direccion_ip: "",
  es_kds: "",
});

// --- WATCHER DE CARGA ---
watch(
  () => props.areaProduccion,
  (newArea) => {
    if (newArea) {
      form.nombre = newArea.nombre ?? "";
      form.impresora_nombre = newArea.impresora_nombre ?? "";
      form.descripcion = newArea.descripcion ?? "";
      form.es_kds = newArea.es_kds ?? "";
      form.direccion_ip = newArea.direccion_ip ?? "";
      form.clearErrors();
    }
  },
  { deep: true, immediate: true }
);

// --- COMPUTED VISUAL ---
const previewIniciales = computed(() => {
  const nombre = form.nombre || props.areaProduccion?.nombre || "Área";
  return nombre.substring(0, 2).toUpperCase();
});

// --- ACCIONES ---
function submit() {
  if (!props.areaProduccion) return;

  form.post(route("repos.areaProduccion.update", props.areaProduccion.id), {
    preserveScroll: true,

    onSuccess: () => {
      closeModal(true), form.reset();
    },
    onError: (errors) => console.error("Error:", errors),
  });
}

function closeModal(force = false) {
  if (form.isDirty && !force) {
    if (confirm("¿Descartar cambios en el área?")) {
      emit("close");
      setTimeout(() => {
        form.reset();
      }, 300);
    }
  } else {
    emit("close");
    setTimeout(() => {
      form.reset();
    }, 300);
  }
}

const kdsOptions = [
  {
    value: 1,
    title: "Habilitar KDS + Impresión",
    description: "Se muestra en pantalla y genera comandas físicas en cocina.",
    icon: "receipt_long",
    activeClass: "border-primary ring-1 ring-primary bg-primary/5",
  },
  {
    value: 0,
    title: "Solo Visualización",
    description: "Únicamente visible en pantalla. No genera papel.",
    icon: "monitor",
    activeClass: "border-gray-400 ring-1 ring-gray-400 bg-gray-50 dark:bg-gray-800",
  },
];

const selectOption = (val) => {
  form.es_kds = val;
};
</script>

<template>
  <BaseModalSteps
    :isOpen="isOpen"
    :currentStep="currentStep"
    :totalSteps="totalSteps"
    :isSubmitting="form.processing"
    title="Configuración de Área"
    description="Modifica los parámetros de impresión y red."
    finalButtonText="Guardar Cambios"
    @close="closeModal"
    @submit="submit"
  >
    <div class="space-y-6 py-2">
      <div
        class="relative w-full overflow-hidden rounded-2xl bg-slate-900 dark:bg-black p-5 shadow-lg border border-slate-800"
      >
        <div class="absolute top-4 right-4 flex items-center gap-2">
          <span
            class="w-2 h-2 rounded-full bg-green-500 animate-pulse shadow-[0_0_8px_rgba(34,197,94,0.6)]"
          ></span>
          <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider"
            >En línea</span
          >
        </div>

        <div class="flex items-center gap-4 relative z-10">
          <div
            class="w-14 h-14 rounded-xl bg-white/10 backdrop-blur-md border border-white/20 flex items-center justify-center text-white shadow-inner"
          >
            <span class="material-symbols-rounded text-2xl">print</span>
          </div>

          <div class="flex-1 min-w-0">
            <p class="text-xs font-bold text-blue-400 uppercase tracking-wider mb-0.5">
              Punto de Producción
            </p>
            <h3 class="text-xl font-bold text-white truncate leading-tight">
              {{ form.nombre || "Nombre del Área" }}
            </h3>
            <div class="flex items-center gap-3 mt-2 text-xs font-mono text-slate-400">
              <span class="bg-slate-800 px-2 py-0.5 rounded border border-slate-700">
                {{ form.impresora_nombre || "No asignada" }}
              </span>
              <span v-if="form.direccion_ip" class="flex items-center gap-1">
                <span class="material-symbols-rounded text-[14px]">lan</span>
                {{ form.direccion_ip }}
              </span>
            </div>
          </div>
        </div>
      </div>

       <div class="space-y-4">
              <h4
                class="text-sm font-bold text-gray-900 dark:text-white border-b border-gray-100 dark:border-gray-700 pb-2 mb-4"
              >
                Identificación del Área
              </h4>

              <InputTexto
                v-model="form.nombre"
                label="Nombre del Área"
                icon="restaurant"
                placeholder="Ej: Cocina Caliente, Barra..."
                :maxLength="limitesCaracteres.nombre_producto"
                :error="form.errors.nombre"
                @input="(e) => handleInput(e, form, 'nombre')"
              />

              <InputTexto
                v-model="form.descripcion"
                label="Descríbela brevemente"
                icon="format_italic"
                placeholder="Ej:Impresora para las comandas de cocina únicamente..."
                :maxLength="limitesCaracteres.descripcion_app"
                :error="form.errors.descripcion"
                @input="(e) => handleInput(e, form, 'descripcion')"
              />
            </div>

       <div class="space-y-4">
        <h4
          class="text-sm font-bold text-gray-900 dark:text-white border-b border-gray-100 dark:border-gray-700 pb-2 mb-4"
        >
          Configuración de KDS
        </h4>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div
            v-for="opt in kdsOptions"
            :key="opt.value"
            @click="selectOption(opt.value)"
            class="relative flex flex-col p-4 rounded-2xl border-2 cursor-pointer transition-all duration-200 group hover:shadow-md"
            :class="[
              form.es_kds === opt.value
                ? opt.activeClass
                : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-[#1A2233] hover:border-gray-300 dark:hover:border-gray-600',
            ]"
          >
            <div
              v-if="form.es_kds === opt.value"
              class="absolute top-3 right-3 text-primary animate-scale-in"
            >
              <span class="material-symbols-rounded text-xl">check_circle</span>
            </div>

            <div class="flex items-start gap-4">
              <div
                class="p-3 rounded-xl transition-colors"
                :class="
                  form.es_kds === opt.value
                    ? 'bg-white dark:bg-black/20 shadow-sm'
                    : 'bg-gray-100 dark:bg-gray-800'
                "
              >
                <span
                  class="material-symbols-rounded text-2xl"
                  :class="form.es_kds === opt.value ? 'text-primary' : 'text-gray-400'"
                >
                  {{ opt.icon }}
                </span>
              </div>

              <div class="flex flex-col">
                <span class="text-sm font-bold text-gray-900 dark:text-white mb-1">
                  {{ opt.title }}
                </span>
                <span class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
                  {{ opt.description }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <span
          v-if="form.errors.es_kds"
          class="text-sm flex items-center mt-1 gap-1 text-universal-naranja animate-pulse"
        >
          <span class="material-symbols-rounded text-[14px]">info</span>
          {{ form.errors.es_kds }}
        </span>
      </div>

      <div class="space-y-4">
              <h4
                class="text-sm font-bold text-gray-900 dark:text-white border-b border-gray-100 dark:border-gray-700 pb-2 mb-4"
              >
                Configuración de Hardware
              </h4>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <InputTexto
                  v-model="form.impresora_nombre"
                  label="Nombre Compartido (Windows/CUPS)"
                  icon="print"
                  placeholder="Ej: POS-80C"
                  :maxLength="limitesCaracteres.impresora_nombre_app"
                  :error="form.errors.impresora_nombre"
                  @input="(e) => handleInput(e, form, 'impresora_nombre')"
                />

                <InputTexto
                  v-model="form.direccion_ip"
                  label="Dirección IP (Red)"
                  icon="router"
                  placeholder="Ej: 192.168.1.200"
                  :maxLength="limitesCaracteres.direccion_ip_app"
                  :error="form.errors.direccion_ip"
                  @input="(e) => handleInput(e, form, 'direccion_ip')"
                />
              </div>
              <p
                class="text-xs text-gray-500 bg-yellow-50 dark:bg-yellow-900/10 p-3 rounded-lg border border-yellow-100 dark:border-yellow-800/30 flex gap-2"
              >
                <span class="material-symbols-rounded text-sm text-yellow-600"
                  >tips_and_updates</span
                >
                <span
                  >Si usas conexión USB directa, deja la IP vacía y asegura que el nombre
                  coincida exactamente con el sistema operativo.</span
                >
              </p>
            </div>

     
    </div>
  </BaseModalSteps>
</template>
