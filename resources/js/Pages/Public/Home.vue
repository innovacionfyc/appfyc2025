<script setup>
import { Head } from '@inertiajs/vue3';

</script>

<template>
    <Head title="Bienvenido con Tailwind" />

    <div class="flex items-center justify-center min-h-screen bg-gray-100">
        <div class="p-8 bg-white rounded-lg shadow-lg text-center">
            <h1 class="text-4xl font-bold text-blue-600">
                ¡Bienvenido a Inertia y Tailwind!
            </h1>
            <p class="mt-4 text-gray-700">
                Ahora estás usando clases de utilidad de Tailwind CSS.
            </p>
            <p class="text-universal-naranja">prueba color</p>
        </div>
    </div>
</template>