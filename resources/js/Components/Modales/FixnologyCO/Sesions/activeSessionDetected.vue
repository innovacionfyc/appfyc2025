<script setup>
import { usePage } from "@inertiajs/vue3";
import { ref, watch, computed } from "vue";

const props = defineProps({
  mainForm: Object, // Recibimos el formulario de la página principal
});

const page = usePage();
const showModal = ref(false);

const sessionData = computed(() => page.props.flash.activeSessionDetected);

watch(
  () => page.props.flash.activeSessionDetected,
  (newData) => {
    if (newData) showModal.value = true;
  }
);

const handleForceLogin = () => {
  props.mainForm.force_login = true;
  props.mainForm.post(route("login.post"), {
    onSuccess: () => {
      showModal.value = false;
    },
    onError: () => {
      props.mainForm.force_login = false;
    },
  });
};
</script>

<template>
  <Transition name="fade-zoom">
    <div
      v-if="showModal"
      class="fixed inset-0 z-[100] flex items-center justify-center p-6 bg-gray-900/90 backdrop-blur-md"
    >
      <div
        class="bg-white dark:bg-[#0B0F1A] w-full max-w-md rounded-[3rem] p-10 shadow-2xl border border-gray-100 dark:border-gray-800 text-center"
      >
        <div
          class="w-24 h-24 bg-amber-50 dark:bg-amber-500/10 rounded-[2.5rem] flex items-center justify-center mx-auto mb-8"
        >
          <span class="material-symbols-rounded text-5xl text-amber-500 animate-pulse"
            >security</span
          >
        </div>

        <h3
          class="text-2xl font-black text-gray-900 dark:text-white tracking-tighter mb-3"
        >
          ¿Deseas cambiar de dispositivo?
        </h3>

        <p class="text-xs text-gray-500 leading-relaxed mb-8 px-4">
          Tu cuenta de <span class="font-bold text-primary">Fixnology</span> ya tiene una
          sesión abierta. Por seguridad, solo permitimos un dispositivo activo a la vez.
        </p>

        <div
          class="bg-gray-50 dark:bg-black/40 rounded-[2rem] p-6 border border-gray-100 dark:border-gray-800 text-left mb-10"
        >
          <div class="flex items-center gap-4">
            <div
              class="w-12 h-12 bg-white dark:bg-gray-800 rounded-2xl flex items-center justify-center shadow-sm"
            >
              <span class="material-symbols-rounded text-gray-400">devices</span>
            </div>
            <div>
              <p
                class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-0.5"
              >
                Sesión Actual en:
              </p>
              <p class="text-sm font-black text-gray-900 dark:text-white tracking-tight">
                {{ sessionData?.dispositivo }}
              </p>
              <p class="text-[10px] font-mono text-primary font-bold">
                IP: {{ sessionData?.ip }} • Activo {{ sessionData?.fecha }}
              </p>
            </div>
          </div>
        </div>

        <div class="flex flex-col gap-4">
          <button
            @click="handleForceLogin"
            class="w-full py-4 bg-gray-900 dark:bg-white text-white dark:text-gray-900 rounded-2xl font-black uppercase tracking-widest text-[10px] shadow-xl hover:scale-[1.02] active:scale-95 transition-all"
          >
            Cerrar sesión remota y entrar aquí
          </button>
          <button
            @click="showModal = false"
            class="text-[10px] font-black uppercase tracking-widest text-gray-400 hover:text-gray-600 transition-colors"
          >
            Cancelar inicio
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.fade-zoom-enter-active,
.fade-zoom-leave-active {
  transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.fade-zoom-enter-from {
  opacity: 0;
  transform: scale(0.9) translateY(20px);
}
.fade-zoom-leave-to {
  opacity: 0;
  transform: scale(1.1) translateY(-20px);
}
</style>
