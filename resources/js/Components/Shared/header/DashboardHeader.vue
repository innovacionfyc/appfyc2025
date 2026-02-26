<script setup>
import { computed } from 'vue';

const props = defineProps({
  title: { type: String, default: 'Panel de control' },
  subtitle: { type: String, default: 'Resumen de actividad' },
  stats: { type: Array, default: () => [] } 
});

const currentDate = computed(() => {
  return new Intl.DateTimeFormat('es-CO', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  }).format(new Date());
});
</script>

<template>
  <header class="mb-10 animate-in fade-in slide-in-from-top-4 duration-1000">
    <div class="flex items-center gap-2 mb-4">
      <div class="px-4 py-1.5 bg-black/5 backdrop-blur-md rounded-full border border-black/5 flex items-center gap-2">
        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
        <span class="text-[12px] font-bold text-slate-900/60">{{ currentDate }}</span>
      </div>
      <span class="text-[12px] font-bold text-slate-300">/</span>
      <span class="text-[12px] font-bold text-slate-400">F&C Consultores</span>
    </div>

    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-8">
      
      <div class="max-w-2xl">
        <h2 class="text-4xl md:text-5xl font-extrabold text-slate-900 tracking-tight mb-3">
          {{ title }}
        </h2>
        <p class="text-lg font-medium text-slate-500/80 leading-snug">
          {{ subtitle }}
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-3">
        
        <div v-for="stat in stats" :key="stat.label" 
             class="relative overflow-hidden flex items-center gap-4 bg-white/40 backdrop-blur-2xl border border-white/60 px-5 py-3 rounded-[2rem] shadow-sm hover:shadow-md transition-all duration-500 group">
          
          <div class="absolute inset-0 bg-gradient-to-tr from-white/20 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>

          <div :class="['w-10 h-10 rounded-2xl flex items-center justify-center shadow-sm border border-white', stat.bg || 'bg-white']">
            <span class="material-symbols-rounded text-[24px]" :class="stat.color || 'text-slate-900'">
              {{ stat.icon }}
            </span>
          </div>

          <div class="flex flex-col relative z-10">
            <span class="text-[15px] font-extrabold text-slate-900 leading-none mb-1">{{ stat.value }}</span>
            <span class="text-[12px] font-semibold text-slate-400 leading-none">{{ stat.label }}</span>
          </div>
        </div>

        <div class="flex items-center gap-3 ml-2">
          <slot name="actions" />
        </div>

      </div>
    </div>

    <div class="w-full h-[1px] bg-gradient-to-r from-transparent via-black/5 to-transparent mt-10"></div>
  </header>
</template>

<style scoped>
.material-symbols-rounded {
  font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24;
}

/* Tipografía de sistema San Francisco style */
h2 {
  letter-spacing: -0.03em;
}

/* Efecto de curvatura Apple (Squircle) aproximado */
.rounded-\[2rem\] {
  border-radius: 2rem;
}
</style>