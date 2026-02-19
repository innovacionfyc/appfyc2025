<script setup>
import { defineProps, defineEmits, ref, computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import {  handleInput, limitesCaracteres } from "@/Utils/formateoInputs";
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";
import BtnPrimario from "@/Components/Shared/buttons/btnPrimario.vue";
import Selects from "@/Components/Shared/inputs/Selects.vue";
import BtnSecundario from "@/Components/Shared/buttons/btnSecundario.vue";

const props = defineProps({
  isOpen: {
    type: Boolean,
    required: true,
  },
  establecimientosDisponibles: {
    type: Array,
    default: () => [],
  },
  ciudadesDisponibles: { type: Array, default: () => [] },
  indicativosDisponibles: { type: Array, default: () => [] },
  tipoDocumentoDisponibles: { type: Array, default: () => [] },
  rolesDisponibles: { type: Array, default: () => [] },
  generosDisponibles: { type: Array, default: () => [] },
  appsDisponibles: { type: Array, default: () => [] },
});

const emit = defineEmits(["close"]);

// --- LÓGICA DE PASOS ---
const currentStep = ref(1);
const totalSteps = 2;

const progressPercentage = computed(() => {
  return ((currentStep.value - 1) / (totalSteps - 1)) * 100;
});

const form = useForm({
  primer_nombre: "",
  segundo_nombre: "",
  primer_apellido: "",
  segundo_apellido: "",
  indicativo_id: "",
  telefono: "",
  correo: "",
  ciudad_residencia: "",
  barrio_residencia: "",
  direccion_residencia: "",
  tipo_documento_id: "",
  numero_documento: "",
  password: "",
  rol_id: "",
  genero: "",
  establecimiento_id: "",
  cargo: "",
  membresia_id: "",
  aplicacion_id: "",
  tipo_establecimiento: "",
});

const inicialesDelFormulario = computed(() => {
  const nombre = form.primer_nombre || "";
  const apellido = form.primer_apellido || "";

  const inicialNombre = nombre ? nombre.charAt(0).toUpperCase() : "";
  const inicialApellido = apellido ? apellido.charAt(0).toUpperCase() : "";

  return `${inicialNombre}${inicialApellido}`;
});

const inicialesDeTiendaDelFormulario = computed(() => {
  const prefijo = "Tienda de";
  const nombre = form.primer_nombre || "";

  const inicialPrefijo = prefijo ? prefijo.charAt(0).toUpperCase() : "";
  const inicialNombre = nombre ? nombre.charAt(0).toUpperCase() : "";

  return `${inicialPrefijo}${inicialNombre}`;
});

function nextStep() {
  form.clearErrors();
  const fieldsToValidate = [
    "primer_nombre",
    "primer_apellido",
    "indicativo_id",
    "telefono",
    "correo",
    "ciudad_residencia",
    "barrio_residencia",
    "direccion_residencia",
    "tipo_documento_id",
    "numero_documento",
    "password",
    "rol_id",
    "genero",
  ];
  let hasErrors = false;
  fieldsToValidate.forEach((field) => {
    if (!form[field]) {
      form.setError(field, `El campo es requerido.`);
      hasErrors = true;
    }
  });

  if (!hasErrors && currentStep.value < totalSteps) {
    currentStep.value++;
  }
}
function prevStep() {
  if (currentStep.value > 1) {
    currentStep.value--;
  }
}

const submit = () => {
  form.post(route("usuarios.store"), {
    preserveScroll: true,
    onSuccess: () => {
      emit("close");
      form.reset();
      currentStep.value = 1;
    },
  });
};
</script>

<template>
  <Transition name="modal-fade" appear>
    <div
      v-if="isOpen"
      class="fixed inset-0 z-50 flex items-center justify-center bg-mono-negro_opacity_full backdrop-blur-sm"
    >
      <button
        type="button"
        @click="$emit('close')"
        class="absolute top-5 right-5 text-gray-400 hover:text-white focus:outline-none"
      >
        <span class="material-symbols-rounded text-[30px]">close</span>
      </button>
      <Transition name="modal-slide" appear>
        <form
          @submit.prevent="submit"
          class="relative bg-mono-negro rounded-xl shadow-lg w-full max-w-[65%] border border-secundary-light text-gray-300 flex flex-col p-5"
        >
          <!-- Barra de Progreso -->
          <div class="flex gap-1 items-center">
            <div class="w-full flex justify-between bg-gray-600 rounded-full h-1">
              <div
                class="bg-primary shadow-[0px_5px_24px] shadow-primary h-1 rounded-full transition-all duration-500 ease-out"
                :style="{ width: progressPercentage + '%' }"
              ></div>
            </div>
            <p class="text-[12px]">{{ Math.round(progressPercentage) }}%</p>
          </div>

          <!-- Encabezado -->

          <!-- Cuerpo del Formulario con Transición -->
          <div class="overflow-hidden">
            <Transition name="slide-fade" mode="out-in">
              <div :key="currentStep" class="space-y-4">
                <!-- PASO 1: DATOS PERSONALES -->
                <div v-if="currentStep === 1">
                  <h4 class="font-semibold text-[35px] text-mono-blanco">
                    Datos personales del usuario
                  </h4>
                  <p class="text-[16px] text-secundary-light -mt-2 mb-4">
                    Ingresa correctamente los datos del usuario para obtener un registro
                    éxitoso.
                  </p>
                  <div class="space-y-4">
                    <div
                      class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
                    >
                      <InputTexto
                        v-model="form.primer_nombre"
                        label="Primer nombre:"
                        icon="format_italic"
                        type="text"
                        placeholder="Ej: Juan"
                        :maxLength="limitesCaracteres.nombresUsuario"
                        :error="form.errors.primer_nombre"
                      
                        @input="(e) => handleInput(e, form, 'primer_nombre')"
                      />

                      <InputTexto
                        v-model="form.segundo_nombre"
                        label="Segundo nombre:"
                        icon="format_italic"
                        type="text"
                        placeholder="Opcional*"
                        :maxLength="limitesCaracteres.nombresUsuario"
                        :error="form.errors.segundo_nombre"
                       
                        @input="(e) => handleInput(e, form, 'segundo_nombre')"
                      />
                    </div>
                    <div
                      class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
                    >
                      <InputTexto
                        v-model="form.primer_apellido"
                        label="Primer apellido:"
                        icon="format_italic"
                        type="text"
                        placeholder="Ej: Medina"
                        :maxLength="limitesCaracteres.nombresUsuario"
                        :error="form.errors.primer_apellido"
                       
                        @input="(e) => handleInput(e, form, 'primer_apellido')"
                      />

                      <InputTexto
                        v-model="form.segundo_apellido"
                        label="Segundo apellido:"
                        icon="format_italic"
                        type="text"
                        placeholder="Opcional*"
                        :maxLength="limitesCaracteres.nombresUsuario"
                        :error="form.errors.segundo_nombre"
                       
                        @input="(e) => handleInput(e, form, 'segundo_apellido')"
                      />
                    </div>

                    <div
                      class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
                    >
                      <div class="flex gap-2 items-center w-full">
                        <Selects
                          v-model="form.indicativo_id"
                          :options="props.indicativosDisponibles"
                          :error="form.errors.indicativo_id"
                          label="Indicativo:"
                          placeholder="Selecciona un indicativo"
                          id="indicativo"
                        />

                        <InputTexto
                          v-model="form.telefono"
                          label="Número celular:"
                          icon="phone"
                          type="number"
                          placeholder="316511****"
                          :maxLength="limitesCaracteres.telefono"
                          :error="form.errors.telefono"
                        
                          @input="(e) => handleInput(e, form, 'telefono')"
                        />
                      </div>

                      <InputTexto
                        v-model="form.correo"
                        label="Correo electrónico:"
                        icon="email"
                        type="email"
                        placeholder="example@dominio.com"
                        :maxLength="limitesCaracteres.nombresUsuario"
                        :error="form.errors.correo"
                       
                      />
                    </div>

                    <div
                      class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
                    >
                      <div class="w-full flex items-center gap-2">
                        <InputTexto
                          v-model="form.ciudad_residencia"
                          label="Ciudad residencia:"
                          icon="map"
                          type="text"
                          placeholder="Bogotá D.C."
                          :maxLength="limitesCaracteres.direccion"
                          :error="form.errors.ciudad_residencia"
                        
                          @input="(e) => handleInput(e, form, 'ciudad_residencia')"
                        />

                        <InputTexto
                          v-model="form.barrio_residencia"
                          label="Barrio residencia:"
                          icon="distance"
                          type="text"
                          placeholder="Luna Park"
                          :maxLength="limitesCaracteres.direccion"
                          :error="form.errors.barrio_residencia"
                          
                          @input="(e) => handleInput(e, form, 'barrio_residencia')"
                        />
                      </div>

                      <InputTexto
                        v-model="form.direccion_residencia"
                        label="Dirección residencia:"
                        icon="format_italic"
                        type="text"
                        placeholder="Calle 1a #2b-3 sur"
                        :maxLength="limitesCaracteres.direccion"
                        :error="form.errors.direccion_residencia"
                     
                      />
                    </div>
                    <div
                      class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
                    >
                      <div class="flex gap-2 items-center w-full">
                        <Selects
                          v-model="form.tipo_documento_id"
                          :options="props.tipoDocumentoDisponibles"
                          :error="form.errors.tipo_documento_id"
                          label="Tipo documento:"
                          placeholder="Selecciona el tipo"
                          id="tipo_documento_id"
                        />

                        <InputTexto
                          v-model="form.numero_documento"
                          label="Número documento:"
                          icon="pin"
                          type="number"
                          placeholder="10135****"
                          :maxLength="limitesCaracteres.numero_documento"
                          :error="form.errors.numero_documento"
                         
                          @input="(e) => handleInput(e, form, 'numero_documento')"
                        />
                      </div>
                      <InputTexto
                        v-model="form.password"
                        label="Contraseña:"
                        icon="password"
                        type="password"
                        placeholder="Genera una aleatoriamente"
                        :maxLength="limitesCaracteres.password"
                        :error="form.errors.password"
                       
                      />
                    </div>
                    <div
                      class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
                    >
                      <Selects
                        v-model="form.rol_id"
                        :options="props.rolesDisponibles"
                        :error="form.errors.rol_id"
                        label="Rol del usuario:"
                        placeholder="Escoje tus funciones"
                        id="rol_id"
                      />
                      <Selects
                        v-model="form.genero"
                        :options="props.generosDisponibles"
                        :error="form.errors.genero"
                        label="Género:"
                        placeholder="Selecciona tu genero"
                        id="genero"
                      />
                    </div>
                  </div>
                </div>

                <!-- PASO 2: TIPO DE USUARIO Y DATOS DE EMPLEADO -->
                <div v-if="currentStep === 2">
                  <h4 class="font-semibold text-[35px] text-mono-blanco">
                    Estás a un paso de finalizar
                  </h4>

                  <div class="space-y-4">
                    <div class="" v-if="form.rol_id === 1">
                      <h5 class="font-semibold text-[25px] text-mono-blanco">
                        ¡Sensacional!
                      </h5>
                      <p class="text-[16px] text-secundary-light -mt-2 mb-4">
                        Vas a crear un nuevo Fixgi
                      </p>
                      <div
                        class="contCard relative my-2 flex items-center gap-5 justify-between border backdrop-blur-xl p-3 rounded-[10px]"
                      >
                        <div
                          class="grid place-content-center foto w-[75px] h-[75px] rounded-[10px] bg-mono-blanco_opacity dark:bg-secundary-opacity backdrop-blur-lg"
                        >
                          <div class="relative group w-[75px] h-[75px]">
                            <p
                              class="h-full w-full flex justify-center items-center text-[20px] text-mono-blanco font-semibold border-2 rounded-[10px]"
                            >
                              {{ inicialesDelFormulario || "??" }}
                            </p>
                          </div>
                        </div>
                        <div class="detalles w-[40%]">
                          <div class="nombre flex gap-1 items-center">
                            <h2 class="text-[20px] font-medium">
                              {{ form.primer_nombre }}
                              {{ form.segundo_nombre }}
                              {{ form.primer_apellido }}
                              {{ form.segundo_apellido }}
                            </h2>
                          </div>
                          <div class="flex -mt-2 justify-between items-end">
                            <p class="text-secundary-light text-[14px]">
                              {{ form.correo }}
                            </p>
                            <p class="text-secundary-light text-[14px]">
                              {{ form.indicativo_id }} {{ form.telefono }}
                            </p>
                          </div>
                          <div class="flex justify-between items-center">
                            <div class="flex items-center gap-1">
                              <div
                                class="w-3 h-2 rounded-full bg-semaforo-verde shadow-[0px_10px_20px] shadow-semaforo-verde"
                              ></div>
                              <span class="text-secundary-light text-[14px]">Activo</span>
                            </div>
                            <p class="text-secundary-light text-[14px]">
                              {{ form.barrio_residencia }}, {{ form.ciudad_residencia }}
                            </p>
                          </div>
                        </div>
                        <p class="text-[50px] font-thin">|</p>
                        <div
                          class="grid place-content-center foto w-[60px] h-[60px] rounded-[10px] bg-mono-blanco_opacity dark:bg-secundary-opacity backdrop-blur-lg"
                        >
                          <div class="relative group w-[60px] h-[60px]">
                            <p
                              class="h-full w-full flex justify-center items-center text-[20px] text-mono-blanco font-semibold border-2 rounded-[10px]"
                            >
                              {{ inicialesDeTiendaDelFormulario || "??" }}
                            </p>
                          </div>
                        </div>
                        <div class="detalles w-[40%]">
                          <div class="nombre flex gap-1 items-center">
                            <h2 class="text-[20px] font-medium">
                              Tienda de {{ form.primer_nombre }}
                            </h2>
                          </div>
                          <div class="flex -mt-2 justify-between items-end">
                            <p class="text-secundary-light text-[14px]">
                              {{ form.tipo_establecimiento }}
                            </p>
                          </div>
                          <div class="flex justify-between items-center">
                            <div class="flex items-center gap-1">
                              <div
                                class="w-3 h-2 rounded-full bg-semaforo-verde shadow-[0px_10px_20px] shadow-semaforo-verde"
                              ></div>
                              <span class="text-secundary-light text-[14px]">Activo</span>
                            </div>
                            <p class="text-secundary-light text-[14px]">
                              {{ form.aplicacion_id }} - {{ form.membresia_id }}
                            </p>
                          </div>
                        </div>
                      </div>
                      <div
                        class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
                      >
                        <Selects
                          v-model="form.aplicacion_id"
                          :options="props.appsDisponibles"
                          :error="form.errors.aplicacion_id"
                          label="Aplicación web:"
                          placeholder="Elije la app"
                          id="aplicacion_id"
                        />

                        <InputTexto
                          v-model="form.tipo_establecimiento"
                          label="Tipo establecimiento:"
                          icon="category"
                          type="text"
                          placeholder="Ingresa el tipo de comercio"
                          :maxLength="limitesCaracteres.tipo_establecimiento"
                          :error="form.errors.tipo_establecimiento"
                       
                        />
                      </div>
                    </div>

                    <div class="" v-else>
                      <div class="" v-if="form.rol_id === 3">
                        <h5 class="font-semibold text-[25px] text-mono-blanco">
                          ¡Añade fieles!
                        </h5>
                        <p class="text-[16px] text-secundary-light -mt-2 mb-4">
                          Vas añadir clientes a un establecimiento
                        </p>
                      </div>

                      <div v-if="form.rol_id === 2 || form.rol_id === 4">
                        <h5 class="font-semibold text-[25px] text-mono-blanco">
                          ¡Alimentemos esos establecimiento!
                        </h5>
                        <p class="text-[16px] text-secundary-light -mt-2 mb-4">
                          Vas a crear un empleado a un establecimiento
                        </p>

                        <div
                          class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
                        >
                          <Selects
                            v-model="form.establecimiento_id"
                            :options="props.establecimientosDisponibles"
                            :error="form.errors.establecimiento_id"
                            label="Selecciona un establecimiento:"
                            placeholder="Elije el lugar"
                            id="establecimiento_id"
                          />

                          <InputTexto
                            v-model="form.cargo"
                            label="Escríbe su cargo:"
                            icon="work"
                            type="text"
                            placeholder="Ingresa su posición"
                            :maxLength="limitesCaracteres.cargo"
                            :error="form.errors.cargo"
                           
                          />
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </Transition>
          </div>

          <!-- Pie del Modal -->
          <div class="mt-5 flex gap-3 justify-between items-center flex-shrink-0">
            <BtnSecundario
              v-if="currentStep > 1"
              type="button"
              @click="prevStep"
              class="w-[50%] py-3 px-5"
            >
              <p class="text-[14px]">Atrás</p>
            </BtnSecundario>
            <div v-else></div>
            <BtnPrimario
              v-if="currentStep < totalSteps"
              type="button"
              @click="nextStep"
              class="w-[50%]"
              label="Siguiente"
            >
              Siguiente
            </BtnPrimario>

            <BtnPrimario
              class="w-[50%]"
              v-else
              type="submit"
              label="Crear usuario"
              :disabled="form.processing"
            ></BtnPrimario>
          </div>
        </form>
      </Transition>
    </div>
  </Transition>
</template>

<style scoped>
.input-field {
  @apply mt-1 block w-full bg-gray-800 border-gray-700 rounded-md shadow-sm focus:ring-primary focus:border-primary text-white;
}

.error-message {
  @apply text-red-500 text-xs mt-1;
}

/* Transición para el modal completo */
.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.3s ease;
}

.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}

.modal-slide-enter-active,
.modal-slide-leave-active {
  transition: all 0.3s ease-out;
}

.modal-slide-enter-from,
.modal-slide-leave-to {
  transform: translateY(20px);
  opacity: 0;
}

/* Transición para los pasos del formulario */
.slide-fade-enter-active,
.slide-fade-leave-active {
  transition: all 0.25s ease-out;
}

.slide-fade-enter-from {
  opacity: 0;
  transform: translateX(30px);
}

.slide-fade-leave-to {
  opacity: 0;
  transform: translateX(-30px);
}
</style>
