<script setup>
import { defineProps, defineEmits, ref, computed, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import {  handleInput, limitesCaracteres } from "@/Utils/formateoInputs";
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";
import Selects from "@/Components/Shared/inputs/Selects.vue";
import NumberInput from "@/Components/Shared/inputs/NumberInput.vue";
import BaseModalSteps from '@/Components/Shared/Modales/BaseModalSteps.vue';

const props = defineProps({
  isOpen: {
    type: Boolean,
    required: true,
  },
  categoriasDisponibles: { type: Array, default: () => [] },
  TipoProductoDisponibles: { type: Array, default: () => [] },
  manejaInventarioProducto: { type: Array, default: () => [] },
  unidadesMedidaDispobibles: { type: Array, default: () => [] },
  ingredientesDisponibles: { type: Array, default: () => [] },
});
const emit = defineEmits(['close']);

const currentStep = ref(1);
const totalSteps = 2;
const transitionName = ref('slide-next');

const form = useForm({
  categoria_id: "",
  tipo: "",
  nombre: "",
  plu_item: "",
  descripcion: "",
  precio: "",
  imagen_url: "",
  maneja_inventario: "",
  stock_actual: "",

  unidad_medida_id: "",
  ingrediente_id: "",
  sku: "",
  stock_actual: "",
  stock_minimo: "",
  costo_promedio: "",
  cantidad_ingrediente: "",
  unidad_medida_id_ingrediente: "",
});

function handleNext() {
  transitionName.value = 'slide-next';
  form.clearErrors();
  // Aquí tu lógica de validación
  if (currentStep.value < totalSteps) {
    currentStep.value++;
  }
}

function handlePrev() {
  transitionName.value = 'slide-back';
  if (currentStep.value > 1) {
    currentStep.value--;
  }
}

function submit() {
  form.post(route('repos.productos.create'), {
    preserveScroll: true,
    onSuccess: () => closeModal(true),
  });
}

function closeModal(force = false) {
  if (form.isDirty && !force) {
    if (confirm('¿Estás seguro de que quieres salir? Perderás los cambios no guardados.')) {
      emit('close');
      setTimeout(() => {
        form.reset();
        currentStep.value = 1;
      }, 300);
    }
  } else {
    emit('close');
    setTimeout(() => {
      form.reset();
      currentStep.value = 1;
    }, 300);
  }
}

const inicialesDeProductoFormulario = computed(() => {
  const nombre = form.nombre || "";

  const inicialNombre = nombre ? nombre.charAt(0).toUpperCase() : "";

  return `${inicialNombre}`;
});
</script>

<template>
  <BaseModalSteps :isOpen="isOpen" v-model:currentStep="currentStep" :totalSteps="totalSteps"
    :isSubmitting="form.processing" finalButtonText="Crear Producto" @close="closeModal" @next="handleNext"
    @prev="handlePrev" @submit="submit">
    <template #header>
    </template>

  <Transition :name="transitionName" mode="out-in">
      <div :key="currentStep">
        <div v-if="currentStep === 1">
          <div class="flex items-center gap-3 pl-3">
            <div class="w-4 h-4 bg-primary rounded-full">
              <div class="w-4 h-4 bg-primary rounded-full animate-ping"></div>
            </div>
            <div class="">
              <h4 class="font-semibold text-[35px] text-secondary dark:text-mono-blanco">
                Editar stock de ingredientes
              </h4>
              <p class="text-[16px] text-secondary dark:text-secundary-light -mt-2 mb-4">
                Ingresa correctamente los datos de tu stok para obtener un
                registro éxitoso.
              </p>
            </div>
          </div>

          <div class="space-y-3 my-2">
            <div
              class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
              <InputTexto v-model="form.nombre" label="Nombra tu producto:" icon="cookie" type="text"
                placeholder="Producto 1" :maxLength="limitesCaracteres.nombre_producto" :error="form.errors.nombre"
                @input="(e) => handleInput(e, form, 'nombre')" />

              <InputTexto v-model="form.descripcion" label="Descripción del producto" icon="format_italic" type="text"
                placeholder="Producto 1 de la casa artesanal" :maxLength="limitesCaracteres.descripcion_app"
                :error="form.errors.descripcion" 
                @input="(e) => handleInput(e, form, 'descripcion')" />
            </div>

            <div
              class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
              <InputTexto v-model="form.plu_item" label="PLU del producto" icon="format_italic" type="text"
                placeholder="28062004" :maxLength="limitesCaracteres.password" :error="form.errors.plu_item"
                @input="(e) => handleInput(e, form, 'plu_item')" />

              <Selects v-model="form.maneja_inventario" :options="props.manejaInventarioProducto"
                :error="form.errors.maneja_inventario" label="Maneja inventario:" placeholder="Si"
                id="maneja_inventario" />
            </div>
            <div
              class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
              <Selects v-model="form.categoria_id" :options="categoriasDisponibles" :error="form.errors.categoria_id"
                label="Categoria:" placeholder="Selecciona una de tus categorias" id="categoria" />

              <Selects v-model="form.tipo" :options="props.TipoProductoDisponibles" :error="form.errors.tipo"
                label="Tipo de producto:" placeholder="Selecciona un tipo" id="tipo" />
            </div>
            <div
              class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
              <NumberInput v-model="form.precio" label="Precio del producto" icon="attach_money" type="number"
                placeholder="Ingresa un monto (10.000)" :maxLength="limitesCaracteres.precio_producto"
                :error="form.errors.precio"  
                @input="(e) => handleInput(e, form, 'precio')" />


            </div>
          </div>
        </div>

        <div v-if="currentStep === 2">
          <h4 class="font-semibold text-[35px] text-mono-blanco">
            Estás a un paso de finalizar
          </h4>

          <div class="space-y-4">
            <div class="" v-if="form.tipo === 'SIMPLE'">
              <h5 class="font-semibold text-[25px] text-mono-blanco">
                ¡Sensacional!
              </h5>
              <p class="text-[16px] text-secundary-light -mt-2 mb-4">
                Selecciona la cantidad que tienes.
              </p>
              <div
                class="contCard relative my-2 flex items-center gap-5 justify-between border backdrop-blur-xl p-3 rounded-[10px]">
                <div
                  class="grid place-content-center foto w-[75px] h-[75px] rounded-[10px] bg-primary dark:bg-secundary-opacity backdrop-blur-lg">
                  <div class="relative group w-[75px] h-[75px]">
                    <p
                      class="h-full w-full flex justify-center items-center text-[20px] text-mono-blanco font-semibold border-2 rounded-[10px]">
                      {{ inicialesDeProductoFormulario || "??" }}
                    </p>
                  </div>
                </div>
                <div class="detalles w-[40%]">
                  <div class="nombre flex gap-1 items-center">
                    <h2 class="text-[20px] font-medium">
                      {{ form.nombre }}
                    </h2>
                  </div>
                  <div class="flex -mt-2 justify-between items-end">
                    <p class="text-secundary-light text-[14px]">
                      {{ form.descripcion }}
                    </p>
                    <!-- <p class="text-secundary-light text-[14px]">
                              {{ form.indicativo_id }} {{ form.telefono }}
                            </p> -->
                  </div>
                </div>
              </div>
              <div
                class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
                <InputTexto v-model="form.stock_actual" label="Cantidad disponible:" icon="pin" type="text"
                  placeholder="100" :maxLength="limitesCaracteres.indicativo" :error="form.errors.stock_actual"
                 />
              </div>
            </div>

            <div class="" v-else>
              <h5 class="font-semibold text-[25px] text-mono-blanco">
                Hmm, ¡Platillo nuevo!
              </h5>
              <p class="text-[16px] text-secundary-light -mt-2 mb-4">
                Arma tu receta
              </p>
              <div
                class="contCard relative my-2 flex items-center gap-5 justify-between border backdrop-blur-xl p-3 rounded-[10px]">
                <div
                  class="grid place-content-center foto w-[75px] h-[75px] rounded-[10px] bg-mono-blanco_opacity dark:bg-secundary-opacity backdrop-blur-lg">
                  <div class="relative group w-[75px] h-[75px]">
                    <p
                      class="h-full w-full flex justify-center items-center text-[20px] text-mono-blanco font-semibold border-2 rounded-[10px]">
                      {{ inicialesDeProductoFormulario || "??" }}
                    </p>
                  </div>
                </div>
                <div class="detalles w-[40%]">
                  <div class="nombre flex gap-1 items-center">
                    <h2 class="text-[20px] font-medium">
                      {{ form.nombre }}
                    </h2>
                  </div>
                  <div class="flex -mt-2 justify-between items-end">
                    <p class="text-secundary-light text-[14px]">
                      {{ form.descripcion }}
                    </p>
                    <!-- <p class="text-secundary-light text-[14px]">
                              {{ form.indicativo_id }} {{ form.telefono }}
                            </p> -->
                  </div>
                </div>
              </div>
              <div
                class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
                <Selects v-model="form.ingrediente_id" :options="props.ingredientesDisponibles"
                  :error="form.errors.ingrediente_id" label="Selecciona uno de tus ingredientes"
                  placeholder="Selecciona uno" id="ingrediente_id" />
              </div>
              <div
                class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
                <InputTexto v-model="form.cantidad_ingrediente" label="cantidad de ingrediente:" icon="pin" type="text"
                  placeholder="1000" :maxLength="limitesCaracteres.capacidadMesas"
                  :error="form.errors.cantidad_ingrediente"/>

                <Selects v-model="form.unidad_medida_id" :options="props.unidadesMedidaDispobibles"
                  :error="form.errors.unidad_medida_id" label="Unidad medida:" placeholder="Selecciona un tipo"
                  id="unidad_medida" />
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </BaseModalSteps>
</template>

<style scoped>
/*
  AÑADIDO: Estilos para la transición direccional.
  Puedes ponerlos en el <style scoped> de tu CreateProductModal.vue
*/

/* --- Estilos para ir HACIA ADELANTE --- */
.slide-next-enter-active,
.slide-next-leave-active {
  /* Usamos una curva de aceleración suave para un look más profesional */
  transition: all 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
}
/* El nuevo paso entra desde la derecha */
.slide-next-enter-from {
  opacity: 0;
  transform: translateX(50px);
}
/* El paso antiguo sale hacia la izquierda */
.slide-next-leave-to {
  opacity: 0;
  transform: translateX(-50px);
}


/* --- Estilos para ir HACIA ATRÁS --- */
.slide-back-enter-active,
.slide-back-leave-active {
  transition: all 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
}
/* El nuevo paso entra desde la izquierda */
.slide-back-enter-from {
  opacity: 0;
  transform: translateX(-50px);
}
/* El paso antiguo sale hacia la derecha */
.slide-back-leave-to {
  opacity: 0;
  transform: translateX(50px);
}
</style>