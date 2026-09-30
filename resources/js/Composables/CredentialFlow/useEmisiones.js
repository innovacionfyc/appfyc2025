// Acciones de emisión (POST JSON) con un único candado: mientras una acción está en curso no se acepta otra,
// de modo que un doble clic nunca envía dos solicitudes. Los errores esperados llegan como { code, message, detalles }.
import { ref } from "vue";
import axios from "axios";

export function useEmisiones() {
  const enCurso = ref(null); // clave de la acción en curso, o null
  const error = ref(null); // { code, message, detalles? }

  async function ejecutar(clave, url, cuerpo = {}) {
    if (enCurso.value !== null) return null;

    enCurso.value = clave;
    error.value = null;
    try {
      const { data } = await axios.post(url, cuerpo);
      return data;
    } catch (e) {
      if (e.response?.status === 429) {
        error.value = { code: "DEMASIADAS_SOLICITUDES", message: "Demasiadas solicitudes seguidas. Espera un minuto e inténtalo de nuevo." };
      } else if (e.response?.status === 422 && e.response.data?.errors) {
        error.value = { code: "VALIDACION", message: Object.values(e.response.data.errors).flat().join(" ") };
      } else {
        error.value = e.response?.data?.error ?? { code: "ERROR", message: "No se pudo completar la operación. Inténtalo de nuevo." };
      }
      return null;
    } finally {
      enCurso.value = null;
    }
  }

  return { enCurso, error, ejecutar };
}
