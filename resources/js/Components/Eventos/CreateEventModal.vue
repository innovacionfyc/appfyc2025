<script setup>
import { ref, computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import {
  LayoutDashboard,
  MapPin,
  X,
  Loader2,
  Save,
  Plus,
  Trash2,
  AlertCircle,
  ArrowRight,
  UserCheck,
} from "lucide-vue-next";

const props = defineProps({
  show: { type: Boolean, default: false },
  estados: { type: Array, default: () => [] },
  areas: { type: Array, default: () => [] },
  formularios: { type: Array, default: () => [] },
  conferencistas: { type: Array, default: () => [] },
});

const emit = defineEmits(["close", "openDependency"]);

// 1. LÓGICA DE DEPENDENCIAS (El "Orden de Creación")
const missingDependencies = computed(() => {
  const missing = [];
  if (props.areas.length === 0)
    missing.push({
      id: "areas",
      name: "Áreas de Formación",
      route: "admin.areas.create",
    });
  if (props.formularios.length === 0)
    missing.push({
      id: "formularios",
      name: "Formularios Base",
      route: "admin.formularios.create",
    });
  if (props.conferencistas.length === 0)
    missing.push({
      id: "conferencistas",
      name: "Perfil de Conferencistas",
      route: "admin.conferencistas.create",
    });
  return missing;
});

// 2. CONTROL DEL WIZARD
const currentStep = ref(1);
const totalSteps = 4;
const nextStep = () => {
  if (currentStep.value < totalSteps) currentStep.value++;
};
const prevStep = () => {
  if (currentStep.value > 1) currentStep.value--;
};

// 3. FORMULARIO REACTIVO
const form = useForm({
  titulo: "",
  subtitulo: "",
  area_formacion_id: "",
  estado_id: "",
  modalidad: "Presencial",
  fecha_hora: "",
  ubicacion: "",
  precio_jornada: "",
  precio_modulo: "",
  formulario_base_id: "",
  conferencistas: [],
  color_hex_secundario: "#4F46E5",
  texto_dinamico: "",
  imagen_relacionada: null,
  url_folleto: null,

  contenido_tematico: [{ tema: "", subtemas: [""] }],
});

// 4. MÉTODOS DINÁMICOS MULTINIVEL
const addTema = () => {
  form.contenido_tematico.push({ tema: "", subtemas: [""] });
};

const removeTema = (temaIndex) => {
  if (form.contenido_tematico.length > 1) {
    form.contenido_tematico.splice(temaIndex, 1);
  }
};

const addSubtema = (temaIndex) => {
  form.contenido_tematico[temaIndex].subtemas.push("");
};

const removeSubtema = (temaIndex, subtemaIndex) => {
  if (form.contenido_tematico[temaIndex].subtemas.length > 1) {
    form.contenido_tematico[temaIndex].subtemas.splice(subtemaIndex, 1);
  }
};

// 5. MÉTODO PARA TARJETAS DE CONFERENCISTAS
const toggleSpeaker = (id) => {
  const index = form.conferencistas.indexOf(id);
  if (index === -1) form.conferencistas.push(id);
  else form.conferencistas.splice(index, 1);
};

const closeModal = () => {
  emit("close");
  setTimeout(() => {
    form.reset();
    form.clearErrors();
    currentStep.value = 1;
  }, 300);
};

const submit = () => {
  form.post(route("eventos.store"), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => closeModal(),
  });
};
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
        class="fixed inset-0 z-[100] flex items-center justify-center p-4 sm:p-6 bg-slate-900/70 backdrop-blur-md overflow-y-auto"
      >
        <div class="absolute inset-0" @click="closeModal"></div>

        <div
          class="relative bg-white rounded-3xl shadow-2xl w-full max-w-5xl max-h-[90vh] overflow-hidden flex flex-col transform transition-all"
          @click.stop
        >
          <div
            v-if="missingDependencies.length > 0"
            class="p-12 flex flex-col items-center text-center"
          >
            <div
              class="w-20 h-20 bg-amber-100 rounded-full flex items-center justify-center mb-6"
            >
              <AlertCircle class="w-10 h-10 text-amber-600" />
            </div>
            <h2 class="text-3xl font-bold text-slate-900 tracking-tight">
              Faltan configuraciones previas
            </h2>
            <p class="text-slate-500 mt-3 max-w-lg mx-auto text-lg">
              Para garantizar la integridad del evento corporativo, debe crear los
              siguientes elementos antes de continuar:
            </p>

            <div class="mt-8 space-y-3 w-full max-w-md text-left">
              <div
                v-for="dep in missingDependencies"
                :key="dep.id"
                class="p-4 bg-slate-50 border border-slate-200 rounded-2xl flex items-center justify-between"
              >
                <span class="font-bold text-slate-700 flex items-center gap-2">
                  <span class="w-2 h-2 rounded-full bg-amber-500"></span> {{ dep.name }}
                </span>
                <button
                  @click="$emit('openDependency', dep.id)"
                  class="text-sm font-bold text-primary-vinotinto hover:text-indigo-800 flex items-center gap-1"
                >
                  Crear ahora <ArrowRight class="w-4 h-4" />
                </button>
              </div>
            </div>

            <button
              @click="closeModal"
              class="mt-10 px-6 py-3 bg-slate-100 text-slate-700 font-bold rounded-xl hover:bg-slate-200 transition-colors"
            >
              Volver al Panel
            </button>
          </div>

          <template v-else>
            <div class="px-8 py-6 border-b border-slate-100 bg-white">
              <div class="flex justify-between items-center mb-6">
                <div>
                  <h2 class="text-2xl font-bold text-slate-900">
                    Configuración de Evento
                  </h2>
                  <p class="text-slate-500 text-sm mt-1">
                    Siga los pasos para publicar una nueva jornada.
                  </p>
                </div>
                <button
                  @click="closeModal"
                  class="text-slate-400 hover:bg-slate-100 p-2 rounded-xl transition"
                >
                  <X class="w-6 h-6" />
                </button>
              </div>
              <div class="flex gap-2">
                <div
                  v-for="step in totalSteps"
                  :key="step"
                  :class="[
                    'h-2 flex-1 rounded-full transition-colors duration-300',
                    currentStep >= step ? 'bg-primary-vinotinto' : 'bg-slate-100',
                  ]"
                ></div>
              </div>
            </div>

            <div class="p-8 overflow-y-auto flex-1 custom-scrollbar bg-slate-50/50">
              <form id="eventoForm" @submit.prevent="submit" class="space-y-6">
                <div
                  v-show="currentStep === 1"
                  class="animate-in fade-in slide-in-from-right-4 duration-300"
                >
                  <h3
                    class="text-lg font-bold text-slate-900 mb-4 flex items-center gap-2"
                  >
                    <LayoutDashboard class="w-5 h-5 text-indigo-500" />
                    1. Información Principal
                  </h3>

                  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="md:col-span-2">
                      <label
                        class="block text-xs font-bold text-slate-700 mb-1.5 uppercase tracking-wide"
                        >Título del Evento *</label
                      >
                      <input
                        v-model="form.titulo"
                        type="text"
                        class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-rose-600 outline-none transition-all"
                        placeholder="Ej. Congreso Internacional de Auditoría"
                      />
                      <p v-if="form.errors.titulo" class="mt-1 text-xs text-red-600">
                        {{ form.errors.titulo }}
                      </p>
                    </div>
                    <div class="md:col-span-2">
                      <label
                        class="block text-xs font-bold text-slate-700 mb-1.5 uppercase tracking-wide"
                        >Subtítulo (Opcional)</label
                      >
                      <input
                        v-model="form.subtitulo"
                        type="text"
                        class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-rose-600 outline-none transition-all"
                        placeholder="Un eslogan o descripción breve"
                      />
                    </div>
                    <div>
                      <label
                        class="block text-xs font-bold text-slate-700 mb-1.5 uppercase tracking-wide"
                        >Área de Formación *</label
                      >
                      <select
                        v-model="form.area_formacion_id"
                        class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-rose-600 outline-none transition-all cursor-pointer"
                      >
                        <option value="" disabled>Seleccione el área...</option>
                        <option v-for="area in areas" :key="area.id" :value="area.id">
                          {{ area.nombre }}
                        </option>
                      </select>
                      <p
                        v-if="form.errors.area_formacion_id"
                        class="mt-1 text-xs text-red-600"
                      >
                        {{ form.errors.area_formacion_id }}
                      </p>
                    </div>
                    <div>
                      <label
                        class="block text-xs font-bold text-slate-700 mb-1.5 uppercase tracking-wide"
                        >Estado Inicial *</label
                      >
                      <select
                        v-model="form.estado_id"
                        class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-rose-600 outline-none transition-all cursor-pointer"
                      >
                        <option value="" disabled>Seleccione el estado...</option>
                        <option
                          v-for="estado in estados"
                          :key="estado.id"
                          :value="estado.id"
                        >
                          {{ estado.tipo_estado }}
                        </option>
                      </select>
                      <p v-if="form.errors.estado_id" class="mt-1 text-xs text-red-600">
                        {{ form.errors.estado_id }}
                      </p>
                    </div>
                  </div>
                </div>

                <div
                  v-show="currentStep === 2"
                  class="animate-in fade-in slide-in-from-right-4 duration-300"
                >
                  <h3
                    class="text-lg font-bold text-slate-900 mb-4 flex items-center gap-2"
                  >
                    <MapPin class="w-5 h-5 text-indigo-500" />
                    2. Logística y Comercial
                  </h3>

                  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                      <label
                        class="block text-xs font-bold text-slate-700 mb-1.5 uppercase tracking-wide"
                        >Fecha y Hora de Inicio *</label
                      >
                      <input
                        v-model="form.fecha_hora"
                        type="datetime-local"
                        class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-rose-600 outline-none transition-all cursor-pointer"
                      />
                      <p v-if="form.errors.fecha_hora" class="mt-1 text-xs text-red-600">
                        {{ form.errors.fecha_hora }}
                      </p>
                    </div>
                    <div>
                      <label
                        class="block text-xs font-bold text-slate-700 mb-1.5 uppercase tracking-wide"
                        >Modalidad *</label
                      >
                      <select
                        v-model="form.modalidad"
                        class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-rose-600 outline-none transition-all cursor-pointer"
                      >
                        <option value="Presencial">Presencial</option>
                        <option value="Virtual">Virtual</option>
                        <option value="Híbrido">Híbrido</option>
                      </select>
                    </div>
                    <div class="md:col-span-2" v-if="form.modalidad !== 'Virtual'">
                      <label
                        class="block text-xs font-bold text-slate-700 mb-1.5 uppercase tracking-wide"
                        >Ubicación Física</label
                      >
                      <input
                        v-model="form.ubicacion"
                        type="text"
                        placeholder="Ej. Hotel Tequendama, Salón Rojo"
                        class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-rose-600 outline-none transition-all"
                      />
                    </div>
                    <div>
                      <label
                        class="block text-xs font-bold text-slate-700 mb-1.5 uppercase tracking-wide"
                        >Precio Jornada Completa (COP)</label
                      >
                      <div class="relative">
                        <span
                          class="absolute left-4 top-1/2 -translate-y-1/2 font-bold text-slate-400"
                          >$</span
                        >
                        <input
                          v-model="form.precio_jornada"
                          type="number"
                          min="0"
                          placeholder="0"
                          class="w-full pl-8 pr-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-rose-600 outline-none transition-all"
                        />
                      </div>
                    </div>
                    <div>
                      <label
                        class="block text-xs font-bold text-slate-700 mb-1.5 uppercase tracking-wide"
                        >Precio por Módulo (COP)</label
                      >
                      <div class="relative">
                        <span
                          class="absolute left-4 top-1/2 -translate-y-1/2 font-bold text-slate-400"
                          >$</span
                        >
                        <input
                          v-model="form.precio_modulo"
                          type="number"
                          min="0"
                          placeholder="0"
                          class="w-full pl-8 pr-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-rose-600 outline-none transition-all"
                        />
                      </div>
                    </div>
                  </div>
                </div>

                <div
                  v-show="currentStep === 3"
                  class="animate-in fade-in slide-in-from-right-4 duration-300 space-y-6"
                >
                  <h3
                    class="text-lg font-bold text-slate-900 mb-4 flex items-center gap-2"
                  >
                    <UserCheck class="w-5 h-5 tex" />
                    3. Configuración Académica
                  </h3>

                  <div
                    class="bg-slate-50 p-6 rounded-2xl border border-slate-200 shadow-inner"
                  >
                    <h4
                      class="text-base font-bold text-slate-900 mb-4 pb-3 flex justify-between items-center border-b border-slate-200"
                    >
                      Temario de la Jornada
                      <button
                        type="button"
                        @click="addTema"
                        class="text-sm font-bold text-primary-vinotinto hover:text-indigo-800 flex items-center gap-1 bg-indigo-50 px-3 py-1.5 rounded-lg transition-colors"
                      >
                        <Plus class="w-4 h-4" /> Añadir Nuevo Tema
                      </button>
                    </h4>

                    <div class="space-y-6">
                      <div
                        v-for="(modulo, temaIndex) in form.contenido_tematico"
                        :key="'tema-' + temaIndex"
                        class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm relative"
                      >
                        <button
                          v-if="form.contenido_tematico.length > 1"
                          type="button"
                          @click="removeTema(temaIndex)"
                          class="absolute top-4 right-4 p-2 text-slate-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-colors"
                          title="Eliminar este tema completo"
                        >
                          <Trash2 class="w-5 h-5" />
                        </button>

                        <div class="mb-5 pr-10">
                          <label
                            class="block text-xs font-bold mb-1.5 uppercase tracking-wide text-primary-vinotinto"
                          >
                            Tema Principal {{ temaIndex + 1 }} *
                          </label>
                          <input
                            v-model="modulo.tema"
                            type="text"
                            placeholder="Ej. Módulo 1: Actualización Normativa"
                            class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-rose-600 outline-none transition-all"
                          />
                        </div>

                        <div class="pl-4 border-l-2 border-indigo-100 space-y-3">
                          <label
                            class="block text-xs font-bold text-slate-500 mb-2 uppercase tracking-wide"
                            >Subtemas del Módulo</label
                          >

                          <div
                            v-for="(subtema, subtemaIndex) in modulo.subtemas"
                            :key="'sub-' + temaIndex + '-' + subtemaIndex"
                            class="flex items-center gap-2"
                          >
                            <div class="flex-1 relative">
                              <span
                                class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 font-bold text-xs"
                                >{{ temaIndex + 1 }}.{{ subtemaIndex + 1 }}</span
                              >
                              <input
                                v-model="modulo.subtemas[subtemaIndex]"
                                type="text"
                                class="w-full pl-10 pr-4 py-2 bg-white border border-slate-200 rounded-lg focus:ring-2 focus:ring-rose-600 outline-none text-sm transition-all shadow-sm"
                                placeholder="Punto a tratar..."
                              />
                            </div>
                            <button
                              type="button"
                              @click="removeSubtema(temaIndex, subtemaIndex)"
                              :disabled="modulo.subtemas.length === 1"
                              class="p-2 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg disabled:opacity-30 transition-colors"
                            >
                              <X class="w-4 h-4" />
                            </button>
                          </div>

                          <button
                            type="button"
                            @click="addSubtema(temaIndex)"
                            class="mt-2 text-xs font-bold text-slate-500 hover:text-primary-vinotinto flex items-center gap-1 transition-colors"
                          >
                            <Plus class="w-3 h-3" /> Agregar ítem al tema
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="grid grid-cols-1 gap-6">
                    <div>
                      <label
                        class="block text-xs font-bold text-slate-700 mb-1.5 uppercase tracking-wide"
                        >Formulario Base de Inscripción *</label
                      >
                      <select
                        v-model="form.formulario_base_id"
                        class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-rose-600 outline-none transition-all cursor-pointer"
                      >
                        <option value="" disabled>Seleccione la plantilla...</option>
                        <option v-for="f in formularios" :key="f.id" :value="f.id">
                          {{ f.nombre_plantilla || "Plantilla #" + f.id }}
                        </option>
                      </select>
                      <p
                        v-if="form.errors.formulario_base_id"
                        class="mt-1 text-xs text-red-600"
                      >
                        {{ form.errors.formulario_base_id }}
                      </p>
                    </div>
                    <!-- <div class="md:col-span-2">
                      <label
                        class="block text-xs font-bold text-slate-700 mb-1.5 uppercase tracking-wide"
                        >Alcance del evento</label
                      >
                      <input
                        v-model="form.alcance"
                        type="text"
                        placeholder="Ej. 
El taller tiene como propósito fortalecer las capacidades técnicas y estratégicas."
                        class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-rose-600 outline-none transition-all"
                      />
                    </div> -->

                    <div>
                      <label
                        class="block text-xs font-bold text-slate-700 mb-3 uppercase tracking-wide"
                        >Selección de Equipo Académico (Conferencistas) *</label
                      >
                      <p
                        v-if="form.errors.conferencistas"
                        class="mb-3 text-xs text-red-600 font-bold"
                      >
                        {{ form.errors.conferencistas }}
                      </p>

                      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div
                          v-for="speaker in conferencistas"
                          :key="speaker.id"
                          @click="toggleSpeaker(speaker.id)"
                          :class="[
                            'relative p-4 rounded-2xl border-2 cursor-pointer transition-all flex items-center gap-4',
                            form.conferencistas.includes(speaker.id)
                              ? 'border-indigo-600 bg-indigo-50/50 shadow-md'
                              : 'border-slate-200 bg-white hover:border-indigo-300',
                          ]"
                        >
                          <div
                            v-if="form.conferencistas.includes(speaker.id)"
                            class="absolute -top-2 -right-2 w-6 h-6 bg-primary-vinotinto rounded-full flex items-center justify-center border-2 border-white shadow-sm transition-transform scale-100"
                          >
                            <UserCheck class="w-3 h-3 text-white" />
                          </div>

                          <img
                            :src="
                              speaker.foto
                                ? '/storage/' + speaker.foto
                                : `https://ui-avatars.com/api/?name=${speaker.primer_nombre}+${speaker.primer_apellido}&background=4f46e5&color=fff`
                            "
                            class="w-12 h-12 rounded-full object-cover shadow-sm border border-slate-200"
                            alt="Foto del conferencista"
                          />

                          <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-slate-900 truncate">
                              {{ speaker.primer_nombre }} {{ speaker.primer_apellido }}
                            </p>
                            <p class="text-xs text-slate-500 truncate mt-0.5">
                              {{ speaker.area_encargada?.nombre || "Consultor Externo" }}
                            </p>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div
                  v-show="currentStep === 4"
                  class="animate-in fade-in slide-in-from-right-4 duration-300"
                >
                  <h3
                    class="text-lg font-bold text-slate-900 mb-4 flex items-center gap-2"
                  >
                    <Save class="w-5 h-5 text-indigo-500" />
                    4. Multimedia y Diseño
                  </h3>

                  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                      <label
                        class="block text-xs font-bold text-slate-700 mb-1.5 uppercase tracking-wide"
                        >Imagen Principal (Banner)</label
                      >
                      <input
                        @input="form.imagen_relacionada = $event.target.files[0]"
                        type="file"
                        accept="image/*"
                        class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 transition-all cursor-pointer"
                      />
                    </div>
                    <div>
                      <label
                        class="block text-xs font-bold text-slate-700 mb-1.5 uppercase tracking-wide"
                        >Folleto Digital (PDF)</label
                      >
                      <input
                        @input="form.url_folleto = $event.target.files[0]"
                        type="file"
                        accept=".pdf"
                        class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 transition-all cursor-pointer"
                      />
                    </div>
                    <div>
                      <label
                        class="block text-xs font-bold text-slate-700 mb-1.5 uppercase tracking-wide"
                        >Color Hex Secundario</label
                      >
                      <div class="flex gap-2 items-center">
                        <input
                          v-model="form.color_hex_secundario"
                          type="color"
                          class="h-11 w-11 rounded-lg cursor-pointer border-0 p-0"
                        />
                        <input
                          v-model="form.color_hex_secundario"
                          type="text"
                          class="flex-1 px-4 py-3 bg-white border border-slate-200 rounded-xl uppercase focus:ring-2 focus:ring-rose-600 outline-none transition-all"
                        />
                      </div>
                    </div>
                    <div class="md:col-span-2">
                      <label
                        class="block text-xs font-bold text-slate-700 mb-1.5 uppercase tracking-wide"
                        >Texto Dinámico (Descripción Web)</label
                      >
                      <textarea
                        v-model="form.texto_dinamico"
                        rows="4"
                        class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-rose-600 outline-none transition-all"
                        placeholder="Información adicional que se mostrará en la landing page del evento..."
                      ></textarea>
                    </div>
                  </div>
                </div>
              </form>
            </div>

            <div
              class="px-8 py-5 border-t border-slate-100 bg-white flex justify-between items-center rounded-b-3xl"
            >
              <button
                type="button"
                @click="prevStep"
                :class="[
                  'px-6 py-2.5 rounded-xl font-bold transition-all',
                  currentStep === 1
                    ? 'opacity-0 cursor-default pointer-events-none'
                    : 'text-slate-600 bg-slate-100 hover:bg-slate-200',
                ]"
                :disabled="currentStep === 1"
              >
                Anterior
              </button>

              <div
                v-if="Object.keys(form.errors).length > 0"
                class="flex-1 text-right mx-4 text-xs font-bold text-red-500 flex items-center justify-end gap-1"
              >
                <AlertCircle class="w-4 h-4" />
                Revise los campos en rojo en los pasos anteriores.
              </div>

              <div class="flex gap-2">
                <button
                  v-if="currentStep < totalSteps"
                  type="button"
                  @click="nextStep"
                  class="px-8 py-2.5 bg-slate-900 text-white rounded-xl font-bold hover:shadow-lg transition-all hover:-translate-y-0.5"
                >
                  Siguiente Paso
                </button>

                <button
                  v-if="currentStep === totalSteps"
                  type="submit"
                  form="eventoForm"
                  :disabled="form.processing"
                  class="px-8 py-2.5 bg-primary-vinotinto hover:bg-indigo-700 text-white rounded-xl font-bold transition-all flex items-center hover:shadow-lg hover:shadow-indigo-200 hover:-translate-y-0.5 disabled:opacity-70 disabled:hover:translate-y-0"
                >
                  <Loader2 v-if="form.processing" class="w-5 h-5 mr-2 animate-spin" />
                  <Save v-else class="w-5 h-5 mr-2" />
                  Guardar Evento Oficial
                </button>
              </div>
            </div>
          </template>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
  width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: #cbd5e1;
  border-radius: 20px;
}
</style>
