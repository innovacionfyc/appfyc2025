// Reparto en varias líneas de un campo dinámico con «Permitir varias líneas». Módulo PURO (sin DOM, Vue ni Vite): es el
// port EXACTO de Generacion/Multilinea.php (mismas reglas y mismos redondeos; se comprueba con vectores compartidos).
//
// Política:
//  1. Primero se ENVUELVE a su tamaño configurado: salto por palabras (nunca se parte una palabra), respetando el ancho de
//     la caja y los saltos manuales.
//  2. Si cabe (ancho de cada línea y alto total del bloque dentro de la caja), se usa el tamaño configurado.
//  3. Si no, se reduce en pasos de `pasoAjuste` (como mucho hasta `escalaMinima`) y se vuelve a envolver en cada paso: se
//     elige el tamaño más grande con el que el bloque cabe.
//  4. Si ni al mínimo cabe, NO se trunca ni se baja más: `noCabe`.

const redondearArriba = (valor, paso) => Math.ceil(valor / paso - 1e-9) * paso;
const dosDecimales = (v) => Math.round(v * 100) / 100;

/**
 * Salto por palabras (separadas por espacios). Una palabra más ancha que la caja queda sola en su línea (no se parte).
 * Un párrafo vacío es una línea en blanco.
 */
export function envolver(medirLinea, parrafos, anchoCaja, tolerancia) {
  const lineas = [];
  for (const parrafo of parrafos) {
    const palabras = parrafo.split(" ").filter((p) => p !== "");
    if (palabras.length === 0) {
      lineas.push("");
      continue;
    }

    let actual = palabras[0];
    for (const palabra of palabras.slice(1)) {
      const candidata = `${actual} ${palabra}`;
      if (medirLinea(candidata) <= anchoCaja + tolerancia) actual = candidata;
      else {
        lineas.push(actual);
        actual = palabra;
      }
    }
    lineas.push(actual);
  }
  return lineas;
}

/**
 * @param {(texto:string, size:number)=>number} medir  ancho (pt) de un texto a un tamaño
 * @param {string[]} parrafos  texto ya en NFC, partido por saltos manuales
 * @param {object} config  { escalaMinima, pasoAjuste, tolerancia, interlineado }
 * @returns {{size:number, reducido:boolean, noCabe:boolean, lineas:string[]}}
 */
export function resolverMultilinea({ medir, parrafos, anchoCaja, altoCaja, fontSize, config }) {
  const { escalaMinima, pasoAjuste, tolerancia, interlineado } = config;
  const piso = redondearArriba(fontSize * escalaMinima, pasoAjuste);

  for (let i = 0; ; i++) {
    const size = fontSize - i * pasoAjuste;
    const lineas = envolver((t) => medir(t, size), parrafos, anchoCaja, tolerancia);

    let anchoMax = 0;
    for (const linea of lineas) anchoMax = Math.max(anchoMax, medir(linea, size));
    const altoTotal = lineas.length * interlineado * size;
    const cabe = anchoMax <= anchoCaja + tolerancia && altoTotal <= altoCaja + tolerancia;

    const siguiente = fontSize - (i + 1) * pasoAjuste;
    if (cabe || siguiente < piso - 1e-9) {
      return { size: dosDecimales(size), reducido: size < fontSize, noCabe: !cabe, lineas };
    }
  }
}
