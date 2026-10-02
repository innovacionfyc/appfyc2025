<script setup>
import { ref, computed } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";
import ConfirmacionesPop from "@/Components/Modales/Confirmaciones/ConfirmacionesPop.vue";
import PlantillaModal from "@/Components/CredentialFlow/PlantillaModal.vue";
import EliminarDefinitivamenteModal from "@/Components/CredentialFlow/EliminarDefinitivamenteModal.vue";
import { useConfirmationModal } from "@/Composables/useConfirmationModal";
import { LayoutTemplate, FileText, Trash2, Search, CalendarDays, SquarePen, Type } from "lucide-vue-next";

const props = defineProps({
  plantillas: { type: Array, default: () => [] },
  eliminadas: { type: Array, default: () => [] }, // plantillas eliminadas antes (ocultas)
  stats: { type: Object, default: () => ({}) },
});

const COLOR = "#942934";

const { confirmationState, openConfirmationModal, closeConfirmationModal, handleConfirm } =
  useConfirmationModal();

// ── Modal de creación ─────────────────────────────────────────────────────────
const isModalOpen = ref(false);
const aLiberar = ref(null); // plantilla para «Eliminar definitivamente»

// ── Búsqueda local ────────────────────────────────────────────────────────────
const searchQuery = ref("");
const filtradas = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  if (!q) return props.plantillas;
  return props.plantillas.filter(
    (p) =>
      p.nombre.toLowerCase().includes(q) ||
      (p.descripcion ?? "").toLowerCase().includes(q) ||
      (p.nombre_archivo_original ?? "").toLowerCase().includes(q)
  );
});

const headerStats = computed(() => [
  {
    label: "Plantillas",
    value: props.stats.total ?? 0,
    icon: "description",
    color: "text-primary-vinotinto",
    bg: "bg-primary-vinotinto/10",
  },
]);

const formatFecha = (iso) => {
  if (!iso) return "—";
  const d = new Date(iso);
  if (isNaN(d.getTime())) return "—";
  return new Intl.DateTimeFormat("es-CO", {
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
    timeZone: "America/Bogota",
  }).format(d);
};

// ── Eliminar ──────────────────────────────────────────────────────────────────
const procesando = ref(false);

const eliminar = (plantilla) => {
  openConfirmationModal({
    title: "Eliminar plantilla",
    icon: "delete",
    confirmText: "Eliminar",
    message: `Se eliminará la plantilla "${plantilla.nombre}". No se puede deshacer.`,
    onConfirm: () =>
      router.delete(route("credential-flow.plantillas.destroy", plantilla.id), {
        preserveScroll: true,
        onStart: () => (procesando.value = true),
        onFinish: () => (procesando.value = false),
      }),
  });
};
</script>

<template>
  <Head title="Credential Flow · Plantillas" />

  <AuthenticatedLayout>
    <Sidebar>
      <nav class="mb-3 flex items-center gap-2 text-[12px] font-bold text-slate-400" aria-label="Ruta de navegación">
        <Link :href="route('credential-flow.index')" class="hover:text-primary-vinotinto transition-colors">
          Credential Flow
        </Link>
        <span>/</span>
        <span class="text-slate-600">Plantillas</span>
      </nav>

      <DashboardHeader
        title="Plantillas"
        subtitle="Los diseños que usarás para tus certificados"
        :stats="headerStats"
      >
        <template #actions>
          <BtnUniversal
            label="Nueva plantilla"
            icon="add"
            icon-position="right"
            size="md"
            :activeColor="COLOR"
            class="md:w-auto"
            @click="isModalOpen = true"
          />
        </template>
      </DashboardHeader>

      <!-- Barra de control -->
      <div
        class="mt-8 mb-8 bg-slate-900 p-5 rounded-xl border border-white/5 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-5"
      >
        <div class="flex items-center gap-4 min-w-0">
          <div class="w-12 h-12 bg-primary-vinotinto/20 rounded-2xl flex items-center justify-center border border-primary-vinotinto/30 shrink-0">
            <LayoutTemplate class="w-5 h-5 text-secondary-vinotinto2" />
          </div>
          <div class="min-w-0">
            <h2 class="text-[18px] font-black text-white truncate">Tus plantillas</h2>
            <p class="text-[14px] font-bold text-slate-500">
              {{ filtradas.length }} plantilla{{ filtradas.length !== 1 ? "s" : "" }}
              <span v-if="searchQuery" class="text-slate-600">· filtradas</span>
            </p>
          </div>
        </div>

        <div class="relative w-full md:w-72 group">
          <Search class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600 group-focus-within:text-secondary-vinotinto2 transition-colors" />
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Buscar plantilla..."
            aria-label="Buscar plantillas"
            class="w-full pl-11 pr-4 py-3 bg-white/5 border-none rounded-2xl text-white placeholder:text-slate-600 focus:ring-1 focus:ring-secondary-vinotinto2/40 focus:bg-white/10 transition-all text-xs font-medium"
          />
        </div>
      </div>

      <!-- Estado vacío -->
      <div
        v-if="filtradas.length === 0"
        class="text-center py-20 space-y-4 bg-white rounded-[2rem] border border-dashed border-slate-200"
      >
        <div class="w-20 h-20 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto">
          <LayoutTemplate class="w-9 h-9 text-slate-400" />
        </div>
        <template v-if="searchQuery">
          <p class="text-slate-600 font-bold text-lg">Sin resultados</p>
          <p class="text-slate-400 text-sm">No encontramos plantillas que coincidan con "{{ searchQuery }}".</p>
        </template>
        <template v-else>
          <p class="text-slate-600 font-bold text-lg">Aún no tienes plantillas</p>
          <p class="text-slate-400 text-sm max-w-md mx-auto px-4">
            Una plantilla es el diseño de tus certificados. Crea la primera para empezar.
          </p>
          <div class="pt-2 flex justify-center">
            <BtnUniversal
              label="Crear primera plantilla"
              icon="add"
              icon-position="right"
              size="md"
              :activeColor="COLOR"
              class="sm:w-auto"
              @click="isModalOpen = true"
            />
          </div>
        </template>
      </div>

      <!-- Listado -->
      <div v-else class="grid grid-cols-1 lg:grid-cols-2 2xl:grid-cols-3 gap-5">
        <article
          v-for="p in filtradas"
          :key="p.id"
          class="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6 flex flex-col gap-4 hover:shadow-lg hover:border-slate-200 transition-all duration-300"
        >
          <div class="flex items-start gap-4">
            <div class="w-12 h-12 rounded-2xl bg-primary-vinotinto/10 text-primary-vinotinto flex items-center justify-center shrink-0">
              <LayoutTemplate class="w-6 h-6" />
            </div>
            <div class="min-w-0 flex-1">
              <h3 class="text-lg font-extrabold text-slate-900 tracking-tight break-words">{{ p.nombre }}</h3>
              <p class="mt-1 text-sm font-medium text-slate-500 leading-snug break-words line-clamp-3">
                {{ p.descripcion || "Sin descripción." }}
              </p>
            </div>
            <button
              type="button"
              class="p-2.5 bg-rose-50 text-rose-400 hover:text-rose-600 hover:bg-rose-100 rounded-xl transition-all shrink-0"
              title="Eliminar plantilla"
              :aria-label="`Eliminar la plantilla ${p.nombre}`"
              @click="eliminar(p)"
            >
              <Trash2 class="w-4 h-4" />
            </button>
          </div>

          <dl class="mt-auto pt-4 border-t border-slate-100 grid gap-2 text-[12px] font-semibold text-slate-500">
            <div class="flex items-center gap-2 min-w-0">
              <FileText class="w-4 h-4 text-slate-400 shrink-0" />
              <dt class="sr-only">Archivo original</dt>
              <dd class="truncate" :title="p.nombre_archivo_original">{{ p.nombre_archivo_original }}</dd>
            </div>
            <div class="flex items-center gap-2">
              <CalendarDays class="w-4 h-4 text-slate-400 shrink-0" />
              <dt class="sr-only">Fecha de creación</dt>
              <dd>Creada el {{ formatFecha(p.created_at) }}</dd>
            </div>
            <div class="flex items-center gap-2">
              <Type class="w-4 h-4 text-slate-400 shrink-0" />
              <dt class="sr-only">Elementos del diseño</dt>
              <dd>{{ p.elementos === 0 ? "Aún sin diseño" : `${p.elementos} elemento${p.elementos === 1 ? "" : "s"} en el diseño` }}</dd>
            </div>
          </dl>

          <Link
            :href="route('credential-flow.plantillas.editor', p.id)"
            class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-2xl bg-primary-vinotinto/10 text-sm font-bold text-primary-vinotinto hover:bg-primary-vinotinto/20 transition-all"
          >
            <SquarePen class="w-4 h-4" />
            Editar diseño
          </Link>
          <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 pt-3 border-t border-dashed border-slate-200" data-zona-liberar-espacio>
            <span class="text-[11px] font-semibold text-slate-400">¿Ya no la necesitas? Libera espacio.</span>
            <button type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-slate-200 bg-white text-[12px] font-bold text-rose-600 hover:bg-rose-50 hover:border-rose-200 transition-all" data-accion="eliminar-definitivamente" @click="aLiberar = p">
              <Trash2 class="w-3.5 h-3.5" /> Eliminar definitivamente
            </button>
          </div>
        </article>
      </div>

      <details v-if="eliminadas.length" class="mt-10 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6" data-plantillas-eliminadas>
        <summary class="cursor-pointer text-sm font-extrabold text-slate-700">Plantillas eliminadas anteriormente ({{ eliminadas.length }})</summary>
        <p class="mt-2 text-[13px] font-medium text-slate-500">Estas plantillas ya no aparecen en tu lista, pero siguen guardadas en el servidor. Si no tienen bases ni certificados relacionados, puedes eliminarlas definitivamente para liberar espacio.</p>
        <ul class="mt-4 divide-y divide-slate-100">
          <li v-for="e in eliminadas" :key="e.id" class="flex flex-wrap items-center justify-between gap-3 py-3" :data-eliminada="e.id">
            <div class="min-w-0">
              <p class="font-extrabold text-slate-800 break-words">{{ e.nombre }}</p>
              <p class="text-[12px] font-semibold text-slate-400">Eliminada el {{ formatFecha(e.eliminada_at) }}</p>
            </div>
            <button type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-rose-50 text-[12px] font-bold text-rose-600 hover:bg-rose-100 transition-all" data-accion="liberar-espacio" @click="aLiberar = e">
              <Trash2 class="w-3.5 h-3.5" /> Eliminar definitivamente
            </button>
          </li>
        </ul>
      </details>
    </Sidebar>
  </AuthenticatedLayout>

  <EliminarDefinitivamenteModal :show="aLiberar !== null" tipo="plantilla" :nombre="aLiberar?.nombre ?? ''" :resumen-url="aLiberar ? route('credential-flow.plantillas.eliminacion.resumen', aLiberar.id) : ''" :eliminar-url="aLiberar ? route('credential-flow.plantillas.destroy-definitivo', aLiberar.id) : ''" @close="aLiberar = null" />

  <PlantillaModal :show="isModalOpen" @close="isModalOpen = false" @success="isModalOpen = false" />

  <ConfirmacionesPop
    :is-open="confirmationState.isOpen"
    :title="confirmationState.title"
    :message="confirmationState.message"
    :icon="confirmationState.icon"
    :confirm-text="confirmationState.confirmText"
    :is-loading="procesando"
    @close="closeConfirmationModal"
    @confirm="handleConfirm"
  />
</template>
