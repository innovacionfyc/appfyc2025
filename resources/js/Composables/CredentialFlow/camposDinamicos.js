// Campos dinámicos del editor: texto visible, medición y autoajuste de una sola línea.
//
// El catálogo (claves, etiquetas, previews) viene del backend (CamposDinamicos.php) dentro de
// `schema.campos`; aquí no se duplica. Las constantes del autoajuste (`escalaMinima`, `pasoAjuste`,
// `toleranciaAjuste`) también vienen de DisenoSchema.
//
// Paridad con el PDF (TCPDF): todo en puntos, sin kerning ni ligaduras, mismo algoritmo y mismo
// redondeo. El ancho sale de la tabla de métricas (metricas.json, hmtx de los TTF), NO del
// navegador: es la misma cuenta que hará el generador PHP.
import { calcularAjuste } from "./autoajuste";
import { lineasDeContenido, planearTexto } from "./planTexto";
import { estadoFuente, esReproducible, metricas } from "@/Composables/CredentialFlow/fuentesCredential";

// Mismos ajustes en todos los textos del lienzo: TCPDF no aplica kerning ni ligaduras.
export const ESTILO_SIN_KERNING = {
  fontKerning: "none",
  fontVariantLigatures: "none",
  fontFeatureSettings: '"kern" 0, "liga" 0, "clig" 0',
};

export { calcularAjuste };

export function useCamposDinamicos(schema) {
  const catalogo = Object.fromEntries((schema.campos ?? []).map((c) => [c.key, c]));
  const config = {
    escalaMinima: schema.escalaMinima,
    pasoAjuste: schema.pasoAjuste,
    tolerancia: schema.toleranciaAjuste,
    interlineado: schema.interlineadoTextoFijo,
  };

  const esDinamico = (el) => el.field !== null && el.field !== undefined;
  const esDesconocido = (el) => esDinamico(el) && !catalogo[el.field];

  // Texto que se dibuja: el fijo, o prefijo + preview del catálogo + sufijo (un solo texto). Nunca se guarda el preview.
  const textoVisible = (el) => {
    if (!esDinamico(el)) return el.text;
    return `${el.prefix ?? ""}${catalogo[el.field]?.preview ?? "Campo desconocido"}${el.suffix ?? ""}`;
  };

  // Plan de dibujo del elemento (líneas, tamaño efectivo, x y línea base): planTexto.js, el mismo
  // contrato que implementa el generador de PDF. No depende de que la fuente haya cargado.
  const planes = new Map();
  const planDe = (el) => {
    const contenido = textoVisible(el);
    const clave = [el.fontFamily, el.fontWeight, el.fontSize, el.x, el.y, el.width, el.height, el.align, esDinamico(el), el.multiline === true, contenido].join("|");
    let plan = planes.get(clave);
    if (!plan) {
      if (planes.size > 500) planes.clear();
      plan = planearTexto(metricas, config, el, contenido);
      planes.set(clave, plan);
    }
    return plan;
  };
  const lineasDe = (el) => lineasDeContenido(textoVisible(el), esDinamico(el));
  const textoLinea = (el) => lineasDe(el)[0];

  // Qué se puede dibujar de un elemento (sin sustituir fuentes en silencio):
  //  - "lista":        fuente reproducible cargada y todos los caracteres existen → se dibuja.
  //  - "cargando":     la fuente aún no terminó de cargar → no se dibuja.
  //  - "error":        la fuente reproducible no cargó → error visible, bloquea el guardado.
  //  - "noSoportado":  algún carácter no existe en la fuente → error visible, bloquea el guardado.
  //  - "heredada":     Figtree/Arial/sans-serif → se dibuja con la fuente del navegador, con aviso.
  const renderDe = (el) => {
    if (el.type === "qr") return { estado: "qr", faltantes: [] }; // el QR no tiene texto ni fuente
    if (esDesconocido(el)) return { estado: "desconocido", faltantes: [] };
    if (!esReproducible(el.fontFamily, el.fontWeight)) {
      return { estado: estadoFuente(el.fontFamily, el.fontWeight) === "heredada" ? "heredada" : "desconocido", faltantes: [] };
    }
    // Cobertura sobre TODO el texto visible (sale de la tabla de métricas). Las heredadas no tienen
    // métricas autoritativas y no se validan.
    const { faltantes } = planDe(el);
    if (faltantes.length) return { estado: "noSoportado", faltantes };

    return { estado: estadoFuente(el.fontFamily, el.fontWeight), faltantes: [] };
  };

  // Estado del autoajuste de un campo dinámico conocido; null si no es dinámico o no se puede
  // medir (fuente sin cargar, heredada o con caracteres no soportados).
  const ajusteDe = (el) => {
    if (!esDinamico(el) || renderDe(el).estado !== "lista") return null;

    const plan = planDe(el);
    return { size: plan.size, reducido: plan.reducido, noCabe: plan.noCabe, ancho: plan.lineas[0].ancho };
  };

  return { catalogo, esDinamico, esDesconocido, textoVisible, textoLinea, lineasDe, renderDe, ajusteDe, planDe };
}
