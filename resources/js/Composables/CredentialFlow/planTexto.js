// Plan de dibujo de UN elemento de texto: módulo PURO (sin DOM, Vue ni Vite). Es el contrato que
// implementa también PHP (Generacion/PlanificadorTexto): líneas, tamaño efectivo, x y línea base,
// todo en puntos PDF desde la esquina superior izquierda de la página.
//
// Reglas (V1):
//  - campo dinámico: UNA línea (cualquier salto se muestra como espacio), con autoajuste
//    (autoajuste.js: proporcional, pasos de 0,25 pt, piso 70 %, «No cabe» sin truncar). El contenido que recibe ya
//    incluye prefijo + valor + sufijo: es UN solo texto (se mide, se centra y se dibuja junto).
//  - campo dinámico con `multiline`: se envuelve por palabras dentro del ancho de la caja y solo si el bloque no cabe en
//    el alto se reduce (multilinea.js, hasta el 70 %); cada línea se alinea por separado; «No cabe» sin truncar.
//  - texto fijo: una línea por cada salto manual; sin wrap automático; interlineado
//    `interlineado × tamaño`; el bloque queda centrado verticalmente en la caja.
//  - texto NFC, ancho = suma de avances (hmtx), sin kerning ni ligaduras.
//  - línea base de una sola línea: y + alto/2 + ((ascent + descent) / 2 / unitsPerEm) × tamaño,
//    con `descent` con su signo almacenado (negativo). Sin factores de calibración.
import { calcularAjuste } from "./autoajuste.js";
import { medirTexto, lineaBase } from "./textoMetrico.js";
import { resolverMultilinea } from "./multilinea.js";

/** Líneas visibles de un elemento a partir de su contenido ya resuelto. */
export function lineasDeContenido(contenido, dinamico) {
  const texto = String(contenido ?? "");
  return dinamico ? [texto.replace(/\r?\n/g, " ")] : texto.split(/\r?\n/);
}

/**
 * @param {object} tabla    metricas.json
 * @param {object} config   { escalaMinima, pasoAjuste, tolerancia, interlineado }
 * @param {object} el       elemento del diseño (x, y, width, height, fontFamily, fontWeight, fontSize, align, field)
 * @param {string} contenido texto fijo, o el valor ya resuelto del campo dinámico
 */
export function planearTexto(tabla, config, el, contenido) {
  const dinamico = el.field !== null && el.field !== undefined;
  const multilinea = dinamico && el.multiline === true;
  let textos = multilinea ? String(contenido ?? "").normalize("NFC").split(/\r?\n/) : lineasDeContenido(contenido, dinamico);
  const base = { dinamico, alineacion: el.align, sizeConfigurado: el.fontSize };

  if (!tabla?.fuentes?.[el.fontFamily]?.[String(el.fontWeight)]) {
    // Fuente sin métricas (heredada): se puede mostrar, pero no medir ni ajustar.
    return {
      ...base,
      sinMetricas: true,
      soportado: true,
      faltantes: [],
      size: el.fontSize,
      reducido: false,
      noCabe: false,
      xAncla: null,
      lineas: textos.map((texto) => ({ texto, ancho: null, xInicio: null, baseline: null })),
    };
  }

  const medidas = textos.map((t) => medirTexto(tabla, el.fontFamily, el.fontWeight, el.fontSize, t));
  const faltantes = [];
  for (const m of medidas) for (const c of m.faltantes) if (!faltantes.includes(c)) faltantes.push(c);

  let size = el.fontSize;
  let reducido = false;
  let noCabe = false;
  if (multilinea && faltantes.length === 0) {
    const reparto = resolverMultilinea({
      medir: (t, s) => medirTexto(tabla, el.fontFamily, el.fontWeight, s, t).ancho,
      parrafos: textos,
      anchoCaja: el.width,
      altoCaja: el.height,
      fontSize: el.fontSize,
      config,
    });
    size = reparto.size;
    reducido = reparto.reducido;
    noCabe = reparto.noCabe;
    textos = reparto.lineas;
  } else if (dinamico && !multilinea && faltantes.length === 0) {
    const ajuste = calcularAjuste({
      ancho: medidas[0].ancho,
      anchoCaja: el.width,
      fontSize: el.fontSize,
      escalaMinima: config.escalaMinima,
      pasoAjuste: config.pasoAjuste,
      tolerancia: config.tolerancia,
    });
    size = ajuste.size;
    reducido = ajuste.reducido;
    noCabe = ajuste.noCabe;
  }

  const primera = lineaBase(tabla, el.fontFamily, el.fontWeight, size, el.y, el.height);
  const n = textos.length;
  const lineas = textos.map((_, i) => {
    const m = medirTexto(tabla, el.fontFamily, el.fontWeight, size, textos[i]);
    const xInicio =
      el.align === "left" ? el.x : el.align === "right" ? el.x + el.width - m.ancho : el.x + (el.width - m.ancho) / 2;
    return { texto: m.texto, ancho: m.ancho, xInicio, baseline: primera + (i - (n - 1) / 2) * config.interlineado * size };
  });

  const xAncla = el.align === "left" ? el.x : el.align === "right" ? el.x + el.width : el.x + el.width / 2;

  return { ...base, sinMetricas: false, soportado: faltantes.length === 0, faltantes, size, reducido, noCabe, xAncla, lineas };
}
