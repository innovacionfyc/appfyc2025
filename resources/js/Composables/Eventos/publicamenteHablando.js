// «Públicamente Hablando»: serie de encuentros gratuitos con previa inscripción. Cada edición se crea
// desde Eventos → Crear evento eligiendo este tipo.

export const PUBLICAMENTE_HABLANDO = "PUB_HABLANDO";

export const SERIE_PUBLICAMENTE_HABLANDO = "Públicamente Hablando con los expertos de F&C Consultores";

// Duración que se propone al elegir el tipo o cambiar el inicio; el fin se puede editar.
export const DURACION_PROPUESTA_MINUTOS = 60;

const TIPOS_SIN_COSTO = [PUBLICAMENTE_HABLANDO];

// Tipos que se guardan sin tarifas, sin oferta de valor y sin exigir folleto.
export const esSinCosto = (tipo) => TIPOS_SIN_COSTO.includes(tipo);

const pad = (n) => String(n).padStart(2, "0");

// Suma minutos a un valor de <input type="datetime-local"> («AAAA-MM-DDTHH:mm») sin pasar por la zona
// horaria del navegador: la hora escrita es la de Colombia y así se conserva.
export function sumarMinutos(valor, minutos) {
  const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(valor || "");
  if (!m) return valor;

  const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5] + minutos));

  return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}T${pad(
    d.getUTCHours()
  )}:${pad(d.getUTCMinutes())}`;
}

// Fin que se propone para un inicio dado. Se respeta un fin escrito a mano, salvo que esté vacío, no sea
// posterior al inicio o siga siendo la duración propuesta para el inicio anterior.
export function finPropuesto(inicio, fin, inicioAnterior = null) {
  if (!inicio) return fin;

  const propuesto = sumarMinutos(inicio, DURACION_PROPUESTA_MINUTOS);

  if (!fin || fin <= inicio) return propuesto;
  if (inicioAnterior && fin === sumarMinutos(inicioAnterior, DURACION_PROPUESTA_MINUTOS)) return propuesto;

  return fin;
}
