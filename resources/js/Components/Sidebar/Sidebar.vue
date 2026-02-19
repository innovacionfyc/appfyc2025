<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
const props = defineProps({
  auth: {
    type: Object,
  },
});
// Estado para controlar el menú en dispositivos móviles
const isMobileMenuOpen = ref(false);

const toggleMenu = () => {
    isMobileMenuOpen.value = !isMobileMenuOpen.value;
};

// Lista de navegación para mantener el template limpio
const navItems = [
    { name: 'Inicio', href: '/', icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6' },
    { name: 'Proyectos', href: '/proyectos', icon: 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10' },
    { name: 'Configuración', href: '/configuracion', icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z' },
];
</script>

<template>
    <div>
        <button 
            @click="toggleMenu" 
            class="md:hidden fixed top-4 left-4 z-50 p-3 rounded-2xl bg-white/70 backdrop-blur-xl shadow-lg border border-white/40 text-gray-700 focus:outline-none dark:bg-gray-800/70 dark:text-gray-200 dark:border-gray-700"
        >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path v-if="!isMobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>

        <transition enter-active-class="transition-opacity duration-300" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="transition-opacity duration-300" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div 
                v-if="isMobileMenuOpen" 
                @click="toggleMenu" 
                class="fixed inset-0 bg-black/30 backdrop-blur-sm z-30 md:hidden"
            ></div>
        </transition>

        <aside 
            :class="[
                'fixed top-4 bottom-4 left-4 z-40 w-72 transition-transform duration-300 ease-[cubic-bezier(0.4,0,0.2,1)]',
                isMobileMenuOpen ? 'translate-x-0' : '-translate-x-[120%] md:translate-x-0',
                'rounded-[2rem] shadow-2xl flex flex-col',
                'bg-white/60 backdrop-blur-2xl border border-white/50',
                'dark:bg-gray-900/60 dark:border-gray-700/50 dark:shadow-gray-900/50'
            ]"
        >
            <div class="px-8 py-8 flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-blue-500 to-indigo-500 shadow-inner flex items-center justify-center">
                    <span class="text-white font-bold text-xl">F</span>
                </div>
                <h1 class="text-1xl font-extrabold text-gray-800 dark:text-white tracking-tight">F&C<span class="text-blue-500"> Consultores</span></h1>
            </div>

            <nav class="flex-1 px-4 space-y-2 overflow-y-auto custom-scrollbar">
                <Link 
                    v-for="item in navItems" 
                    :key="item.name" 
                    :href="item.href"
                    :class="[
                        'flex items-center gap-4 px-4 py-3.5 rounded-2xl transition-all duration-200 font-medium',
                        $page.url === item.href 
                            ? 'bg-white shadow-sm text-blue-600 dark:bg-gray-800 dark:text-blue-400' 
                            : 'text-gray-600 hover:bg-white/50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800/50 dark:hover:text-white'
                    ]"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="item.icon" />
                    </svg>
                    {{ item.name }}
                </Link>
            </nav>

            <div class="p-4 mt-auto">
                <div class="p-4 rounded-2xl bg-white/40 dark:bg-gray-800/40 border border-white/30 dark:border-gray-700/30 flex items-center gap-3 cursor-pointer hover:bg-white/60 transition-colors">
                    <img src="https://ui-avatars.com/api/?name=User&background=0D8ABC&color=fff" alt="User Avatar" class="w-10 h-10 rounded-full shadow-sm">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white truncate">{{ auth.user.name }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ auth.user.email }}</p>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</template>

<style scoped>
/* Para ocultar el scrollbar feo pero permitir scroll si hay muchos items */
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: rgba(156, 163, 175, 0.3);
    border-radius: 20px;
}
</style>