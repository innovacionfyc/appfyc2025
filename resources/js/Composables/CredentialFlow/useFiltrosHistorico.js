import { reactive, onBeforeUnmount } from "vue";
import { router } from "@inertiajs/vue3";

/**
 * Filtros del histórico: SIEMPRE se aplican en el servidor (GET con la consulta en la URL). El texto espera un momento tras
 * teclear; los selectores aplican de inmediato. Los valores vacíos o iguales al valor por defecto no viajan en la URL ni cuentan
 * como «filtro activo» (por eso «Limpiar filtros» solo aparece cuando hay algo que limpiar).
 *
 * @param {() => string} urlFn        URL base de la pantalla (p. ej. () => route("credential-flow.historico.index"))
 * @param {Object} iniciales          valores actuales de los filtros (los que devuelve el servidor)
 * @param {Object} porDefecto         valores que equivalen a «sin filtro» (p. ej. { orden: "anio" })
 */
export function useFiltrosHistorico(urlFn, iniciales, porDefecto = {}) {
  const filtros = reactive({ ...iniciales });
  let espera = null;

  const activos = () =>
    Object.fromEntries(
      Object.entries(filtros).filter(([k, v]) => v !== "" && v !== null && v !== undefined && v !== porDefecto[k])
    );

  const aplicar = () => {
    router.get(urlFn(), activos(), { preserveState: true, preserveScroll: true, replace: true });
  };

  const aplicarConEspera = () => {
    clearTimeout(espera);
    espera = setTimeout(aplicar, 400);
  };

  const limpiar = () => {
    for (const k of Object.keys(filtros)) filtros[k] = porDefecto[k] ?? "";
    aplicar();
  };

  onBeforeUnmount(() => clearTimeout(espera));

  return { filtros, aplicar, aplicarConEspera, limpiar, hayFiltros: () => Object.keys(activos()).length > 0 };
}
