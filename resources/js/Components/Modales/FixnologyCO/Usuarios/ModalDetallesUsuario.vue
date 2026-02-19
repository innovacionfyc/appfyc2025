<script setup>
import { defineProps, defineEmits, computed, ref, watch } from "vue";
import useEstadoClass from "@/Composables/useEstado";
import { useTiempo } from "@/Composables/useTiempo";
import { formatFecha } from "@/Utils/date";
import { formatCOP } from "@/Utils/formateoMoneda";
import { router, useForm } from "@inertiajs/vue3";
import { useInfoUser } from "@/stores/infoUsers";
import { useAuthStore } from "@/stores/auth";
import dayjs from "dayjs";
import ConfirmacionesPop from "../../Confirmaciones/ConfirmacionesPop.vue";
import { handleInput, limitesCaracteres } from "@/Utils/formateoInputs";
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
  authStore: {
    type: Object,
    required: true,
  },
  ciudadesDisponibles: { type: Array, default: () => [] },
  indicativosDisponibles: { type: Array, default: () => [] },
  tipoDocumentoDisponibles: { type: Array, default: () => [] },
  rolesDisponibles: { type: Array, default: () => [] },
  generosDisponibles: { type: Array, default: () => [] },
  appsDisponibles: { type: Array, default: () => [] },
  estadosDisponibles: { type: Array, default: () => [] },
});

const emit = defineEmits(["close"]);
const infoUserStore = useInfoUser();
const authStore = useAuthStore();
const aplicacion = authStore.aplicacion;
const rol = authStore.rol;
const { getEstadoClass } = useEstadoClass();
const error = ref(null);
const usuarioSeleccionado = computed(() => infoUserStore.user);

const { tiempoActivo, diasRestantes } = useTiempo(usuarioSeleccionado);

const keysToSync = [
  "primerNombre",
  "segundoNombre",
  "primerApellido",
  "segundoApellido",
  "indicativo_id",
  "telefono",
  "email",
  "ciudadResidencia",
  "barrioResidencia",
  "direccionResidencia",
  "tipoDocumento_id",
  "numero_documento",
  "rol_id",
  "genero",
  "nombreEstablecimiento",
  "cargo",
  "tipoEstablecimiento",
  "telefonoEstablecimiento",
  "emailEstablecimiento",
  "ciudadEstablecimiento",
  "barrioEstablecimiento",
  "direccionEstablecimiento",
  "tokenEstablecimiento",
  "estadoToken_id",
  "estadoEmpleado_id",
  "estadoUsuario_id",
  "aplicacion_id",
  "estadoIdEstablecimiento",
];

const initialData = {};
keysToSync.forEach((key) => {
  initialData[key] = infoUserStore[key];
});

const form = useForm({
  ...initialData,
});
watch(
  infoUserStore,
  (newState) => {
    keysToSync.forEach((key) => {
      if (form[key] !== newState[key]) {
        form[key] = newState[key];
      }
    });
  },
  { deep: true }
);

const inicialesNombreUsuario = computed(() => {
  const nombres = infoUserStore.primerNombre || "";
  const apellidos = infoUserStore.primerApellido || "";
  const firstNameInitial = nombres.charAt(0).toUpperCase() || "";
  const lastNameInitial = apellidos.charAt(0).toUpperCase() || "";
  return firstNameInitial + lastNameInitial;
});

const inicialesNombreEstablecimiento = computed(() => {
  const nombreEstablecimiento = infoUserStore.nombreEstablecimiento || "Tienda";
  const palabrasAIgnorar = ["de", "el", "la", "los", "las", "y", "a"];
  const iniciales = nombreEstablecimiento
    .split(" ")
    .filter((palabra) => !palabrasAIgnorar.includes(palabra.toLowerCase()))
    .map((palabra) => palabra[0])
    .join("");
  return iniciales.toUpperCase().slice(0, 2);
});

const activeTab = ref(0);
const tabs = [{ label: "Información general" }, { label: "Editar usuario" }];

const currentStep = ref(1);
const totalSteps = 3;

const progressPercentage = computed(() => {
  return ((currentStep.value - 1) / (totalSteps - 1)) * 100;
});

function nextStep() {
  form.clearErrors();

  if (currentStep.value < totalSteps) {
    currentStep.value++;
  }
}
function prevStep() {
  if (currentStep.value > 1) {
    currentStep.value--;
  }
}

// CORRECTO ✅
const submit = () => {
  form.put(
    // 1. Primer argumento: SOLO la URL con sus parámetros de ruta (si los necesita)
    route("usuarios.actualizarPerfilUsuario", {
      user: infoUserStore.idUsuario,
      aplicacion: aplicacion,
      rol: rol,
    }),

    // 2. Segundo argumento: UN objeto con TODAS las opciones de Inertia
    {
      preserveScroll: true,
      onSuccess: () => {
        emit("close");
        form.reset();
        currentStep.value = 1;
      },
      onError: (errors) => {
        // Es una buena práctica añadir esto para ver los errores de validación en la consola
        console.error("Errores del backend:", errors);
      },
    }
  );
};

import { useConfirmationModal } from "@/Composables/useConfirmationModal";
const {
  confirmationState,
  openConfirmationModal,
  closeConfirmationModal,
  handleConfirm,
} = useConfirmationModal();

function eliminarUsuario() {
  openConfirmationModal({
    title: "¿Estás seguro de enviar a la papelera?",
    message: `Estás a punto de enviar a la papelera a ${infoUserStore.nombreCompleto}.`,
    icon: "info",
    iconBgClass: "bg-yellow-500/20 border-semaforo-amarillo",
    confirmText: "Sí, enviar",
    onConfirm: () => {
      router.delete(
        route("usuarios.destroy", {
          usuarioAEliminar: infoUserStore.idUsuario,
        }),
        {
          preserveScroll: true,
          onSuccess: () => {
            emit("close");
          },
        }
      );
    },
  });
}

const historialFacturas = computed(() => {
  return [...infoUserStore.facturas].sort((a, b) =>
    dayjs(b.fecha_pago).diff(dayjs(a.fecha_pago))
  );
});

const proximaFactura = computed(() => {
  if (infoUserStore.facturas.length === 0) {
    return null;
  }

  const ultimaFacturaPagada = historialFacturas.value.find(
    (f) => f.estado.tipo_estado === "Pagado"
  );

  if (!ultimaFacturaPagada) {
    return historialFacturas.value[0];
  }
  const fechaUltimoPago = dayjs(ultimaFacturaPagada.fecha_pago);
  const proximaFechaPago = fechaUltimoPago.add(infoUserStore.duracionMembresia, "day");

  return {
    fecha_pago: proximaFechaPago.toISOString(),
    estado: { tipo_estado: "Pendiente" },
    medio_pago: ultimaFacturaPagada.medio_pago,
    monto_total: ultimaFacturaPagada.monto_total,
  };
});
</script>

<template>
  <Transition name="modal-fade" appear>
    <div
      v-if="isOpen"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-70 backdrop-blur-sm"
      @click.self="$emit('close')"
    >
      <Transition name="modal-slide" appear>
        <div
          v-if="infoUserStore.isAuthenticated"
          class="relative bg-mono-negro_opacity_full rounded-xl shadow-lg w-full max-w-[90%] text-gray-300 overflow-hidden"
        >
          <div class="mb-6 flex justify-between items-center my-3 px-4">
            <nav class="-mb-px flex space-x-4">
              <button
                v-for="(tab, index) in tabs"
                :key="index"
                @click="activeTab = index"
                :class="[
                  'text-[12px] font-medium px-4 py-2',
                  activeTab === index
                    ? 'text-primary border-b-2 border-b-primary'
                    : 'text-secundary-default dark:text-secundary-light dark:hover:text-primary',
                ]"
              >
                {{ tab.label }}
              </button>
            </nav>
            <button
              @click="$emit('close')"
              class="text-gray-400 material-symbols-rounded text-[25px] hover:text-gray-200 focus:outline-none"
            >
              Close
            </button>
          </div>
          <div v-if="activeTab === 0" class="p-3">
            <div
              class="contenido px-3 max-h-[85vh] w-full overflow-auto scrollbar-custom contenido-principal"
            >
              <div class="miPerfil w-full">
                <div
                  class="rounded-[15px] p-3 flex flex-col gap-5 div2 bg-mono-blanco_opacity dark:bg-secundary-opacity"
                >
                  <div class="w-full h-[130px] rounded-md opacity-50 bg-primary"></div>

                  <div class="flex justify-center flex-col items-center">
                    <div
                      class="-mt-28 ml-5 mb-2 grid place-content-center foto w-[180px] h-[180px] rounded-[18px] bg-mono-blanco_opacity dark:bg-secundary-opacity backdrop-blur-lg"
                    >
                      <template v-if="infoUserStore.fotoUrlCompletaUsuario">
                        <div class="relative group w-[160px] h-[160px]">
                          <img
                            :src="infoUserStore.fotoUrlCompletaUsuario"
                            class="rounded-[18px] w-full h-full object-cover shadow-lg"
                          />
                        </div>
                      </template>

                      <template v-else>
                        <div
                          class="relative flex justify-center rounded-[18px] items-center group w-[160px] h-[160px] bg-primary shadow-[0px_10px_20px] shadow-primary"
                        >
                          <p class="text-[60px] font-semibold">
                            {{ inicialesNombreUsuario }}
                          </p>
                        </div>
                      </template>
                    </div>
                    <div class="flex items-center justify-between gap-1">
                      <span class="material-symbols-rounded text-[18px] text-primary"
                        >crown</span
                      >
                      <h3
                        class="text-mono-negro text-[25px] font-semibold dark:text-mono-blanco"
                      >
                        {{ infoUserStore.nombreCompleto }}
                      </h3>
                      <div
                        class="grid place-items-center"
                        v-if="infoUserStore.google_id === null"
                      >
                        <span class="material-symbols-rounded text-[18px] text-gray-700"
                          >verified_off</span
                        >
                      </div>
                      <div class="text-secundary-default dark:text-mono-blanco" v-else>
                        <span
                          class="grid place-items-center material-symbols-rounded text-[18px] text-universal-azul_secundaria"
                          >verified</span
                        >
                      </div>
                    </div>
                  </div>

                  <div class="infoBasica">
                    <div class="number flex items-center justify-between gap-2">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[16px] text-primary"
                          >key</span
                        >
                        <p
                          class="text-[14px] text-secundary-default dark:text-mono-blanco"
                        >
                          {{ infoUserStore.google_id || infoUserStore.idUsuario }}
                        </p>
                      </div>

                      <div class="flex items-center gap-1">
                        <div
                          class="w-3 h-3 rounded-full"
                          :class="getEstadoClass(infoUserStore.estadoUsuario)"
                        ></div>
                        <span class="text-secundary-default dark:text-mono-blanco">{{
                          infoUserStore.estadoUsuario
                        }}</span>
                      </div>
                    </div>

                    <div class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[16px] text-primary"
                          >phone</span
                        >
                        <p class="text-secundary-default dark:text-mono-blanco">
                          {{ infoUserStore.telefonoCompleto }}
                        </p>
                      </div>
                    </div>

                    <div class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[16px] text-primary"
                          >email</span
                        >
                        <p class="text-secundary-default dark:text-mono-blanco">
                          {{ infoUserStore.email }}
                        </p>
                      </div>
                    </div>

                    <div class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[16px] text-primary"
                          >id_card</span
                        >
                        <p class="text-secundary-default dark:text-mono-blanco">
                          {{
                            infoUserStore.tipoDocumento +
                            ": " +
                            infoUserStore.numero_documento
                          }}
                        </p>
                      </div>
                    </div>

                    <div class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[16px] text-primary"
                          >location_chip</span
                        >
                        <p class="text-secundary-default dark:text-mono-blanco">
                          {{
                            infoUserStore.direccionResidencia +
                            ", " +
                            infoUserStore.barrioResidencia +
                            " " +
                            infoUserStore.ciudadResidencia
                          }}
                        </p>
                      </div>
                    </div>
                  </div>
                  <div class="datos-recurentes flex justify-between mt-3 gap-2">
                    <div class="dias-restante w-auto rounded-md">
                      <h4 class="text-secundary-default dark:text-mono-blanco">
                        Tu membresía finaliza en:
                      </h4>
                      <p
                        class="font-semibold text-[25px] -mt-1 text-secundary-default dark:text-mono-blanco"
                      >
                        {{ diasRestantes
                        }}<span
                          class="text-[14px] text-secundary-default dark:text-mono-blanco"
                        >
                          Días</span
                        >
                      </p>
                    </div>
                    <div class="dias-activo w-auto rounded-md">
                      <h4 class="text-right text-secundary-default dark:text-mono-blanco">
                        Te uniste a la familia:
                      </h4>
                      <p
                        class="font-semibold -mt-1 text-secundary-default text-[25px] dark:text-mono-blanco"
                      >
                        {{ tiempoActivo }}
                      </p>
                    </div>
                  </div>
                </div>
                <div
                  class="rounded-[15px] p-3 div3 border border-mono-negro_opacity_medio dark:border-mono-blanco_opacity"
                >
                  <div class="flex justify-between items-center">
                    <h4 class="text-secundary-default dark:text-mono-blanco">
                      Membresia del usuario:
                    </h4>
                    <span
                      class="material-symbols-rounded text-[16px] text-primary cursor-pointer text-secundary-default dark:text-mono-blanco dark:hover:text-universal-azul_secundaria"
                      >info</span
                    >
                  </div>

                  <div class="px-3 flex justify-between items-center">
                    <div class="">
                      <p
                        class="text-[40px] font-medium text-secundary-default dark:text-mono-blanco"
                      >
                        {{ infoUserStore.membresia }}
                      </p>
                      <p
                        class="-mt-3 font-light text-secundary-default dark:text-mono-blanco"
                      >
                        {{ infoUserStore.descripcionMembresia }}
                      </p>
                    </div>
                  </div>
                </div>
                <div
                  class="rounded-[15px] div4 flex justify-between items-center dark:bg-secundary-opacity bg-mono-blanco_opacity p-5"
                >
                  <h4
                    class="text-[60px] font-medium text-secundary-default dark:text-mono-blanco"
                  >
                    Información del usuario
                  </h4>

                  <div class="btn-initon flex justify-center gap-2 mt-5 p-2">
                    <button
                      @click="eliminarUsuario"
                      class="border border-semaforo-rojo_opacity text-semaforo-rojo_opacity rounded-md p-1 gap-1 w-[100%] flex items-center justify-center hover:text-semaforo-rojo hover:border-semaforo-rojo"
                    >
                      <span class="material-symbols-rounded text-[18px]">delete</span>
                      <span class="">Eliminar Perfil</span>
                    </button>
                  </div>
                </div>
                <div
                  class="rounded-[15px] p-3 div5 dark:bg-secundary-opacity bg-mono-blanco_opacity"
                >
                  <div class="w-full h-[130px] rounded-md opacity-50 bg-primary"></div>

                  <div class="flex gap-2 justify-center items-center">
                    <div
                      class="-mt-20 ml-5 mb-2 grid place-content-center foto w-[180px] h-[180px] rounded-[18px] bg-mono-blanco_opacity dark:bg-secundary-opacity backdrop-blur-lg"
                    >
                      <template v-if="infoUserStore.fotoUrlCompletaEstablecimiento">
                        <div class="relative group w-[160px] h-[160px]">
                          <img
                            :src="infoUserStore.fotoUrlCompletaEstablecimiento"
                            class="rounded-[18px] w-full h-full object-cover shadow-lg"
                          />
                        </div>
                      </template>

                      <template v-else>
                        <div
                          class="relative flex justify-center rounded-[18px] items-center group w-[160px] h-[160px] bg-primary shadow-[0px_8px_20px] shadow-primary"
                        >
                          <p class="text-[60px] font-semibold">
                            {{ inicialesNombreEstablecimiento }}
                          </p>
                        </div>
                      </template>
                    </div>
                  </div>
                  <div class="flex justify-between items-center">
                    <h4 class="text-secundary-default dark:text-mono-blanco">
                      Tienda vinculada
                    </h4>
                    <span
                      class="material-symbols-rounded text-[16px] text-primary cursor-pointer text-secundary-default dark:text-mono-blanco dark:hover:text-universal-azul_secundaria"
                      >info</span
                    >
                  </div>

                  <div class="flex items-center justify-between gap-1">
                    <span class="material-symbols-rounded text-[20px] text-primary"
                      >store</span
                    >
                    <h3
                      class="text-mono-negro font-semibold text-[35px] dark:text-mono-blanco"
                    >
                      {{ infoUserStore.nombreEstablecimiento }}
                    </h3>
                    <div
                      class="grid place-items-center"
                      v-if="infoUserStore.google_id === null"
                    >
                      <span class="material-symbols-rounded text-[20px] text-gray-700"
                        >verified_off</span
                      >
                    </div>
                    <div class="text-secundary-default dark:text-mono-blanco" v-else>
                      <span
                        class="grid place-items-center material-symbols-rounded text-[18px] text-universal-azul_secundaria"
                        >verified</span
                      >
                    </div>
                  </div>
                  <div class="number flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                      <span class="material-symbols-rounded text-[16px] text-primary"
                        >key</span
                      >
                      <p class="text-[14px] text-secundary-default dark:text-mono-blanco">
                        {{ infoUserStore.tokenEstablecimiento }}
                      </p>
                    </div>

                    <div class="flex items-center gap-1">
                      <div
                        class="w-3 h-3 rounded-full"
                        :class="getEstadoClass(infoUserStore.estadoTienda)"
                      ></div>
                      <span class="text-secundary-default dark:text-mono-blanco">{{
                        infoUserStore.estadoTienda
                      }}</span>
                    </div>
                  </div>

                  <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                      <span class="material-symbols-rounded text-[16px] text-primary"
                        >phone</span
                      >
                      <p class="text-secundary-default dark:text-mono-blanco">
                        {{ infoUserStore.telefonoEstablecimiento }}
                      </p>
                    </div>
                  </div>

                  <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                      <span class="material-symbols-rounded text-[16px] text-primary"
                        >email</span
                      >
                      <p class="text-secundary-default dark:text-mono-blanco">
                        {{ infoUserStore.emailEstablecimiento }}
                      </p>
                    </div>
                  </div>

                  <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                      <span class="material-symbols-rounded text-[16px] text-primary"
                        >category</span
                      >
                      <p class="text-secundary-default dark:text-mono-blanco">
                        {{ infoUserStore.tipoEstablecimiento }}
                      </p>
                    </div>
                  </div>

                  <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                      <span class="material-symbols-rounded text-[16px] text-primary"
                        >location_chip</span
                      >
                      <p class="text-secundary-default dark:text-mono-blanco">
                        {{
                          infoUserStore.direccionEstablecimiento +
                          ", " +
                          infoUserStore.ciudadEstablecimiento +
                          " " +
                          infoUserStore.barrioEstablecimiento
                        }}
                      </p>
                    </div>
                  </div>
                </div>
                <div
                  class="rounded-[15px] p-3 div6 flex flex-col justify-center dark:bg-secundary-opacity bg-mono-blanco_opacity"
                >
                  <div class="infoBasicaEmpleado">
                    <div class="flex justify-between items-center">
                      <h4 class="text-secundary-default dark:text-mono-blanco">
                        Información de empleado
                      </h4>
                      <span
                        class="material-symbols-rounded text-[16px] text-primary cursor-pointer text-secundary-default dark:text-mono-blanco dark:hover:text-universal-azul_secundaria"
                        >info</span
                      >
                    </div>
                    <div class="flex items-center justify-between gap-1">
                      <h3
                        class="text-mono-negro font-semibold text-[30px] dark:text-mono-blanco"
                      >
                        {{ infoUserStore.cargoEmpleado }}
                      </h3>
                    </div>
                    <div class="number flex items-center justify-between gap-2">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[16px] text-primary"
                          >key</span
                        >
                        <p
                          class="text-[14px] text-secundary-default dark:text-mono-blanco"
                        >
                          {{ infoUserStore.establecimientoAsignado?.id }}
                        </p>
                      </div>

                      <div class="flex items-center gap-1">
                        <div
                          class="w-3 h-3 rounded-full"
                          :class="getEstadoClass(infoUserStore.estadoEmpleado)"
                        ></div>
                        <span class="text-secundary-default dark:text-mono-blanco">{{
                          infoUserStore.estadoEmpleado
                        }}</span>
                      </div>
                    </div>

                    <div class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[16px] text-primary"
                          >event</span
                        >
                        <p class="text-secundary-default dark:text-mono-blanco">
                          {{ infoUserStore.horariosEmpleado }}
                        </p>
                      </div>
                    </div>

                    <div class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[16px] text-primary"
                          >article_person</span
                        >
                        <p class="text-secundary-default dark:text-mono-blanco">
                          Contrato: {{ infoUserStore.tipoContratoEmpleado }}
                        </p>
                      </div>
                    </div>

                    <div class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[16px] text-primary"
                          >attach_money</span
                        >
                        <p class="text-secundary-default dark:text-mono-blanco">
                          Salario: {{ formatCOP(infoUserStore.salarioBaseEmpleado) }}
                        </p>
                      </div>
                    </div>

                    <div class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[16px] text-primary"
                          >tag</span
                        >
                        <p class="text-secundary-default dark:text-mono-blanco">
                          Cuenta de ahorros: 0{{ infoUserStore.cuentaAhorrosEmpleado }}
                        </p>
                      </div>
                    </div>

                    <div class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[16px] text-primary"
                          >account_balance</span
                        >
                        <p class="text-secundary-default dark:text-mono-blanco">
                          Banco: {{ infoUserStore.bancoEmpleado }}
                        </p>
                      </div>
                    </div>
                    <div class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[16px] text-primary"
                          >account_balance_wallet</span
                        >
                        <p class="text-secundary-default dark:text-mono-blanco">
                          Medio de pago: {{ infoUserStore.medioPagoEmpleado }}
                        </p>
                      </div>
                    </div>

                    <div class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[16px] text-primary"
                          >event</span
                        >
                        <p class="text-secundary-default dark:text-mono-blanco">
                          Ingresó: {{ formatFecha(infoUserStore.fechaIngresoEmpleado) }}
                        </p>
                      </div>
                    </div>

                    <div class="flex items-center justify-between">
                      <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-[16px] text-primary"
                          >event</span
                        >
                        <p class="text-secundary-default dark:text-mono-blanco">
                          Actualizado:
                          {{ formatFecha(infoUserStore.fechaModificacionEmpleado) }}
                        </p>
                      </div>
                    </div>
                  </div>
                </div>
                <div
                  class="rounded-[15px] p-3 div7 dark:bg-secundary-opacity bg-mono-blanco_opacity"
                >
                  <div class="flex justify-between items-center">
                    <h4 class="text-secundary-default dark:text-mono-blanco">
                      Estas disfrutando de tu app
                    </h4>
                    <span
                      class="material-symbols-rounded text-[16px] text-primary cursor-pointer text-secundary-default dark:text-mono-blanco dark:hover:text-universal-azul_secundaria"
                      >info</span
                    >
                  </div>
                  <div class="px-3 flex justify-between items-center">
                    <div class="">
                      <p
                        class="text-[40px] font-medium text-secundary-default dark:text-mono-blanco"
                      >
                        {{ infoUserStore.aplicacion }}
                      </p>
                      <p
                        class="-mt-3 font-light text-secundary-default dark:text-mono-blanco"
                      >
                        {{ infoUserStore.descripcionApp }}
                      </p>
                    </div>
                    <p
                      class="flex items-center gap-1 text-secundary-default dark:text-mono-blanco"
                    >
                      <span class="material-symbols-rounded text-primary"
                        >app_badging</span
                      >{{ infoUserStore.categoriaApp }}
                    </p>
                    <p
                      class="flex items-center gap-1 text-secundary-default dark:text-mono-blanco"
                    >
                      <span class="material-symbols-rounded text-primary"
                        >calendar_clock</span
                      >{{ infoUserStore.periodoMembresia }}
                    </p>
                  </div>
                </div>
                <div
                  class="rounded-[15px] p-4 div8 dark:bg-secundary-opacity bg-mono-blanco_opacity"
                >
                  <div class="flex justify-between items-center">
                    <h4
                      class="text-secundary-default dark:text-mono-blanco font-semibold"
                    >
                      Facturación de la Cuenta
                    </h4>
                    <span class="material-symbols-rounded text-[16px] text-gray-500"
                      >info</span
                    >
                  </div>

                  <!-- Próximo Cobro -->
                  <div class="mt-4">
                    <h5 class="text-sm font-medium text-gray-400 mb-2">Próximo Cobro</h5>
                    <div
                      v-if="proximaFactura"
                      class="w-full p-3 rounded-md bg-gray-800/50 outline-dashed outline-1 outline-gray-600"
                    >
                      <div class="flex justify-between items-center">
                        <p class="font-semibold text-gray-200">
                          {{ formatFecha(proximaFactura.fecha_pago) }}
                        </p>
                        <span
                          class="text-xs font-bold px-2 py-1 rounded-full"
                          :class="getEstadoClass(proximaFactura.estado.tipo_estado)"
                        >
                          {{ proximaFactura.estado.tipo_estado }}
                        </span>
                      </div>
                      <div class="flex justify-between items-center mt-1">
                        <p class="flex items-center gap-1 text-sm text-gray-400">
                          <span class="material-symbols-rounded text-[16px] text-primary"
                            >credit_card</span
                          >
                          {{ proximaFactura.medio_pago.forma_pago }}
                        </p>
                        <p class="font-semibold text-lg text-white">
                          {{ formatCOP(proximaFactura.monto_total) }}
                        </p>
                      </div>
                    </div>
                    <div v-else class="text-center py-4 text-gray-500 text-sm">
                      No hay información de próximo cobro.
                    </div>
                  </div>

                  <!-- Historial de Facturas -->
                  <div class="mt-6">
                    <h5 class="text-sm font-medium text-gray-400 mb-2">
                      Historial de Pagos
                    </h5>
                    <div
                      v-if="historialFacturas.length > 0"
                      class="space-y-2 max-h-48 overflow-y-auto pr-2"
                    >
                      <div
                        v-for="factura in historialFacturas"
                        :key="factura.id"
                        class="w-full p-2 rounded-md bg-gray-800/50"
                      >
                        <div class="flex justify-between items-center">
                          <p class="text-sm text-gray-300">
                            {{ formatFecha(factura.fecha_pago) }}
                          </p>
                          <span
                            class="text-xs font-bold px-2 py-1 rounded-full"
                            :class="getEstadoClass(factura.estado.tipo_estado)"
                          >
                            {{ factura.estado.tipo_estado }}
                          </span>
                        </div>
                        <div class="flex justify-between items-center mt-1">
                          <p class="flex items-center gap-1 text-xs text-gray-500">
                            <span class="material-symbols-rounded text-[14px]"
                              >credit_card</span
                            >
                            {{ factura.medio_pago.forma_pago }}
                          </p>
                          <p class="font-medium text-gray-200">
                            {{ formatCOP(factura.monto_total) }}
                          </p>
                        </div>
                      </div>
                    </div>
                    <div v-else class="text-center py-4 text-gray-500 text-sm">
                      No hay historial de facturas.
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div v-if="activeTab === 1" class="p-5">
            <Transition name="modal-slide" appear>
              <form
                @submit.prevent="submit"
                class="relative bg-mono-negro rounded-xl shadow-lg w-full border border-secundary-light text-gray-300 flex flex-col p-5"
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

                <!-- Cuerpo del Formulario con Transición -->
                <div class="overflow-hidden">
                  <Transition name="slide-fade" mode="out-in">
                    <div :key="currentStep" class="space-y-4">
                      <!-- PASO 1: DATOS PERSONALES -->
                      <div v-if="currentStep === 1">
                        <h4 class="font-semibold text-[35px] text-mono-blanco">
                          Editar datos del usuario: {{ infoUserStore.nombreCompleto }}
                        </h4>
                        <p class="text-[16px] text-secundary-light -mt-2 mb-4">
                          Ten en cuenta los cambios respectivos, lo que no cambiarás,
                          déjalo tal cual y continúa.
                        </p>
                        <div class="space-y-4">
                          <div
                            class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
                          >
                            <InputTexto
                              v-model="form.primerNombre"
                              label="Primer nombre:"
                              icon="format_italic"
                              type="text"
                              placeholder="Ej: Juan"
                              :maxLength="limitesCaracteres.nombresUsuario"
                              :error="form.errors.primer_nombre"
                            
                              @input="(e) => handleInput(e, form, 'primer_nombre')"
                            />

                            <InputTexto
                              v-model="form.segundoNombre"
                              label="Segundo nombre:"
                              icon="format_italic"
                              type="text"
                              placeholder="Opcional*"
                              :maxLength="limitesCaracteres.nombresUsuario"
                              :error="form.errors.segundo_nombre"
                             
                              @input="(e) => handleInput(e, form, 'segundo_nombre')"
                            />

                            <InputTexto
                              v-model="form.primerApellido"
                              label="Primer apellido:"
                              icon="format_italic"
                              type="text"
                              placeholder="Ej: Medina"
                              :maxLength="limitesCaracteres.nombresUsuario"
                              :error="form.errors.primer_apellido"
                             
                              @input="(e) => handleInput(e, form, 'primer_apellido')"
                            />

                            <InputTexto
                              v-model="form.segundoApellido"
                              label="Segundo apellido:"
                              icon="format_italic"
                              type="text"
                              placeholder="Opcional*"
                              :maxLength="limitesCaracteres.nombresUsuario"
                              :error="form.errors.segundo_apellido"
                             
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
                              v-model="form.email"
                              label="Correo electrónico:"
                              icon="email"
                              type="email"
                              placeholder="example@dominio.com"
                              :maxLength="limitesCaracteres.email"
                              :error="form.errors.email"
                             
                              @input="(e) => handleInput(e, form, 'email')"
                            />
                          </div>

                          <div
                            class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
                          >
                            <div class="w-full flex items-center gap-2">
                              <InputTexto
                                v-model="form.ciudadResidencia"
                                label="Ciudad residencia:"
                                icon="map"
                                type="text"
                                placeholder="Bogotá D.C."
                                :maxLength="limitesCaracteres.direccion"
                                :error="form.errors.ciudad_residencia"
                             
                                @input="(e) => handleInput(e, form, 'ciudad_residencia')"
                              />

                              <InputTexto
                                v-model="form.barrioResidencia"
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
                              v-model="form.direccionResidencia"
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
                                v-model="form.tipoDocumento_id"
                                :options="props.tipoDocumentoDisponibles"
                                :error="form.errors.tipo_documento_id"
                                label="Tipo documento:"
                                placeholder="Selecciona el tipo"
                                id="tipo_documento_id"
                              />

                              <div class="w-full">
                                <div
                                  class="contador-label flex items-center justify-between"
                                >
                                  <label
                                    class="my-[5px] text-[14px] dark:text-mono-blanco"
                                    >Documento de identidad:</label
                                  >
                                </div>

                                <div
                                  class="w-full p-[3px] flex items-center gap-[8px] transition-all rounded-[5px] border-secundary-light border-[1px]"
                                >
                                  <span
                                    class="material-symbols-rounded text-primary text-[20px] pl-[5px]"
                                    >key</span
                                  >
                                  <p>{{ infoUserStore.numero_documento }}</p>
                                </div>

                                <span v-if="error" class="text-sm text-universal-naranja">
                                  {{ error }}
                                </span>
                              </div>
                            </div>
                          </div>
                          <div
                            class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
                          >
                            <Selects
                              v-model="form.genero"
                              :options="props.generosDisponibles"
                              :error="form.errors.genero"
                              label="Género:"
                              placeholder="Selecciona tu genero"
                              id="genero"
                            />
                            <Selects
                              v-model="form.estadoUsuario_id"
                              :options="props.estadosDisponibles"
                              :error="form.errors.estadoCliente_id"
                              label="Estado usuario:"
                              placeholder="Selecciona el tipo"
                              id="estado_id"
                            />
                          </div>
                        </div>
                      </div>

                      <!-- PASO 2: TIPO DE USUARIO Y DATOS DE EMPLEADO -->
                      <div v-if="currentStep === 2">
                        <h4 class="font-semibold text-[35px] text-mono-blanco">
                          Editar datos de la tienda:
                          {{ infoUserStore.nombreEstablecimiento }}
                        </h4>
                        <p class="text-[16px] text-secundary-light -mt-2 mb-4">
                          Ten en cuenta los cambios respectivos, lo que no cambiarás,
                          déjalo tal cual y continúa.
                        </p>
                        <div class="space-y-4">
                          <div
                            class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
                          >
                            <InputTexto
                              v-model="form.nombreEstablecimiento"
                              label="Nombre de tienda:"
                              icon="format_italic"
                              type="text"
                              placeholder="CC Smit"
                              :maxLength="limitesCaracteres.nombresUsuario"
                              :error="form.errors.nombre_establecimiento"
                              
                            />

                            <InputTexto
                              v-model="form.tipoEstablecimiento"
                              label="Categoria del establecimiento:"
                              icon="format_italic"
                              type="text"
                              placeholder="TI"
                              :maxLength="limitesCaracteres.tipo_establecimiento"
                              :error="form.errors.tipo_establecimiento"
                             
                            />
                          </div>

                          <div
                            class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
                          >
                            <div class="flex gap-2 items-center w-full">
                              <InputTexto
                                v-model="form.telefonoEstablecimiento"
                                label="Número Corporativo:"
                                icon="phone"
                                type="number"
                                placeholder="316511****"
                                :maxLength="limitesCaracteres.telefono"
                                :error="form.errors.telefono_establecimiento"
                               
                                @input="(e) => handleInput(e, form, 'telefono')"
                              />
                            </div>

                            <InputTexto
                              v-model="form.emailEstablecimiento"
                              label="Correo electrónico:"
                              icon="email"
                              type="email"
                              placeholder="example@dominio.com"
                              :maxLength="limitesCaracteres.nombresUsuario"
                              :error="form.errors.email"
                           
                            />
                          </div>

                          <div
                            class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
                          >
                            <div class="w-full flex items-center gap-2">
                              <InputTexto
                                v-model="form.ciudadEstablecimiento"
                                label="Ciudad establecimiento:"
                                icon="map"
                                type="text"
                                placeholder="Bogotá D.C."
                                :maxLength="limitesCaracteres.direccion"
                                :error="form.errors.ciudad_establecimiento"
                               
                                @input="
                                  (e) => handleInput(e, form, 'ciudad_establecimiento')
                                "
                              />

                              <InputTexto
                                v-model="form.barrioEstablecimiento"
                                label="Barrio establecimiento:"
                                icon="distance"
                                type="text"
                                placeholder="Luna Park"
                                :maxLength="limitesCaracteres.direccion"
                                :error="form.errors.barrio_establecimiento"
                            
                                @input="
                                  (e) => handleInput(e, form, 'barrio_establecimiento')
                                "
                              />
                            </div>

                            <InputTexto
                              v-model="form.direccionEstablecimiento"
                              label="Dirección establecimiento:"
                              icon="format_italic"
                              type="text"
                              placeholder="Calle 1a #2b-3 sur"
                              :maxLength="limitesCaracteres.direccion"
                              :error="form.errors.direccion_establecimiento"
                            
                            />
                          </div>
                          <div
                            class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
                          >
                            <div class="flex gap-2 items-center w-full">
                              <Selects
                                v-model="form.aplicacion_id"
                                :options="props.appsDisponibles"
                                :error="form.errors.aplicacion_id"
                                label="Aplicación asignada:"
                                placeholder="Selecciona una app"
                                id="aplicacion_id"
                              />
                              <Selects
                                v-model="form.estadoIdEstablecimiento"
                                :options="props.estadosDisponibles"
                                :error="form.errors.estadosDisponibles"
                                label="Estado tienda:"
                                placeholder="Cambia el estado"
                                id="estado"
                              />
                            </div>
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
                          </div>
                        </div>
                      </div>

                      <!-- PASO 3: pagos y estado de factura -->
                      <div v-if="currentStep === 3">
                        <h4 class="font-semibold text-[35px] text-mono-blanco">
                          Accesos a la app:
                          {{ infoUserStore.aplicacion }}
                        </h4>
                        <p class="text-[16px] text-secundary-light -mt-2 mb-4">
                          Ten en cuenta los cambios respectivos, lo que no cambiarás,
                          déjalo tal cual y continúa.
                        </p>
                        <div class="space-y-4">
                          <div
                            class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
                          >
                            <div class="w-full">
                              <div
                                class="contador-label flex items-center justify-between"
                              >
                                <label class="my-[5px] text-[14px] dark:text-mono-blanco"
                                  >Token asignado:</label
                                >
                              </div>

                              <div
                                class="w-full p-[3px] flex items-center gap-[8px] transition-all rounded-[5px] border-secundary-light border-[1px]"
                              >
                                <span
                                  class="material-symbols-rounded text-primary text-[20px] pl-[5px]"
                                  >key</span
                                >
                                <p>{{ infoUserStore.tokenEstablecimiento }}</p>
                              </div>

                              <span v-if="error" class="text-sm text-universal-naranja">
                                {{ error }}
                              </span>
                            </div>

                            <Selects
                              v-model="form.estadoToken_id"
                              :options="props.estadosDisponibles"
                              :error="form.errors.estado_token_id"
                              label="Estado del token:"
                              placeholder="Selecciona una app"
                              id="estado_token_id"
                            />
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
                  >
                    Siguiente
                  </BtnPrimario>

                  <BtnPrimario
                    class="w-[50%]"
                    v-else
                    type="submit"
                    :disabled="form.processing"
                    >Finalizar</BtnPrimario
                  >
                </div>
              </form>
            </Transition>
          </div>
        </div>
      </Transition>
    </div>
  </Transition>
  <ConfirmacionesPop
    :is-open="confirmationState.isOpen"
    :title="confirmationState.title"
    :message="confirmationState.message"
    :icon="confirmationState.icon"
    :icon-bg-class="confirmationState.iconBgClass"
    :confirm-text="confirmationState.confirmText"
    @close="closeConfirmationModal"
    @confirm="handleConfirm"
  />
</template>

<style scoped>
.input-field {
  margin-top: 0.25rem;
  display: block;
  width: 100%;
  background-color: #1f2937;
  /* bg-gray-800 */
  border: 1px solid #374151;
  /* border-gray-700 */
  border-radius: 0.375rem;
  /* rounded-md */
  box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
  /* shadow-sm */
  color: #fff;
  /* text-white */
}

.input-field:focus {
  outline: none;
  border-color: #2563eb;
  /* focus:border-primary (blue-600) */
  box-shadow: 0 0 0 2px #2563eb33;
  /* focus:ring-primary */
}

.error-message {
  color: #ef4444;
  /* Tailwind text-red-500 */
  font-size: 0.75rem;
  /* Tailwind text-xs */
  margin-top: 0.25rem;
  /* Tailwind mt-1 */
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
