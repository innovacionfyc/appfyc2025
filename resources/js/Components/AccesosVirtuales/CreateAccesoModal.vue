<script setup>
import { ref, computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import {
  X,
  Video,
  Calendar,
  Clock,
  Link,
  ExternalLink,
  Image,
  Save,
} from "lucide-vue-next";
import FormInput from "@/Components/Shared/inputs/FormInput.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";

const props = defineProps({
  show:    { type: Boolean, default: false },
  mode:    { type: String,  default: "create" },
  acceso:  { type: Object,  default: null },
  estados: { type: Array,   default: () => [] },
});

const emit = defineEmits(["close", "success"]);

const isEdit = computed(() => props.mode === "edit");

// ── Formulario ────────────────────────────────────────────────────────────────
const form = useForm({
  nombre:        "",
  descripcion:   "",
  fecha:         "",
  hora:          "",
  url_zoom:      "",
  estado_id:     "",
  imagen_banner: null,
});

// Detectar estado "Activo" por nombre para selección por defecto
const estadoActivoId = computed(
  () => props.estados.find((e) => e.tipo_estado?.toLowerCase() === "activo")?.id ?? ""
);

const estadosOptions = computed(() =>
  props.estados.map((e) => ({ id: e.id, nombre: e.tipo_estado }))
);

// Poblar al abrir
watch(
  () => [props.show, props.mode, props.acceso],
  ([show]) => {
    if (!show) return;
    form.clearErrors();
    previewImageUrl.value = null;

    if (isEdit.value && props.acceso) {
      form.nombre        = props.acceso.nombre        ?? "";
      form.descripcion   = props.acceso.descripcion   ?? "";
      form.fecha         = props.acceso.fecha         ? String(props.acceso.fecha).substring(0, 10) : "";
      form.hora          = props.acceso.hora          ? String(props.acceso.hora).substring(0, 5)   : "";
      form.url_zoom      = props.acceso.url_zoom      ?? "";
      form.estado_id     = props.acceso.estado_id     ?? "";
      form.imagen_banner = null;
    } else {
      form.reset();
      form.estado_id = estadoActivoId.value;
    }
  },
  { immediate: true, deep: true }
);

// ── Preview de imagen ──────────────────────────────────────────────────────────
const previewImageUrl = ref(null);

watch(
  () => form.imagen_banner,
  (file) => {
    if (file instanceof File) {
      previewImageUrl.value = URL.createObjectURL(file);
    } else if (!file) {
      previewImageUrl.value = null;
    }
  }
);

const existingBannerUrl = computed(() =>
  isEdit.value && props.acceso?.imagen_banner_url
    ? props.acceso.imagen_banner_url
    : null
);

const displayBannerUrl = computed(
  () => previewImageUrl.value ?? existingBannerUrl.value ?? "/images/default-bg.webp"
);

// ── Fecha formateada para preview ──────────────────────────────────────────────
const previewFecha = computed(() => {
  if (!form.fecha) return null;
  const d = new Date(form.fecha + "T00:00:00");
  if (isNaN(d.getTime())) return null;
  return d.toLocaleDateString("es-CO", {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
  });
});

// ── Submit ────────────────────────────────────────────────────────────────────
const submit = () => {
  if (isEdit.value && props.acceso) {
    // PUT con spoofing para soportar multipart/form-data con archivo
    form.post(route("accesos-virtuales.update", props.acceso.id), {
      data: { ...form.data(), _method: "PUT" },
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => { emit("success"); emit("close"); },
    });
  } else {
    form.post(route("accesos-virtuales.store"), {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => {
        form.reset();
        form.estado_id = estadoActivoId.value;
        previewImageUrl.value = null;
        emit("success");
        emit("close");
      },
    });
  }
};
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      leave-active-class="transition duration-150 ease-in"
      leave-to-class="opacity-0"
    >
      <div
        v-if="show"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 overflow-y-auto"
        @click.self="emit('close')"
      >
        <!-- Fondo -->
        <div
          class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm"
          @click="emit('close')"
        />

        <!-- Panel -->
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
            class="relative z-50 w-full max-w-5xl bg-white rounded-[2.5rem] shadow-2xl overflow-hidden flex flex-col lg:flex-row max-h-[92vh]"
          >
            <!-- ── PANEL IZQUIERDO: formulario ── -->
            <div class="lg:w-3/5 flex flex-col overflow-hidden border-r border-slate-100">
              <!-- Header -->
              <div class="px-8 py-6 border-b border-slate-100 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3">
                  <div
                    class="w-10 h-10 bg-gradient-to-br from-primary-vinotinto to-secondary-vinotinto2 rounded-2xl flex items-center justify-center shadow-md"
                  >
                    <Video class="w-5 h-5 text-white" />
                  </div>
                  <div>
                    <h3 class="text-xl font-black text-slate-900">
                      {{ isEdit ? "Editar acceso virtual" : "Nuevo acceso virtual" }}
                    </h3>
                    <p class="text-xs font-medium text-slate-400">
                      {{ isEdit ? "Modifica los datos del acceso" : "Completa el formulario para crear una página de acceso" }}
                    </p>
                  </div>
                </div>
                <button
                  type="button"
                  @click="emit('close')"
                  class="w-9 h-9 flex items-center justify-center rounded-2xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-all"
                >
                  <X class="w-5 h-5" />
                </button>
              </div>

              <!-- Campos -->
              <div class="flex-grow overflow-y-auto px-8 py-6 space-y-5">
                <!-- Nombre -->
                <FormInput
                  v-model="form.nombre"
                  label="Nombre del evento o reunión"
                  type="text"
                  icon="event"
                  placeholder="Ej: Reunión de Seguimiento — Junio 2026"
                  :max="200"
                  :required="true"
                  activeColor="#7f1d1d"
                  :error="form.errors.nombre"
                  @clearError="form.clearErrors('nombre')"
                />

                <!-- Descripción -->
                <FormInput
                  v-model="form.descripcion"
                  label="Descripción (opcional)"
                  type="textarea"
                  icon="notes"
                  placeholder="Información adicional para los participantes..."
                  :max="500"
                  :rows="3"
                  activeColor="#7f1d1d"
                  :error="form.errors.descripcion"
                  @clearError="form.clearErrors('descripcion')"
                />

                <!-- Fecha y Hora -->
                <div class="grid grid-cols-2 gap-4">
                  <!-- Fecha -->
                  <div class="space-y-2">
                    <label class="text-[13px] font-bold text-slate-400 px-1 block">
                      Fecha
                      <span class="text-[10px] font-medium text-slate-400 ml-1">(opcional)</span>
                    </label>
                    <div
                      class="relative flex items-center gap-2 px-4 py-3 bg-slate-50 border-2 border-slate-200 rounded-xl transition-all focus-within:border-[#7f1d1d] focus-within:bg-white focus-within:shadow-lg"
                    >
                      <Calendar class="w-5 h-5 text-slate-300 shrink-0" />
                      <input
                        v-model="form.fecha"
                        type="date"
                        class="w-full bg-transparent border-none p-0 focus:ring-0 outline-none font-semibold text-slate-700 text-sm"
                      />
                    </div>
                    <p v-if="form.errors.fecha" class="text-[12px] text-red-600 font-medium flex items-center gap-1 px-1">
                      <span class="material-symbols-rounded text-sm">warning</span>
                      {{ form.errors.fecha }}
                    </p>
                  </div>

                  <!-- Hora -->
                  <div class="space-y-2">
                    <label class="text-[13px] font-bold text-slate-400 px-1 block">
                      Hora
                      <span class="text-[10px] font-medium text-slate-400 ml-1">(opcional)</span>
                    </label>
                    <div
                      class="relative flex items-center gap-2 px-4 py-3 bg-slate-50 border-2 border-slate-200 rounded-xl transition-all focus-within:border-[#7f1d1d] focus-within:bg-white focus-within:shadow-lg"
                    >
                      <Clock class="w-5 h-5 text-slate-300 shrink-0" />
                      <input
                        v-model="form.hora"
                        type="time"
                        class="w-full bg-transparent border-none p-0 focus:ring-0 outline-none font-semibold text-slate-700 text-sm"
                      />
                    </div>
                    <p v-if="form.errors.hora" class="text-[12px] text-red-600 font-medium flex items-center gap-1 px-1">
                      <span class="material-symbols-rounded text-sm">warning</span>
                      {{ form.errors.hora }}
                    </p>
                  </div>
                </div>

                <!-- URL Zoom -->
                <FormInput
                  v-model="form.url_zoom"
                  label="Link de la reunión virtual"
                  type="text"
                  icon="link"
                  placeholder="https://zoom.us/j/123456789"
                  :max="500"
                  :required="true"
                  activeColor="#7f1d1d"
                  :error="form.errors.url_zoom"
                  @clearError="form.clearErrors('url_zoom')"
                />

                <!-- Estado -->
                <FormInput
                  v-model="form.estado_id"
                  label="Estado del acceso"
                  type="select"
                  icon="toggle_on"
                  placeholder="Seleccione estado..."
                  :options="estadosOptions"
                  :required="true"
                  activeColor="#7f1d1d"
                  :error="form.errors.estado_id"
                  @clearError="form.clearErrors('estado_id')"
                />

                <!-- Banner -->
                <div>
                  <FormInput
                    v-model="form.imagen_banner"
                    label="Banner / imagen del acceso"
                    type="file"
                    icon="image"
                    placeholder="Seleccionar imagen (JPG, PNG o WEBP · máx. 4 MB)"
                    activeColor="#7f1d1d"
                    :error="form.errors.imagen_banner"
                    @clearError="form.clearErrors('imagen_banner')"
                  />
                  <p
                    v-if="isEdit && existingBannerUrl && !previewImageUrl"
                    class="text-[11px] font-medium text-slate-400 mt-2 px-1"
                  >
                    Ya existe un banner. Selecciona un nuevo archivo solo si deseas reemplazarlo.
                  </p>
                </div>

                <!-- Error general -->
                <p v-if="form.errors.general" class="text-[13px] text-red-600 font-medium bg-red-50 rounded-xl p-3">
                  {{ form.errors.general }}
                </p>
              </div>

              <!-- Footer acciones -->
              <div
                class="px-8 py-5 border-t border-slate-100 bg-slate-50/50 flex items-center justify-between gap-4 shrink-0"
              >
                <button
                  type="button"
                  @click="emit('close')"
                  class="text-sm font-bold text-slate-500 hover:text-slate-800 transition-colors px-4 py-2 rounded-xl hover:bg-slate-100"
                >
                  Cancelar
                </button>
                <BtnUniversal
                  :label="isEdit ? 'Guardar cambios' : 'Crear acceso virtual'"
                  icon="save"
                  icon-position="right"
                  size="md"
                  :loading="form.processing"
                  :disabled="form.processing"
                  process="Guardando..."
                  @click="submit"
                />
              </div>
            </div>

            <!-- ── PANEL DERECHO: vista previa ── -->
            <div class="lg:w-2/5 bg-slate-50 flex flex-col overflow-hidden">
              <div class="px-6 pt-6 pb-3 shrink-0">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">
                  Vista previa · página pública
                </p>
              </div>

              <div class="flex-grow overflow-y-auto px-6 pb-6">
                <!-- Card de preview -->
                <div
                  class="rounded-[2rem] overflow-hidden shadow-xl border border-slate-200/60 bg-white"
                >
                  <!-- Banner -->
                  <div class="relative h-44 bg-slate-900 overflow-hidden">
                    <img
                      :src="displayBannerUrl"
                      class="w-full h-full object-cover opacity-80 transition-all duration-500"
                    />
                    <div
                      class="absolute inset-0 bg-gradient-to-t from-slate-900/80 via-slate-900/20 to-transparent"
                    />
                    <!-- Logo FYC sobre banner -->
                    <div class="absolute top-4 left-4">
                      <div
                        class="px-3 py-1.5 bg-white/10 backdrop-blur-md rounded-xl border border-white/20"
                      >
                        <span class="text-white font-black text-xs tracking-wider">F&C Consultores</span>
                      </div>
                    </div>
                    <!-- Badge de estado -->
                    <div class="absolute top-4 right-4">
                      <span
                        class="px-2.5 py-1 rounded-lg text-[9px] font-black uppercase tracking-widest bg-emerald-500/20 text-emerald-300 ring-1 ring-emerald-500/30 backdrop-blur-md"
                      >
                        En línea
                      </span>
                    </div>
                  </div>

                  <!-- Contenido de la card -->
                  <div class="p-6">
                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 mb-2">
                      Acceso virtual
                    </p>

                    <!-- Nombre -->
                    <h3
                      class="text-xl font-black text-slate-900 leading-tight mb-4 min-h-[2.5rem]"
                      :class="{ 'text-slate-300': !form.nombre }"
                    >
                      {{ form.nombre || "Nombre del evento o reunión" }}
                    </h3>

                    <!-- Descripción -->
                    <p
                      v-if="form.descripcion"
                      class="text-sm text-slate-500 mb-4 line-clamp-2"
                    >
                      {{ form.descripcion }}
                    </p>

                    <!-- Fecha y hora -->
                    <div
                      v-if="previewFecha || form.hora"
                      class="flex items-center gap-4 mb-5 p-3 bg-slate-50 rounded-2xl"
                    >
                      <div v-if="previewFecha" class="flex items-center gap-2">
                        <Calendar class="w-4 h-4 text-slate-400 shrink-0" />
                        <span class="text-sm font-semibold text-slate-700 capitalize">
                          {{ previewFecha }}
                        </span>
                      </div>
                      <div v-if="form.hora" class="flex items-center gap-2 border-l border-slate-200 pl-4">
                        <Clock class="w-4 h-4 text-slate-400 shrink-0" />
                        <span class="text-sm font-semibold text-slate-700">
                          {{ form.hora }}
                        </span>
                      </div>
                    </div>
                    <div v-else class="mb-5 p-3 bg-slate-50 rounded-2xl text-[11px] text-slate-400 font-medium text-center">
                      Sin fecha ni hora definidas
                    </div>

                    <!-- Botón Entrar -->
                    <button
                      type="button"
                      class="w-full py-3.5 bg-gradient-to-br from-primary-vinotinto to-secondary-vinotinto2 text-white font-black rounded-2xl flex items-center justify-center gap-2 shadow-lg cursor-default"
                    >
                      <ExternalLink class="w-4 h-4" />
                      Entrar a la reunión
                    </button>

                    <!-- URL slug -->
                    <p class="text-center text-[10px] font-mono text-slate-400 mt-3">
                      {{ isEdit && props.acceso ? `/acceso/${props.acceso.slug}` : "/acceso/url-generada-automaticamente" }}
                    </p>
                  </div>
                </div>

                <!-- Nota informativa -->
                <div class="mt-4 p-4 bg-blue-50 rounded-2xl border border-blue-100">
                  <p class="text-[11px] font-semibold text-blue-600 leading-relaxed">
                    La URL pública se genera automáticamente al guardar y puede compartirse directamente con los participantes.
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
