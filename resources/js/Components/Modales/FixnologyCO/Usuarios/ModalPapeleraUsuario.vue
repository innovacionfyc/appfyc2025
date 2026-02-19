<script setup>
import { defineProps, defineEmits, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { formatFecha } from "@/Utils/date";
import {
  getFotoEstablecimientoUrlCompleta,
  getFotoUrlCompleta,
} from "@/Utils/ImagenUsuarios";
import {
  getInicialesEstablecimiento,
  getInicialesUsuario,
} from "@/Utils/inicialesNombres";
import { formatCOP } from "@/Utils/formateoMoneda";
import useEstadoClass from "@/Composables/useEstado";
import ConfirmacionesPop from "../../Confirmaciones/ConfirmacionesPop.vue";
const { getEstadoClass } = useEstadoClass();

const props = defineProps({
  isOpen: {
    type: Boolean,
    required: true,
  },
  usuariosEnPapelera: {
    type: Array,
    default: () => [],
  },
});

defineEmits(["close"]);

import { useConfirmationModal } from "@/Composables/useConfirmationModal";
const {
  confirmationState,
  openConfirmationModal,
  closeConfirmationModal,
  handleConfirm,
} = useConfirmationModal();

// --- 3. FUNCIONES DE ACCIÓN ACTUALIZADAS ---
function restaurarUsuario(usuario) {
  openConfirmationModal({
    title: "¿Restaurar Usuario?",
    message: `Estás a punto de restaurar a ${usuario.perfil_usuario.primer_nombre}. ¿Continuar?`,
    icon: "restore_from_trash",
    iconBgClass: "bg-green-500/20 border-semaforo-verde",
    confirmText: "Sí, restaurar",
    onConfirm: () => {
      router.post(route("usuarios.restore", { id: usuario.id }), {
        preserveScroll: true,
        onSuccess: () => {
          emit("close");
        },
      });
    },
  });
}

function eliminarPermanentemente(usuario) {
  openConfirmationModal({
    title: "¿Eliminar Permanentemente?",
    message: `¡Advertencia! Esta acción es irreversible y eliminará todos los datos de ${usuario.perfil_usuario.primer_nombre}.`,
    icon: "delete_forever",
    iconBgClass: "bg-red-500/20 border-semaforo-rojo",
    confirmText: "Sí, eliminar",
    onConfirm: () => {
      router.delete(route("usuarios.forceDestroy", { id: usuario.id }), {
        preserveScroll: true,
        onSuccess: () => {
          emit("close");
        },
      });
    },
  });
}

function vaciarPapelera() {
  openConfirmationModal({
    title: "¿Vaciar toda la Papelera?",
    message:
      "Esta acción eliminará permanentemente a todos los usuarios en la papelera y no se puede deshacer.",
    icon: "delete_sweep",
    iconBgClass: "bg-red-500/20 border-semaforo-rojo",
    confirmText: "Sí, vaciar todo",
    onConfirm: () => {
      router.post(
        route("usuarios.emptyTrash"),

        {
          onSuccess: () => {
            emit("close");
          },
          preserveScroll: true,
        }
      );
    },
  });
}
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
        <div
          class="relative bg-mono-negro rounded-lg h-auto max-h-[80%] w-full max-w-[80%] border border-secundary-light text-mono-blanco p-5 overflow-auto"
        >
          <div class="max-h-[70vh] overflow-y-auto">
            <div v-if="usuariosEnPapelera.length > 0">
              <h4 class="font-semibold text-[35px] text-mono-blanco">
                Papelera de usuarios
              </h4>
              <div class="flex justify-between items-center">
                <p class="text-[16px] text-secundary-light -mt-2 mb-4">
                  Aqui aparecerán todos los usuarios que eliminaste.
                </p>
                <div class="flex gap-2 items-center">
                  <p
                    class="text-[14px] border border-secundary-light px-3 rounded-md text-secundary-light"
                  >
                    <span class="text-[20px] font-medium">{{
                      usuariosEnPapelera.length
                    }}</span>
                    Total
                  </p>
                  <button
                    @click="vaciarPapelera"
                    class="flex items-center justify-center hover:-translate-y-1 text-[14px] border border-semaforo-rojo p-1 rounded-md text-semaforo-rojo"
                  >
                    <span class="material-symbols-rounded">delete_sweep</span>
                  </button>
                </div>
              </div>

              <table class="w-full text-left text-mono-blanco">
                <thead class="text-xs uppercase">
                  <tr>
                    <th scope="col" class="p-4 font-semibold">Usuario</th>

                    <th scope="col" class="font-semibold">Teléfono</th>
                    <th scope="col" class="font-semibold">Locación</th>
                    <th scope="col" class="font-semibold">Paquete</th>

                    <th scope="col" class="font-semibold">Estado Factura</th>
                    <th scope="col" class="font-semibold">Fecha eliminación</th>
                    <th scope="col" class="font-semibold">Acciones</th>
                  </tr>
                </thead>

                <TransitionGroup tag="tbody" name="list">
                  <tr
                    v-for="usuario in usuariosEnPapelera"
                    :key="usuario.id"
                    class="group hover:bg-mono-blanco_opacity"
                  >
                    <td class="flex items-center w-full py-1">
                      <div
                        class="mr-2 ml-4 my-1 grid place-content-center foto w-[50px] h-[50px] rounded-[10px] bg-mono-blanco_opacity dark:bg-secundary-opacity backdrop-blur-lg"
                      >
                        <template v-if="getFotoUrlCompleta(usuario)">
                          <div class="relative group w-[50px] h-[50px]">
                            <img
                              :src="getFotoUrlCompleta(usuario)"
                              class="rounded-[10px] border-2 w-full h-full object-cover shadow-lg"
                            />
                          </div>
                        </template>

                        <template v-else>
                          <div class="relative group w-[50px] h-[50px]">
                            <p
                              class="h-full w-full flex justify-center items-center text-[20px] font-semibold border-2 rounded-[10px]"
                            >
                              {{ getInicialesUsuario(usuario) }}
                            </p>
                          </div>
                        </template>
                      </div>
                      <div class="detalles">
                        <div class="nombre flex gap-1 items-center">
                          <h2 class="text-[18px] font-medium">
                            {{ usuario.perfil_usuario.primer_nombre }}
                            {{ usuario.perfil_usuario.segundo_nombre }}
                            {{ usuario.perfil_usuario.primer_apellido }}
                            {{ usuario.perfil_usuario.segundo_apellido }}
                          </h2>
                          <div
                            class="grid place-items-center"
                            v-if="usuario.google_id === null"
                          >
                            <span
                              class="material-symbols-rounded text-[18px] text-gray-700"
                              >verified_off</span
                            >
                          </div>
                          <div
                            class="grid place-items-center text-secundary-default dark:text-mono-blanco"
                            v-else
                          >
                            <span
                              class="material-symbols-rounded text-[18px] text-secondary"
                              >verified</span
                            >
                          </div>
                        </div>
                        <div class="flex -mt-2 justify-between items-end">
                          <p class="text-secundary-light text-[14px]">
                            {{ usuario.establecimiento_asignado.nombre_establecimiento }}
                          </p>
                        </div>
                      </div>
                    </td>

                    <td>
                      <p class="text-secundary-light text-[14px]">
                        ({{ usuario.perfil_usuario.indicativo.codigo_pais }})
                        {{ usuario.perfil_usuario.telefono }}
                      </p>
                    </td>

                    <td>
                      <p class="text-secundary-light text-[14px]">
                        {{ usuario.perfil_usuario.barrio_residencia }},
                        {{ usuario.perfil_usuario.ciudad_residencia }}
                      </p>
                    </td>

                    <td>
                      <p class="text-secundary-light text-[14px]">
                        {{ usuario.establecimiento_asignado.aplicacion_web.nombre_app }} -
                        {{
                          usuario.establecimiento_asignado.aplicacion_web.membresia
                            .nombre_membresia
                        }}
                      </p>
                    </td>

                    <td class="text-center pr-4">
                      <div
                        v-if="
                          usuario.establecimiento?.facturas &&
                          usuario.establecimiento?.facturas.length > 0
                        "
                      >
                        <p
                          class="p-1 w-full rounded-md text-[14px] font-bold"
                          :class="
                            getEstadoClass(
                              usuario.establecimiento_asignado?.facturas[0].estado
                                .tipo_estado
                            )
                          "
                        >
                          {{
                            usuario.establecimiento_asignado?.facturas[0].estado
                              .tipo_estado
                          }}
                        </p>
                      </div>

                      <div v-else>
                        <div v-if="usuario" class="text-sm text-gray-400 mt-2">
                          No posee
                        </div>
                        <div v-else class="text-sm text-gray-500">Sin Información :c</div>
                      </div>
                    </td>

                    <td class="text-secundary-light text-[14px]">
                      {{ formatFecha(usuario.deleted_at) }}
                    </td>

                    <td class="flex gap-2">
                      <button
                        @click="restaurarUsuario(usuario)"
                        class="font-medium text-green-500 hover:underline"
                      >
                        Restaurar
                      </button>
                      <button
                        @click="eliminarPermanentemente(usuario)"
                        class="font-medium text-red-500 hover:underline"
                      >
                        Eliminar
                      </button>
                    </td>
                  </tr>
                </TransitionGroup>
              </table>
            </div>

            <div v-else class="text-center py-10 text-secundary-light">
              <span class="material-symbols-rounded text-5xl">recycling</span>
              <p class="mt-2">La papelera está vacía.</p>
              <p>Aqui aparecerán todos los usuarios que eliminaste.</p>
            </div>
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
  border-color: #374151;
  /* border-gray-700 */
  border-radius: 0.375rem;
  /* rounded-md */
  box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
  /* shadow-sm */
  color: #fff;
  /* text-white */
}

.error-message {
  color: #ef4444;
  /* text-red-500 */
  font-size: 0.75rem;
  /* text-xs */
  margin-top: 0.25rem;
  /* mt-1 */
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
