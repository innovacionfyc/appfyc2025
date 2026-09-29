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
const TEXTO_FIJO_POR_DEFECTO = "Texto de ejemplo";

export function useDisenoEditor(schema) {
  const pagina = ref({ width: 0, height: 0 });
  const elementos = ref([]);
  const seleccionId = ref(null);
  const base = ref(""); // instantánea del último estado guardado (o cargado)

  // Claves del catálogo (vienen del backend en schema.campos).
  const clavesCatalogo = new Set((schema.campos ?? []).map((c) => c.key));

  // Texto fijo de cada elemento mientras se convierte en campo dinámico, por id. Solo vive en
  // memoria: no se guarda en el diseño (un campo dinámico persiste con text = '').
  const textosFijosRecordados = new Map();

  const serializar = () =>
    JSON.stringify({ page: pagina.value, elements: elementos.value });

  // Sin instantánea base (antes de iniciar()) no hay nada que comparar: no hay cambios pendientes.
  const sinGuardar = computed(() => base.value !== "" && serializar() !== base.value);
  const seleccionado = computed(() => elementos.value.find((e) => e.id === seleccionId.value) ?? null);

  // Elementos cuyo `field` ya no existe en el catálogo: no se sustituyen ni se borran; hay que
  // elegir una opción válida antes de poder guardar.
  const conCampoDesconocido = computed(() =>
    elementos.value.filter((e) => e.field !== null && e.field !== undefined && !clavesCatalogo.has(e.field))
  );

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
      text: TEXTO_FIJO_POR_DEFECTO,
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
    // En un campo dinámico `text` no tiene semántica: nunca se modifica desde aquí.
    if (el.field !== null && el.field !== undefined) delete c.text;
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

  // Cambia el contenido de un elemento: null = texto fijo, o una clave del catálogo.
  //  - fijo → dinámico: recuerda el texto fijo (en memoria), asigna `field` y deja text = ''.
  //  - dinámico → fijo: field = null y recupera el texto recordado; si no existe (p. ej. se
  //    recargó la página) usa «Texto de ejemplo».
  //  - dinámico → dinámico: cambia solo `field`; posición, caja y estilos no se tocan.
  function cambiarOrigen(id, valor) {
    const el = buscar(id);
    if (!el) return;

    const nuevo = valor === "" || valor === undefined ? null : valor;
    const actual = el.field ?? null;
    if (nuevo === actual) return;

    if (actual === null) {
      textosFijosRecordados.set(id, el.text);
      el.field = nuevo;
      el.text = "";
    } else if (nuevo === null) {
      el.field = null;
      el.text = textosFijosRecordados.has(id) ? textosFijosRecordados.get(id) : TEXTO_FIJO_POR_DEFECTO;
    } else {
      el.field = nuevo;
    }
  }

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
    textosFijosRecordados.delete(elementos.value[i].id);
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
        text: e.field ? "" : e.text, // un campo dinámico siempre se guarda con text = ''
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
    conCampoDesconocido,
    sinGuardar,
    iniciar,
    agregarTexto,
    seleccionar,
    actualizar,
    mover,
    cambiarOrigen,
    centrarHorizontal,
    centrarVertical,
    empujar,
    eliminarSeleccionado,
    payload,
    marcarGuardado,
  };
}
