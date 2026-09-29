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
import { Monitor, Info, CircleAlert, ArrowLeft } from "lucide-vue-next";

const props = defineProps({
  plantilla: { type: Object, required: true },
  diseno: { type: Object, default: null },
  pdfUrl: { type: String, required: true },
  schema: { type: Object, required: true },
});

const editor = useDisenoEditor(props.schema);

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
          @agregar-texto="editor.agregarTexto"
          @centrar-horizontal="editor.centrarHorizontal"
          @centrar-vertical="editor.centrarVertical"
          @eliminar="editor.eliminarSeleccionado"
          @guardar="guardar"
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
            @actualizar="(cambios) => editor.actualizar(editor.seleccionId.value, cambios)"
            @seleccionar="editor.seleccionar"
          />
        </div>
      </div>
    </Sidebar>
  </AuthenticatedLayout>
</template>
