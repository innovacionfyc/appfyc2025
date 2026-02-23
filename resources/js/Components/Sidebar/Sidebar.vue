<script setup>
import { ref } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import { LayoutDashboard, CalendarDays, Users, Briefcase, Settings, Menu, X } from "lucide-vue-next";
import { useAuthStore } from "@/stores/auth";

const authStore = useAuthStore();
const page = usePage();

// Estado para el menú móvil
const isMobileMenuOpen = ref(false);
const toggleMenu = () => isMobileMenuOpen.value = !isMobileMenuOpen.value;

// Menú dinámico corporativo
const navItems = [
  { name: "Panel Principal", href: "/admin/dashboard", icon: LayoutDashboard },
  { name: "Gestión de Eventos", href: "/admin/eventos", icon: CalendarDays },
  { name: "Equipo Académico", href: "/admin/conferencistas", icon: Briefcase },
  { name: "Usuarios y Roles", href: "/admin/usuarios", icon: Users },
  { name: "Configuración", href: "/admin/configuracion", icon: Settings },
];
</script>

<template>
  <div>
    <button
      @click="toggleMenu"
      class="md:hidden fixed top-4 left-4 z-50 p-3 rounded-2xl bg-white/80 backdrop-blur-xl shadow-lg border border-slate-200 text-slate-700 transition-all"
    >
      <Menu v-if="!isMobileMenuOpen" class="w-6 h-6" />
      <X v-else class="w-6 h-6" />
    </button>

    <transition
      enter-active-class="transition-opacity duration-300"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-opacity duration-300"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div v-if="isMobileMenuOpen" @click="toggleMenu" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-30 md:hidden"></div>
    </transition>

    <aside
      :class="[
        'fixed top-4 bottom-4 left-4 z-40 w-72 transition-transform duration-300 ease-[cubic-bezier(0.4,0,0.2,1)] flex flex-col',
        isMobileMenuOpen ? 'translate-x-0' : '-translate-x-[120%] md:translate-x-0',
        'rounded-[2rem] shadow-2xl shadow-indigo-900/10',
        'bg-white/80 backdrop-blur-2xl border border-white'
      ]"
    >
      <div class="px-8 py-8 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-indigo-600 shadow-lg shadow-indigo-200 flex items-center justify-center shrink-0">
          <span class="text-white font-bold text-xl leading-none">F</span>
        </div>
        <div>
          <h1 class="text-xl font-extrabold text-slate-900 tracking-tight leading-none">F&C</h1>
          <span class="text-xs font-bold text-indigo-600 uppercase tracking-widest">Consultores</span>
        </div>
      </div>

      <nav class="flex-1 px-4 space-y-1 overflow-y-auto custom-scrollbar">
        <Link
          v-for="item in navItems"
          :key="item.name"
          :href="item.href"
          :class="[
            'flex items-center gap-4 px-4 py-3.5 rounded-2xl transition-all duration-200 font-medium text-sm',
            page.url.startsWith(item.href)
              ? 'bg-white shadow-sm border border-slate-100 text-indigo-600'
              : 'text-slate-600 hover:bg-white/50 hover:text-slate-900'
          ]"
        >
          <component :is="item.icon" class="w-5 h-5" :class="page.url.startsWith(item.href) ? 'text-indigo-600' : 'text-slate-400'" />
          {{ item.name }}
        </Link>
      </nav>

      <div class="p-4 mt-auto">
        <div class="p-4 rounded-2xl bg-white shadow-sm border border-slate-100 flex items-center gap-3 hover:shadow-md transition-shadow">
          <img
            :src="`https://ui-avatars.com/api/?name=${authStore.primerNombre}+${authStore.primerApellido}&background=4f46e5&color=fff&rounded=true`"
            alt="Avatar"
            class="w-10 h-10 rounded-full shadow-sm"
          />
          <div class="flex-1 min-w-0">
            <p class="text-sm font-bold text-slate-900 truncate">
              {{ authStore.nombreCompleto }}
            </p>
            <p class="text-xs text-slate-500 truncate font-medium">
              {{ authStore.cargo }}
            </p>
          </div>
        </div>
      </div>
    </aside>
  </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 20px; }
</style>