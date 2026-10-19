<script setup>
import { computed, reactive } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import { CalendarDays, ArrowRight, Link2, History, AlertTriangle } from "lucide-vue-next";

const nuevo = reactive({ nombre: "", fecha: "" });
const crearEvento = (datos = {}) => router.post(route("credential-flow.eventos.store"), { ...nuevo, ...datos }, { preserveScroll: true });

const props = defineProps({
  eventos: { type: Array, default: () => [] }, // eventos nativos con su resumen (reales vs QA)
  sin_evento: { type: Array, default: () => [] }, // bases que aún no pertenecen a ningún evento, con la propuesta de asociación (unir | crear | ambigua)
  historico: { type: Object, default: () => ({ eventos: 0, certificados: 0 }) }, // resumen del módulo Histórico (solo lectura)
});

// Elección del administrador cuando la propuesta es ambigua: evento existente al que unir la base.
const elegido = reactive({});

const headerStats = computed(() => [
  { label: "Eventos", value: props.eventos.length, icon: "event", color: "text-primary-vinotinto", bg: "bg-primary-vinotinto/10" },
  { label: "Bases sin evento", value: props.sin_evento.length, icon: "groups", color: "text-amber-600", bg: "bg-amber-50" },
]);

const asociar = (base, datos = {}) => router.post(route("credential-flow.lotes.evento.desde-base", base.id), datos, { preserveScroll: true });
const unirAlElegido = (base) => elegido[base.id] && asociar(base, { evento_id: elegido[base.id] });
const confirmarNuevo = (base) => asociar(base, { crear_nuevo: true });
</script>

<template>
  <Head title="Credential Flow · Eventos" />

  <AuthenticatedLayout>
    <Sidebar>
      <nav class="mb-3 flex flex-wrap items-center gap-x-2 pr-14 md:pr-0 text-[12px] font-bold text-slate-400" aria-label="Ruta de navegación">
        <Link :href="route('credential-flow.index')" class="hover:text-primary-vinotinto transition-colors">Credential Flow</Link>
        <span>/</span>
        <span class="text-slate-600">Eventos</span>
      </nav>

      <DashboardHeader title="Eventos" subtitle="Agrupa las bases de un mismo evento (presencial, virtual…) y descarga sus certificados" :stats="headerStats" />

      <section class="mt-8 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-4 sm:p-6" data-nuevo-evento>
        <h2 class="text-sm font-extrabold text-slate-700">1. Crear un evento</h2>
        <p class="mt-1 text-[13px] font-medium text-slate-500">Primero el evento y luego sus participantes. Si ya existe uno igual (mismo nombre y año), se abre ese: no se duplica por modalidad ni por volver a importar.</p>
        <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="crearEvento()">
          <label class="min-w-0 flex-1 basis-64 text-[12px] font-bold text-slate-500">Nombre del evento
            <input v-model="nuevo.nombre" type="text" maxlength="255" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700" data-campo="nuevo-nombre" />
          </label>
          <label class="min-w-0 basis-56 text-[12px] font-bold text-slate-500">Fecha
            <input v-model="nuevo.fecha" type="text" maxlength="120" placeholder="16 de octubre de 2026" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700" data-campo="nuevo-fecha" />
          </label>
          <button type="submit" :disabled="!nuevo.nombre.trim() || !nuevo.fecha.trim()" class="px-4 py-2 rounded-full bg-primary-vinotinto text-white text-[12px] font-bold disabled:opacity-40" data-accion="crear-evento">Crear evento</button>
          <button type="button" :disabled="!nuevo.nombre.trim() || !nuevo.fecha.trim()" class="px-4 py-2 rounded-full bg-white border border-slate-200 text-[12px] font-bold text-slate-700 disabled:opacity-40" data-accion="crear-evento-confirmado" @click="crearEvento({ crear_nuevo: true })">Es un evento distinto, crear de todos modos</button>
        </form>
      </section>

      <div v-if="eventos.length === 0" class="mt-8 text-center py-16 bg-white rounded-[2rem] border border-dashed border-slate-200" data-sin-eventos>
        <p class="text-slate-600 font-bold text-lg">Aún no hay eventos creados desde Credential Flow</p>
        <p class="text-slate-400 text-sm max-w-md mx-auto px-4 mt-2">Asocia una base de la lista de abajo y se creará su evento.</p>
      </div>

      <div v-else class="relative mt-8 overflow-x-auto bg-white rounded-[2rem] border border-slate-100 shadow-sm">
        <table class="min-w-full text-sm" data-tabla-eventos>
          <thead class="text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
            <tr>
              <th class="text-left font-black px-6 py-4">Evento</th>
              <th class="text-left font-black px-4 py-4">Fecha</th>
              <th class="text-right font-black px-4 py-4">Bases</th>
              <th class="text-right font-black px-4 py-4">Participantes</th>
              <th class="text-right font-black px-4 py-4">Certificados vigentes</th>
              <th class="text-right font-black px-4 py-4">Revocados</th>
              <th class="text-right font-black px-4 py-4">Pendientes</th>
              <th class="text-right font-black px-4 py-4">Control (QA)</th>
              <th class="px-6 py-4"><span class="sr-only">Acciones</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="e in eventos" :key="e.id" class="hover:bg-slate-50/60 transition-colors" :data-evento="e.id">
              <td class="px-6 py-4 font-extrabold text-slate-900 break-words max-w-xs">{{ e.nombre }}</td>
              <td class="px-4 py-4 text-slate-600 font-semibold"><span class="inline-flex items-center gap-1.5"><CalendarDays class="w-3.5 h-3.5 text-slate-400" />{{ e.fecha_texto ?? e.anio ?? "—" }}</span></td>
              <td class="px-4 py-4 text-right font-black text-slate-900">{{ e.bases }}</td>
              <td class="px-4 py-4 text-right font-black text-slate-900">{{ e.reales.participantes }}</td>
              <td class="px-4 py-4 text-right font-black text-emerald-700">{{ e.reales.vigentes }}</td>
              <td class="px-4 py-4 text-right font-black text-rose-600">{{ e.reales.revocadas }}</td>
              <td class="px-4 py-4 text-right font-black text-amber-600">{{ e.reales.pendientes }}</td>
              <td class="px-4 py-4 text-right text-slate-500 font-semibold">{{ e.qa.participantes }}</td>
              <td class="px-6 py-4 text-right">
                <Link :href="route('credential-flow.lotes.nuevo', { evento: e.id })" class="mr-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-white border border-slate-200 text-[12px] font-bold text-slate-700 hover:bg-slate-50" data-accion="importar-participantes">Importar participantes</Link>
                <Link :href="route('credential-flow.eventos.show', e.id)" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-primary-vinotinto/10 text-[12px] font-bold text-primary-vinotinto hover:bg-primary-vinotinto/20 transition-all">
                  Abrir <ArrowRight class="w-3.5 h-3.5" />
                </Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <section v-if="sin_evento.length" class="mt-10 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-4 sm:p-6" data-bases-sin-evento>
        <h2 class="text-sm font-extrabold text-slate-700">Bases que aún no pertenecen a ningún evento</h2>
        <p class="mt-1 text-[13px] font-medium text-slate-500">Solo se asocia sola cuando no hay duda. Si el evento puede ser otro, tú eliges o confirmas. Nunca se cambia un certificado emitido.</p>
        <ul class="mt-4 divide-y divide-slate-100">
          <li v-for="b in sin_evento" :key="b.id" class="py-4 space-y-3" :data-base="b.id">
            <div class="min-w-0">
              <p class="font-extrabold text-slate-800 break-words">{{ b.nombre }}</p>
              <p class="text-[12px] font-semibold text-slate-400 break-words">
                {{ b.evento ?? "Sin nombre de evento" }}<template v-if="b.fecha"> · {{ b.fecha }}</template> · {{ b.participantes }} {{ b.participantes === 1 ? "participante" : "participantes" }}
              </p>
            </div>

            <p v-if="!b.propuesta" class="text-[12px] font-semibold text-slate-400">La base no tiene nombre de evento: edítala para poder asociarla.</p>

            <div v-else-if="b.propuesta.accion !== 'ambigua'" class="flex flex-wrap items-center gap-3">
              <span class="text-[12px] font-semibold text-slate-500 break-words min-w-0">
                {{ b.propuesta.accion === "unir" ? `Se unirá al evento «${b.propuesta.evento}»` : "Se creará un evento nuevo con el nombre de la base" }}
              </span>
              <button type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-primary-vinotinto/10 text-[12px] font-bold text-primary-vinotinto hover:bg-primary-vinotinto/20 transition-all" data-accion="asociar-evento" @click="asociar(b)">
                <Link2 class="w-3.5 h-3.5" /> {{ b.propuesta.accion === "unir" ? "Unir" : "Crear y asociar" }}
              </button>
            </div>

            <div v-else class="rounded-2xl bg-amber-50 border border-amber-100 p-3 space-y-3" data-ambigua>
              <p v-for="m in b.propuesta.motivos" :key="m" class="flex items-start gap-2 text-[12px] font-bold text-amber-700"><AlertTriangle class="w-4 h-4 shrink-0 mt-px" /><span>{{ m }}</span></p>
              <div class="flex flex-wrap items-center gap-2">
                <select v-model="elegido[b.id]" class="min-w-0 max-w-full rounded-xl border border-slate-200 bg-white text-sm font-semibold text-slate-700 px-3 py-2" data-campo="evento-existente">
                  <option :value="undefined">Elegir un evento existente…</option>
                  <option v-for="e in eventos" :key="e.id" :value="e.id">{{ e.nombre }} · {{ e.fecha_texto ?? e.anio ?? "s/f" }}</option>
                </select>
                <button type="button" :disabled="!elegido[b.id]" class="px-3 py-2 rounded-full bg-primary-vinotinto text-white text-[12px] font-bold disabled:opacity-40" data-accion="unir-elegido" @click="unirAlElegido(b)">Unir al elegido</button>
                <button type="button" class="px-3 py-2 rounded-full bg-white border border-slate-200 text-[12px] font-bold text-slate-700 hover:bg-slate-50" data-accion="confirmar-nuevo" @click="confirmarNuevo(b)">Es un evento nuevo</button>
              </div>
            </div>
          </li>
        </ul>
      </section>

      <section class="mt-10 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-4 sm:p-6" data-historico>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="min-w-0">
            <h2 class="flex items-center gap-2 text-sm font-extrabold text-slate-700"><History class="w-4 h-4 text-slate-400" /> Certificados históricos</h2>
            <p class="mt-1 text-[13px] font-medium text-slate-500">{{ historico.eventos }} eventos y {{ historico.certificados }} certificados de años anteriores, en modo consulta. Cada evento histórico permite descargar un ZIP con sus PDF ya generados y abrir cada PDF disponible.</p>
            <p class="mt-1 text-[12px] font-semibold text-amber-700">Fuentes separadas: el ZIP de un evento de esta página reúne solo certificados nuevos; el ZIP de los históricos se descarga desde su evento en el Histórico.</p>
          </div>
          <Link :href="route('credential-flow.historico.index')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-700 hover:bg-slate-50" data-accion="ver-historico">
            Ver histórico <ArrowRight class="w-4 h-4" />
          </Link>
        </div>
      </section>
    </Sidebar>
  </AuthenticatedLayout>
</template>
