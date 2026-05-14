<script setup>
import { Head } from "@inertiajs/vue3";
import HeaderNav from "@/Components/HeaderNav.vue";
import SiteFooter from "@/Components/SiteFooter.vue";
import RevealSection from "@/Components/RevealSection.vue";

import OfferHero from "@/Components/Offer/OfferHero.vue";
import OfferModalities from "@/Components/Offer/OfferModalities.vue";
import OfferMethodology from "@/Components/Offer/OfferMethodology.vue";
import OfferCtaStrip from "@/Components/Offer/OfferCtaStrip.vue";
import GuestLayout from "@/Layouts/GuestLayout.vue";
</script>

<template>
  <Head title="Oferta" />

  <GuestLayout>
    <div
      class="h-screen w-full overflow-y-scroll snap-y snap-mandatory scroll-smooth bg-white flex flex-col"
    >
      <HeaderNav />

      <main class="flex-grow">
        <RevealSection class="snap-start min-h-dvh w-full">
          <OfferHero />
        </RevealSection>

        <RevealSection :repeat="true" class="snap-start w-full z-30 relative">
          <OfferModalities />
        </RevealSection>

        <RevealSection :repeat="true" class="snap-start w-full z-30 relative">
          <OfferMethodology />
        </RevealSection>
      </main>

      <RevealSection class="snap-end w-full z-40 relative mt-auto">
        <SiteFooter />
      </RevealSection>
    </div>
  </GuestLayout>
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
