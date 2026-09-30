// QR de verificación (elemento OPCIONAL del diseño, schema 2). Todo en puntos PDF, origen arriba a la izquierda.
//
// El editor dibuja un QR REAL de la URL de EJEMPLO que envía el servidor (código QA fijo que no existe en la base):
// la caja coincide con la del PDF (x, y, ancho y alto); la matriz interna puede usar otra máscara que la de TCPDF,
// pero codifica la misma URL con el mismo nivel de corrección (M) y el mismo número de módulos.
// La caja del elemento INCLUYE la zona de silencio (schema.qr.quietModulos por lado) y un fondo blanco.
import QRCode from "qrcode";

import { limitar, redondear } from "./coordenadas.js";

export const TIPO_QR = "qr";

export const esQr = (el) => el?.type === TIPO_QR;

// Matriz de módulos oscuros de un texto con corrección de errores fija (M). Devuelve `n` módulos por lado y la
// ruta SVG (un cuadrado 1×1 por módulo oscuro) en coordenadas de módulo, sin zona de silencio.
export function matrizQr(texto, ecc = "M") {
  const { modules } = QRCode.create(texto, { errorCorrectionLevel: ecc });
  const n = modules.size;
  let ruta = "";
  for (let fila = 0; fila < n; fila++) {
    for (let col = 0; col < n; col++) {
      if (modules.get(fila, col)) ruta += `M${col} ${fila}h1v1h-1z`;
    }
  }
  return { n, ruta };
}

// Lado permitido: dentro de [mínimo, máximo] y sin superar la página.
export function ladoQrPermitido(valor, qr, pagina) {
  const maximo = Math.min(qr.maximo, pagina.width, pagina.height);
  return redondear(limitar(Number(valor), qr.minimo, maximo));
}

// Normaliza un QR: siempre cuadrado, con el lado permitido y dentro de la página.
export function normalizarQr(el, qr, pagina, ladoDeseado = el.width) {
  const lado = ladoQrPermitido(ladoDeseado, qr, pagina);
  return {
    ...el,
    width: lado,
    height: lado,
    x: redondear(limitar(el.x, 0, pagina.width - lado)),
    y: redondear(limitar(el.y, 0, pagina.height - lado)),
  };
}

// Elemento nuevo: tamaño por defecto 100 pt (el recomendado), abajo a la derecha con margen de 36 pt. El
// administrador lo mueve donde quiera; no es una esquina fija.
export function nuevoQr(id, qr, pagina) {
  const base = normalizarQr({ id, type: TIPO_QR, x: 0, y: 0, width: 100, height: 100 }, qr, pagina, 100);
  return normalizarQr({ ...base, x: pagina.width - base.width - 36, y: pagina.height - base.height - 36 }, qr, pagina, base.width);
}

// Cambios de un QR desde el arrastre o el inspector: solo x, y y tamaño; el tamaño mantiene 1:1.
export function aplicarCambiosQr(el, cambios, qr, pagina) {
  const c = {};
  for (const k of ["x", "y", "width", "height"]) {
    if (k in cambios && Number.isFinite(Number(cambios[k]))) c[k] = Number(cambios[k]);
  }
  const lado = "width" in c ? c.width : "height" in c ? c.height : el.width;
  const base = { ...el };
  if ("x" in c) base.x = c.x;
  if ("y" in c) base.y = c.y;
  return normalizarQr(base, qr, pagina, lado);
}

// Payload que espera el backend: un QR solo lleva id, type, x, y, width y height.
export const payloadQr = (e) => ({ id: e.id, type: TIPO_QR, x: e.x, y: e.y, width: e.width, height: e.height });
