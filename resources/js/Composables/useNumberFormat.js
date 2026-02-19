import { computed } from 'vue';

export function useNumberFormat(model) {
  // Esta propiedad computada manejará la conversión en ambas direcciones.
  const formattedValue = computed({
    // GET: Toma el número del modelo y lo formatea para mostrarlo en el input.
    get() {
      if (model.value === null || model.value === undefined) {
        return '';
      }
      // 'es-CO' usa el punto como separador de miles.
      return model.value.toLocaleString('es-CO');
    },
    // SET: Toma el texto del input, lo limpia y actualiza el modelo.
    set(newValue) {
      // Elimina todo lo que no sea un dígito.
      const cleanedValue = newValue.replace(/[^\d]/g, '');

      // Si el campo está vacío, el modelo será null.
      if (cleanedValue === '') {
        model.value = null;
        return;
      }

      // Convierte el texto limpio a un número y actualiza el modelo.
      model.value = parseInt(cleanedValue, 10);
    },
  });

  return {
    formattedValue,
  };
}