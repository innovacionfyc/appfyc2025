// Asistente de importación inteligente (F4/F5): estado del borrador, correcciones locales sin guardar y llamadas a la API segura de F3.
// Recorrido GUIADO: Subir Excel → Confirmar evento → Datos del certificado → Personas → Ver el PDF → Crear la base. Son etapas de una misma pantalla (no rutas nuevas).
// El asistente RECOMIENDA el siguiente paso, pero nunca confirma nada por la persona: cada confirmación es un clic suyo.
//
// Nada de lo que hace este archivo crea una base: analizar, corregir, validar y ver la vista previa solo leen o guardan el BORRADOR. La única llamada que crea la base
// es `confirmar`, y solo la dispara el botón «Crear base de participantes». Aquí nunca se inserta HTML: todo el texto se muestra escapado por Vue.
import { computed, inject, nextTick, onBeforeUnmount, provide, reactive, ref, watchEffect } from "vue";
import axios from "axios";

export const PASOS = [
  { id: "subir", numero: 1, titulo: "Subir Excel", corto: "Subir" },
  { id: "evento", numero: 2, titulo: "Confirmar evento", corto: "Evento" },
  { id: "datos", numero: 3, titulo: "Datos del certificado", corto: "Datos" },
  { id: "personas", numero: 4, titulo: "Revisar personas", corto: "Personas" },
  { id: "vista", numero: 5, titulo: "Ver el PDF", corto: "PDF" },
  { id: "confirmar", numero: 6, titulo: "Crear la base", corto: "Crear" },
];

// Códigos de bloqueo del servidor que se resuelven en la etapa de las personas.
const BLOQUEOS_PERSONAS = ["valores_ambiguos_sin_revisar", "sin_participantes", "fila_invalida", "documento_de_contacto_qa", "intensidad_individual_invalida", "modalidad_faltante", "modalidad_individual_invalida", "modalidad_por_revisar", "elegibilidad_sin_decidir", "tipo_documento_sin_confirmar"];

// «la fecha», «el texto de certificación»…: cómo se nombra cada dato en una frase (y el género para «correcta/correcto»).
export const NOMBRES_DATOS = {
  fecha: ["la fecha", "correcta"],
  intensidad_horaria: ["la intensidad horaria", "correcta"],
  modalidad: ["la modalidad", "correcta"],
  ciudad: ["la ciudad", "correcta"],
  texto_certificacion: ["el texto de certificación", "correcto"],
  texto_complementario: ["el texto complementario", "correcto"],
};
export const nombreEnFrase = (d) => NOMBRES_DATOS[d.campo]?.[0] ?? String(d.etiqueta ?? d.campo).toLowerCase();

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
  { campo: "fecha", etiqueta: "Fecha", ayuda: "Tal como se imprimirá, por ejemplo «16 de octubre de 2026» o «22 y 23 de octubre de 2026».", requerido: true, largo: false },
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
  evento_sin_decision: { titulo: "Falta decidir a qué evento pertenece", paso: "evento", ancla: "evento" },
  evento_invalido: { titulo: "El evento elegido ya no está disponible", paso: "evento", ancla: "evento" },
  evento_sin_nombre: { titulo: "Falta el nombre del evento nuevo", paso: "evento", ancla: "evento" },
  evento_ya_existe: { titulo: "Ese evento ya existe", paso: "evento", ancla: "evento" },
  evento_similar_sin_confirmar: { titulo: "Falta confirmar que es un evento distinto", paso: "evento", ancla: "evento" },
  archivo_repetido_en_evento: { titulo: "Este Excel ya se importó en ese evento", paso: "evento", ancla: "evento" },
  dato_comun_faltante: { titulo: "Falta un dato del certificado", paso: "datos", ancla: "datos" },
  dato_comun_sin_confirmar: { titulo: "Falta revisar un dato del certificado", paso: "datos", ancla: "datos" },
  dato_comun_invalido: { titulo: "Un dato del certificado no se puede imprimir", paso: "datos", ancla: "datos" },
  datos_del_diseno_incompletos: { titulo: "La plantilla imprime un dato que falta", paso: "datos", ancla: "datos" },
  valores_desactualizados_sin_revisar: { titulo: "Faltan por revisar datos que pueden estar desactualizados", paso: "datos", ancla: "avisos" },
  valores_ambiguos_sin_revisar: { titulo: "Faltan por revisar datos dudosos", paso: "personas", ancla: "avisos" },
  sin_participantes: { titulo: "No queda ningún participante por importar", paso: "personas", ancla: "participantes" },
  fila_invalida: { titulo: "Hay participantes con datos que corregir", paso: "personas", ancla: "participantes" },
  documento_de_contacto_qa: { titulo: "Un documento coincide con un contacto de control (QA)", paso: "personas", ancla: "participantes" },
  intensidad_individual_invalida: { titulo: "Una intensidad individual no se puede imprimir", paso: "personas", ancla: "participantes" },
  modalidad_faltante: { titulo: "Hay personas sin modalidad", paso: "personas", ancla: "participantes" },
  modalidad_individual_invalida: { titulo: "Una modalidad no se puede imprimir", paso: "personas", ancla: "participantes" },
  modalidad_por_revisar: { titulo: "Hay personas con la modalidad sin aclarar", paso: "personas", ancla: "participantes" },
  elegibilidad_sin_decidir: { titulo: "Falta decidir si algunas personas se certifican", paso: "personas", ancla: "participantes" },
  tipo_documento_sin_confirmar: { titulo: "Falta confirmar tipos de documento", paso: "personas", ancla: "participantes" },
  catalogo_qa_incompleto: { titulo: "Los contactos de control (QA) no están completos", paso: "confirmar" },
};

export const AVISOS_FILA = {
  tipo_documento_por_confirmar: "Tipo de documento por confirmar",
  tipo_documento_propuesto_sin_confirmar: "El Excel sugiere un tipo de documento, pero no es seguro",
  valores_ambiguos: "Algún dato no se detectó con certeza",
  valores_posiblemente_desactualizados: "Viene de una hoja externa y puede estar desactualizado",
  asistencia_cupo: "El Excel marca «Cupos» (solo informativo)",
  asistencia_conflicto: "La asistencia del Excel se contradice (solo informativo)",
  asistencia_desconocida: "Estado de asistencia desconocido (solo informativo)",
  documento_llego_como_numero: "El documento llegó como número (si empezaba por cero, ya se había perdido)",
  posible_contacto_qa: "Se parece a un contacto de control (QA)",
  modalidad_por_revisar: "La modalidad del Excel no está clara: escríbela",
  elegibilidad_por_decidir: "Falta decidir si se certifica",
  excel_declara_no_elegible: "El Excel la declara «No elegible»: decide si se certifica",
  excel_declara_pendiente: "El Excel la declara «Pendiente»: decide si se certifica",
  excel_declara_estado_dudoso: "El Excel trae un estado de elegibilidad que no se entiende: decide",
};

// Avisos que se resumen UNA sola vez (con la cantidad) en vez de repetirse en cada participante. El detalle de cada persona queda dentro de «Detalles» de su fila.
// No están aquí el tipo de documento por confirmar (tiene su propio filtro) ni los datos posiblemente desactualizados (tarjeta de IMPORTRANGE).
export const RESUMEN_AVISOS = {
  documento_llego_como_numero: (n) => `${n} ${n === 1 ? "documento llegó" : "documentos llegaron"} como número. Si alguno empezaba por cero, el Excel ya lo había perdido: compáralos con la fuente.`,
  valores_ambiguos: (n) => `${n} ${n === 1 ? "persona tiene" : "personas tienen"} datos que no se detectaron con certeza.`,
  tipo_documento_propuesto_sin_confirmar: (n) => `En ${n} ${n === 1 ? "fila" : "filas"} el Excel sugiere un tipo de documento, pero no es seguro: tú decides.`,
  asistencia_cupo: (n) => `${n} ${n === 1 ? "persona figura" : "personas figuran"} con «Cupos» en el Excel: es solo informativo, quedan elegibles igual.`,
  asistencia_conflicto: (n) => `${n} ${n === 1 ? "persona tiene" : "personas tienen"} datos de asistencia que se contradicen.`,
  asistencia_desconocida: (n) => `${n} ${n === 1 ? "persona tiene" : "personas tienen"} un estado de asistencia desconocido.`,
  posible_contacto_qa: (n) => `${n} ${n === 1 ? "persona se parece" : "personas se parecen"} a un contacto de control (QA): revísalo.`,
  modalidad_por_revisar: (n) => `${n} ${n === 1 ? "persona no tiene" : "personas no tienen"} una modalidad clara en el Excel: escríbela en cada una (no se usa la general).`,
  elegibilidad_por_decidir: (n) => `El Excel declara que ${n} ${n === 1 ? "persona no es" : "personas no son"} «Elegible»: decide a propósito si se certifican antes de crear la base.`,
};

// F5 · Acciones sobre la selección. Por qué una persona NO se toca en una acción masiva (lo decide el servidor; la pantalla solo lo explica).
export const MOTIVOS_OMISION = {
  excluida: "Está marcada para no importarse",
  posible_qa: "Se parece a un contacto de control (QA): no se toca",
};

export const ACCIONES_MASIVAS = {
  elegibilidad: { titulo: "Elegibilidad", campo: "Elegibilidad" },
  tipo_documento: { titulo: "Tipo de documento", campo: "Tipo de documento" },
};

export const etiquetaEstado = (campo, valor) => (valor == null ? "Por confirmar" : campo === "elegibilidad" ? (ELEGIBILIDADES.find((e) => e.valor === valor)?.etiqueta ?? valor) : valor);

/** Identificador único de una operación masiva (sirve para que un reintento o un doble clic no la aplique dos veces). */
function nuevoId() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  const h = () => Math.floor(Math.random() * 0x10000).toString(16).padStart(4, "0");
  return `${h()}${h()}-${h()}-4${h().slice(1)}-a${h().slice(1)}-${h()}${h()}${h()}`;
}

// Bloqueos de varias personas → la entrada de la fila que hay que completar.
const CAMPO_DE_BLOQUEO = { elegibilidad_sin_decidir: "elegibilidad", tipo_documento_sin_confirmar: "tipo_documento" };

// Campo del servidor (columna del importador) → atributo `data-campo-fila` de la entrada de la tabla.
const CAMPO_FILA = { nombre_completo: "nombre_completo", documento: "documento", tipo_documento: "tipo_documento", correo: "correo", elegibilidad: "elegibilidad", intensidad_horaria: "intensidad_horaria", modalidad: "modalidad" };

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

  // ── F5 · Selección y acciones sobre la selección ────────────────────────────
  const seleccion = ref(new Set()); // filas del Excel marcadas (pueden estar en otras páginas o filtros)
  const masiva = reactive({ abierta: false, campo: null, valor: "", verificado: false, sobrescribir: false, vista: null, cargando: false, aplicando: false, error: null, operacionId: null, resultado: null });

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

  // ── Recorrido guiado ────────────────────────────────────────────────────────
  const hecho = ref(""); // lo último que la persona acaba de hacer (lo cuenta la tarjeta guía)
  const datoActivo = ref(null); // dato (o «__personas») que la etapa de datos debe mostrar al entrar
  const listaAbierta = ref(false); // la lista completa de personas empieza cerrada
  const maxEtapa = ref(0); // la etapa más lejana a la que ya llegó (se puede volver a cualquiera anterior)
  // UNA sola acción principal por pantalla: la etapa que está a la vista la registra aquí y la barra fija de abajo la muestra. Los bloques interiores solo guían o informan.
  const principal = ref(null); // { texto, accion, deshabilitado?, razon? }
  // Si llegaste a corregir algo desde otra etapa (por ejemplo desde el PDF), aquí se recuerda de dónde venías para regresar directo cuando termines.
  const origen = ref(null); // { paso, titulo }

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
      return { ...def, dudoso, valor, propuesta: s.propuesta ?? null, original: s.original ?? null, referencia: s.referencia ?? null, desactualizable: s.desactualizable === true, motivos: s.motivos ?? [], origen, confirmado, estado, editado };
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

  // Se puede seleccionar a quien se importa y no es un contacto de control (QA): el servidor lo vuelve a comprobar.
  const esSeleccionable = (p) => !p.excluir && p.posible_qa == null;
  // La selección efectiva: lo marcado que sigue siendo seleccionable (si luego se excluye a alguien, deja de contar).
  const seleccionadas = computed(() => participantes.value.filter((p) => seleccion.value.has(p.fila) && esSeleccionable(p)));
  const historial = computed(() => borrador.value?.historial ?? []);

  const validacion = computed(() => borrador.value?.validacion ?? null);
  const plantillaId = computed(() => (edicion.plantilla_id !== undefined ? edicion.plantilla_id : (borrador.value?.plantilla_id ?? null)));
  const confirmados = computed(() => ({ ...(borrador.value?.confirmados ?? {}), ...edicion.confirmados }));

  // ── Qué falta en cada etapa (para guiar; el servidor vuelve a validar todo al crear la base) ──
  const eventoListo = computed(() => {
    const ev = evento.value;
    const d = ev?.decision;
    if (!d?.decision) return false;
    if (d.decision === "crear") {
      const nombre = String(d.nombre ?? ev.propuesta?.nombre_excel ?? "").trim();
      const parecido = (ev.propuesta?.candidatos ?? []).length > 0 || ev.propuesta?.homonimo_historico === true;
      if (!nombre || (parecido && d.confirmar_distinto !== true)) return false;
    }

    return true;
  });
  // Datos del certificado que necesitan al administrador: los que hay que REVISAR (dudosos o de otra hoja) y los obligatorios que faltan.
  const datosPendientes = computed(() => datos.value.filter((d) => d.estado === "revisar" || (d.estado === "faltante" && d.requerido)));
  const personasDesactualizadas = computed(() => borrador.value?.avisos_generales?.desactualizables_participantes ?? 0);
  const importrangePendiente = computed(() => personasDesactualizadas.value > 0 && confirmados.value.valores_desactualizados !== true);
  const bloqueosPersonas = computed(() => {
    const aceptados = confirmados.value.valores_ambiguos === true;

    return (validacion.value?.bloqueos ?? []).filter((b) => BLOQUEOS_PERSONAS.includes(b.codigo) && !(aceptados && b.codigo === "valores_ambiguos_sin_revisar"));
  });
  const bloqueosVista = computed(() => (validacion.value?.bloqueos ?? []).filter((b) => ["plantilla_faltante", "plantilla_invalida", "datos_del_diseno_incompletos"].includes(b.codigo)));
  const etapaLista = computed(() => ({
    subir: !!borrador.value,
    evento: eventoListo.value,
    datos: datosPendientes.value.length === 0 && !importrangePendiente.value,
    personas: bloqueosPersonas.value.length === 0,
    vista: plantillaId.value != null && bloqueosVista.value.length === 0,
    confirmar: !!lote.value,
  }));
  // Cuántas cosas faltan para poder CREAR la base (tipo de documento y elegibilidad NO cuentan: solo impiden emitir).
  const faltanParaCrear = computed(
    () => (eventoListo.value ? 0 : 1) + datosPendientes.value.length + (importrangePendiente.value ? 1 : 0) + bloqueosPersonas.value.length + (plantillaId.value != null ? 0 : 1)
  );

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

  // ── Tarjeta guía y progreso ─────────────────────────────────────────────────
  const indicePaso = computed(() => PASOS.findIndex((x) => x.id === paso.value));
  const personasTotal = computed(() => borrador.value?.participantes?.length ?? 0);
  const unaCosa = (n, uno, varios) => (n === 1 ? uno : varios.replace("{n}", n));

  const guia = computed(() => {
    const p = paso.value;
    const hechoPorDefecto = {
      subir: "",
      evento: `Analizamos tu Excel y encontramos ${personasTotal.value} ${personasTotal.value === 1 ? "persona" : "personas"}.`,
      datos: "Confirmaste a qué evento pertenece.",
      personas: "Confirmaste los datos del certificado.",
      vista: "Revisaste a las personas.",
      confirmar: "Elegiste la plantilla y viste el PDF.",
    };
    const ahora = {
      subir: "Estás subiendo el Excel.",
      evento: "Estás confirmando a qué evento pertenece.",
      datos: "Estás confirmando los datos que se imprimen en el certificado.",
      personas: "Estás revisando a las personas del Excel.",
      vista: "Estás viendo cómo queda el certificado.",
      confirmar: "Estás a un paso de crear la base.",
    };
    let sigue = null;
    if (p === "evento") sigue = eventoListo.value ? { texto: "Confirma tu decisión para seguir.", boton: "Confirmar y seguir" } : { texto: "Elige si es el evento que ya existe o uno distinto.", boton: "Elegir el evento" };
    else if (p === "datos") {
      const d = datosPendientes.value[0];
      if (d) sigue = { texto: `${d.estado === "faltante" ? "Escribe" : "Confirma"} ${nombreEnFrase(d)}.`, boton: `Revisar ${nombreEnFrase(d)}` };
      else if (importrangePendiente.value) sigue = { texto: "Confirma los datos de las personas que vienen de otra hoja.", boton: "Revisar este dato" };
      else sigue = { texto: "Pasa a revisar a las personas.", boton: "Continuar" };
    } else if (p === "personas") {
      const n = bloqueosPersonas.value.length;
      sigue = n
        ? { texto: unaCosa(n, "Hay 1 dato por corregir antes de crear la base.", "Hay {n} datos por corregir antes de crear la base."), boton: "Revisar este dato" }
        : { texto: "Nada de aquí impide crear la base. Puedes revisar la elegibilidad y los tipos ahora, o seguir.", boton: "Continuar" };
    } else if (p === "vista") {
      sigue = plantillaId.value == null ? { texto: "Elige la plantilla del certificado.", boton: "Elegir la plantilla" } : bloqueosVista.value.length ? { texto: "Completa el dato que falta para dibujar el PDF.", boton: "Revisar este dato" } : { texto: "Mira el PDF y, si está bien, continúa.", boton: "Continuar" };
    } else if (p === "confirmar") {
      sigue = faltanParaCrear.value ? { texto: unaCosa(faltanParaCrear.value, "Falta 1 cosa para poder crear la base.", "Faltan {n} cosas para poder crear la base."), boton: "Revisar lo que falta" } : { texto: "Marca la casilla y crea la base.", boton: "Ir al botón" };
    }

    if (origen.value && p !== origen.value.paso && p !== "subir") sigue = { texto: `Corrige lo que falta y regresa directo a «${origen.value.titulo}».`, boton: "Volver" };

    return { hecho: hecho.value || hechoPorDefecto[p] || "", ahora: ahora[p] ?? "", sigue };
  });

  const progreso = computed(() => ({
    actual: indicePaso.value + 1,
    total: PASOS.length,
    porcentaje: Math.round((indicePaso.value / (PASOS.length - 1)) * 100),
    faltan: faltanParaCrear.value,
    titulo: PASOS[indicePaso.value]?.titulo ?? "",
  }));

  /** La etapa que conviene mostrar al abrir un borrador: la primera con algo por hacer (lo opcional no cuenta). */
  function etapaSugerida() {
    if (!eventoListo.value) return "evento";
    if (!etapaLista.value.datos) return "datos";
    if (!etapaLista.value.personas) return "personas";

    return etapaLista.value.vista ? "confirmar" : "vista";
  }

  /** Lleva la atención a lo que el asistente recomienda hacer ahora (lo resalta y le da el foco); si la etapa ya está lista, avanza. */
  async function irARecomendado() {
    const el = document.querySelector("[data-recomendado]");
    if (el) return destacar(el);
    const i = indicePaso.value;
    if (etapaLista.value[paso.value] && i < PASOS.length - 1) return irA(PASOS[i + 1].id);

    return false;
  }

  // ── Revisión guiada de varias personas ──────────────────────────────────────
  /** Prepara la selección (NO decide nada): los «Completo» sin confirmar como elegibles, o los que no tienen tipo de documento. Después abre el panel de revisión. */
  function iniciarRevisionMasiva(tipo) {
    const filas = participantes.value
      .filter((p) => esSeleccionable(p) && (tipo === "elegibilidad" ? p.senal === "completo" && p.elegibilidad !== "elegible" : !p.tipo_documento))
      .map((p) => p.fila);
    if (filas.length === 0) return 0;
    seleccion.value = new Set(filas);
    abrirMasiva(tipo);

    return filas.length;
  }

  /** Resumen de la selección actual (para mostrarlo ANTES de proponer cambios). */
  const resumenSeleccion = computed(() => {
    const sel = seleccionadas.value;

    return {
      total: sel.length,
      completos: sel.filter((p) => p.senal === "completo").length,
      cupos: sel.filter((p) => p.senal === "cupo").length,
      // «Sin asistencia clara» = el Excel la trae pero se contradice o no se entiende. Un Excel SIN columna de asistencia no es «poco claro».
      otros: sel.filter((p) => ["conflicto", "desconocida"].includes(p.senal)).length,
      elegibles: sel.filter((p) => p.elegibilidad === "elegible").length,
      pendientes: sel.filter((p) => p.elegibilidad === "pendiente").length,
      noElegibles: sel.filter((p) => p.elegibilidad === "no_elegible").length,
      sinTipo: sel.filter((p) => !p.tipo_documento).length,
      muestra: sel.slice(0, 6).map((p) => p.nombre_completo ?? `Fila ${p.fila}`),
    };
  });

  function verPersonas(filtro) {
    listaAbierta.value = true;
    foco.value = { tipo: "filtro", id: filtro };
  }

  /** Regresa directo a la etapa desde la que viniste a corregir algo (lo guardado se conserva y la etapa se vuelve a calcular con los datos nuevos). */
  async function volverAlOrigen() {
    const o = origen.value;
    if (!o) return false;
    if (!(await irA(o.paso))) return false;
    origen.value = null;
    hecho.value = `Corregiste el dato y volviste a «${o.titulo}».`;

    return true;
  }

  /** Muestra, en la etapa de datos, la tarea de ese dato. */
  async function irADato(campo) {
    return resolver({ codigo: "dato_comun_faltante", campo });
  }

  // ── Selección ───────────────────────────────────────────────────────────────
  function alternarSeleccion(fila) {
    const n = new Set(seleccion.value);
    n.has(fila) ? n.delete(fila) : n.add(fila);
    seleccion.value = n;
  }

  /** Marca estas filas (solo las seleccionables). Devuelve cuántas quedaron marcadas. */
  function seleccionar(filas) {
    const ok = new Set(participantes.value.filter(esSeleccionable).map((p) => p.fila));
    const n = new Set(seleccion.value);
    filas.forEach((f) => ok.has(f) && n.add(f));
    seleccion.value = n;

    return filas.filter((f) => ok.has(f)).length;
  }

  function quitarSeleccion(filas) {
    const n = new Set(seleccion.value);
    filas.forEach((f) => n.delete(f));
    seleccion.value = n;
  }

  function limpiarSeleccion() {
    seleccion.value = new Set();
  }

  // ── Acciones sobre la selección (F5) ────────────────────────────────────────
  const reiniciarMasiva = () => Object.assign(masiva, { abierta: false, campo: null, valor: "", verificado: false, sobrescribir: false, vista: null, cargando: false, aplicando: false, error: null, operacionId: null });

  function abrirMasiva(campo) {
    reiniciarMasiva();
    Object.assign(masiva, { abierta: true, campo, resultado: null });
  }

  function cancelarMasiva() {
    reiniciarMasiva();
  }

  /** Cambiar el valor elegido invalida lo que se había revisado: hay que volver a revisar. */
  function elegirValorMasivo(valor) {
    masiva.valor = valor;
    masiva.vista = null;
    masiva.verificado = false;
    masiva.sobrescribir = false;
    masiva.error = null;
  }

  const cuerpoMasivo = () => ({ campo: masiva.campo, valor: masiva.valor, filas: seleccionadas.value.map((p) => p.fila) });

  /** Pide al servidor el detalle de lo que pasaría (no guarda nada). Antes guarda lo que estabas editando para que lo revisado sea lo real. */
  async function revisarMasiva() {
    if (!borrador.value || masiva.cargando || !masiva.campo || !masiva.valor || seleccionadas.value.length === 0) return false;
    masiva.cargando = true;
    masiva.error = null;
    masiva.vista = null;
    try {
      if (!(await guardar())) {
        masiva.error = error.value ?? { mensaje: "No se pudieron guardar tus cambios pendientes." };
        return false;
      }
      const { data } = await axios.post(route("credential-flow.importaciones.masivas.vista-previa", borrador.value.id), { version: borrador.value.version, ...cuerpoMasivo() });
      masiva.vista = data.vista_previa;
      masiva.operacionId = nuevoId();
      masiva.verificado = false;
      masiva.sobrescribir = false;

      return true;
    } catch (e) {
      const err = errorDeApi(e);
      if (err.codigo === "BORRADOR_DESACTUALIZADO") desactualizado.value = true;
      masiva.error = err;

      return false;
    } finally {
      masiva.cargando = false;
    }
  }

  const puedeAplicarMasiva = computed(() => {
    const v = masiva.vista;
    if (!v || masiva.aplicando || masiva.cargando || v.se_modifican === 0) return false;
    if (v.requiere_verificacion && !masiva.verificado) return false;
    if (v.requiere_confirmar_sobrescritura && !masiva.sobrescribir) return false;

    return true;
  });

  // Qué falta para poder aplicar (se muestra junto al botón principal cuando está apagado).
  const razonAplicarMasiva = computed(() => {
    const x = masiva.vista;
    if (!x || x.se_modifican === 0) return "";
    if (x.requiere_verificacion && !masiva.verificado) return "Marca «Confirmo que ya verifiqué la asistencia» para poder aplicar.";
    if (x.requiere_confirmar_sobrescritura && !masiva.sobrescribir) return "Marca «Entiendo que esto reemplaza lo que ya había decidido» para poder aplicar.";

    return "";
  });

  /** Aplica EXACTAMENTE lo que se revisó (con su huella) al borrador. No crea la base ni emite. Un doble clic o un reintento no lo aplica dos veces. */
  async function aplicarMasiva() {
    if (!puedeAplicarMasiva.value || !borrador.value) return false;
    masiva.aplicando = true;
    masiva.error = null;
    try {
      const { data } = await axios.post(route("credential-flow.importaciones.masivas.aplicar", borrador.value.id), {
        version: borrador.value.version, operacion_id: masiva.operacionId, huella: masiva.vista.huella, ...cuerpoMasivo(), verificado: masiva.verificado, confirmar_sobrescritura: masiva.sobrescribir,
      });
      const antes = { campo: masiva.campo, valor: masiva.valor };
      aplicar(data.borrador);
      reiniciarMasiva();
      limpiarSeleccion();
      masiva.resultado = data.resultado ? { ...antes, ...data.resultado, repetida: false } : { ...antes, repetida: true };
      aviso.value = data.repetida ? "Ese cambio ya se había aplicado: no se repitió." : "Cambios aplicados al borrador. Todavía no se creó ninguna base.";

      return true;
    } catch (e) {
      const err = errorDeApi(e);
      if (err.codigo === "BORRADOR_DESACTUALIZADO") desactualizado.value = true;
      if (err.codigo === "ACCION_MASIVA_VISTA_PREVIA_OBSOLETA") masiva.vista = null;
      masiva.error = err;

      return false;
    } finally {
      masiva.aplicando = false;
    }
  }

  async function deshacerMasiva(id) {
    if (!borrador.value || masiva.aplicando) return false;
    masiva.aplicando = true;
    masiva.error = null;
    try {
      const { data } = await axios.post(route("credential-flow.importaciones.masivas.deshacer", [borrador.value.id, id]), { version: borrador.value.version });
      aplicar(data.borrador);
      masiva.resultado = null;
      aviso.value = "Se deshizo el cambio masivo. El borrador volvió a como estaba.";

      return true;
    } catch (e) {
      const err = errorDeApi(e);
      if (err.codigo === "BORRADOR_DESACTUALIZADO") desactualizado.value = true;
      masiva.error = err;

      return false;
    } finally {
      masiva.aplicando = false;
    }
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
      hecho.value = "";
      if (data.recuperado) {
        paso.value = etapaSugerida();
        maxEtapa.value = PASOS.findIndex((x) => x.id === paso.value);
      } else {
        paso.value = "evento";
        maxEtapa.value = 1;
      }
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
      hecho.value = "";
      paso.value = data.borrador.estado === "confirmado" ? "confirmar" : etapaSugerida();
      maxEtapa.value = Math.max(maxEtapa.value, PASOS.findIndex((x) => x.id === paso.value));
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
      limpiarSeleccion();
      reiniciarMasiva();
      masiva.resultado = null;
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
    limpiarSeleccion();
    reiniciarMasiva();
    masiva.resultado = null;
    error.value = null;
    hecho.value = "";
    datoActivo.value = null;
    listaAbierta.value = false;
    maxEtapa.value = 0;
    origen.value = null;
    principal.value = null;
    paso.value = "subir";
  }

  async function irA(id) {
    if (id === paso.value) return true;
    if (id !== "subir" && !borrador.value) return false;
    if (sinGuardar.value && !lote.value && !(await guardar())) return false;
    const destino = PASOS.findIndex((x) => x.id === id);
    if (destino > indicePaso.value) hecho.value = ""; // al avanzar, la tarjeta guía vuelve a contar lo último que se hizo
    maxEtapa.value = Math.max(maxEtapa.value, destino);
    if (origen.value?.paso === id) origen.value = null;
    paso.value = id;
    return true;
  }

  /**
   * «Revisar este dato»: cambia a la etapa que corresponde, muestra la sección y lleva el foco al campo exacto que falta (la fila, el dato, la decisión del evento,
   * la confirmación o la plantilla). Si el campo está en una fila de la lista cerrada o paginada, la tabla la abre y la deja visible.
   */
  async function resolver(b) {
    const def = BLOQUEOS[b.codigo] ?? { paso: "personas" };
    const campo = b.campo ?? (Array.isArray(b.campos) ? b.campos[0] : null);
    if (def.paso === "datos") datoActivo.value = b.codigo === "valores_desactualizados_sin_revisar" ? "__personas" : campo;
    const desde = paso.value;
    const retrocede = PASOS.findIndex((x) => x.id === def.paso) < PASOS.findIndex((x) => x.id === desde);
    if (!(await irA(def.paso))) return false;
    // Si venías de una etapa más adelante (por ejemplo, del PDF), se recuerda para regresar directo cuando termines.
    if (retrocede) origen.value = { paso: desde, titulo: PASOS.find((x) => x.id === desde)?.titulo ?? "" };
    await nextTick();
    let el = null;
    if (def.paso === "vista") {
      el = await esperar("#plantilla");
    } else if (def.paso === "confirmar") {
      el = await esperar("[data-resumen-confirmar]");
    } else if (def.paso === "evento") {
      el = await esperar("[data-seccion=evento]");
    } else if (def.paso === "datos") {
      el = await esperar(datoActivo.value === "__personas" ? "[data-tarea-importrange]" : campo ? `[data-tarea-dato="${campo}"]` : "[data-seccion=datos]");
    } else if (b.fila != null || (Array.isArray(b.filas) && b.filas.length > 0)) {
      // Un bloqueo de varias personas (por ejemplo, sin modalidad) lleva a la primera de ellas.
      listaAbierta.value = true;
      foco.value = { tipo: "fila", fila: Number(b.fila ?? b.filas[0]), campo: CAMPO_FILA[b.campo] ?? CAMPO_DE_BLOQUEO[b.codigo] ?? (String(b.codigo).startsWith("modalidad_") ? "modalidad" : null) };

      return true; // la tabla lo atiende
    } else if (b.codigo === "valores_ambiguos_sin_revisar") {
      el = await esperar("[data-confirmar-ambiguos]");
    } else {
      el = await esperar("[data-seccion=personas]");
    }

    return destacar(el);
  }

  const store = {
    props, paso, borrador, cargando, guardando, confirmando, error, aviso, desactualizado, lote, erroresCampo, edicion, foco, avisosResumen, resolver,
    sinGuardar, evento, datos, participantes, resumenParticipantes, validacion, plantillaId, confirmados,
    hecho, datoActivo, listaAbierta, maxEtapa, eventoListo, datosPendientes, importrangePendiente, bloqueosPersonas, bloqueosVista, etapaLista, faltanParaCrear, indicePaso, guia, progreso,
    etapaSugerida, irARecomendado, iniciarRevisionMasiva, verPersonas, irADato, personasDesactualizadas, principal, origen, volverAlOrigen,
    seleccion, seleccionadas, esSeleccionable, historial, masiva, puedeAplicarMasiva, razonAplicarMasiva, resumenSeleccion,
    alternarSeleccion, seleccionar, quitarSeleccion, limpiarSeleccion, abrirMasiva, cancelarMasiva, elegirValorMasivo, revisarMasiva, aplicarMasiva, deshacerMasiva,
    editarFila, restaurarFila, editarDato, confirmarDato, decidirEvento, elegirPlantilla, marcarConfirmacion,
    analizar, abrir, guardar, recargar, confirmar, descartar, nuevoArchivo, irA,
  };
  provide(claveEstado, store);

  return store;
}

/**
 * La etapa que está a la vista dice cuál es SU acción principal (una sola por pantalla): la barra fija de abajo la muestra. `calcular()` devuelve
 * `{ texto, accion, deshabilitado?, razon? }` y se recalcula sola; un botón apagado siempre trae su `razon` (qué falta para activarlo).
 */
export function registrarPrincipal(a, calcular) {
  watchEffect(() => {
    a.principal.value = calcular();
  });
  onBeforeUnmount(() => {
    a.principal.value = null;
  });
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
