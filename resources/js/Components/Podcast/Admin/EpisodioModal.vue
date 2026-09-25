<script setup>
import { ref, computed, watch, onBeforeUnmount } from "vue";
import { useForm } from "@inertiajs/vue3";
import axios from "axios";
import { X, ListVideo, Calendar, Clock, ExternalLink, Play, Star, Info, User } from "lucide-vue-next";
import FormInput from "@/Components/Shared/inputs/FormInput.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";
import { usePodcastAdmin } from "@/Composables/usePodcastAdmin";

const props = defineProps({
  show: { type: Boolean, default: false },
  mode: { type: String, default: "create" },
  episodio: { type: Object, default: null },
  temporadas: { type: Array, default: () => [] },
  estados: { type: Array, default: () => [] },
  // { [temporada_id]: último número usado } para sugerir el siguiente
  ultimosNumeros: { type: Object, default: () => ({}) },
  destacadoActual: { type: String, default: null },
});

const emit = defineEmits(["close", "success"]);

const { estadoClass, iniciales, idEstadoPorNombre, opcionesEstados } = usePodcastAdmin();

const COLOR = "#3f4e54";
const isEdit = computed(() => props.mode === "edit");

const form = useForm({
  temporada_id: "",
  numero: "",
  titulo: "",
  invitado_nombre: "",
  invitado_cargo: "",
  invitado_foto: null,
  descripcion: "",
  fecha_publicacion: "",
  youtube_url: "",
  imagen_miniatura: null,
  duracion_min: "",
  duracion_seg: "",
  destacado: false,
  estado_id: "",
});

const estadosOptions = computed(() => opcionesEstados(props.estados));
const temporadasOptions = computed(() =>
  props.temporadas.map((t) => ({ id: t.id, nombre: `Temporada ${t.numero} · ${t.titulo}` }))
);
const estadoBorradorId = computed(() => idEstadoPorNombre(props.estados, "Borrador"));
const temporadaSeleccionada = computed(() => props.temporadas.find((t) => t.id === form.temporada_id) ?? null);
const nombreEstado = computed(() => props.estados.find((e) => e.id === form.estado_id)?.tipo_estado ?? "Sin estado");

const codigoPreview = computed(() => {
  const t = temporadaSeleccionada.value?.numero ?? "?";
  const n = form.numero ? String(form.numero).padStart(2, "0") : "??";
  return `T${t}·E${n}`;
});

// ── Estado de previews y de YouTube (declarado antes de los watchers que lo usan) ──
const previewFoto = ref(null);
const previewMini = ref(null);
const usarObjectUrl = (refVar, file) => {
  if (refVar.value) URL.revokeObjectURL(refVar.value);
  refVar.value = file instanceof File ? URL.createObjectURL(file) : null;
};
const limpiarPreviews = () => {
  usarObjectUrl(previewFoto, null);
  usarObjectUrl(previewMini, null);
};

const yt = ref({ estado: "idle" });
const reproduciendo = ref(false);
let urlValidada = "";
let timerYt = null;
let solicitudYt = 0;

// ── Sugerir número siguiente al elegir temporada (solo al crear) ──────────────
let numeroSugerido = null;
watch(
  () => form.temporada_id,
  (id) => {
    if (isEdit.value || !id) return;
    const siguiente = String((props.ultimosNumeros[id] ?? 0) + 1);
    if (!form.numero || form.numero === numeroSugerido) form.numero = siguiente;
    numeroSugerido = siguiente;
  }
);

// ── Poblar / resetear al abrir ────────────────────────────────────────────────
watch(
  () => [props.show, props.mode, props.episodio],
  ([show]) => {
    if (!show) {
      reproduciendo.value = false;
      return;
    }
    form.clearErrors();
    limpiarPreviews();
    numeroSugerido = null;

    if (isEdit.value && props.episodio) {
      const e = props.episodio;
      form.temporada_id = e.temporada_id ?? "";
      form.numero = String(e.numero ?? "");
      form.titulo = e.titulo ?? "";
      form.invitado_nombre = e.invitado_nombre ?? "";
      form.invitado_cargo = e.invitado_cargo ?? "";
      form.invitado_foto = null;
      form.descripcion = e.descripcion ?? "";
      form.fecha_publicacion = e.fecha_publicacion ? String(e.fecha_publicacion).substring(0, 10) : "";
      form.youtube_url = e.youtube_url ?? "";
      form.imagen_miniatura = null;
      form.duracion_min = e.duracion_segundos ? String(Math.floor(e.duracion_segundos / 60)) : "";
      form.duracion_seg = e.duracion_segundos ? String(e.duracion_segundos % 60) : "";
      form.destacado = !!e.destacado;
      form.estado_id = e.estado_id ?? "";

      // El video ya fue validado por el backend: no hace falta volver a consultar
      yt.value = e.youtube_video_id
        ? {
            estado: "valido",
            video_id: e.youtube_video_id,
            watch_url: e.youtube_watch_url,
            embed_url: e.youtube_embed_url,
            thumbnail_url: `https://i.ytimg.com/vi/${e.youtube_video_id}/hqdefault.jpg`,
          }
        : { estado: "idle" };
      urlValidada = form.youtube_url;
    } else {
      form.reset();
      form.estado_id = estadoBorradorId.value;
      yt.value = { estado: "idle" };
      urlValidada = "";
    }
  },
  { immediate: true, deep: true }
);

// ── Previews de archivos ──────────────────────────────────────────────────────
watch(() => form.invitado_foto, (f) => usarObjectUrl(previewFoto, f));
watch(() => form.imagen_miniatura, (f) => usarObjectUrl(previewMini, f));

const fotoExistente = computed(() => (isEdit.value ? props.episodio?.invitado_foto_url ?? null : null));
const fotoVisible = computed(() => previewFoto.value ?? fotoExistente.value);

const miniaturaExistente = computed(() =>
  isEdit.value && props.episodio?.imagen_miniatura ? props.episodio.miniatura_url : null
);
const miniaturaVisible = computed(() => previewMini.value ?? miniaturaExistente.value ?? yt.value.thumbnail_url ?? null);
const origenMiniatura = computed(() => {
  if (previewMini.value) return "Miniatura personalizada (nueva)";
  if (miniaturaExistente.value) return "Miniatura personalizada";
  if (yt.value.estado === "valido") return "Miniatura de YouTube";
  return "Sin video todavía";
});

// ── YouTube: validación con debounce contra el endpoint interno ───────────────
watch(
  () => form.youtube_url,
  (url) => {
    clearTimeout(timerYt);
    reproduciendo.value = false;
    const limpia = (url || "").trim();

    if (!limpia) {
      yt.value = { estado: "idle" };
      urlValidada = "";
      return;
    }
    if (limpia === urlValidada && yt.value.estado === "valido") return;

    yt.value = { estado: "validando" };
    timerYt = setTimeout(() => validarYoutube(limpia), 500);
  }
);

const validarYoutube = async (url) => {
  const id = ++solicitudYt;
  try {
    const { data } = await axios.post(route("podcast.episodios.validar-youtube"), { url });
    if (id !== solicitudYt) return;
    yt.value = { estado: "valido", ...data };
    urlValidada = url;
    form.clearErrors("youtube_url");
  } catch (error) {
    if (id !== solicitudYt) return;
    yt.value = { estado: "invalido", mensaje: "No pudimos reconocer este enlace de YouTube." };
  }
};

const embedAutoplay = computed(() => (yt.value.embed_url ? `${yt.value.embed_url}&autoplay=1` : ""));

// ── Destacado ─────────────────────────────────────────────────────────────────
const avisoDestacado = computed(() => {
  if (!form.destacado || !props.destacadoActual) return null;
  if (isEdit.value && props.episodio?.destacado) return null;
  return `Actualmente el destacado es ${props.destacadoActual}. Al guardar, este episodio lo reemplazará.`;
});

// ── Cerrar / enviar ───────────────────────────────────────────────────────────
const cerrar = () => {
  if (form.processing) return;
  clearTimeout(timerYt);
  form.clearErrors();
  emit("close");
};

const submit = () => {
  if (form.processing) return;

  const transformar = (data) => {
    const min = parseInt(data.duracion_min, 10);
    const seg = parseInt(data.duracion_seg, 10);
    const total = (Number.isFinite(min) ? min * 60 : 0) + (Number.isFinite(seg) ? seg : 0);
    const { duracion_min, duracion_seg, ...resto } = data;
    return {
      ...resto,
      duracion_segundos: data.duracion_min === "" && data.duracion_seg === "" ? null : total,
      destacado: data.destacado ? 1 : 0,
      ...(isEdit.value ? { _method: "PUT" } : {}),
    };
  };

  const opciones = {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      limpiarPreviews();
      emit("success");
      emit("close");
    },
  };

  if (isEdit.value && props.episodio) {
    form.transform(transformar).post(route("podcast.episodios.update", props.episodio.id), opciones);
  } else {
    form.transform(transformar).post(route("podcast.episodios.store"), opciones);
  }
};

onBeforeUnmount(() => {
  clearTimeout(timerYt);
  limpiarPreviews();
});
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      leave-active-class="transition duration-150 ease-in"
      leave-to-class="opacity-0"
    >
      <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" @click="cerrar" />

        <Transition
          enter-active-class="transition duration-200 ease-out"
          enter-from-class="opacity-0 scale-95 translate-y-2"
          enter-to-class="opacity-100 scale-100 translate-y-0"
          leave-active-class="transition duration-150 ease-in"
          leave-from-class="opacity-100 scale-100 translate-y-0"
          leave-to-class="opacity-0 scale-95 translate-y-2"
        >
          <div
            v-if="show"
            class="relative z-50 w-full max-w-6xl bg-white rounded-[2rem] sm:rounded-[2.5rem] shadow-2xl overflow-hidden flex flex-col lg:flex-row max-h-[92dvh]"
          >
            <!-- ── Formulario ── -->
            <div class="lg:w-3/5 flex flex-col min-h-0 lg:border-r border-slate-100">
              <div class="px-6 sm:px-8 py-5 sm:py-6 border-b border-slate-100 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3 min-w-0">
                  <div class="w-10 h-10 bg-podcast-oscuro rounded-2xl flex items-center justify-center shadow-md shrink-0">
                    <ListVideo class="w-5 h-5 text-podcast-acento" />
                  </div>
                  <div class="min-w-0">
                    <h3 class="text-lg sm:text-xl font-black text-slate-900 truncate">
                      {{ isEdit ? "Editar episodio" : "Nuevo episodio" }}
                      <span class="text-slate-300 font-bold">· {{ codigoPreview }}</span>
                    </h3>
                    <p class="text-xs font-medium text-slate-400">Podcast · Íntimamente Hablando</p>
                  </div>
                </div>
                <button type="button" @click="cerrar" class="w-9 h-9 flex items-center justify-center rounded-2xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-all" aria-label="Cerrar">
                  <X class="w-5 h-5" />
                </button>
              </div>

              <div class="flex-grow overflow-y-auto px-6 sm:px-8 py-6 space-y-6">
                <!-- Ubicación -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <div class="sm:col-span-2">
                    <FormInput
                      v-model="form.temporada_id"
                      label="Temporada"
                      type="select"
                      icon="layers"
                      placeholder="Seleccione temporada..."
                      :options="temporadasOptions"
                      :required="true"
                      :activeColor="COLOR"
                      :error="form.errors.temporada_id"
                      @clearError="form.clearErrors('temporada_id')"
                    />
                  </div>
                  <FormInput
                    v-model="form.numero"
                    label="Episodio N.º"
                    type="number"
                    icon="tag"
                    placeholder="1"
                    :max="5"
                    :required="true"
                    :activeColor="COLOR"
                    :error="form.errors.numero"
                    @clearError="form.clearErrors('numero')"
                  />
                </div>

                <FormInput
                  v-model="form.titulo"
                  label="Título del episodio"
                  type="text"
                  icon="title"
                  placeholder="Ej: Tres décadas moldeando el presupuesto público"
                  :max="220"
                  :required="true"
                  :activeColor="COLOR"
                  :error="form.errors.titulo"
                  @clearError="form.clearErrors('titulo')"
                />

                <!-- YouTube -->
                <div class="space-y-3">
                  <FormInput
                    v-model="form.youtube_url"
                    label="Enlace del video en YouTube"
                    type="url"
                    icon="smart_display"
                    placeholder="https://www.youtube.com/watch?v=… o https://youtu.be/…"
                    :max="500"
                    :required="true"
                    :activeColor="COLOR"
                    :error="form.errors.youtube_url"
                    @clearError="form.clearErrors('youtube_url')"
                  />

                  <!-- Preview de YouTube -->
                  <div v-if="yt.estado === 'validando'" class="flex items-center gap-3 px-4 py-3 bg-slate-50 rounded-2xl text-xs font-semibold text-slate-500">
                    <span class="w-4 h-4 border-2 border-slate-300 border-t-podcast-oscuro rounded-full animate-spin"></span>
                    Comprobando el enlace…
                  </div>

                  <div v-else-if="yt.estado === 'invalido'" class="flex items-start gap-3 px-4 py-3 bg-red-50 rounded-2xl text-xs font-semibold text-red-600">
                    <span class="material-symbols-rounded text-lg leading-none">error</span>
                    <span>{{ yt.mensaje }} Acepta enlaces watch?v=, youtu.be, shorts y live.</span>
                  </div>

                  <div v-else-if="yt.estado === 'valido'" class="rounded-[1.5rem] overflow-hidden border border-slate-200/70 bg-slate-900">
                    <!-- Fachada: miniatura + play; el iframe (youtube-nocookie) solo se monta al pulsar -->
                    <div class="relative aspect-video bg-black">
                      <iframe
                        v-if="reproduciendo"
                        :src="embedAutoplay"
                        class="absolute inset-0 w-full h-full"
                        title="Vista previa del video"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                        referrerpolicy="strict-origin-when-cross-origin"
                        allowfullscreen
                      ></iframe>
                      <button
                        v-else
                        type="button"
                        @click="reproduciendo = true"
                        class="group absolute inset-0 w-full h-full focus:outline-none"
                        aria-label="Reproducir vista previa"
                      >
                        <img :src="yt.thumbnail_url" alt="" class="absolute inset-0 w-full h-full object-cover opacity-90 group-hover:opacity-100 transition-opacity" />
                        <span class="absolute inset-0 flex items-center justify-center">
                          <span class="w-14 h-14 rounded-full bg-podcast-acento text-podcast-oscuro flex items-center justify-center shadow-xl shadow-black/30 group-hover:scale-110 transition-transform">
                            <Play class="w-6 h-6 translate-x-[1px]" fill="currentColor" />
                          </span>
                        </span>
                      </button>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                      <p class="text-[11px] font-semibold text-slate-400">
                        Video reconocido · ID <span class="font-mono text-slate-200">{{ yt.video_id }}</span>
                      </p>
                      <a :href="yt.watch_url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-[11px] font-bold text-podcast-acento hover:text-white transition-colors">
                        Abrir en YouTube <ExternalLink class="w-3.5 h-3.5" />
                      </a>
                    </div>
                  </div>
                </div>

                <!-- Invitado -->
                <div class="p-5 bg-slate-50 rounded-[1.5rem] border border-slate-100 space-y-4">
                  <div class="flex items-center gap-4">
                    <div class="w-16 h-16 rounded-full overflow-hidden bg-podcast-oscuro text-white flex items-center justify-center shrink-0 ring-4 ring-white shadow-md">
                      <img v-if="fotoVisible" :src="fotoVisible" alt="" class="w-full h-full object-cover" />
                      <span v-else-if="form.invitado_nombre" class="text-lg font-black">{{ iniciales(form.invitado_nombre) }}</span>
                      <User v-else class="w-6 h-6 text-slate-300" />
                    </div>
                    <div class="min-w-0">
                      <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Invitado</p>
                      <p class="text-sm font-bold text-slate-800 truncate">{{ form.invitado_nombre || "Nombre del invitado" }}</p>
                      <p class="text-xs text-slate-500 truncate">{{ form.invitado_cargo || "Cargo o rol (opcional)" }}</p>
                    </div>
                  </div>
                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <FormInput
                      v-model="form.invitado_nombre"
                      label="Nombre"
                      type="text"
                      icon="person"
                      placeholder="Nombre completo"
                      :max="150"
                      :required="true"
                      :activeColor="COLOR"
                      :error="form.errors.invitado_nombre"
                      @clearError="form.clearErrors('invitado_nombre')"
                    />
                    <FormInput
                      v-model="form.invitado_cargo"
                      label="Cargo (opcional)"
                      type="text"
                      icon="work"
                      placeholder="Ej: Consultora en contratación"
                      :max="200"
                      :activeColor="COLOR"
                      :error="form.errors.invitado_cargo"
                      @clearError="form.clearErrors('invitado_cargo')"
                    />
                  </div>
                  <div>
                    <FormInput
                      v-model="form.invitado_foto"
                      label="Foto (opcional)"
                      type="file"
                      icon="account_circle"
                      placeholder="Seleccionar foto (JPG, PNG o WEBP · máx. 4 MB)"
                      :activeColor="COLOR"
                      :error="form.errors.invitado_foto"
                      @clearError="form.clearErrors('invitado_foto')"
                    />
                    <p v-if="fotoExistente && !previewFoto" class="text-[11px] font-medium text-slate-400 mt-2 px-1">
                      Ya existe una foto. Selecciona un archivo solo si deseas reemplazarla.
                    </p>
                  </div>
                </div>

                <FormInput
                  v-model="form.descripcion"
                  label="Descripción"
                  type="textarea"
                  icon="notes"
                  placeholder="De qué trata la conversación..."
                  :max="5000"
                  :rows="5"
                  :required="true"
                  :activeColor="COLOR"
                  :error="form.errors.descripcion"
                  @clearError="form.clearErrors('descripcion')"
                />

                <!-- Fecha y duración -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div class="space-y-2">
                    <label class="text-[13px] font-bold text-slate-400 px-1 block">
                      Fecha de publicación <span class="text-[10px] font-medium ml-1">(opcional)</span>
                    </label>
                    <div class="relative flex items-center gap-2 px-4 py-3 bg-slate-50 border-2 border-slate-200 rounded-xl transition-all focus-within:border-podcast-oscuro focus-within:bg-white focus-within:shadow-lg">
                      <Calendar class="w-5 h-5 text-slate-300 shrink-0" />
                      <input v-model="form.fecha_publicacion" type="date" @input="form.clearErrors('fecha_publicacion')" class="w-full bg-transparent border-none p-0 focus:ring-0 outline-none font-semibold text-slate-700 text-sm" />
                    </div>
                    <p v-if="form.errors.fecha_publicacion" class="text-[12px] text-red-600 font-medium flex items-center gap-1 px-1">
                      <span class="material-symbols-rounded text-sm">warning</span> {{ form.errors.fecha_publicacion }}
                    </p>
                  </div>

                  <div class="space-y-2">
                    <label class="text-[13px] font-bold text-slate-400 px-1 block">
                      Duración <span class="text-[10px] font-medium ml-1">(opcional)</span>
                    </label>
                    <div class="relative flex items-center gap-2 px-4 py-3 bg-slate-50 border-2 border-slate-200 rounded-xl transition-all focus-within:border-podcast-oscuro focus-within:bg-white focus-within:shadow-lg">
                      <Clock class="w-5 h-5 text-slate-300 shrink-0" />
                      <input v-model="form.duracion_min" type="number" min="0" max="1440" placeholder="min" @input="form.clearErrors('duracion_segundos')" class="w-full bg-transparent border-none p-0 focus:ring-0 outline-none font-semibold text-slate-700 text-sm" />
                      <span class="text-xs font-bold text-slate-300">min</span>
                      <input v-model="form.duracion_seg" type="number" min="0" max="59" placeholder="seg" class="w-16 bg-transparent border-none p-0 focus:ring-0 outline-none font-semibold text-slate-700 text-sm" />
                      <span class="text-xs font-bold text-slate-300">s</span>
                    </div>
                    <p v-if="form.errors.duracion_segundos" class="text-[12px] text-red-600 font-medium flex items-center gap-1 px-1">
                      <span class="material-symbols-rounded text-sm">warning</span> {{ form.errors.duracion_segundos }}
                    </p>
                  </div>
                </div>

                <!-- Miniatura -->
                <div>
                  <FormInput
                    v-model="form.imagen_miniatura"
                    label="Miniatura personalizada (opcional)"
                    type="file"
                    icon="image"
                    placeholder="Seleccionar imagen 16:9 (JPG, PNG o WEBP · máx. 4 MB)"
                    :activeColor="COLOR"
                    :error="form.errors.imagen_miniatura"
                    @clearError="form.clearErrors('imagen_miniatura')"
                  />
                  <p class="text-[11px] font-medium text-slate-400 mt-2 px-1">
                    Si no subes una miniatura, utilizaremos automáticamente la imagen del video de YouTube.
                  </p>
                </div>

                <!-- Destacado + estado -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div class="p-4 rounded-2xl border-2 transition-all" :class="form.destacado ? 'border-podcast-acento bg-podcast-acento/10' : 'border-slate-200 bg-slate-50'">
                    <label class="flex items-start gap-3 cursor-pointer">
                      <button
                        type="button"
                        role="switch"
                        :aria-checked="form.destacado"
                        @click="form.destacado = !form.destacado"
                        class="relative shrink-0 w-11 h-6 rounded-full transition-colors mt-0.5"
                        :class="form.destacado ? 'bg-podcast-oscuro' : 'bg-slate-300'"
                      >
                        <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform" :class="{ 'translate-x-5': form.destacado }"></span>
                      </button>
                      <span class="min-w-0">
                        <span class="flex items-center gap-1.5 text-sm font-bold text-slate-800">
                          <Star class="w-4 h-4" :class="form.destacado ? 'text-podcast-oscuro' : 'text-slate-400'" :fill="form.destacado ? '#c2c027' : 'none'" />
                          Marcar como episodio destacado
                        </span>
                        <span class="block text-[11px] text-slate-500 mt-1 leading-relaxed">
                          Solo puede existir un episodio destacado. Al guardar, este reemplazará al destacado actual.
                        </span>
                      </span>
                    </label>
                    <p v-if="avisoDestacado" class="mt-3 flex items-start gap-2 text-[11px] font-semibold text-amber-700 bg-amber-50 rounded-xl px-3 py-2">
                      <Info class="w-3.5 h-3.5 shrink-0 mt-0.5" /> {{ avisoDestacado }}
                    </p>
                  </div>

                  <FormInput
                    v-model="form.estado_id"
                    label="Estado"
                    type="select"
                    icon="toggle_on"
                    placeholder="Seleccione estado..."
                    :options="estadosOptions"
                    :required="true"
                    :activeColor="COLOR"
                    :error="form.errors.estado_id"
                    @clearError="form.clearErrors('estado_id')"
                  />
                </div>

                <p v-if="form.errors.general" class="text-[13px] text-red-600 font-medium bg-red-50 rounded-xl p-3">
                  {{ form.errors.general }}
                </p>
              </div>

              <div class="px-6 sm:px-8 py-4 sm:py-5 border-t border-slate-100 bg-slate-50/50 flex items-center justify-between gap-4 shrink-0">
                <button type="button" @click="cerrar" :disabled="form.processing" class="text-sm font-bold text-slate-500 hover:text-slate-800 transition-colors px-4 py-2 rounded-xl hover:bg-slate-100 disabled:opacity-50">
                  Cancelar
                </button>
                <BtnUniversal
                  :label="isEdit ? 'Guardar cambios' : 'Crear episodio'"
                  icon="save"
                  icon-position="right"
                  size="md"
                  :activeColor="COLOR"
                  :loading="form.processing"
                  :disabled="form.processing"
                  process="Guardando..."
                  class="sm:w-auto"
                  @click="submit"
                />
              </div>
            </div>

            <!-- ── Vista previa (escritorio) ── -->
            <div class="hidden lg:flex lg:w-2/5 bg-slate-50 flex-col min-h-0">
              <div class="px-6 pt-6 pb-3 shrink-0">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Vista previa · card pública</p>
              </div>
              <div class="flex-grow overflow-y-auto px-6 pb-6 space-y-4">
                <div class="rounded-[2rem] overflow-hidden shadow-xl border border-slate-200/60 bg-white">
                  <div class="relative aspect-video bg-podcast-oscuro overflow-hidden">
                    <img v-if="miniaturaVisible" :src="miniaturaVisible" alt="" class="absolute inset-0 w-full h-full object-cover" />
                    <div v-else class="absolute inset-0 opacity-[0.08]" style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 24px 24px"></div>
                    <span class="absolute inset-0 flex items-center justify-center">
                      <span class="w-12 h-12 rounded-full bg-podcast-acento text-podcast-oscuro flex items-center justify-center shadow-lg shadow-black/20">
                        <Play class="w-5 h-5 translate-x-[1px]" fill="currentColor" />
                      </span>
                    </span>
                    <span v-if="form.destacado" class="absolute top-3 left-3 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-black/40 backdrop-blur-md border border-white/10 text-white text-[9px] font-black uppercase tracking-widest">
                      <span class="w-1.5 h-1.5 rounded-full bg-podcast-acento"></span> Destacado
                    </span>
                    <span class="absolute bottom-3 right-3 px-2 py-1 rounded-lg bg-black/50 backdrop-blur-md text-white text-[10px] font-bold">
                      {{ origenMiniatura }}
                    </span>
                  </div>
                  <div class="p-5">
                    <div class="flex items-center gap-2 mb-2">
                      <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">{{ codigoPreview }}</span>
                      <span class="px-2 py-0.5 rounded-lg text-[9px] font-black uppercase tracking-widest" :class="estadoClass(nombreEstado)">{{ nombreEstado }}</span>
                    </div>
                    <h3 class="text-base font-bold text-slate-900 leading-snug line-clamp-2 mb-1" :class="{ 'text-slate-300': !form.titulo }">
                      {{ form.titulo || "Título del episodio" }}
                    </h3>
                    <p class="text-xs text-slate-500">
                      con <span class="font-semibold text-slate-700">{{ form.invitado_nombre || "invitado" }}</span>
                    </p>
                  </div>
                </div>

                <div class="p-4 bg-white rounded-2xl border border-slate-200/60 space-y-2">
                  <p class="text-[11px] font-semibold text-slate-500 leading-relaxed">
                    El público solo ve episodios en estado <span class="font-black text-emerald-600">Activo</span> dentro de temporadas activas.
                  </p>
                  <p class="text-[11px] font-semibold text-slate-500 leading-relaxed">
                    El video se reproduce desde YouTube (modo privacidad mejorada); F&amp;C no aloja el archivo.
                  </p>
                </div>
              </div>
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>
