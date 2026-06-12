<script setup>
import { Head } from "@inertiajs/vue3";
import HeaderNav from "@/Components/HeaderNav.vue";
import HeroShowcase from "@/Components/HeroShowcase.vue";
import ConfidenceStrip from "@/Components/ConfidenceStrip.vue";
import OfferGrid from "@/Components/OfferGrid.vue";
import ProgramCtaStrip from "@/Components/ProgramCtaStrip.vue";
import SiteFooter from "@/Components/SiteFooter.vue";
import RevealSection from "@/Components/RevealSection.vue";
import GuestLayout from "@/Layouts/GuestLayout.vue";
import Creditos from "@/Components/Creditos.vue";

const props = defineProps({
  eventosHero: {
    type: Array,
    default: () => [],
  },
});
</script>

<template>
  <Head title="Bienvenido a F&C Consultores" />

  <GuestLayout>
    <div
      class="h-dvh w-full overflow-y-auto overflow-x-hidden snap-y snap-proximity scroll-smooth bg-white dark:bg-[#0B192C]"
    >
      <HeaderNav />

      <RevealSection class="snap-start min-h-dvh w-full">
        <HeroShowcase :events="eventosHero" />
      </RevealSection>

      <RevealSection
        :repeat="true"
        class="snap-start min-h-dvh w-full flex items-center bg-white z-10 relative"
      >
        <ConfidenceStrip />
      </RevealSection>

      <RevealSection
        :repeat="true"
        class="snap-start min-h-dvh w-full flex items-center bg-[#FAFAFA] z-20 relative"
      >
        <OfferGrid />
      </RevealSection>

      <RevealSection :repeat="true" class="snap-start w-full z-30 relative">
        <ProgramCtaStrip />
      </RevealSection>

      <RevealSection class="snap-start scroll-mt-72 md:scroll-mt-[22rem] lg:scroll-mt-72 w-full z-40 relative">
        <SiteFooter />

      </RevealSection>
    </div>
  </GuestLayout>
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
