<script setup>
import { computed } from 'vue'

const props = defineProps({
  logos: { type: Array, required: true },
  rows: { type: Number, default: 2 },
  itemHeight: { type: Number, default: 120 }, // Altura generosa para que el logo respire
  cardWidth: { type: Number, default: 220 },  // Ancho suficiente
  gap: { type: Number, default: 24 },
  speed: { type: Number, default: 40 },       // Velocidad suave
  pauseOnHover: { type: Boolean, default: true },
})

const containerHeight = computed(
  () => `${props.rows * props.itemHeight + (props.rows - 1) * props.gap}px`
)

const rowsData = computed(() => {
  const arr = Array.from({ length: props.rows }, () => [])
  // Duplicamos los logos si son pocos para asegurar que el loop infinito no se corte visualmente
  const logosSource = props.logos.length < 10 ? [...props.logos, ...props.logos] : props.logos
  
  logosSource.forEach((logo, i) => arr[i % props.rows].push(logo))
  return arr
})
</script>

<template>
  <div class="relative w-full overflow-hidden select-none" :style="{ height: containerHeight }">
    
    <div class="absolute inset-y-0 left-0 w-32 bg-gradient-to-r from-white via-white/90 to-transparent z-20 pointer-events-none"></div>
    <div class="absolute inset-y-0 right-0 w-32 bg-gradient-to-l from-white via-white/90 to-transparent z-20 pointer-events-none"></div>

    <div class="flex flex-col" :style="{ rowGap: `${gap}px` }">
      <div
        v-for="(row, rIndex) in rowsData"
        :key="rIndex"
        class="relative flex group-container"
        :style="{ height: `${itemHeight}px` }"
      >
        <div
          class="flex items-center will-change-transform animate-marquee"
          :class="[
            rIndex % 2 === 1 ? 'animate-marquee-reverse' : '',
            pauseOnHover ? 'hover-pause' : '' /* La pausa se aplica aquí */
          ]"
          :style="{ 
            columnGap: `${gap}px`, 
            animationDuration: `${speed + (rIndex * 5)}s` 
          }"
        >
          <div v-for="loop in 2" :key="loop" class="flex items-center" :style="{ columnGap: `${gap}px` }">
            
            <div
              v-for="(src, i) in row"
              :key="`logo-${rIndex}-${loop}-${i}`"
              class="relative bg-white rounded-xl flex items-center justify-center overflow-hidden"
              :style="{ width: `${cardWidth}px`, height: `${itemHeight}px` }"
            >
              <img
                :src="src"
                :alt="`Aliado`"
                class="max-h-[80%] max-w-[85%] object-contain transition-opacity duration-300"
                loading="lazy"
              />
              
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* --- Animación Marquee --- */
@keyframes marquee {
  0% { transform: translateX(0); }
  100% { transform: translateX(-50%); }
}

.animate-marquee {
  animation: marquee linear infinite;
  width: max-content;
}

.animate-marquee-reverse {
  animation-direction: reverse;
}

/* --- Pausa al hacer hover --- */
/* Cuando el usuario pone el mouse sobre la TIRA, la animación se pausa */
.hover-pause:hover {
  animation-play-state: paused;
}
</style>