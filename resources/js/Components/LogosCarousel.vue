<script setup>
import { computed } from 'vue'

const props = defineProps({
  logos: { type: Array, required: true },    // rutas de imagen
  rows: { type: Number, default: 2 },        // nº filas
  itemHeight: { type: Number, default: 92 }, // alto tarjeta (px)
  cardWidth: { type: Number, default: 200 }, // ancho tarjeta (px)
  gap: { type: Number, default: 18 },        // separación (px)
  speed: { type: Number, default: 18 },      // seg por vuelta (menor = más rápido)
  pauseOnHover: { type: Boolean, default: true },
})

const containerHeight = computed(
  () => `${props.rows * props.itemHeight + (props.rows - 1) * props.gap}px`
)

const rowsData = computed(() => {
  const arr = Array.from({ length: props.rows }, () => [])
  props.logos.forEach((logo, i) => arr[i % props.rows].push(logo))
  return arr
})

/* ---- Tilt 3D handlers por tarjeta ---- */
function onMove(e) {
  const el = e.currentTarget
  const rect = el.getBoundingClientRect()
  const x = (e.clientX - rect.left) / rect.width   // 0..1
  const y = (e.clientY - rect.top) / rect.height   // 0..1

  const rotateX = (0.5 - y) * 12 // máx 12°
  const rotateY = (x - 0.5) * 14 // máx 14°
  el.style.setProperty('--rx', `${rotateX}deg`)
  el.style.setProperty('--ry', `${rotateY}deg`)
  el.style.setProperty('--tz', `14px`) // “flota” un poco
  el.style.setProperty('--sh', `${Math.round((x - 0.5) * 16)}px ${Math.round((y - 0.5) * -16)}px 24px rgba(0,0,0,.12)`)
}

function onLeave(e) {
  const el = e.currentTarget
  el.style.setProperty('--rx', `0deg`)
  el.style.setProperty('--ry', `0deg`)
  el.style.setProperty('--tz', `0px`)
  el.style.setProperty('--sh', `0 6px 18px rgba(0,0,0,.08)`)
}
</script>

<template>
  <div
    class="relative overflow-hidden rounded-2xl ring-1 ring-black/5 bg-white/80 backdrop-blur p-4"
    :style="{ height: containerHeight }"
  >
    <!-- vignettes laterales -->
    <div class="pointer-events-none absolute inset-y-0 left-0 w-12 bg-gradient-to-r from-white/80 to-transparent z-10" />
    <div class="pointer-events-none absolute inset-y-0 right-0 w-12 bg-gradient-to-l from-white/80 to-transparent z-10" />

    <div class="flex flex-col" :style="{ rowGap: `${gap}px` }">
      <div
        v-for="(row, rIndex) in rowsData"
        :key="rIndex"
        class="relative"
        :style="{ height: `${itemHeight}px` }"
      >
        <!-- cinta duplicada para loop infinito -->
        <div
          class="absolute inset-y-0 flex items-center will-change-transform whitespace-nowrap animate-marquee"
          :class="[
            rIndex % 2 === 1 ? 'reverse-direction' : '',
            pauseOnHover ? 'hover:[animation-play-state:paused]' : ''
          ]"
          :style="{ columnGap: `${gap}px`, animationDuration: `${speed}s` }"
        >
          <!-- bloque 1 -->
          <div class="flex items-center" :style="{ columnGap: `${gap}px` }">
            <div
              v-for="(src, i) in row"
              :key="'a-'+rIndex+'-'+i"
              class="tilt-card"
              :style="{ width: `${cardWidth}px`, height: `${itemHeight}px` }"
              @mousemove="onMove"
              @mouseleave="onLeave"
            >
              <img
                :src="src"
                :alt="'Logo '+(i+1)"
                class="max-h-16 w-auto"
                loading="lazy"
                @error="e => (e.target.style.opacity = 0.35)"
              />
            </div>
          </div>

          <!-- bloque 2 (duplicado) -->
          <div class="flex items-center" :style="{ columnGap: `${gap}px` }">
            <div
              v-for="(src, i) in row"
              :key="'b-'+rIndex+'-'+i"
              class="tilt-card"
              :style="{ width: `${cardWidth}px`, height: `${itemHeight}px` }"
              @mousemove="onMove"
              @mouseleave="onLeave"
            >
              <img
                :src="src"
                :alt="'Logo '+(i+1)"
                class="max-h-16 w-auto"
                loading="lazy"
                @error="e => (e.target.style.opacity = 0.35)"
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Marquee horizontal infinito */
@keyframes marquee-x {
  0%   { transform: translateX(0%); }
  100% { transform: translateX(-50%); }
}
.animate-marquee { animation: marquee-x linear infinite; }
.reverse-direction { animation-direction: reverse; }

/* Tarjeta con tilt 3D */
.tilt-card{
  display:flex; align-items:center; justify-content:center;
  background:#fff; border-radius:12px;
  box-shadow: var(--sh, 0 6px 18px rgba(0,0,0,.08));
  border: 1px solid rgba(0,0,0,.06);
  transform-style: preserve-3d;
  transform: perspective(800px) rotateX(var(--rx,0)) rotateY(var(--ry,0)) translateZ(var(--tz,0));
  transition: transform .15s ease, box-shadow .2s ease;
  will-change: transform;
}
.tilt-card:hover{ cursor: pointer; }
</style>
