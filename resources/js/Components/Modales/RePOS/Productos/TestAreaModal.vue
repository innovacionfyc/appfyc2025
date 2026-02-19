<script setup>
import { ref, watch, onUnmounted } from "vue";
import axios from "axios";

const props = defineProps({
  isOpen: { type: Boolean, required: true },
  ip: { type: String, default: "" }, // IP de la impresora
  areaName: { type: String, default: "Área" },
  // CAMBIO: Poner localhost como defecto
  bridgeIp: { type: String, default: "127.0.0.1" },
});

const emit = defineEmits(["close"]);

const status = ref("idle");
const loadingText = ref("Iniciando...");
const errorDetails = ref("");
const logs = ref([]);
let textInterval = null;
let abortController = null;

const messages = [
  "Contactando al Puente...",
  "Puente buscando impresora...",
  "Verificando puerto 9100...",
  "Esperando confirmación TCP...",
];

watch(
  () => props.isOpen,
  (newVal) => {
    if (newVal) {
      runRealDiagnostics();
    } else {
      if (abortController) abortController.abort();
      setTimeout(resetState, 300);
    }
  }
);

function resetState() {
  status.value = "idle";
  logs.value = [];
  errorDetails.value = "";
  if (textInterval) clearInterval(textInterval);
}

// --- LÓGICA REAL CON EL PUENTE ---
async function runRealDiagnostics() {
  status.value = "scanning";
  logs.value = [];
  errorDetails.value = "";

  // 1. Validar IP Impresora

  if (!props.ip) {
    addLog("Error: No hay IP de impresora configurada.", "error");
    handleError({ message: "Configure una IP para esta área." });

    return;
  }

  // 2. Construir URL del Puente
  // Asumimos puerto 4000 como en tu servidor
  const bridgeUrl = `http://${props.bridgeIp}:4000/check-status`;

  addLog(`Puente objetivo: ${props.bridgeIp}:4000`, "info");
  addLog(`Buscando dispositivo: ${props.ip}`, "info");

  // Animación de texto
  let msgIndex = 0;
  loadingText.value = messages[0];
  textInterval = setInterval(() => {
    msgIndex = (msgIndex + 1) % messages.length;
    loadingText.value = messages[msgIndex];
  }, 800);

  abortController = new AbortController();

  try {
    // 3. PETICIÓN AL PUENTE (POST)
    const response = await axios.post(
      bridgeUrl,
      {
        ip_impresora: props.ip,
      },
      {
        timeout: 6000, // 6 segundos total (el server tiene 3s de timeout)

        signal: abortController.signal,
      }
    );

    // 4. Interpretar respuesta del Puente
    if (response.data.success) {
      addLog("¡Conexión TCP establecida!", "success");
      addLog(response.data.message, "success");
      handleSuccess();
    } else {
      throw new Error(response.data.message || "El puente reportó un error desconocido.");
    }
  } catch (error) {
    // 5. MANEJO DE ERRORES

    if (axios.isCancel(error)) {
      addLog("Operación cancelada.", "error");
    } else if (error.code === "ERR_NETWORK") {
      // Esto significa que NO SE PUDO TOCAR EL PUENTE (Node server caído o IP incorrecta)
      addLog("FALLO CRÍTICO: No se alcanza el Servidor Puente.", "error");
      handleError({
        message: `No se puede conectar con el servidor de impresión en ${props.bridgeIp}:4000. Verifica que "node server.js" esté corriendo.`,
      });
    } else if (error.response) {
      // El puente respondió, pero con error (ej. impresora apagada)
      addLog(`El puente respondió: ${error.response.data.message}`, "error");
      handleError({ message: error.response.data.message });
    } else {
      addLog(`Error: ${error.message}`, "error");
      handleError(error);
    }
  } finally {
    clearInterval(textInterval);
  }
}

function addLog(text, type) {
  logs.value.push({ text, type, id: Date.now() + Math.random() });
}

function handleSuccess() {
  status.value = "success";
}

function handleError(error) {
  status.value = "error";
  errorDetails.value = error.message || "Error desconocido.";
}

function retry() {
  runRealDiagnostics();
}

onUnmounted(() => {
  if (textInterval) clearInterval(textInterval);
  if (abortController) abortController.abort();
});
</script>

<template>
  <Teleport to="body">
    <Transition name="overlay">
      <div
        v-if="isOpen"
        class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
      >
        <div
          class="absolute inset-0 bg-[#000]/60 backdrop-blur-xl transition-opacity"
          @click="emit('close')"
        ></div>

        <Transition name="modal-pop">
          <div
            v-if="isOpen"
            class="relative w-full max-w-sm bg-[#0A0A0A] border border-white/10 rounded-[32px] shadow-2xl overflow-hidden flex flex-col items-center text-center p-8"
          >
            <div
              class="absolute top-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-white/20 to-transparent"
            ></div>
            <div
              class="absolute -top-20 -right-20 w-40 h-40 bg-indigo-500/20 blur-[60px] rounded-full pointer-events-none"
            ></div>
            <div
              class="absolute -bottom-20 -left-20 w-40 h-40 bg-rose-500/10 blur-[60px] rounded-full pointer-events-none"
            ></div>

            <div class="relative z-10 w-full mb-6">
              <h3 class="text-lg font-bold text-white mb-1">{{ areaName }}</h3>
              <div
                class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/5 border border-white/5"
              >
                <span
                  class="w-1.5 h-1.5 rounded-full"
                  :class="
                    status === 'success'
                      ? 'bg-emerald-500 shadow-[0_0_8px_#10b981]'
                      : status === 'error'
                      ? 'bg-rose-500'
                      : 'bg-amber-500 animate-pulse'
                  "
                ></span>
                <span
                  class="text-[10px] font-mono text-gray-400 tracking-wider flex gap-2"
                >
                  <span>PRN: {{ ip || "N/A" }}</span>
                  <span class="text-gray-600">|</span>
                  <span>SRV: {{ bridgeIp }}</span>
                </span>
              </div>
            </div>

            <div class="relative w-32 h-32 mb-8 flex items-center justify-center">
              <div
                v-if="status === 'scanning'"
                class="absolute inset-0 border border-indigo-500/30 rounded-full animate-ping-slow"
              ></div>
              <div
                v-if="status === 'scanning'"
                class="absolute inset-4 border border-indigo-500/50 rounded-full animate-spin-slow border-t-transparent border-l-transparent"
              ></div>

              <div
                class="relative w-16 h-16 rounded-2xl flex items-center justify-center shadow-lg transition-all duration-500"
                :class="{
                  'bg-indigo-500 shadow-indigo-500/40': status === 'scanning',
                  'bg-emerald-500 shadow-emerald-500/40': status === 'success',
                  'bg-rose-500 shadow-rose-500/40': status === 'error',
                }"
              >
                <span class="material-symbols-rounded text-3xl text-white">
                  {{
                    status === "scanning"
                      ? "router"
                      : status === "success"
                      ? "print_connect"
                      : "print_disabled"
                  }}
                </span>
              </div>
            </div>

            <div
              class="w-full bg-white/5 rounded-xl p-4 border border-white/5 mb-6 min-h-[120px] max-h-[150px] overflow-y-auto flex flex-col justify-start items-start text-left relative scrollbar-hide"
            >
              <div
                v-if="status === 'scanning'"
                class="absolute top-0 left-0 h-0.5 bg-gradient-to-r from-transparent via-indigo-500 to-transparent w-full animate-scan"
              ></div>

              <div class="space-y-1.5 w-full">
                <p
                  v-for="log in logs"
                  :key="log.id"
                  class="text-[10px] font-mono flex items-center gap-2 animate-fade-in-up"
                >
                  <span class="text-[8px] opacity-50">{{
                    new Date(log.id).toLocaleTimeString().slice(0, -3)
                  }}</span>
                  <span
                    :class="{
                      'text-gray-300': log.type === 'info',
                      'text-emerald-400': log.type === 'success',
                      'text-rose-400': log.type === 'error',
                    }"
                    >> {{ log.text }}</span
                  >
                </p>
                <p
                  v-if="status === 'scanning'"
                  class="text-[10px] text-indigo-400 font-mono animate-pulse"
                >
                  _ {{ loadingText }}
                </p>
              </div>
            </div>

            <div
              v-if="status === 'error'"
              class="w-full mb-6 p-3 bg-rose-500/10 border border-rose-500/20 rounded-xl text-left animate-fade-in-up"
            >
              <p class="text-[10px] font-bold text-rose-400 uppercase mb-1">
                Diagnóstico Fallido
              </p>
              <p class="text-xs text-gray-300 leading-snug">{{ errorDetails }}</p>
            </div>

            <div class="flex w-full gap-3">
              <button
                @click="emit('close')"
                class="flex-1 py-3 rounded-xl text-xs font-bold text-gray-400 hover:text-white hover:bg-white/5 transition-colors"
              >
                Cerrar
              </button>
              <button
                v-if="status === 'error'"
                @click="retry"
                class="flex-1 py-3 rounded-xl text-xs font-bold bg-white text-black hover:bg-gray-200 transition-colors"
              >
                Reintentar
              </button>
              <button
                v-if="status === 'success'"
                @click="emit('close')"
                class="flex-1 py-3 rounded-xl text-xs font-bold bg-emerald-500 text-white hover:bg-emerald-400 transition-colors shadow-lg shadow-emerald-500/20"
              >
                Aceptar
              </button>
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
/* Las mismas animaciones que ya tenías */
.overlay-enter-active,
.overlay-leave-active {
  transition: opacity 0.3s ease;
}
.overlay-enter-from,
.overlay-leave-to {
  opacity: 0;
}

.modal-pop-enter-active {
  transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
.modal-pop-leave-active {
  transition: all 0.2s ease-in;
}
.modal-pop-enter-from,
.modal-pop-leave-to {
  opacity: 0;
  transform: scale(0.9) translateY(10px);
}

.animate-ping-slow {
  animation: ping 2s cubic-bezier(0, 0, 0.2, 1) infinite;
}
.animate-spin-slow {
  animation: spin 3s linear infinite;
}
.animate-fade-in-up {
  animation: fadeInUp 0.3s ease-out forwards;
}

@keyframes scan {
  0% {
    transform: translateX(-100%);
  }
  100% {
    transform: translateX(100%);
  }
}
.animate-scan {
  animation: scan 1.5s linear infinite;
}

@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(5px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.scrollbar-hide::-webkit-scrollbar {
  display: none;
}
.scrollbar-hide {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>
