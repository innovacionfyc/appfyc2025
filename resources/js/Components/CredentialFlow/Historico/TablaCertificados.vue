<script setup>
import { Link } from "@inertiajs/vue3";
import Insignia from "@/Components/CredentialFlow/Historico/Insignia.vue";
import { ArrowRight, Mail, Download, Layers } from "lucide-vue-next";

// Tabla de certificados históricos (SOLO LECTURA). Privacidad: el documento llega ENMASCARADO y el correo es solo un indicador;
// no hay acciones de edición, borrado, conciliación ni generación: únicamente «Ver».
defineProps({
  filas: { type: Array, required: true },
  mostrarEvento: { type: Boolean, default: false },
});
</script>

<template>
  <div class="overflow-x-auto bg-white rounded-[2rem] border border-slate-100 shadow-sm">
    <table class="min-w-full text-sm" data-tabla-certificados>
      <thead class="text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
        <tr>
          <th class="text-left font-black px-6 py-4">Nombre</th>
          <th v-if="mostrarEvento" class="text-left font-black px-4 py-4">Evento</th>
          <th class="text-left font-black px-4 py-4">Documento</th>
          <th class="text-left font-black px-4 py-4">Conciliación</th>
          <th class="text-left font-black px-4 py-4">Código</th>
          <th class="text-left font-black px-4 py-4">Correo</th>
          <th class="text-right font-black px-4 py-4">Descargas</th>
          <th class="text-left font-black px-4 py-4">Estado</th>
          <th class="px-6 py-4"><span class="sr-only">Acciones</span></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <tr v-for="c in filas" :key="c.id" class="hover:bg-slate-50/60 transition-colors" :data-certificado="c.id">
          <td class="px-6 py-4 font-extrabold text-slate-900 break-words max-w-xs">
            {{ c.nombre || "—" }}
            <Layers v-if="c.duplicado" class="inline w-3.5 h-3.5 ml-1 text-sky-500" title="Pertenece a un grupo duplicado histórico" aria-label="Pertenece a un grupo duplicado histórico" />
          </td>
          <td v-if="mostrarEvento" class="px-4 py-4 text-slate-600 font-semibold break-words max-w-[14rem]">
            <Link :href="route('credential-flow.historico.eventos.show', c.evento.id)" class="hover:text-primary-vinotinto">{{ c.evento.nombre }}</Link>
            <span class="block text-[11px] text-slate-400">{{ c.evento.anio_etiqueta }}</span>
          </td>
          <td class="px-4 py-4 text-slate-600 font-semibold whitespace-nowrap"><span class="text-slate-400">{{ c.tipo_documento ?? "" }}</span> <span class="font-mono">{{ c.documento }}</span></td>
          <td class="px-4 py-4"><Insignia :etiqueta="c.conciliacion.etiqueta" :tono="c.conciliacion.tono" /></td>
          <td class="px-4 py-4 text-slate-600 font-mono font-semibold">{{ c.codigo_legado ?? "—" }}</td>
          <td class="px-4 py-4 text-slate-600 font-semibold whitespace-nowrap"><span class="inline-flex items-center gap-1.5"><Mail class="w-3.5 h-3.5 text-slate-400" />{{ c.correo }}</span></td>
          <td class="px-4 py-4 text-right font-semibold text-slate-600"><span class="inline-flex items-center gap-1.5"><Download class="w-3.5 h-3.5 text-slate-400" />{{ c.descargas }}</span></td>
          <td class="px-4 py-4 text-slate-600 font-semibold">{{ c.estado }}</td>
          <td class="px-6 py-4 text-right">
            <Link :href="route('credential-flow.historico.certificados.show', c.id)" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-primary-vinotinto/10 text-[12px] font-bold text-primary-vinotinto hover:bg-primary-vinotinto/20 transition-all" :aria-label="`Ver el certificado de ${c.nombre}`">
              Ver <ArrowRight class="w-3.5 h-3.5" />
            </Link>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
