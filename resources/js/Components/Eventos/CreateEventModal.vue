<script setup>
import { ref, computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import {
  SquareSquare,
  MapPin,
  Save,
  Plus,
  Trash2,
  Columns,
  LayoutGrid,
  List,
  Calendar,
  X,
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
  organizador: { type: Array, default: () => [] },
  conferencistas: { type: Array, default: () => [] },
  mode: { type: String, default: "create" },
  evento: { type: Object, default: null },
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

const form = useForm({
  id: null,
  id_origen_duplicado: null,
  linea_evento: "",
  titulo: "",
  modo_evento: null,
  subtitulo: "",
  area_formacion_id: "",
  estado_id: "",
  modalidad: null,
  fecha_hora_inicio: "",
  fecha_hora_fin: "",
  fecha_hora_inicio: "",
  fecha_hora_fin: "",
  ubicacion: null,
  formulario_base_id: "",
  conferencistas: [],
  color_hex_secundario: null,
  estilo_temario: "lista",
  estilo_expertos: "lista",
  texto_dinamico: "",
  tipo_evento: null,
  precio_jornada: 0,
  precio_modulo: 0,
  precio_cng: 0,
  precio_curso_intensivo: 0,
  precio_diplomado: 0,
  imagen_relacionada: null,
  url_folleto: null,
  url_formulario_inscripcion: null,
  organizador_id: "",
  tiene_oferta_valor: false,
  oferta_valor: "",

  contenido_tematico: [{ tema: "(Escribe algo...)", subtemas: [""] }],
});

const camposPrecioActivos = computed(() => {
  const map = {
    JORNADA: ["precio_jornada"],
    MODULO: ["precio_modulo"],
    CNG: ["precio_cng"],
    CURSO_INTENSIVO: ["precio_curso_intensivo"],
    DIPLOMADO: ["precio_diplomado"],
    CI_CNG: ["precio_curso_intensivo", "precio_cng"],
    JOR_MOD: ["precio_jornada", "precio_modulo"],
  };
  return map[form.tipo_evento] || [];
});

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

const addTema = () => {
  form.contenido_tematico.push({ tema: "", subtemas: [""] });
};
const removeTema = (i) => form.contenido_tematico.splice(i, 1);
const addSubtema = (ti) => form.contenido_tematico[ti].subtemas.push("");
const removeSubtema = (ti, si) => form.contenido_tematico[ti].subtemas.splice(si, 1);

const toggleSpeaker = (id) => {
  const index = form.conferencistas.indexOf(id);
  index === -1 ? form.conferencistas.push(id) : form.conferencistas.splice(index, 1);
};

const clearForm = () => {
  form.id = null;
  form.id_origen_duplicado = null;
  form.modo_evento = "";
  form.titulo = "";
  form.subtitulo = "";
  form.area_formacion_id = "";
  form.estado_id = "";
  form.tipo_evento = "";
  form.modalidad = "";
  form.fecha_hora_inicio = "";
  form.fecha_hora_fin = "";
  form.ubicacion = "";

  form.precio_jornada = 0;
  form.precio_modulo = 0;
  form.precio_cng = 0;
  form.precio_curso_intensivo = 0;
  form.precio_diplomado = 0;

  form.tiene_oferta_valor = false;
  form.oferta_valor = "";

  form.conferencistas = [];
  form.contenido_tematico = [{ tema: "", subtemas: [""] }];

  form.color_hex_secundario = "#4F46E5";
  form.estilo_temario = "lista";
  form.estilo_expertos = "lista";
  form.texto_dinamico = "";

  form.imagen_relacionada = null;
  form.url_folleto = null;
  form.url_formulario_inscripcion = "";
  form.organizador_id = "";

  form.clearErrors();
  previewImageUrl.value = null;
};

watch(
  () => form.imagen_relacionada,
  (file) => {
    if (file instanceof File) previewImageUrl.value = URL.createObjectURL(file);
  }
);

watch(
  () => [props.show, props.evento, props.mode],
  ([isVisible, evento, mode]) => {
    if (isVisible) {
      if ((mode === "edit" || mode === "duplicate") && evento) {
        form.id = mode === "edit" ? evento.id : null;
        form.id_origen_duplicado = mode === "duplicate" ? evento.id : null;

        form.modo_evento = evento.modo_evento || null;
        form.titulo = mode === "duplicate" ? `${evento.titulo} (Copia)` : evento.titulo;
        form.subtitulo = evento.subtitulo || null;
        form.area_formacion_id = evento.area_formacion_id || null;
        form.estado_id = mode === "duplicate" ? 1 : evento.estado_id || null;
        form.tipo_evento = evento.tipo_evento || null;
        form.modalidad = evento.modalidad || null;
        form.ubicacion = evento.ubicacion || null;

        form.precio_jornada = evento.precio_jornada || 0;
        form.precio_modulo = evento.precio_modulo || 0;
        form.precio_cng = evento.precio_cng || 0;
        form.precio_curso_intensivo = evento.precio_curso_intensivo || 0;
        form.precio_diplomado = evento.precio_diplomado || 0;

        form.tiene_oferta_valor = !!evento.oferta_valor;
        form.oferta_valor = evento.oferta_valor || null;

        form.texto_dinamico = evento.texto_dinamico || null;
        form.color_hex_secundario = evento.color_hex_secundario || "#4F46E5";
        form.estilo_temario = evento.estilo_temario || "lista";
        form.estilo_expertos = evento.estilo_expertos || "lista";

        form.url_formulario_inscripcion = evento.url_formulario_inscripcion || null;
        form.organizador_id = evento.organizador_id || null;

        form.imagen_relacionada = null;
        form.url_folleto = null;
        form.conferencistas = evento.conferencistas
          ? evento.conferencistas.map((s) => s.id)
          : [];

        const formatForInput = (dbDate) => {
          if (!dbDate) return "";
          return dbDate.replace(" ", "T").substring(0, 16);
        };

        form.fecha_hora_inicio = formatForInput(evento.fecha_hora_inicio);
        form.fecha_hora_fin = formatForInput(evento.fecha_hora_fin);

        const rawModulos =
          evento.contenido_tematico?.modulos || evento.contenido_tematico;
        if (rawModulos) {
          form.contenido_tematico = JSON.parse(JSON.stringify(rawModulos)).map((m) => ({
            tema: m.tema || "",
            subtemas: m.subtemas || [],
          }));
        } else {
          form.contenido_tematico = [{ tema: "", subtemas: [""] }];
        }

        previewImageUrl.value = evento.imagen_relacionada
          ? evento.imagen_relacionada.startsWith("http")
            ? evento.imagen_relacionada
            : `/storage/${evento.imagen_relacionada}`
          : null;

        form.defaults();
      } else {
        clearForm();
      }
    } else {
      clearForm();
    }
  },
  { deep: true, immediate: true }
);

const submit = () => {
  const url = form.id ? route("eventos.update", form.id) : route("eventos.store");

  form
    .transform((data) => {
      const todosLosPrecios = [
        "precio_jornada",
        "precio_modulo",
        "precio_cng",
        "precio_curso_intensivo",
        "precio_diplomado",
      ];
      todosLosPrecios.forEach((campo) => {
        if (!camposPrecioActivos.value.includes(campo)) data[campo] = 0;
      });

      const ofertaFinal = data.tiene_oferta_valor ? data.oferta_valor : null;

      const temarioLimpio = (data.contenido_tematico || [])
        .filter((m) => m && m.tema && typeof m.tema === "string" && m.tema.trim() !== "")
        .map((m) => ({
          ...m,
          subtemas: (m.subtemas || []).filter(
            (s) => s && typeof s === "string" && s.trim() !== ""
          ),
        }));

      const formatToDB = (dateStr) =>
        dateStr ? dateStr.replace("T", " ") + ":00" : null;

      const start = formatToDB(data.fecha_hora_inicio);
      const end = formatToDB(data.fecha_hora_fin) || start;

      return {
        ...data,
        _method: form.id ? "put" : "post",
        fecha_hora_inicio: start,
        fecha_hora_fin: end,
        oferta_valor: ofertaFinal,
        contenido_tematico: temarioLimpio,
      };
    })
    .post(url, {
      forceFormData: true,
      onSuccess: () => {
        emit("success");
        emit("close");
        form.reset();
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

const toggleSubtemas = (modulo) => {
  if (modulo.subtemas.length > 0) {
    modulo.subtemas = [];
  } else {
    modulo.subtemas.push("");
  }
};
</script>

<template>
  <BaseStepperModal
    :show="show"
    :title="
      mode === 'edit'
        ? 'Editar Publicación'
        : mode === 'duplicate'
        ? 'Duplicar Evento'
        : 'Nueva Publicación'
    "
    :totalSteps="4"
    :icon="mode === 'edit' ? Save : Globe"
    :activeColor="selectedArea.color_hex_principal"
    :loading="form.processing"
    @close="emit('close')"
    @submit="submit"
  >
    <template #default="{ currentStep }">
      <div class="flex flex-col lg:flex-row gap-8 h-full">
        <div class="w-full space-y-6">
          <div class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
            <div v-if="currentStep === 1" class="space-y-5 animate-in">
              <h4 class="text-[14px] font-medium text-slate-400 flex items-center gap-2">
                <Info class="w-3 h-3" /> Identidad del Evento
              </h4>

              <div class="flex justify-between gap-4">
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
            </div>

            <div v-if="currentStep === 2" class="space-y-5 animate-in">
              <h4
                class="text-[10px] font-black uppercase text-slate-400 tracking-widest flex items-center gap-2"
              >
                <Calendar class="w-3 h-3" /> Agenda y Costos
              </h4>

              <div class="flex justify-between gap-4">
                <FormInput
                  label="Inicio de Jornada"
                  type="datetime-local"
                  v-model="form.fecha_hora_inicio"
                  :error="form.errors.fecha_hora_inicio"
                  icon="calendar_clock"
                  :activeColor="selectedArea.color_hex_principal"
                  required
                />
                <FormInput
                  label="Fin de Jornada"
                  type="datetime-local"
                  v-model="form.fecha_hora_fin"
                  :error="form.errors.fecha_hora_fin"
                  icon="event_busy"
                  :activeColor="selectedArea.color_hex_principal"
                  required
                />
              </div>

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
              <div class="flex items-center justify-between gap-4">
                <FormInput
                  label="Tipo de Evento"
                  type="select"
                  v-model="form.tipo_evento"
                  :options="[
                    'JORNADA',
                    'MODULO',
                    'CNG',
                    'CURSO_INTENSIVO',
                    'DIPLOMADO',
                    'CI_CNG',
                    'JOR_MOD',
                  ]"
                  icon="category"
                  :activeColor="selectedArea.color_hex_principal"
                  :error="form.errors.tipo_evento"
                  required
                />

                <FormInput
                  v-if="camposPrecioActivos.includes('precio_jornada')"
                  label="Precio Jornada"
                  type="number"
                  v-model="form.precio_jornada"
                  icon="payments"
                  :error="form.errors.precio_jornada"
                  required
                />
                <FormInput
                  v-if="camposPrecioActivos.includes('precio_modulo')"
                  label="Precio Módulo"
                  type="number"
                  v-model="form.precio_modulo"
                  icon="payments"
                  :error="form.errors.precio_modulo"
                  required
                />
                <FormInput
                  v-if="camposPrecioActivos.includes('precio_cng')"
                  label="Precio CNG"
                  type="number"
                  v-model="form.precio_cng"
                  icon="payments"
                  :error="form.errors.precio_cng"
                  required
                />
                <FormInput
                  v-if="camposPrecioActivos.includes('precio_curso_intensivo')"
                  label="Precio Curso Intensivo"
                  type="number"
                  v-model="form.precio_curso_intensivo"
                  icon="payments"
                  :error="form.errors.precio_curso_intensivo"
                  required
                />
                <FormInput
                  v-if="camposPrecioActivos.includes('precio_diplomado')"
                  label="Precio Diplomado"
                  type="number"
                  v-model="form.precio_diplomado"
                  icon="payments"
                  :error="form.errors.precio_diplomado"
                  required
                />
              </div>
            </div>

            <div v-if="currentStep === 3" class="space-y-6 animate-in">
              <p
                v-if="form.errors.contenido_tematico"
                class="text-[11px] font-bold text-red-500 mb-2"
              >
                {{ form.errors.contenido_tematico }}
              </p>
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
                class="max-h-[450px] overflow-y-auto pr-2 custom-scroll space-y-4 py-2"
              >
                <div
                  v-for="(modulo, ti) in form.contenido_tematico"
                  :key="ti"
                  class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm transition-all"
                >
                  <div class="flex items-center gap-3 mb-4">
                    <div
                      class="w-8 h-8 rounded-full flex items-center justify-center text-[10px] font-black text-white"
                      :style="{ backgroundColor: selectedArea.color_hex_principal }"
                    >
                      {{ ti + 1 }}
                    </div>
                    <input
                      v-model="modulo.tema"
                      placeholder="Nombre del módulo..."
                      class="flex-1 border-none p-0 text-sm font-black text-slate-800 placeholder:text-slate-300 focus:ring-0"
                    />
                    <button
                      @click="removeTema(ti)"
                      class="text-slate-300 hover:text-red-500 transition-colors"
                    >
                      <Trash2 class="w-4 h-4" />
                    </button>
                  </div>
                  <p
                    v-if="form.errors[`contenido_tematico.${ti}.tema`]"
                    class="text-[10px] font-bold text-red-500 ml-11 mb-3"
                  >
                    {{ form.errors[`contenido_tematico.${ti}.tema`] }}
                  </p>

                  <div class="pl-11">
                    <button
                      type="button"
                      @click="toggleSubtemas(modulo)"
                      class="text-[10px] font-bold uppercase tracking-widest flex items-center gap-2 transition-colors"
                      :class="
                        modulo.subtemas?.length > 0
                          ? 'text-rose-600'
                          : 'text-slate-400 hover:text-slate-600'
                      "
                    >
                      <Plus class="w-3 h-3" />
                      {{
                        modulo.subtemas?.length > 0
                          ? "Ocultar subtemas"
                          : "Agregar subtemas"
                      }}
                    </button>

                    <div
                      v-if="modulo.subtemas?.length > 0"
                      class="mt-3 space-y-2 animate-in fade-in slide-in-from-top-2"
                    >
                      <div
                        v-for="(sub, si) in modulo.subtemas"
                        :key="si"
                        class="flex items-center gap-2 group"
                      >
                        <div
                          class="w-1.5 h-1.5 rounded-full"
                          :style="{ backgroundColor: selectedArea.color_hex_principal }"
                        ></div>
                        <input
                          v-model="modulo.subtemas[si]"
                          placeholder="Escribe un subtema..."
                          class="flex-1 bg-slate-50 border-none text-[12px] p-2 rounded-lg text-slate-600 focus:ring-1 focus:ring-slate-200"
                        />
                        <button
                          @click="removeSubtema(ti, si)"
                          class="text-slate-300 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-opacity"
                        >
                          <X class="w-3 h-3" />
                        </button>
                      </div>

                      <button
                        type="button"
                        @click="addSubtema(ti)"
                        class="text-[10px] font-bold text-slate-400 hover:text-blue-600 flex items-center gap-1 mt-2 pl-4"
                      >
                        <Plus class="w-3 h-3" /> Añadir otro punto
                      </button>
                    </div>
                  </div>
                </div>
              </div>

              <h4
                class="text-[10px] font-black uppercase text-slate-400 tracking-widest pt-2"
              >
                Expertos Asignados
              </h4>
              <p
                v-if="form.errors.conferencistas"
                class="text-[11px] font-bold text-red-500 mb-2"
              >
                {{ form.errors.conferencistas }}
              </p>
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
                    <br />
                    <p
                      class="text-[11px] text-slate-700 truncate"
                      :style="{ color: selectedArea.color_hex_principal }"
                    >
                      {{ s.area_encargada.nombre }}
                    </p>
                  </span>
                </div>
              </div>

              <h4
                class="text-[10px] font-black uppercase text-slate-400 tracking-widest pt-2"
              >
                Oferta de valor
              </h4>

              <div class="bg-slate-50 p-6 rounded-3xl border border-slate-100 space-y-4">
                <div class="flex items-center justify-between">
                  <div>
                    <h4
                      class="text-[11px] font-black text-slate-800 uppercase tracking-widest"
                    >
                      ¿Tiene oferta de valor?
                    </h4>
                    <p class="text-[10px] text-slate-400 font-medium">
                      Activa para destacar un beneficio especial.
                    </p>
                  </div>

                  <button
                    type="button"
                    @click="form.tiene_oferta_valor = !form.tiene_oferta_valor"
                    class="w-12 h-6 rounded-full p-1 transition-all duration-300 flex items-center"
                    :class="
                      form.tiene_oferta_valor
                        ? 'bg-emerald-500 justify-end'
                        : 'bg-slate-200 justify-start'
                    "
                  >
                    <div class="w-4 h-4 rounded-full bg-white shadow-sm"></div>
                  </button>
                </div>

                <div
                  v-if="form.tiene_oferta_valor"
                  class="animate-in fade-in slide-in-from-top-2"
                >
                  <FormInput
                    label="Descripción de la Oferta"
                    type="text"
                    v-model="form.oferta_valor"
                    icon="stars"
                    placeholder="Ej: 20% de descuento por pronto pago..."
                    :activeColor="selectedArea.color_hex_principal"
                    :max="100"
                    required
                  />
                </div>
              </div>
            </div>

           <div v-if="currentStep === 4" class="space-y-6 animate-in">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
              <h4 class="text-[14px] font-medium text-slate-400 flex items-center gap-2">
                <Palette class="w-4 h-4" /> Diseño y Anexos
              </h4>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 p-2">
              
              <div 
                class="relative overflow-hidden p-6 rounded-3xl border-2 border-dashed flex flex-col items-center justify-center min-h-[160px] transition-all duration-300 group cursor-pointer hover:shadow-sm"
                :class="previewImageUrl ? 'border-emerald-300 bg-emerald-50/50' : 'border-slate-200 bg-slate-50/50 hover:bg-slate-50 hover:border-slate-300'"
              >
                <img 
                  v-if="previewImageUrl" 
                  :src="previewImageUrl" 
                  class="absolute inset-0 w-full h-full object-cover opacity-15 mix-blend-multiply transition-opacity group-hover:opacity-25" 
                />
                
                <input
                  type="file"
                  @input="form.imagen_relacionada = $event.target.files[0]"
                  id="banner-up"
                  class="hidden"
                  accept="image/*"
                />
                <label for="banner-up" class="cursor-pointer flex flex-col items-center relative z-10 w-full h-full justify-center">
                  <div 
                    class="w-12 h-12 rounded-full shadow-sm flex items-center justify-center mb-3 transition-colors"
                    :class="previewImageUrl ? 'bg-emerald-100 text-emerald-600' : 'bg-white text-slate-400 group-hover:text-slate-600 group-hover:scale-105'"
                  >
                    <Palette class="w-5 h-5" />
                  </div>
                  <span 
                    class="text-xs font-black uppercase tracking-widest text-center" 
                    :class="previewImageUrl ? 'text-emerald-700' : 'text-slate-600'"
                  >
                    {{ previewImageUrl ? "Cambiar Banner" : "Subir Banner Principal" }}
                  </span>
                  <span class="text-[10px] text-slate-400 font-medium mt-1">Recomendado: 1920x1080px</span>
                </label>
              </div>

              <div class="space-y-5 bg-white p-5 rounded-3xl border border-slate-100 shadow-sm flex flex-col justify-center">
                <div>
                  <div class="flex items-center justify-between mb-2.5">
                    <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">
                      Diseño del Temario
                    </label>
                  </div>
                  <div class="flex bg-slate-50 p-1 rounded-2xl border border-slate-100">
                    <button 
                      @click="form.estilo_temario = 'lista'" 
                      type="button" 
                      :class="form.estilo_temario === 'lista' ? 'bg-white text-slate-800 shadow-sm ring-1 ring-slate-200' : 'text-slate-400 hover:text-slate-600'" 
                      class="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-xl text-[11px] font-bold transition-all"
                    >
                      <List class="w-3.5 h-3.5" /> Clásico
                    </button>
                    <button 
                      @click="form.estilo_temario = 'cuadricula'" 
                      type="button" 
                      :class="form.estilo_temario === 'cuadricula' ? 'bg-white text-slate-800 shadow-sm ring-1 ring-slate-200' : 'text-slate-400 hover:text-slate-600'" 
                      class="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-xl text-[11px] font-bold transition-all"
                    >
                      <Columns class="w-3.5 h-3.5" /> Columnas
                    </button>
                    <button 
                      @click="form.estilo_temario = 'tarjetas'" 
                      type="button" 
                      :class="form.estilo_temario === 'tarjetas' ? 'bg-white text-slate-800 shadow-sm ring-1 ring-slate-200' : 'text-slate-400 hover:text-slate-600'" 
                      class="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-xl text-[11px] font-bold transition-all"
                    >
                      <LayoutGrid class="w-3.5 h-3.5" /> Tarjetas
                    </button>
                  </div>
                </div>

                <div>
                  <div class="flex items-center justify-between mb-2.5">
                    <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">
                      Diseño del Equipo
                    </label>
                  </div>
                  <div class="flex bg-slate-50 p-1 rounded-2xl border border-slate-100">
                    <button 
                      @click="form.estilo_expertos = 'lista'" 
                      type="button" 
                      :class="form.estilo_expertos === 'lista' ? 'bg-white text-slate-800 shadow-sm ring-1 ring-slate-200' : 'text-slate-400 hover:text-slate-600'" 
                      class="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-xl text-[11px] font-bold transition-all"
                    >
                      <List class="w-3.5 h-3.5" /> Fila
                    </button>
                    <button 
                      @click="form.estilo_expertos = 'tarjetas'" 
                      type="button" 
                      :class="form.estilo_expertos === 'tarjetas' ? 'bg-white text-slate-800 shadow-sm ring-1 ring-slate-200' : 'text-slate-400 hover:text-slate-600'" 
                      class="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-xl text-[11px] font-bold transition-all"
                    >
                      <SquareSquare class="w-3.5 h-3.5" /> Cuadrícula
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2">
              <FormInput
                label="Url de inscripción"
                type="text"
                v-model="form.url_formulario_inscripcion"
                icon="link"
                placeholder="https://docs.google.com/..."
                :max="250"
                :activeColor="selectedArea.color_hex_principal"
                required
                :error="form.errors.url_formulario_inscripcion"
              />
              <FormInput
                label="Documento soporte (Brochure)"
                type="file"
                v-model="form.url_folleto"
                icon="upload_file"
                placeholder="Subir PDF descriptivo"
                :activeColor="selectedArea.color_hex_principal"
                required
                :error="form.errors.url_folleto"
              />
            </div>

            <FormInput
              label="Comercial encargado"
              type="select"
              v-model="form.organizador_id"
              :options="organizador"
              icon="people"
              :activeColor="selectedArea.color_hex_principal"
              required
              :error="form.errors.organizador_id"
            />
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
