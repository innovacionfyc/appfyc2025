<script setup>
import { Head } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import NavEncuestas from "@/Components/CredentialFlow/Encuestas/NavEncuestas.vue";
import FiltrosEncuestas from "@/Components/CredentialFlow/Encuestas/FiltrosEncuestas.vue";
import Paginador from "@/Components/CredentialFlow/Historico/Paginador.vue";
import Insignia from "@/Components/CredentialFlow/Historico/Insignia.vue";
import { ShieldAlert, MessageSquareText } from "lucide-vue-next";

// Vista RESTRINGIDA (solo super-admin): comentarios completos de las encuestas. Cada consulta deja un registro de acceso sin el contenido.
// No muestra documentos, correos ni identificadores de la persona. El texto se imprime siempre escapado.
defineProps({
  filtros: { type: Object, required: true },
  opciones: { type: Object, required: true },
  comentarios: { type: Object, required: true }, // paginador de Laravel
});
const fecha = (v) => (v ? String(v).slice(0, 10) : "—");
const CAJA = "bg-white rounded-[2rem] border border-slate-100 shadow-sm";
</script>

<template>
  <Head title="Credential Flow · Comentarios de encuestas" />

  <AuthenticatedLayout>
    <Sidebar>
      <NavEncuestas actual="comentarios" :filtros="filtros" :puede-comentarios="true" :migas="[{ texto: 'Comentarios' }]" />

      <DashboardHeader title="Comentarios" subtitle="Texto libre de las encuestas. Acceso restringido: cada consulta queda registrada, sin el contenido." />

      <div class="mt-6 mb-6 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-800" role="note" data-aviso-restringido>
        <ShieldAlert class="w-5 h-5 shrink-0" /> Información sensible. Los comentarios no se incluyen en las exportaciones y no deben copiarse fuera del sistema sin necesidad.
      </div>

      <FiltrosEncuestas :filtros="filtros" :opciones="opciones" :url="() => route('credential-flow.encuestas.comentarios')" :campos="['q', 'version', 'origen', 'resp_fechas']" />

      <section :class="[CAJA, 'p-4 sm:p-6']" data-lista-comentarios>
        <div v-if="comentarios.data.length === 0" class="text-center py-12 space-y-3" data-sin-resultados>
          <div class="w-14 h-14 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto"><MessageSquareText class="w-7 h-7 text-slate-400" /></div>
          <p class="text-slate-600 font-bold">No hay comentarios para estos filtros</p>
        </div>
        <ul v-else class="divide-y divide-slate-100">
          <li v-for="c in comentarios.data" :key="c.id" class="py-4" :data-comentario="c.id">
            <div class="flex flex-wrap items-center gap-2 mb-1">
              <span class="font-black text-slate-800 break-words">{{ c.evento }}</span>
              <Insignia :etiqueta="'Versión ' + c.version" tono="slate" />
              <span class="text-[12px] font-bold text-slate-400">{{ fecha(c.fecha) }}</span>
            </div>
            <p class="text-[12px] font-semibold text-slate-500 mb-1">{{ c.pregunta }}</p>
            <p class="text-sm font-medium text-slate-700 whitespace-pre-line break-words">{{ c.comentario }}</p>
          </li>
        </ul>
      </section>

      <div class="mt-4"><Paginador :paginador="comentarios" unidad="comentarios" /></div>
    </Sidebar>
  </AuthenticatedLayout>
</template>
