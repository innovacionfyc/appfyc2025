<script setup>
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import { LayoutTemplate, Users, Sparkles, History, Archive, Mail, ArrowRight } from "lucide-vue-next";

// Los pasos del módulo. Plantillas y Bases de participantes tienen su propia pantalla; Generación e Historial no:
// se hacen dentro de cada base, así que esas tarjetas llevan a la lista de bases y lo dicen.
const pasos = [
  {
    titulo: "Plantillas",
    descripcion: "Prepara el diseño que usarás para tus certificados.",
    ruta: "credential-flow.plantillas.index",
    accion: "Abrir plantillas",
    icono: LayoutTemplate,
    acento: "text-primary-vinotinto bg-primary-vinotinto/10",
  },
  {
    titulo: "Bases de participantes",
    descripcion: "Carga la lista de participantes de un evento y revisa que sus datos estén correctos.",
    ruta: "credential-flow.lotes.index",
    accion: "Abrir bases",
    icono: Users,
    acento: "text-primary-naranja bg-primary-naranja/10",
  },
  {
    titulo: "Generación",
    descripcion: "Genera certificados uno a uno o todos de una vez, y descárgalos. Se hace dentro de cada base.",
    ruta: "credential-flow.lotes.index",
    accion: "Elegir una base",
    icono: Sparkles,
    acento: "text-primary-verde bg-primary-verde/10",
  },
  {
    titulo: "Historial",
    descripcion: "Consulta los certificados emitidos y sus versiones anteriores. Está al final de cada base.",
    ruta: "credential-flow.lotes.index",
    accion: "Elegir una base",
    icono: History,
    acento: "text-primary-gris bg-primary-gris/10",
  },
  {
    titulo: "Histórico",
    descripcion: "Consulta los certificados de las evaluaciones anteriores: eventos, personas, plantillas y descargas. Solo lectura.",
    ruta: "credential-flow.historico.index",
    accion: "Abrir histórico",
    icono: Archive,
    acento: "text-slate-600 bg-slate-100",
  },
  {
    titulo: "Envíos",
    descripcion: "Revisa los códigos de acceso enviados por correo y si el servidor de correo los aceptó. Solo lectura.",
    ruta: "credential-flow.envios.index",
    accion: "Abrir envíos",
    icono: Mail,
    acento: "text-sky-700 bg-sky-50",
  },
];
</script>

<template>
  <Head title="Credential Flow" />

  <AuthenticatedLayout>
    <Sidebar>
      <DashboardHeader
        title="F&C Credential Flow"
        subtitle="Diseña, genera y consulta los certificados de tus eventos"
      />

      <div class="mt-8 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
        <Link
          v-for="(paso, i) in pasos"
          :key="paso.titulo"
          :href="route(paso.ruta)"
          class="relative bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6 flex flex-col gap-4 hover:shadow-lg hover:border-slate-200 transition-all duration-300 group"
          :data-paso="paso.titulo"
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

          <span class="mt-auto self-start inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-primary-vinotinto/10 text-[11px] font-bold text-primary-vinotinto">
            {{ paso.accion }}
            <ArrowRight class="w-3.5 h-3.5 transition-transform group-hover:translate-x-0.5" />
          </span>
        </Link>
      </div>
    </Sidebar>
  </AuthenticatedLayout>
</template>
