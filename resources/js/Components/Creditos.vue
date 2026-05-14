<template>
  <section class="w-full bg-white py-8 flex flex-col items-center justify-center gap-2 border-t border-slate-50">
    <div class="flex items-center gap-2">
      <p class="text-[10px] font-black text-slate-300 uppercase tracking-[0.3em]">
        f&c consultores
      </p>
    </div>
    
    <div class="flex items-center gap-1.5 group cursor-default">
      <span class="text-[11px] font-medium text-slate-400 lowercase">
        hecho con
      </span>
      
      <span class="material-symbols-rounded text-red-500 text-sm animate-pulse">
        favorite
      </span>
      
      <span class="text-[11px] font-medium text-slate-400 lowercase">
        por el equipo <span class="text-slate-600 font-bold">transformación digital y comunicaciones</span>
      </span>
    </div>

    <div class="h-1 w-12 bg-slate-100 rounded-full mt-2"></div>
  </section>
</template>

<style scoped>
.material-symbols-rounded {
  font-variation-settings:
    'FILL' 1,
    'wght' 400,
    'GRAD' 0,
    'opsz' 24;
}

@keyframes pulse-soft {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.15); }
}

.animate-pulse {
  animation: pulse-soft 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
</style>