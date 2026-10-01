// Pruebas de la medición tipográfica y el autoajuste de Credential Flow, con Node puro:
//   node tests/js/credential-flow.test.mjs
// Usan los MISMOS vectores que PHPUnit (tests/Feature/CredentialFlow/vectores), generados de forma
// independiente con fontTools a partir de los TTF, y la misma metricas.json que lee PHP.
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { parse, compileScript } from "@vue/compiler-sfc";
import { medirTexto, lineaBase, faltantesEnLineas } from "../../resources/js/Composables/CredentialFlow/textoMetrico.js";
import { calcularAjuste } from "../../resources/js/Composables/CredentialFlow/autoajuste.js";
import { planearTexto } from "../../resources/js/Composables/CredentialFlow/planTexto.js";
import { matrizQr, ladoQrPermitido, normalizarQr, nuevoQr, aplicarCambiosQr, payloadQr, esQr } from "../../resources/js/Composables/CredentialFlow/qrVerificacion.js";

const leer = (ruta) => JSON.parse(readFileSync(new URL(ruta, import.meta.url), "utf8"));
const tabla = leer("../../resources/fonts/credential-flow/metricas.json");
const vectores = leer("../Feature/CredentialFlow/vectores/vectores-tipograficos.json");

let n = 0;
const prueba = (nombre, fn) => {
  try {
    fn();
    n++;
  } catch (e) {
    console.error(`FALLA: ${nombre}\n${e.message}`);
    process.exitCode = 1;
  }
};

for (const v of vectores.anchos) {
  prueba(`ancho ${v.peso}/${v.fontSize} «${v.texto}»`, () => {
    const r = medirTexto(tabla, v.familia, v.peso, v.fontSize, v.texto);
    assert.ok(Math.abs(r.ancho - v.ancho) <= vectores.tolerancia + 1e-9, `${r.ancho} != ${v.ancho}`);
    assert.deepEqual(r.faltantes, v.faltantes);
    assert.equal(r.soportado, v.faltantes.length === 0);
  });
}

for (const v of vectores.lineasBase) {
  prueba(`línea base ${v.peso}/${v.fontSize}`, () => {
    const lb = lineaBase(tabla, v.familia, v.peso, v.fontSize, v.y, v.alto);
    assert.ok(Math.abs(lb - v.lineaBase) <= vectores.tolerancia + 1e-9, `${lb} != ${v.lineaBase}`);
  });
}

prueba("NFC: e + acento combinado mide igual que é", () => {
  const a = medirTexto(tabla, "outfit", 700, 22, "Café");
  const b = medirTexto(tabla, "outfit", 700, 22, "Café");
  assert.equal(a.ancho, b.ancho);
  assert.equal(a.texto, b.texto);
});

prueba("sin kerning: AV mide A + V", () => {
  const av = medirTexto(tabla, "outfit", 700, 100, "AV").ancho;
  const suma = medirTexto(tabla, "outfit", 700, 100, "A").ancho + medirTexto(tabla, "outfit", 700, 100, "V").ancho;
  assert.equal(av, suma);
});

prueba("fuente heredada o inexistente: sin métricas, no soportado", () => {
  const r = medirTexto(tabla, "Figtree", 700, 22, "Hola");
  assert.equal(r.soportado, false);
  assert.ok(Number.isNaN(r.ancho));
  assert.equal(lineaBase(tabla, "Figtree", 700, 22, 0, 30), null);
});

prueba("el ancho escala linealmente con el tamaño", () => {
  const a = medirTexto(tabla, "outfit", 400, 10, "Certificado").ancho;
  const b = medirTexto(tabla, "outfit", 400, 20, "Certificado").ancho;
  assert.ok(Math.abs(b - 2 * a) < 1e-9);
});

prueba("cobertura multilínea: un salto manual no es un carácter faltante", () => {
  assert.deepEqual(faltantesEnLineas(tabla, "outfit", 700, ["LÍNEA UNO", "LÍNEA DOS"]), []);
});

prueba("cobertura: 日本 se reporta sin duplicados, en cualquier línea", () => {
  assert.deepEqual(faltantesEnLineas(tabla, "outfit", 400, ["Hola 日本", "本 fin"]), ["日", "本"]);
});

prueba("cobertura: el español completo no falta en ningún peso", () => {
  for (const peso of [300, 400, 500, 600, 700, 800]) {
    assert.deepEqual(faltantesEnLineas(tabla, "outfit", peso, ["ÁÉÍÓÚáéíóúÑñÜü¿?¡!«»“”—"]), []);
  }
});

prueba("cobertura: las fuentes heredadas no se validan (sin métricas autoritativas)", () => {
  assert.deepEqual(faltantesEnLineas(tabla, "Figtree", 700, ["日本"]), []);
  assert.deepEqual(faltantesEnLineas(tabla, "Arial", 400, ["日本"]), []);
});

const cfg = { escalaMinima: 0.7, pasoAjuste: 0.25, tolerancia: 0.01 };

prueba("autoajuste: cabe → tamaño configurado", () => {
  const r = calcularAjuste({ ancho: 100, anchoCaja: 120, fontSize: 22, ...cfg });
  assert.deepEqual([r.size, r.reducido, r.noCabe], [22, false, false]);
});

prueba("autoajuste: reduce proporcional hacia abajo en pasos de 0,25 pt", () => {
  const r = calcularAjuste({ ancho: 300, anchoCaja: 260, fontSize: 22, ...cfg });
  assert.equal(r.size, 19); // 22*260/300 = 19.0667 → 19
  assert.equal(r.reducido, true);
  assert.equal(r.noCabe, false);
});

prueba("autoajuste: no baja del 70 % y marca «No cabe»", () => {
  const r = calcularAjuste({ ancho: 400, anchoCaja: 200, fontSize: 22, ...cfg });
  assert.equal(r.size, 15.5); // piso: ceil(15.4 / 0.25) * 0.25
  assert.equal(r.noCabe, true);
});

prueba("autoajuste: tolerancia de 0,01 pt", () => {
  const r = calcularAjuste({ ancho: 200.01, anchoCaja: 200, fontSize: 22, ...cfg });
  assert.equal(r.reducido, false);
});


const cerca = (a, b, m) => assert.ok(Math.abs(a - b) <= vectores.tolerancia + 1e-9, `${m}: ${a} != ${b}`);
const cfgPlan = { ...cfg, interlineado: 1.2 };

vectores.ajustes.forEach((v, i) => {
  prueba(`vector de autoajuste #${i}`, () => {
    const r = calcularAjuste({ ancho: v.ancho, anchoCaja: v.anchoCaja, fontSize: v.fontSize, ...cfg });
    assert.equal(r.size, v.esperado.size);
    assert.equal(r.reducido, v.esperado.reducido);
    assert.equal(r.noCabe, v.esperado.noCabe);
    cerca(r.ancho, v.esperado.ancho, "ancho");
  });
});

vectores.planes.forEach((v, i) => {
  prueba(`plan completo #${i} (${v.elemento.align}, ${v.elemento.field ?? "fijo"})`, () => {
    const p = planearTexto(tabla, cfgPlan, v.elemento, v.contenido);
    assert.equal(p.size, v.esperado.size);
    assert.equal(p.reducido, v.esperado.reducido);
    assert.equal(p.noCabe, v.esperado.noCabe);
    assert.equal(p.lineas.length, v.esperado.lineas.length);
    p.lineas.forEach((l, j) => {
      const e = v.esperado.lineas[j];
      assert.equal(l.texto, e.texto);
      cerca(l.ancho, e.ancho, "ancho línea");
      cerca(l.xInicio, e.xInicio, "xInicio");
      cerca(l.baseline, e.baseline, "baseline");
    });
  });
});

prueba("interlineado del texto fijo: 1,2 × tamaño y bloque centrado", () => {
  const el = { ...vectores.planes[3].elemento };
  const p = planearTexto(tabla, cfgPlan, el, "a\nb\nc");
  cerca(p.lineas[1].baseline - p.lineas[0].baseline, 1.2 * 22, "paso");
  cerca((p.lineas[0].baseline + p.lineas[2].baseline) / 2, planearTexto(tabla, cfgPlan, el, "a").lineas[0].baseline, "centro");
});

// ── QR de verificación (schema 2, opcional) ─────────────────────────────────────────────────────────────
const QR = { minimo: 85, maximo: 240, recomendado: 100, quietModulos: 4, ecc: "M" };
const PAG = { width: 792, height: 612 };
const URL_EJEMPLO = "https://fycconsultores.com/verificar/QA234567ABCDEFGHJKMN";

prueba("QR: la URL de ejemplo (57 caracteres, ECC M) da 33×33 módulos, igual que TCPDF (comprobado también en PHPUnit)", () => {
  const { n: lado, ruta } = matrizQr(URL_EJEMPLO, "M");
  assert.equal(lado, 33);
  assert.ok((ruta.match(/M/g) ?? []).length > 200, "hay módulos oscuros");
  assert.equal(matrizQr(URL_EJEMPLO, "L").n, 29);
  assert.equal(matrizQr(URL_EJEMPLO, "H").n, 41);
});

prueba("QR: dos códigos distintos dan matrices distintas", () => {
  assert.notEqual(matrizQr(URL_EJEMPLO).ruta, matrizQr(URL_EJEMPLO.replace("QA2345", "QA9999")).ruta);
});

prueba("QR: lado permitido entre 85 y 240 y sin superar la página", () => {
  assert.equal(ladoQrPermitido(10, QR, PAG), 85);
  assert.equal(ladoQrPermitido(500, QR, PAG), 240);
  assert.equal(ladoQrPermitido(100, QR, { width: 90, height: 612 }), 90);
  assert.equal(ladoQrPermitido(96.004, QR, PAG), 96);
});

prueba("QR: nuevo elemento cuadrado, dentro de la página, tamaño 100 y sin propiedades de texto", () => {
  const el = nuevoQr("00000000-0000-4000-8000-000000000001", QR, PAG);
  assert.equal(el.type, "qr");
  assert.equal(el.width, el.height);
  assert.equal(el.width, 100);
  assert.ok(el.x >= 0 && el.y >= 0 && el.x + el.width <= PAG.width && el.y + el.height <= PAG.height);
  assert.deepEqual(Object.keys(payloadQr(el)), ["id", "type", "x", "y", "width", "height"]);
  assert.ok(esQr(el) && !esQr({ type: "text" }));
});

prueba("QR: el resize mantiene 1:1 (ancho o alto) y respeta mínimo y máximo", () => {
  const el = nuevoQr("00000000-0000-4000-8000-000000000001", QR, PAG);
  let r = aplicarCambiosQr(el, { width: 120 }, QR, PAG);
  assert.deepEqual([r.width, r.height], [120, 120]);
  r = aplicarCambiosQr(el, { height: 150 }, QR, PAG);
  assert.deepEqual([r.width, r.height], [150, 150]);
  r = aplicarCambiosQr(el, { width: 10 }, QR, PAG);
  assert.deepEqual([r.width, r.height], [85, 85]);
  r = aplicarCambiosQr(el, { width: 999 }, QR, PAG);
  assert.deepEqual([r.width, r.height], [240, 240]);
  r = aplicarCambiosQr(el, { width: "abc" }, QR, PAG);
  assert.deepEqual([r.width, r.height], [100, 100]);
});

prueba("QR: no puede salir de la página al arrastrar, al agrandar ni desde el inspector", () => {
  const el = { ...nuevoQr("00000000-0000-4000-8000-000000000001", QR, PAG), x: 100, y: 100 };
  let r = aplicarCambiosQr(el, { x: 5000, y: 5000 }, QR, PAG);
  assert.equal(r.x, PAG.width - 100);
  assert.equal(r.y, PAG.height - 100);
  r = aplicarCambiosQr(el, { x: -20, y: -20 }, QR, PAG);
  assert.deepEqual([r.x, r.y], [0, 0]);
  r = aplicarCambiosQr({ ...el, x: 700, y: 500 }, { width: 200 }, QR, PAG);
  assert.ok(r.x + r.width <= PAG.width && r.y + r.height <= PAG.height, "al agrandar se reubica dentro de la página");
});

prueba("QR: un QR guardado no cuadrado o fuera de rango se normaliza a un cuadrado válido", () => {
  const r = normalizarQr({ id: "x", type: "qr", x: 0, y: 0, width: 50, height: 300 }, QR, PAG);
  assert.equal(r.width, r.height);
  assert.equal(r.width, 85);
});

// MensajesLayout: Vue no deja pasar `clearTimeout` como global en un template con <script setup> y lo compila como
// `_ctx.clearTimeout(...)` (TypeError al pasar el ratón por el toast). El handler debe vivir en el script.
prueba("MensajesLayout: el toast no llama a clearTimeout desde el template compilado", () => {
  const origen = readFileSync(new URL("../../resources/js/Layouts/MensajesLayout.vue", import.meta.url), "utf8");
  const { descriptor } = parse(origen);
  const compilado = compileScript(descriptor, { id: "mensajes-layout", inlineTemplate: true }).content;
  assert.ok(!compilado.includes("_ctx.clearTimeout"), "el template no debe resolver clearTimeout contra la instancia");
  assert.match(compilado, /onMouseenter:[^\n]*pausarTimer/, "mouseenter usa el handler del script");
  assert.match(descriptor.scriptSetup.content, /const pausarTimer = \(\) => \{\s*clearTimeout\(timer\);/);
});

console.log(`${n} pruebas correctas${process.exitCode ? " (hay fallas)" : ""}`);
