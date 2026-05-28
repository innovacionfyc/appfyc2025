<script setup>
import { ref, computed } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";
import CreateConferencistaModal from "@/Components/Conferencistas/CreateConferencistaModal.vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";

import {
  Users,
  UserCog,
  GraduationCap,
  Search,
  Mail,
  Phone,
  Trash2,
  Edit3,
  Briefcase,
  IdCard,
  FileText,
  ExternalLink,
  ShieldCheck,
} from "lucide-vue-next";
import CreateEquipoModal from "@/Equipo/CreateEquipoModal.vue";

const props = defineProps({
  auth: Object,
  organizadores: Array,
  conferencistas: Array,
  areas: Array,
  stats_counts: Object,
  roles: Array,
  equipos: Array,
  tiposDocumento: Array,
});

const activeTab = ref("equipo");
const searchQuery = ref("");
const isSpeakerModalOpen = ref(false);
// Variables Modal Equipo FYC
const isTeamModalOpen = ref(false);
const selectedTeamMember = ref(null);

const filteredList = computed(() => {
  const list = activeTab.value === "equipo" ? props.organizadores : props.conferencistas;
  if (!searchQuery.value) return list;

  const query = searchQuery.value.toLowerCase();
  return list.filter((item) => {
    const name =
      activeTab.value === "equipo"
        ? `${item.primer_nombre} ${item.primer_apellido}`
        : `${item.primer_nombre} ${item.primer_apellido}`;
    return name.toLowerCase().includes(query);
  });
});

const headerStats = computed(() => [
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
]);

const selectedSpeaker = ref(null);

// 🔴 MODIFICAR ESTAS DOS FUNCIONES:
const openEditModal = (item) => {
  if (activeTab.value === "conferencistas") {
    selectedSpeaker.value = item;
    isSpeakerModalOpen.value = true;
  } else {
    selectedTeamMember.value = item;
    isTeamModalOpen.value = true;
  }
};

const openCreateModal = () => {
  if (activeTab.value === "conferencistas") {
    selectedSpeaker.value = null;
    isSpeakerModalOpen.value = true;
  } else {
    selectedTeamMember.value = null;
    isTeamModalOpen.value = true;
  }
};

const getAreaName = (id) => {
  const area = props.areas.find((a) => a.id === id);
  return area ? area.nombre : "Área desconocida";
};

const deleteSpeaker = (item) => {
  const name =
    activeTab.value === "equipo"
      ? `${item.primer_nombre} ${item.primer_apellido}`
      : `${item.primer_nombre} ${item.primer_apellido}`;

  if (confirm(`¿Estás seguro de que deseas eliminar a ${name}?`)) {
    if (activeTab.value === "conferencistas") {
      router.delete(route("conferencistas.destroy", item.id), {
        preserveScroll: true,
      });
    } else {
      router.delete(route("equipo.destroy", item.id), { preserveScroll: true });
    }
  }
};


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

      <main class="p-6 md:p-8 space-y-8 animate-in mx-auto">
        <div
          class="flex flex-col lg:flex-row lg:items-center justify-between gap-5 bg-white p-3 rounded-3xl shadow-sm border border-slate-200/60"
        >
          <div class="flex bg-slate-100/80 p-1.5 rounded-2xl">
            <button
              @click="activeTab = 'equipo'"
              :class="
                activeTab === 'equipo'
                  ? 'bg-white shadow-sm text-primary-vinotinto ring-1 ring-slate-200/50'
                  : 'text-slate-500 hover:text-slate-800'
              "
              class="flex-1 lg:flex-none flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl text-[13px] font-black transition-all duration-300"
            >
              <UserCog :size="16" /> Equipo FYC
            </button>
            <button
              @click="activeTab = 'conferencistas'"
              :class="
                activeTab === 'conferencistas'
                  ? 'bg-white shadow-sm text-primary-vinotinto ring-1 ring-slate-200/50'
                  : 'text-slate-500 hover:text-slate-800'
              "
              class="flex-1 lg:flex-none flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl text-[13px] font-black transition-all duration-300"
            >
              <GraduationCap :size="16" /> Expertos
            </button>
          </div>

          <div class="flex flex-col sm:flex-row items-center gap-3 w-full lg:w-auto">
            <div class="relative w-full sm:w-80 group">
              <Search
                class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-rose-500 transition-colors"
                :size="16"
              />
              <input
                v-model="searchQuery"
                type="text"
                :placeholder="
                  activeTab === 'equipo'
                    ? 'Buscar miembro del equipo...'
                    : 'Buscar conferencista...'
                "
                class="w-full pl-11 pr-4 py-3 bg-slate-50 border border-slate-200/60 rounded-2xl text-[13px] font-medium text-slate-800 placeholder:text-slate-400 focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500 focus:bg-white transition-all outline-none"
              />
            </div>

            <BtnUniversal
              v-if="activeTab === 'conferencistas'"
              @click="openCreateModal"
              label="Nuevo Experto"
              icon="add"
              class="w-full sm:w-auto !rounded-2xl !py-3 !px-5"
            />
            <BtnUniversal
              v-else
              @click="openCreateModal"
              label="Invitar Miembro"
              icon="person_add"
              activeColor="#2563eb"
              class="w-full sm:w-auto !rounded-2xl !py-3 !px-5"
            />
          </div>
        </div>

        <div
          class="bg-white rounded-[2rem] shadow-sm border border-slate-200/60 overflow-hidden"
        >
          <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse whitespace-nowrap">
              <thead>
                <tr class="bg-slate-50/80 border-b border-slate-100">
                  <th
                    class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest"
                  >
                    {{
                      activeTab === "equipo"
                        ? "Perfil del Colaborador"
                        : "Perfil del Experto"
                    }}
                  </th>
                  <th
                    class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest"
                  >
                    {{ activeTab === "equipo" ? "Cargo y Área" : "Especialidad e Info" }}
                  </th>
                  <th
                    class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest"
                  >
                    Medios de Contacto
                  </th>
                  <th
                    class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center"
                  >
                    Estado
                  </th>
                  <th
                    class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right"
                  >
                    Gestión
                  </th>
                </tr>
              </thead>

              <tbody class="divide-y divide-slate-100/80">
                <tr
                  v-for="item in filteredList"
                  :key="item.id"
                  class="hover:bg-slate-50/50 transition-colors group"
                >
                  <td class="px-8 py-4">
                    <div class="flex items-center gap-4">
                      <div
                        class="relative h-14 w-14 rounded-2xl bg-slate-100 overflow-hidden shrink-0 border border-slate-200/50 shadow-sm group-hover:shadow-md transition-shadow"
                      >
                        <img
                          v-if="item.foto"
                          :src="'/storage/' + item.foto"
                          class="h-full w-full object-cover"
                        />
                        <div
                          v-else
                          class="h-full w-full flex items-center justify-center bg-gradient-to-br from-rose-50 to-rose-100 text-primary-vinotinto text-xl font-black uppercase"
                        >
                          {{ item.primer_nombre[0]
                          }}{{ item.primer_apellido ? item.primer_apellido[0] : "" }}
                        </div>
                      </div>
                      <div class="flex flex-col min-w-0 gap-0.5">
                        <p class="text-sm font-black text-slate-900 truncate">
                          {{ item.primer_nombre }} {{ item.segundo_nombre || "" }}
                          {{ item.primer_apellido }}
                        </p>
                        <div
                          v-if="activeTab === 'equipo'"
                          class="flex items-center gap-1.5 text-[11px] font-bold text-slate-500"
                        >
                          <IdCard :size="12" class="text-slate-400" />
                          <span>CC: {{ item.numero_documento }}</span>
                        </div>
                        <div
                          v-else
                          class="flex items-center gap-1.5 text-[11px] font-bold text-slate-500"
                        >
                          <ShieldCheck :size="12" class="text-slate-400" />
                          <span>Conferencista FYC</span>
                        </div>
                      </div>
                    </div>
                  </td>

                  <td class="px-6 py-4">
                    <div
                      v-if="activeTab === 'equipo'"
                      class="flex flex-col items-start gap-1.5"
                    >
                      <span
                        class="text-xs font-black text-slate-800 flex items-center gap-1.5"
                      >
                        <Briefcase :size="13" class="text-primary-vinotinto" />
                        {{ item.cargo }}
                      </span>
                      <div class="flex items-center gap-2">
                        <span
                          class="inline-flex items-center px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-widest bg-blue-50 text-primary-vinotinto border border-blue-100/50"
                        >
                          {{ item.rol?.nombre || "Sin Rol" }}
                        </span>
                        <span
                          class="text-[11px] font-bold text-slate-500 truncate max-w-[150px]"
                          :title="item.equipo?.nombre"
                        >
                          {{ item.equipo?.nombre || "Sin equipo asignado" }}
                        </span>
                      </div>
                    </div>

                    <div v-else class="flex flex-col items-start gap-2 max-w-xs">
                      <div class="flex flex-wrap gap-1.5">
                        <span
                          v-for="areaId in item.areas_encargadas"
                          :key="areaId"
                          class="inline-flex items-center px-2 py-1 rounded-md text-[9px] font-black uppercase tracking-wider bg-orange-50 text-orange-600 border border-orange-100/50"
                        >
                          {{ getAreaName(areaId) }}
                        </span>

                        <span
                          v-if="
                            !item.areas_encargadas || item.areas_encargadas.length === 0
                          "
                          class="text-[10px] text-slate-400 italic font-medium"
                        >
                          Sin áreas asignadas
                        </span>
                      </div>

                      <p
                        class="text-[11px] font-medium text-slate-500 line-clamp-2 w-full mt-1"
                        :title="item.biografia"
                      >
                        {{ item.biografia || "Sin biografía registrada." }}
                      </p>

                      <a
                        v-if="item.url_hv"
                        :href="item.url_hv"
                        target="_blank"
                        class="flex items-center gap-1 text-[10px] font-bold text-blue-500 hover:text-blue-700 hover:underline mt-0.5"
                      >
                        <FileText :size="12" /> Ver Hoja de Vida
                        <ExternalLink :size="10" />
                      </a>
                    </div>
                  </td>

                  <td class="px-6 py-4">
                    <div class="flex flex-col gap-2">
                      <a
                        :href="`mailto:${
                          activeTab === 'equipo' ? item.correo_corporativo : item.correo
                        }`"
                        class="flex items-center gap-2 text-[11px] font-bold text-slate-600 hover:text-primary-vinotinto transition-colors"
                      >
                        <div
                          class="p-1 rounded-md bg-slate-50 border border-slate-200/50 text-slate-400"
                        >
                          <Mail :size="12" />
                        </div>
                        <span class="truncate max-w-[150px]">{{
                          activeTab === "equipo"
                            ? item.correo_corporativo || "Sin correo corp."
                            : item.correo
                        }}</span>
                      </a>

                      <div
                        class="flex items-center gap-2 text-[11px] font-bold text-slate-600"
                      >
                        <div
                          class="p-1 rounded-md bg-slate-50 border border-slate-200/50 text-slate-400"
                        >
                          <Phone :size="12" />
                        </div>
                        <span>{{
                          activeTab === "equipo"
                            ? item.telefono_corporativo || item.telefono_personal
                            : item.telefono
                        }}</span>
                      </div>
                    </div>
                  </td>

                  <td class="px-6 py-4 text-center">
                    <template v-if="activeTab === 'equipo'">
                      <div
                        v-if="item.usuario?.estado_id === 1"
                        class="inline-flex items-center justify-center gap-1.5 px-3 py-1 rounded-full bg-emerald-50 border border-emerald-100/50"
                      >
                        <div
                          class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"
                        ></div>
                        <span
                          class="text-[9px] font-black uppercase tracking-wider text-emerald-700"
                          >Activo</span
                        >
                      </div>
                      <div
                        v-else
                        class="inline-flex items-center justify-center gap-1.5 px-3 py-1 rounded-full bg-slate-50 border border-slate-200/50"
                      >
                        <div class="h-1.5 w-1.5 rounded-full bg-slate-400"></div>
                        <span
                          class="text-[9px] font-black uppercase tracking-wider text-slate-600"
                          >Inactivo</span
                        >
                      </div>
                    </template>

                    <template v-else>
                      <div
                        class="inline-flex items-center justify-center gap-1.5 px-3 py-1 rounded-full bg-emerald-50 border border-emerald-100/50"
                      >
                        <div class="h-1.5 w-1.5 rounded-full bg-emerald-500"></div>
                        <span
                          class="text-[9px] font-black uppercase tracking-wider text-emerald-700"
                          >Disponible</span
                        >
                      </div>
                    </template>
                  </td>

                  <td class="px-8 py-4">
                    <div
                      class="flex items-center justify-end gap-1.5 opacity-60 group-hover:opacity-100 transition-opacity"
                    >
                      <button
                        @click="openEditModal(item)"
                        class="p-2.5 text-slate-400 hover:text-primary-vinotinto hover:bg-blue-50 rounded-xl transition-all"
                        title="Editar perfil"
                      >
                        <Edit3 :size="16" />
                      </button>
                      <button
                        @click="deleteSpeaker(item)"
                        class="p-2.5 text-slate-400 hover:text-primary-vinotinto hover:bg-rose-50 rounded-xl transition-all"
                        title="Eliminar registro"
                      >
                        <Trash2 :size="16" />
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div
            v-if="filteredList.length === 0"
            class="py-24 flex flex-col items-center justify-center text-center px-4"
          >
            <div
              class="h-20 w-20 bg-slate-50 rounded-full flex items-center justify-center mb-4 border border-slate-100"
            >
              <Users :size="32" class="text-slate-300" />
            </div>
            <h4 class="text-base font-black text-slate-700 mb-1">
              No se encontraron resultados
            </h4>
            <p class="text-sm font-medium text-slate-400 max-w-sm">
              Intenta ajustar tu búsqueda o asegúrate de que el nombre esté escrito
              correctamente.
            </p>
          </div>
        </div>
      </main>

      <CreateConferencistaModal
        :show="isSpeakerModalOpen"
        :areas="areas"
        :speaker="selectedSpeaker"
        @close="isSpeakerModalOpen = false"
      />

      <CreateEquipoModal
        :show="isTeamModalOpen"
        :areas="areas"
        :roles="roles"
        :equipos="equipos"
        :tiposDocumento="tiposDocumento"
        :member="selectedTeamMember"
        @close="isTeamModalOpen = false"
      />
    </Sidebar>
  </AuthenticatedLayout>
</template>

<style scoped>
.animate-in {
  animation: slide-up 0.4s ease-out;
}

@keyframes slide-up {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>
