// Pruebas de la medición tipográfica y el autoajuste de Credential Flow, con Node puro:
//   node tests/js/credential-flow.test.mjs
// Usan los MISMOS vectores que PHPUnit (tests/Feature/CredentialFlow/vectores), generados de forma
// independiente con fontTools a partir de los TTF, y la misma metricas.json que lee PHP.
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { medirTexto, lineaBase, faltantesEnLineas } from "../../resources/js/Composables/CredentialFlow/textoMetrico.js";
import { calcularAjuste } from "../../resources/js/Composables/CredentialFlow/autoajuste.js";
import { planearTexto } from "../../resources/js/Composables/CredentialFlow/planTexto.js";

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

console.log(`${n} pruebas correctas${process.exitCode ? " (hay fallas)" : ""}`);
