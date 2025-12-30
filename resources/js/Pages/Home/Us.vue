<script setup>
import { Head } from '@inertiajs/vue3'
import HeaderNav from '@/Components/HeaderNav.vue'
import SiteFooter from '@/Components/SiteFooter.vue'
import RevealSection from '@/Components/RevealSection.vue'

import UsHero from '@/Components/Us/UsHero.vue'
import UsStory from '@/Components/Us/UsStory.vue'
import UsValues from '@/Components/Us/UsValues.vue'
import UsApproach from '@/Components/Us/UsApproach.vue'
</script>

<template>
  <Head title="Nosotros" />

  <HeaderNav />

  <main class="min-h-screen pt-24">
    <UsHero />

    <RevealSection>
      <UsStory />
    </RevealSection>

    <RevealSection>
      <UsValues />
    </RevealSection>

    <RevealSection>
      <UsApproach />
    </RevealSection>
  </main>

  <SiteFooter />
</template>
