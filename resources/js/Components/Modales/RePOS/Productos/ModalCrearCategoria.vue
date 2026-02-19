<script setup>
import { defineProps, defineEmits, ref, computed, watch } from "vue";
import { useForm, router } from "@inertiajs/vue3";
import { handleInput, limitesCaracteres } from "@/Utils/formateoInputs";
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";
import BaseModalSteps from "@/Components/Shared/Modales/BaseModalSteps.vue";
import Pagination from "@/Components/Shared/Pagination.vue";
import { formatFecha } from "@/Utils/date";
import Swal from 'sweetalert2';


// --- PROPS & EMITS ---
const props = defineProps({
  isOpen: { type: Boolean, required: true },
  categorias: { type: Object, required: true },
});
const emit = defineEmits(["close", "edit"]);

// --- STATE ---
const activeTab = ref(0);
const tabs = [{ label: "Catálogo de Categorías" }, { label: "Nueva Categoría" }];

const form = useForm({
  categoria_padre_id: "",
  nombre: "",
  slug: "",
  descripcion: "",
  icono: "",
  color_hex: "",
  prioridad: "",
});

// --- COMPUTEDS ---
const isFormMode = computed(() => activeTab.value === 1);

// Genera iniciales para la tarjeta visual
const previewIniciales = computed(() => {
  const nombre = form.nombre || "Categoria";
  return nombre.substring(0, 2).toUpperCase();
});

// --- ACCIONES ---
function submit() {
  form.post(route("repos.categorias.create"), {
    preserveScroll: true,
    onSuccess: () => {
      closeModal(true);
      // Opcional: Cambiar al tab 0 para ver la creación
      // activeTab.value = 0;
    },
  });
}

function closeModal(force = false) {
  if (form.isDirty && !force) {
    if (confirm("¿Descartar cambios?")) {
      emit("close");
      setTimeout(() => {
        form.reset();
        activeTab.value = 0;
      }, 300);
    }
  } else {
    emit("close");
    setTimeout(() => {
      form.reset();
      activeTab.value = 0;
    }, 300);
  }
}

function handlePaginationClick(event) {
  const link = event.target.closest("a");
  if (!link || !link.href) return;

  router.get(
    link.href,
    {},
    {
      preserveState: true,
      only: ["categorias"],
      preserveScroll: true,
    }
  );
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

const confirmarEliminar = (cat) => {
  Swal.fire({
    title: '¿Eliminar Categoría?',
    html: `Estás a punto de borrar <b class="text-primary">${cat.nombre}</b>.<br><small class="text-gray-500">Esta acción se registrará en el log de auditoría.</small>`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Sí, eliminar',
    cancelButtonText: 'Cancelar',
    confirmButtonColor: '#ef4444',
    // Estética Dark Mode compatible
    background: document.documentElement.classList.contains('dark') ? '#0B0F1A' : '#fff',
    color: document.documentElement.classList.contains('dark') ? '#fff' : '#000',
    customClass: {
        popup: 'rounded-[2.5rem] border border-gray-100 dark:border-gray-800 shadow-2xl',
        confirmButton: 'rounded-xl font-black uppercase tracking-widest text-xs px-6 py-3',
        cancelButton: 'rounded-xl font-black uppercase tracking-widest text-xs px-6 py-3'
    }
  }).then((result) => {
    if (result.isConfirmed) {
      router.delete(route('repos.categorias.delete', cat.id), {
        preserveScroll: true,
        onSuccess: () => {
          Swal.fire({
            title: '¡Eliminado!',
            text: 'La categoría ha sido movida a la papelera.',
            icon: 'success',
            timer: 2000,
            showConfirmButton: false
          });
        }
      });
    }
  });
};
</script>

<template>
  <BaseModalSteps
    :isOpen="isOpen"
    :currentStep="1"
    :totalSteps="1"
    :title="activeTab === 0 ? 'Categorías del Menú' : 'Crear Categoría'"
    :description="
      activeTab === 0
        ? 'Organiza tus productos para una venta más rápida.'
        : 'Define una nueva sección para tu carta.'
    "
    :isSubmitting="form.processing"
    :finalButtonText="activeTab === 0 ? 'Cerrar' : 'Guardar Categoría'"
    :tabs="tabs"
    :activeTab="activeTab"
    @update:activeTab="(val) => (activeTab = val)"
    @close="closeModal"
    @submit="isFormMode ? submit() : closeModal()"
    :isFormStep="isFormMode"
  >
    <div class="py-2">
      <Transition name="fade-slide" mode="out-in">
      <div v-if="activeTab === 0" key="catalog" class="flex flex-col h-full dark:bg-[#07090E]">
  
  <div class="flex items-center justify-between mb-6 px-1">
    <div class="flex items-center gap-3">
      <div class="w-1 h-6 bg-primary rounded-full"></div>
      <div>
        <h3 class="text-sm font-black text-gray-900 dark:text-white uppercase tracking-wider">
          Directorio de Categorías
        </h3>
        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">
          {{ categorias.total }} registros totales
        </p>
      </div>
    </div>
    
    <button @click="activeTab = 1" class="text-[10px] font-black text-primary uppercase border-b-2 border-primary/20 hover:border-primary transition-all pb-0.5">
      + Añadir Nueva
    </button>
  </div>

  <div
    v-if="categorias.data.length === 0"
    class="flex-1 flex flex-col items-center justify-center border border-gray-100 dark:border-gray-800 rounded-3xl bg-gray-50/30 dark:bg-black/20"
  >
    <span class="material-symbols-rounded text-4xl text-gray-200 dark:text-gray-800 mb-2">folder_off</span>
    <p class="text-xs font-bold text-gray-400 uppercase tracking-tighter">No hay categorías diseñadas</p>
  </div>

  <div
    v-else
    class="grid grid-cols-1 md:grid-cols-2 gap-3 overflow-y-auto pr-2 scrollbar-hide pb-6"
  >
    <div
      v-for="cat in categorias.data"
      :key="cat.id"
      class="group relative bg-white dark:bg-[#0B0F1A] border border-gray-100 dark:border-gray-800 rounded-2xl p-3 hover:border-primary/40 hover:shadow-md transition-all duration-300 flex items-center justify-between"
    >
      <div 
        class="absolute left-0 top-1/4 bottom-1/4 w-1 rounded-r-full"
        :style="{ backgroundColor: cat.color_hex }"
      ></div>

      <div class="flex items-center gap-4 pl-2">
        <div
          class="w-10 h-10 rounded-xl flex items-center justify-center text-white shadow-sm transition-transform duration-500 group-hover:scale-105"
          :style="{ 
            backgroundColor: cat.color_hex, 
            boxShadow: `0 4px 12px ${cat.color_hex}30` 
          }"
        >
          <span class="material-symbols-rounded text-xl">{{ cat.icono || "category" }}</span>
        </div>

        <div class="flex flex-col min-w-0">
          <h4 class="font-bold text-gray-900 dark:text-white text-sm tracking-tight truncate max-w-[150px]">
            {{ cat.nombre }}
          </h4>
          <span class="text-[9px] font-mono text-gray-400 font-bold uppercase truncate">
            /{{ cat.slug }}
          </span>
        </div>
      </div>

      <div class="flex gap-1">
          <button
            @click.stop="emit('edit', cat)"
            class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-primary hover:bg-primary/10 transition-all"
          >
            <span class="material-symbols-rounded text-lg">edit</span>
          </button>
          <button
            type="button"
            @click.stop="confirmarEliminar(cat)"
            class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/10 transition-all"
          >
            <span class="material-symbols-rounded text-lg">delete</span>
          </button>
        </div>
    </div>
  </div>

  <div
    v-if="categorias.total > categorias.per_page"
    class="mt-auto pt-4 border-t border-gray-100 dark:border-gray-800 flex justify-center bg-white/50 dark:bg-black/10 backdrop-blur-sm"
  >
    <Pagination
      :links="categorias.links"
      :from="categorias.from"
      :to="categorias.to"
      :total="categorias.total"
      v-model="resultsPerPage"
      @click.prevent="handlePaginationClick"
    />
  </div>
</div>

        <div
          v-else
          key="create"
          class="flex flex-col lg:flex-row gap-8 h-full items-start"
        >
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

              <div
                class="pt-4 border-t border-dashed border-gray-200 dark:border-gray-700"
              >
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
                <span class="material-symbols-rounded text-primary text-xl"
                  >insights</span
                >
                <p class="text-[11px] text-gray-400 leading-relaxed">
                  <b class="text-white dark:text-primary">Tip de Fixnology:</b> Usa
                  colores vibrantes para categorías de alta rotación para reducir errores
                  en el cobro.
                </p>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </div>
  </BaseModalSteps>
</template>

<style scoped>
/* Transiciones suaves */
.fade-slide-enter-active,
.fade-slide-leave-active {
  transition: all 0.3s ease;
}
.fade-slide-enter-from {
  opacity: 0;
  transform: translateX(10px);
}
.fade-slide-leave-to {
  opacity: 0;
  transform: translateX(-10px);
}

.scrollbar-thin::-webkit-scrollbar {
  width: 6px;
}
.scrollbar-thin::-webkit-scrollbar-track {
  background: transparent;
}
.scrollbar-thin::-webkit-scrollbar-thumb {
  background-color: #cbd5e1;
  border-radius: 20px;
}
.dark .scrollbar-thin::-webkit-scrollbar-thumb {
  background-color: #4b5563;
}
</style>
