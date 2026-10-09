<script setup>
import { reactive } from "vue";
import { Head, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import NavHistorico from "@/Components/CredentialFlow/Historico/NavHistorico.vue";
import Paginador from "@/Components/CredentialFlow/Historico/Paginador.vue";
import TablaCertificados from "@/Components/CredentialFlow/Historico/TablaCertificados.vue";
import { Search, SearchX } from "lucide-vue-next";

const props = defineProps({
  resultados: { type: Object, default: null }, // paginador de Laravel, o null si aún no se buscó
  filtros: { type: Object, required: true },
});

const form = reactive({ campo: props.filtros.campo ?? "nombre", q: props.filtros.q ?? "" });

const AYUDA = {
  nombre: "Escribe al menos 3 letras del nombre.",
  documento: "Escribe el documento completo (con o sin puntos).",
  codigo: "Escribe el código que tenía el certificado (solo números).",
};
const buscar = () => router.get(route("credential-flow.historico.buscar"), { campo: form.campo, q: form.q.trim() }, { preserveScroll: true });

const SELECT = "w-full rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-primary-vinotinto/30 focus:border-primary-vinotinto";
</script>

<template>
  <Head title="Credential Flow · Buscar en el histórico" />

  <AuthenticatedLayout>
    <Sidebar>
      <NavHistorico actual="buscar" :migas="[{ texto: 'Buscar' }]" />

      <DashboardHeader title="Buscar en el histórico" subtitle="Encuentra un certificado anterior por nombre, documento o código. Solo lectura." />

      <form class="mt-6 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-5 grid grid-cols-1 sm:grid-cols-4 gap-4" data-form-buscar @submit.prevent="buscar">
        <label class="block">
          <span class="sr-only">Buscar por</span>
          <select v-model="form.campo" :class="SELECT" data-campo>
            <option value="nombre">Nombre</option>
            <option value="documento">Documento</option>
            <option value="codigo">Código legado</option>
          </select>
        </label>
        <label class="relative block sm:col-span-2">
          <span class="sr-only">Texto a buscar</span>
          <Search class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
          <input v-model="form.q" type="search" maxlength="80" :placeholder="AYUDA[form.campo]" :class="[SELECT, 'pl-10']" data-q autocomplete="off" />
        </label>
        <button type="submit" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90 transition-all">Buscar</button>
      </form>
      <p class="mt-2 px-2 text-[12px] font-semibold text-slate-400">{{ AYUDA[form.campo] }} Para buscar un evento usa el listado de eventos.</p>

      <div v-if="resultados === null" class="mt-8 text-center py-16 bg-white rounded-[2rem] border border-dashed border-slate-200 text-slate-400 font-semibold" data-sin-busqueda>
        Escribe algo para buscar.
      </div>
      <div v-else-if="resultados.data.length === 0" class="mt-8 text-center py-16 space-y-3 bg-white rounded-[2rem] border border-dashed border-slate-200" data-sin-resultados>
        <div class="w-16 h-16 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto"><SearchX class="w-8 h-8 text-slate-400" /></div>
        <p class="text-slate-600 font-bold text-lg">No encontramos certificados</p>
        <p class="text-slate-400 text-sm max-w-md mx-auto px-4">Revisa el dato o prueba con otro criterio de búsqueda.</p>
      </div>
      <div v-else class="mt-6 space-y-4">
        <p class="px-2 text-[13px] font-bold text-slate-500">{{ resultados.total.toLocaleString("es-CO") }} {{ resultados.total === 1 ? "resultado" : "resultados" }}<span v-if="filtros.campo === 'codigo' && resultados.total > 1"> · un mismo código puede tener varias filas (duplicados históricos)</span></p>
        <TablaCertificados :filas="resultados.data" mostrar-evento />
        <Paginador :paginador="resultados" unidad="resultados" />
      </div>
    </Sidebar>
  </AuthenticatedLayout>
</template>
