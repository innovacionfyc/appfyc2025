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
import { estadoFuente, esReproducible, faltantesDeLineas, medirTexto } from "@/Composables/CredentialFlow/fuentesCredential";

// Mismos ajustes en todos los textos del lienzo: TCPDF no aplica kerning ni ligaduras.
export const ESTILO_SIN_KERNING = {
  fontKerning: "none",
  fontVariantLigatures: "none",
  fontFeatureSettings: '"kern" 0, "liga" 0, "clig" 0',
};

const ajustes = new Map(); // clave completa del cálculo → resultado (función pura de la tabla)

export { calcularAjuste };

export function useCamposDinamicos(schema) {
  const catalogo = Object.fromEntries((schema.campos ?? []).map((c) => [c.key, c]));
  const config = {
    escalaMinima: schema.escalaMinima,
    pasoAjuste: schema.pasoAjuste,
    tolerancia: schema.toleranciaAjuste,
  };

  const esDinamico = (el) => el.field !== null && el.field !== undefined;
  const esDesconocido = (el) => esDinamico(el) && !catalogo[el.field];

  // Texto que se dibuja: el fijo, o el preview del catálogo. Nunca se guarda el preview.
  const textoVisible = (el) => {
    if (!esDinamico(el)) return el.text;
    return catalogo[el.field]?.preview ?? "Campo desconocido";
  };

  // Líneas que se dibujan:
  //  - campo dinámico (V1): una sola línea, como TCPDF Cell; cualquier salto se muestra como espacio.
  //  - texto fijo: conserva sus saltos manuales (una línea por cada salto). La paridad del texto
  //    fijo multilínea con el PDF se resolverá en la fase de generación.
  const lineasDe = (el) => {
    if (esDinamico(el)) return [String(textoVisible(el)).replace(/\r?\n/g, " ")];
    return String(el.text ?? "").split(/\r?\n/);
  };
  const textoLinea = (el) => lineasDe(el)[0];

  // Cobertura de la fuente sobre TODO el texto visible (no depende de que la fuente haya cargado:
  // sale de la tabla de métricas). Solo aplica a fuentes reproducibles; las heredadas no tienen
  // métricas autoritativas y no se validan.
  const faltantesDe = (el) => faltantesDeLineas(el.fontFamily, el.fontWeight, lineasDe(el));

  // Qué se puede dibujar de un elemento (sin sustituir fuentes en silencio):
  //  - "lista":        fuente reproducible cargada y todos los caracteres existen → se dibuja.
  //  - "cargando":     la fuente aún no terminó de cargar → no se dibuja.
  //  - "error":        la fuente reproducible no cargó → error visible, bloquea el guardado.
  //  - "noSoportado":  algún carácter no existe en la fuente → error visible, bloquea el guardado.
  //  - "heredada":     Figtree/Arial/sans-serif → se dibuja con la fuente del navegador, con aviso.
  const renderDe = (el) => {
    if (esDesconocido(el)) return { estado: "desconocido", faltantes: [] };
    if (!esReproducible(el.fontFamily, el.fontWeight)) {
      return { estado: estadoFuente(el.fontFamily, el.fontWeight) === "heredada" ? "heredada" : "desconocido", faltantes: [] };
    }
    const faltantes = faltantesDe(el);
    if (faltantes.length) return { estado: "noSoportado", faltantes };

    return { estado: estadoFuente(el.fontFamily, el.fontWeight), faltantes: [] };
  };

  // Estado del autoajuste de un campo dinámico conocido; null si no es dinámico o no se puede
  // medir (fuente sin cargar, heredada o con caracteres no soportados).
  const ajusteDe = (el) => {
    if (!esDinamico(el) || renderDe(el).estado !== "lista") return null;

    const texto = textoLinea(el);
    const clave = `${el.fontFamily}|${el.fontWeight}|${texto}|${el.fontSize}|${el.width}`;
    const previo = ajustes.get(clave);
    if (previo) return previo;

    const { ancho } = medirTexto(el.fontFamily, el.fontWeight, el.fontSize, texto);
    const resultado = calcularAjuste({ ancho, anchoCaja: el.width, fontSize: el.fontSize, ...config });
    ajustes.set(clave, resultado);
    return resultado;
  };

  return { catalogo, esDinamico, esDesconocido, textoVisible, textoLinea, lineasDe, renderDe, ajusteDe };
}
