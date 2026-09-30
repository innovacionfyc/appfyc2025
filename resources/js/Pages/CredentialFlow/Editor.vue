<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import EditorCanvas from "@/Components/CredentialFlow/Editor/EditorCanvas.vue";
import EditorToolbar from "@/Components/CredentialFlow/Editor/EditorToolbar.vue";
import EditorInspector from "@/Components/CredentialFlow/Editor/EditorInspector.vue";
import { useDisenoEditor } from "@/Composables/CredentialFlow/useDisenoEditor";
import { useCamposDinamicos } from "@/Composables/CredentialFlow/camposDinamicos";
import { Monitor, Info, CircleAlert, ArrowLeft } from "lucide-vue-next";

const props = defineProps({
  plantilla: { type: Object, required: true },
  diseno: { type: Object, default: null },
  pdfUrl: { type: String, required: true },
  schema: { type: Object, required: true },
});

const editor = useDisenoEditor(props.schema);
const campos = useCamposDinamicos(props.schema);

// Autoajuste del elemento seleccionado (solo campos dinámicos) y cuántos no caben aun al mínimo.
const ajusteSeleccionado = computed(() =>
  editor.seleccionado.value ? campos.ajusteDe(editor.seleccionado.value) : null
);
const cantidadNoCabe = computed(() => editor.elementos.value.filter((e) => campos.ajusteDe(e)?.noCabe).length);

// Fuente reproducible que no cargó: bloquea el guardado (no hay sustitución silenciosa).
const conErrorDeFuente = computed(() => editor.elementos.value.filter((e) => campos.renderDe(e).estado === "error"));
// Textos con caracteres que su fuente reproducible (Outfit) no tiene: no se dibujan con otra fuente
// y bloquean el guardado. Las fuentes heredadas no se validan (no hay métricas autoritativas).
const conCaracterNoSoportado = computed(() => editor.elementos.value.filter((e) => campos.renderDe(e).estado === "noSoportado"));
const conFuenteHeredada = computed(() => editor.elementos.value.filter((e) => campos.renderDe(e).estado === "heredada"));
const renderSeleccionado = computed(() =>
  editor.seleccionado.value ? campos.renderDe(editor.seleccionado.value) : undefined
);
const guardarBloqueado = computed(
  () => editor.conCampoDesconocido.value.length > 0 || conErrorDeFuente.value.length > 0 || conCaracterNoSoportado.value.length > 0
);

// ── PDF de prueba ────────────────────────────────────────────────────────────
// Se genera en el servidor SIEMPRE desde el diseño guardado (no se envía el JSON del editor), con un
// dataset QA fijo. Solo se habilita si no hay nada que el editor ya sepa que impedirá generarlo.
const generando = ref(false);
const errorGeneracion = ref(null); // { code, message }

const motivoNoGenerar = computed(() => {
  if (editor.conCampoDesconocido.value.length) return "Hay un campo dinámico desconocido: corrígelo para poder generar.";
  if (conErrorDeFuente.value.length) return "Una fuente Outfit no cargó: recarga la página.";
  if (conCaracterNoSoportado.value.length) return "Hay textos con caracteres que Outfit no tiene: corrígelos para poder generar.";
  if (conFuenteHeredada.value.length) return "Hay elementos con fuente heredada: cambia a Outfit para poder generar.";
  if (cantidadNoCabe.value) return "Hay campos que no caben en su caja: ensancha la caja o baja el tamaño.";
  if (editor.sinGuardar.value) return "Guarda el diseño primero: el PDF de prueba se genera con el diseño guardado.";
  return null;
});

async function generarPrueba() {
  if (generando.value || motivoNoGenerar.value || !pdfListo.value) return;

  generando.value = true;
  errorGeneracion.value = null;
  try {
    const respuesta = await fetch(route("credential-flow.plantillas.pdf-prueba", props.plantilla.id), {
      credentials: "same-origin",
      headers: { Accept: "application/pdf, application/json", "X-Requested-With": "XMLHttpRequest" },
    });

    if (respuesta.ok && (respuesta.headers.get("Content-Type") ?? "").includes("application/pdf")) {
      const url = URL.createObjectURL(await respuesta.blob());
      const enlace = document.createElement("a");
      enlace.href = url;
      enlace.download = `credencial-prueba-${props.plantilla.id}.pdf`;
      document.body.appendChild(enlace);
      enlace.click();
      enlace.remove();
      setTimeout(() => URL.revokeObjectURL(url), 10000);
      return;
    }

    if (respuesta.status === 429) {
      errorGeneracion.value = { code: "DEMASIADAS_SOLICITUDES", message: "Demasiadas solicitudes seguidas. Espera un minuto e inténtalo de nuevo." };
      return;
    }
    const cuerpo = await respuesta.json().catch(() => null);
    errorGeneracion.value = cuerpo?.error ?? { code: "ERROR", message: "No se pudo generar el PDF de prueba." };
  } catch {
    errorGeneracion.value = { code: "RED", message: "No se pudo contactar con el servidor. Revisa tu conexión e inténtalo de nuevo." };
  } finally {
    generando.value = false;
  }
}

const pdfListo = ref(false);
const pdfConError = ref(false);
const paginas = ref(1);
const guardando = ref(false);
const erroresGuardado = ref([]);

// El editor está pensado para escritorio (≥ 1024 px); en pantallas pequeñas solo se muestra un aviso.
const CONSULTA_ESCRITORIO = "(min-width: 1024px)";
const esEscritorio = ref(typeof window === "undefined" ? true : window.matchMedia(CONSULTA_ESCRITORIO).matches);
let medios = null;
const alCambiarMedios = (e) => (esEscritorio.value = e.matches);

// ── PDF cargado: las medidas reales de la página (en pt) fijan el sistema de coordenadas ──
const alCargarPdf = ({ ancho, alto, paginas: n }) => {
  // Si el lienzo se vuelve a montar (p. ej. la ventana pasó a tamaño pequeño y volvió), no se
  // reinicia el diseño: se perderían los cambios sin guardar.
  if (!pdfListo.value) editor.iniciar(props.diseno, ancho, alto);
  paginas.value = n;
  pdfListo.value = true;
};

// ── Guardar ───────────────────────────────────────────────────────────────────
const guardar = () => {
  if (guardando.value || !pdfListo.value || !editor.sinGuardar.value) return;
  // Un campo desconocido no se sustituye ni se borra en silencio: hay que elegir uno válido.
  if (guardarBloqueado.value) return;

  router.put(
    route("credential-flow.plantillas.diseno.update", props.plantilla.id),
    { diseno: editor.payload() },
    {
      preserveScroll: true,
      preserveState: true,
      onStart: () => {
        guardando.value = true;
        erroresGuardado.value = [];
      },
      onSuccess: () => editor.marcarGuardado(),
      onError: (errores) => {
        erroresGuardado.value = [...new Set(Object.values(errores))];
      },
      onFinish: () => (guardando.value = false),
    }
  );
};

// ── Teclado: Supr elimina; flechas mueven 1 pt (Mayús: 10 pt) ────────────────────────
const enCampoDeTexto = (t) => t instanceof HTMLElement && (t.isContentEditable || ["INPUT", "TEXTAREA", "SELECT"].includes(t.tagName));

const alPulsarTecla = (e) => {
  if (!esEscritorio.value || !editor.seleccionado.value || enCampoDeTexto(e.target)) return;

  const paso = e.shiftKey ? 10 : 1;
  const movimientos = { ArrowLeft: [-paso, 0], ArrowRight: [paso, 0], ArrowUp: [0, -paso], ArrowDown: [0, paso] };

  if (e.key === "Delete") {
    e.preventDefault();
    editor.eliminarSeleccionado();
  } else if (movimientos[e.key]) {
    e.preventDefault();
    editor.empujar(...movimientos[e.key]);
  }
};

onMounted(() => {
  medios = window.matchMedia(CONSULTA_ESCRITORIO);
  medios.addEventListener("change", alCambiarMedios);
  window.addEventListener("keydown", alPulsarTecla);
});

onBeforeUnmount(() => {
  medios?.removeEventListener("change", alCambiarMedios);
  window.removeEventListener("keydown", alPulsarTecla);
});

const headerStats = computed(() => [
  {
    label: "Elementos",
    value: editor.elementos.value.length,
    icon: "text_fields",
    color: "text-primary-vinotinto",
    bg: "bg-primary-vinotinto/10",
  },
]);
</script>

<template>
  <Head :title="`Credential Flow · Editor · ${plantilla.nombre}`" />

  <AuthenticatedLayout>
    <Sidebar>
      <nav class="mb-3 flex flex-wrap items-center gap-2 text-[12px] font-bold text-slate-400" aria-label="Ruta de navegación">
        <Link :href="route('credential-flow.index')" class="hover:text-primary-vinotinto transition-colors">Credential Flow</Link>
        <span>/</span>
        <Link :href="route('credential-flow.plantillas.index')" class="hover:text-primary-vinotinto transition-colors">Plantillas</Link>
        <span>/</span>
        <span class="text-slate-600">Editor</span>
      </nav>

      <DashboardHeader
        :title="plantilla.nombre"
        :subtitle="`Editor de diseño · PDF base: ${plantilla.nombre_archivo_original}`"
        :stats="headerStats"
      >
        <template #actions>
          <Link
            :href="route('credential-flow.plantillas.index')"
            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 transition-all"
          >
            <ArrowLeft class="w-4 h-4" />
            Volver a plantillas
          </Link>
        </template>
      </DashboardHeader>

      <!-- Pantallas pequeñas: sin edición -->
      <div
        v-if="!esEscritorio"
        class="mt-8 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-8 text-center space-y-3"
        data-aviso="pantalla-pequena"
      >
        <div class="w-16 h-16 mx-auto rounded-3xl bg-primary-vinotinto/10 text-primary-vinotinto flex items-center justify-center">
          <Monitor class="w-8 h-8" />
        </div>
        <p class="text-lg font-extrabold text-slate-800">Para editar una plantilla usa una pantalla más grande</p>
        <p class="text-sm text-slate-500 max-w-sm mx-auto">
          El editor necesita espacio para mostrar el PDF y sus propiedades. Ábrelo desde un computador de escritorio o una tablet en horizontal.
        </p>
        <Link
          :href="route('credential-flow.plantillas.index')"
          class="inline-flex items-center gap-2 mt-2 px-4 py-2.5 rounded-2xl bg-primary-vinotinto/10 text-sm font-bold text-primary-vinotinto hover:bg-primary-vinotinto/20 transition-all"
        >
          <ArrowLeft class="w-4 h-4" />
          Volver a plantillas
        </Link>
      </div>

      <!-- Escritorio -->
      <div v-else class="mt-8 space-y-4">
        <EditorToolbar
          :hay-seleccion="!!editor.seleccionado.value"
          :sin-guardar="editor.sinGuardar.value"
          :guardando="guardando"
          :puede-agregar="editor.elementos.value.length < schema.maxElementos"
          :deshabilitado="!pdfListo"
          :guardar-bloqueado="guardarBloqueado"
          :generando="generando"
          :motivo-no-generar="motivoNoGenerar"
          @agregar-texto="editor.agregarTexto"
          @centrar-horizontal="editor.centrarHorizontal"
          @centrar-vertical="editor.centrarVertical"
          @eliminar="editor.eliminarSeleccionado"
          @guardar="guardar"
          @generar-prueba="generarPrueba"
        />

        <div
          v-if="paginas > 1"
          class="flex items-start gap-3 px-4 py-3 rounded-2xl bg-blue-50 text-blue-700 text-[13px] font-semibold"
          data-aviso="varias-paginas"
        >
          <Info class="w-4 h-4 mt-0.5 shrink-0" />
          Este PDF tiene {{ paginas }} páginas. En esta primera versión solo se edita la página 1.
        </div>

        <div
          v-if="editor.conCampoDesconocido.value.length"
          class="flex items-start gap-3 px-4 py-3 rounded-2xl bg-red-50 text-red-700 text-[13px] font-semibold"
          role="alert"
          data-aviso="campo-desconocido"
        >
          <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" />
          {{ editor.conCampoDesconocido.value.length === 1 ? "Hay 1 elemento con un campo dinámico desconocido" : `Hay ${editor.conCampoDesconocido.value.length} elementos con un campo dinámico desconocido` }}.
          Elige un contenido válido en cada uno para poder guardar.
        </div>

        <div
          v-if="conErrorDeFuente.length"
          class="flex items-start gap-3 px-4 py-3 rounded-2xl bg-red-50 text-red-700 text-[13px] font-semibold"
          role="alert"
          data-aviso="error-fuente"
        >
          <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" />
          No se pudo cargar una fuente de Outfit. Recarga la página; no se puede guardar hasta que cargue.
        </div>

        <div
          v-if="conCaracterNoSoportado.length"
          class="flex items-start gap-3 px-4 py-3 rounded-2xl bg-red-50 text-red-700 text-[13px] font-semibold"
          role="alert"
          data-aviso="caracter-no-soportado"
        >
          <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" />
          {{ conCaracterNoSoportado.length === 1 ? "Hay 1 texto" : `Hay ${conCaracterNoSoportado.length} textos` }} con caracteres que la fuente Outfit no tiene y no se dibujan con otra fuente. No se puede guardar el diseño hasta corregir o quitar esos caracteres.
        </div>

        <div
          v-if="conFuenteHeredada.length"
          class="flex items-start gap-3 px-4 py-3 rounded-2xl bg-amber-50 text-amber-700 text-[13px] font-semibold"
          data-aviso="fuente-heredada"
        >
          <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" />
          {{ conFuenteHeredada.length === 1 ? "Hay 1 elemento" : `Hay ${conFuenteHeredada.length} elementos` }} con fuente heredada, no preparada para generación PDF. Cambia a Outfit para una salida reproducible.
        </div>

        <div
          v-if="cantidadNoCabe"
          class="flex items-start gap-3 px-4 py-3 rounded-2xl bg-amber-50 text-amber-700 text-[13px] font-semibold"
          data-aviso="no-cabe"
        >
          <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" />
          {{ cantidadNoCabe === 1 ? "Hay 1 campo que no cabe" : `Hay ${cantidadNoCabe} campos que no caben` }} en su caja aun reduciendo su tamaño al
          {{ Math.round(schema.escalaMinima * 100) }} %. Ensancha la caja o baja el tamaño.
        </div>

        <div
          v-if="errorGeneracion"
          class="flex items-start gap-3 px-4 py-3 rounded-2xl bg-red-50 text-red-700 text-[13px] font-semibold"
          role="alert"
          data-aviso="error-generacion"
          :data-codigo="errorGeneracion.code"
        >
          <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" />
          <span class="flex-1">No se pudo generar el PDF de prueba: {{ errorGeneracion.message }}</span>
          <button type="button" class="text-[12px] underline" @click="errorGeneracion = null">Cerrar</button>
        </div>

        <div
          v-if="erroresGuardado.length"
          class="px-4 py-3 rounded-2xl bg-red-50 text-red-700 text-[13px] font-semibold space-y-1"
          role="alert"
          data-aviso="errores-guardado"
        >
          <p class="flex items-center gap-2"><CircleAlert class="w-4 h-4" /> No se pudo guardar el diseño:</p>
          <ul class="list-disc pl-9">
            <li v-for="m in erroresGuardado" :key="m">{{ m }}</li>
          </ul>
        </div>

        <div class="grid grid-cols-[minmax(0,1fr)_320px] gap-6 items-start">
          <div class="bg-slate-100/70 rounded-[2rem] p-4 lg:p-6 min-w-0">
            <EditorCanvas
              :pdf-url="pdfUrl"
              :pagina="editor.pagina.value"
              :elementos="editor.elementos.value"
              :seleccion-id="editor.seleccionId.value"
              :schema="schema"
              @pdf-listo="alCargarPdf"
              @pdf-error="pdfConError = true"
              @seleccionar="editor.seleccionar"
              @mover="editor.mover"
            />
          </div>

          <EditorInspector
            :elemento="editor.seleccionado.value"
            :elementos="editor.elementos.value"
            :schema="schema"
            :pagina="editor.pagina.value"
            :ajuste="ajusteSeleccionado"
            :render="renderSeleccionado"
            @actualizar="(cambios) => editor.actualizar(editor.seleccionId.value, cambios)"
            @cambiar-origen="(valor) => editor.cambiarOrigen(editor.seleccionId.value, valor)"
            @seleccionar="editor.seleccionar"
          />
        </div>
      </div>
    </Sidebar>
  </AuthenticatedLayout>
</template>
