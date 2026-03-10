<script setup>
import { ref, computed } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";
import CreateConferencistaModal from "@/Components/Conferencistas/CreateConferencistaModal.vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";

// ICONOS
import {
  Users, UserCog, GraduationCap, Search, 
  Plus, MoreHorizontal, Mail, Phone, 
  ShieldCheck, Filter, Trash2, Edit3
} from "lucide-vue-next";

const props = defineProps({
  auth: Object,
  organizadores: Array,
  conferencistas: Array,
  areas: Array,
  stats_counts: Object,
});

// ESTADOS
const activeTab = ref('equipo'); // 'equipo' o 'conferencistas'
const searchQuery = ref("");
const isSpeakerModalOpen = ref(false);

// FILTRADO DINÁMICO
const filteredList = computed(() => {
  const list = activeTab.value === 'equipo' ? props.organizadores : props.conferencistas;
  if (!searchQuery.value) return list;
  
  const query = searchQuery.value.toLowerCase();
  return list.filter(item => {
    const name = activeTab.value === 'equipo' 
      ? `${item.primer_nombre} ${item.primer_apellido}` 
      : item.nombre_completo;
    return name.toLowerCase().includes(query);
  });
});

const headerStats = [
  {
    label: "Equipo FYC",
    value: props.stats_counts?.total_equipo || 0,
    icon: "groups",
    color: "text-rose-500",
    bg: "bg-rose-50",
  },
  {
    label: "Conferencistas",
    value: props.stats_counts?.total_conferencistas || 0,
    icon: "record_voice_over",
    color: "text-orange-500",
    bg: "bg-orange-50",
  },
];
</script>

<template>
  <Head title="Gestión de Equipo | F&C" />

  <AuthenticatedLayout>
    <Sidebar>
      <DashboardHeader
        title="Gestión de Talento"
        subtitle="Administra tu equipo de trabajo y el panel de conferencistas"
        :stats="headerStats"
      />

      <main class="p-6 space-y-6 animate-in">
        
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-4 rounded-2xl shadow-sm border border-slate-100">
          <div class="flex bg-slate-100 p-1 rounded-xl">
            <button 
              @click="activeTab = 'equipo'"
              :class="activeTab === 'equipo' ? 'bg-white shadow-sm text-rose-600' : 'text-slate-500 hover:text-slate-700'"
              class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-bold transition-all"
            >
              <UserCog :size="18" /> Equipo FYC
            </button>
            <button 
              @click="activeTab = 'conferencistas'"
              :class="activeTab === 'conferencistas' ? 'bg-white shadow-sm text-rose-600' : 'text-slate-500 hover:text-slate-700'"
              class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-bold transition-all"
            >
              <GraduationCap :size="18" /> Conferencistas
            </button>
          </div>

          <div class="flex items-center gap-3">
            <div class="relative">
              <Search class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" :size="18" />
              <input 
                v-model="searchQuery"
                type="text" 
                placeholder="Buscar por nombre..."
                class="pl-10 pr-4 py-2 bg-slate-50 border-none rounded-xl text-sm focus:ring-2 focus:ring-rose-500 w-64"
              />
            </div>
            <BtnUniversal 
              v-if="activeTab === 'conferencistas'"
              @click="isSpeakerModalOpen = true"
              label="Nuevo Conferencista"
              icon="add"
              class="!rounded-xl"
            />
            <BtnUniversal 
              v-else
              label="Invitar Miembro"
              icon="person_add"
              activeColor="#2563eb"
              class="!rounded-xl"
            />
          </div>
        </div>

        <div class="bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden">
          <table class="w-full text-left border-collapse">
            <thead>
              <tr class="bg-slate-50/50 border-b border-slate-100">
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase">Nombre / Perfil</th>
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase">
                  {{ activeTab === 'equipo' ? 'Rol y Equipo' : 'Área de Expertise' }}
                </th>
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase">Contacto</th>
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase">Estado</th>
                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase text-right">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
              <tr v-for="item in filteredList" :key="item.id" class="hover:bg-slate-50/80 transition-colors group">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="h-10 w-10 rounded-full bg-slate-200 overflow-hidden flex-shrink-0">
                      <img v-if="item.foto" :src="item.foto" class="h-full w-full object-cover">
                      <div v-else class="h-full w-full flex items-center justify-center bg-rose-100 text-rose-600 font-bold uppercase">
                        {{ activeTab === 'equipo' ? item.primer_nombre[0] : item.nombre_completo[0] }}
                      </div>
                    </div>
                    <div>
                      <p class="font-bold text-slate-800">
                        {{ activeTab === 'equipo' ? `${item.primer_nombre} ${item.primer_apellido}` : `${item.primer_nombre} ${item.primer_apellido}` }}
                      </p>
                      <p class="text-xs text-slate-500">{{ activeTab === 'equipo' ? item.correo_corporativo : item.correo }}</p>
                    </div>
                  </div>
                </td>

                <td class="px-6 py-4">
                  <div v-if="activeTab === 'equipo'">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-rose-100 text-rose-800">
                      {{ item.rol.tipo_rol }}
                    </span>
                    <p class="text-xs text-slate-400 mt-1">{{ item.equipo?.nombre }}</p>
                  </div>
                  <div v-else>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-rose-100 text-rose-800">
                      {{ item.area_encargada?.nombre || 'General' }}
                    </span>
                  </div>
                </td>

                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <button class="p-1.5 rounded-lg bg-slate-100 text-slate-600 hover:bg-rose-600 hover:text-white transition-all">
                      <Mail :size="14" />
                    </button>
                    <button v-if="item" class="p-1.5 rounded-lg bg-slate-100 text-slate-600 hover:bg-emerald-600 hover:text-white transition-all">
                      <Phone :size="14" />
                    </button>
                  </div>
                </td>

                <td class="px-6 py-4">
                   <div class="flex items-center gap-1.5">
                      <div class="h-2 w-2 rounded-full bg-emerald-500"></div>
                      <span class="text-xs font-bold text-slate-700">Activo</span>
                   </div>
                </td>

                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <button class="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition-all">
                      <Edit3 :size="18" />
                    </button>
                    <button class="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition-all">
                      <Trash2 :size="18" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>

          <div v-if="filteredList.length === 0" class="py-20 flex flex-col items-center justify-center text-slate-400">
            <Users :size="48" class="mb-4 opacity-20" />
            <p class="font-medium">No se encontraron resultados</p>
          </div>
        </div>
      </main>

      <CreateConferencistaModal
        :show="isSpeakerModalOpen"
        :areas="areas"
        @close="isSpeakerModalOpen = false"
      />
      
    </Sidebar>
  </AuthenticatedLayout>
</template>

<style scoped>
.animate-in {
  animation: slide-up 0.4s ease-out;
}

@keyframes slide-up {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>