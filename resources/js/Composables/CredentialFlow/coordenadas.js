// Conversión entre el canvas del navegador (píxeles) y la página PDF (puntos).
//
// El diseño se guarda SIEMPRE en puntos PDF (1 pt = 1/72 in), con origen arriba a la izquierda.
// El canvas se muestra a un ancho en píxeles que depende de la ventana; la relación entre ambos
// es la escala (px por pt):
//
//     escala = anchoCanvasPx / anchoPaginaPt
//     px = pt * escala        pt = px / escala
//
// Cambiar el tamaño de la ventana solo cambia la escala; las coordenadas guardadas no se tocan.

export const DECIMALES = 2;

export const calcularEscala = (anchoPx, anchoPt) => (anchoPt > 0 && anchoPx > 0 ? anchoPx / anchoPt : 0);

export const ptAPx = (pt, escala) => pt * escala;

export const pxAPt = (px, escala) => (escala > 0 ? px / escala : 0);

export const redondear = (valor, decimales = DECIMALES) => {
  const f = 10 ** decimales;
  return Math.round((Number(valor) + Number.EPSILON) * f) / f;
};

// Limita `valor` a [min, max]; si el rango es inválido (max < min) gana `min`.
export const limitar = (valor, min, max) => Math.min(Math.max(valor, min), Math.max(min, max));

// Mantiene un elemento (x, y, width, height en pt) dentro de la página.
export const limitarElemento = (el, pagina, minimo = 1) => {
  const width = limitar(el.width, minimo, pagina.width);
  const height = limitar(el.height, minimo, pagina.height);
  return {
    ...el,
    width: redondear(width),
    height: redondear(height),
    x: redondear(limitar(el.x, 0, pagina.width - width)),
    y: redondear(limitar(el.y, 0, pagina.height - height)),
  };
};

export const xCentrada = (el, pagina) => redondear((pagina.width - el.width) / 2);

export const yCentrada = (el, pagina) => redondear((pagina.height - el.height) / 2);

// UUID v4. crypto.randomUUID solo existe en contextos seguros (https / localhost) y el panel
// local corre en http://*.test, así que se usa getRandomValues, que sí está disponible.
export const nuevoUuid = () => {
  const b = crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = [...b].map((x) => x.toString(16).padStart(2, "0"));
  return `${h.slice(0, 4).join("")}-${h.slice(4, 6).join("")}-${h.slice(6, 8).join("")}-${h.slice(8, 10).join("")}-${h.slice(10).join("")}`;
};
