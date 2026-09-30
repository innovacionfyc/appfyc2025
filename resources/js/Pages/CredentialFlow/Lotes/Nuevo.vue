<script setup>
import { ref, reactive, computed, watch } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import axios from "axios";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";
import FormInput from "@/Components/Shared/inputs/FormInput.vue";
import ArchivoParticipantesDropzone from "@/Components/CredentialFlow/Lotes/ArchivoParticipantesDropzone.vue";
import PreviewImportacion from "@/Components/CredentialFlow/Lotes/PreviewImportacion.vue";
import { ArrowLeft, Download, CircleAlert, RotateCcw, LayoutTemplate } from "lucide-vue-next";

const props = defineProps({
  plantillas: { type: Array, default: () => [] },
  limites: { type: Object, default: () => ({ archivoMb: 2, filas: 1000 }) },
});

const COLOR = "#942934";

const form = reactive({
  plantilla_id: "",
  nombre: "",
  descripcion: "",
  evento: "",
  fecha: "",
  intensidad_horaria: "",
  archivo: null,
});

const errores = ref({}); // errores de formulario por campo
const errorGeneral = ref("");
const resultado = ref(null); // resultado de «Validar archivo» (preview)
const validando = ref(false);
const importando = ref(false);
const ocupado = computed(() => validando.value || importando.value);

// El preview solo vale para el formulario y el archivo con los que se generó: si algo cambia, se descarta.
// (La importación vuelve a validar desde cero de todos modos.)
watch(
  () => JSON.stringify({ ...form, archivo: form.archivo ? [form.archivo.name, form.archivo.size, form.archivo.lastModified] : null }),
  () => {
    if (!ocupado.value) resultado.value = null;
  }
);

const limpiarError = (campo) => {
  if (errores.value[campo]) delete errores.value[campo];
};

const cuerpo = () => {
  const fd = new FormData();
  for (const [k, v] of Object.entries(form)) {
    if (v !== null && v !== "") fd.append(k, v);
  }
  return fd;
};

const aplicarErrores = (e) => {
  const r = e.response;
  if (r?.status === 422 && r.data?.errors) {
    errores.value = Object.fromEntries(Object.entries(r.data.errors).map(([k, v]) => [k, v[0]]));
    errorGeneral.value = "Revisa los campos marcados en el formulario.";
    return true;
  }
  return false;
};

const mensajeInesperado = (e) =>
  e.response?.data?.error?.message ?? "No se pudo completar la operación. Inténtalo de nuevo; si continúa, avisa al equipo técnico.";

async function validar() {
  if (ocupado.value) return;
  errores.value = {};
  errorGeneral.value = "";
  resultado.value = null;
  validando.value = true;
  try {
    const { data } = await axios.post(route("credential-flow.lotes.validar"), cuerpo());
    resultado.value = data.resultado;
  } catch (e) {
    if (!aplicarErrores(e)) errorGeneral.value = mensajeInesperado(e);
  } finally {
    validando.value = false;
  }
}

async function importar() {
  if (ocupado.value || !resultado.value?.valido) return;
  errores.value = {};
  errorGeneral.value = "";
  importando.value = true;
  try {
    const { data } = await axios.post(route("credential-flow.lotes.store"), cuerpo());
    router.visit(data.redirect);
  } catch (e) {
    if (e.response?.status === 422 && e.response.data?.resultado) {
      // La confirmación volvió a validar y encontró errores: no se creó nada.
      resultado.value = e.response.data.resultado;
      errorGeneral.value = e.response.data.error?.message ?? "El archivo tiene errores.";
    } else if (!aplicarErrores(e)) {
      errorGeneral.value = mensajeInesperado(e);
    }
    importando.value = false;
  }
}

const corregir = () => {
  resultado.value = null;
  errorGeneral.value = "";
  form.archivo = null;
};

const headerStats = computed(() => [
  { label: "Plantillas con diseño", value: props.plantillas.length, icon: "description", color: "text-primary-vinotinto", bg: "bg-primary-vinotinto/10" },
]);
</script>

<template>
  <Head title="Credential Flow · Nuevo lote" />

  <AuthenticatedLayout>
    <Sidebar>
      <nav class="mb-3 flex flex-wrap items-center gap-2 text-[12px] font-bold text-slate-400" aria-label="Ruta de navegación">
        <Link :href="route('credential-flow.index')" class="hover:text-primary-vinotinto transition-colors">Credential Flow</Link>
        <span>/</span>
        <Link :href="route('credential-flow.lotes.index')" class="hover:text-primary-vinotinto transition-colors">Lotes</Link>
        <span>/</span>
        <span class="text-slate-600">Nuevo lote</span>
      </nav>

      <DashboardHeader title="Nuevo lote" subtitle="Importa una lista de participantes desde un archivo Excel o CSV" :stats="headerStats">
        <template #actions>
          <Link
            :href="route('credential-flow.lotes.index')"
            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 transition-all"
          >
            <ArrowLeft class="w-4 h-4" />
            Volver a lotes
          </Link>
        </template>
      </DashboardHeader>

      <!-- Sin plantillas con diseño -->
      <div v-if="plantillas.length === 0" class="mt-8 bg-white rounded-[2rem] border border-dashed border-slate-200 text-center py-16 px-6 space-y-3" data-sin-plantillas>
        <div class="w-16 h-16 mx-auto rounded-3xl bg-slate-100 flex items-center justify-center">
          <LayoutTemplate class="w-8 h-8 text-slate-400" />
        </div>
        <p class="text-lg font-bold text-slate-700">Primero necesitas una plantilla con diseño</p>
        <p class="text-sm text-slate-500 max-w-md mx-auto">Un lote se asocia a una plantilla cuyo diseño ya esté guardado en el editor.</p>
        <Link :href="route('credential-flow.plantillas.index')" class="inline-flex items-center gap-2 mt-2 px-4 py-2.5 rounded-2xl bg-primary-vinotinto/10 text-sm font-bold text-primary-vinotinto hover:bg-primary-vinotinto/20 transition-all">
          Ir a plantillas
        </Link>
      </div>

      <form v-else class="mt-8 space-y-6" novalidate @submit.prevent="validar">
        <!-- Datos del lote -->
        <section class="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6 sm:p-8 space-y-5">
          <h3 class="text-lg font-extrabold text-slate-900">1. Datos del lote</h3>

          <FormInput
            v-model="form.plantilla_id"
            label="Plantilla"
            type="select"
            icon="description"
            placeholder="Elige una plantilla"
            :options="plantillas"
            :required="true"
            :activeColor="COLOR"
            :error="errores.plantilla_id"
            @clearError="limpiarError('plantilla_id')"
          />

          <div class="grid gap-5 md:grid-cols-2">
            <FormInput v-model="form.nombre" label="Nombre del lote" type="text" icon="badge" placeholder="Ej: Congreso de finanzas 2026" :max="200" :required="true" :activeColor="COLOR" :error="errores.nombre" @clearError="limpiarError('nombre')" />
            <FormInput v-model="form.descripcion" label="Descripción (opcional)" type="text" icon="notes" placeholder="Notas internas del lote" :max="1000" :activeColor="COLOR" :error="errores.descripcion" @clearError="limpiarError('descripcion')" />
          </div>

          <p class="text-[12px] font-semibold text-slate-500 bg-slate-50 rounded-2xl px-4 py-3">
            El evento, la fecha y la intensidad horaria se escriben una sola vez y valen para todos los participantes del lote. No van en el archivo.
          </p>

          <div class="grid gap-5 md:grid-cols-3">
            <FormInput v-model="form.evento" label="Evento" type="text" icon="event" placeholder="Nombre del evento" :max="200" :required="true" :activeColor="COLOR" :error="errores.evento" @clearError="limpiarError('evento')" />
            <FormInput v-model="form.fecha" label="Fecha" type="text" icon="calendar_month" placeholder="29 de septiembre de 2026" :max="80" :required="true" :activeColor="COLOR" :error="errores.fecha" @clearError="limpiarError('fecha')" />
            <FormInput v-model="form.intensidad_horaria" label="Intensidad horaria" type="text" icon="schedule" placeholder="16 horas" :max="30" :required="true" :activeColor="COLOR" :error="errores.intensidad_horaria" @clearError="limpiarError('intensidad_horaria')" />
          </div>
        </section>

        <!-- Archivo -->
        <section class="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6 sm:p-8 space-y-5">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-lg font-extrabold text-slate-900">2. Archivo de participantes</h3>
            <a
              :href="route('credential-flow.lotes.plantilla-excel')"
              class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-slate-100 text-[13px] font-bold text-slate-600 hover:bg-slate-200 transition-all"
              data-descargar-plantilla
            >
              <Download class="w-4 h-4" />
              Descargar plantilla Excel
            </a>
          </div>

          <p class="text-sm font-medium text-slate-500">
            Dos columnas: <strong>nombre_completo</strong> y <strong>documento</strong> (el documento tal como debe imprimirse, por ejemplo
            <em>C.C. 1.023.456.789</em>). Máximo {{ limites.filas }} participantes.
          </p>

          <ArchivoParticipantesDropzone v-model="form.archivo" :max-mb="limites.archivoMb" :disabled="ocupado" :error="errores.archivo" @clearError="limpiarError('archivo')" />
        </section>

        <div v-if="errorGeneral" class="flex items-start gap-3 px-4 py-3 rounded-2xl bg-red-50 text-red-700 text-[13px] font-semibold" role="alert" data-error-general>
          <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" />
          {{ errorGeneral }}
        </div>

        <!-- Acción 1: validar -->
        <div class="flex flex-wrap items-center gap-3">
          <BtnUniversal
            type="submit"
            label="Validar archivo"
            icon="fact_check"
            icon-position="right"
            size="md"
            :activeColor="COLOR"
            :loading="validando"
            :disabled="validando"
            process="Validando..."
            :class="['sm:w-auto', !validando && (importando || !form.archivo) ? 'opacity-50 pointer-events-none' : '']"
            :aria-disabled="!validando && (importando || !form.archivo)"
            data-accion="validar"
          />
          <span v-if="!form.archivo" class="text-[12px] font-semibold text-slate-400">Elige un archivo para validarlo.</span>
        </div>

        <!-- Preview -->
        <section v-if="resultado" class="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6 sm:p-8 space-y-6">
          <h3 class="text-lg font-extrabold text-slate-900">3. Preview de la importación</h3>
          <PreviewImportacion :resultado="resultado" />

          <!-- Acción 2: confirmar -->
          <div class="flex flex-wrap items-center gap-3 pt-2 border-t border-slate-100">
            <button
              type="button"
              class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 transition-all disabled:opacity-50"
              :disabled="ocupado"
              data-accion="corregir"
              @click="corregir"
            >
              <RotateCcw class="w-4 h-4" />
              Corregir archivo
            </button>
            <BtnUniversal
              v-if="resultado.valido"
              type="button"
              :label="`Importar ${resultado.filas_validas} participantes`"
              icon="upload"
              icon-position="right"
              size="md"
              :activeColor="COLOR"
              :loading="importando"
              :disabled="importando"
              process="Importando..."
              class="sm:w-auto"
              data-accion="importar"
              @click="importar"
            />
          </div>
        </section>
      </form>
    </Sidebar>
  </AuthenticatedLayout>
</template>
