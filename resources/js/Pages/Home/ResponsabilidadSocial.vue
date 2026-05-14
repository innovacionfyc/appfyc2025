<script setup>
import HeaderNav from "@/Components/HeaderNav.vue";
import RevealSection from "@/Components/RevealSection.vue";
import SiteFooter from "@/Components/SiteFooter.vue";
import GuestLayout from "@/Layouts/GuestLayout.vue";
</script>

<template>
  <GuestLayout>
    <div class="min-h-screen">
      <HeaderNav />
      <main class="min-h-screen pt-24">
        <RevealSection>
          <div class="max-w-7xl mx-auto px-6 lg:px-8">
            <h1 class="text-4xl font-black text-gray-900 mb-6">Responsabilidad Social</h1>

            <p class="text-lg text-gray-600 leading-relaxed max-w-3xl text-justify">
              Bienvenido al Rincón del Cliente. Aquí encontrarás contenidos, recursos,
              publicaciones y material exclusivo diseñado para acompañar tu experiencia
              con F&C Consultores.
            </p>
          </div>
          <SiteFooter />
        </RevealSection>
      </main>
    </div>
  </GuestLayout>
</template>
