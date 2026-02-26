<script setup>
import { defineProps, defineEmits, ref, computed, watch } from "vue";
import { useForm, router } from "@inertiajs/vue3";
import { handleInput, limitesCaracteres } from "@/Utils/formateoInputs";
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";
import BaseModalSteps from "@/Components/Shared/Modales/BaseModalSteps.vue";
import Pagination from "@/Components/Shared/Pagination.vue";
import Swal from "sweetalert2";
import TestAreaModal from "./TestAreaModal.vue";

const props = defineProps({
  isOpen: { type: Boolean, required: true },
  areasProduccion: { type: Object, required: true },
  esKds: { type: Object, required: true },
  aplicacion: String,
  rol: String,
});
const emit = defineEmits(["close", "edit"]);

// --- STATE ---
const activeTab = ref(0);
const tabs = [{ label: "Puntos de Producción" }, { label: "Nueva Área" }];

const form = useForm({
  nombre: "",
  slug: "",
  descripcion: "",
  impresora_nombre: "",
  direccion_ip: "",
  es_kds: "",
});

const isFormMode = computed(() => activeTab.value === 1);

const isTestModalOpen = ref(false);
const testAreaIp = ref("");
const testAreaName = ref("");

const realizarTestPrueba = (area) => {
  testAreaIp.value = area.direccion_ip;
  testAreaName.value = area.nombre;
  isTestModalOpen.value = true;
};

// Genera iniciales para la tarjeta de vista previa
const previewIniciales = computed(() => {
  const nombre = form.nombre || "Nueva Área";
  return nombre.substring(0, 2).toUpperCase();
});

// --- ACCIONES ---
function submit() {
  form.post(
    route("repos.areaProduccion.create", {
      aplicacion: props.aplicacion,
      rol: props.rol,
    }),
    {
      preserveScroll: true,
      onSuccess: () => {
        closeModal(true);
        form.reset();
      },
    }
  );
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
      only: ["areasProduccion"],
      preserveScroll: true,
    }
  );
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

// Función reutilizable para crear slugs
const slugify = (text) => {
  return text
    .toString()
    .toLowerCase()
    .trim()
    .normalize("NFD") // Separa las tildes de las letras
    .replace(/[\u0300-\u036f]/g, "") // Elimina las tildes
    .replace(/\s+/g, "-") // Reemplaza espacios por guiones
    .replace(/[^\w-]+/g, "") // Elimina caracteres no alfanuméricos
    .replace(/--+/g, "-") // Reemplaza múltiples guiones por uno solo
    .replace(/^-+/, "") // Elimina guiones al inicio
    .replace(/-+$/, ""); // Elimina guiones al final
};

// Propiedad computada para la vista previa
const previewSlug = computed(() => {
  const nombre = form.nombre || "";
  const ip = form.direccion_ip || "";

  // Si no hay nombre, devolvemos un placeholder
  if (!nombre) return "url-amigable";

  // Combinamos nombre e IP como lo haces en el controlador de Laravel
  const rawText = `${nombre}-${ip}`;
  return slugify(rawText);
});

const confirmarEliminacion = (area) => {
  Swal.fire({
    title: "¿Eliminar área?",
    text: `El área "${area.nombre}" dejará de estar disponible, pero los productos vinculados no serán borrados.`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonText: "Sí, eliminar",
    cancelButtonText: "Cancelar",
    confirmButtonColor: "#ef4444", // Red 500
    background: document.documentElement.classList.contains("dark") ? "#111827" : "#fff",
    color: document.documentElement.classList.contains("dark") ? "#fff" : "#000",
  }).then((result) => {
    if (result.isConfirmed) {
      router.delete(route("repos.areaProduccion.delete", area.id), {
        onSuccess: () => {
          Swal.fire("¡Eliminado!", "El área ha sido removida.", "success");
        },
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
    :title="activeTab === 0 ? 'Configuración de Impresión' : 'Nueva Área de Producción'"
    :description="
      activeTab === 0
        ? 'Gestiona dónde se imprimen las comandas.'
        : 'Define un nuevo punto de despacho (Cocina, Bar, etc).'
    "
    :isSubmitting="form.processing"
    :finalButtonText="activeTab === 0 ? 'Cerrar' : 'Guardar Área'"
    :tabs="tabs"
    :activeTab="activeTab"
    @update:activeTab="(val) => (activeTab = val)"
    @close="closeModal"
    @submit="isFormMode ? submit() : closeModal()"
    :isFormStep="isFormMode"
  >
    <div class="py-2">
      <Transition name="fade-slide" mode="out-in">
        <div v-if="activeTab === 0" key="devices" class="space-y-6">
          <div class="flex justify-between items-center px-1">
            <p class="text-sm text-gray-500">
              <strong class="text-gray-900 dark:text-white">{{
                areasProduccion.total
              }}</strong>
              puntos configurados
            </p>
          </div>

          <div
            v-if="areasProduccion.data.length === 0"
            class="flex flex-col items-center justify-center py-20 border-2 border-dashed border-gray-200 dark:border-gray-700 rounded-2xl bg-gray-50/50 dark:bg-gray-800/30"
          >
            <div
              class="w-20 h-20 bg-blue-100 dark:bg-blue-900/30 rounded-full flex items-center justify-center mb-4 animate-pulse"
            >
              <span class="material-symbols-rounded text-4xl text-blue-500"
                >print_disabled</span
              >
            </div>
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">
              Sin Áreas Definidas
            </h3>
            <p class="text-sm text-gray-500 mb-6 max-w-xs text-center">
              No has configurado impresoras para comandas (Cocina, Bar, etc).
            </p>
            <button @click="activeTab = 1" class="text-primary font-bold hover:underline">
              Configurar la primera
            </button>
          </div>

          <div
            v-else
            class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8 max-h-[650px] overflow-y-auto pr-3 scrollbar-thin pb-6"
          >
            <div
              v-for="area in areasProduccion.data"
              :key="area.id"
              class="group relative bg-white dark:bg-gray-900 border border-gray-200/60 dark:border-gray-800 rounded-3xl overflow-hidden transition-all duration-500 hover:-translate-y-2 hover:shadow-[0_20px_50px_rgba(0,0,0,0.1)] dark:hover:shadow-[0_20px_50px_rgba(0,0,0,0.3)]"
            >
              <div
                :class="
                  area.es_kds
                    ? 'from-indigo-500/5 to-transparent'
                    : 'from-emerald-500/5 to-transparent'
                "
                class="absolute inset-0 bg-gradient-to-br opacity-0 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none"
              ></div>

              <div class="relative z-10 p-6 pb-0">
                <div class="flex items-start justify-between">
                  <div class="relative">
                    <div
                      :class="
                        area.es_kds
                          ? 'border-indigo-100 dark:border-indigo-900/30'
                          : 'border-emerald-100 dark:border-emerald-900/30'
                      "
                      class="w-16 h-16 rounded-2xl border-2 flex items-center justify-center bg-white dark:bg-gray-800 shadow-sm group-hover:scale-110 transition-transform duration-500"
                    >
                      <span
                        :class="area.es_kds ? 'text-indigo-600' : 'text-emerald-600'"
                        class="material-symbols-rounded text-3xl"
                      >
                        {{ !area.es_kds ? "desktop_windows" : "print_connect" }}
                      </span>
                    </div>
                    <span class="absolute -top-1 -right-1 flex h-4 w-4">
                      <span
                        class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"
                      ></span>
                      <span
                        class="relative inline-flex rounded-full h-4 w-4 bg-green-500 border-2 border-white dark:border-gray-900"
                      ></span>
                    </span>
                  </div>

                  <div class="flex flex-col items-end gap-2">
                    <span
                      :class="
                        area.es_kds
                          ? 'bg-indigo-100/50 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300'
                          : 'bg-emerald-100/50 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300'
                      "
                      class="text-[10px] px-3 py-1 rounded-lg font-black uppercase tracking-widest backdrop-blur-md"
                    >
                      {{ !area.es_kds ? "KDS System" : "Thermal Print" }}
                    </span>
                    <div class="flex items-center gap-2">
                      <button
                        @click.stop="emit('edit', area)"
                        class="text-gray-400 hover:text-primary transition-all p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 cursor-pointer relative z-20"
                      >
                        <span class="material-symbols-rounded text-2xl">settings</span>
                      </button>
                      <button
                        @click.stop="confirmarEliminacion(area)"
                        class="w-12 h-11 bg-red-50 text-red-500 rounded-xl flex items-center justify-center hover:bg-red-500 hover:text-white transition-colors border border-red-100 dark:border-red-900/30 cursor-pointer relative z-20"
                        title="Eliminar área"
                      >
                        <span class="material-symbols-rounded">delete_outline</span>
                      </button>
                    </div>
                  </div>
                </div>

                <div class="mt-4">
                  <h4
                    class="text-xl font-black text-gray-900 dark:text-white tracking-tight leading-none"
                  >
                    {{ area.nombre }}
                  </h4>
                  <p
                    class="text-xs text-gray-400 dark:text-gray-500 font-medium mt-2 flex items-center gap-1"
                  >
                    <span class="material-symbols-rounded text-sm">comment</span>
                    {{ area.descripcion }}
                  </p>
                </div>
              </div>

              <div class="relative z-10 p-6 pt-4">
                <div class="grid grid-cols-2 gap-3 mb-6">
                  <div
                    class="bg-gray-50 dark:bg-gray-800/50 p-3 rounded-2xl border border-gray-100 dark:border-gray-700/50"
                  >
                    <p class="text-[10px] text-gray-400 uppercase font-bold mb-1">
                      IP Address
                    </p>
                    <p
                      class="text-sm font-mono font-bold text-gray-700 dark:text-gray-200"
                    >
                      {{ area.direccion_ip || "Medio USB" }}
                    </p>
                  </div>
                  <div
                    class="bg-gray-50 dark:bg-gray-800/50 p-3 rounded-2xl border border-gray-100 dark:border-gray-700/50"
                  >
                    <p class="text-[10px] text-gray-400 uppercase font-bold mb-1">
                      Cola de impresión
                    </p>
                    <p
                      class="text-sm font-bold text-gray-700 dark:text-gray-200 flex items-center gap-1"
                    >
                      0
                      <span class="text-[10px] font-normal text-gray-400"
                        >pendientes</span
                      >
                    </p>
                  </div>
                </div>

                <div class="flex items-center gap-2 transition-all duration-300">
                  <button type="button" v-if="area.direccion_ip"
                    class="flex-1 bg-gray-900 dark:bg-white text-white dark:text-gray-900 text-xs font-bold py-3 rounded-xl flex items-center justify-center gap-2 hover:opacity-90 active:scale-95 transition-all cursor-pointer relative z-20"
                    @click.stop="realizarTestPrueba(area)"
                  >
                    <span class="material-symbols-rounded text-sm">rocket_launch</span>
                    Test de Prueba
                  </button>
                  <span type="button" v-else
                    class="flex-1 bg-gray-600 dark:bg-white text-white dark:text-gray-900 text-xs font-bold py-3 rounded-xl flex items-center justify-center gap-2 active:scale-95 transition-all relative z-20">
                    <span class="material-symbols-rounded text-sm">usb</span>
                    Dispositivo conectado
                  </span>

                
                </div>
              </div>

              <div class="h-1.5 w-full bg-gray-100 dark:bg-gray-800 relative z-10">
                <div
                  :class="area.direccion_ip ? 'bg-green-500' : 'bg-red-400'"
                  class="h-full w-full transition-all duration-1000"
                ></div>
              </div>
            </div>
          </div>

          <div
            v-if="areasProduccion.total > areasProduccion.per_page"
            @click.prevent="handlePaginationClick"
            class="flex justify-center pt-2"
          >
            <Pagination
          :links="areasProduccion.links"
          :total="areasProduccion.total"
          @click.prevent="handlePaginationClick"
        />
          </div>
        </div>

        <div
          v-else
          key="create"
          class="flex flex-col lg:flex-row gap-10 h-full items-start"
        >
          <div class="flex-1 w-full space-y-6">
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
                        :class="
                          form.es_kds === opt.value ? 'text-primary' : 'text-gray-400'
                        "
                      >
                        {{ opt.icon }}
                      </span>
                    </div>

                    <div class="flex flex-col">
                      <span class="text-sm font-bold text-gray-900 dark:text-white mb-1">
                        {{ opt.title }}
                      </span>
                      <span
                        class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed"
                      >
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

          <div class="w-full lg:w-[350px] flex flex-col items-center sticky top-6">
            <p
              class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-6"
            >
              Vista Previa en Tiempo Real
            </p>

            <div
              class="w-full relative bg-white dark:bg-gray-900 border-2 border-primary/20 rounded-[2.5rem] overflow-hidden shadow-2xl transition-all duration-500"
            >
              <div class="absolute top-5 right-5">
                <span
                  :class="
                    !form.es_kds
                      ? 'bg-indigo-100/50 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300'
                      : 'bg-emerald-100/50 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300'
                  "
                  class="text-[9px] px-2.5 py-1 rounded-lg font-black uppercase tracking-widest backdrop-blur-md border border-white/20"
                >
                  {{ !form.es_kds ? "KDS System" : "Thermal Print" }}
                </span>
              </div>

              <div class="p-6">
                <div class="flex flex-col items-center text-center mt-4">
                  <div
                    :class="
                      !form.es_kds
                        ? 'border-indigo-100 bg-indigo-50/30'
                        : 'border-emerald-100 bg-emerald-50/30'
                    "
                    class="w-20 h-20 rounded-3xl border-2 flex items-center justify-center relative mb-4 shadow-inner"
                  >
                    <span
                      :class="!form.es_kds ? 'text-indigo-600' : 'text-emerald-600'"
                      class="material-symbols-rounded text-4xl"
                    >
                      {{ !form.es_kds ? "desktop_windows" : "print_connect" }}
                    </span>

                    <span class="absolute -top-1 -right-1 flex h-5 w-5">
                      <span
                        class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"
                      ></span>
                      <span
                        class="relative inline-flex rounded-full h-5 w-5 bg-green-500 border-4 border-white dark:border-gray-900"
                      ></span>
                    </span>
                  </div>

                  <h4
                    class="text-xl font-black text-gray-900 dark:text-white tracking-tight leading-tight break-all"
                  >
                    {{ form.nombre || "Nombre del Área" }}
                  </h4>
                  <p
                    class="text-[11px] font-bold text-gray-400 dark:text-gray-500 mt-1 uppercase tracking-tighter italic"
                  >
                    /{{ previewSlug }}
                  </p>
                </div>

                <div class="mt-8 space-y-3">
                  <div
                    class="bg-gray-50 dark:bg-gray-800/50 p-4 rounded-2xl border border-gray-100 dark:border-gray-700/50 relative overflow-hidden"
                  >
                    <div class="flex justify-between items-center relative z-10">
                      <div>
                        <p class="text-[9px] text-gray-400 uppercase font-black mb-0.5">
                          Hardware
                        </p>
                        <p
                          class="text-xs font-bold text-gray-700 dark:text-gray-200 truncate max-w-[120px]"
                        >
                          {{ form.impresora_nombre || "Sin Asignar" }}
                        </p>
                      </div>
                      <div class="text-right">
                        <p class="text-[9px] text-gray-400 uppercase font-black mb-0.5">
                          Red IP
                        </p>
                        <p class="text-xs font-mono font-black text-primary">
                          {{ form.direccion_ip || "127.0.0.1" }}
                        </p>
                      </div>
                    </div>
                    <span
                      class="material-symbols-rounded absolute -right-2 -bottom-2 text-4xl opacity-[0.03] dark:opacity-[0.05]"
                    >
                      settings_ethernet
                    </span>
                  </div>
                </div>
              </div>

              <div class="h-2 w-full bg-gray-100 dark:bg-gray-800">
                <div
                  :class="
                    form.direccion_ip ? 'bg-green-500 w-full' : 'bg-yellow-500 w-1/2'
                  "
                  class="h-full transition-all duration-1000 ease-in-out"
                ></div>
              </div>
            </div>

            <p class="mt-4 text-[10px] text-gray-400 text-center px-6 leading-relaxed">
              Los cambios se reflejarán instantáneamente en todos los dispositivos
              conectados a <span class="font-bold text-primary">RePOS</span>.
            </p>
          </div>
        </div>
      </Transition>
      <TestAreaModal
        :is-open="isTestModalOpen"
        :ip="testAreaIp"
        :area-name="testAreaName"
        @close="isTestModalOpen = false"
      />
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

.animate-scale-in {
  animation: scaleIn 0.2s ease-out forwards;
}
@keyframes scaleIn {
  from {
    transform: scale(0);
    opacity: 0;
  }
  to {
    transform: scale(1);
    opacity: 1;
  }
}
</style>
