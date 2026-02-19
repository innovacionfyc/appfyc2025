<script setup>
import { Head } from '@inertiajs/vue3'
import HeaderNav from '@/Components/HeaderNav.vue'
import SectionPlantilla from '@/Components/SectionPlantilla.vue'
</script>

<template>
  <Head title="Plantilla" />

  <div class="h-screen w-full overflow-y-scroll snap-y snap-mandatory scroll-smooth bg-white">
    
    <HeaderNav />

    <SectionPlantilla class="snap-start h-dvh w-full">
    </SectionPlantilla>
    

  </div>
</template>

<style>
div::-webkit-scrollbar {
  display: none;
}
div {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>