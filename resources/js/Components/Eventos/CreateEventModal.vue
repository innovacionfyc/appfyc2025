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
  Users,
} from "lucide-vue-next";
import BaseStepperModal from "../Modales/BaseStepperModal.vue";
import FormInput from "../Shared/inputs/FormInput.vue";

const props = defineProps({
  show: { type: Boolean, default: false },
  estados: { type: Array, default: () => [] },
  areas: { type: Array, default: () => [] },
  formularios: { type: Array, default: () => [] },
  organizador: { type: Array, default: () => [] },
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
  modo_evento: "",
  subtitulo: "",
  area_formacion_id: "",
  estado_id: "",
  modalidad: "Presencial",
  fecha_hora_inicio: "",
  fecha_hora_fin: "",
  rango_fechas: [],
  ubicacion: "Centro de convenciones CAFAM Floresta",
  precio_jornada: "",
  precio_modulo: "",
  formulario_base_id: "",
  conferencistas: [],
  color_hex_secundario: "#4F46E5",
  texto_dinamico: "",
  imagen_relacionada: null,
  url_folleto: null,
  url_formulario_inscripcion: null,
  organizador_id: "",

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

// --- DENTRO DE TU FUNCIÓN SUBMIT ---
const submit = () => {
  form
    .transform((data) => {
      const formatDate = (date) => {
        if (!date) return null;
        const d = new Date(date);
        return d.toISOString().split('T')[0];
      };

      return {
        ...data,
        fecha_hora_inicio: data.rango_fechas?.[0] ? formatDate(data.rango_fechas[0]) : null,
        fecha_hora_fin: data.rango_fechas?.[1] 
          ? formatDate(data.rango_fechas[1]) 
          : (data.rango_fechas?.[0] ? formatDate(data.rango_fechas[0]) : null),
      };
    })
    .post(route("eventos.store"), {
      forceFormData: true,
      onSuccess: () => {
        emit("success");
        emit("close");
        form.reset();
        previewImageUrl.value = null;
      },
      onError: (errors) => {
        console.log("Errores detectados:", errors);
      },
    });
};

const stepFields = [
  ["titulo", "area_formacion_id"],
  ["rango_fechas", "modalidad", "ubicacion"],
  ["conferencistas", "contenido_tematico"],
  ["imagen_relacionada", "texto_dinamico"],
];

const getAreaTagImage = () => {
  const areaSeleccionada = props.areas.find((a) => a.id === form.area_formacion_id);

  const areaName = areaSeleccionada?.nombre;

  const imagenesPorArea = {
    Jurídica: "/images/areasFormacion/juridica_web.png",
    "Talento Humano": "/images/areasFormacion/talento_humano_web.png",
    "Gestión y Políticas Públicas": "/images/areasFormacion/gestion_publica_web.png",
    "Enfoques Misionales": "/images/areasFormacion/enfoque_misional_web.png",
    "Finanzas y Hacienda Pública": "/images/areasFormacion/finanzas_publicas_web.png",
  };

  return imagenesPorArea[areaName] || "/images/areasFormacion/formacion_defecto_web.png";
};
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
              <h4 class="text-[14px] font-medium text-slate-400 flex items-center gap-2">
                <Info class="w-3 h-3" /> Identidad del Evento
              </h4>

              <FormInput
                label="Línea de formación"
                v-model="form.modo_evento"
                placeholder="Ej: Seminario de actualización"
                type="text"
                icon="subtitles"
                :activeColor="selectedArea.color_hex_principal"
                :max="100"
                :error="form.errors.modo_evento"
                  required

              />
              <FormInput
                label="Título Principal"
                v-model="form.titulo"
                placeholder="Ej: Gestión Financiera Pública"
                type="text"
                icon="title"
                :activeColor="selectedArea.color_hex_principal"
                :max="150"
                :error="form.errors.titulo"
                  required

              />
              <FormInput
                label="Subtítulo descriptivo"
                v-model="form.subtitulo"
                placeholder="Un eslogan llamativo o complemento del título"
                type="text"
                icon="subtitles"
                :activeColor="selectedArea.color_hex_principal"
                :max="200"
                :error="form.errors.subtitulo"
              />

              <FormInput
                label="Área Académica"
                type="select"
                v-model="form.area_formacion_id"
                :error="form.errors.area_formacion_id"
                :options="areas"
                icon="category"
                :activeColor="selectedArea.color_hex_principal"
                required
              />
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
                :error="form.errors.rango_fechas"
                icon="event"
                :activeColor="selectedArea.color_hex_principal"
                required
              />

              <div class="flex items-center justify-between gap-4">
                <FormInput
                  label="Modalidad"
                  type="select"
                  v-model="form.modalidad"
                  :error="form.errors.modalidad"
                  :options="['Presencial', 'Virtual', 'Híbrido']"
                  :activeColor="selectedArea.color_hex_principal"
                  icon="sensors"
                  required
                />
                <FormInput
                  v-if="form.modalidad === 'Presencial'"
                  label="Ubicación / Link"
                  v-model="form.ubicacion"
                  :error="form.errors.ubicacion"
                  icon="map"
                  :activeColor="selectedArea.color_hex_principal"
                  placeholder="Lugar del evento"
                  required
                />
              </div>
              <div class="grid grid-cols-2 gap-4">
                <FormInput
                  label="Inversión Jornada"
                  type="number"
                  :error="form.errors.precio_jornada"
                  v-model="form.precio_jornada"
                  icon="payments"
                  :max="20"
                  :activeColor="selectedArea.color_hex_principal"
                  required
                />
                <FormInput
                  label="Inversión Módulo"
                  type="price"
                  v-model="form.precio_modulo"
                  :error="form.errors.precio_modulo"
                  icon="sell"
                  :max="20"
                  :activeColor="selectedArea.color_hex_principal"
                  required
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
                  class="text-xs font-bold flex items-center gap-1 hover:bg-blue-50 px-2 py-1 rounded-lg transition-all"
                  :style="{
                    color: selectedArea.color_hex_principal || '#f97316',
                  }"
                >
                  <Plus class="w-3 h-3" /> Nuevo Módulo
                </button>
              </div>
              <div
                class="max-h-[400px] overflow-y-auto pr-4 custom-scroll space-y-6 py-2"
              >
                <div
                  v-for="(modulo, ti) in form.contenido_tematico"
                  :key="ti"
                  class="relative bg-white rounded-3xl border border-slate-100 shadow-sm hover:shadow-md transition-all duration-300 group/item"
                >
                  <div
                    class="flex items-center justify-between px-5 py-3 bg-slate-50/50 rounded-t-[1.8rem] border-b border-slate-50"
                  >
                    <div class="flex items-center gap-2">
                      <span
                        class="flex items-center justify-center w-6 h-6 rounded-full text-[10px] font-black text-white shadow-sm"
                        :style="{ backgroundColor: selectedArea.color_hex_principal }"
                      >
                        {{ ti + 1 }}
                      </span>
                      <span
                        class="text-[10px] font-black uppercase tracking-[0.15em] text-slate-400"
                        >Módulo Académico</span
                      >
                    </div>

                    <button
                      v-if="form.contenido_tematico.length > 1"
                      @click="removeTema(ti)"
                      class="p-1.5 text-slate-300 hover:text-red-500 hover:bg-red-50 rounded-lg transition-all"
                      title="Eliminar módulo"
                    >
                      <Trash2 class="w-4 h-4" />
                    </button>
                  </div>

                  <div class="p-5 pt-4">
                    <FormInput
                      label="Tema"
                      type="price"
                      v-model="modulo.tema"
                      icon="book"
                      :max="200"
                      :activeColor="selectedArea.color_hex_principal"
                      required
                      placeholder="Nombre del eje temático..."
                    />

                    <div
                      class="mt-4 ml-2 pl-6 border-l-2 border-slate-100 space-y-3 relative"
                    >
                      <div
                        v-for="(sub, si) in modulo.subtemas"
                        :key="si"
                        class="group/sub flex items-center gap-3 relative"
                      >
                        <div
                          class="absolute -left-[31px] w-2 h-2 rounded-full bg-slate-200 group-focus-within/sub:scale-125 transition-transform"
                          :style="{
                            backgroundColor: modulo.subtemas[si]
                              ? selectedArea.color_hex_principal
                              : '',
                          }"
                        ></div>

                        <input
                          v-model="modulo.subtemas[si]"
                          placeholder="Añadir subtema o punto clave..."
                          class="flex-1 bg-transparent border-none p-0 text-sm font-medium text-slate-600 placeholder:text-slate-300 focus:ring-0 transition-colors"
                        />

                        <button
                          v-if="modulo.subtemas.length > 1"
                          @click="removeSubtema(ti, si)"
                          class="opacity-0 group-hover/sub:opacity-100 p-1 text-slate-300 hover:text-red-400 transition-opacity"
                        >
                          <X class="w-3.5 h-3.5" />
                        </button>
                      </div>

                      <button
                        type="button"
                        @click="addSubtema(ti)"
                        class="flex items-center gap-2 text-[11px] font-black uppercase tracking-widest text-slate-400 hover:text-rose-800 transition-colors pt-1"
                      >
                        <Plus class="w-3.5 h-3.5" />
                        <span>Añadir punto</span>
                      </button>
                    </div>
                  </div>

                  <div
                    class="absolute left-0 top-10 bottom-10 w-1 rounded-r-full opacity-0 group-hover/item:opacity-100 transition-opacity"
                    :style="{ backgroundColor: selectedArea.color_hex_principal }"
                  ></div>
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
                      ? 'border-rose-600 bg-blue-50'
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
                  <span class="text-[12px] font-bold text-slate-700"
                    >{{ s.primer_nombre }} {{ s.primer_apellido }}
                    <br>
                    <p class="text-[11px] text-slate-700 truncate"
                    :style="{color: selectedArea.color_hex_principal}"
                    >{{ s.area_encargada.nombre }} </p
                  >
                    </span
                  >
                  

                </div>
              </div>
            </div>

            <div v-if="currentStep === 4" class="space-y-5 animate-in">
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
                  :activeColor="selectedArea.color_hex_principal"
                />
              </div>
              <FormInput
                label="Url de formulario de inscripción"
                type="text"
                v-model="form.url_formulario_inscripcion"
                :error="form.errors.url_formulario_inscripcion"
                icon="link"
                placeholder="Ingrese el vinculo correspondiente."
                :max="250"
                :activeColor="selectedArea.color_hex_principal"
                required
              />
              <FormInput
                label="Documento soporte"
                type="file"
                v-model="form.url_folleto"
                icon="upload_file"
                activeColor="#4F46E5"
                placeholder="Click para subir PDF o Imagen"
                :error="form.errors.url_folleto"
                :activeColor="selectedArea.color_hex_principal"
              />

               <FormInput
                label="Comercial encargado"
                type="select"
                v-model="form.organizador_id"
                :error="form.errors.organizador_id"
                :options="organizador"
                icon="category"
                :activeColor="selectedArea.color_hex_principal"
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
              class="relative transition-all duration-700 ease-[cubic-bezier(0.23,1,0.32,1)] bg-slate-800 shadow-2xl border-slate-800 flex flex-col"
              :class="
                deviceMode === 'desktop'
                  ? 'w-full h-full rounded-[20px]'
                  : 'w-[320px] h-[600px] rounded-[3rem]'
              "
            >
              <div
                v-if="deviceMode === 'desktop'"
                class="w-full h-8 bg-slate-800 flex items-center rounded-xl px-4 gap-2 border-b border-slate-700/50"
              >
                <div class="flex gap-1.5">
                  <div class="w-2.5 h-2.5 bg-red-500 rounded-full"></div>
                  <div class="w-2.5 h-2.5 bg-amber-500 rounded-full"></div>
                  <div class="w-2.5 h-2.5 bg-emerald-500 rounded-full"></div>
                </div>
                <div
                  class="bg-slate-700/50 rounded-md px-3 py-1 text-[9px] text-slate-500 flex-1 mx-4 truncate tracking-wider"
                >
                  https://fycconsultores.com/eventos/{{
                    form.titulo.toLowerCase().replace(/\s+/g, "-")
                  }}
                </div>
              </div>

              <div
                class="flex-1 bg-white overflow-y-auto custom-scroll relative pointer-events-none select-none"
              >
                <section
                  class="relative bg-[#0b192cdc] flex items-center justify-center overflow-hidden"
                  :class="deviceMode === 'desktop' ? 'h-[300px]' : 'h-[250px]'"
                >
                  <img
                    :src="previewImageUrl || '/images/default-bg.webp'"
                    class="absolute inset-0 w-full h-full object-cover opacity-100 mix-blend-overlay"
                  />
                  <div class="relative z-10 text-center px-6">
                    <div class="mb-6 md:mb-8 flex justify-center">
                      <img
                        :src="getAreaTagImage()"
                        :alt="form.modo_evento || 'Área de formación'"
                        class="h-10 md:h-14 w-auto object-contain drop-shadow-lg hover:scale-105 transition-transform duration-300"
                      />
                    </div>
                    <p
                      class="text-sm md:text-md text-slate-400 font-medium max-w-xl mx-auto md:mb-2"
                    >
                      · {{ form.modo_evento || "Sin línea de formación" }} ·
                    </p>
                    <h1
                      class="font-black text-white leading-tight mb-1"
                      :class="deviceMode === 'desktop' ? 'text-5xl' : 'text-xl'"
                    >
                      {{ form.titulo || "Sin título definido" }}
                    </h1>
                    <p
                      class="text-sm md:text-md text-white font-medium max-w-xl mx-auto md:mb-2"
                    >
                      {{ form.subtitulo || "Sin subtitulo definido" }}
                    </p>

                    <div
                      class="flex flex-col sm:flex-row items-center justify-center gap-4 sm:gap-8 text-white font-medium bg-black/30 w-full sm:w-fit mx-auto px-6 py-4 rounded-xl backdrop-blur-md border border-white/10"
                    >
                      <span class="flex items-center gap-2 text-[10px]">
                        <Calendar
                          class="w-4 h-4"
                          :style="{
                            color: selectedArea.color_hex_principal || '#f97316',
                          }"
                        />
                        {{ formatRangeFull(form.rango_fechas) }}
                      </span>
                      <span class="flex items-center gap-2 text-[10px]">
                        <MapPin
                          class="w-4 h-4"
                          :style="{
                            color: selectedArea.color_hex_principal || '#f97316',
                          }"
                        />
                        Modalidad {{ form.modalidad }}
                      </span>
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
                      class="relative overflow-hidden bg-gradient-to-br from-[#0B192C] to-[#081121] rounded-[20px] p-3 shadow-xl shrink-0 border border-white/10 isolate"
                    >
                      <div
                        class="absolute -top-10 -right-10 w-48 h-48 opacity-20 blur-[60px] pointer-events-none"
                        :style="{
                          background: selectedArea.color_hex_principal || '#f97316',
                        }"
                      ></div>

                      <div class="relative z-10 flex flex-col gap-4">
                        <div class="flex items-center justify-between">
                          <div>
                            <h3 class="text-xl font-black text-white tracking-tight">
                              Asegura tu cupo
                            </h3>
                            <p
                              class="text-[10px] text-slate-500 font-bold uppercase tracking-widest"
                            >
                              Inversión para esta fecha
                            </p>
                          </div>
                        </div>
                        <div
                          class="flex items-center gap-2 bg-white/5 px-3 py-1.5 rounded-full border border-white/10"
                        >
                          <MapPin
                            class="w-3 h-3"
                            :style="{
                              color: selectedArea.color_hex_principal || '#f97316',
                            }"
                          />
                          <span class="text-[10px] font-bold text-slate-300 uppercase">{{
                            form.modalidad
                          }}</span>
                        </div>
                        <div
                          class="flex items-center justify-between py-4 border-y border-white/5"
                        >
                          <div class="flex flex-col">
                            <p class="text-white text-3xl font-black tracking-tighter">
                              {{ formatPrice(form.precio_jornada) }}
                            </p>
                            <p class="text-[9px] text-slate-500 font-bold uppercase">
                              Jornada Completa • IVA Incl.
                            </p>
                          </div>
                          <div
                            v-if="form.precio_modulo > 0"
                            class="text-right border-l border-white/10 pl-4"
                          >
                            <p class="text-slate-300 text-lg font-bold">
                              {{ formatPrice(form.precio_modulo) }}
                            </p>
                            <p class="text-[9px] text-slate-500 font-bold uppercase">
                              Por Módulo
                            </p>
                          </div>
                        </div>
                        <div
                          class="w-full py-3 rounded-xl text-[10px] text-mono-blanco font-medium uppercase text-center"
                          :style="{ backgroundColor: selectedArea.color_hex_principal }"
                        >
                          Inscribirme Ahora
                        </div>
                      </div>
                    </div>

                    <div
                      class="bg-white border border-slate-200 rounded-[20px] p-3 shadow-lg flex-1 flex flex-col min-h-0 overflow-hidden group/box"
                    >
                      <div class="mb-4 md:mb-6 shrink-0 px-2">
                        <div class="flex items-center justify-between mb-2">
                          <h3
                            class="text-xs font-bold text-slate-900 uppercase tracking-widest flex items-center gap-2"
                          >
                            <Users
                              class="w-4 h-4"
                              :style="{
                                color: selectedArea.color_hex_principal || '#f97316',
                              }"
                            />
                            Equipo Académico
                          </h3>
                          <span class="text-[10px] font-bold text-slate-400"
                            >{{ form.conferencistas?.length }} Exp.</span
                          >
                        </div>
                        <div class="w-full h-1 bg-slate-100 rounded-full overflow-hidden">
                          <div
                            class="h-full rounded-full"
                            :style="{
                              background: selectedArea.color_hex_principal || '#f97316',
                              width: '35%',
                            }"
                          ></div>
                        </div>
                      </div>

                      <div class="flex-1 overflow-y-auto custom-scroll pr-1">
                        <div class="space-y-3 pb-4">
                          <div
                            v-for="sid in form.conferencistas"
                            :key="sid"
                            class="group relative bg-slate-50/50 hover:bg-white border border-transparent hover:border-slate-200 rounded-[1.2rem] md:rounded-[1.5rem] transition-all flex items-center gap-4 overflow-hidden shadow-sm"
                          >
                            <div class="relative shrink-0">
                              <img
                                :src="
                                  '/storage/' +
                                    conferencistas.find((s) => s.id === sid)?.foto ||
                                  'https://ui-avatars.com/api/?name=C'
                                "
                                class="relative w-12 h-12 rounded-2xl object-cover border-2 border-white shadow-sm z-10"
                              />
                            </div>
                            <div class="min-w-0 flex-1">
                              <span
                                class="text-[10px] font-bold"
                                :style="{
                                  color: selectedArea.color_hex_principal || '#f97316',
                                }"
                                >Consultor experto</span
                              >
                              <h4 class="font-bold text-slate-900 text-[14px]">
                                {{
                                  conferencistas.find((s) => s.id === sid)?.primer_nombre
                                }}
                                {{
                                  conferencistas.find((s) => s.id === sid)
                                    ?.primer_apellido
                                }}
                              </h4>
                              <p
                                class="text-[11px] text-slate-500 line-clamp-2 italic font-medium"
                              >
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
