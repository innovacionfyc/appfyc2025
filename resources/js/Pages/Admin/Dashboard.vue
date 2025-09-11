<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useAuthStore } from '@/stores/auth'; // Importa el store

const auth = useAuthStore(); // Usa el store
</script>

<template>
    <Head title="Admin Dashboard" />

    <AdminLayout>
        <template #header>
            Dashboard
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        ¡Bienvenido de nuevo, {{ auth.userName }}! Este es tu panel de administración.
                    </div>
                    <Link :href="route('test.flash')" class="p-2 bg-blue-500 text-white rounded">
        Probar Mensaje Flash
    </Link>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>