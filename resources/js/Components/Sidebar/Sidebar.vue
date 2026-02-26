<script setup>
import { ref, computed } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import {
  LayoutDashboard,
  CalendarDays,
  Users,
  Settings,
  ChevronDown,
  LogOut,
  GraduationCap,
  Menu,
  X,
} from "lucide-vue-next";
import { useAuthStore } from "@/stores/auth";

const authStore = useAuthStore();
const page = usePage();

// --- ESTADOS ---
const isHovered = ref(false);
const activeSubmenu = ref(null);
const isMobileMenuOpen = ref(false);

// --- NAVEGACIÓN ---
const navItems = [
  { name: "Dashboard", href: "/admin/dashboard", icon: LayoutDashboard },
  {
    name: "Eventos",
    icon: CalendarDays,
    submenu: [
      { name: "Ver Todos", href: "/admin/eventos" },
      { name: "Inscripciones", href: "/admin/inscripciones" },
      { name: "Áreas", href: "/admin/areas" },
    ],
  },
  {
    name: "Academia",
    icon: GraduationCap,
    submenu: [
      { name: "Conferencistas", href: "/admin/conferencistas" },
      { name: "Material", href: "/admin/material" },
    ],
  },
  { name: "Usuarios", icon: Users, href: "/admin/usuarios" },
  { name: "Configuración", icon: Settings, href: "/admin/configuracion" },
];

// --- LÓGICA ---
const toggleSubmenu = (name) => {
  if (activeSubmenu.value === name) activeSubmenu.value = null;
  else {
    activeSubmenu.value = name;
    isHovered.value = true;
  }
};

const isUrlActive = (href) => (href ? page.url.startsWith(href) : false);
</script>

<template>
  <div class="min-h-screen bg-slate-50 font-sans">
    <button
      @click="isMobileMenuOpen = !isMobileMenuOpen"
      class="md:hidden fixed top-5 right-5 z-[100] p-3 rounded-2xl bg-white shadow-xl text-slate-600 border border-slate-100"
    >
      <component :is="isMobileMenuOpen ? X : Menu" class="w-6 h-6" />
    </button>

    <Transition
      enter-active-class="duration-300"
      enter-from-class="opacity-0"
      leave-to-class="opacity-0"
    >
      <div
        v-if="isMobileMenuOpen"
        @click="isMobileMenuOpen = false"
        class="fixed inset-0 bg-slate-950/20 backdrop-blur-sm z-[80] md:hidden"
      ></div>
    </Transition>

    <aside
      @mouseenter="isHovered = true"
      @mouseleave="
        isHovered = false;
        activeSubmenu = null;
      "
      :class="[
        'fixed left-4 top-4 bottom-4 z-[90] transition-all duration-700 ease-[cubic-bezier(0.34,1.56,0.64,1)]',
        'bg-white/70 backdrop-blur-2xl border border-white/50 shadow-[0_20px_50px_rgba(0,0,0,0.04)] rounded-[2.5rem]',
        'flex flex-col overflow-hidden',
        isHovered ? 'w-72' : 'w-20',
        isMobileMenuOpen
          ? 'translate-x-0 !w-[280px]'
          : '-translate-x-[120%] md:translate-x-0',
      ]"
    >
      <div class="p-6 mb-4 flex items-center gap-4 shrink-0">
        <div
          :class="[
            'w-10 h-10 rounded-xl bg-gradient-to-tr to-primary-vinotinto from-secondary-vinotinto2 shadow-lg flex items-center justify-center shrink-0',
            isHovered ? 'hidden' : ' opacity-100 translate-x-0',
          ]"
        >
          <span class="text-white font-bold text-md">F&C</span>
        </div>
        <div
          :class="[
            'transition-all duration-500 whitespace-nowrap',
            isHovered ? 'opacity-100 translate-x-0' : 'opacity-0 -translate-x-4',
          ]"
        >
          <img
            src="/images/logo-fyc.png"
            alt="F&C Consultores"
            class="w-auto p-2 object-contain transition-all duration-500 drop-shadow-sm"
          />
        </div>
      </div>

      <nav class="flex-1 px-3 space-y-2 overflow-y-auto no-scrollbar">
        <div v-for="item in navItems" :key="item.name">
          <Link
            v-if="!item.submenu"
            :href="item.href"
            :class="[
              'flex items-center gap-4 p-3.5 rounded-[1.25rem] transition-all duration-300 group',
              isUrlActive(item.href)
                ? 'bg-gradient-to-tr to-primary-vinotinto from-secondary-vinotinto2  text-white shadow-xl shadow-rose-900/20'
                : 'text-slate-500 hover:bg-white hover:shadow-sm',
            ]"
          >
            <component
              :is="item.icon"
              class="w-6 h-6 shrink-0 transition-transform group-hover:scale-110"
            />
            <span
              :class="[
                'font-bold text-sm transition-all duration-500',
                isHovered ? 'opacity-100' : 'opacity-0',
              ]"
            >
              {{ item.name }}
            </span>
          </Link>

          <div v-else class="space-y-1">
            <button
              @click="toggleSubmenu(item.name)"
              :class="[
                'w-full flex items-center gap-4 p-3.5 rounded-[1.25rem] transition-all duration-300 group',
                activeSubmenu === item.name
                  ? 'bg-slate-100 text-slate-900'
                  : 'text-slate-500 hover:bg-white',
              ]"
            >
              <component :is="item.icon" class="w-6 h-6 shrink-0" />
              <span
                :class="[
                  'flex-1 font-bold text-sm text-left transition-all duration-500',
                  isHovered ? 'opacity-100' : 'opacity-0',
                ]"
              >
                {{ item.name }}
              </span>
              <ChevronDown
                v-if="isHovered"
                :class="[
                  'w-4 h-4 transition-transform duration-500',
                  activeSubmenu === item.name ? 'rotate-180' : '',
                ]"
              />
            </button>

            <Transition
              enter-active-class="overflow-hidden transition-[max-height,opacity] duration-500 ease-in-out"
              enter-from-class="max-h-0 opacity-0"
              enter-to-class="max-h-40 opacity-100"
              leave-active-class="overflow-hidden transition-[max-height,opacity] duration-300 ease-in-out"
              leave-from-class="max-h-40 opacity-100"
              leave-to-class="max-h-0 opacity-0"
            >
              <div
                v-if="activeSubmenu === item.name && isHovered"
                class="pl-12 space-y-1"
              >
                <Link
                  v-for="sub in item.submenu"
                  :key="sub.name"
                  :href="sub.href"
                  class="block py-2 text-xs font-bold text-slate-400 hover:text-slate-900 transition-colors"
                >
                  {{ sub.name }}
                </Link>
              </div>
            </Transition>
          </div>
        </div>
      </nav>

      <div class="p-3 mt-auto shrink-0 border-t border-slate-100/50">
        <div
          :class="[
            'flex items-center gap-3 p-2 rounded-3xl transition-all duration-500',
            isHovered ? 'bg-white/50 shadow-sm' : '',
          ]"
        >
          <div class="relative shrink-0">
            <img
              :src="`https://ui-avatars.com/api/?name=${authStore.primerNombre}+${authStore.primerApellido}&background=0f172a&color=fff`"
              class="w-10 h-10 rounded-2xl shadow-md border-2 border-white"
            />
            <span
              class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 bg-emerald-500 border-2 border-white rounded-full"
            ></span>
          </div>

          <div
            :class="[
              'flex-1 min-w-0 transition-all duration-500',
              isHovered ? 'opacity-100 translate-x-0' : 'opacity-0 -translate-x-2',
            ]"
          >
            <p class="text-xs font-black text-slate-900 truncate">
              {{ authStore.primerNombre }} {{ authStore.primerApellido }}
            </p>
            <p class="text-[10px] font-bold text-slate-400">
              {{ authStore.cargo }} - {{ authStore.equipoNombre }}
            </p>
          </div>

          <Link  :href="route('logout')" method="post" as="button">
            <button
              v-if="isHovered"
              class="p-2 text-slate-300 hover:text-red-500 transition-colors"
            >
              <LogOut class="w-5 h-5" /></button
          ></Link>
        </div>
      </div>
    </aside>

    <main
      :class="[
        'transition-all mx-5 duration-700 ease-[cubic-bezier(0.34,1.56,0.64,1)] min-h-screen',
        isHovered ? 'md:pl-80' : 'md:pl-28',
        'p-4 md:p-8',
      ]"
    >
      <slot />
    </main>
  </div>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar {
  display: none;
}
.no-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>
