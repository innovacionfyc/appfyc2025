// Tarifas que muestra la página pública de un evento.

// Lista general: cada tarifa con importe mayor que 0, con su etiqueta habitual.
const TARIFAS = [
  { label: "Jornada Completa", campo: "precio_jornada" },
  { label: "Jornada Presencial", campo: "precio_jornada_presencial" },
  { label: "Jornada Virtual", campo: "precio_jornada_virtual" },
  { label: "Seminario", campo: "precio_seminario" },
  { label: "Seminario Presencial", campo: "precio_seminario_presencial" },
  { label: "Seminario Streaming", campo: "precio_seminario_streaming" },
  { label: "Módulo Presencial", campo: "precio_modulo" },
  { label: "Módulo Virtual", campo: "precio_modulo_virtual" },
  { label: "Congreso Presencial", campo: "precio_cng" },
  { label: "Congreso Virtual", campo: "precio_cng_virtual" },
  { label: "Curso Intensivo Híbrido", campo: "precio_curso_intensivo_hibrido" },
  { label: "Curso Intensivo Virtual", campo: "precio_curso_intensivo_virtual" },
  { label: "Diplomado Híbrido", campo: "precio_diplomado_hibrido" },
  { label: "Diplomado Virtual", campo: "precio_diplomado_virtual" },
];

// Tipos de tarifa única: solo se muestra su tarifa, con su propia etiqueta y sin indicar la
// modalidad, que se configura aparte. El importe es el guardado en cada evento.
const TARIFA_UNICA = {
  MODULO: { label: "Módulo", campo: "precio_modulo" },
};

const importe = (evento, campo) => parseFloat(evento?.[campo]) || 0;

export function tarifasVisibles(evento) {
  const unica = TARIFA_UNICA[evento?.tipo_evento];
  const tarifas = unica ? [unica] : TARIFAS;

  return tarifas
    .map(({ label, campo }) => ({ label, value: importe(evento, campo) }))
    .filter((item) => item.value > 0);
}

// Etiqueta que acompaña al importe cuando el tipo tiene tarifa única (null en los demás casos,
// que siguen mostrando solo el importe).
export function etiquetaTarifaUnica(evento) {
  const unica = TARIFA_UNICA[evento?.tipo_evento];

  return unica && importe(evento, unica.campo) > 0 ? unica.label : null;
}
