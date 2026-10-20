// Asistente de importación inteligente (F4): estado del borrador, correcciones locales sin guardar y llamadas a la API segura de F3.
//
// Nada de lo que hace este archivo crea una base: analizar, corregir, validar y ver la vista previa solo leen o guardan el BORRADOR. La única llamada que crea la base
// es `confirmar`, y solo la dispara el botón «Crear base de participantes». Aquí nunca se inserta HTML: todo el texto se muestra escapado por Vue.
import { computed, inject, nextTick, provide, reactive, ref } from "vue";
import axios from "axios";

export const PASOS = [
  { id: "subir", numero: 1, titulo: "Subir Excel", corto: "Subir" },
  { id: "revisar", numero: 2, titulo: "Revisar datos", corto: "Revisar" },
  { id: "vista", numero: 3, titulo: "Vista previa", corto: "Vista previa" },
  { id: "confirmar", numero: 4, titulo: "Confirmar importación", corto: "Confirmar" },
];

export const TIPOS_DOCUMENTO = ["C.C.", "C.E.", "T.I.", "PASAPORTE", "NIT", "PEP", "PPT"];

export const ELEGIBILIDADES = [
  { valor: "pendiente", etiqueta: "Pendiente" },
  { valor: "elegible", etiqueta: "Elegible" },
  { valor: "no_elegible", etiqueta: "No elegible" },
];

export const ASISTENCIA = {
  completo: { etiqueta: "Completo", clase: "bg-emerald-50 text-emerald-700 ring-emerald-200" },
  cupo: { etiqueta: "Cupos", clase: "bg-amber-50 text-amber-700 ring-amber-200" },
  conflicto: { etiqueta: "Datos en conflicto", clase: "bg-rose-50 text-rose-700 ring-rose-200" },
  desconocida: { etiqueta: "Estado desconocido", clase: "bg-rose-50 text-rose-700 ring-rose-200" },
  sin_senal: { etiqueta: "Sin dato", clase: "bg-slate-100 text-slate-500 ring-slate-200" },
};

// Datos del certificado que se pueden revisar (el nombre del evento se decide en su propia tarjeta).
export const DATOS = [
  { campo: "fecha", etiqueta: "Fecha", ayuda: "Tal como se imprimirá, por ejemplo «16 DE OCTUBRE DE 2026».", requerido: true, largo: false },
  { campo: "intensidad_horaria", etiqueta: "Intensidad horaria", ayuda: "Por ejemplo «5 HORAS».", requerido: true, largo: false },
  { campo: "modalidad", etiqueta: "Modalidad", ayuda: "Se imprime exactamente como la escribas o la confirmes.", requerido: false, largo: false },
  { campo: "ciudad", etiqueta: "Ciudad", ayuda: "Solo si el evento la define. No se inventa.", requerido: false, largo: false },
  { campo: "texto_certificacion", etiqueta: "Texto de certificación", ayuda: "Solo si la plantilla lo imprime. Escríbelo tal como fue aprobado.", requerido: false, largo: true },
  { campo: "texto_complementario", etiqueta: "Texto complementario", ayuda: "Solo si la plantilla lo imprime.", requerido: false, largo: true },
];

// Qué significa cada bloqueo del servidor, en palabras simples, y en qué paso se resuelve.
export const BLOQUEOS = {
  plantilla_faltante: { titulo: "Falta elegir la plantilla", paso: "vista" },
  plantilla_invalida: { titulo: "La plantilla elegida no sirve para certificados oficiales", paso: "vista" },
  evento_sin_decision: { titulo: "Falta decidir a qué evento pertenece", paso: "revisar", ancla: "evento" },
  evento_invalido: { titulo: "El evento elegido ya no está disponible", paso: "revisar", ancla: "evento" },
  evento_sin_nombre: { titulo: "Falta el nombre del evento nuevo", paso: "revisar", ancla: "evento" },
  evento_ya_existe: { titulo: "Ese evento ya existe", paso: "revisar", ancla: "evento" },
  evento_similar_sin_confirmar: { titulo: "Falta confirmar que es un evento distinto", paso: "revisar", ancla: "evento" },
  archivo_repetido_en_evento: { titulo: "Este Excel ya se importó en ese evento", paso: "revisar", ancla: "evento" },
  dato_comun_faltante: { titulo: "Falta un dato del certificado", paso: "revisar", ancla: "datos" },
  dato_comun_sin_confirmar: { titulo: "Falta revisar un dato del certificado", paso: "revisar", ancla: "datos" },
  dato_comun_invalido: { titulo: "Un dato del certificado no se puede imprimir", paso: "revisar", ancla: "datos" },
  datos_del_diseno_incompletos: { titulo: "La plantilla imprime un dato que falta", paso: "revisar", ancla: "datos" },
  valores_desactualizados_sin_revisar: { titulo: "Faltan por revisar datos que pueden estar desactualizados", paso: "revisar", ancla: "avisos" },
  valores_ambiguos_sin_revisar: { titulo: "Faltan por revisar datos dudosos", paso: "revisar", ancla: "avisos" },
  sin_participantes: { titulo: "No queda ningún participante por importar", paso: "revisar", ancla: "participantes" },
  fila_invalida: { titulo: "Hay participantes con datos que corregir", paso: "revisar", ancla: "participantes" },
  documento_de_contacto_qa: { titulo: "Un documento coincide con un contacto de control (QA)", paso: "revisar", ancla: "participantes" },
  intensidad_individual_invalida: { titulo: "Una intensidad individual no se puede imprimir", paso: "revisar", ancla: "participantes" },
  catalogo_qa_incompleto: { titulo: "Los contactos de control (QA) no están completos", paso: "confirmar" },
};

export const AVISOS_FILA = {
  tipo_documento_por_confirmar: "Tipo de documento por confirmar",
  tipo_documento_propuesto_sin_confirmar: "El Excel sugiere un tipo de documento, pero no es seguro",
  valores_ambiguos: "Algún dato no se detectó con certeza",
  valores_posiblemente_desactualizados: "Viene de una hoja externa y puede estar desactualizado",
  asistencia_cupo: "Asistencia «Cupos»: se decide a mano",
  asistencia_conflicto: "La asistencia del Excel se contradice",
  asistencia_desconocida: "Estado de asistencia desconocido",
  documento_llego_como_numero: "El documento llegó como número (si empezaba por cero, ya se había perdido)",
  posible_contacto_qa: "Se parece a un contacto de control (QA)",
};

// Avisos que se resumen UNA sola vez (con la cantidad) en vez de repetirse en cada participante. El detalle de cada persona queda dentro de «Detalles» de su fila.
// No están aquí el tipo de documento por confirmar (tiene su propio filtro) ni los datos posiblemente desactualizados (tarjeta de IMPORTRANGE).
export const RESUMEN_AVISOS = {
  documento_llego_como_numero: (n) => `${n} ${n === 1 ? "documento llegó" : "documentos llegaron"} como número. Si alguno empezaba por cero, el Excel ya lo había perdido: compáralos con la fuente.`,
  valores_ambiguos: (n) => `${n} ${n === 1 ? "persona tiene" : "personas tienen"} datos que no se detectaron con certeza.`,
  tipo_documento_propuesto_sin_confirmar: (n) => `En ${n} ${n === 1 ? "fila" : "filas"} el Excel sugiere un tipo de documento, pero no es seguro: tú decides.`,
  asistencia_cupo: (n) => `${n} ${n === 1 ? "persona figura" : "personas figuran"} con asistencia «Cupos»: su elegibilidad se decide a mano.`,
  asistencia_conflicto: (n) => `${n} ${n === 1 ? "persona tiene" : "personas tienen"} datos de asistencia que se contradicen.`,
  asistencia_desconocida: (n) => `${n} ${n === 1 ? "persona tiene" : "personas tienen"} un estado de asistencia desconocido.`,
  posible_contacto_qa: (n) => `${n} ${n === 1 ? "persona se parece" : "personas se parecen"} a un contacto de control (QA): revísalo.`,
};

// Campo del servidor (columna del importador) → atributo `data-campo-fila` de la entrada de la tabla.
const CAMPO_FILA = { nombre_completo: "nombre_completo", documento: "documento", tipo_documento: "tipo_documento", correo: "correo", elegibilidad: "elegibilidad", intensidad_horaria: "intensidad_horaria" };

/** Espera (hasta ~1 s) a que exista el elemento y lo devuelve; null si nunca aparece. */
async function esperar(selector) {
  for (let i = 0; i < 25; i++) {
    const el = document.querySelector(selector);
    if (el) return el;
    await nextTick();
    await new Promise((r) => setTimeout(r, 40));
  }

  return null;
}

/** Lleva el elemento a la vista, lo resalta unos segundos y le da el foco (sin insertar HTML). */
export function destacar(el, enfocar = true) {
  if (!el) return false;
  el.scrollIntoView({ behavior: "smooth", block: "center" });
  el.classList.add("ring-2", "ring-amber-400", "ring-offset-2", "transition-shadow");
  setTimeout(() => el.classList.remove("ring-2", "ring-amber-400", "ring-offset-2"), 2600);
  if (enfocar) {
    const objetivo = el.matches("input, select, textarea, button") ? el : el.querySelector("input:not([type=hidden]), select, textarea, button");
    objetivo?.focus({ preventScroll: true });
  }

  return true;
}

/** Mensaje, código y detalles de un error de la API (o un texto genérico si no se pudo hablar con el servidor). */
export function errorDeApi(e) {
  const r = e?.response;
  if (!r) return { codigo: "SIN_CONEXION", mensaje: "No se pudo conectar con el servidor. Revisa tu conexión e inténtalo de nuevo.", detalles: [], estado: 0 };
  const d = r.data ?? {};
  if (r.status === 422 && d.errors) {
    const primero = Object.values(d.errors)[0];
    return { codigo: "VALIDACION", mensaje: Array.isArray(primero) ? primero[0] : "Revisa los datos enviados.", detalles: [], estado: 422 };
  }
  if (r.status === 419) return { codigo: "SESION", mensaje: "Tu sesión venció. Recarga la página e inicia sesión de nuevo.", detalles: [], estado: 419 };
  if (r.status === 429) return { codigo: "DEMASIADAS", mensaje: "Demasiados intentos seguidos. Espera un momento y vuelve a intentarlo.", detalles: [], estado: 429 };
  if (r.status === 413) return { codigo: "ARCHIVO_GRANDE", mensaje: "El archivo es demasiado grande.", detalles: [], estado: 413 };
  return {
    codigo: d.error?.code ?? "ERROR",
    mensaje: d.error?.message ?? "No se pudo completar. Inténtalo de nuevo y, si sigue igual, avisa al equipo técnico.",
    detalles: Array.isArray(d.error?.detalles) ? d.error.detalles : [],
    estado: r.status,
  };
}

export const claveEstado = Symbol("asistente-importacion");

/** Crea el estado del asistente (una sola vez, en la página) y lo comparte con los pasos. */
export function crearAsistente(props) {
  const paso = ref("subir");
  const borrador = ref(null); // lo último que dijo el servidor
  const cargando = ref(false); // analizando / abriendo un borrador
  const guardando = ref(false);
  const confirmando = ref(false);
  const error = ref(null); // {codigo, mensaje, detalles}
  const aviso = ref(null); // mensaje positivo breve (aria-live)
  const desactualizado = ref(false); // otra pestaña/acción cambió el borrador
  const lote = ref(null); // {id, url} cuando ya se creó la base
  const erroresCampo = reactive({}); // 'fila:campo' o 'dato:campo' -> mensaje
  const foco = ref(null); // {tipo:'fila', fila, campo} o {tipo:'filtro', id}: la tabla de participantes lo atiende y lo limpia

  const vacia = () => ({ plantilla_id: undefined, nombre_base: undefined, evento: undefined, datos_comunes: {}, confirmar_datos: {}, confirmados: {}, participantes: {} });
  const edicion = reactive(vacia());

  const sinGuardar = computed(
    () =>
      edicion.plantilla_id !== undefined ||
      edicion.nombre_base !== undefined ||
      edicion.evento !== undefined ||
      Object.keys(edicion.datos_comunes).length > 0 ||
      Object.keys(edicion.confirmar_datos).length > 0 ||
      Object.keys(edicion.confirmados).length > 0 ||
      Object.keys(edicion.participantes).length > 0
  );

  const limpiarEdicion = () => Object.assign(edicion, vacia());
  const limpiarErrores = () => Object.keys(erroresCampo).forEach((k) => delete erroresCampo[k]);

  // ── Vistas con las correcciones locales aplicadas ───────────────────────────
  const evento = computed(() => {
    const b = borrador.value;
    if (!b?.evento) return null;
    const base = b.evento.decision ?? { decision: null, evento_id: null, nombre: null, confirmar_distinto: false };
    return { propuesta: b.evento.propuesta, decision: { ...base, ...(edicion.evento ?? {}) } };
  });

  const datos = computed(() => {
    const b = borrador.value;
    if (!b) return [];
    return DATOS.map((def) => {
      const s = b.datos_comunes?.[def.campo] ?? {};
      const editado = Object.prototype.hasOwnProperty.call(edicion.datos_comunes, def.campo);
      const valorLocal = editado ? edicion.datos_comunes[def.campo] : null;
      // Un texto local vacío significa «volver a lo que trae el Excel».
      const valor = editado ? (valorLocal === "" ? (s.propuesta ?? "") : valorLocal) : (s.valor ?? "");
      const origen = editado && valorLocal !== "" ? "admin" : s.origen;
      const confirmadoLocal = edicion.confirmar_datos[def.campo];
      const confirmado = origen === "admin" || (confirmadoLocal !== undefined ? confirmadoLocal : s.confirmado === true);
      const vacio = valor === "" || valor === null;
      const dudoso = origen === "excel" && !vacio && (s.confianza !== "confiable" || s.desactualizable === true);
      let estado;
      if (vacio) estado = def.requerido ? "faltante" : "sin_dato";
      else if (dudoso && !confirmado) estado = "revisar";
      else if (dudoso && confirmado) estado = "confirmado";
      else if (origen === "admin") estado = "escrito";
      else estado = "detectado";
      return { ...def, valor, propuesta: s.propuesta ?? null, original: s.original ?? null, referencia: s.referencia ?? null, desactualizable: s.desactualizable === true, motivos: s.motivos ?? [], origen, confirmado, estado, editado };
    });
  });

  const participantes = computed(() => {
    const b = borrador.value;
    if (!b) return [];
    return (b.participantes ?? []).map((p) => {
      const cambios = edicion.participantes[p.fila] ?? {};
      const fila = { ...p };
      for (const [campo, v] of Object.entries(cambios)) {
        if (campo === "excluir") fila.excluir = v === true;
        else if (v !== null) fila[campo] = v;
      }
      const tocados = Object.keys(cambios);
      // Datos mínimos: nombre y documento (el correo y el tipo se pueden completar después).
      fila.faltan = [];
      if (!String(fila.nombre_completo ?? "").trim()) fila.faltan.push("nombre");
      if (!String(fila.documento ?? "").trim()) fila.faltan.push("documento");
      fila.modificados = tocados.filter((c) => c !== "excluir");
      fila.tieneCambios = tocados.length > 0;
      return fila;
    });
  });

  const resumenParticipantes = computed(() => {
    const todos = participantes.value;
    const incluidos = todos.filter((p) => !p.excluir);
    return {
      total: todos.length,
      incluidos: incluidos.length,
      excluidos: todos.length - incluidos.length,
      tipoPendiente: incluidos.filter((p) => !p.tipo_documento).length,
      elegibilidadPendiente: incluidos.filter((p) => p.elegibilidad === "pendiente").length,
      elegibles: incluidos.filter((p) => p.elegibilidad === "elegible").length,
      cupos: incluidos.filter((p) => p.senal === "cupo").length,
      completos: incluidos.filter((p) => p.senal === "completo").length,
      faltanDatos: incluidos.filter((p) => p.faltan.length > 0).length,
    };
  });

  // Cuántas personas tienen cada aviso (solo las que se importarán): se muestra una vez, con acceso a las filas.
  const avisosResumen = computed(() => {
    const incluidos = participantes.value.filter((p) => !p.excluir);

    return Object.keys(RESUMEN_AVISOS)
      .map((codigo) => ({ codigo, cantidad: incluidos.filter((p) => p.avisos.includes(codigo)).length }))
      .filter((a) => a.cantidad > 0)
      .map((a) => ({ ...a, texto: RESUMEN_AVISOS[a.codigo](a.cantidad) }));
  });

  const validacion = computed(() => borrador.value?.validacion ?? null);
  const plantillaId = computed(() => (edicion.plantilla_id !== undefined ? edicion.plantilla_id : (borrador.value?.plantilla_id ?? null)));
  const confirmados = computed(() => ({ ...(borrador.value?.confirmados ?? {}), ...edicion.confirmados }));

  // ── Edición local ───────────────────────────────────────────────────────────
  function editarFila(fila, campo, valor) {
    const f = { ...(edicion.participantes[fila] ?? {}) };
    f[campo] = valor;
    edicion.participantes = { ...edicion.participantes, [fila]: f };
    delete erroresCampo[`${fila}:${campo}`];
  }

  function restaurarFila(fila, campo) {
    const f = { ...(edicion.participantes[fila] ?? {}) };
    f[campo] = null; // el servidor vuelve a usar lo que trae el Excel
    edicion.participantes = { ...edicion.participantes, [fila]: f };
    delete erroresCampo[`${fila}:${campo}`];
  }

  function editarDato(campo, valor) {
    edicion.datos_comunes = { ...edicion.datos_comunes, [campo]: valor };
    delete erroresCampo[`dato:${campo}`];
  }

  function confirmarDato(campo, si) {
    edicion.confirmar_datos = { ...edicion.confirmar_datos, [campo]: si };
  }

  function decidirEvento(cambios) {
    edicion.evento = { ...(edicion.evento ?? {}), ...cambios };
  }

  function elegirPlantilla(id) {
    edicion.plantilla_id = id === "" || id === null ? null : Number(id);
  }

  function marcarConfirmacion(clave, si) {
    edicion.confirmados = { ...edicion.confirmados, [clave]: si };
  }

  // ── Llamadas ────────────────────────────────────────────────────────────────
  function aplicar(b) {
    borrador.value = b;
    limpiarEdicion();
    desactualizado.value = false;
    if (b.estado === "confirmado" && b.lote_id) lote.value = { id: b.lote_id, url: route("credential-flow.lotes.show", b.lote_id) };
  }

  async function analizar(archivo) {
    cargando.value = true;
    error.value = null;
    try {
      const fd = new FormData();
      fd.append("archivo", archivo);
      const { data } = await axios.post(route("credential-flow.importaciones.analizar"), fd);
      aplicar(data.borrador);
      aviso.value = data.recuperado ? "Ya habías subido este mismo Excel: recuperamos tu borrador." : "Listo, analizamos tu Excel.";
      paso.value = "revisar";
      return data;
    } catch (e) {
      error.value = errorDeApi(e);
      return null;
    } finally {
      cargando.value = false;
    }
  }

  async function abrir(id) {
    cargando.value = true;
    error.value = null;
    try {
      const { data } = await axios.get(route("credential-flow.importaciones.show", id));
      aplicar(data.borrador);
      paso.value = data.borrador.estado === "confirmado" ? "confirmar" : "revisar";
      return data.borrador;
    } catch (e) {
      error.value = errorDeApi(e);
      return null;
    } finally {
      cargando.value = false;
    }
  }

  function construirPayload() {
    const b = borrador.value;
    const p = { version: b.version };
    if (edicion.plantilla_id !== undefined) p.plantilla_id = edicion.plantilla_id;
    if (edicion.nombre_base !== undefined) p.nombre_base = edicion.nombre_base === "" ? null : edicion.nombre_base;
    if (edicion.evento !== undefined) p.evento = { ...(evento.value?.decision ?? {}) };
    if (Object.keys(edicion.datos_comunes).length) p.datos_comunes = Object.fromEntries(Object.entries(edicion.datos_comunes).map(([c, v]) => [c, v === "" ? null : v]));
    const conf = {};
    if (Object.keys(edicion.confirmar_datos).length) {
      const actuales = new Set(Object.entries(b.datos_comunes ?? {}).filter(([, d]) => d.confirmado && d.origen !== "admin").map(([c]) => c));
      for (const [c, si] of Object.entries(edicion.confirmar_datos)) si ? actuales.add(c) : actuales.delete(c);
      conf.datos_comunes = [...actuales];
    }
    for (const [k, v] of Object.entries(edicion.confirmados)) conf[k] = v;
    if (Object.keys(conf).length) p.confirmados = conf;
    if (Object.keys(edicion.participantes).length) p.participantes = edicion.participantes;
    return p;
  }

  /** Guarda las correcciones locales en el borrador (nunca crea la base). Devuelve true si quedó guardado. */
  async function guardar() {
    if (!borrador.value) return false;
    if (!sinGuardar.value) return true;
    guardando.value = true;
    error.value = null;
    limpiarErrores();
    try {
      const { data } = await axios.put(route("credential-flow.importaciones.correcciones", borrador.value.id), construirPayload());
      aplicar(data.borrador);
      aviso.value = "Cambios guardados.";
      return true;
    } catch (e) {
      const err = errorDeApi(e);
      if (err.codigo === "BORRADOR_DESACTUALIZADO") desactualizado.value = true;
      for (const d of err.detalles) {
        const m = /^participantes\.(\d+)\.(\w+)$/.exec(d.campo ?? "");
        const c = /^datos_comunes\.(\w+)$/.exec(d.campo ?? "");
        if (m) erroresCampo[`${m[1]}:${m[2]}`] = d.mensaje;
        else if (c) erroresCampo[`dato:${c[1]}`] = d.mensaje;
      }
      error.value = err;
      return false;
    } finally {
      guardando.value = false;
    }
  }

  /** Descarta lo no guardado y vuelve a pedir el borrador al servidor. */
  async function recargar() {
    if (!borrador.value) return;
    limpiarEdicion();
    limpiarErrores();
    await abrir(borrador.value.id);
  }

  async function confirmar() {
    if (!borrador.value || confirmando.value || lote.value) return null;
    confirmando.value = true;
    error.value = null;
    try {
      if (!(await guardar())) return null;
      const { data } = await axios.post(route("credential-flow.importaciones.confirmar", borrador.value.id), { version: borrador.value.version, confirmar: true });
      lote.value = { id: data.lote_id, url: data.redirect };
      aviso.value = "La base de participantes se creó. No se emitió ningún certificado.";
      return data;
    } catch (e) {
      const err = errorDeApi(e);
      if (err.codigo === "BORRADOR_DESACTUALIZADO") desactualizado.value = true;
      if (err.codigo === "BORRADOR_YA_CONFIRMADO") await abrir(borrador.value.id);
      error.value = err;
      return null;
    } finally {
      confirmando.value = false;
    }
  }

  async function descartar() {
    if (!borrador.value) return false;
    try {
      await axios.post(route("credential-flow.importaciones.rechazar", borrador.value.id));
      borrador.value = null;
      limpiarEdicion();
      paso.value = "subir";
      aviso.value = "Borrador descartado. Sus datos personales se eliminaron.";
      return true;
    } catch (e) {
      error.value = errorDeApi(e);
      return false;
    }
  }

  function nuevoArchivo() {
    borrador.value = null;
    lote.value = null;
    limpiarEdicion();
    limpiarErrores();
    error.value = null;
    paso.value = "subir";
  }

  async function irA(id) {
    if (id === paso.value) return true;
    if (id !== "subir" && !borrador.value) return false;
    if (sinGuardar.value && !lote.value && !(await guardar())) return false;
    paso.value = id;
    return true;
  }

  /**
   * «Ir a resolverlo»: cambia al paso que corresponde, muestra la sección y lleva el foco al campo exacto que falta (la fila, el dato, la decisión del evento, la
   * confirmación o la plantilla). Si el campo está en una fila oculta por la búsqueda o la paginación, la tabla la deja visible.
   */
  async function resolver(b) {
    const def = BLOQUEOS[b.codigo] ?? { paso: "revisar" };
    if (!(await irA(def.paso))) return false;
    await nextTick();
    let el = null;
    if (def.paso === "vista") {
      el = await esperar("#plantilla");
    } else if (def.paso !== "revisar") {
      el = await esperar("[data-resumen-confirmar]");
    } else if (b.fila != null) {
      foco.value = { tipo: "fila", fila: Number(b.fila), campo: CAMPO_FILA[b.campo] ?? null };

      return true; // la tabla lo atiende
    } else if (b.campo && b.codigo.startsWith("dato_comun")) {
      el = await esperar(`[data-dato="${b.campo}"]`);
    } else if (def.ancla === "evento") {
      el = await esperar("[data-seccion=evento]");
    } else if (def.ancla === "datos") {
      el = await esperar("[data-seccion=datos]");
    } else if (b.codigo === "valores_desactualizados_sin_revisar") {
      el = await esperar("[data-aviso-importrange]");
    } else if (b.codigo === "valores_ambiguos_sin_revisar") {
      el = await esperar("[data-confirmar-ambiguos]");
    } else {
      el = await esperar("[data-seccion=participantes]");
    }

    return destacar(el);
  }

  const store = {
    props, paso, borrador, cargando, guardando, confirmando, error, aviso, desactualizado, lote, erroresCampo, edicion, foco, avisosResumen, resolver,
    sinGuardar, evento, datos, participantes, resumenParticipantes, validacion, plantillaId, confirmados,
    editarFila, restaurarFila, editarDato, confirmarDato, decidirEvento, elegirPlantilla, marcarConfirmacion,
    analizar, abrir, guardar, recargar, confirmar, descartar, nuevoArchivo, irA,
  };
  provide(claveEstado, store);

  return store;
}

export function useAsistente() {
  return inject(claveEstado);
}

/** Vista previa del certificado: datos que se imprimirían y, si se puede, el PDF (como URL temporal del navegador). */
export async function pedirVistaPrevia(id, fila) {
  const { data } = await axios.get(route("credential-flow.importaciones.vista-previa", id), { params: fila ? { fila } : {} });

  return data.vista_previa;
}

export async function pedirPdfVistaPrevia(id, fila) {
  const r = await axios.get(route("credential-flow.importaciones.vista-previa.pdf", id), { params: fila ? { fila } : {}, responseType: "blob" });

  return URL.createObjectURL(new Blob([r.data], { type: "application/pdf" }));
}

/** Los errores de un PDF llegan como blob JSON: se lee el mensaje. */
export async function mensajeDeErrorBlob(e) {
  const d = e?.response?.data;
  if (d instanceof Blob) {
    try {
      const j = JSON.parse(await d.text());
      return j.error?.message ?? "No se pudo dibujar el PDF.";
    } catch {
      return "No se pudo dibujar el PDF.";
    }
  }

  return errorDeApi(e).mensaje;
}

export const formatearTamano = (bytes) => (bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / (1024 * 1024)).toFixed(1)} MB`);

/** Parte un texto en trozos para resaltar una diferencia sin usar HTML. */
export function partirResaltado(texto, resaltado) {
  if (!texto) return [];
  if (!resaltado) return [{ texto, resaltar: false }];
  const i = texto.toLowerCase().indexOf(String(resaltado).toLowerCase());
  if (i < 0) return [{ texto, resaltar: false }];

  return [
    { texto: texto.slice(0, i), resaltar: false },
    { texto: texto.slice(i, i + resaltado.length), resaltar: true },
    { texto: texto.slice(i + resaltado.length), resaltar: false },
  ].filter((t) => t.texto !== "");
}
