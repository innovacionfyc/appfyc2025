<script setup>
defineProps({
  temporadas: { type: Array, required: true },
  modelValue: { type: Number, required: true },
});

defineEmits(["update:modelValue"]);
</script>

<template>
  <div class="flex flex-wrap gap-2.5" role="tablist" aria-label="Temporadas">
    <button
      v-for="temporada in temporadas"
      :key="temporada.numero"
      type="button"
      role="tab"
      :aria-selected="temporada.numero === modelValue"
      :disabled="!temporada.disponible"
      @click="temporada.disponible && $emit('update:modelValue', temporada.numero)"
      class="inline-flex items-center gap-2.5 px-5 py-2.5 rounded-full text-sm font-bold border transition-all duration-300 focus:outline-none focus-visible:ring-4 focus-visible:ring-podcast-oscuro/20"
      :class="
        temporada.numero === modelValue
          ? 'bg-podcast-oscuro text-white border-podcast-oscuro shadow-md shadow-podcast-oscuro/20'
          : temporada.disponible
            ? 'bg-white text-slate-600 border-slate-200 hover:border-podcast-oscuro/40 hover:text-podcast-oscuro'
            : 'bg-white/60 text-slate-400 border-dashed border-slate-200 cursor-not-allowed'
      "
    >
      <span
        class="w-2 h-2 rounded-full"
        :class="temporada.numero === modelValue ? 'bg-podcast-acento' : 'bg-slate-300'"
      ></span>
      Temporada {{ temporada.numero }}
      <span
        v-if="!temporada.disponible"
        class="text-[10px] font-black uppercase tracking-widest text-slate-400"
        >Próximamente</span
      >
    </button>
  </div>
</template>
