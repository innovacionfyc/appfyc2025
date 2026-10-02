<script setup>
import { ref, computed } from "vue";
import { FileText, Image as ImageIcon, UploadCloud, X } from "lucide-vue-next";

const props = defineProps({
  modelValue: { type: [File, null], default: null },
  error: { type: String, default: "" },
  maxMb: { type: Number, default: 20 },
  disabled: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue", "clearError"]);

const inputRef = ref(null);
const arrastrando = ref(false);
const errorLocal = ref("");

const mensajeError = computed(() => errorLocal.value || props.error);

// Un PDF o una imagen PNG/JPG. Las imágenes se convierten a PDF en el servidor al crear la plantilla.
const esImagen = computed(() => !!props.modelValue && !/\.pdf$/i.test(props.modelValue.name));
const tipoArchivo = computed(() => {
  const ext = (props.modelValue?.name.match(/\.([a-z0-9]+)$/i)?.[1] ?? "").toUpperCase();
  return ext === "JPEG" ? "JPG" : ext || "Archivo";
});

const formatearTamano = (bytes) => {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

// Validación de comodidad en el navegador; la del servidor es la que manda.
const aceptar = (archivo) => {
  errorLocal.value = "";
  if (!archivo) return;

  if (!/\.(pdf|png|jpe?g)$/i.test(archivo.name)) {
    errorLocal.value = "Usa un archivo PDF, PNG o JPG.";
    return;
  }
  if (archivo.size > props.maxMb * 1024 * 1024) {
    errorLocal.value = `El archivo pesa más de ${props.maxMb} MB.`;
    return;
  }

  emit("clearError");
  emit("update:modelValue", archivo);
};

const alSeleccionar = (e) => {
  aceptar(e.target.files?.[0] ?? null);
  // Permite volver a elegir el mismo archivo después de quitarlo
  if (inputRef.value) inputRef.value.value = "";
};

const alSoltar = (e) => {
  arrastrando.value = false;
  if (props.disabled) return;
  aceptar(e.dataTransfer?.files?.[0] ?? null);
};

const quitar = () => {
  errorLocal.value = "";
  emit("update:modelValue", null);
};

const abrirSelector = () => {
  if (!props.disabled) inputRef.value?.click();
};
</script>

<template>
  <div>
    <label class="block text-[11px] font-black uppercase tracking-widest text-slate-500 mb-2 px-1">
      Diseño del certificado <span class="text-primary-vinotinto">*</span>
    </label>

    <input
      ref="inputRef"
      type="file"
      accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg"
      class="hidden"
      :disabled="disabled"
      @change="alSeleccionar"
    />

    <!-- Archivo seleccionado -->
    <div
      v-if="modelValue"
      :class="[
        'flex items-center gap-4 p-4 rounded-2xl border bg-slate-50',
        mensajeError ? 'border-red-300' : 'border-slate-200',
      ]"
    >
      <div class="w-12 h-12 rounded-xl bg-primary-vinotinto/10 text-primary-vinotinto flex items-center justify-center shrink-0">
        <ImageIcon v-if="esImagen" class="w-6 h-6" />
        <FileText v-else class="w-6 h-6" />
      </div>
      <div class="min-w-0 flex-1">
        <p class="text-sm font-bold text-slate-900 truncate" :title="modelValue.name">{{ modelValue.name }}</p>
        <p class="text-[12px] font-semibold text-slate-400">{{ tipoArchivo }} · {{ formatearTamano(modelValue.size) }}</p>
      </div>
      <button
        type="button"
        :disabled="disabled"
        class="w-9 h-9 flex items-center justify-center rounded-xl text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-all disabled:opacity-50"
        aria-label="Quitar archivo"
        @click="quitar"
      >
        <X class="w-5 h-5" />
      </button>
    </div>

    <!-- Zona de carga -->
    <button
      v-else
      type="button"
      :disabled="disabled"
      :class="[
        'w-full flex flex-col items-center justify-center gap-2 px-6 py-8 rounded-2xl border-2 border-dashed transition-all text-center',
        arrastrando ? 'border-primary-vinotinto bg-primary-vinotinto/5' : 'border-slate-200 bg-slate-50 hover:border-slate-300 hover:bg-white',
        mensajeError ? 'border-red-300' : '',
      ]"
      @click="abrirSelector"
      @dragover.prevent="arrastrando = true"
      @dragleave.prevent="arrastrando = false"
      @drop.prevent="alSoltar"
    >
      <span class="w-12 h-12 rounded-2xl bg-white shadow-sm border border-slate-100 flex items-center justify-center text-primary-vinotinto">
        <UploadCloud class="w-6 h-6" />
      </span>
      <span class="text-sm font-bold text-slate-700">Arrastra el archivo aquí o haz clic para elegirlo</span>
      <span class="text-[12px] font-medium text-slate-400">Sube un PDF, PNG o JPG · máximo {{ maxMb }} MB</span>
    </button>

    <p v-if="mensajeError" class="mt-2 px-1 text-[12px] font-semibold text-red-600">{{ mensajeError }}</p>
  </div>
</template>
