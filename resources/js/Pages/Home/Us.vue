<script setup>
import { Head } from "@inertiajs/vue3";
import HeaderNav from "@/Components/HeaderNav.vue";
import SiteFooter from "@/Components/SiteFooter.vue";
import RevealSection from "@/Components/RevealSection.vue";

import UsHistory from "@/Components/Us/UsHistory.vue";
import UsTeam from "@/Components/Us/UsTeam.vue";
import UsModalities from "@/Components/Us/UsModalities.vue";
import UsMethodologies from "@/Components/Us/UsMethodologies.vue";
import GuestLayout from "@/Layouts/GuestLayout.vue";
</script>

<template>
  <Head title="Nosotros" />
  <GuestLayout>
  <div
      class="h-screen w-full overflow-y-scroll snap-y snap-mandatory scroll-smooth bg-white flex flex-col"
    >
    <HeaderNav />

    <main class="flex-grow">
      <RevealSection class="snap-start min-h-dvh w-full">
        <UsHistory />
      </RevealSection>

      <RevealSection :repeat="true" class="snap-start w-full z-30 relative">
        <UsTeam />
      </RevealSection>

      <RevealSection :repeat="true" class="snap-start w-full z-30 relative">
        <UsModalities />
      </RevealSection>

      <RevealSection :repeat="true" class="snap-start w-full z-30 relative">
        <UsMethodologies />
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