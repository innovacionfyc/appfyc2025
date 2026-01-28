<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { LogOut } from 'lucide-vue-next';
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div
                    class="overflow-hidden bg-white shadow-sm sm:rounded-lg"
                >
                    <div class="p-6 text-gray-900">
                    Eres super admin!
                    </div>
                </div>
                  <Link
          :href="route('logout')"
          method="post"
          as="button"
          class="flex w-full items-center px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 rounded-xl transition-all duration-200 group"
        >
          <LogOut
            class="mr-3 h-5 w-5 text-red-400 group-hover:text-red-600 transition-colors"
          />
          Finalizar Sesión
        </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
