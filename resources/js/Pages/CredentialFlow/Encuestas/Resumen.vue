<script setup>
import { computed } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import NavEncuestas from "@/Components/CredentialFlow/Encuestas/NavEncuestas.vue";
import FiltrosEncuestas from "@/Components/CredentialFlow/Encuestas/FiltrosEncuestas.vue";
import GraficoBarras from "@/Components/CredentialFlow/Encuestas/GraficoBarras.vue";
import GraficoTendencia from "@/Components/CredentialFlow/Encuestas/GraficoTendencia.vue";
import { ClipboardList, ArrowRight } from "lucide-vue-next";

// Resumen de resultados de encuestas (históricas + modernas, todas las versiones). SOLO LECTURA: nada de texto libre ni datos personales.
const props = defineProps({
  filtros: { type: Object, required: true },
  opciones: { type: Object, required: true },
  resumen: { type: Object, required: true },
});

const n = (v) => (v ?? 0).toLocaleString("es-CO");
const fecha = (v) => (v ? String(v).slice(0, 10) : "—");
const CAJA = "bg-white rounded-[2rem] border border-slate-100 shadow-sm";
const ETIQUETA_ORIGEN = { legado: "Históricas (sistema anterior)", credential_flow: "Modernas (portal)" };

const activos = computed(() => Object.fromEntries(Object.entries(props.filtros).filter(([, v]) => v !== null && v !== "")));
const porOrigen = computed(() => Object.entries(props.resumen.por_origen).map(([k, v]) => ({ etiqueta: ETIQUETA_ORIGEN[k] ?? k, valor: v })));
const porVersion = computed(() => props.resumen.por_version.map((v) => ({ etiqueta: `Versión ${v.numero} · ${v.titulo}`, valor: v.respuestas })));
const porAnio = computed(() => props.resumen.por_anio.map((a) => ({ etiqueta: a.anio ?? "Sin año", valor: a.respuestas })));
const tendencia = computed(() => props.resumen.por_mes.map((m) => ({ etiqueta: m.mes, valor: m.respuestas })));
const metricas = computed(() => [
  { id: "respuestas", titulo: "Respuestas", valor: n(props.resumen.respuestas), nota: "Registros conservados, incluidos duplicados históricos" },
  { id: "participaciones", titulo: "Participaciones únicas", valor: n(props.resumen.participaciones_unicas), nota: "Personas distintas por evento" },
  { id: "eventos", titulo: "Eventos evaluados", valor: n(props.resumen.eventos_evaluados), nota: "Eventos con al menos una respuesta" },
  { id: "historicas", titulo: "Históricas", valor: n(props.resumen.por_origen.legado ?? 0), nota: "Sistema anterior" },
  { id: "modernas", titulo: "Modernas", valor: n(props.resumen.por_origen.credential_flow ?? 0), nota: "Portal actual" },
  { id: "versiones", titulo: "Versiones con respuestas", valor: n(props.resumen.por_version.length), nota: "Estructuras sin fusionar" },
]);
</script>

<template>
  <Head title="Credential Flow · Encuestas" />

  <AuthenticatedLayout>
    <Sidebar>
      <NavEncuestas actual="resumen" :filtros="filtros" />

      <DashboardHeader title="Encuestas" subtitle="Resultados de las encuestas de eventos: respuestas históricas y modernas, agrupadas por evento. Solo lectura." />

      <div class="mt-6">
        <FiltrosEncuestas :filtros="filtros" :opciones="opciones" :url="() => route('credential-flow.encuestas.resumen')" />
      </div>

      <div v-if="resumen.respuestas === 0" class="text-center py-16 space-y-3 bg-white rounded-[2rem] border border-dashed border-slate-200" data-sin-resultados>
        <div class="w-16 h-16 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto"><ClipboardList class="w-8 h-8 text-slate-400" /></div>
        <p class="text-slate-600 font-bold text-lg">No hay respuestas con estos filtros</p>
        <p class="text-sm font-semibold text-slate-400">Ajustar o limpiar los filtros para ver resultados.</p>
      </div>

      <template v-else>
        <section class="grid grid-cols-2 lg:grid-cols-3 gap-4" data-metricas>
          <div v-for="m in metricas" :key="m.id" :class="[CAJA, 'p-5']" :data-metrica="m.id">
            <p class="text-[11px] uppercase tracking-wide font-black text-slate-400">{{ m.titulo }}</p>
            <p class="text-3xl font-black text-slate-900" data-valor>{{ m.valor }}</p>
            <p class="text-[12px] font-semibold text-slate-400 mt-1">{{ m.nota }}</p>
          </div>
        </section>

        <p class="mt-4 text-[12px] font-semibold text-slate-500" data-aviso-participacion>
          Participación sobre personas elegibles: <b class="text-slate-600">no disponible</b>. {{ resumen.participacion.motivo }}
          Periodo de respuestas: {{ fecha(resumen.desde) }} a {{ fecha(resumen.hasta) }}.
        </p>
        <p v-if="resumen.sin_evento > 0 || resumen.sin_vinculo > 0" class="mt-1 text-[12px] font-semibold text-slate-500" data-categorias-aparte>
          <template v-if="resumen.sin_evento > 0">{{ n(resumen.sin_evento) }} respuestas pertenecen a eventos que ya no existen en el sistema y se muestran aparte; no se asignan a ningún otro evento. </template>
          <template v-if="resumen.sin_vinculo > 0">{{ n(resumen.sin_vinculo) }} respuestas del evento no tienen un participante verificable.</template>
        </p>

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
          <section :class="[CAJA, 'p-6']" data-seccion="origen">
            <h2 class="text-lg font-black text-slate-900 mb-1">Respuestas por origen</h2>
            <p class="text-sm font-semibold text-slate-500 mb-4">Históricas del sistema anterior y modernas del portal.</p>
            <GraficoBarras :items="porOrigen" titulo="Respuestas por origen" :base="resumen.respuestas" />
          </section>
          <section :class="[CAJA, 'p-6']" data-seccion="version">
            <h2 class="text-lg font-black text-slate-900 mb-1">Respuestas por versión</h2>
            <p class="text-sm font-semibold text-slate-500 mb-4">Cada versión conserva preguntas propias.</p>
            <GraficoBarras :items="porVersion" titulo="Respuestas por versión" :base="resumen.respuestas" color="bg-primary-naranja/80" />
          </section>
          <section :class="[CAJA, 'p-6']" data-seccion="anio">
            <h2 class="text-lg font-black text-slate-900 mb-1">Respuestas por año del evento</h2>
            <p class="text-sm font-semibold text-slate-500 mb-4">Sin contar las respuestas de eventos no disponibles.</p>
            <GraficoBarras v-if="porAnio.length" :items="porAnio" titulo="Respuestas por año del evento" color="bg-primary-verde/80" />
            <p v-else class="text-sm font-semibold text-slate-400">Sin respuestas con año de evento.</p>
          </section>
          <section :class="[CAJA, 'p-6']" data-seccion="tendencia">
            <h2 class="text-lg font-black text-slate-900 mb-1">Evolución mensual</h2>
            <p class="text-sm font-semibold text-slate-500 mb-4">Respuestas recibidas por mes (fecha de respuesta).</p>
            <GraficoTendencia :puntos="tendencia" titulo="Respuestas por mes" />
          </section>
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
          <Link :href="route('credential-flow.encuestas.eventos', activos)" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90 transition-opacity" data-ir="eventos">
            Ver resultados por evento <ArrowRight class="w-4 h-4" />
          </Link>
          <Link :href="route('credential-flow.encuestas.informes', activos)" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50" data-ir="informes">
            Informes y exportaciones
          </Link>
          <Link :href="route('credential-flow.historico.encuestas')" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50" data-ir="detalle-historico">
            Detalle técnico de la migración histórica
          </Link>
        </div>
      </template>
    </Sidebar>
  </AuthenticatedLayout>
</template>
