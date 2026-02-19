import { ref, computed } from 'vue';

export function useCurrencyFormat(model) {
  const isFocused = ref(false);

  const formattedValue = computed({
    get() {
      // MODO EDICIÓN: Si el input está enfocado
      if (isFocused.value) {
        // Si el valor es 0, null o undefined, mostramos '0' en lugar de vacío
        return (model.value === null || model.value === undefined || model.value === 0) 
          ? '0' 
          : model.value;
      }

      // MODO VISUALIZACIÓN: Formato Moneda COP
      // Si el valor no existe, formateamos el 0 por defecto
      const valueToFormat = model.value ?? 0;

      const options = {
        style: 'currency',
        currency: 'COP',
        minimumFractionDigits: 0, // En Colombia solemos usar 0 decimales para pesos
        maximumFractionDigits: 2,
      };
      return new Intl.NumberFormat('es-CO', options).format(valueToFormat);
    },
    
    set(newValue) {
      // Si el usuario borra todo, asignamos 0 al modelo para mantener el tipo Number
      if (newValue === '' || newValue === null || newValue === undefined) {
        model.value = 0;
        return;
      }
      
      // Limpiamos el valor para asegurar que sea un número válido
      const cleanValue = String(newValue).replace(',', '.');
      const parsed = parseFloat(cleanValue);

      if (!isNaN(parsed)) {
        model.value = parsed;
      } else {
        model.value = 0; // Backup en caso de que escriban basura
      }
    },
  });

  function handleFocus() {
    isFocused.value = true;
  }
  
  function handleBlur() {
    isFocused.value = false;
  }

  return {
    formattedValue,
    handleFocus,
    handleBlur,
  };
}