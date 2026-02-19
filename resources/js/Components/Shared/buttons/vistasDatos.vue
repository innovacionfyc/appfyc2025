<script setup>
import BtnSecundario from "./btnSecundario.vue";

const viewMode = defineModel("viewMode");
</script>

<template>
  <div class="flex items-center gap-1">
    <BtnSecundario
      @click="viewMode = 'table'"
      class="material-symbols-rounded border hover:-translate-y-1"
      :class="
        viewMode === 'table'
          ? 'bg-secondary border-secondary text-mono-blanco hover:border-secondary hover:text-mono-blanco'
          : 'border-transparent'
      "
    >
      table</BtnSecundario
    >
    <BtnSecundario
      @click="viewMode = 'list'"
      class="material-symbols-rounded border hover:-translate-y-1"
      :class="
        viewMode === 'list'
          ? 'bg-secondary border-secondary text-mono-blanco hover:border-secondary hover:text-mono-blanco'
          : 'border-transparent'
      "
    >
      list</BtnSecundario
    >
   <BtnSecundario
      @click="viewMode = 'grid'"
      class="material-symbols-rounded border hover:-translate-y-1"
      :class="
        viewMode === 'grid'
          ? 'bg-secondary border-secondary text-mono-blanco hover:border-secondary hover:text-mono-blanco'
          : 'border-transparent'
      "
    >
      grid_view</BtnSecundario
    >
  </div>
</template>
