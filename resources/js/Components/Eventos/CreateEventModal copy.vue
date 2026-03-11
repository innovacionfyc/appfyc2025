<script setup>
import { ref, computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import {
  LayoutDashboard,
  MapPin,
  Save,
  Plus,
  Trash2,
  AlertCircle,
  ArrowRight,
  UserCheck,
  CheckCircle2,
  Calendar,
  DollarSign,
  Monitor,
  Smartphone,
  X,
  FileText,
  Globe,
  Palette,
  Info,
} from "lucide-vue-next";
import BaseStepperModal from "../Modales/BaseStepperModal.vue";
import FormInput from "../Shared/inputs/FormInput.vue";

const props = defineProps({
  show: { type: Boolean, default: false },
  estados: { type: Array, default: () => [] },
  areas: { type: Array, default: () => [] },
  formularios: { type: Array, default: () => [] },
  conferencistas: { type: Array, default: () => [] },
});

const emit = defineEmits(["close", "openDependency", "success"]);

// --- ESTADOS DE LA VISTA PREVIA ---
const deviceMode = ref("desktop"); 
const previewImageUrl = ref(null);


const missingDependencies = computed(() => {
  const missing = [];
  if (props.areas.length === 0)
    missing.push({
      id: "areas",
      name: "Áreas de Formación",
      route: "admin.areas.create",
    });
  // if (props.formularios.length === 0)
  //   missing.push({
  //     id: "formularios",
  //     name: "Formularios Base",
  //     route: "admin.formularios.create",
  //   });
  if (props.conferencistas.length === 0)
    missing.push({
      id: "conferencistas",
      name: "Perfil de Conferencistas",
      route: "admin.conferencistas.create",
    });
  return missing;
});

// --- FORMULARIO REACTIVO ---
const form = useForm({
  linea_evento: "",
  titulo: "",
  subtitulo: "",
  area_formacion_id: "",
  estado_id: "",
  modalidad: "Presencial",
  fecha_hora_inicio: "",
  fecha_hora_fin: "",
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
// --- LÓGICA DE FORMATEO PARA PREVIEW ---
const selectedArea = computed(
  () =>
    props.areas.find((a) => a.id === form.area_formacion_id) || {
      nombre: "Área Académica",
      color_hex_principal: "#64748b",
    }
);

const formatPrice = (val) => {
  if (!val) return "Gratuito";
  return new Intl.NumberFormat("es-CO", {
    style: "currency",
    currency: "COP",
    maximumFractionDigits: 0,
  }).format(val);
};

const formatRangeFull = (range) => {
  if (!range || !range[0]) return "Fecha por definir";
  const start = new Date(range[0]);
  const end = range[1] ? new Date(range[1]) : null;
  const getDayName = (d) => d.toLocaleString("es-ES", { weekday: "long" });
  const getDayNum = (d) => d.getDate();
  const getMonth = (d) => d.toLocaleString("es-ES", { month: "long" });
  const getYear = (d) => d.getFullYear();

  if (!end || start.getTime() === end.getTime()) {
    return `${getDayName(start)} ${getDayNum(start)} de ${getMonth(start)} — todo el día`;
  }
  if (start.getMonth() === end.getMonth() && start.getFullYear() === end.getFullYear()) {
    return `${getDayName(start)} ${getDayNum(start)} y ${getDayName(end)} ${getDayNum(
      end
    )} de ${getMonth(start)} de ${getYear(start)}`;
  }
  return `${getDayName(start)} ${getDayNum(start)} de ${getMonth(start)} — ${getDayName(
    end
  )} ${getDayNum(end)} de ${getMonth(end)} de ${getYear(end)}`;
};

// --- MÉTODOS ---
const addTema = () => form.contenido_tematico.push({ tema: "", subtemas: [""] });
const removeTema = (i) => form.contenido_tematico.splice(i, 1);
const addSubtema = (ti) => form.contenido_tematico[ti].subtemas.push("");
const removeSubtema = (ti, si) => form.contenido_tematico[ti].subtemas.splice(si, 1);

const toggleSpeaker = (id) => {
  const index = form.conferencistas.indexOf(id);
  index === -1 ? form.conferencistas.push(id) : form.conferencistas.splice(index, 1);
};

watch(
  () => form.imagen_relacionada,
  (file) => {
    if (file instanceof File) previewImageUrl.value = URL.createObjectURL(file);
  }
);

const submit = () => {
  form.transform((data) => ({
    ...data,
    fecha_hora_inicio: data.rango_fechas[0] ? data.rango_fechas[0] : null,
    
    fecha_hora_fin: data.rango_fechas[1] ? data.rango_fechas[1] : (data.rango_fechas[0] || null),
  })).post(route("eventos.store"), {
    forceFormData: true,
    onSuccess: () => {
      emit("success");
      emit("close");
      form.reset();
      previewImageUrl.value = null;
    },
  });
};

const stepFields = [
  ["titulo", "area_formacion_id"],
  ["rango_fechas", "modalidad", "ubicacion"],
  ["conferencistas", "contenido_tematico"],
  ["imagen_relacionada", "texto_dinamico"],
];
</script>

<template>
  <BaseStepperModal
    :show="show"
    title="Diseño y Publicación de Evento"
    :totalSteps="4"
    :icon="Globe"
    :activeColor="selectedArea.color_hex_principal"
    :isDirty="form.isDirty"
    :errors="form.errors"
    :stepFields="stepFields"
    :loading="form.processing"
    class="!max-w-[95vw] lg:!max-w-[1500px]"
    @close="emit('close')"
    @submit="submit"
  >
    <template #default="{ currentStep }">
      <div class="flex flex-col lg:flex-row gap-8 h-full">
        <div class="w-full lg:w-5/12 space-y-6">
          <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
            <div v-if="currentStep === 1" class="space-y-5 animate-in">
              <h4
                class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2"
              >
                <Info class="w-3 h-3" /> Identidad del Evento
              </h4>
              <FormInput
                label="Título Principal"
                v-model="form.titulo"
                icon="title"
                placeholder="Ej: Seminario de Contratación"
                required
                :max="80"
              />
              <FormInput
                label="Subtítulo descriptivo"
                v-model="form.subtitulo"
                icon="subtitles"
                placeholder="Un eslogan llamativo..."
              />
              <div class="grid grid-cols-2 gap-4">
                <FormInput
                  label="Área Académica"
                  type="select"
                  v-model="form.area_formacion_id"
                  :options="areas"
                  icon="category"
                  required
                />
                <FormInput
                  label="Estado"
                  type="select"
                  v-model="form.estado_id"
                  :options="estados"
                  icon="flag"
                  required
                />
              </div>
            </div>

            <div v-if="currentStep === 2" class="space-y-5 animate-in">
              <h4
                class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2"
              >
                <Calendar class="w-3 h-3" /> Agenda y Costos
              </h4>
              <FormInput
                label="Fechas del Evento"
                type="date-range"
                v-model="form.rango_fechas"
                icon="event"
                required
              />
              <div class="grid grid-cols-2 gap-4">
                <FormInput
                  label="Modalidad"
                  type="select"
                  v-model="form.modalidad"
                  :options="['Presencial', 'Virtual', 'Híbrido']"
                  icon="sensors"
                  required
                />
                <FormInput
                  label="Ubicación / Link"
                  v-model="form.ubicacion"
                  icon="map"
                  placeholder="Lugar del evento"
                />
              </div>
              <div class="grid grid-cols-2 gap-4">
                <FormInput
                  label="Inversión Total"
                  type="price"
                  v-model="form.precio_jornada"
                  icon="payments"
                  :max="12"
                />
                <FormInput
                  label="Inversión Módulo"
                  type="price"
                  v-model="form.precio_modulo"
                  icon="sell"
                  :max="12"
                />
              </div>
            </div>

            <div v-if="currentStep === 3" class="space-y-6 animate-in">
              <div class="flex justify-between items-center">
                <h4
                  class="text-[10px] font-black uppercase text-slate-400 tracking-widest"
                >
                  Temario Académico
                </h4>
                <button
                  @click="addTema"
                  class="text-xs font-bold text-blue-600 flex items-center gap-1 hover:bg-blue-50 px-2 py-1 rounded-lg transition-all"
                >
                  <Plus class="w-3 h-3" /> Nuevo Módulo
                </button>
              </div>
              <div class="max-h-[300px] overflow-y-auto pr-2 custom-scroll space-y-4">
                <div
                  v-for="(modulo, ti) in form.contenido_tematico"
                  :key="ti"
                  class="p-4 bg-slate-50 rounded-2xl relative group/item border border-slate-100"
                >
                  <button
                    v-if="form.contenido_tematico.length > 1"
                    @click="removeTema(ti)"
                    class="absolute top-2 right-2 text-slate-300 hover:text-red-500 opacity-0 group-hover/item:opacity-100 transition-opacity"
                  >
                    <Trash2 class="w-4 h-4" />
                  </button>
                  <FormInput
                    :label="'Módulo ' + (ti + 1)"
                    v-model="modulo.tema"
                    placeholder="Título del tema..."
                  />
                  <div class="mt-3 pl-4 border-l-2 border-slate-200 space-y-2">
                    <div
                      v-for="(sub, si) in modulo.subtemas"
                      :key="si"
                      class="flex gap-2"
                    >
                      <input
                        v-model="modulo.subtemas[si]"
                        placeholder="Subtema..."
                        class="flex-1 bg-white border-none rounded-lg text-xs py-1.5 focus:ring-1 focus:ring-blue-500 shadow-sm"
                      />
                      <button
                        @click="removeSubtema(ti, si)"
                        class="text-slate-300 hover:text-red-400"
                      >
                        <X class="w-3 h-3" />
                      </button>
                    </div>
                    <button
                      @click="addSubtema(ti)"
                      class="text-[10px] font-bold text-blue-500 mt-1"
                    >
                      + Añadir punto
                    </button>
                  </div>
                </div>
              </div>
              <h4
                class="text-[10px] font-black uppercase text-slate-400 tracking-widest pt-2"
              >
                Expertos Asignados
              </h4>
              <div class="grid grid-cols-2 gap-2">
                <div
                  v-for="s in conferencistas"
                  :key="s.id"
                  @click="toggleSpeaker(s.id)"
                  :class="
                    form.conferencistas.includes(s.id)
                      ? 'border-blue-600 bg-blue-50'
                      : 'border-slate-100 bg-white'
                  "
                  class="flex items-center gap-3 p-2 rounded-xl border-2 cursor-pointer transition-all"
                >
                  <img
                    :src="
                      s.foto
                        ? '/storage/' + s.foto
                        : 'https://ui-avatars.com/api/?name=' + s.primer_nombre
                    "
                    class="w-8 h-8 rounded-full object-cover"
                  />
                  <span class="text-[11px] font-bold text-slate-700 truncate"
                    >{{ s.primer_nombre }} {{ s.primer_apellido }}</span
                  >
                </div>
              </div>
            </div>

            <div v-if="currentStep === 4" class="space-y-5 animate-in">
              <FormInput
                label="Descripción Landing Page"
                type="textarea"
                v-model="form.texto_dinamico"
                :rows="5"
                icon="article"
              />
              <div class="grid grid-cols-2 gap-4 items-center">
                <div
                  class="p-6 rounded-3xl border-2 border-dashed border-slate-200 text-center hover:bg-slate-50 transition-colors"
                >
                  <input
                    type="file"
                    @input="form.imagen_relacionada = $event.target.files[0]"
                    id="banner-up"
                    class="hidden"
                    accept="image/*"
                  />
                  <label
                    for="banner-up"
                    class="cursor-pointer flex flex-col items-center"
                  >
                    <Palette class="w-6 h-6 text-slate-300 mb-2" />
                    <span class="text-xs font-black text-slate-500 uppercase">{{
                      form.imagen_relacionada ? "Imagen Lista" : "Banner Evento"
                    }}</span>
                  </label>
                </div>
                <FormInput
                  label="Color Identidad"
                  type="color"
                  v-model="form.color_hex_secundario"
                />
              </div>
              <FormInput
                label="Plantilla de Formulario"
                type="select"
                v-model="form.formulario_base_id"
                :options="formularios"
                icon="assignment"
                required
              />
            </div>
          </div>
        </div>

        <div class="w-full lg:w-7/12 flex flex-col">
          <div class="flex justify-center gap-4 mb-4">
            <button
              @click="deviceMode = 'desktop'"
              :class="
                deviceMode === 'desktop'
                  ? 'bg-slate-900 text-white shadow-lg'
                  : 'bg-white text-slate-400 border border-slate-100'
              "
              class="p-3 rounded-2xl transition-all"
            >
              <Monitor class="w-5 h-5" />
            </button>
            <button
              @click="deviceMode = 'mobile'"
              :class="
                deviceMode === 'mobile'
                  ? 'bg-slate-900 text-white shadow-lg'
                  : 'bg-white text-slate-400 border border-slate-100'
              "
              class="p-3 rounded-2xl transition-all"
            >
              <Smartphone class="w-5 h-5" />
            </button>
          </div>

          <div class="flex-1 flex justify-center items-start overflow-hidden">
            <div
              class="relative transition-all duration-700 ease-[cubic-bezier(0.23,1,0.32,1)] bg-slate-800 shadow-2xl border-[12px] border-slate-800 flex flex-col"
              :class="
                deviceMode === 'desktop'
                  ? 'w-full h-full rounded-[2.5rem]'
                  : 'w-[320px] h-[600px] rounded-[3rem]'
              "
            >
              <div
                v-if="deviceMode === 'desktop'"
                class="w-full h-8 bg-slate-800 flex items-center px-4 gap-2 border-b border-slate-700/50"
              >
                <div class="flex gap-1.5">
                  <div class="w-2.5 h-2.5 bg-red-500 rounded-full"></div>
                  <div class="w-2.5 h-2.5 bg-amber-500 rounded-full"></div>
                  <div class="w-2.5 h-2.5 bg-emerald-500 rounded-full"></div>
                </div>
                <div
                  class="bg-slate-700/50 rounded-md px-3 py-1 text-[9px] text-slate-500 flex-1 mx-4 truncate tracking-wider"
                >
                  https://fyc-consultores.com/eventos/{{
                    form.titulo.toLowerCase().replace(/\s+/g, "-")
                  }}
                </div>
              </div>

              <div
                class="flex-1 bg-white overflow-y-auto custom-scroll relative pointer-events-none select-none"
              >
                <section
                  class="relative bg-[#0B192C] flex items-center justify-center overflow-hidden"
                  :class="deviceMode === 'desktop' ? 'h-[300px]' : 'h-[250px]'"
                >
                  <img
                    :src="previewImageUrl || '/images/default-evento-bg.webp'"
                    class="absolute inset-0 w-full h-full object-cover opacity-40 mix-blend-overlay"
                  />
                  <div class="relative z-10 text-center px-6">
                    <p
                      class="text-[9px] font-black uppercase tracking-[0.3em] mb-3"
                      :style="{ color: selectedArea.color_hex_principal }"
                    >
                      {{ selectedArea.nombre }}
                    </p>
                    <h1
                      class="font-black text-white leading-tight mb-4"
                      :class="deviceMode === 'desktop' ? 'text-4xl' : 'text-xl'"
                    >
                      {{ form.titulo || "Sin título definido" }}
                    </h1>
                    <div
                      class="flex flex-wrap justify-center gap-3 text-white/70 text-[9px] font-bold"
                    >
                      <span
                        class="px-2 py-1 rounded-lg border border-white/10 bg-white/5 flex items-center gap-1"
                        ><MapPin class="w-3 h-3" /> {{ form.modalidad }}</span
                      >
                      <span
                        class="px-2 py-1 rounded-lg border border-white/10 bg-white/5 flex items-center gap-1"
                        ><Calendar class="w-3 h-3" />
                        {{ formatRangeFull(form.rango_fechas) }}</span
                      >
                    </div>
                  </div>
                </section>

                <div
                  class="p-6 grid grid-cols-1 gap-8"
                  :class="deviceMode === 'desktop' ? 'grid-cols-12' : 'grid-cols-1'"
                >
                  <div
                    :class="deviceMode === 'desktop' ? 'col-span-7' : 'col-span-1'"
                    class="space-y-6"
                  >
                    <div class="flex items-center gap-3">
                      <div
                        class="w-8 h-8 rounded-lg flex items-center justify-center"
                        :style="{
                          backgroundColor: selectedArea.color_hex_principal + '15',
                        }"
                      >
                        <FileText
                          class="w-4 h-4"
                          :style="{ color: selectedArea.color_hex_principal }"
                        />
                      </div>
                      <h3
                        class="text-sm font-black text-slate-900 uppercase tracking-widest"
                      >
                        Ejes Temáticos
                      </h3>
                    </div>
                    <div
                      v-for="(tema, i) in form.contenido_tematico"
                      :key="i"
                      class="p-4 bg-slate-50 rounded-2xl border border-slate-100/50"
                    >
                      <div class="flex gap-3">
                        <span
                          class="text-lg font-black opacity-20"
                          :style="{ color: selectedArea.color_hex_principal }"
                          >0{{ i + 1 }}</span
                        >
                        <div>
                          <p class="text-[11px] font-black text-slate-800 uppercase">
                            {{ tema.tema || "Tema pendiente..." }}
                          </p>
                          <div
                            v-if="tema.subtemas.length"
                            class="mt-2 grid grid-cols-1 gap-1"
                          >
                            <div
                              v-for="s in tema.subtemas"
                              :key="s"
                              class="text-[10px] text-slate-500 flex items-center gap-1"
                            >
                              <CheckCircle2
                                class="w-2.5 h-2.5"
                                :style="{ color: selectedArea.color_hex_principal }"
                              />
                              {{ s }}
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div
                    :class="deviceMode === 'desktop' ? 'col-span-5' : 'col-span-1'"
                    class="space-y-6"
                  >
                    <div
                      class="bg-slate-900 rounded-[2rem] p-6 text-white shadow-xl relative overflow-hidden"
                    >
                      <div
                        class="absolute -top-10 -right-10 w-32 h-32 blur-[50px] opacity-30 rounded-full"
                        :style="{ background: selectedArea.color_hex_principal }"
                      ></div>
                      <p
                        class="text-[9px] font-black text-white/50 uppercase mb-4 tracking-widest"
                      >
                        Registro Oficial
                      </p>
                      <h2 class="text-2xl font-black mb-1">
                        {{ formatPrice(form.precio_jornada) }}
                      </h2>
                      <p class="text-[8px] font-bold text-slate-400 mb-6 uppercase">
                        Iva incluido • Certificación digital
                      </p>
                      <div
                        class="w-full py-3 rounded-xl text-[10px] font-black uppercase text-center"
                        :style="{ backgroundColor: selectedArea.color_hex_principal }"
                      >
                        Inscribirme Ahora
                      </div>
                    </div>

                    <div class="space-y-4">
                      <h4
                        class="text-[10px] font-black text-slate-400 uppercase tracking-widest"
                      >
                        Docentes
                      </h4>
                      <div
                        v-for="sid in form.conferencistas"
                        :key="sid"
                        class="flex items-center gap-3 p-2 bg-slate-50 rounded-xl"
                      >
                        <img
                          :src="
                            conferencistas.find((s) => s.id === sid)?.foto ||
                            'https://ui-avatars.com/api/?name=C'
                          "
                          class="w-10 h-10 rounded-full border border-white shadow-sm"
                        />
                        <div>
                          <p class="text-[10px] font-bold text-slate-800">
                            {{ conferencistas.find((s) => s.id === sid)?.primer_nombre }}
                            {{
                              conferencistas.find((s) => s.id === sid)?.primer_apellido
                            }}
                          </p>
                          <p class="text-[8px] text-slate-400 font-bold uppercase">
                            {{
                              conferencistas.find((s) => s.id === sid)?.area_encargada
                                ?.nombre
                            }}
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div
                  class="bg-slate-50 p-6 mt-10 border-t border-slate-100 flex flex-col items-center gap-4"
                >
                  <img
                    src="/images/buho_fyc.png"
                    class="w-12 h-auto opacity-20 grayscale"
                  />
                  <p class="text-[8px] font-bold text-slate-300 uppercase">
                    © 2026 F&C Consultores Académicos
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
  </BaseStepperModal>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar {
  display: none;
}

.custom-scroll::-webkit-scrollbar {
  width: 4px;
}
.custom-scroll::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 10px;
}

.animate-in {
  animation: slideIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateY(15px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* Efecto para el simulador */
.simulator-glow {
  box-shadow: 0 0 40px rgba(0, 0, 0, 0.1);
}
</style>
