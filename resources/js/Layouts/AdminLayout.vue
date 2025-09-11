<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import FlashMessage from '@/Components/FlashMessage.vue';
import { useAuthStore } from '@/stores/auth'; // <-- 1. Importa tu store

const page = usePage();
const authStore = useAuthStore(); // <-- 2. Inicializa el store

// 3. Creamos una propiedad computada que refleje el usuario de Inertia
const userFromInertia = computed(() => page.props.auth.user);

// 4. Usamos la acción del store para establecer el usuario
// Esto se ejecutará en cada carga de página, manteniendo el store sincronizado
authStore.setUser(userFromInertia.value);
</script>

<template>
    <FlashMessage
        :message="page.props.flash.success"
        type="success"
    />
    <FlashMessage
        :message="page.props.flash.error"
        type="error"
    />
    <div class="min-h-screen bg-gray-100 flex">
        <aside class="w-64 bg-gray-800 text-white p-4">
            <h1 class="text-2xl font-bold mb-6">La Magistral POS</h1>
            <nav>
                <ul>
                    <li>
                        <Link :href="route('admin.dashboard')" class="block py-2 px-4 rounded hover:bg-gray-700">Dashboard</Link>
                    </li>
                    <li>
                        <Link href="#" class="block py-2 px-4 rounded hover:bg-gray-700">Usuarios</Link>
                    </li>
                    <li>
                        <Link href="#" class="block py-2 px-4 rounded hover:bg-gray-700">Productos</Link>
                    </li>
                    </ul>
            </nav>
        </aside>

        <main class="flex-1 p-8">
            <header class="flex justify-between items-center mb-8">
                <h2 class="text-2xl text-gray-800 font-bold">
                    <slot name="header" />
                </h2>
                </header>

            <slot />
        </main>
    </div>
</template>