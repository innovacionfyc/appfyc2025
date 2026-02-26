<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'

/**
 * Fondo de búhos responsive:
 * - Calcula filas/columnas con ResizeObserver.
 * - Ajusta tamaño de celda por breakpoint.
 * - Pupilas siguen el mouse con easing.
 */
const props = defineProps({
  opacity: { type: Number, default: 0.22 },
  cellBase: { type: Number, default: 120 },   // tamaño base desktop
  pupilMax: { type: Number, default: 5 },     // desplazamiento máx. de pupila (px)
  smooth:   { type: Number, default: 0.15 },  // 0–1, mayor = más lento
  owlTint:  { type: String, default: '' },    // ej: 'rgba(148,41,52,0.06)'
})

const root = ref(null)
const width = ref(0)
const height = ref(0)

const mouse = ref({ x: 0, y: 0 })
const target = ref({ x: 0, y: 0 })
let rafId

// tamaño de celda según ancho
const cell = computed(() => {
  const w = width.value
  if (w < 640)  return Math.round(props.cellBase * 0.75) // sm
  if (w < 1024) return Math.round(props.cellBase * 0.92) // md
  return props.cellBase                                  // lg+
})

const cols = computed(() => Math.max(1, Math.ceil(width.value  / cell.value) + 1))
const rows = computed(() => Math.max(1, Math.ceil(height.value / cell.value) + 1))

function tick() {
  // easing simple hacia la posición del mouse
  target.value.x += (mouse.value.x - target.value.x) * props.smooth
  target.value.y += (mouse.value.y - target.value.y) * props.smooth
  rafId = requestAnimationFrame(tick)
}

function onMouseMove(e) {
  const rect = root.value?.getBoundingClientRect()
  if (!rect) return
  mouse.value = { x: e.clientX - rect.left, y: e.clientY - rect.top }
}

let ro
onMounted(() => {
  if (!root.value) return

  ro = new ResizeObserver(([entry]) => {
    const cr = entry.contentRect
    width.value  = Math.max(1, cr.width)
    height.value = Math.max(1, cr.height)
  })
  ro.observe(root.value)

  window.addEventListener('mousemove', onMouseMove, { passive: true })
  rafId = requestAnimationFrame(tick)
})

onBeforeUnmount(() => {
  ro?.disconnect?.()
  window.removeEventListener('mousemove', onMouseMove)
  cancelAnimationFrame(rafId)
})

function pupilOffset(cx, cy) {
  const dx = target.value.x - cx
  const dy = target.value.y - cy
  const len = Math.hypot(dx, dy) || 1
  const ux = dx / len
  const uy = dy / len
  const m = props.pupilMax
  return { x: ux * m, y: uy * m }
}
</script>

<template>
  <div
    ref="root"
    class="absolute inset-0 pointer-events-none select-none"
    :style="{ opacity: props.opacity }"
  >
    <div
      class="h-full w-full grid"
      :style="{
        gridTemplateColumns: `repeat(${cols}, ${cell}px)`,
        gridTemplateRows: `repeat(${rows}, ${cell}px)`
      }"
    >
      <div v-for="r in rows" :key="'r'+r" class="contents">
        <div
          v-for="c in cols"
          :key="'c'+c+'r'+r"
          class="relative"
          :style="{ width: cell + 'px', height: cell + 'px' }"
        >
          <svg :width="cell" :height="cell" viewBox="0 0 100 100" class="block">
            <!-- tono sutil del cuerpo (opcional) -->
            <g v-if="owlTint">
              <circle cx="50" cy="58" r="38" :fill="owlTint" />
            </g>

            <!-- mejillas/ojos base -->
            <circle cx="35" cy="40" r="22" fill="#f4f1ee" />
            <circle cx="65" cy="40" r="22" fill="#f4f1ee" />

            <!-- iris -->
            <circle cx="35" cy="40" r="13" fill="#d9d4cf" />
            <circle cx="65" cy="40" r="13" fill="#d9d4cf" />

            <!-- pupilas (siguen el mouse) -->
            <template v-for="eye in ['left','right']" :key="eye">
              <circle
                :cx="(eye==='left'?35:65) + pupilOffset((c-0.5)*cell, (r-0.5)*cell).x"
                :cy="40 + pupilOffset((c-0.5)*cell, (r-0.5)*cell).y"
                r="6.5"
                fill="#6b6b6b"
              />
            </template>

            <!-- pico -->
            <path d="M50 46 C47 54 47 62 50 70 C53 62 53 54 50 46Z" fill="#8a8886" />
          </svg>
        </div>
      </div>
    </div>
  </div>
</template>
