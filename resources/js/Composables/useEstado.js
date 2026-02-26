export default function useEstadoClass() {
  const getEstadoClass = (estado) => {
    if (estado === 'Inactivo' || estado === 'Pendiente') return 'bg-semaforo-rojo shadow-rojo';
    if (estado === 'Suspendido') return 'bg-semaforo-amarillo shadow-amarillo';
    if (estado === 'Activo' || estado === 'Estable') return 'bg-semaforo-verde shadow-verde';
    if ( estado === 'Pagado') return 'bg-semaforo-verde_opacity border border-semaforo-verde ';
    return '';
  };

  return { getEstadoClass };
}
