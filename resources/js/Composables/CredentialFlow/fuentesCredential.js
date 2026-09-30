// Fuentes de Credential Flow en el editor: carga real de los TTF (mismos archivos que usará el
// generador de PDF), estado de carga por combinación familia+peso y medición por tabla.
//
// Nunca hay una sustitución silenciosa: si una fuente reproducible no carga, su estado es «error»
// y el editor no dibuja ni mide con otra fuente.
import { reactive } from "vue";
import metricas from "../../../fonts/credential-flow/metricas.json";
import outfitLight from "../../../fonts/credential-flow/outfit/Outfit-Light.ttf?url";
import outfitRegular from "../../../fonts/credential-flow/outfit/Outfit-Regular.ttf?url";
import outfitMedium from "../../../fonts/credential-flow/outfit/Outfit-Medium.ttf?url";
import outfitSemiBold from "../../../fonts/credential-flow/outfit/Outfit-SemiBold.ttf?url";
import outfitBold from "../../../fonts/credential-flow/outfit/Outfit-Bold.ttf?url";
import outfitExtraBold from "../../../fonts/credential-flow/outfit/Outfit-ExtraBold.ttf?url";
import { medirTexto as medirTextoTabla, lineaBase as lineaBaseTabla, faltantesEnLineas } from "./textoMetrico";

export const OUTFIT = "outfit";

// Nombre propio para no chocar con una «Outfit» instalada en el sistema del usuario.
export const CSS_FAMILIA_OUTFIT = "'CF Outfit'";

const URLS = {
  300: outfitLight,
  400: outfitRegular,
  500: outfitMedium,
  600: outfitSemiBold,
  700: outfitBold,
  800: outfitExtraBold,
};

// Fuentes heredadas: solo se muestran con la fuente CSS del navegador y se advierte de ello.
export const FUENTES_HEREDADAS_CSS = {
  Figtree: "'Figtree', sans-serif",
  Arial: "Arial, Helvetica, sans-serif",
  "sans-serif": "sans-serif",
};

export const AVISO_HEREDADA =
  "Fuente heredada: no está preparada para generación PDF. Usa Outfit para una salida reproducible.";

export const esReproducible = (familia, peso) => familia === OUTFIT && URLS[Number(peso)] !== undefined;
export const esHeredada = (familia) => familia in FUENTES_HEREDADAS_CSS;

export const cssFamilia = (familia) => (familia === OUTFIT ? CSS_FAMILIA_OUTFIT : FUENTES_HEREDADAS_CSS[familia] ?? null);

// "familia|peso" → 'cargando' | 'lista' | 'error'. Reactivo: la interfaz reacciona al cambio.
const estados = reactive({});
const promesas = new Map();

const clave = (familia, peso) => `${familia}|${Number(peso)}`;

/** 'lista' | 'cargando' | 'error' | 'heredada' | 'desconocida' */
export function estadoFuente(familia, peso) {
  if (esHeredada(familia)) return "heredada";
  if (!esReproducible(familia, peso)) return "desconocida";
  return estados[clave(familia, peso)] ?? "cargando";
}

async function cargarUna(familia, peso) {
  const k = clave(familia, peso);
  if (estados[k] === "lista") return true;
  if (promesas.has(k)) return promesas.get(k);

  estados[k] = "cargando";
  const promesa = (async () => {
    try {
      const face = new FontFace("CF Outfit", `url(${URLS[Number(peso)]})`, {
        weight: String(peso),
        style: "normal",
      });
      await face.load();
      document.fonts.add(face);
      estados[k] = "lista";
      return true;
    } catch {
      estados[k] = "error";
      promesas.delete(k); // permite reintentar
      return false;
    }
  })();
  promesas.set(k, promesa);
  return promesa;
}

/** Inicia (o espera) la carga de las combinaciones dadas [{familia, peso}]. Solo las reproducibles. */
export function cargarFuentes(usos) {
  return Promise.all(
    usos.filter((u) => esReproducible(u.familia, u.peso)).map((u) => cargarUna(u.familia, u.peso))
  );
}

/** Reintenta las que fallaron. */
export function reintentarFuentes() {
  const fallidas = Object.keys(estados).filter((k) => estados[k] === "error");
  return cargarFuentes(fallidas.map((k) => ({ familia: k.split("|")[0], peso: Number(k.split("|")[1]) })));
}

/** Hay alguna fuente reproducible en error (bloquea el guardado). */
export const hayFuentesConError = () => Object.values(estados).includes("error");

export const medirTexto = (familia, peso, fontSize, texto) => medirTextoTabla(metricas, familia, peso, fontSize, texto);

export const lineaBase = (familia, peso, fontSize, y, alto) => lineaBaseTabla(metricas, familia, peso, fontSize, y, alto);

export const faltantesDeLineas = (familia, peso, lineas) => faltantesEnLineas(metricas, familia, peso, lineas);

export { metricas };
