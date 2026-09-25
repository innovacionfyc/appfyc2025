<script setup>
import { computed, reactive, ref } from "vue";
import axios from "axios";

const props = defineProps({
  url: { type: String, required: true },
});

const emit = defineEmits(["enviado"]);

const form = reactive({ nombre: "", correo: "", contenido: "", sitio_web: "" });
const errores = reactive({ nombre: "", correo: "", contenido: "" });
const procesando = ref(false);
const exito = ref("");
const errorGeneral = ref("");

const MAX_CONTENIDO = 1000;
const restantes = computed(() => MAX_CONTENIDO - form.contenido.length);

const limpiarErrores = () => {
  errores.nombre = "";
  errores.correo = "";
  errores.contenido = "";
  errorGeneral.value = "";
};

const enviar = async () => {
  if (procesando.value) return; // ignora el doble clic

  procesando.value = true;
  limpiarErrores();
  exito.value = "";

  try {
    const { data } = await axios.post(props.url, { ...form });
    exito.value = data.mensaje;
    form.nombre = "";
    form.correo = "";
    form.contenido = "";
    emit("enviado");
  } catch (error) {
    const status = error?.response?.status;
    const detalle = error?.response?.data?.errors ?? {};

    if (status === 422) {
      errores.nombre = detalle.nombre?.[0] ?? "";
      errores.correo = detalle.correo?.[0] ?? "";
      errores.contenido = detalle.contenido?.[0] ?? "";
      if (!errores.nombre && !errores.correo && !errores.contenido) {
        errorGeneral.value = "Revisa los datos del formulario e inténtalo de nuevo.";
      }
    } else if (status === 429) {
      errorGeneral.value =
        "Has enviado varios comentarios seguidos. Espera unos minutos antes de intentarlo de nuevo.";
    } else if (status === 419) {
      errorGeneral.value = "La sesión caducó. Recarga la página e inténtalo de nuevo.";
    } else {
      errorGeneral.value = "No pudimos enviar tu comentario. Inténtalo de nuevo en un momento.";
    }
  } finally {
    procesando.value = false;
  }
};

const claseCampo = (conError) =>
  [
    "w-full rounded-xl border bg-white px-4 py-3 text-sm text-slate-900 placeholder:text-slate-400 transition-colors focus:outline-none focus:ring-4 focus:ring-podcast-oscuro/15",
    conError ? "border-rose-400 focus:border-rose-500" : "border-slate-200 focus:border-podcast-oscuro/60",
  ].join(" ");
</script>

<template>
  <form class="space-y-5" novalidate @submit.prevent="enviar">
    <div
      v-if="exito"
      role="status"
      aria-live="polite"
      class="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-800"
    >
      <span class="material-symbols-rounded text-xl text-emerald-600" aria-hidden="true">check_circle</span>
      <p class="font-semibold">{{ exito }}</p>
    </div>

    <div
      v-if="errorGeneral"
      role="alert"
      class="flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800"
    >
      <span class="material-symbols-rounded text-xl text-rose-600" aria-hidden="true">error</span>
      <p class="font-semibold">{{ errorGeneral }}</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
      <div>
        <label for="comentario-nombre" class="block text-xs font-black uppercase tracking-widest text-slate-500 mb-2">
          Nombre <span class="text-rose-500" aria-hidden="true">*</span>
        </label>
        <input
          id="comentario-nombre"
          v-model="form.nombre"
          type="text"
          name="nombre"
          autocomplete="name"
          maxlength="80"
          required
          :aria-invalid="!!errores.nombre"
          :aria-describedby="errores.nombre ? 'comentario-nombre-error' : undefined"
          :class="claseCampo(errores.nombre)"
          placeholder="¿Cómo te llamas?"
        />
        <p v-if="errores.nombre" id="comentario-nombre-error" class="mt-1.5 text-xs font-semibold text-rose-600">
          {{ errores.nombre }}
        </p>
      </div>

      <div>
        <label for="comentario-correo" class="block text-xs font-black uppercase tracking-widest text-slate-500 mb-2">
          Correo <span class="font-semibold normal-case tracking-normal text-slate-400">(opcional, no se publica)</span>
        </label>
        <input
          id="comentario-correo"
          v-model="form.correo"
          type="email"
          name="correo"
          autocomplete="email"
          maxlength="150"
          :aria-invalid="!!errores.correo"
          :aria-describedby="errores.correo ? 'comentario-correo-error' : undefined"
          :class="claseCampo(errores.correo)"
          placeholder="nombre@correo.com"
        />
        <p v-if="errores.correo" id="comentario-correo-error" class="mt-1.5 text-xs font-semibold text-rose-600">
          {{ errores.correo }}
        </p>
      </div>
    </div>

    <div>
      <label for="comentario-contenido" class="block text-xs font-black uppercase tracking-widest text-slate-500 mb-2">
        Comentario <span class="text-rose-500" aria-hidden="true">*</span>
      </label>
      <textarea
        id="comentario-contenido"
        v-model="form.contenido"
        name="contenido"
        rows="4"
        :maxlength="MAX_CONTENIDO"
        required
        :aria-invalid="!!errores.contenido"
        :aria-describedby="errores.contenido ? 'comentario-contenido-error' : 'comentario-contenido-ayuda'"
        :class="claseCampo(errores.contenido)"
        placeholder="¿Qué te dejó esta conversación?"
      ></textarea>
      <div class="mt-1.5 flex items-start justify-between gap-4">
        <p v-if="errores.contenido" id="comentario-contenido-error" class="text-xs font-semibold text-rose-600">
          {{ errores.contenido }}
        </p>
        <p v-else id="comentario-contenido-ayuda" class="text-xs text-slate-500">
          Entre 10 y 1000 caracteres. Los comentarios se revisan antes de publicarse.
        </p>
        <span class="shrink-0 text-xs tabular-nums text-slate-400" aria-hidden="true">{{ restantes }}</span>
      </div>
    </div>

    <!-- Campo señuelo contra bots: oculto para personas y lectores de pantalla; debe quedar vacío -->
    <div class="hidden" aria-hidden="true">
      <label for="comentario-sitio-web">Sitio web</label>
      <input id="comentario-sitio-web" v-model="form.sitio_web" type="text" name="sitio_web" tabindex="-1" autocomplete="off" />
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center gap-4">
      <button
        type="submit"
        :disabled="procesando"
        :aria-busy="procesando"
        class="inline-flex items-center justify-center gap-2.5 px-7 py-3 rounded-2xl text-sm font-bold bg-podcast-oscuro text-white shadow-md shadow-podcast-oscuro/20 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg active:scale-[0.98] focus:outline-none focus-visible:ring-4 focus-visible:ring-podcast-oscuro/25 disabled:cursor-wait disabled:opacity-70 disabled:hover:translate-y-0"
      >
        <span class="material-symbols-rounded text-xl" aria-hidden="true">{{ procesando ? "hourglass_top" : "send" }}</span>
        {{ procesando ? "Enviando…" : "Enviar comentario" }}
      </button>
      <p class="text-xs text-slate-500">Tu correo nunca se muestra en público.</p>
    </div>
  </form>
</template>
