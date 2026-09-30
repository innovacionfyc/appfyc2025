<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from "vue";
import { Loader2, FileWarning } from "lucide-vue-next";
import { cargarPdf } from "@/Composables/CredentialFlow/pdfjs";
import { calcularEscala, ptAPx, pxAPt } from "@/Composables/CredentialFlow/coordenadas";
import { ESTILO_SIN_KERNING, useCamposDinamicos } from "@/Composables/CredentialFlow/camposDinamicos";
import { AVISO_HEREDADA, cargarFuentes, cssFamilia, lineaBase } from "@/Composables/CredentialFlow/fuentesCredential";

const props = defineProps({
  pdfUrl: { type: String, required: true },
  pagina: { type: Object, required: true }, // { width, height } en puntos PDF
  elementos: { type: Array, default: () => [] },
  seleccionId: { type: String, default: null },
  schema: { type: Object, required: true }, // catálogo de campos y constantes del autoajuste
});

const emit = defineEmits(["seleccionar", "mover", "pdf-listo", "pdf-error"]);

const ANCLAJE = { left: "start", center: "middle", right: "end" }; // text-anchor del SVG

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
const campos = useCamposDinamicos(props.schema);

// Todas las combinaciones familia+peso reproducibles en uso se cargan (FontFace real, sin
// sustitución silenciosa); mientras cargan, o si fallan, el texto NO se dibuja.
watch(
  () => JSON.stringify([...new Set(props.elementos.map((e) => `${e.fontFamily}|${e.fontWeight}`))]),
  () => cargarFuentes(props.elementos.map((e) => ({ familia: e.fontFamily, peso: e.fontWeight }))),
  { immediate: true }
);

// Tamaño con el que se DIBUJA un elemento. El tamaño configurado (el que se guarda) no cambia:
// el autoajuste de los campos dinámicos es solo de representación.
const tamanoVisual = (el) => campos.ajusteDe(el)?.size ?? el.fontSize;
const noCabe = (el) => campos.ajusteDe(el)?.noCabe === true;

const estadoRender = (el) => campos.renderDe(el);
const hayProblema = (el) => ["error", "noSoportado", "desconocido"].includes(estadoRender(el).estado);

const clasesContorno = (el) => {
  const problema = hayProblema(el) || noCabe(el);
  if (el.id === props.seleccionId) {
    return ["!outline-2 !outline-solid !outline-primary-vinotinto z-10", problema ? "bg-red-500/10" : "bg-primary-vinotinto/5"];
  }
  if (problema) return ["outline-red-500 bg-red-500/10"];
  if (campos.esDinamico(el)) return ["outline-sky-500 bg-sky-500/5 hover:outline-sky-600"];
  return ["outline-slate-400/70 hover:outline-slate-500"];
};

// Etiqueta sobre el elemento: distingue campos dinámicos y avisa de cualquier problema (no cabe,
// fuente sin cargar o con error, carácter no soportado, fuente heredada). Se coloca debajo cuando
// el elemento está pegado al borde superior para que el lienzo no la recorte.
const chip = (el) => {
  const abajo = ptAPx(el.y, escala.value) < 20;
  const posicion = abajo ? "top-full mt-0.5" : "-top-[18px]";
  const rojo = `bg-red-600 text-white ${posicion}`;
  const render = estadoRender(el);

  if (render.estado === "error") return { texto: "Fuente no disponible", clase: rojo };
  if (render.estado === "cargando") return { texto: "Cargando fuente…", clase: `bg-slate-600 text-white ${posicion}` };
  if (render.estado === "noSoportado") {
    return { texto: `Carácter no soportado: ${render.faltantes.map((c) => `«${c}»`).join(" ")}`, clase: rojo };
  }
  if (render.estado === "desconocido" && !campos.esDesconocido(el)) return { texto: "Fuente no válida", clase: rojo };

  if (!campos.esDinamico(el)) {
    return render.estado === "heredada" ? { texto: "Fuente heredada", clase: `bg-amber-600 text-white ${posicion}`, titulo: AVISO_HEREDADA } : null;
  }
  const etiqueta = campos.catalogo[el.field]?.etiqueta;

  if (campos.esDesconocido(el)) return { texto: "Campo desconocido", clase: rojo };
  if (noCabe(el)) return { texto: `No cabe · ${etiqueta}`, clase: rojo };
  if (render.estado === "heredada") return { texto: `${etiqueta} · Fuente heredada`, clase: `bg-amber-600 text-white ${posicion}`, titulo: AVISO_HEREDADA };

  const ajuste = campos.ajusteDe(el);
  const detalle = ajuste?.reducido ? ` · ${ajuste.size} pt` : "";
  return { texto: `${etiqueta}${detalle}`, clase: `bg-sky-600 text-white ${posicion}` };
};

// Texto SVG: viewBox en PUNTOS (1 unidad = 1 pt), tamaño en px = pt × escala. La línea base es
// explícita: y_base = alto/2 + ((ascent + descent) / 2 / unitsPerEm) × fontSize, con las métricas
// de la fuente (la misma fórmula del generador). Sin factores de calibración.
const puedeDibujar = (el) => ["lista", "heredada"].includes(estadoRender(el).estado);
const xTexto = (el) => (el.align === "left" ? 0 : el.align === "right" ? el.width : el.width / 2);
// Un texto fijo con saltos manuales dibuja una línea por salto (interlineado 1,2 × tamaño, como
// antes de la Fase 4) y el bloque queda centrado en la caja; con una sola línea coincide con la
// fórmula de línea base. Un campo dinámico es siempre una línea.
const INTERLINEADO = 1.2;
const lineasSvg = (el) => {
  const lineas = campos.lineasDe(el);
  const size = tamanoVisual(el);
  const base =
    estadoRender(el).estado === "lista"
      ? lineaBase(el.fontFamily, el.fontWeight, size, 0, el.height)
      : el.height / 2; // heredada: sin métricas, se centra con dominant-baseline
  return lineas.map((texto, i) => ({ texto, y: base + (i - (lineas.length - 1) / 2) * INTERLINEADO * size }));
};
const estiloTexto = (el) => ({
  fontFamily: cssFamilia(el.fontFamily),
  fontWeight: el.fontWeight,
  fill: el.color,
  ...ESTILO_SIN_KERNING, // TCPDF no aplica kerning ni ligaduras
});

const estiloElemento = (el) => ({
  left: `${ptAPx(el.x, escala.value)}px`,
  top: `${ptAPx(el.y, escala.value)}px`,
  width: `${ptAPx(el.width, escala.value)}px`,
  height: `${ptAPx(el.height, escala.value)}px`,
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
        class="absolute touch-none cursor-move outline outline-dashed outline-1"
        :class="[clasesContorno(el), arrastrando && el.id === seleccionId ? 'cursor-grabbing' : '']"
        :data-campo="el.field ?? undefined"
        :data-no-cabe="campos.esDinamico(el) ? (noCabe(el) ? 'si' : 'no') : undefined"
        :data-estado-texto="estadoRender(el).estado"
        :data-tamano-visual="tamanoVisual(el)"
        :style="estiloElemento(el)"
        @pointerdown.stop="iniciarArrastre($event, el)"
        @pointermove="moverArrastre"
        @pointerup="terminarArrastre"
        @pointercancel="terminarArrastre"
      >
        <!-- Una sola línea, sin salto automático (el autoajuste reduce los campos dinámicos hasta el 70 %). -->
        <svg
          class="absolute inset-0 w-full h-full pointer-events-none overflow-visible"
          :viewBox="`0 0 ${el.width} ${el.height}`"
          preserveAspectRatio="none"
          data-texto-svg
        >
          <text
            v-for="(linea, i) in puedeDibujar(el) ? lineasSvg(el) : []"
            :key="i"
            :x="xTexto(el)"
            :y="linea.y"
            :font-size="tamanoVisual(el)"
            :text-anchor="ANCLAJE[el.align] ?? 'middle'"
            :dominant-baseline="estadoRender(el).estado === 'heredada' ? 'central' : undefined"
            :style="estiloTexto(el)"
            xml:space="preserve"
            data-texto
          >{{ linea.texto }}</text>
        </svg>

        <span
          v-if="chip(el)"
          class="absolute left-0 px-1.5 py-1 rounded text-[10px] leading-none font-bold whitespace-nowrap pointer-events-none z-20"
          :class="chip(el).clase"
          :title="chip(el).titulo"
          data-chip-campo
        >{{ chip(el).texto }}</span>
      </div>
    </div>
  </div>
</template>
