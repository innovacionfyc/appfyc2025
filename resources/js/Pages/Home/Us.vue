<script setup>
import { Head } from '@inertiajs/vue3'
import HeaderNav from '@/Components/HeaderNav.vue'
import SiteFooter from '@/Components/SiteFooter.vue'
import RevealSection from '@/Components/RevealSection.vue'

import UsHistory from '@/Components/Us/UsHistory.vue'
import UsTeam from '@/Components/Us/UsTeam.vue'
import UsModalities from '@/Components/Us/UsModalities.vue'
import UsMethodologies from '@/Components/Us/UsMethodologies.vue'
</script>

<template>
  <Head title="Nosotros" />

  <HeaderNav />

  <main class="min-h-screen pt-24">
    <RevealSection>
      <UsHistory />
    </RevealSection>

    <RevealSection>
      <UsTeam />
    </RevealSection>

    <RevealSection>
      <UsModalities />
    </RevealSection>

    <RevealSection>
      <UsMethodologies />
    </RevealSection>
  </main>

  <SiteFooter />
</template>

<!-- <style>
/* Ocultar barra de scroll para inmersión total */
div::-webkit-scrollbar {
  display: none;
}
div {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style> -->