// Pruebas de las reglas de «Públicamente Hablando» que usa el formulario de eventos, con Node puro:
//   node tests/js/publicamente-hablando.test.mjs
import assert from "node:assert/strict";
import {
  PUBLICAMENTE_HABLANDO,
  esSinCosto,
  sumarMinutos,
  finPropuesto,
} from "../../resources/js/Composables/Eventos/publicamenteHablando.js";
import { tarifasVisibles } from "../../resources/js/Composables/Eventos/tarifasEvento.js";

let n = 0;
const prueba = (nombre, fn) => {
  try {
    fn();
    n++;
  } catch (e) {
    console.error(`✗ ${nombre}`);
    throw e;
  }
};

prueba("solo Públicamente Hablando es sin costo", () => {
  assert.equal(esSinCosto(PUBLICAMENTE_HABLANDO), true);
  for (const tipo of ["SEMINARIO", "JORNADA", "MODULO", "JOR_MOD", "SEM_DUPLA", "JOR_DUPLA", "", null, undefined]) {
    assert.equal(esSinCosto(tipo), false, String(tipo));
  }
});

prueba("sumarMinutos conserva la hora escrita y cruza día, mes y año", () => {
  assert.equal(sumarMinutos("2026-11-27T08:30", 60), "2026-11-27T09:30");
  assert.equal(sumarMinutos("2026-11-27T23:30", 60), "2026-11-28T00:30");
  assert.equal(sumarMinutos("2026-11-30T23:45", 60), "2026-12-01T00:45");
  assert.equal(sumarMinutos("2026-12-31T23:30", 60), "2027-01-01T00:30");
  assert.equal(sumarMinutos("", 60), "");
});

prueba("finPropuesto propone una hora si el fin está vacío o no es posterior al inicio", () => {
  assert.equal(finPropuesto("2026-11-27T08:30", ""), "2026-11-27T09:30");
  assert.equal(finPropuesto("2026-11-27T08:30", "2026-11-27T08:30"), "2026-11-27T09:30");
  assert.equal(finPropuesto("2026-11-27T08:30", "2026-11-20T10:00"), "2026-11-27T09:30");
});

prueba("finPropuesto respeta un fin escrito a mano", () => {
  assert.equal(finPropuesto("2026-11-27T08:30", "2026-11-27T10:00"), "2026-11-27T10:00");
  assert.equal(finPropuesto("2026-11-27T08:00", "2026-11-27T10:00", "2026-11-27T08:30"), "2026-11-27T10:00");
});

prueba("finPropuesto mueve el fin con el inicio mientras se mantenga la duración propuesta", () => {
  assert.equal(finPropuesto("2026-11-27T10:00", "2026-11-27T09:30", "2026-11-27T08:30"), "2026-11-27T11:00");
  assert.equal(finPropuesto("2027-01-29T08:30", "2026-11-27T09:30", "2026-11-27T08:30"), "2027-01-29T09:30");
});

prueba("finPropuesto sin inicio no cambia el fin", () => {
  assert.equal(finPropuesto("", "2026-11-27T09:30"), "2026-11-27T09:30");
});

prueba("una edición guardada sin tarifas no muestra importes", () => {
  const sinPrecios = Object.fromEntries(
    ["precio_jornada", "precio_seminario", "precio_modulo", "precio_cng", "precio_jornada_virtual"].map((c) => [c, "0.00"])
  );
  assert.deepEqual(tarifasVisibles({ tipo_evento: PUBLICAMENTE_HABLANDO, ...sinPrecios }), []);
});

console.log(`✓ ${n} pruebas de Públicamente Hablando`);
