// Helpers compartidos por las páginas y modales administrativos del podcast.

export function usePodcastAdmin() {
  // Clases de badge por nombre de estado (nunca por ID)
  const estadoClass = (nombre) => {
    const n = (nombre || "").toLowerCase();
    if (n === "activo") return "bg-emerald-500/10 text-emerald-600 ring-1 ring-emerald-500/20";
    if (n === "borrador") return "bg-amber-500/10 text-amber-600 ring-1 ring-amber-500/20";
    if (n === "archivado") return "bg-slate-500/10 text-slate-500 ring-1 ring-slate-400/20";
    return "bg-blue-500/10 text-blue-600 ring-1 ring-blue-500/20";
  };

  const formatFecha = (fecha, opciones = { day: "numeric", month: "short", year: "numeric" }) => {
    if (!fecha) return null;
    const d = new Date(String(fecha).substring(0, 10) + "T12:00:00");
    if (isNaN(d.getTime())) return null;
    return new Intl.DateTimeFormat("es-CO", opciones).format(d);
  };

  const formatDuracion = (segundos) => {
    if (!segundos && segundos !== 0) return null;
    const total = Number(segundos);
    if (!Number.isFinite(total) || total <= 0) return null;
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    if (h > 0) return `${h} h ${String(m).padStart(2, "0")} min`;
    return s ? `${m} min ${String(s).padStart(2, "0")} s` : `${m} min`;
  };

  const iniciales = (nombre) =>
    (nombre || "")
      .trim()
      .split(/\s+/)
      .slice(0, 2)
      .map((p) => p[0] || "")
      .join("")
      .toUpperCase() || "?";

  // Busca un estado por nombre dentro de los estados enviados por el backend
  const idEstadoPorNombre = (estados, nombre) =>
    estados.find((e) => (e.tipo_estado || "").toLowerCase() === nombre.toLowerCase())?.id ?? "";

  const opcionesEstados = (estados) => estados.map((e) => ({ id: e.id, nombre: e.tipo_estado }));

  return { estadoClass, formatFecha, formatDuracion, iniciales, idEstadoPorNombre, opcionesEstados };
}
