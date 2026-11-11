<script setup>
import { computed, reactive } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import { Info, ChevronLeft, ChevronRight, Search, X } from "lucide-vue-next";

const props = defineProps({
  evento: { type: Object, required: true }, // { id, nombre, anio, fecha_texto, historico, volver }
  resumen: { type: Object, required: true }, // { modernos{oficial,prueba_interna,indeterminada}, por_modalidad, por_base, historicos, clasificacion_distinta_a_marca }
  listado: { type: Object, required: true }, // { items, total, pagina, por_pagina, ultima }
  filtros: { type: Object, default: () => ({}) },
  opciones: { type: Object, default: () => ({ bases: [], modalidades: [] }) },
  permisos: { type: Object, default: () => ({ documento_completo: false }) },
  aviso: { type: String, required: true },
  reintento_segundos: { type: Number, default: 120 },
});

const of = computed(() => props.resumen.modernos.oficial);
const qa = computed(() => props.resumen.modernos.prueba_interna);
const ind = computed(() => props.resumen.modernos.indeterminada);
const hist = computed(() => props.resumen.historicos);
const hayModernos = computed(() => of.value.participaciones + qa.value.participaciones + ind.value.participaciones > 0);
const hayHistoricos = computed(() => hist.value.certificados > 0);

const pct = (a, b) => (b > 0 ? Math.round((a * 1000) / b) / 10 : 0);
const n = (v) => new Intl.NumberFormat("es-CO").format(v ?? 0);
// Cantidad con su sustantivo en singular o plural («1 solicitud», «3 solicitudes»).
const c = (v, uno, varios) => `${n(v)} ${(v ?? 0) === 1 ? uno : varios}`;
const MESES = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];
// Las fechas llegan como texto de la base de datos (hora de Colombia): se muestran tal cual, sin conversión de zona.
const fecha = (s) => {
  if (!s) return "—";
  const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
  return m ? `${Number(m[3])} ${MESES[Number(m[2]) - 1]} ${m[1]}, ${m[4]}:${m[5]}` : String(s);
};

// Cabecera: certificados OFICIALES vigentes del evento, nuevos + históricos. Se pueden sumar sin contar dos veces: un histórico reemplazado no
// es vigente (su certificado nuevo sí, y cuenta como nuevo) y un duplicado histórico es un solo certificado.
const headerStats = computed(() => {
  const suma = (k) => (hayModernos.value ? of.value[k] ?? 0 : 0) + (hayHistoricos.value ? hist.value[k] ?? 0 : 0);
  const base = { vigentes: suma("vigentes"), con_descarga: suma("con_descarga"), pendientes: suma("pendientes") };
  return [
    { label: "Vigentes", value: base.vigentes, icon: "verified", color: "text-emerald-700", bg: "bg-emerald-50" },
    { label: "Con descarga", value: base.con_descarga, icon: "download_done", color: "text-sky-700", bg: "bg-sky-50" },
    { label: "Pendientes", value: base.pendientes, icon: "pending", color: "text-amber-600", bg: "bg-amber-50" },
  ];
});

const f = reactive({
  tipo: props.filtros.tipo ?? "",
  clase: props.filtros.clase ?? "oficial",
  base: props.filtros.base ?? "",
  modalidad: props.filtros.modalidad ?? "",
  estado: props.filtros.estado ?? "",
  nombre: props.filtros.nombre ?? "",
  documento: props.filtros.documento ?? "",
  desde: props.filtros.desde ?? "",
  hasta: props.filtros.hasta ?? "",
});
const limpio = (o) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== "" && v !== null && v !== undefined));
const consultar = (extra = {}) =>
  router.get(route("credential-flow.eventos.descargas", props.evento.id), limpio({ ...f, ...extra, documento: props.permisos.documento_completo ? f.documento : "" }), {
    preserveScroll: true,
    preserveState: true,
  });
const limpiar = () => {
  Object.assign(f, { tipo: "", clase: "oficial", base: "", modalidad: "", estado: "", nombre: "", documento: "", desde: "", hasta: "" });
  consultar();
};
const ir = (pagina) => consultar({ page: pagina });

const ESTADO = {
  vigente: (r) => (r.descargado ? ["Descargado", "bg-sky-50 text-sky-700"] : ["Pendiente", "bg-amber-50 text-amber-700"]),
  revocado: () => ["Revocado", "bg-rose-50 text-rose-700"],
  reemplazado: () => ["Reemplazado", "bg-slate-100 text-slate-600"],
};
const estado = (r) => (ESTADO[r.estado] ?? (() => [r.estado, "bg-slate-100 text-slate-600"]))(r);
const CLASE = { oficial: "Oficial", prueba_interna: "Prueba interna (QA)", indeterminada: "Sin clasificar" };
const solicitudes = (r) => (r.tipo === "moderno" ? r.descargas.vigente.solicitudes : r.descargas.credential_flow.solicitudes + r.descargas.importadas.solicitudes);
const primera = (r) => (r.tipo === "moderno" ? r.descargas.vigente.primera : [r.descargas.importadas.primera, r.descargas.credential_flow.primera].filter(Boolean).sort()[0]);
const ultima = (r) => (r.tipo === "moderno" ? r.descargas.vigente.ultima : [r.descargas.importadas.ultima, r.descargas.credential_flow.ultima].filter(Boolean).sort().pop());
</script>

<template>
  <Head :title="`Credential Flow · Descargas · ${evento.nombre}`" />

  <AuthenticatedLayout>
    <Sidebar>
      <nav class="mb-3 flex flex-wrap items-center gap-x-2 pr-14 md:pr-0 text-[12px] font-bold text-slate-400" aria-label="Ruta de navegación">
        <Link :href="route('credential-flow.index')" class="hover:text-primary-vinotinto transition-colors">Credential Flow</Link>
        <span>/</span>
        <Link :href="evento.historico ? route('credential-flow.historico.index') : route('credential-flow.eventos.index')" class="hover:text-primary-vinotinto transition-colors">{{ evento.historico ? "Histórico" : "Eventos" }}</Link>
        <span>/</span>
        <Link :href="evento.volver" class="hover:text-primary-vinotinto transition-colors break-words">{{ evento.nombre }}</Link>
        <span>/</span>
        <span class="text-slate-600">Descargas</span>
      </nav>

      <div class="mb-4 flex gap-2 text-[13px] font-bold" role="tablist" data-pestanas-evento>
        <Link :href="evento.volver" class="px-4 py-2 rounded-full bg-white border border-slate-200 text-slate-600 hover:bg-slate-50" role="tab" aria-selected="false">{{ evento.historico ? "Evento histórico" : "Resumen del evento" }}</Link>
        <span class="px-4 py-2 rounded-full bg-primary-vinotinto text-white" role="tab" aria-selected="true" data-pestana-descargas>Descargas</span>
      </div>

      <DashboardHeader :title="evento.nombre" :subtitle="`Seguimiento de descargas · ${evento.fecha_texto ?? evento.anio ?? ''}`" :stats="headerStats" />

      <p class="mt-6 flex items-start gap-2 rounded-2xl bg-sky-50 border border-sky-100 px-4 py-3 text-[13px] font-semibold text-sky-900" data-aviso-descargas role="note">
        <Info class="w-4 h-4 mt-0.5 shrink-0" /> {{ aviso }}
      </p>

      <!-- Resumen -->
      <section v-if="hayModernos" class="mt-6 grid gap-4 lg:grid-cols-3" data-resumen-modernos>
        <div class="lg:col-span-2 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6">
          <h2 class="text-sm font-extrabold text-slate-700">Certificados nuevos · oficiales</h2>
          <dl class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div><dt class="text-[11px] uppercase tracking-wide font-black text-slate-400">Emitidos vigentes</dt><dd class="text-2xl font-black text-slate-900" data-m="vigentes">{{ n(of.vigentes) }}</dd></div>
            <div><dt class="text-[11px] uppercase tracking-wide font-black text-slate-400">Con descarga registrada</dt><dd class="text-2xl font-black text-sky-700" data-m="con-descarga">{{ n(of.con_descarga) }} <span class="text-sm font-bold text-slate-400">{{ pct(of.con_descarga, of.vigentes) }} %</span></dd></div>
            <div><dt class="text-[11px] uppercase tracking-wide font-black text-slate-400">Pendientes de 1.ª descarga</dt><dd class="text-2xl font-black text-amber-600" data-m="pendientes">{{ n(of.pendientes) }}</dd></div>
            <div><dt class="text-[11px] uppercase tracking-wide font-black text-slate-400">Solicitudes de descarga</dt><dd class="text-2xl font-black text-slate-900" data-m="solicitudes">{{ n(of.solicitudes_vigentes) }}</dd></div>
          </dl>
          <p class="mt-4 text-[12px] font-semibold text-slate-500">
            Primera: {{ fecha(of.primera) }} · Última: {{ fecha(of.ultima) }}
            <template v-if="of.revocadas"> · {{ n(of.revocadas) }} sin versión vigente (revocados)</template>
          </p>
          <p v-if="of.solicitudes_anteriores" class="mt-1 text-[12px] font-semibold text-slate-500" data-m="anteriores">
            {{ c(of.solicitudes_anteriores, "solicitud", "solicitudes") }} de versiones anteriores ({{ c(of.con_descarga_anterior, "certificado reemitido o revocado", "certificados reemitidos o revocados") }}): no cuentan para la versión vigente.
          </p>
          <p class="mt-1 text-[12px] font-semibold text-slate-400" data-m="reintentos">
            Indicador secundario: {{ c(of.reintentos, "posible reintento", "posibles reintentos") }} (misma IP, menos de {{ reintento_segundos / 60 }} min de la anterior). Se informan aparte; no cambian las cifras.
          </p>
        </div>
        <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6" data-resumen-qa>
          <h2 class="text-sm font-extrabold text-slate-700">Pruebas internas (QA)</h2>
          <p class="mt-3 text-sm font-semibold text-slate-600">{{ c(qa.vigentes, "vigente", "vigentes") }} · {{ n(qa.con_descarga) }} con descarga · {{ c(qa.solicitudes_vigentes, "solicitud", "solicitudes") }}</p>
          <p class="mt-2 text-[12px] font-semibold text-slate-400">No suman a las cifras oficiales.</p>
          <p v-if="ind.participaciones" class="mt-3 text-[12px] font-semibold text-amber-700">{{ c(ind.participaciones, "certificado", "certificados") }} sin clasificar (diseño no legible): fuera de las cifras oficiales.</p>
          <p v-if="resumen.clasificacion_distinta_a_marca" class="mt-3 text-[12px] font-semibold text-amber-700" data-aviso-clasificacion>
            {{ c(resumen.clasificacion_distinta_a_marca, "certificado cuya", "certificados cuya") }} marca de participante de control no coincide con el documento emitido; se clasifican por el
            documento emitido, igual que el porcentaje del listado de eventos. Por eso la columna «Certificados vigentes» del listado (que cuenta por la marca) puede no coincidir.
          </p>
        </div>
      </section>

      <section v-if="hayModernos && (resumen.por_modalidad.length || resumen.por_base.length)" class="mt-4 grid gap-4 md:grid-cols-2" data-desgloses>
        <div v-for="(grupo, titulo) in { 'Por modalidad': resumen.por_modalidad, 'Por base': resumen.por_base }" :key="titulo" class="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6">
          <h3 class="text-sm font-extrabold text-slate-700">{{ titulo }} <span class="font-semibold text-slate-400">(oficiales vigentes)</span></h3>
          <ul class="mt-3 divide-y divide-slate-100">
            <li v-for="g in grupo" :key="g.clave" class="py-2 flex items-center justify-between gap-3 text-sm">
              <span class="font-bold text-slate-700 break-words min-w-0">{{ g.etiqueta }}</span>
              <span class="shrink-0 font-semibold text-slate-500">{{ n(g.con_descarga) }}/{{ n(g.vigentes) }} · {{ pct(g.con_descarga, g.vigentes) }} % · {{ n(g.solicitudes) }} solic.</span>
            </li>
          </ul>
        </div>
      </section>

      <section v-if="hayHistoricos" class="mt-4 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6" data-resumen-historicos>
        <h2 class="text-sm font-extrabold text-slate-700">Certificados históricos</h2>
        <dl class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
          <div><dt class="text-[11px] uppercase tracking-wide font-black text-slate-400">Vigentes</dt><dd class="text-2xl font-black text-slate-900">{{ n(hist.vigentes) }}</dd></div>
          <div><dt class="text-[11px] uppercase tracking-wide font-black text-slate-400">Con alguna descarga</dt><dd class="text-2xl font-black text-sky-700">{{ n(hist.con_descarga) }} <span class="text-sm font-bold text-slate-400">{{ pct(hist.con_descarga, hist.vigentes) }} %</span></dd></div>
          <div><dt class="text-[11px] uppercase tracking-wide font-black text-slate-400">Sin ninguna descarga</dt><dd class="text-2xl font-black text-amber-600">{{ n(hist.pendientes) }}</dd></div>
          <div><dt class="text-[11px] uppercase tracking-wide font-black text-slate-400">Reemplazados por uno nuevo</dt><dd class="text-2xl font-black text-slate-600">{{ n(hist.reemplazados) }}</dd></div>
        </dl>
        <p class="mt-4 text-[12px] font-semibold text-slate-500" data-m="historico-origenes">
          Credential Flow: {{ c(hist.solicitudes_credential_flow, "solicitud", "solicitudes") }} · {{ c(hist.con_descarga_credential_flow, "certificado vigente", "certificados vigentes") }} con alguna · {{ fecha(hist.primera_credential_flow) }} – {{ fecha(hist.ultima_credential_flow) }}.
          Sistema anterior (importadas): {{ c(hist.solicitudes_importadas, "solicitud", "solicitudes") }} · {{ c(hist.con_descarga_importada, "certificado vigente", "certificados vigentes") }} con alguna · {{ fecha(hist.primera_importada) }} – {{ fecha(hist.ultima_importada) }}.
        </p>
        <p class="mt-1 text-[12px] font-semibold text-slate-400">
          Las solicitudes incluyen las de certificados reemplazados o revocados anteriores a ese cambio; las posteriores a un reemplazo se cuentan en su certificado nuevo, no aquí.
          <template v-if="hist.revocados"> {{ c(hist.revocados, "certificado histórico revocado", "certificados históricos revocados") }} (no cuentan como vigentes).</template>
        </p>
      </section>

      <!-- Filtros -->
      <form class="mt-6 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-filtros-descargas @submit.prevent="consultar()">
        <label class="text-[12px] font-bold text-slate-500">Tipo
          <select v-model="f.tipo" class="mt-1 w-full rounded-xl border-slate-200 text-sm" data-filtro="tipo"><option value="">Todos</option><option value="moderno">Nuevos</option><option value="historico">Históricos</option></select>
        </label>
        <label class="text-[12px] font-bold text-slate-500">Clase
          <select v-model="f.clase" class="mt-1 w-full rounded-xl border-slate-200 text-sm" data-filtro="clase"><option value="oficial">Oficiales</option><option value="prueba_interna">Pruebas internas (QA)</option><option value="indeterminada">Sin clasificar</option><option value="todas">Todas</option></select>
        </label>
        <label class="text-[12px] font-bold text-slate-500">Estado
          <select v-model="f.estado" class="mt-1 w-full rounded-xl border-slate-200 text-sm" data-filtro="estado"><option value="">Todos</option><option value="descargado">Con descarga</option><option value="pendiente">Pendiente de 1.ª descarga</option><option value="revocado">Revocado</option><option value="reemplazado">Reemplazado</option></select>
        </label>
        <label v-if="opciones.bases.length" class="text-[12px] font-bold text-slate-500">Base
          <select v-model="f.base" class="mt-1 w-full rounded-xl border-slate-200 text-sm" data-filtro="base"><option value="">Todas</option><option v-for="b in opciones.bases" :key="b.id" :value="b.id">{{ b.nombre }}</option></select>
        </label>
        <label v-if="opciones.modalidades.length" class="text-[12px] font-bold text-slate-500">Modalidad
          <select v-model="f.modalidad" class="mt-1 w-full rounded-xl border-slate-200 text-sm" data-filtro="modalidad"><option value="">Todas</option><option v-for="m in opciones.modalidades" :key="m.clave" :value="m.clave">{{ m.etiqueta }}</option></select>
        </label>
        <label class="text-[12px] font-bold text-slate-500">Participante
          <input v-model="f.nombre" type="search" maxlength="120" autocomplete="off" class="mt-1 w-full rounded-xl border-slate-200 text-sm" placeholder="Nombre" data-filtro="nombre" />
        </label>
        <label v-if="permisos.documento_completo" class="text-[12px] font-bold text-slate-500">Documento exacto
          <input v-model="f.documento" type="search" maxlength="40" autocomplete="off" class="mt-1 w-full rounded-xl border-slate-200 text-sm" placeholder="Número completo" data-filtro="documento" />
        </label>
        <label class="text-[12px] font-bold text-slate-500">Descargas desde
          <input v-model="f.desde" type="date" class="mt-1 w-full rounded-xl border-slate-200 text-sm" data-filtro="desde" />
        </label>
        <label class="text-[12px] font-bold text-slate-500">Descargas hasta
          <input v-model="f.hasta" type="date" class="mt-1 w-full rounded-xl border-slate-200 text-sm" data-filtro="hasta" />
        </label>
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
          <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90" data-accion="filtrar"><Search class="w-4 h-4" /> Filtrar</button>
          <button type="button" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50" data-accion="limpiar" @click="limpiar"><X class="w-4 h-4" /> Limpiar</button>
          <p class="ml-auto text-[12px] font-semibold text-slate-400">{{ c(listado.total, "certificado", "certificados") }}</p>
        </div>
        <p v-if="filtros.desde || filtros.hasta" class="sm:col-span-2 lg:col-span-4 text-[12px] font-semibold text-amber-700" data-aviso-rango>
          El rango de fechas solo limita qué certificados se listan: los que tienen al menos una descarga en ese rango (de cualquier versión). Las solicitudes, la
          primera y la última descarga de cada fila, y los indicadores de arriba, siguen siendo totales del certificado y del evento, no del rango.
        </p>
      </form>

      <!-- Listado -->
      <section class="mt-4 bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden" data-listado-descargas>
        <p v-if="listado.total === 0" class="p-6 text-sm font-semibold text-slate-500">No hay certificados con estos filtros.</p>
        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
              <tr>
                <th class="text-left font-black px-4 py-3">Participante</th>
                <th class="hidden md:table-cell text-left font-black px-4 py-3">Modalidad · base</th>
                <th class="text-left font-black px-4 py-3">Estado</th>
                <th class="hidden sm:table-cell text-right font-black px-4 py-3">Solicitudes</th>
                <th class="hidden sm:table-cell text-left font-black px-4 py-3">Primera</th>
                <th class="hidden sm:table-cell text-left font-black px-4 py-3">Última</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="r in listado.items" :key="r.clave" :data-registro="r.clave" class="align-top">
                <td class="px-4 py-3 sm:min-w-[12rem]">
                  <p class="font-extrabold text-slate-900 break-words">{{ r.nombre }}</p>
                  <p class="text-[12px] font-semibold text-slate-400 font-mono">{{ r.documento }}</p>
                  <div class="mt-1 flex flex-wrap gap-1 text-[10px] font-bold">
                    <span v-if="r.tipo === 'historico'" class="px-2 py-0.5 rounded-full bg-violet-50 text-violet-700" data-insignia="historico">Histórico{{ r.filas > 1 ? ` · ${r.filas} filas` : "" }}</span>
                    <span v-if="r.tipo === 'moderno' && r.clase !== 'oficial'" class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600" data-insignia="clase">{{ CLASE[r.clase] }}</span>
                    <span v-if="r.reemplaza_historico" class="px-2 py-0.5 rounded-full bg-violet-50 text-violet-700" data-insignia="reemplaza">Reemplaza a un histórico</span>
                    <span v-if="r.versiones > 1" class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">Versión {{ r.version }} ({{ r.versiones }} emitidas)</span>
                    <span v-if="r.participante_eliminado" class="px-2 py-0.5 rounded-full bg-rose-50 text-rose-700">Participante eliminado</span>
                  </div>
                  <p class="md:hidden mt-1 text-[12px] font-semibold text-slate-500">{{ r.modalidad ?? "" }}{{ r.base ? ` · ${r.base.nombre}` : "" }}</p>
                  <!-- En pantallas pequeñas las solicitudes y las fechas van aquí (sin columnas ocultas tras un desplazamiento lateral). -->
                  <p class="sm:hidden mt-1 text-[12px] font-semibold text-slate-600" data-solicitudes-movil>
                    {{ c(solicitudes(r), "solicitud", "solicitudes") }}<template v-if="r.tipo === 'historico' && r.descargas.importadas.solicitudes"> ({{ n(r.descargas.importadas.solicitudes) }} del sistema anterior)</template><template v-if="r.tipo === 'moderno' && r.descargas.anteriores.solicitudes"> · +{{ n(r.descargas.anteriores.solicitudes) }} de versiones anteriores</template><template v-if="r.reintentos"> · {{ c(r.reintentos, "posible reintento", "posibles reintentos") }}</template>
                  </p>
                  <p v-if="primera(r)" class="sm:hidden mt-0.5 text-[12px] font-semibold text-slate-500">{{ fecha(primera(r)) }} → {{ fecha(ultima(r)) }}</p>
                </td>
                <td class="hidden md:table-cell px-4 py-3 text-slate-600 font-semibold">
                  {{ r.modalidad ?? "—" }}<span v-if="r.base" class="block text-[12px] text-slate-400">{{ r.base.nombre }}</span>
                </td>
                <td class="px-4 py-3">
                  <span class="inline-block px-2.5 py-1 rounded-full text-[11px] font-black" :class="estado(r)[1]" data-estado>{{ estado(r)[0] }}</span>
                </td>
                <td class="hidden sm:table-cell px-4 py-3 text-right">
                  <span class="font-black text-slate-900">{{ n(solicitudes(r)) }}</span>
                  <span v-if="r.tipo === 'historico' && r.descargas.importadas.solicitudes" class="block text-[11px] font-semibold text-slate-400">{{ n(r.descargas.importadas.solicitudes) }} del sistema anterior</span>
                  <span v-if="r.tipo === 'moderno' && r.descargas.anteriores.solicitudes" class="block text-[11px] font-semibold text-slate-400">+{{ n(r.descargas.anteriores.solicitudes) }} de versiones anteriores</span>
                  <span v-if="r.reintentos" class="block text-[11px] font-semibold text-slate-400">{{ c(r.reintentos, "posible reintento", "posibles reintentos") }}</span>
                </td>
                <td class="hidden sm:table-cell px-4 py-3 text-slate-500 font-semibold whitespace-nowrap">{{ fecha(primera(r)) }}</td>
                <td class="hidden sm:table-cell px-4 py-3 text-slate-500 font-semibold whitespace-nowrap">{{ fecha(ultima(r)) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="listado.ultima > 1" class="flex items-center justify-between gap-3 px-4 py-3 border-t border-slate-100 text-[12px] font-bold text-slate-500" data-paginacion>
          <button type="button" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full border border-slate-200 disabled:opacity-40" :disabled="listado.pagina <= 1" @click="ir(listado.pagina - 1)"><ChevronLeft class="w-3.5 h-3.5" /> Anterior</button>
          <span>Página {{ listado.pagina }} de {{ listado.ultima }}</span>
          <button type="button" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full border border-slate-200 disabled:opacity-40" :disabled="listado.pagina >= listado.ultima" @click="ir(listado.pagina + 1)">Siguiente <ChevronRight class="w-3.5 h-3.5" /></button>
        </div>
      </section>
    </Sidebar>
  </AuthenticatedLayout>
</template>
