<script setup>
import { watch, ref, computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import BaseModalSteps from "@/Components/Shared/Modales/BaseModalSteps.vue";
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";

const props = defineProps({
  isOpen: Boolean,
  mesa: Object,
  zonas: Array,
});

const emit = defineEmits(["close"]);

// --- ESTADO LOCAL ---
const activeTab = ref(0);
const tabs = [{ label: "Datos de Mesa" }];

const form = useForm({
  nombre_o_numero: "",
  capacidad: "",
  zona_id: "",
  es_unible: false,
});

// --- CARGA DE DATOS ---
watch(
  () => props.mesa,
  (newMesa) => {
    if (newMesa) {
      form.nombre_o_numero = newMesa.nombre_o_numero;
      form.capacidad = newMesa.capacidad;
      form.zona_id = newMesa.zona_id;
      form.es_unible = !!newMesa.es_unible;
    }
  },
  { immediate: true }
);

// Helper para encontrar la zona seleccionada
const zonaSeleccionada = computed(() => {
  return props.zonas.find((z) => z.id === form.zona_id) || null;
});

const capacityCount = computed(() => {
  const n = parseInt(form.capacidad);
  return isNaN(n) || n < 0 ? 0 : Math.min(n, 12); // Limitado a 12 para la vista previa circular
});

// --- ACCIONES ---
const ajustarCapacidad = (v) => {
  const nueva = parseInt(form.capacidad || 0) + v;
  if (nueva >= 1 && nueva <= 20) form.capacidad = nueva;
};

// --- LÓGICA DE FORMA (Sincronizada con el Controlador) ---
const formaCalculada = computed(() => {
  const cap = parseInt(form.capacidad) || 0;
  if (cap <= 4) return "cuadrada";
  if (cap >= 5 && cap <= 8) return "rectangular-h";
  return "rectangular-v"; // 9 o más
});

const tableShapeClass = computed(() => {
  const base = "transition-all duration-500 ease-[cubic-bezier(0.34,1.56,0.64,1)]";
  switch (formaCalculada.value) {
    case "cuadrada":
      return `${base} w-32 h-32 rounded-[2.5rem]`;
    case "rectangular-h":
      return `${base} w-48 h-32 rounded-[2rem]`;
    case "rectangular-v":
      return `${base} w-32 h-48 rounded-[2rem]`;
    default:
      return `${base} w-32 h-32 rounded-[2.5rem]`;
  }
});

const chairsStyle = (index, total) => {
  // Convertimos el índice a un ángulo en radianes
  const angle = ((360 / total) * index - 90) * (Math.PI / 180);

  // Definimos dimensiones reales basadas en tus clases de Tailwind (w-32 = 128px, w-48 = 192px)
  let w, h;
  if (formaCalculada.value === "cuadrada") {
    w = 128;
    h = 128;
  } else if (formaCalculada.value === "rectangular-h") {
    w = 192;
    h = 128;
  } else {
    w = 128;
    h = 192;
  }

  // El "radio" de colisión: mitad de la mesa + un margen para la silla (25px)
  const halfW = w / 2 + 25;
  const halfH = h / 2 + 25;

  const cos = Math.cos(angle);
  const sin = Math.sin(angle);

  // FÓRMULA MAESTRA: Proyecta un círculo sobre el perímetro de un rectángulo
  const scale = Math.min(halfW / Math.abs(cos), halfH / Math.abs(sin));

  const posX = cos * scale;
  const posY = sin * scale;

  // Calculamos la rotación para que la silla siempre "mire" hacia la mesa
  // Sumamos 90 grados para alinear el respaldo correctamente
  const rotation = Math.atan2(posY, posX) * (180 / Math.PI) + 90;

  return {
    transform: `translate(${posX}px, ${posY}px) rotate(${rotation}deg)`,
    transitionDelay: `${index * 40}ms`, // Efecto de cascada al aparecer
  };
};

// --- ACCIONES ---
const closeModal = () => {
  form.reset();
  emit("close");
};

const submit = () => {
  form.post(route("repos.mesas.update", { mesa: props.mesa.id }), {
    preserveScroll: true,
    onSuccess: () => closeModal(),
  });
};
</script>

<template>
  <BaseModalSteps
    :isOpen="isOpen"
    :currentStep="1"
    :totalSteps="1"
    title="Arquitecto de Mesas"
    description="Configura la geometría y ubicación de la nueva unidad."
    :isSubmitting="form.processing"
    finalButtonText="Guardar Mesa"
    @close="closeModal"
    @submit="submit"
  >
    <div class="py-4">
      <div class="flex flex-col lg:flex-row gap-8 items-stretch">
        <div class="flex-1 space-y-6">
          <section
            class="bg-white dark:bg-slate-900/50 p-6 rounded-[2rem] border border-slate-200/60 dark:border-slate-800 shadow-sm space-y-4"
          >
            <header class="flex items-center gap-3 mb-2">
              <div class="w-1.5 h-5 bg-primary rounded-full"></div>
              <h4 class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                Identificación
              </h4>
            </header>

            <InputTexto
              v-model="form.nombre_o_numero"
              label="Nombre / Número de Mesa"
              placeholder="Ej: Mesa 1, VIP-A"
              :error="form.errors.nombre_o_numero"
            />

            <div class="pt-2">
              <label
                class="text-[10px] font-black uppercase tracking-widest text-slate-400 ml-1 mb-3 block"
                >Capacidad de Comensales</label
              >
              <div class="flex items-center gap-4">
                <div
                  class="flex items-center bg-slate-100 dark:bg-slate-950 rounded-2xl p-1 shadow-inner border border-slate-200/50 dark:border-slate-800"
                >
                  <button
                    @click="ajustarCapacidad(-1)"
                    type="button"
                    class="w-10 h-10 flex items-center justify-center rounded-xl hover:bg-white dark:hover:bg-slate-800 text-slate-500 transition-all active:scale-90"
                  >
                    <span class="material-symbols-rounded">remove</span>
                  </button>
                  <input
                    type="number"
                    v-model="form.capacidad"
                    class="w-16 text-center bg-transparent border-none font-black text-lg focus:ring-0 dark:text-white"
                    readonly
                  />
                  <button
                    @click="ajustarCapacidad(1)"
                    type="button"
                    class="w-10 h-10 flex items-center justify-center rounded-xl hover:bg-white dark:hover:bg-slate-800 text-slate-500 transition-all active:scale-90"
                  >
                    <span class="material-symbols-rounded">add</span>
                  </button>
                </div>
                <div class="flex gap-2">
                  <button
                    v-for="n in [2, 4, 6]"
                    :key="n"
                    @click="form.capacidad = n"
                    type="button"
                    :class="
                      form.capacidad === n
                        ? 'bg-primary text-white shadow-lg'
                        : 'bg-slate-50 dark:bg-slate-800 text-slate-400'
                    "
                    class="w-10 h-10 rounded-xl text-xs font-black transition-all hover:scale-105 active:scale-95 border border-slate-200/50 dark:border-slate-700"
                  >
                    {{ n }}
                  </button>
                </div>
              </div>
            </div>
          </section>

          <section class="space-y-4">
            <header class="flex items-center gap-3 px-1">
              <div class="w-1.5 h-5 bg-indigo-500 rounded-full"></div>
              <h4 class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                Zona del Establecimiento
              </h4>
            </header>

            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
              <button
                v-for="zona in zonas"
                :key="zona.id"
                @click="form.zona_id = zona.id"
                type="button"
                :class="
                  form.zona_id === zona.id
                    ? 'ring-2 ring-primary bg-white dark:bg-slate-900 shadow-md'
                    : 'bg-slate-50 dark:bg-slate-900/30 border border-slate-200/50 dark:border-slate-800'
                "
                class="p-4 rounded-[1.5rem] flex flex-col items-center gap-2 transition-all hover:scale-[1.02] relative group"
              >
                <div
                  class="w-10 h-10 rounded-xl flex items-center justify-center text-white shadow-lg"
                  :style="{ backgroundColor: zona.color_hex }"
                >
                  <span class="material-symbols-rounded">{{
                    zona.imagen_plano_url || "grid_view"
                  }}</span>
                </div>
                <span
                  class="text-[10px] font-bold uppercase tracking-tight text-slate-600 dark:text-slate-300"
                  >{{ zona.nombre }}</span
                >
                <div
                  v-if="form.zona_id === zona.id"
                  class="absolute top-2 right-2 text-primary"
                >
                  <span class="material-symbols-rounded text-base">check_circle</span>
                </div>
              </button>

             
            </div>
             
               <span
            v-if="form.errors"
            class="text-sm flex items-center mt-1 gap-1 text-primary animate-pulse"
        >
            <span class="material-symbols-rounded text-[14px]">info</span> {{ form.errors.zona_id  }}
        </span>
          </section>

          <div
            class="p-5 bg-blue-50/50 dark:bg-primary/5 rounded-[2rem] border border-blue-100 dark:border-primary/20 flex items-center justify-between group"
          >
            <div class="flex items-center gap-4">
              <div
                class="w-12 h-12 bg-white dark:bg-slate-900 rounded-2xl flex items-center justify-center text-blue-500 shadow-sm border border-blue-100 dark:border-slate-800"
              >
                <span class="material-symbols-rounded">link</span>
              </div>
              <div class="space-y-0.5">
                <p
                  class="text-xs font-black text-slate-800 dark:text-white uppercase tracking-tight"
                >
                  Estructura Unible
                </p>
                <p class="text-[10px] text-slate-500">
                  ¿Esta mesa puede fusionarse con otras?
                </p>
              </div>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" v-model="form.es_unible" class="sr-only peer" />
              <div
                class="w-12 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary"
              ></div>
            </label>
          </div>
        </div>

        <aside class="w-full lg:w-[400px] shrink-0">
          <div
            class="sticky top-0 bg-slate-100 dark:bg-slate-950/50 rounded-[3rem] p-8 border border-slate-200 dark:border-slate-800 flex flex-col items-center justify-center min-h-[500px] relative overflow-hidden group"
          >
            <div
              class="absolute inset-0 opacity-[0.03] dark:opacity-[0.05] pointer-events-none"
              style="
                background-image: radial-gradient(#000 1px, transparent 1px);
                background-size: 20px 20px;
              "
            ></div>

            <header class="absolute top-8 text-center space-y-1">
              <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.3em]">
                Simulador de Distribución
              </p>
              <p class="text-[9px] font-bold text-primary uppercase tracking-tighter">
                Forma: {{ formaCalculada.replace("-", " ") }}
              </p>
            </header>

            <div class="relative w-80 h-80 flex items-center justify-center">
              <div
                :class="[
                  tableShapeClass,
                  form.es_unible
                    ? 'border-dashed border-primary/50 ring-8 ring-primary/5'
                    : 'border-white dark:border-slate-800',
                ]"
                class="bg-white dark:bg-slate-900 shadow-[0_20px_50px_rgba(0,0,0,0.1)] flex flex-col items-center justify-center z-10 border-4 relative overflow-hidden"
              >
                <div
                  v-if="form.es_unible"
                  class="absolute inset-0 bg-primary/5 animate-pulse"
                ></div>

                <span
                  class="font-black text-2xl text-slate-800 dark:text-white relative z-10"
                  >{{ form.nombre_o_numero || "00" }}</span
                >

                <Transition name="fade">
                  <div
                    v-if="zonaSeleccionada"
                    class="mt-2 flex items-center gap-1.5 px-3 py-1 rounded-full text-[9px] text-white font-black uppercase tracking-tighter relative z-10 shadow-sm"
                    :style="{ backgroundColor: zonaSeleccionada.color_hex }"
                  >
                    <span class="material-symbols-rounded text-[14px]">{{
                      zonaSeleccionada.imagen_plano_url || "location_on"
                    }}</span>
                    {{ zonaSeleccionada.nombre }}
                  </div>
                </Transition>
              </div>

              <TransitionGroup name="chair-pop">
                <div
                  v-for="i in capacityCount"
                  :key="i"
                  class="absolute w-10 h-10 bg-white dark:bg-slate-800 rounded-xl shadow-md border border-slate-200 dark:border-slate-700 flex items-center justify-center transition-all duration-700 z-0"
                  :style="chairsStyle(i - 1, capacityCount)"
                >
                  <div
                    class="w-5 h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full"
                  ></div>
                </div>
              </TransitionGroup>
            </div>

            <div
              class="absolute bottom-8 flex gap-6 bg-white/50 dark:bg-slate-900/50 backdrop-blur-md px-6 py-3 rounded-2xl border border-white/20"
            >
              <div class="text-center">
                <p class="text-[8px] font-bold text-slate-400 uppercase">Capacidad</p>
                <p class="text-xs font-black text-slate-700 dark:text-slate-200">
                  {{ form.capacidad || 0 }} PAX
                </p>
              </div>
              <div class="w-px h-6 bg-slate-200 dark:border-slate-800"></div>
              <div class="text-center">
                <p class="text-[8px] font-bold text-slate-400 uppercase">Tipo</p>
                <p class="text-xs font-black text-primary uppercase">
                  {{ form.es_unible ? "Unible" : "Fija" }}
                </p>
              </div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  </BaseModalSteps>
</template>

<style scoped>
/* Animaciones */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.4s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

input[type="number"]::-webkit-inner-spin-button,
input[type="number"]::-webkit-outer-spin-button {
  -webkit-appearance: none;
  margin: 0;
}

/* Chair Animation */
.chair-pop-enter-active {
  transition: all 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.chair-pop-enter-from {
  opacity: 0;
  transform: scale(0) rotate(-45deg);
}
</style>
