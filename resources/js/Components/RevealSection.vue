<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({
  delay: { type: Number, default: 0 },
  // Permite que la animación se repita si el usuario sube y baja rápido
  repeat: { type: Boolean, default: false }, 
  class: { type: String, default: '' }
})

const target = ref(null)
const isVisible = ref(false)
let observer = null

onMounted(() => {
  observer = new IntersectionObserver(
    ([entry]) => {
      // Activamos cuando el 15% del componente es visible
      if (entry.isIntersecting) {
        isVisible.value = true
        // Si no queremos repetir, desconectamos para ahorrar recursos
        if (!props.repeat) observer.disconnect()
      } else {
        // Si repeat es true, ocultamos al salir para volver a animar al entrar
        if (props.repeat) isVisible.value = false
      }
    },
    { threshold: 0.15, rootMargin: '0px' } // Ajustado para ser más reactivo
  )

  if (target.value) observer.observe(target.value)
})

onBeforeUnmount(() => {
  if (observer) observer.disconnect()
})
</script>

<template>
  <div
    ref="target"
    :class="props.class"
    class="perspective-container" 
  >
    <div
      class="w-full h-full transition-all duration-[1200ms] will-change-transform"
      :style="{ 
        transitionDelay: `${delay}ms`,
        transitionTimingFunction: 'cubic-bezier(0.2, 0.8, 0.2, 1)' 
      }"
      :class="[
        isVisible 
          ? 'opacity-100 translate-y-0 scale-100 rotate-x-0 blur-0' 
          : 'opacity-0 translate-y-24 scale-[0.92] rotate-x-6 blur-sm'
      ]"
    >
      <slot />
    </div>
  </div>
</template>

<style scoped>
/* Esto agrega profundidad 3D. 
  Hace que el elemento parezca venir "desde el fondo" de la pantalla.
*/
.perspective-container {
  perspective: 1200px;
  perspective-origin: center center;
}

/* Clase utilitaria para rotación en X (si no usas Tailwind plugin de 3d).
  Esto inclina la sección hacia atrás cuando está oculta.
*/
.rotate-x-6 {
  transform: rotateX(6deg);
}
.rotate-x-0 {
  transform: rotateX(0deg);
}
</style>