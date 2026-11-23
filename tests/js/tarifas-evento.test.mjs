// Pruebas de las tarifas que muestra la página pública de un evento, con Node puro:
//   node tests/js/tarifas-evento.test.mjs
import assert from "node:assert/strict";
import { tarifasVisibles, etiquetaTarifaUnica, moduloSinModalidad } from "../../resources/js/Composables/Eventos/tarifasEvento.js";

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

const sinPrecios = {
  precio_jornada: "0.00", precio_jornada_presencial: "0.00", precio_jornada_virtual: "0.00",
  precio_seminario: "0.00", precio_seminario_presencial: "0.00", precio_seminario_streaming: "0.00",
  precio_modulo: "0.00", precio_modulo_virtual: "0.00", precio_cng: "0.00", precio_cng_virtual: "0.00",
  precio_curso_intensivo_hibrido: "0.00", precio_curso_intensivo_virtual: "0.00",
  precio_diplomado_hibrido: "0.00", precio_diplomado_virtual: "0.00",
};
const evento = (tipo, precios, modalidad = "Virtual") => ({ ...sinPrecios, tipo_evento: tipo, modalidad, ...precios });

prueba("MODULO muestra solo «Módulo» con su importe, sea cual sea la modalidad", () => {
  for (const modalidad of ["Virtual", "Presencial", "Híbrido"]) {
    const e = evento("MODULO", { precio_modulo: "480000.00" }, modalidad);
    assert.deepEqual(tarifasVisibles(e), [{ label: "Módulo", value: 480000 }]);
    assert.equal(etiquetaTarifaUnica(e), "Módulo");
  }
});

prueba("MODULO no muestra tarifas de otros tipos aunque queden importes guardados", () => {
  const e = evento("MODULO", { precio_modulo: "480000.00", precio_jornada: "990000.00", precio_modulo_virtual: "1.00" });
  assert.deepEqual(tarifasVisibles(e), [{ label: "Módulo", value: 480000 }]);
});

prueba("MODULO sin importe se muestra como gratuito (sin tarifas)", () => {
  const e = evento("MODULO", { precio_jornada: "990000.00" });
  assert.deepEqual(tarifasVisibles(e), []);
  assert.equal(etiquetaTarifaUnica(e), null);
});

prueba("JOR_MOD presencial o híbrido conserva «Jornada Completa» y «Módulo Presencial»", () => {
  for (const modalidad of ["Presencial", "Híbrido"]) {
    const e = evento("JOR_MOD", { precio_jornada: "400000.00", precio_modulo: "450000.00" }, modalidad);
    assert.deepEqual(tarifasVisibles(e), [
      { label: "Jornada Completa", value: 400000 },
      { label: "Módulo Presencial", value: 450000 },
    ]);
    assert.equal(etiquetaTarifaUnica(e), null);
  }
});

prueba("JOR_MOD virtual muestra «Jornada Completa» y «Módulo», en ese orden y con sus importes", () => {
  const e = evento("JOR_MOD", { precio_jornada: "410000.00", precio_modulo: "460000.00" }, "Virtual");
  assert.deepEqual(tarifasVisibles(e), [
    { label: "Jornada Completa", value: 410000 },
    { label: "Módulo", value: 460000 },
  ]);
  assert.equal(etiquetaTarifaUnica(e), null);
});

prueba("El precio del módulo se nombra sin modalidad solo en MODULO y en JOR_MOD virtual", () => {
  assert.equal(moduloSinModalidad("MODULO", "Presencial"), true);
  assert.equal(moduloSinModalidad("MODULO", "Virtual"), true);
  assert.equal(moduloSinModalidad("JOR_MOD", "Virtual"), true);
  assert.equal(moduloSinModalidad("JOR_MOD", "Presencial"), false);
  assert.equal(moduloSinModalidad("JOR_MOD", "Híbrido"), false);
  assert.equal(moduloSinModalidad("MOD_DUPLA", "Virtual"), false);
  assert.equal(moduloSinModalidad("CUR_CNG_MOD_DUPLA", "Virtual"), false);
});

prueba("MOD_DUPLA virtual conserva «Módulo Presencial» y «Módulo Virtual»", () => {
  const e = evento("MOD_DUPLA", { precio_modulo: "300000.00", precio_modulo_virtual: "250000.00" }, "Virtual");
  assert.deepEqual(tarifasVisibles(e).map((t) => t.label), ["Módulo Presencial", "Módulo Virtual"]);
});

prueba("JOR_DUPLA conserva «Jornada Presencial» y «Jornada Virtual»", () => {
  const e = evento("JOR_DUPLA", { precio_jornada_presencial: "310000.00", precio_jornada_virtual: "210000.00" }, "Híbrido");
  assert.deepEqual(tarifasVisibles(e), [
    { label: "Jornada Presencial", value: 310000 },
    { label: "Jornada Virtual", value: 210000 },
  ]);
});

prueba("SEM_DUPLA conserva «Seminario Presencial» y «Seminario Streaming»", () => {
  const e = evento("SEM_DUPLA", { precio_seminario_presencial: "350000.00", precio_seminario_streaming: "250000.00" }, "Híbrido");
  assert.deepEqual(tarifasVisibles(e), [
    { label: "Seminario Presencial", value: 350000 },
    { label: "Seminario Streaming", value: 250000 },
  ]);
});

prueba("MOD_DUPLA conserva «Módulo Presencial» y «Módulo Virtual»", () => {
  const e = evento("MOD_DUPLA", { precio_modulo: "300000.00", precio_modulo_virtual: "250000.00" });
  assert.deepEqual(tarifasVisibles(e), [
    { label: "Módulo Presencial", value: 300000 },
    { label: "Módulo Virtual", value: 250000 },
  ]);
});

prueba("Un tipo de tarifa única distinto de MODULO no muestra etiqueta (sin cambios)", () => {
  const e = evento("JORNADA", { precio_jornada: "200000.00" });
  assert.deepEqual(tarifasVisibles(e), [{ label: "Jornada Completa", value: 200000 }]);
  assert.equal(etiquetaTarifaUnica(e), null);
});

prueba("Sin evento no falla", () => {
  assert.deepEqual(tarifasVisibles(null), []);
  assert.equal(etiquetaTarifaUnica(undefined), null);
});

console.log(`✓ ${n} pruebas de tarifas de eventos`);
