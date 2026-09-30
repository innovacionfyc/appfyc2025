// Descarga de un archivo (PDF o ZIP) generado en el servidor con fetch: si la respuesta es un PDF se descarga como blob;
// si es un error esperado (JSON 422) se devuelve el código y el mensaje para mostrarlo en la página.
import { ref } from "vue";

export function useDescargaPdf() {
  const generando = ref(null); // id de lo que se está generando, o null
  const error = ref(null); // { code, message }

  async function descargar(id, url, nombreArchivo) {
    if (generando.value !== null) return false;

    generando.value = id;
    error.value = null;
    try {
      const respuesta = await fetch(url, {
        credentials: "same-origin",
        headers: { Accept: "application/pdf, application/zip, application/json", "X-Requested-With": "XMLHttpRequest" },
      });

      const tipo = respuesta.headers.get("Content-Type") ?? "";
      if (respuesta.ok && (tipo.includes("application/pdf") || tipo.includes("application/zip"))) {
        const enlaceUrl = URL.createObjectURL(await respuesta.blob());
        const enlace = document.createElement("a");
        enlace.href = enlaceUrl;
        enlace.download = nombreArchivo;
        document.body.appendChild(enlace);
        enlace.click();
        enlace.remove();
        setTimeout(() => URL.revokeObjectURL(enlaceUrl), 10000);
        return true;
      }

      if (respuesta.status === 429) {
        error.value = { code: "DEMASIADAS_SOLICITUDES", message: "Demasiadas solicitudes seguidas. Espera un minuto e inténtalo de nuevo." };
        return false;
      }
      const cuerpo = await respuesta.json().catch(() => null);
      error.value = cuerpo?.error ?? { code: "ERROR", message: "No se pudo obtener el archivo." };
      return false;
    } catch {
      error.value = { code: "RED", message: "No se pudo contactar con el servidor. Revisa tu conexión e inténtalo de nuevo." };
      return false;
    } finally {
      generando.value = null;
    }
  }

  return { generando, error, descargar };
}
