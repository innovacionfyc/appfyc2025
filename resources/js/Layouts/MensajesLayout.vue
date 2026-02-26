<script setup>
import { usePage } from "@inertiajs/vue3";
import { ref, watch, computed } from "vue";

const page = usePage();
const mensajeNotificacion = ref("");
const tipoNotificacion = ref("success");
const mostrarNotificacion = ref(false);
let timer = null;

const playSound = (tipo) => {};

const mostrarMensaje = (mensaje, tipo) => {
  if (mostrarNotificacion.value) {
    mostrarNotificacion.value = false;
    setTimeout(() => initNotification(mensaje, tipo), 250); // Un poco más de tiempo para el cambio
  } else {
    initNotification(mensaje, tipo);
  }
};

const initNotification = (mensaje, tipo) => {
  mensajeNotificacion.value = mensaje;
  tipoNotificacion.value = tipo;
  mostrarNotificacion.value = true;
  playSound(tipo);
  iniciarTimer();
};

const iniciarTimer = () => {
  clearTimeout(timer);
  timer = setTimeout(() => cerrarNotificacion(), 5000);
};

const cerrarNotificacion = () => {
  mostrarNotificacion.value = false;
};

const styles = computed(() => {
  const isError = tipoNotificacion.value === 'error';
  return {
    iconColor: isError ? 'text-red-500' : 'text-emerald-500',
    iconName: isError ? 'error' : 'check_circle',
    title: isError ? 'Error' : 'Notificación',
    // Efecto de brillo de borde dinámico
    ring: isError ? 'ring-red-400/20' : 'ring-emerald-400/20'
  };
});

watch(
  () => page.props.flash,
  (flash) => {
    if (flash?.success) mostrarMensaje(flash.success, "success");
    else if (flash?.error) mostrarMensaje(flash.error, "error");
  },
  { deep: true, immediate: true }
);
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition all duration-600 ease-[cubic-bezier(0.17,0.89,0.32,1.49)]"
      enter-from-class="opacity-0 -translate-y-12 scale-75 blur-lg"
      enter-to-class="opacity-100 translate-y-0 scale-100 blur-0"
      
      leave-active-class="transition all duration-400 ease-[cubic-bezier(0.4,0,0.2,1)]"
      leave-from-class="opacity-100 scale-100 blur-0"
      leave-to-class="opacity-0 scale-90 -translate-y-8 blur-sm"
    >
      <div v-if="mostrarNotificacion" 
           class="fixed top-8 left-0 right-0 z-[9999] flex justify-center pointer-events-none px-4">
        
        <div 
          class="
            pointer-events-auto
            relative w-auto
            bg-white/50 backdrop-blur-2xl
            border border-white/50
            rounded-[32px] 
            shadow-[0_20px_50px_rgba(0,0,0,0.1)]
            overflow-hidden group ring-1
          "
          :class="styles.ring"
          @mouseenter="clearTimeout(timer)" 
          @mouseleave="iniciarTimer"
        >
          <div class="p-4 pl-5 flex items-center gap-4">
            
            <div class="shrink-0 w-11 h-11 flex items-center justify-center rounded-2xl bg-white/40 shadow-[inset_0_1px_2px_rgba(255,255,255,0.8)] border border-white/60">
              <span class="material-symbols-rounded text-[28px] transition-transform duration-500 group-hover:scale-110" :class="styles.iconColor">
                {{ styles.iconName }}
              </span>
            </div>

            <div class="flex-1 min-w-0 pr-2">
              <h4 class="text-[12px] font-bold text-black/30 leading-none mb-1">
                {{ styles.title }}
              </h4>
              <p class="text-[15px] font-bold text-slate-800 leading-tight">
                {{ mensajeNotificacion }}
              </p>
            </div>

            <button 
              @click="cerrarNotificacion"
              class="shrink-0 w-8 h-8 flex items-center justify-center rounded-full bg-black/[0.03] text-black/20 hover:bg-black/[0.08] hover:text-black/50 transition-all"
            >
              <span class="material-symbols-rounded text-[18px]">close</span>
            </button>
          </div>

          <div class="absolute bottom-0 left-0 w-full h-[3px] bg-black/[0.02]">
            <div 
              class="h-full bg-black/[0.08] origin-left animate-progress"
            ></div>
          </div>

        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
@keyframes progress-shrink {
  from { transform: scaleX(1); }
  to { transform: scaleX(0); }
}

.animate-progress {
  animation: progress-shrink 5s linear forwards;
}

.group:hover .animate-progress {
  animation-play-state: paused;
}

/* Forzar que los iconos se vean rellenos y suaves */
.material-symbols-rounded {
  font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 24;
}

/* Efecto de cristal extra para navegadores que lo soporten */
@supports (backdrop-filter: blur(1px)) {
  .backdrop-blur-2xl {
    backdrop-filter: blur(40px) saturate(180%);
  }
}
</style>