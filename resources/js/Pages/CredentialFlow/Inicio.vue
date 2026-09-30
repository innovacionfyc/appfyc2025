<script setup>
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import { LayoutTemplate, Users, Sparkles, History, Construction, ArrowRight } from "lucide-vue-next";

// Presentación del flujo. "Plantillas" y "Participantes" están disponibles (tienen `ruta`); el resto es informativo.
const pasos = [
  {
    titulo: "Plantillas",
    descripcion: "Un PDF base con los elementos del certificado colocados a tu medida, listo para reutilizar.",
    ruta: "credential-flow.plantillas.index",
    icono: LayoutTemplate,
    acento: "text-primary-vinotinto bg-primary-vinotinto/10",
  },
  {
    titulo: "Participantes",
    descripcion: "Importa la lista de asistentes desde un archivo CSV o Excel y revisa sus datos antes de generar.",
    ruta: "credential-flow.lotes.index",
    icono: Users,
    acento: "text-primary-naranja bg-primary-naranja/10",
  },
  {
    titulo: "Generación",
    descripcion: "Crea certificados individuales o por lote y descárgalos en un solo archivo ZIP.",
    icono: Sparkles,
    acento: "text-primary-verde bg-primary-verde/10",
  },
  {
    titulo: "Historial",
    descripcion: "Consulta los lotes generados anteriormente y vuelve a usar tus plantillas.",
    icono: History,
    acento: "text-primary-gris bg-primary-gris/10",
  },
];

const headerStats = [
  { label: "Estado del módulo", value: "En construcción", icon: "construction", color: "text-amber-500", bg: "bg-amber-50" },
];
</script>

<template>
  <Head title="Credential Flow" />

  <AuthenticatedLayout>
    <Sidebar>
      <DashboardHeader
        title="F&C Credential Flow"
        subtitle="Gestión y generación de certificados"
        :stats="headerStats"
      />

      <!-- Aviso de módulo en construcción -->
      <div
        class="mt-8 mb-8 bg-slate-900 p-5 rounded-xl border border-white/5 shadow-xl flex items-center gap-4"
      >
        <div
          class="w-12 h-12 bg-primary-naranja/15 rounded-2xl flex items-center justify-center border border-primary-naranja/20 shrink-0"
        >
          <Construction class="w-5 h-5 text-primary-naranja" />
        </div>
        <div class="min-w-0">
          <h2 class="text-[18px] font-black text-white">Módulo en construcción</h2>
          <p class="text-[14px] font-bold text-slate-500">
            Estamos preparando las herramientas para diseñar y generar certificados desde el panel.
          </p>
        </div>
      </div>

      <!-- Flujo futuro -->
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
        <component
          :is="paso.ruta ? Link : 'article'"
          v-for="(paso, i) in pasos"
          :key="paso.titulo"
          :href="paso.ruta ? route(paso.ruta) : undefined"
          :class="[
            'relative bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6 flex flex-col gap-4',
            paso.ruta ? 'hover:shadow-lg hover:border-slate-200 transition-all duration-300 group' : '',
          ]"
        >
          <div class="flex items-center justify-between">
            <div :class="['w-12 h-12 rounded-2xl flex items-center justify-center', paso.acento]">
              <component :is="paso.icono" class="w-6 h-6" />
            </div>
            <span class="text-[12px] font-black text-slate-300">0{{ i + 1 }}</span>
          </div>

          <div>
            <h3 class="text-lg font-extrabold text-slate-900 tracking-tight">{{ paso.titulo }}</h3>
            <p class="mt-1.5 text-sm font-medium text-slate-500 leading-snug">{{ paso.descripcion }}</p>
          </div>

          <span
            v-if="paso.ruta"
            class="mt-auto self-start inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-primary-vinotinto/10 text-[11px] font-bold text-primary-vinotinto"
          >
            Abrir
            <ArrowRight class="w-3.5 h-3.5 transition-transform group-hover:translate-x-0.5" />
          </span>
          <span
            v-else
            class="mt-auto self-start px-3 py-1 rounded-full bg-slate-100 text-[11px] font-bold text-slate-400"
          >
            Próximamente
          </span>
        </component>
      </div>
    </Sidebar>
  </AuthenticatedLayout>
</template>
