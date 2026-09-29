// Estado y operaciones del editor de diseño de Credential Flow. Todo en puntos PDF.
import { ref, computed } from "vue";
import {
  limitar,
  limitarElemento,
  redondear,
  xCentrada,
  yCentrada,
  nuevoUuid,
} from "@/Composables/CredentialFlow/coordenadas";

const ALTO_MIN_POR_TAMANO = 1.25; // la caja no debe quedar más baja que el texto

export function useDisenoEditor(schema) {
  const pagina = ref({ width: 0, height: 0 });
  const elementos = ref([]);
  const seleccionId = ref(null);
  const base = ref(""); // instantánea del último estado guardado (o cargado)

  const serializar = () =>
    JSON.stringify({ page: pagina.value, elements: elementos.value });

  const sinGuardar = computed(() => serializar() !== base.value);
  const seleccionado = computed(() => elementos.value.find((e) => e.id === seleccionId.value) ?? null);

  // Carga el diseño guardado (o uno vacío). Las medidas de la página son las REALES del PDF.
  function iniciar(disenoGuardado, ancho, alto) {
    pagina.value = { width: redondear(ancho), height: redondear(alto) };
    elementos.value = (disenoGuardado?.elements ?? []).map((e) =>
      limitarElemento({ ...e }, pagina.value, schema.elementoMin)
    );
    seleccionId.value = null;
    base.value = serializar();
  }

  function agregarTexto() {
    if (elementos.value.length >= schema.maxElementos) return false;

    const width = Math.min(300, pagina.value.width);
    const height = Math.min(40, pagina.value.height);
    const el = {
      id: nuevoUuid(),
      type: "text",
      field: null,
      text: "Texto de ejemplo",
      x: 0,
      y: 0,
      width,
      height,
      fontFamily: "Figtree",
      fontSize: 22,
      fontWeight: 700,
      color: "#000000",
      align: "center",
    };
    el.x = xCentrada(el, pagina.value);
    el.y = yCentrada(el, pagina.value);

    elementos.value.push(el);
    seleccionId.value = el.id;
    return true;
  }

  const buscar = (id) => elementos.value.find((e) => e.id === id);

  function seleccionar(id) {
    seleccionId.value = id ?? null;
  }

  // Aplica cambios validados a un elemento y lo mantiene dentro de la página.
  function actualizar(id, cambios) {
    const el = buscar(id);
    if (!el) return;

    const c = { ...cambios };
    if ("fontSize" in c) {
      const fs = Number(c.fontSize);
      if (!Number.isFinite(fs)) delete c.fontSize;
      else {
        c.fontSize = redondear(limitar(fs, schema.fontSizeMin, schema.fontSizeMax));
        // Si el texto no cabe en la caja, esta crece lo justo.
        const minimo = Math.ceil(c.fontSize * ALTO_MIN_POR_TAMANO);
        if ((c.height ?? el.height) < minimo) c.height = minimo;
      }
    }
    for (const k of ["x", "y", "width", "height"]) {
      if (k in c && !Number.isFinite(Number(c[k]))) delete c[k];
      else if (k in c) c[k] = Number(c[k]);
    }
    if ("fontWeight" in c) c.fontWeight = Number(c.fontWeight);
    if ("text" in c) c.text = String(c.text).slice(0, schema.textoMax);

    Object.assign(el, limitarElemento({ ...el, ...c }, pagina.value, schema.elementoMin));
  }

  const mover = (id, x, y) => actualizar(id, { x, y });

  function centrarHorizontal() {
    const el = seleccionado.value;
    if (el) el.x = xCentrada(el, pagina.value);
  }

  function centrarVertical() {
    const el = seleccionado.value;
    if (el) el.y = yCentrada(el, pagina.value);
  }

  function empujar(dx, dy) {
    const el = seleccionado.value;
    if (el) actualizar(el.id, { x: el.x + dx, y: el.y + dy });
  }

  function eliminarSeleccionado() {
    const i = elementos.value.findIndex((e) => e.id === seleccionId.value);
    if (i < 0) return;
    elementos.value.splice(i, 1);
    seleccionId.value = null;
  }

  // Diseño completo tal como lo espera el backend (claves exactas, sin estado de interfaz).
  function payload() {
    return {
      page: { width: pagina.value.width, height: pagina.value.height },
      elements: elementos.value.map((e) => ({
        id: e.id,
        type: e.type,
        field: e.field ?? null,
        text: e.text,
        x: e.x,
        y: e.y,
        width: e.width,
        height: e.height,
        fontFamily: e.fontFamily,
        fontSize: e.fontSize,
        fontWeight: e.fontWeight,
        color: e.color,
        align: e.align,
      })),
    };
  }

  const marcarGuardado = () => {
    base.value = serializar();
  };

  return {
    pagina,
    elementos,
    seleccionId,
    seleccionado,
    sinGuardar,
    iniciar,
    agregarTexto,
    seleccionar,
    actualizar,
    mover,
    centrarHorizontal,
    centrarVertical,
    empujar,
    eliminarSeleccionado,
    payload,
    marcarGuardado,
  };
}
