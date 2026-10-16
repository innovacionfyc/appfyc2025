<script setup>
import { computed } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import NavEncuestas from "@/Components/CredentialFlow/Encuestas/NavEncuestas.vue";
import FiltrosEncuestas from "@/Components/CredentialFlow/Encuestas/FiltrosEncuestas.vue";
import GraficoBarras from "@/Components/CredentialFlow/Encuestas/GraficoBarras.vue";
import Insignia from "@/Components/CredentialFlow/Historico/Insignia.vue";
import { ClipboardList, Download, MessageSquareText } from "lucide-vue-next";

// Detalle de un evento: respuestas, personas únicas y resultados por versión y pregunta. Las versiones con estructuras distintas NO se fusionan.
// Los porcentajes llevan su denominador explícito (respuestas con contenido en esa pregunta). El texto libre solo se cuenta.
const props = defineProps({
  filtros: { type: Object, required: true },
  opciones: { type: Object, required: true },
  evento: { type: Object, required: true },
  resumen: { type: Object, required: true },
  versiones: { type: Array, default: () => [] },
  puede_comentarios: { type: Boolean, default: false },
});

const n = (v) => (v ?? 0).toLocaleString("es-CO");
const fecha = (v) => (v ? String(v).slice(0, 10) : "—");
const TIPOS = { opcion_unica: "Opción única", opcion_multiple: "Opción múltiple", texto: "Texto libre", escala: "Escala" };
const CAJA = "bg-white rounded-[2rem] border border-slate-100 shadow-sm";
const activos = computed(() => Object.fromEntries(Object.entries(props.filtros).filter(([, v]) => v !== null && v !== "")));
const urlBase = () => route("credential-flow.encuestas.eventos.show", { evento: props.evento.id ?? "sin-evento" });
const items = (p) => p.opciones.map((o) => ({ etiqueta: o.etiqueta || o.valor, valor: o.conteo }));
const escala = (p) => (p.escala?.distribucion ?? []).map((d) => ({ etiqueta: String(d.valor), valor: d.conteo }));
</script>

<template>
  <Head :title="`Credential Flow · ${evento.nombre}`" />

  <AuthenticatedLayout>
    <Sidebar>
      <NavEncuestas actual="eventos" :filtros="filtros" :puede-comentarios="puede_comentarios" :migas="[{ texto: 'Resultados por evento', href: route('credential-flow.encuestas.eventos') }, { texto: evento.nombre }]" />

      <DashboardHeader :title="evento.nombre" :subtitle="evento.no_disponible ? 'Respuestas de eventos que ya no existen en el sistema anterior. No se asignan a ningún otro evento.' : `Año ${evento.anio ?? '—'} · inicio ${fecha(evento.fecha_inicio)}`" />

      <div class="mt-6 flex flex-wrap items-center gap-3" data-acciones-evento>
        <a :href="route('credential-flow.encuestas.exportar.xlsx', activos)" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-primary-vinotinto text-white text-[13px] font-bold hover:opacity-90" data-exportar="xlsx"><Download class="w-4 h-4" /> Excel de este evento</a>
        <a :href="route('credential-flow.encuestas.exportar.csv', { ...activos, conjunto: 'distribucion' })" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-[13px] font-bold text-slate-600 hover:bg-slate-50" data-exportar="csv"><Download class="w-4 h-4" /> CSV</a>
        <Link v-if="puede_comentarios && evento.id" :href="route('credential-flow.encuestas.comentarios', { q: evento.nombre })" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-[13px] font-bold text-slate-600 hover:bg-slate-50" data-ver-comentarios>
          <MessageSquareText class="w-4 h-4" /> Ver comentarios (restringido)
        </Link>
      </div>

      <div class="mt-6">
        <FiltrosEncuestas :filtros="filtros" :opciones="opciones" :url="urlBase" :campos="['version', 'origen', 'resp_fechas']" />
      </div>

      <section class="grid grid-cols-2 lg:grid-cols-4 gap-4" data-metricas-evento>
        <div :class="[CAJA, 'p-5']"><p class="text-[11px] uppercase tracking-wide font-black text-slate-400">Respuestas</p><p class="text-3xl font-black text-slate-900" data-total="respuestas">{{ n(resumen.respuestas) }}</p></div>
        <div :class="[CAJA, 'p-5']"><p class="text-[11px] uppercase tracking-wide font-black text-slate-400">Personas únicas</p><p class="text-3xl font-black text-slate-900" data-total="personas">{{ n(resumen.participaciones_unicas) }}</p></div>
        <div :class="[CAJA, 'p-5']"><p class="text-[11px] uppercase tracking-wide font-black text-slate-400">Versiones</p><p class="text-3xl font-black text-slate-900" data-total="versiones">{{ n(versiones.length) }}</p></div>
        <div :class="[CAJA, 'p-5']"><p class="text-[11px] uppercase tracking-wide font-black text-slate-400">Periodo</p><p class="text-lg font-black text-slate-900">{{ fecha(resumen.desde) }} · {{ fecha(resumen.hasta) }}</p></div>
      </section>
      <p class="mt-3 text-[12px] font-semibold text-slate-500">
        Participación sobre personas elegibles: <b class="text-slate-600">no disponible</b>.
        <template v-if="resumen.respuestas !== resumen.participaciones_unicas">Hay {{ n(resumen.respuestas - resumen.participaciones_unicas) }} respuestas repetidas de una misma persona (se conservan). </template>
        <template v-if="resumen.sin_vinculo > 0">{{ n(resumen.sin_vinculo) }} respuestas no tienen un participante verificable.</template>
      </p>

      <div v-if="versiones.length === 0" class="mt-6 text-center py-14 space-y-3 bg-white rounded-[2rem] border border-dashed border-slate-200" data-sin-resultados>
        <div class="w-14 h-14 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto"><ClipboardList class="w-7 h-7 text-slate-400" /></div>
        <p class="text-slate-600 font-bold">Este evento no tiene respuestas con los filtros elegidos</p>
      </div>

      <section v-for="v in versiones" :key="v.id" :class="[CAJA, 'mt-6 p-6']" :data-version="v.numero">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <h2 class="text-lg font-black text-slate-900">Versión {{ v.numero }} · {{ v.titulo }}</h2>
          <div class="flex gap-2"><Insignia :etiqueta="n(v.respuestas) + ' respuestas'" tono="emerald" /><Insignia :etiqueta="n(v.personas) + ' personas'" tono="sky" /></div>
        </div>
        <p class="text-[12px] font-semibold text-slate-400 mb-5">Respuestas del {{ fecha(v.desde) }} al {{ fecha(v.hasta) }}.</p>

        <div class="space-y-6">
          <article v-for="p in v.preguntas" :key="p.id" :data-pregunta="p.clave">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-black text-slate-800">{{ p.orden }}. {{ p.texto }}</span>
              <Insignia :etiqueta="TIPOS[p.tipo] ?? p.tipo" tono="slate" />
            </div>
            <p class="text-[12px] font-semibold text-slate-500 mb-2">
              {{ n(p.respondidas) }} respondidas · {{ n(p.omitidas) }} omitidas · los porcentajes se calculan sobre las {{ n(p.base_porcentaje) }} respondidas.
            </p>
            <GraficoBarras v-if="p.tipo !== 'texto' && p.tipo !== 'escala' && p.opciones.length" :items="items(p)" :titulo="p.texto" :base="p.base_porcentaje || null" />
            <template v-else-if="p.tipo === 'escala'">
              <GraficoBarras v-if="escala(p).length" :items="escala(p)" :titulo="p.texto" :base="p.base_porcentaje || null" />
              <p v-if="p.escala?.promedio !== null && p.escala?.promedio !== undefined" class="text-sm font-bold text-slate-600 mt-1">Promedio: {{ p.escala.promedio.toLocaleString("es-CO") }}</p>
            </template>
            <p v-else-if="p.tipo === 'texto'" class="text-sm font-semibold text-slate-600" data-texto-libre>
              {{ n(p.texto_libre.con_contenido) }} respuestas con contenido y {{ n(p.texto_libre.sin_contenido) }} sin contenido. Los comentarios no se muestran en esta pantalla.
            </p>
          </article>
        </div>
      </section>
    </Sidebar>
  </AuthenticatedLayout>
</template>
