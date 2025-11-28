<script setup>
import { Head } from '@inertiajs/vue3'
import HeaderNav from '@/Components/HeaderNav.vue'
import HeroShowcase from '@/Components/HeroShowcase.vue'
import ConfidenceStrip from '@/Components/ConfidenceStrip.vue'
import OfferGrid from '@/Components/OfferGrid.vue'
import ProgramCtaStrip from '@/Components/ProgramCtaStrip.vue'
import SiteFooter from '@/Components/SiteFooter.vue'
</script>

<template>
  <Head title="Bienvenido a F&C Consultores" />

  <!-- Header -->
  <HeaderNav />

  <!-- HERO inmersivo -->
  <HeroShowcase />

  <!-- Si tienes datos: <HeroShowcase :events="events" /> -->
  <ConfidenceStrip/>

  <OfferGrid class="mt-0 mb-0" />

  <ProgramCtaStrip class="mt-0 mb-0" />

  <SiteFooter />


</template>