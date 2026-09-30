// Carga de PDF con pdf.js para el editor de Credential Flow.
//
// Los PDF exportados desde InDesign suelen llevar el fondo en JPEG2000 (JPXDecode), que pdf.js
// decodifica con un módulo WebAssembly (openjpeg.wasm). En lugar de copiar esos archivos a
// public/, Vite los empaqueta con `?url` y esta fábrica se los entrega a pdf.js: así siempre
// coinciden con la versión instalada y no se toca public/build ni se añaden binarios al repo.
import * as pdfjsLib from "pdfjs-dist";
import workerUrl from "pdfjs-dist/build/pdf.worker.min.mjs?url";
import openjpegWasm from "pdfjs-dist/wasm/openjpeg.wasm?url";
import qcmsWasm from "pdfjs-dist/wasm/qcms_bg.wasm?url";
import jbig2Wasm from "pdfjs-dist/wasm/jbig2.wasm?url";

pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl;

const ARCHIVOS_WASM = {
  "openjpeg.wasm": openjpegWasm,
  "qcms_bg.wasm": qcmsWasm,
  "jbig2.wasm": jbig2Wasm,
};

class FabricaDatosBinarios {
  // pdf.js pide aquí los archivos auxiliares (wasm, cmaps, fuentes estándar).
  async fetch({ kind, filename }) {
    const url = kind === "wasmUrl" ? ARCHIVOS_WASM[filename] : null;
    if (!url) {
      // Sin cmaps ni fuentes estándar: los PDF del certificado llevan sus fuentes embebidas.
      throw new Error(`Recurso de pdf.js no disponible: ${kind}/${filename}`);
    }
    const respuesta = await fetch(url);
    if (!respuesta.ok) throw new Error(`No se pudo cargar ${filename} (${respuesta.status})`);
    return new Uint8Array(await respuesta.arrayBuffer());
  }
}

// Devuelve { documento, pagina, ancho, alto, paginas } con las medidas de la página 1 en puntos.
export async function cargarPdf(url) {
  // Absoluta: pdf.js puede resolver una ruta relativa desde el worker y no desde la página.
  const absoluta = new URL(url, window.location.origin).href;

  const tarea = pdfjsLib.getDocument({
    url: absoluta,
    withCredentials: true, // el PDF privado solo se sirve a un admin con sesión
    useWorkerFetch: false,
    BinaryDataFactory: FabricaDatosBinarios,
    wasmUrl: "/", // obligatorio para activar wasm; los archivos reales los sirve la fábrica
  });

  const documento = await tarea.promise;
  const pagina = await documento.getPage(1);
  const vista = pagina.getViewport({ scale: 1 }); // a escala 1, 1 unidad = 1 punto PDF

  return {
    documento,
    pagina,
    ancho: vista.width,
    alto: vista.height,
    paginas: documento.numPages,
  };
}
