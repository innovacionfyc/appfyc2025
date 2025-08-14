<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
    laravelVersion: {
        type: String,
        required: true,
    },
    phpVersion: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <Head title="Bienvenido" />
    <div class="p-6">
        <h1 class="">¡Bienvenido a Inertia con Laravel!</h1>
        <p>Versión de Laravela: {{ laravelVersion }}</p>
        <p>Versión de PHP: {{ phpVersion }}</p>
    </div>
</template>