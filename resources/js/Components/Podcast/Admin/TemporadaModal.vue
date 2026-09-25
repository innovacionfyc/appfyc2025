<script setup>
import { ref, computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { X, Layers, ImageOff } from "lucide-vue-next";
import FormInput from "@/Components/Shared/inputs/FormInput.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";
import { usePodcastAdmin } from "@/Composables/usePodcastAdmin";

const props = defineProps({
  show: { type: Boolean, default: false },
  mode: { type: String, default: "create" },
  temporada: { type: Object, default: null },
  estados: { type: Array, default: () => [] },
  siguienteNumero: { type: Number, default: 1 },
});

const emit = defineEmits(["close", "success"]);

const { estadoClass, idEstadoPorNombre, opcionesEstados } = usePodcastAdmin();

const COLOR = "#3f4e54";
const isEdit = computed(() => props.mode === "edit");

const form = useForm({
  numero: "",
  titulo: "",
  descripcion: "",
  estado_id: "",
  imagen_portada: null,
});

const estadosOptions = computed(() => opcionesEstados(props.estados));
const estadoBorradorId = computed(() => idEstadoPorNombre(props.estados, "Borrador"));
const nombreEstadoSeleccionado = computed(
  () => props.estados.find((e) => e.id === form.estado_id)?.tipo_estado ?? "Sin estado"
);

// Declarado antes del watcher inmediato que lo reinicia
const previewUrl = ref(null);

// ── Poblar / resetear al abrir ────────────────────────────────────────────────
watch(
  () => [props.show, props.mode, props.temporada],
  ([show]) => {
    if (!show) return;
    form.clearErrors();
    previewUrl.value = null;

    if (isEdit.value && props.temporada) {
      form.numero = String(props.temporada.numero ?? "");
      form.titulo = props.temporada.titulo ?? "";
      form.descripcion = props.temporada.descripcion ?? "";
      form.estado_id = props.temporada.estado_id ?? "";
      form.imagen_portada = null;
    } else {
      form.reset();
      form.numero = String(props.siguienteNumero);
      form.estado_id = estadoBorradorId.value;
    }
  },
  { immediate: true, deep: true }
);

// ── Preview de portada ────────────────────────────────────────────────────────
watch(
  () => form.imagen_portada,
  (file) => {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = file instanceof File ? URL.createObjectURL(file) : null;
  }
);
const portadaExistente = computed(() => (isEdit.value ? props.temporada?.imagen_portada_url ?? null : null));
const portadaVisible = computed(() => previewUrl.value ?? portadaExistente.value);

// ── Cerrar / enviar ───────────────────────────────────────────────────────────
const cerrar = () => {
  if (form.processing) return;
  form.clearErrors();
  emit("close");
};

const submit = () => {
  if (form.processing) return;

  const opciones = {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      previewUrl.value = null;
      emit("success");
      emit("close");
    },
  };

  if (isEdit.value && props.temporada) {
    form
      .transform((data) => ({ ...data, _method: "PUT" }))
      .post(route("podcast.temporadas.update", props.temporada.id), opciones);
  } else {
    form.transform((data) => data).post(route("podcast.temporadas.store"), opciones);
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
            class="relative z-50 w-full max-w-4xl bg-white rounded-[2rem] sm:rounded-[2.5rem] shadow-2xl overflow-hidden flex flex-col lg:flex-row max-h-[92dvh]"
          >
            <!-- Formulario -->
            <div class="lg:w-3/5 flex flex-col min-h-0 lg:border-r border-slate-100">
              <div class="px-6 sm:px-8 py-5 sm:py-6 border-b border-slate-100 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 bg-podcast-oscuro rounded-2xl flex items-center justify-center shadow-md">
                    <Layers class="w-5 h-5 text-podcast-acento" />
                  </div>
                  <div>
                    <h3 class="text-lg sm:text-xl font-black text-slate-900">
                      {{ isEdit ? "Editar temporada" : "Nueva temporada" }}
                    </h3>
                    <p class="text-xs font-medium text-slate-400">Podcast · Íntimamente Hablando</p>
                  </div>
                </div>
                <button
                  type="button"
                  @click="cerrar"
                  class="w-9 h-9 flex items-center justify-center rounded-2xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-all"
                  aria-label="Cerrar"
                >
                  <X class="w-5 h-5" />
                </button>
              </div>

              <div class="flex-grow overflow-y-auto px-6 sm:px-8 py-6 space-y-5">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <FormInput
                    v-model="form.numero"
                    label="Número"
                    type="number"
                    icon="tag"
                    placeholder="1"
                    :max="5"
                    :required="true"
                    :activeColor="COLOR"
                    :error="form.errors.numero"
                    @clearError="form.clearErrors('numero')"
                  />
                  <div class="sm:col-span-2">
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
                </div>

                <FormInput
                  v-model="form.titulo"
                  label="Título de la temporada"
                  type="text"
                  icon="title"
                  placeholder="Ej: Quienes construyen lo público"
                  :max="200"
                  :required="true"
                  :activeColor="COLOR"
                  :error="form.errors.titulo"
                  @clearError="form.clearErrors('titulo')"
                />

                <FormInput
                  v-model="form.descripcion"
                  label="Descripción (opcional)"
                  type="textarea"
                  icon="notes"
                  placeholder="De qué trata esta temporada..."
                  :max="2000"
                  :rows="4"
                  :activeColor="COLOR"
                  :error="form.errors.descripcion"
                  @clearError="form.clearErrors('descripcion')"
                />

                <div>
                  <FormInput
                    v-model="form.imagen_portada"
                    label="Portada (opcional)"
                    type="file"
                    icon="image"
                    placeholder="Seleccionar imagen (JPG, PNG o WEBP · máx. 4 MB)"
                    :activeColor="COLOR"
                    :error="form.errors.imagen_portada"
                    @clearError="form.clearErrors('imagen_portada')"
                  />
                  <p v-if="portadaExistente && !previewUrl" class="text-[11px] font-medium text-slate-400 mt-2 px-1">
                    Ya existe una portada. Selecciona un archivo solo si deseas reemplazarla.
                  </p>
                </div>

                <p v-if="form.errors.general" class="text-[13px] text-red-600 font-medium bg-red-50 rounded-xl p-3">
                  {{ form.errors.general }}
                </p>
              </div>

              <div class="px-6 sm:px-8 py-4 sm:py-5 border-t border-slate-100 bg-slate-50/50 flex items-center justify-between gap-4 shrink-0">
                <button
                  type="button"
                  @click="cerrar"
                  :disabled="form.processing"
                  class="text-sm font-bold text-slate-500 hover:text-slate-800 transition-colors px-4 py-2 rounded-xl hover:bg-slate-100 disabled:opacity-50"
                >
                  Cancelar
                </button>
                <BtnUniversal
                  :label="isEdit ? 'Guardar cambios' : 'Crear temporada'"
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

            <!-- Vista previa -->
            <div class="hidden lg:flex lg:w-2/5 bg-slate-50 flex-col min-h-0">
              <div class="px-6 pt-6 pb-3 shrink-0">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Vista previa</p>
              </div>
              <div class="flex-grow overflow-y-auto px-6 pb-6">
                <div class="rounded-[2rem] overflow-hidden shadow-xl border border-slate-200/60 bg-white">
                  <div class="relative aspect-[4/3] bg-podcast-oscuro overflow-hidden">
                    <img v-if="portadaVisible" :src="portadaVisible" class="w-full h-full object-cover" alt="" />
                    <div v-else class="absolute inset-0 flex flex-col items-center justify-center gap-2 text-slate-400">
                      <div
                        class="absolute inset-0 opacity-[0.08]"
                        style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 24px 24px"
                      ></div>
                      <ImageOff class="w-7 h-7 relative" />
                      <span class="text-[10px] font-bold uppercase tracking-widest relative">Sin portada</span>
                    </div>
                    <span
                      class="absolute top-4 left-4 inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-black/40 backdrop-blur-md border border-white/10 text-white text-[10px] font-black uppercase tracking-widest"
                    >
                      <span class="w-1.5 h-1.5 rounded-full bg-podcast-acento"></span>
                      Temporada {{ form.numero || "?" }}
                    </span>
                  </div>
                  <div class="p-6">
                    <span
                      class="inline-block px-2.5 py-0.5 rounded-lg text-[9px] font-black uppercase tracking-widest mb-3"
                      :class="estadoClass(nombreEstadoSeleccionado)"
                    >
                      {{ nombreEstadoSeleccionado }}
                    </span>
                    <h3 class="text-xl font-black text-slate-900 leading-tight mb-2" :class="{ 'text-slate-300': !form.titulo }">
                      {{ form.titulo || "Título de la temporada" }}
                    </h3>
                    <p class="text-sm text-slate-500 line-clamp-3" :class="{ 'text-slate-300': !form.descripcion }">
                      {{ form.descripcion || "La descripción aparecerá en la página pública del podcast." }}
                    </p>
                  </div>
                </div>
                <div class="mt-4 p-4 bg-white rounded-2xl border border-slate-200/60">
                  <p class="text-[11px] font-semibold text-slate-500 leading-relaxed">
                    Solo las temporadas en estado <span class="font-black text-emerald-600">Activo</span> se muestran al público. Los episodios se gestionan en su propia sección.
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
