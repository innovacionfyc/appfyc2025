
<script setup>
import MensajesLayout from './MensajesLayout.vue';

</script>
<template>
  <div class="selection:bg-primary-vinotinto selection:text-white">
    <main>
      <slot />
      <MensajesLayout />
    </main>
  </div>
</template>
