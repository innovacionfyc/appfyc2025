<script setup>
import { Head, Link, router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import NavHistorico from "@/Components/CredentialFlow/Historico/NavHistorico.vue";
import Insignia from "@/Components/CredentialFlow/Historico/Insignia.vue";
import { ClipboardList, Search } from "lucide-vue-next";

// Encuestas históricas: SOLO LECTURA (todo son enlaces GET). Conteos por pregunta/opción; nunca se muestra el texto libre que escribieron las personas.
const props = defineProps({
  resumen: { type: Object, required: true },
  versiones: { type: Array, default: () => [] },
  claves: { type: Array, default: () => [] },
  sin_pregunta: { type: Array, default: () => [] },
  eventos: { type: Array, default: () => [] },
  evento: { type: Object, default: null },
  filtro: { type: String, default: "todos" }, // todos | evento | no_disponible
  filtros: { type: Object, default: () => ({ q: "" }) },
});

const n = (v) => (v ?? 0).toLocaleString("es-CO");
const fecha = (v) => (v ? String(v).slice(0, 10) : "—");
const q = ref(props.filtros.q ?? "");
const ir = (params = {}) => router.get(route("credential-flow.historico.encuestas"), params, { preserveState: true, preserveScroll: true, replace: true });
const buscar = () => ir(q.value ? { q: q.value } : {});

const CLASES = {
  vinculada: { etiqueta: "Ligadas a un certificado", tono: "emerald" },
  participante_ambiguo: { etiqueta: "Participante ambiguo", tono: "amber" },
  participante_no_encontrado: { etiqueta: "Participante no encontrado", tono: "amber" },
  evento_historico_eliminado: { etiqueta: "Evento histórico no disponible", tono: "slate" },
};
const TIPOS = { opcion_unica: "Opción única", opcion_multiple: "Opción múltiple", texto: "Texto libre", escala: "Escala" };
const maximo = (p) => Math.max(1, ...p.opciones.map((o) => o.conteo));
const huerfanas = computed(() => Object.entries(props.resumen.clasificacion ?? {}).filter(([k]) => k !== "vinculada").reduce((a, [, c]) => a + c, 0));
const CAJA = "bg-white rounded-[2rem] border border-slate-100 shadow-sm";
</script>

<template>
  <Head title="Credential Flow · Encuestas históricas" />

  <AuthenticatedLayout>
    <Sidebar>
      <NavHistorico actual="encuestas" :migas="[{ texto: 'Encuestas' }]" />

      <DashboardHeader title="Encuestas históricas" subtitle="Lo que respondieron las personas en el sistema anterior. Solo lectura: aquí se ven conteos, nunca los textos escritos." />

      <div v-if="!resumen.hay" class="mt-6 text-center py-16 space-y-3 bg-white rounded-[2rem] border border-dashed border-slate-200" data-sin-encuestas>
        <div class="w-16 h-16 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto"><ClipboardList class="w-8 h-8 text-slate-400" /></div>
        <p class="text-slate-600 font-bold text-lg">Todavía no se han migrado encuestas históricas</p>
      </div>

      <template v-else>
        <section class="mt-6 grid grid-cols-2 lg:grid-cols-3 gap-4" data-resumen-encuestas>
          <div :class="[CAJA, 'p-5']"><p class="text-[11px] uppercase tracking-wide font-black text-slate-400">Respuestas</p><p class="text-3xl font-black text-slate-900" data-total="respuestas">{{ n(resumen.respuestas) }}</p></div>
          <div :class="[CAJA, 'p-5']"><p class="text-[11px] uppercase tracking-wide font-black text-slate-400">Versiones detectadas</p><p class="text-3xl font-black text-slate-900" data-total="versiones">{{ n(resumen.versiones) }}</p></div>
          <div :class="[CAJA, 'p-5']"><p class="text-[11px] uppercase tracking-wide font-black text-slate-400">Preguntas (entre versiones)</p><p class="text-3xl font-black text-slate-900" data-total="preguntas">{{ n(resumen.preguntas) }}</p></div>
          <div :class="[CAJA, 'p-5']"><p class="text-[11px] uppercase tracking-wide font-black text-slate-400">Opciones</p><p class="text-3xl font-black text-slate-900" data-total="opciones">{{ n(resumen.opciones) }}</p></div>
          <div :class="[CAJA, 'p-5']"><p class="text-[11px] uppercase tracking-wide font-black text-slate-400">Respuestas por pregunta</p><p class="text-3xl font-black text-slate-900" data-total="detalles">{{ n(resumen.detalles) }}</p></div>
          <div :class="[CAJA, 'p-5']"><p class="text-[11px] uppercase tracking-wide font-black text-slate-400">Periodo</p><p class="text-lg font-black text-slate-900" data-total="periodo">{{ fecha(resumen.desde) }} → {{ fecha(resumen.hasta) }}</p></div>
        </section>

        <section class="mt-4" data-clasificacion>
          <div class="flex flex-wrap gap-2">
            <span v-for="(cant, clave) in resumen.clasificacion" :key="clave" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full border border-slate-200 bg-white text-[12px] font-bold text-slate-600" :data-clase="clave">
              <Insignia :etiqueta="n(cant)" :tono="CLASES[clave]?.tono ?? 'slate'" /> {{ CLASES[clave]?.etiqueta ?? clave }}
            </span>
          </div>
          <p class="mt-2 text-[12px] font-semibold text-slate-500">
            {{ n(resumen.clasificacion.vinculada ?? 0) }} respuestas están ligadas a un certificado histórico; {{ n(huerfanas) }} no (huérfanas). Los
            {{ n(resumen.eventos_con_respuestas) }} eventos con respuestas siguen existiendo; los otros {{ n(resumen.eventos_no_disponibles) }} eventos del sistema anterior ya no están disponibles.
          </p>
        </section>

        <section :class="[CAJA, 'mt-8 p-6']" data-filtro-evento>
          <h2 class="text-lg font-black text-slate-900">Filtrar por evento</h2>
          <p class="text-sm font-semibold text-slate-500 mb-4">Los conteos de las versiones de abajo se limitan a lo que elijas.</p>
          <div class="flex flex-wrap gap-2 mb-4">
            <Link :href="route('credential-flow.historico.encuestas')" :class="['px-4 py-2 rounded-full text-[13px] font-bold', filtro === 'todos' ? 'bg-primary-vinotinto text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50']" data-opcion="todos">Todos los eventos</Link>
            <Link :href="route('credential-flow.historico.encuestas', { evento: 'no_disponible' })" :class="['px-4 py-2 rounded-full text-[13px] font-bold', filtro === 'no_disponible' ? 'bg-primary-vinotinto text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50']" data-opcion="no_disponible">
              Evento histórico no disponible ({{ n(resumen.respuestas_evento_no_disponible) }})
            </Link>
          </div>
          <p v-if="filtro === 'evento' && evento" class="mb-3 text-sm font-bold text-primary-vinotinto" data-evento-actual>Mostrando solo el evento «{{ evento.nombre }}».</p>
          <p v-if="filtro === 'no_disponible'" class="mb-3 text-sm font-bold text-primary-vinotinto" data-evento-actual>Mostrando las respuestas de eventos que ya no existen en el sistema. Solo se conserva su identificador anterior.</p>
          <form class="relative block max-w-md mb-3" @submit.prevent="buscar">
            <Search class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
            <input v-model="q" type="search" maxlength="80" placeholder="Buscar evento…" class="w-full rounded-2xl border border-slate-200 bg-white pl-10 pr-3 py-2.5 text-sm font-semibold text-slate-700" data-filtro="q" @change="buscar" />
          </form>
          <ul class="divide-y divide-slate-100">
            <li v-for="e in eventos" :key="e.id" class="py-2.5 flex items-center justify-between gap-3" :data-evento="e.id">
              <Link :href="route('credential-flow.historico.encuestas', { evento: e.id })" class="font-bold text-slate-700 hover:text-primary-vinotinto break-words">{{ e.nombre }} <span class="text-slate-400">· {{ e.anio ?? "sin año" }}</span></Link>
              <Insignia :etiqueta="n(e.respuestas) + ' resp.'" tono="sky" />
            </li>
            <li v-if="eventos.length === 0" class="py-4 text-sm font-semibold text-slate-400">Sin eventos con respuestas.</li>
          </ul>
        </section>

        <section :class="[CAJA, 'mt-8 p-6 overflow-x-auto']" data-resumen-preguntas>
          <h2 class="text-lg font-black text-slate-900">Resumen por pregunta</h2>
          <p class="text-sm font-semibold text-slate-500 mb-4">Cómo se contestó cada pregunta a lo largo de las versiones.</p>
          <table class="min-w-full text-sm">
            <thead class="text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
              <tr><th class="text-left font-black py-3 pr-4">Pregunta</th><th class="text-left font-black py-3 pr-4">Tipo</th><th class="text-left font-black py-3 pr-4">Versiones</th><th class="text-right font-black py-3 pr-4">Contestadas</th><th class="text-right font-black py-3">Sin respuesta</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="c in claves" :key="c.clave" :data-clave="c.clave">
                <td class="py-3 pr-4 font-bold text-slate-800">{{ c.clave }}
                  <span v-if="c.parcial" class="ml-2"><Insignia etiqueta="Pregunta histórica real" tono="amber" /></span>
                </td>
                <td class="py-3 pr-4 text-slate-600 font-semibold whitespace-nowrap">{{ c.tipo.split(" / ").map((t) => TIPOS[t] ?? t).join(" / ") }}</td>
                <td class="py-3 pr-4 text-slate-600 font-semibold">{{ c.versiones.join(", ") }}<span v-if="c.parcial" class="text-slate-400"> (no está en todas)</span></td>
                <td class="py-3 pr-4 text-right font-black text-slate-800">{{ n(c.contestadas) }}</td>
                <td class="py-3 text-right font-semibold text-slate-500">{{ n(c.sin_respuesta) }}</td>
              </tr>
            </tbody>
          </table>
          <ul class="mt-4 space-y-1 text-[12px] font-semibold text-slate-500" data-columnas-sin-pregunta>
            <li v-for="s in sin_pregunta" :key="s.clave" :data-sin-pregunta="s.clave">
              <b class="text-slate-600">{{ s.clave }}</b>:
              <template v-if="s.con_respuestas === 0">la columna existió en el sistema anterior pero nunca tuvo respuestas; no se creó como pregunta.</template>
              <template v-else>{{ n(s.con_respuestas) }} respuestas conservadas por su clave histórica, sin pregunta configurable.</template>
            </li>
          </ul>
        </section>

        <section v-for="v in versiones" :key="v.id" :class="[CAJA, 'mt-8 p-6']" :data-version="v.numero">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-lg font-black text-slate-900">Versión {{ v.numero }} · {{ v.titulo }}</h2>
            <Insignia :etiqueta="n(v.respuestas) + ' respuestas'" tono="emerald" />
          </div>
          <p class="text-[12px] font-semibold text-slate-400 mb-1">Respuestas del {{ fecha(v.desde) }} al {{ fecha(v.hasta) }} (periodo inferido de las respuestas; la fecha exacta del cambio no se conserva).</p>
          <p class="text-[12px] font-semibold text-slate-500 mb-4">{{ n(v.preguntas_total) }} preguntas, {{ n(v.preguntas_activas) }} activas.</p>

          <div class="space-y-5">
            <article v-for="p in v.preguntas" :key="p.clave" :data-pregunta="p.clave">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-black text-slate-800">{{ p.orden }}. {{ p.texto }}</span>
                <Insignia :etiqueta="TIPOS[p.tipo] ?? p.tipo" tono="slate" />
                <Insignia v-if="!p.redaccion_recuperable" etiqueta="Redacción original no recuperable" tono="amber" />
              </div>
              <p class="text-[12px] font-semibold text-slate-500">{{ p.clave }} · {{ n(p.contestadas) }} contestadas · {{ n(p.sin_respuesta) }} sin respuesta</p>
              <p v-if="p.texto_libre" class="text-[12px] font-semibold text-slate-500" data-texto-libre>
                Texto libre (no se muestra el contenido): {{ n(p.texto_libre.respondidas) }} con texto · {{ n(p.texto_libre.vacias) }} vacías · {{ n(p.texto_libre.total) }} en total · {{ n(p.texto_libre.distintos) }} textos distintos.
              </p>
              <ul v-if="p.opciones.length" class="mt-2 space-y-1.5">
                <li v-for="o in p.opciones" :key="o.valor" class="flex items-center gap-3 text-sm">
                  <span class="w-44 shrink-0 font-semibold text-slate-600 truncate" :title="o.valor">{{ o.valor }}<span v-if="o.historica" class="ml-1 text-amber-600" title="Redacción antigua, conservada tal como se contestó">·&nbsp;histórica</span></span>
                  <span class="flex-1 h-2 rounded-full bg-slate-100 overflow-hidden"><span class="block h-full bg-primary-vinotinto/70" :style="{ width: (o.conteo / maximo(p)) * 100 + '%' }"></span></span>
                  <span class="w-14 text-right font-black text-slate-700" data-conteo>{{ n(o.conteo) }}</span>
                </li>
              </ul>
            </article>
          </div>
        </section>
      </template>
    </Sidebar>
  </AuthenticatedLayout>
</template>
