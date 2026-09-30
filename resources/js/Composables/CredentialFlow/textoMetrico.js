// Medición tipográfica PURA (sin DOM, sin Vue, sin Vite): ancho de un texto y línea base a partir
// de la tabla de métricas (metricas.json), la misma que lee el generador PHP (FuentesCredential).
//
// Reglas de paridad con el PDF (TCPDF):
//  - el texto se normaliza a NFC;
//  - el ancho es la suma de los avances (hmtx) de cada carácter: sin kerning ni ligaduras;
//  - si algún carácter no existe en la fuente NO se estima: `soportado` es false;
//  - línea base = y + alto/2 + ((ascent + descent) / 2 / unitsPerEm) * fontSize, con los valores
//    hhea de la fuente (descent negativo). Sin factores de calibración.

/** @returns {{ancho:number, soportado:boolean, faltantes:string[], texto:string}} */
export function medirTexto(tabla, familia, peso, fontSize, texto) {
  const m = tabla?.fuentes?.[familia]?.[String(peso)];
  if (!m) return { ancho: NaN, soportado: false, faltantes: [], texto };

  const nfc = String(texto).normalize("NFC");
  let suma = 0;
  const faltantes = [];
  for (const caracter of nfc) {
    const cp = caracter.codePointAt(0);
    const avance = cp < 32 ? undefined : m.anchos[cp];
    if (avance === undefined) {
      if (!faltantes.includes(caracter)) faltantes.push(caracter);
      continue;
    }
    suma += avance;
  }

  return { ancho: (suma / m.unitsPerEm) * fontSize, soportado: faltantes.length === 0, faltantes, texto: nfc };
}

/**
 * Caracteres únicos que la fuente no tiene, sobre varias líneas (los saltos entre líneas no cuentan).
 * Una fuente sin métricas (heredada) devuelve [] a propósito: no hay datos autoritativos que validar.
 */
export function faltantesEnLineas(tabla, familia, peso, lineas) {
  if (!tabla?.fuentes?.[familia]?.[String(peso)]) return [];
  const faltantes = [];
  for (const linea of lineas) {
    for (const c of medirTexto(tabla, familia, peso, 1, linea).faltantes) {
      if (!faltantes.includes(c)) faltantes.push(c);
    }
  }
  return faltantes;
}

/** Línea base en puntos desde el borde superior de la página; null si no hay métricas. */
export function lineaBase(tabla, familia, peso, fontSize, y, alto) {
  const m = tabla?.fuentes?.[familia]?.[String(peso)];
  if (!m) return null;

  return y + alto / 2 + ((m.ascent + m.descent) / 2 / m.unitsPerEm) * fontSize;
}
