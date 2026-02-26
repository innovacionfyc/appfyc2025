
<script setup>
import { watch } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import { LogOut, Menu, User, LayoutDashboard, Users, Calendar, Megaphone } from 'lucide-vue-next';
import { useAuthStore } from '@/stores/auth';
import MensajesLayout from './MensajesLayout.vue';

const page = usePage();
const authStore = useAuthStore();

watch(
    () => page.props.auth.user,
    (newUser) => {
        if (newUser) {
            authStore.setUser(newUser);
        } else {
            authStore.clearUser();
        }
    },
    { immediate: true }
);
</script>
<template>
  <div>
    <main>
      <slot />
      <MensajesLayout />
    </main>
  </div>
</template>