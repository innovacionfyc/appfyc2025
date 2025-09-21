<script setup>
import { Head } from '@inertiajs/vue3'
import HeaderNav from '@/Components/HeaderNav.vue'
import HeroShowcase from '@/Components/HeroShowcase.vue'
</script>

<template>
  <Head title="Bienvenido a F&C Consultores" />

  <!-- Header -->
  <HeaderNav />

   <!-- HERO inmersivo -->
    <HeroShowcase />
    <!-- Si tienes datos: <HeroShowcase :events="events" /> -->
</template>