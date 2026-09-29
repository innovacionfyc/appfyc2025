// Campos dinámicos del editor: texto visible, medición y autoajuste de una sola línea.
//
// El catálogo (claves, etiquetas, previews) viene del backend (CamposDinamicos.php) dentro de
// `schema.campos`; aquí no se duplica. Las constantes del autoajuste (`escalaMinima`, `pasoAjuste`,
// `toleranciaAjuste`) también vienen de DisenoSchema.
//
// Paridad futura con el PDF (TCPDF): todo en puntos, sin kerning ni ligaduras, mismo algoritmo y
// mismo redondeo. El ancho se mide con la fuente REAL cargada en el navegador; la coincidencia
// exacta con el PDF llegará cuando editor y generador usen el mismo archivo de fuente.
import { ref } from "vue";

export const FUENTES_CSS = {
  Figtree: "'Figtree', sans-serif",
  Arial: "Arial, Helvetica, sans-serif",
  "sans-serif": "sans-serif",
};

// Mismos ajustes en todos los elementos del lienzo: TCPDF no aplica kerning ni ligaduras.
export const ESTILO_SIN_KERNING = {
  fontKerning: "none",
  fontVariantLigatures: "none",
  fontFeatureSettings: '"kern" 0, "liga" 0, "clig" 0',
};

const REFERENCIA_PX = 100; // se mide a un tamaño grande y se escala: minimiza el redondeo de glifos

// Cambia cuando termina de cargar alguna fuente: obliga a recalcular las mediciones.
const versionMediciones = ref(0);
// Combinaciones "familia|peso" ya cargadas; solo con ellas se confía en las mediciones.
const cargadas = new Set();

const anchosEm = new Map(); // "familia|peso|texto" → ancho en em
const ajustes = new Map(); // clave completa del cálculo → resultado

let lienzo = null;
const contexto = () => {
  if (!lienzo) lienzo = document.createElement("canvas");
  return lienzo.getContext("2d");
};

// Ancho del texto en em (sin kerning). Multiplicado por el tamaño en pt da el ancho en pt.
export function medirEm(texto, familia, peso) {
  const clave = `${familia}|${peso}|${texto}`;
  const guardado = anchosEm.get(clave);
  if (guardado !== undefined) return guardado;

  const ctx = contexto();
  ctx.font = `${peso} ${REFERENCIA_PX}px ${FUENTES_CSS[familia] ?? FUENTES_CSS["sans-serif"]}`;
  ctx.fontKerning = "none";
  if ("letterSpacing" in ctx) ctx.letterSpacing = "0px";
  const em = ctx.measureText(texto).width / REFERENCIA_PX;

  anchosEm.set(clave, em);
  return em;
}

const redondearAbajo = (valor, paso) => Math.floor(valor / paso + 1e-9) * paso;
const redondearArriba = (valor, paso) => Math.ceil(valor / paso - 1e-9) * paso;
const dosDecimales = (v) => Math.round(v * 100) / 100;

// Política V1 (ver DisenoSchema::ESCALA_MINIMA_TEXTO_DINAMICO). Función pura: la misma que deberá
// implementar el generador de PDF.
export function calcularAjuste({ ancho, anchoCaja, fontSize, escalaMinima, pasoAjuste, tolerancia }) {
  if (ancho <= anchoCaja + tolerancia) {
    return { size: fontSize, reducido: false, noCabe: false, ancho };
  }

  const piso = redondearArriba(fontSize * escalaMinima, pasoAjuste);
  const proporcional = redondearAbajo((fontSize * anchoCaja) / ancho, pasoAjuste);
  const size = Math.max(proporcional, piso);
  const anchoFinal = ancho * (size / fontSize);

  return {
    size: dosDecimales(Math.min(size, fontSize)),
    reducido: size < fontSize,
    noCabe: anchoFinal > anchoCaja + tolerancia,
    ancho: anchoFinal,
  };
}

// Espera a que las fuentes usadas estén cargadas (document.fonts) antes de confiar en las
// mediciones. Las combinaciones que aún no cargaron no producen ajuste.
export async function prepararFuentes(usos) {
  const pendientes = usos.filter(({ familia, peso }) => !cargadas.has(`${familia}|${peso}`));
  if (!pendientes.length) return;

  if (typeof document !== "undefined" && document.fonts) {
    try {
      await Promise.all(
        pendientes.map(({ familia, peso }) =>
          document.fonts.load(`${peso} ${REFERENCIA_PX}px ${FUENTES_CSS[familia] ?? FUENTES_CSS["sans-serif"]}`)
        )
      );
      await document.fonts.ready;
    } catch {
      // Si una fuente falla al cargar se usa la de reserva; la medición seguirá siendo coherente.
    }
  }

  for (const { familia, peso } of pendientes) cargadas.add(`${familia}|${peso}`);
  anchosEm.clear();
  ajustes.clear();
  versionMediciones.value++;
}

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

  // Estado del autoajuste de un campo dinámico conocido; null para texto fijo, desconocido o
  // mientras su fuente no ha terminado de cargar.
  const ajusteDe = (el) => {
    versionMediciones.value; // dependencia reactiva: se recalcula al terminar de cargar las fuentes
    if (!esDinamico(el) || esDesconocido(el) || !cargadas.has(`${el.fontFamily}|${el.fontWeight}`)) return null;

    const texto = textoVisible(el);
    const clave = `${el.fontFamily}|${el.fontWeight}|${texto}|${el.fontSize}|${el.width}`;
    const previo = ajustes.get(clave);
    if (previo) return previo;

    const ancho = medirEm(texto, el.fontFamily, el.fontWeight) * el.fontSize;
    const resultado = calcularAjuste({ ancho, anchoCaja: el.width, fontSize: el.fontSize, ...config });
    ajustes.set(clave, resultado);
    return resultado;
  };

  // Familias/pesos de los campos dinámicos presentes, para esperar a que carguen.
  const usosDeFuentes = (elementos) => {
    const vistos = new Map();
    for (const el of elementos) {
      if (esDinamico(el)) vistos.set(`${el.fontFamily}|${el.fontWeight}`, { familia: el.fontFamily, peso: el.fontWeight });
    }
    return [...vistos.values()];
  };

  return { catalogo, esDinamico, esDesconocido, textoVisible, ajusteDe, usosDeFuentes };
}
