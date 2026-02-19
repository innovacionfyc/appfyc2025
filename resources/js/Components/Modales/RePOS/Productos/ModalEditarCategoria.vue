<script setup>
import { defineProps, defineEmits, ref, computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { handleInput, limitesCaracteres } from "@/Utils/formateoInputs";
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";
import BaseModalSteps from "@/Components/Shared/Modales/BaseModalSteps.vue";

const props = defineProps({
  isOpen: { type: Boolean, required: true },
  categoria: { type: Object, default: null },
});
const emit = defineEmits(["close"]);

const currentStep = ref(1);
const totalSteps = 1;

const form = useForm({
  categoria_padre_id: "",
  nombre: "",
  slug: "",
  descripcion: "",
  icono: "",
  color_hex: "",
  prioridad: "",
});

// --- WATCHER ---
watch(
  () => props.categoria,
  (newCategoria) => {
    if (newCategoria) {
      form.nombre = newCategoria.nombre ?? "";
      form.descripcion = newCategoria.descripcion ?? "";
      form.icono = newCategoria.icono ?? "";
      form.color_hex = newCategoria.color_hex ?? "";
      form.clearErrors();
    }
  },
  { deep: true, immediate: true }
);

// --- COMPUTED ---
const previewIniciales = computed(() => {
  const nombre = form.nombre || props.categoria?.nombre || "Cat";
  return nombre.substring(0, 2).toUpperCase();
});

// --- ACCIONES ---
function submit() {
  if (!props.categoria) return;

  form.post(route("repos.categorias.update", props.categoria.id), {
    preserveScroll: true,
    onSuccess: () => closeModal(true),
    onError: (errors) => console.error("Error:", errors),
  });
}

function closeModal(force = false) {
  if (form.isDirty && !force) {
    if (confirm("¿Descartar cambios?")) {
      emit("close");
      setTimeout(() => form.reset(), 300);
    }
  } else {
    emit("close");
    setTimeout(() => form.reset(), 300);
  }
}

// Lista de iconos comunes para POS (Hospitality/Retail)
const iconosSugeridos = [
  "restaurant",
  "local_bar",
  "icecream",
  "bakery_dining",
  "coffee",
  "fastfood",
  "cake",
  "liquor",
  "dinner_dining",
  "brunch_dining",
  "shopping_bag",
  "inventory_2",
  "sell",
  "auto_awesome",
];

// Paleta de colores Pro para POS
const coloresSugeridos = [
  "#6366f1",
  "#ec4899",
  "#f59e0b",
  "#10b981",
  "#3b82f6",
  "#ef4444",
  "#8b5cf6",
  "#06b6d4",
  "#4b5563",
];

const slugify = (text) => {
  return text
    .toString()
    .toLowerCase()
    .trim()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/\s+/g, "-")
    .replace(/[^\w-]+/g, "")
    .replace(/--+/g, "-");
};

// Computado para el slug dinámico (nombre + hex sin #)
const previewSlug = computed(() => {
  const nombre = slugify(form.nombre || "categoria");
  const color = (form.color_hex || "000000").replace("#", "");
  return `${nombre}-${color}`;
});
</script>

<template>
  <BaseModalSteps
    :isOpen="isOpen"
    :currentStep="currentStep"
    :totalSteps="totalSteps"
    :isSubmitting="form.processing"
    title="Editar Categoría"
    description="Actualiza la información visible en el menú."
    finalButtonText="Guardar Cambios"
    @close="closeModal"
    @submit="submit"
  >
    <div key="create" class="flex flex-col lg:flex-row gap-8 h-full items-start">
      <div class="flex-1 w-full space-y-6">
        <div
          class="bg-gray-50 dark:bg-gray-800/50 p-4 rounded-2xl border border-gray-100 dark:border-gray-700/50"
        >
          <h4 class="font-bold text-gray-900 dark:text-white text-sm">
            Personalización Visual
          </h4>
          <p class="text-xs text-gray-500">
            El color e icono ayudan a tus meseros a identificar productos más rápido.
          </p>
        </div>

        <InputTexto
          v-model="form.nombre"
          label="Nombre de la Categoría"
          icon="label"
          placeholder="Ej: Postres Gourmet"
          :error="form.errors.nombre"
        />

        <div>
          <label
            class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-3"
            >Selecciona un Icono</label
          >
          <div class="grid grid-cols-7 gap-2">
            <button
              v-for="icon in iconosSugeridos"
              :key="icon"
              @click="form.icono = icon"
              type="button"
              :class="
                form.icono === icon
                  ? 'bg-primary text-white shadow-lg shadow-primary/30 scale-110'
                  : 'bg-white dark:bg-gray-900 text-gray-400 border-gray-100 dark:border-gray-800'
              "
              class="h-10 w-10 rounded-xl border flex items-center justify-center transition-all hover:border-primary/50"
            >
              <span class="material-symbols-rounded text-xl">{{ icon }}</span>
            </button>
          </div>
        </div>

        <div>
          <label
            class="block text-xs font-black text-gray-400 uppercase tracking-widest mb-3"
            >Color de Marca</label
          >
          <div class="flex flex-wrap gap-3 items-center">
            <button
              v-for="color in coloresSugeridos"
              :key="color"
              @click="form.color_hex = color"
              type="button"
              :style="{ backgroundColor: color }"
              :class="
                form.color_hex === color
                  ? 'ring-4 ring-offset-2 ring-primary dark:ring-offset-gray-900 scale-110'
                  : ''
              "
              class="h-8 w-8 rounded-full transition-all shadow-sm"
            ></button>

            <div
              class="flex items-center gap-2 ml-2 pl-4 border-l border-gray-200 dark:border-gray-700"
            >
              <input
                type="color"
                v-model="form.color_hex"
                class="h-8 w-8 rounded-lg cursor-pointer border-none bg-transparent"
              />
              <span class="text-[10px] font-mono font-bold text-gray-500 uppercase">{{
                form.color_hex
              }}</span>
            </div>
          </div>
        </div>

        <InputTexto
          v-model="form.descripcion"
          label="Descripción de la Categoría"
          icon="description"
          placeholder="Describe el propósito de esta sección..."
          :error="form.errors.descripcion"
        />
      </div>

      <div class="w-full lg:w-[320px] sticky top-6">
        <p
          class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-4 text-center"
        >
          Vista Previa Point of Sale
        </p>

        <div
          class="w-full rounded-[2.5rem] p-6 shadow-2xl transition-all duration-500 border-2"
          :style="{
            borderColor: form.color_hex + '20',
            backgroundColor: 'var(--tw-bg-opacity)',
          }"
        >
          <div class="flex justify-between items-start mb-6">
            <div
              class="w-16 h-16 rounded-2xl flex items-center justify-center text-white shadow-xl transition-all duration-500"
              :style="{
                backgroundColor: form.color_hex,
                boxShadow: `0 10px 20px ${form.color_hex}40`,
              }"
            >
              <span class="material-symbols-rounded text-3xl">{{
                form.icono || "category"
              }}</span>
            </div>
            <div class="flex flex-col items-end">
              <span
                class="text-[10px] font-black text-gray-300 uppercase tracking-tighter"
                >Items: 0</span
              >
              <div
                class="h-1.5 w-8 rounded-full mt-1"
                :style="{ backgroundColor: form.color_hex }"
              ></div>
            </div>
          </div>

          <h3
            class="text-2xl font-black text-gray-900 dark:text-white leading-tight mb-2"
          >
            {{ form.nombre || "Nombre" }}
          </h3>

          <p class="text-xs text-gray-500 line-clamp-2 italic mb-4">
            {{ form.descripcion || "Sin descripción asignada..." }}
          </p>

          <div class="pt-4 border-t border-dashed border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-2">
              <span
                class="w-2 h-2 rounded-full animate-pulse"
                :style="{ backgroundColor: form.color_hex }"
              ></span>
              <span class="text-[10px] font-mono text-gray-400 font-bold"
                >/{{ previewSlug }}</span
              >
            </div>
          </div>
        </div>

        <div
          class="mt-8 p-4 bg-gray-900 dark:bg-primary/10 rounded-3xl border border-white/5 shadow-inner"
        >
          <div class="flex gap-3">
            <span class="material-symbols-rounded text-primary text-xl">insights</span>
            <p class="text-[11px] text-gray-400 leading-relaxed">
              <b class="text-white dark:text-primary">Tip de Fixnology:</b> Usa colores
              vibrantes para categorías de alta rotación para reducir errores en el cobro.
            </p>
          </div>
        </div>
      </div>
    </div>
  </BaseModalSteps>
</template>
