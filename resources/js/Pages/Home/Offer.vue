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

  <div class="min-h-screen">
    <GuestLayout>
    <HeaderNav />
      <main class="pt-24">
        <OfferHero />

        <RevealSection>
          <OfferModalities />
        </RevealSection>

        <RevealSection>
          <OfferMethodology />
        </RevealSection>

        <RevealSection>
          <OfferCtaStrip />
        </RevealSection>
      </main>
    <SiteFooter />
    </GuestLayout>

  </div>
</template>
