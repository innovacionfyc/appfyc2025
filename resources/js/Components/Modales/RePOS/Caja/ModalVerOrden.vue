<script setup>
import { computed, ref } from "vue";
import Swal from "sweetalert2";
import { usePage, router } from "@inertiajs/vue3";
import BaseModalSteps from "@/Components/Shared/Modales/BaseModalSteps.vue";
import { formatCOP } from "@/Utils/formateoMoneda";
import { getInicialesProducto } from "@/Utils/inicialesNombres";
import { getFotoProductoUrlCompleta } from "@/Utils/ImagenUsuarios";
import axios from "axios";
import BtnSecundario from "@/Components/Shared/buttons/btnSecundario.vue";

const props = defineProps({
  isOpen: { type: Boolean, required: true },
  order: { type: Object, default: null },
});

const emit = defineEmits(["close", "open-finalize"]);
const page = usePage();
const isPrintingPreview = ref(false);

const canFinalize = computed(() => {
  if (!props.order) return false;

  // Lista de estados donde NO se puede finalizar (Pagado, Anulado, etc)
  const estadosFinalizados = ["Pagado", "Anulado", 13, 25];

  return !estadosFinalizados.includes(props.order.estado);
});

function handleSubmit() {
  if (canFinalize.value) {
    emit("open-finalize");
  } else {
    emit("close");
  }
}

// --- UTILIDADES VISUALES ---
const getStatusStyles = (status) => {
  switch (status) {
    case "Pagado":
      return "bg-emerald-100 text-emerald-700 ring-emerald-600/20";
    case "Procesando":
      return "bg-amber-100 text-amber-700 ring-amber-600/20";
    case "Anulado":
    case "Reembolsado":
      return "bg-red-100 text-red-700 ring-red-600/20";
    case "Pendiente de Pago":
      return "bg-blue-100 text-blue-700 ring-blue-600/20";
    default:  
      return "bg-gray-100 text-gray-700 ring-gray-600/20";
  }
};

const getStatusIcon = (status) => {
  switch (status) {
    case "Pagado":
      return "check_circle";
    case "Procesando":
      return "sync";
    case "Anulado":
      return "cancel";
    case "Reembolsado":
      return "cancel";
    case "Pendiente de Pago":
      return "pending";
    default:
      return "info";
  }
};

// --- LÓGICA DE IMPRESIÓN (Tu código original optimizado) ---
const isMobileOrTablet = () => {
  if (navigator.userAgentData && navigator.userAgentData.mobile) return true;
  return /android|iphone|ipad|ipod|blackberry|iemobile|opera mini|tablet|mobi/i.test(
    navigator.userAgent || ""
  );
};

const printPDF = (url) => {
  if (!url) return;
  if (isMobileOrTablet()) {
    window.open(url, "_blank");
  } else {
    const iframe = document.createElement("iframe");
    iframe.style.display = "none";
    iframe.src = url;
    document.body.appendChild(iframe);
    iframe.onload = () => {
      try {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
      } catch (e) {
        window.open(url, "_blank");
      }
      setTimeout(() => document.body.removeChild(iframe), 1000);
    };
  }
};

async function generarPreFactura() {
  if (!props.order) return;
  isPrintingPreview.value = true;
  try {
    const response = await axios.get(
      route("repos.ordenes.prefactura", {
        aplicacion: page.props.aplicacion,
        rol: page.props.rol,
        orden: props.order.id,
      })
    );
    if (response.data.invoice_url) printPDF(response.data.invoice_url);
  } catch (error) {
    console.error("Error pre-factura:", error);
  } finally {
    isPrintingPreview.value = false;
  }
}

const confirmarReversion = () => {
  if (!props.order) return;

  Swal.fire({
    title: "AUTORIZACIÓN REQUERIDA",
    html: `
            <div class="text-left">
                <p class="text-sm text-gray-500 mb-2">Esta acción requiere permisos de Administrador o Propietario.</p>
                <label class="text-xs font-bold text-gray-700">Motivo de anulación</label>
                <input id="swal-motivo" class="swal2-input" placeholder="Ej: Error de digitación" style="margin: 5px 0 15px 0; width: 100%;">
                
                <label class="text-xs font-bold text-gray-700">PIN de Seguridad</label>
                <input id="swal-pin" type="password" class="swal2-input" placeholder="Últimos 3 dígitos del documento" maxlength="3" style="margin: 5px 0 0 0; width: 100%;">
            </div>
        `,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#e11d48",
    confirmButtonText: "Validar y Revertir",
    cancelButtonText: "Cancelar",
    focusConfirm: false,
    preConfirm: () => {
      const motivo = document.getElementById("swal-motivo").value;
      const pin = document.getElementById("swal-pin").value;

      if (!motivo) return Swal.showValidationMessage("El motivo es obligatorio");
      if (!pin) return Swal.showValidationMessage("El PIN es obligatorio");
      if (pin.length < 3)
        return Swal.showValidationMessage("El PIN debe tener 3 dígitos");

      return { motivo, pin };
    },
  }).then((result) => {
    if (result.isConfirmed) {
      router.post(
        route("repos.ordenes.revertir"),
        {
          orden_id: props.order.id,
          motivo: result.value.motivo,
          pin_seguridad: result.value.pin,
        },
        {
          preserveScroll: true,
          onSuccess: () => {
            emit("close");
          },
          onError: (errors) => {
            // Muestra el mensaje de error que viene del controlador (ej: PIN incorrecto)
            const mensaje =
              page.props.flash?.error || Object.values(errors)[0] || "Error desconocido";
            Swal.fire("Error", mensaje, "error");
          },
        }
      );
    }
  });
};
</script>

<template>
  <BaseModalSteps
    :isOpen="isOpen"
    :currentStep="1"
    :totalSteps="1"
    :finalButtonText="canFinalize ? 'Finalizar Orden' : 'Cerrar Detalle'"
    :isSubmitting="isPrintingPreview"
    @close="emit('close')"
    @submit="handleSubmit"
    title="Detalles de la orden"
    description="Aquí podrás observar todos los detalles de la orden gestionada por el mesero."
  >
    <div v-if="order" class="flex flex-col h-full">
      <div class="flex items-center justify-between gap-3">
        <div class="">
          <h2 class="text-3xl font-black text-gray-900 dark:text-white tracking-tight">
            ORDEN #{{ order.consecutivo_completo }}
          </h2>
          <p class="text-sm text-gray-500 mt-1 flex items-center gap-2">
            <span class="material-symbols-rounded text-base">event</span>
            {{ order.fecha }}
          </p>
        </div>
        <div class="flex items-center gap-4">
          <span
            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold ring-1 ring-inset uppercase tracking-wide"
            :class="getStatusStyles(order.estado)"
          >
            <span class="material-symbols-rounded text-sm">{{
              getStatusIcon(order.estado)
            }}</span>
            {{ order.estado }}
          </span>
          <button
            v-if="order.estado === 13 || order.estado === 'Pagado'"
            type="button"
            @click.stop="confirmarReversion"
            class="flex items-center gap-2 px-4 py-2 bg-rose-100 text-rose-700 rounded-lg hover:bg-rose-200 font-bold transition-colors"
          >
            <span class="material-symbols-rounded">history_edu</span>
            Anular / Devolución
          </button>

          <button
            v-if="canFinalize"
            @click="generarPreFactura"
            :disabled="isPrintingPreview"
            class="border-gray-200 dark:border-gray-700"
          >
            <div
              class="flex items-center gap-2 text-gray-700 dark:text-gray-200 hover:text-primary"
            >
              <span
                class="material-symbols-rounded text-lg"
                :class="{ 'animate-spin': isPrintingPreview }"
              >
                {{ isPrintingPreview ? "hourglass_empty" : "print" }}
              </span>
            </div>
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 py-6">
        <div
          class="p-4 rounded-2xl border border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm hover:shadow-md transition-shadow"
        >
          <div
            class="flex items-center gap-2 mb-3 text-gray-400 uppercase text-xs font-bold tracking-wider"
          >
            <span class="material-symbols-rounded text-lg text-primary">person</span>
            Cliente
          </div>
          <p
            class="font-bold text-gray-900 dark:text-white truncate"
            :title="order.cliente_detalle.nombre"
          >
            {{ order.cliente_detalle.nombre }}
          </p>
          <p class="text-sm text-gray-500 font-mono">
            {{ order.cliente_detalle.documento }}
          </p>
          <p class="text-xs text-gray-400 truncate">
            {{ order.cliente_detalle.email || "Sin correo" }}
          </p>
        </div>

        <div
          class="p-4 rounded-2xl border border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm hover:shadow-md transition-shadow"
        >
          <div
            class="flex items-center gap-2 mb-3 text-gray-400 uppercase text-xs font-bold tracking-wider"
          >
            <span class="material-symbols-rounded text-lg text-blue-500">badge</span>
            Atendido Por
          </div>
          <p class="font-bold text-gray-900 dark:text-white">{{ order.empleado }}</p>
          <div
            class="inline-flex mt-1 px-2 py-0.5 rounded text-[10px] bg-blue-50 text-blue-600 font-bold uppercase"
          >
            {{ order.rol_empleado }}
          </div>
        </div>

        <div
          class="p-4 rounded-2xl border border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm hover:shadow-md transition-shadow"
        >
          <div
            class="flex items-center gap-2 mb-3 text-gray-400 uppercase text-xs font-bold tracking-wider"
          >
            <span class="material-symbols-rounded text-lg text-orange-500"
              >room_service</span
            >
            Ubicación
          </div>
          <div class="flex justify-between items-center">
            <div>
              <p class="text-xs text-gray-400">Mesa</p>
              <p class="font-bold text-gray-900 dark:text-white">
                {{ order.mesa_nombre }}
              </p>
            </div>
            <div class="text-right">
              <p class="text-xs text-gray-400">Tipo</p>
              <p class="font-bold text-gray-900 dark:text-white">
                {{ order.tipo_orden }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <div
        class="flex-1 min-h-[200px] flex flex-col border border-gray-200 dark:border-gray-700 rounded-2xl overflow-hidden"
      >
        <div
          class="bg-gray-50 dark:bg-gray-800 px-4 py-2 border-b border-gray-200 dark:border-gray-700 flex justify-between text-xs font-bold text-gray-500 uppercase"
        >
          <span>Detalle de Consumo</span>
          <span>Subtotal</span>
        </div>

        <div
          class="overflow-y-auto max-h-[300px] scrollbar-thin bg-white dark:bg-gray-900 p-2 space-y-2"
        >
          <div
            v-for="item in order.items"
            :key="item.id"
            class="group flex items-center gap-4 p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
          >
            <div
              class="w-12 h-12 rounded-lg flex-shrink-0 overflow-hidden relative border border-gray-100 dark:border-gray-600"
            >
              <img
                v-if="item.producto_foto"
                :src="getFotoProductoUrlCompleta(item.producto_foto)"
                class="w-full h-full object-cover"
              />
              <div
                v-else
                class="w-full h-full bg-gradient-to-br from-primary to-rose-700 flex items-center justify-center text-xs font-black text-mono-blanco"
              >
                {{ getInicialesProducto(item.producto_nombre) }}
              </div>
            </div>

            <div class="flex-grow min-w-0">
              <p
                class="font-extrabold text-gray-900 dark:text-white text-sm leading-tight group-hover:text-primary transition-colors"
              >
                {{ item.producto_nombre }}
              </p>

              <div class="flex flex-wrap items-center gap-2 mt-1">
                <p
                  class="text-[11px] font-medium text-gray-500 dark:text-gray-400 font-mono flex items-center gap-1"
                >
                  <span
                    class="bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 rounded text-gray-700 dark:text-gray-200 font-black"
                  >
                    x{{ +item.cantidad }}
                  </span>
                  <span>{{ formatCOP(item.precio_unitario) }}</span>
                </p>

                <div
                  v-if="item.porcentaje_aplicado > 0"
                  class="flex items-center gap-1 bg-rose-50 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400 px-1.5 py-0.5 rounded-md border border-rose-100 dark:border-rose-800/50"
                >
                  <span class="material-symbols-rounded text-[12px]">sell</span>
                  <span class="text-[9px] font-black uppercase tracking-tighter">
                    -{{ Math.round(item.porcentaje_aplicado) }}% OFF
                  </span>
                </div>
              </div>

              <div
                v-if="item.notas_item"
                class="text-[10px] text-blue-600 dark:text-blue-400 italic mt-2 flex items-center gap-1.5 bg-blue-50/50 dark:bg-blue-900/20 px-2 py-1 rounded-lg w-fit border border-blue-100/50 dark:border-blue-800/30"
              >
                <span class="material-symbols-rounded text-[14px]">chat_bubble</span>
                <span class="leading-none">{{ item.notas_item }}</span>
              </div>
            </div>

            <div class="text-right">
              <p class="font-mono font-bold text-gray-900 dark:text-white">
                {{ formatCOP(item.cantidad * item.precio_unitario) }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <div
        class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6 pt-4 border-t border-gray-100 dark:border-gray-800"
      >
        <div class="space-y-3 text-sm">
          <h4 class="font-bold text-gray-900 dark:text-white flex items-center gap-2">
            <span class="material-symbols-rounded text-primary">receipt_long</span> Datos
            de Facturación
          </h4>
          <div
            class="bg-gray-50 dark:bg-gray-800/50 p-3 rounded-xl space-y-2 border border-gray-100 dark:border-gray-700"
          >
            <div class="flex justify-between">
              <span class="text-gray-500">Estado:</span>
              <span class="font-medium text-gray-700 dark:text-gray-300">{{
                order.factura_info.estado_factura
              }}</span>
            </div>
            <div
              class="flex justify-between items-start py-2 border-b border-gray-50 last:border-0"
            >
              <span class="text-sm font-medium text-gray-500 uppercase tracking-wider"
                >Pago</span
              >

              <div class="flex flex-col items-end gap-2">
                <div class="flex flex-wrap justify-end gap-1.5">
                  <div
                    v-for="(metodo, index) in order.factura_info.metodo_pago"
                    :key="index"
                    class="flex items-center gap-2 bg-gray-50 dark:bg-white/[0.03] px-3 py-1 rounded-xl border border-gray-100 dark:border-white/10"
                  >
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    <span
                      class="text-[11px] font-black text-gray-700 dark:text-gray-300 uppercase tracking-tight"
                    >
                      {{ metodo }}
                    </span>
                  </div>
                </div>

                <div
                  v-if="order.inc > 0"
                  class="flex items-center gap-1.5 text-amber-600/80 dark:text-amber-500/60 px-2 py-0.5 rounded-lg bg-amber-50/30 dark:bg-amber-900/5 border border-amber-100/50 dark:border-amber-800/20"
                >
                  <span class="material-symbols-rounded text-sm">info</span>
                  <span class="text-[10px] font-bold">Incluye recargo:</span>
                  <span class="text-[10px] font-black tabular-nums"
                    >+{{ formatCOP(order.inc) }}</span
                  >
                </div>
              </div>
            </div>
            <div class="flex justify-between" v-if="order.factura_info.fecha_pago">
              <span class="text-gray-500">Pagado el:</span>
              <span class="font-medium text-gray-700 dark:text-gray-300">{{
                order.factura_info.fecha_pago
              }}</span>
            </div>
          </div>
        </div>

        <div
          class="space-y-2 bg-gray-50/50 dark:bg-white/[0.02] p-4 rounded-2xl border border-gray-100 dark:border-white/5"
        >
          <div class="flex justify-between text-gray-600 dark:text-gray-400 text-sm">
            <span class="font-medium">Subtotal Bruto</span>
            <span class="font-mono">{{ formatCOP(order.subtotal) }}</span>
          </div>

          <div
            v-if="order.descuentos > 0"
            class="flex justify-between text-rose-600 dark:text-rose-400 text-sm font-bold"
          >
            <span class="flex items-center gap-1">
              <span class="material-symbols-rounded text-sm">loyalty</span>
              Ahorro Total
            </span>
            <span class="font-mono">- {{ formatCOP(order.descuentos) }}</span>
          </div>

          <div
            class="flex justify-between text-emerald-600 dark:text-emerald-400 text-sm font-medium"
          >
            <span>Propina ({{ order.porcentaje_propina?.toFixed(0) || 0 }}%)</span>
            <span class="font-mono">{{ formatCOP(order.propinas) }}</span>
          </div>

          <div
            class="border-t-2 border-dashed border-gray-200 dark:border-gray-700 my-2"
          ></div>

          <div class="flex justify-between items-end">
            <div class="flex flex-col">
              <span class="font-black text-gray-900 dark:text-white text-lg"
                >Total Final</span
              >
              <span class="text-[10px] text-gray-400 uppercase font-bold tracking-widest"
                >Impuestos Incluidos</span
              >
              {{ formatCOP(order.inc) }}
            </div>
            <span
              class="font-mono font-black text-3xl text-primary bg-primary/5 px-3 py-1 rounded-xl shadow-inner border border-primary/10"
            >
              {{ formatCOP(order.total) }}
            </span>
          </div>
        </div>
      </div>
    </div>
  </BaseModalSteps>
</template>

<style scoped>
/* Scrollbar bonita */
.scrollbar-thin::-webkit-scrollbar {
  width: 6px;
}

.scrollbar-thin::-webkit-scrollbar-track {
  background: transparent;
}

.scrollbar-thin::-webkit-scrollbar-thumb {
  background-color: #cbd5e1;
  border-radius: 20px;
}

.dark .scrollbar-thin::-webkit-scrollbar-thumb {
  background-color: #4b5563;
}
</style>
