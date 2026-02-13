<script setup>
import { Head } from '@inertiajs/vue3'
import HeaderNav from '@/Components/HeaderNav.vue'
import HeroShowcase from '@/Components/HeroShowcase.vue'
import ConfidenceStrip from '@/Components/ConfidenceStrip.vue'
import OfferGrid from '@/Components/OfferGrid.vue'
import ProgramCtaStrip from '@/Components/ProgramCtaStrip.vue'
import SiteFooter from '@/Components/SiteFooter.vue'
import RevealSection from '@/Components/RevealSection.vue'
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
/* Ocultar barra de scroll para inmersión total */
div::-webkit-scrollbar {
  display: none;
}
div {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>