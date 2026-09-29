<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from "vue";
import { Loader2, FileWarning } from "lucide-vue-next";
import { cargarPdf } from "@/Composables/CredentialFlow/pdfjs";
import { calcularEscala, ptAPx, pxAPt } from "@/Composables/CredentialFlow/coordenadas";

const props = defineProps({
  pdfUrl: { type: String, required: true },
  pagina: { type: Object, required: true }, // { width, height } en puntos PDF
  elementos: { type: Array, default: () => [] },
  seleccionId: { type: String, default: null },
});

const emit = defineEmits(["seleccionar", "mover", "pdf-listo", "pdf-error"]);

const FUENTES_CSS = {
  Figtree: "'Figtree', sans-serif",
  Arial: "Arial, Helvetica, sans-serif",
  "sans-serif": "sans-serif",
};
const JUSTIFICADO = { left: "flex-start", center: "center", right: "flex-end" };

const contenedor = ref(null);
const lienzo = ref(null);
const cargando = ref(true);
const errorPdf = ref("");
const anchoDisponible = ref(0);
const altoVentana = ref(typeof window !== "undefined" ? window.innerHeight : 800);
const arrastrando = ref(false);

let paginaPdf = null; // PDFPageProxy (no reactivo a propósito)
let tareaRender = null;
let temporizador = null;
let observador = null;

// ── Tamaño y escala ───────────────────────────────────────────────────────────
// El canvas se ajusta al ancho disponible y a la altura de la ventana. Solo cambia la escala
// (px por pt): las coordenadas de los elementos, en puntos PDF, no se modifican nunca.
const anchoCanvas = computed(() => {
  if (!props.pagina.width) return 0;
  const porAltura = Math.max(420, altoVentana.value - 260) * (props.pagina.width / props.pagina.height);
  return Math.floor(Math.max(0, Math.min(anchoDisponible.value, porAltura, 1400)));
});
const escala = computed(() => calcularEscala(anchoCanvas.value, props.pagina.width));
const altoCanvas = computed(() => Math.round(ptAPx(props.pagina.height, escala.value)));

// ── Render del PDF ────────────────────────────────────────────────────────────
async function dibujar() {
  if (!paginaPdf || !lienzo.value || !escala.value) return;

  const dpr = Math.min(window.devicePixelRatio || 1, 2);
  const vista = paginaPdf.getViewport({ scale: escala.value * dpr });

  if (tareaRender) {
    tareaRender.cancel();
    tareaRender = null;
  }

  const c = lienzo.value;
  c.width = Math.floor(vista.width);
  c.height = Math.floor(vista.height);

  const tarea = paginaPdf.render({ canvas: c, viewport: vista });
  tareaRender = tarea;
  try {
    await tarea.promise;
  } catch (e) {
    if (e?.name !== "RenderingCancelledException") {
      errorPdf.value = "No se pudo dibujar el PDF.";
      emit("pdf-error", e);
    }
  }
}

function programarDibujo() {
  clearTimeout(temporizador);
  temporizador = setTimeout(dibujar, 180);
}

watch(anchoCanvas, (nuevo, anterior) => {
  if (nuevo && Math.abs(nuevo - (anterior ?? 0)) >= 2) programarDibujo();
});

onMounted(async () => {
  observador = new ResizeObserver(([entrada]) => {
    anchoDisponible.value = Math.floor(entrada.contentRect.width);
  });
  observador.observe(contenedor.value);
  window.addEventListener("resize", alCambiarVentana);

  try {
    const pdf = await cargarPdf(props.pdfUrl);
    paginaPdf = pdf.pagina;
    emit("pdf-listo", { ancho: pdf.ancho, alto: pdf.alto, paginas: pdf.paginas });
  } catch (e) {
    errorPdf.value = "No se pudo cargar el PDF de esta plantilla.";
    emit("pdf-error", e);
  } finally {
    cargando.value = false;
  }
});

onBeforeUnmount(() => {
  clearTimeout(temporizador);
  observador?.disconnect();
  window.removeEventListener("resize", alCambiarVentana);
  tareaRender?.cancel();
});

function alCambiarVentana() {
  altoVentana.value = window.innerHeight;
}

// Cuando ya se conocen las medidas de la página y el PDF está listo, se dibuja la primera vez.
watch(
  () => [cargando.value, escala.value > 0],
  ([terminoCarga, hayEscala]) => {
    if (terminoCarga === false && hayEscala && paginaPdf) dibujar();
  },
  { flush: "post" }
);

// ── Elementos ─────────────────────────────────────────────────────────────────
const estiloElemento = (el) => ({
  left: `${ptAPx(el.x, escala.value)}px`,
  top: `${ptAPx(el.y, escala.value)}px`,
  width: `${ptAPx(el.width, escala.value)}px`,
  height: `${ptAPx(el.height, escala.value)}px`,
  fontSize: `${ptAPx(el.fontSize, escala.value)}px`,
  fontFamily: FUENTES_CSS[el.fontFamily] ?? FUENTES_CSS["sans-serif"],
  fontWeight: el.fontWeight,
  color: el.color,
  textAlign: el.align,
  justifyContent: JUSTIFICADO[el.align] ?? "center",
});

// ── Arrastre con Pointer Events (mouse y touch) ───────────────────────────────
let arrastre = null;

function iniciarArrastre(e, el) {
  if (e.pointerType === "mouse" && e.button !== 0) return;
  emit("seleccionar", el.id);
  e.currentTarget.setPointerCapture(e.pointerId);
  arrastre = { id: el.id, pointerId: e.pointerId, x0: e.clientX, y0: e.clientY, xEl: el.x, yEl: el.y };
  arrastrando.value = true;
}

function moverArrastre(e) {
  if (!arrastre || e.pointerId !== arrastre.pointerId) return;
  const dx = pxAPt(e.clientX - arrastre.x0, escala.value);
  const dy = pxAPt(e.clientY - arrastre.y0, escala.value);
  // El límite de página se aplica en useDisenoEditor.actualizar (un elemento no puede salir del PDF).
  emit("mover", arrastre.id, arrastre.xEl + dx, arrastre.yEl + dy);
}

function terminarArrastre(e) {
  if (!arrastre || e.pointerId !== arrastre.pointerId) return;
  if (e.currentTarget.hasPointerCapture?.(e.pointerId)) e.currentTarget.releasePointerCapture(e.pointerId);
  arrastre = null;
  arrastrando.value = false;
}

// ── Guías del centro de la página ─────────────────────────────────────────────
const seleccionado = computed(() => props.elementos.find((e) => e.id === props.seleccionId) ?? null);
const TOLERANCIA_PT = 0.6;
const centradoH = computed(
  () => !!seleccionado.value && Math.abs(seleccionado.value.x + seleccionado.value.width / 2 - props.pagina.width / 2) <= TOLERANCIA_PT
);
const centradoV = computed(
  () => !!seleccionado.value && Math.abs(seleccionado.value.y + seleccionado.value.height / 2 - props.pagina.height / 2) <= TOLERANCIA_PT
);
</script>

<template>
  <div ref="contenedor" class="w-full flex justify-center">
    <!-- Estados de carga / error (antes de conocer las medidas de la página) -->
    <div
      v-if="cargando || errorPdf"
      class="w-full min-h-[320px] rounded-[2rem] bg-white border border-slate-100 flex flex-col items-center justify-center gap-3 text-center p-8"
    >
      <template v-if="errorPdf">
        <FileWarning class="w-10 h-10 text-rose-400" />
        <p class="font-bold text-slate-700">{{ errorPdf }}</p>
        <p class="text-sm text-slate-400 max-w-sm">Recarga la página. Si el problema continúa, avisa al equipo técnico.</p>
      </template>
      <template v-else>
        <Loader2 class="w-8 h-8 text-primary-vinotinto animate-spin" />
        <p class="text-sm font-bold text-slate-500">Cargando PDF…</p>
      </template>
    </div>

    <!-- Lienzo -->
    <div
      v-show="!cargando && !errorPdf && anchoCanvas > 0"
      class="relative bg-white shadow-xl ring-1 ring-slate-200 overflow-hidden select-none"
      :style="{ width: anchoCanvas + 'px', height: altoCanvas + 'px' }"
      data-editor-lienzo
      :data-escala="escala"
      @pointerdown="emit('seleccionar', null)"
    >
      <canvas ref="lienzo" class="absolute inset-0 w-full h-full pointer-events-none" />

      <!-- Guías permanentes: centro vertical y horizontal -->
      <div
        class="absolute inset-y-0 left-1/2 -translate-x-1/2 border-l border-dashed pointer-events-none transition-colors"
        :class="centradoH ? 'border-primary-naranja' : 'border-primary-vinotinto/30'"
        data-guia="vertical"
      />
      <div
        class="absolute inset-x-0 top-1/2 -translate-y-1/2 border-t border-dashed pointer-events-none transition-colors"
        :class="centradoV ? 'border-primary-naranja' : 'border-primary-vinotinto/30'"
        data-guia="horizontal"
      />

      <div
        v-for="el in elementos"
        :key="el.id"
        :data-elemento="el.id"
        class="absolute flex items-center touch-none cursor-move outline outline-dashed outline-1 outline-slate-400/70"
        :class="[
          el.id === seleccionId ? '!outline-2 !outline-solid !outline-primary-vinotinto bg-primary-vinotinto/5 z-10' : 'hover:outline-slate-500',
          arrastrando && el.id === seleccionId ? 'cursor-grabbing' : '',
        ]"
        :style="estiloElemento(el)"
        @pointerdown.stop="iniciarArrastre($event, el)"
        @pointermove="moverArrastre"
        @pointerup="terminarArrastre"
        @pointercancel="terminarArrastre"
      >
        <span class="block w-full whitespace-pre-wrap break-words leading-[1.2] pointer-events-none">{{ el.text }}</span>
      </div>
    </div>
  </div>
</template>
