// Política de autoajuste V1 de los campos dinámicos (una línea). Módulo puro: sin DOM ni Vue.

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
