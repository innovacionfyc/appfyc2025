<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import NavHistorico from "@/Components/CredentialFlow/Historico/NavHistorico.vue";
import Insignia from "@/Components/CredentialFlow/Historico/Insignia.vue";
import DecisionesIdentidad from "@/Components/CredentialFlow/Historico/DecisionesIdentidad.vue";
import CasoEspecial from "@/Components/CredentialFlow/Historico/CasoEspecial.vue";
import { ref, computed } from "vue";
import { Info, TriangleAlert, X, Loader2 } from "lucide-vue-next";

// Detalle de un caso de conciliación. Los datos personales llegan enmascarados. Los casos de plantilla abiertos ofrecen UNA acción (10B-1)
// con confirmación explícita y motivo obligatorio; el resto de los tipos sigue siendo solo lectura.
const props = defineProps({
  caso: { type: Object, required: true },
});

const c = props.caso;
const ev = c.evidencia;
const fecha = (v) => {
  if (!v) return "—";
  const d = new Date(String(v).replace(" ", "T"));
  return isNaN(d.getTime()) ? v : new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }).format(d);
};
const n = (v) => (v ?? 0).toLocaleString("es-CO");
const CAJA = "bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6";
const ETQ = "text-[11px] uppercase tracking-wide font-black text-slate-400";
const esPlantilla = ["plantilla_candidata", "plantilla_faltante", "plantilla_tipo_invalido"].includes(c.tipo);
// ── Acción de resolución (solo casos de plantilla abiertos) ──────────────────────────────────────────────
const accion = c.accion;
const modal = ref(false);
const form = useForm({ motivo: "", confirmo: false, archivo: null, canonico_id: null });
const resumenArchivo = ref(null);
const RESOLUCIONES = { identidad_aplicada: "Identidad aplicada: una decisión habilita el acceso al portal (se reabre si se revoca)", reemplazo_emitido: "Certificado corregido emitido: el histórico quedó reemplazado por una emisión moderna", correo_consolidado: "Variantes consolidadas: diferían solo en el correo registrado", nombre_cosmetico_consolidado: "Variación de nombre consolidada con la variante elegida como canónica", sin_evidencia_util: "Caso descartado: no había datos útiles", codigo_consolidado: "Variantes consolidadas: la diferencia era solo la asignación tardía del código", candidata_aprobada: "Imagen candidata aprobada y asociada", renderizable_confirmado: "Contenido confirmado como renderizable", plantilla_aportada: "Plantilla aportada manualmente" };
const bytes = (b) => (b < 1048576 ? `${Math.round(b / 1024).toLocaleString("es-CO")} KB` : `${(b / 1048576).toLocaleString("es-CO", { maximumFractionDigits: 1 })} MB`);
const motivoOk = computed(() => form.motivo.trim().length >= (accion?.motivo_min ?? 10));
const puedeConfirmar = computed(() => motivoOk.value && form.confirmo && !form.processing && (!accion?.requiere_archivo || !!form.archivo) && (!accion?.opciones || !!form.canonico_id));

const abrir = () => {
  form.reset();
  form.clearErrors();
  resumenArchivo.value = null;
  modal.value = true;
};
const cerrar = () => !form.processing && (modal.value = false);
const elegirArchivo = (e) => {
  const f = e.target.files?.[0] ?? null;
  form.archivo = f;
  resumenArchivo.value = null;
  if (!f) return;
  // Resumen orientativo del navegador; el servidor valida de nuevo por CONTENIDO (tipo real, tamaño, dimensiones y huella).
  const resumen = { tipo: f.type || "desconocido", tamano: bytes(f.size), dimensiones: null };
  resumenArchivo.value = resumen;
  const url = URL.createObjectURL(f);
  const img = new Image();
  img.onload = () => {
    resumenArchivo.value = { ...resumen, dimensiones: `${img.naturalWidth} × ${img.naturalHeight} px` };
    URL.revokeObjectURL(url);
  };
  img.onerror = () => {
    resumenArchivo.value = { ...resumen, dimensiones: "no se pudo leer como imagen" };
    URL.revokeObjectURL(url);
  };
  img.src = url;
};
const enviar = () => {
  if (!puedeConfirmar.value) return;
  form
    .transform((d) => (accion.requiere_archivo ? d : accion.opciones ? { motivo: d.motivo, confirmo: d.confirmo, canonico_id: d.canonico_id } : { motivo: d.motivo, confirmo: d.confirmo }))
    .post(accion.url, { forceFormData: !!accion.requiere_archivo, preserveScroll: true, onSuccess: () => (modal.value = false) });
};

</script>

<template>
  <Head title="Credential Flow · Caso por revisar" />

  <AuthenticatedLayout>
    <Sidebar>
      <NavHistorico actual="casos" :migas="[{ texto: 'Casos por revisar', href: route('credential-flow.historico.casos.index') }, { texto: 'Caso ' + c.id }]" />

      <header class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
          <Insignia :etiqueta="c.estado_info.etiqueta" :tono="c.estado_info.tono" />
          <Insignia :etiqueta="n(c.afectados) + ' certificado' + (c.afectados === 1 ? '' : 's')" tono="slate" />
        </div>
        <h2 class="text-3xl md:text-4xl font-extrabold text-slate-900 tracking-tight">{{ c.tipo_info.etiqueta }}</h2>
        <p class="text-sm font-semibold text-slate-500 max-w-3xl">{{ c.tipo_info.ayuda }}</p>
        <p class="text-sm font-semibold text-slate-400">Solo lectura · Detectado el {{ fecha(c.detectado_at) }}<span v-if="c.motivo"> · {{ c.motivo }}</span></p>
      </header>

      <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-5">
        <section :class="CAJA" data-datos-caso>
          <h3 class="text-base font-extrabold text-slate-900">Resumen</h3>
          <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-4 text-sm font-semibold text-slate-700">
            <div><dt :class="ETQ">Estado</dt><dd>{{ c.estado_info.etiqueta }}</dd></div>
            <div><dt :class="ETQ">Certificados afectados</dt><dd>{{ n(c.afectados) }}</dd></div>
            <div class="col-span-2">
              <dt :class="ETQ">Evento</dt>
              <dd v-if="c.evento"><Link :href="route('credential-flow.historico.eventos.show', c.evento.id)" class="text-primary-vinotinto hover:underline">{{ c.evento.nombre }}</Link> · {{ c.evento.anio_etiqueta }}</dd>
              <dd v-else>Varios eventos</dd>
            </div>
          </dl>
        </section>

        <!-- Conflicto entre variantes -->
        <section v-if="c.tipo === 'conflicto_variantes'" :class="[CAJA, 'lg:col-span-2']" data-evidencia-conflicto>
          <h3 class="text-base font-extrabold text-slate-900">Variantes</h3>
          <p class="mt-2 text-sm font-semibold text-slate-500">
            Difiere: <strong>{{ ev.diferencias.length ? ev.diferencias.join(", ") : "ningún campo visible" }}</strong>.
            <template v-if="ev.diferencias.includes('Nombre')"> El nombre {{ ev.nombre_igual_conservador ? "es igual" : "sigue siendo distinto" }} tras la normalización conservadora (sin tildes, mayúsculas ni signos).</template>
            Descargas históricas en total: {{ n(ev.descargas_total) }}. {{ ev.canonico_actual ? "Hay una variante canónica." : "Ninguna variante es canónica." }}
          </p>
          <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
                <tr>
                  <th class="text-left font-black py-2 pr-4">Registro</th><th class="text-left font-black py-2 pr-4">Nombre</th><th class="text-left font-black py-2 pr-4">Documento</th>
                  <th class="text-left font-black py-2 pr-4">Correos</th><th class="text-left font-black py-2 pr-4">Código</th><th class="text-right font-black py-2 pr-4">Descargas</th><th class="text-left font-black py-2">Conciliación</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="v in ev.variantes" :key="v.id" :data-variante="v.id">
                  <td class="py-2 pr-4"><Link :href="route('credential-flow.historico.certificados.show', v.id)" class="text-primary-vinotinto hover:underline font-bold">#{{ v.id }}</Link></td>
                  <td class="py-2 pr-4 font-mono text-slate-600">{{ v.nombre }}</td>
                  <td class="py-2 pr-4 font-mono text-slate-600">{{ v.tipo_documento || "—" }} {{ v.documento }}</td>
                  <td class="py-2 pr-4 font-mono text-slate-600">{{ v.correos.length ? v.correos.join(", ") : "Sin correo" }}</td>
                  <td class="py-2 pr-4 text-slate-600">{{ v.tiene_codigo ? "Con código" : "Sin código" }}</td>
                  <td class="py-2 pr-4 text-right text-slate-600">{{ n(v.descargas) }}</td>
                  <td class="py-2"><Insignia :etiqueta="v.conciliacion.etiqueta" :tono="v.conciliacion.tono" /></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Consolidación de la diferencia de código (solo DIF_VERIF) -->
        <section v-if="c.tipo === 'conflicto_variantes' && ev.consolidacion?.aplicable" :class="[CAJA, 'lg:col-span-2']" data-consolidacion-codigo>
          <h3 class="text-base font-extrabold text-slate-900" data-titulo-consolidacion>{{ ev.consolidacion.titulo ?? "Diferencia de código" }}</h3>
          <template v-if="ev.consolidacion.estado === 'propuesta'">
            <p class="mt-2 text-sm font-semibold text-slate-600">{{ ev.consolidacion.explicacion }}</p>
            <dl class="mt-4 grid grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-4 text-sm font-semibold text-slate-700">
              <div><dt :class="ETQ">Mismo evento y documento</dt><dd>Sí</dd></div>
              <div><dt :class="ETQ">Código del par</dt><dd class="font-mono" data-codigo-par>{{ ev.consolidacion.codigo ?? "Aún no asignado" }}</dd></div>
              <div><dt :class="ETQ">Variantes</dt><dd>{{ ev.variantes.length }}</dd></div>
              <div><dt :class="ETQ">Canónico</dt><dd v-if="ev.consolidacion.eleccion_manual" data-eleccion-manual>La eliges tú al consolidar</dd><dd v-else data-canonico-propuesto>#{{ ev.consolidacion.canonico_id }}</dd></div>
              <div><dt :class="ETQ">Descargas históricas (total)</dt><dd>{{ n(Object.values(ev.consolidacion.descargas_historicas ?? {}).reduce((a, b) => a + b, 0)) }}</dd></div>
            </dl>
            <ul v-if="ev.consolidacion.bloqueos.length" class="mt-3 text-sm font-semibold text-rose-700" data-bloqueos-consolidacion><li v-for="(b, i) in ev.consolidacion.bloqueos" :key="i">{{ b }}</li></ul>
          </template>
          <p v-else class="mt-2 text-sm font-semibold text-emerald-700" data-consolidada>{{ ev.consolidacion.explicacion }} Canónico: #{{ ev.consolidacion.canonico_id }}.</p>
        </section>

        <!-- Documento en revisión -->
        <section v-if="c.tipo === 'revision_documento'" :class="[CAJA, 'lg:col-span-2']" data-evidencia-documento>
          <h3 class="text-base font-extrabold text-slate-900">Documento</h3>
          <dl v-for="d in ev.certificados" :key="d.id" class="mt-4 grid grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-4 text-sm font-semibold text-slate-700">
            <div class="col-span-2 lg:col-span-4"><dt :class="ETQ">Motivo técnico</dt><dd>{{ d.motivo_tecnico }}</dd></div>
            <div><dt :class="ETQ">Documento</dt><dd class="font-mono">{{ d.documento }}</dd></div>
            <div><dt :class="ETQ">Nombre</dt><dd class="font-mono">{{ d.nombre }}</dd></div>
            <div><dt :class="ETQ">Conciliación</dt><dd>{{ d.conciliacion.etiqueta }}</dd></div>
            <div><dt :class="ETQ">Estado</dt><dd>{{ d.estado }}</dd></div>
            <div><dt :class="ETQ">Descargas</dt><dd>{{ n(d.descargas) }}</dd></div>
            <div><dt :class="ETQ">Correos</dt><dd>{{ d.correos }}</dd></div>
            <div><dt :class="ETQ">Duplicado histórico</dt><dd>{{ d.en_grupo_duplicado ? "Sí" : "No" }}</dd></div>
            <div><dt :class="ETQ">Certificado</dt><dd><Link :href="route('credential-flow.historico.certificados.show', d.id)" class="text-primary-vinotinto hover:underline font-bold">Ver #{{ d.id }}</Link></dd></div>
          </dl>
        </section>

        <!-- Identidad ambigua -->
        <section v-if="c.tipo === 'identidad_ambigua'" :class="[CAJA, 'lg:col-span-2']" data-evidencia-identidad>
          <h3 class="text-base font-extrabold text-slate-900">{{ ev.grupos_conservadores }} nombres distintos con el documento {{ ev.documento }}</h3>
          <p class="mt-2 flex items-start gap-2 text-sm font-semibold text-slate-500"><Info class="w-4 h-4 mt-0.5 shrink-0" /> {{ ev.nota }}</p>
          <p v-if="ev.correos_compartidos" class="mt-2 flex items-start gap-2 text-sm font-semibold text-amber-700"><TriangleAlert class="w-4 h-4 mt-0.5 shrink-0" /> {{ ev.correos_compartidos }} correo(s) aparecen en más de un nombre.</p>
          <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div v-for="g in ev.grupos" :key="g.etiqueta" class="rounded-2xl border border-slate-100 p-4 space-y-2" :data-grupo="g.etiqueta">
              <p class="font-extrabold text-slate-800">{{ g.etiqueta }} · <span class="font-mono text-slate-500">{{ g.nombre }}</span></p>
              <p class="text-sm font-semibold text-slate-600">{{ n(g.certificados) }} certificado(s)</p>
              <div class="flex flex-wrap gap-1.5"><Insignia v-for="e in g.estados" :key="e.estado" :etiqueta="e.etiqueta + ' · ' + e.n" :tono="e.tono" /></div>
              <p class="text-[12px] font-semibold text-slate-500">Eventos ({{ n(g.eventos.total) }}): {{ g.eventos.items.map((e) => e.nombre).join(", ") || "—" }}<span v-if="g.eventos.total > g.eventos.items.length"> y {{ n(g.eventos.total - g.eventos.items.length) }} más</span></p>
              <ul class="text-[12px] font-mono text-slate-600">
                <li v-for="(m, i) in g.correos" :key="i">{{ m.mascara }}<span v-if="m.compartido" class="ml-2 font-sans font-bold text-amber-700">compartido</span></li>
                <li v-if="!g.correos.length" class="font-sans font-semibold text-slate-400">Sin correos válidos</li>
              </ul>
            </div>
          </div>
        </section>

        <!-- Plantillas -->
        <section v-if="esPlantilla" :class="[CAJA, 'lg:col-span-2']" data-evidencia-plantilla>
          <h3 class="text-base font-extrabold text-slate-900">{{ ev.mensaje }}</h3>
          <dl class="mt-4 grid grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-4 text-sm font-semibold text-slate-700">
            <div><dt :class="ETQ">Certificados</dt><dd>{{ n(ev.certificados) }}</dd></div>
            <div v-if="ev.estado_plantilla"><dt :class="ETQ">Estado de la imagen</dt><dd>{{ ev.estado_plantilla.etiqueta }}</dd></div>
            <div class="col-span-2"><dt :class="ETQ">Eventos</dt><dd>{{ ev.eventos.map((e) => e.nombre + " · " + e.anio_etiqueta).join(", ") || "—" }}</dd></div>
          </dl>

          <dl v-if="ev.contenido" class="mt-4 grid grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-4 text-sm font-semibold text-slate-700" data-contenido-existente>
            <div><dt :class="ETQ">Extensión histórica</dt><dd class="font-mono break-all">{{ ev.contenido.extension_historica }}</dd></div>
            <div><dt :class="ETQ">Tipo real</dt><dd>{{ ev.contenido.mime_real ?? "—" }}</dd></div>
            <div><dt :class="ETQ">Huella</dt><dd class="font-mono">{{ ev.contenido.sha ?? "—" }}</dd></div>
            <div><dt :class="ETQ">Tamaño</dt><dd>{{ ev.contenido.dimensiones ?? "—" }} · {{ ev.contenido.bytes ?? "—" }}</dd></div>
            <div><dt :class="ETQ">Renderizable ahora</dt><dd>{{ ev.contenido.renderizable_actual ? "Sí" : "No" }}</dd></div>
            <div><dt :class="ETQ">Renderizable potencialmente</dt><dd>{{ ev.contenido.renderizable_potencialmente ? "Sí" : "No" }}</dd></div>
            <div class="col-span-2"><dt :class="ETQ">Motivo</dt><dd>{{ ev.contenido.motivo ?? "—" }}</dd></div>
          </dl>

          <dl v-if="ev.candidata" class="mt-4 grid grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-4 text-sm font-semibold text-slate-700" data-candidata>
            <div><dt :class="ETQ">Huella</dt><dd class="font-mono">{{ ev.candidata.sha ?? "—" }}</dd></div>
            <div><dt :class="ETQ">Tipo</dt><dd>{{ ev.candidata.mime ?? "—" }}</dd></div>
            <div><dt :class="ETQ">Tamaño</dt><dd>{{ ev.candidata.dimensiones ?? "—" }} · {{ ev.candidata.bytes ?? "—" }}</dd></div>
            <div><dt :class="ETQ">Asociada al evento</dt><dd>{{ ev.candidata.asociada ? "Sí" : "No" }}</dd></div>
            <div v-if="ev.candidata.evidencia_migrador" class="col-span-2 lg:col-span-4"><dt :class="ETQ">Evidencia de la migración</dt><dd class="font-medium text-slate-500">Parecido del nombre: {{ ev.candidata.evidencia_migrador.similitud ?? "—" }}. {{ ev.candidata.evidencia_migrador.nota }}</dd></div>
          </dl>

          <p class="mt-4 flex items-start gap-2 text-[13px] font-semibold text-slate-500" data-sin-vista-previa><Info class="w-4 h-4 mt-0.5 shrink-0" /> {{ ev.vista_previa.motivo }}</p>
          <ul v-if="ev.certificados_por_evento.length > 1" class="mt-3 text-[13px] font-semibold text-slate-600">
            <li v-for="(x, i) in ev.certificados_por_evento" :key="i">{{ x.evento?.nombre ?? "Evento" }}: {{ n(x.certificados) }} certificado(s)</li>
          </ul>
        </section>

        <!-- Resolver (casos de plantilla) -->
        <section v-if="accion || ((esPlantilla || ['conflicto_variantes', 'revision_documento'].includes(c.tipo)) && c.estado !== 'abierto')" :class="[CAJA, 'lg:col-span-2']" data-accion-caso>
          <h3 class="text-base font-extrabold text-slate-900">{{ c.estado !== "abierto" ? "Decisión" : "Resolver este caso" }}</h3>
          <p v-if="c.estado === 'resuelto' || c.estado === 'descartado'" class="mt-3 text-sm font-semibold text-emerald-700" data-resuelto>{{ RESOLUCIONES[c.resolucion] ?? "Caso resuelto" }}.</p>
          <p v-else-if="c.estado === 'requiere_soporte'" class="mt-3 text-sm font-semibold text-amber-700" data-requiere-soporte>Este caso se marcó como «requiere soporte»: no se puede resolver con la evidencia disponible. No se modificó ningún certificado.</p>
          <template v-else>
            <p class="mt-2 text-sm font-semibold text-slate-600">{{ accion.texto }}</p>
            <p v-if="accion.bloqueo" class="mt-3 flex items-start gap-2 text-sm font-semibold text-rose-700" data-bloqueo><TriangleAlert class="w-4 h-4 mt-0.5 shrink-0" /> {{ accion.bloqueo }}</p>
            <Link v-else-if="accion.enlace" :href="accion.url" class="mt-4 inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90 transition-all" data-abrir-reemplazo>{{ accion.etiqueta }}</Link>
            <button v-else type="button" class="mt-4 inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90 transition-all" data-abrir-accion @click="abrir">{{ accion.etiqueta }}</button>
          </template>
        </section>

        <!-- Decisiones de identidad (10B-3A): registran, no autorizan -->
        <!-- Caso especial (10B-3C-4): no se resuelve automáticamente -->
        <CasoEspecial v-if="c.especial" :especial="c.especial" />
        <DecisionesIdentidad v-if="c.identidad" :identidad="c.identidad" />

        <!-- Certificado lógico (10B-2B-2C.1): este caso es de una variante; el reemplazo se gestiona desde el certificado principal -->
        <section v-if="c.certificado_logico && c.certificado_logico.es_variante" :class="[CAJA, 'lg:col-span-2']" data-certificado-logico data-variante>
          <h3 class="text-base font-extrabold text-slate-900">Certificado consolidado</h3>
          <p class="mt-2 text-sm font-semibold text-slate-600">{{ c.certificado_logico.mensaje }}</p>
          <p v-if="c.certificado_logico.caso_principal" class="mt-3 text-sm font-semibold text-slate-700">
            Caso del certificado principal:
            <Link :href="c.certificado_logico.caso_principal.url" class="text-primary-vinotinto underline" data-caso-principal>#{{ c.certificado_logico.caso_principal.id }}</Link>
            · {{ c.certificado_logico.caso_principal.referencia }}
          </p>
        </section>
        <section v-else-if="c.certificado_logico" :class="[CAJA, 'lg:col-span-2']" data-certificado-logico data-principal>
          <p class="text-sm font-semibold text-slate-600">{{ c.certificado_logico.mensaje }}</p>
        </section>

        <!-- Reemplazo emitido (10B-2B-2B): referencia a la emisión moderna -->
        <section v-if="c.reemplazo" :class="[CAJA, 'lg:col-span-2']" data-reemplazo-emitido>
          <h3 class="text-base font-extrabold text-slate-900">Certificado corregido</h3>
          <p class="mt-2 text-sm font-semibold text-emerald-700">{{ c.reemplazo.cubierto_por_canonico ? "Este registro quedó cubierto por el reemplazo de su certificado principal." : "Certificado corregido emitido correctamente. El certificado histórico quedó reemplazado." }}</p>
          <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 text-sm font-semibold text-slate-700">
            <div><dt :class="ETQ">Estado de la emisión</dt><dd>{{ c.reemplazo.estado === "emitida" ? "Vigente" : "Revocada" }} · versión {{ c.reemplazo.version }}</dd></div>
            <div><dt :class="ETQ">Código moderno</dt><dd class="font-mono" data-codigo-emision>{{ c.reemplazo.codigo }}</dd></div>
            <div><dt :class="ETQ">Emitida</dt><dd>{{ fecha(c.reemplazo.emitido_at) }}</dd></div>
            <div v-if="c.reemplazo.vigente_actual"><dt :class="ETQ">Emisión vigente</dt><dd>{{ c.reemplazo.vigente_actual.es_la_misma ? "Es esta emisión" : "Hay una versión posterior vigente" }}</dd></div>
            <div v-else><dt :class="ETQ">Emisión vigente</dt><dd>Ninguna (la emisión fue revocada)</dd></div>
          </dl>
          <div class="mt-4 flex flex-wrap gap-3">
            <a :href="c.reemplazo.url_pdf" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90" data-ver-pdf>Ver PDF (administración)</a>
            <a :href="c.reemplazo.url_verificacion" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl border border-slate-200 text-slate-700 text-sm font-bold hover:bg-slate-50" data-ver-verificacion>Ver verificación pública</a>
          </div>
        </section>

        <div v-if="modal && accion" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50" role="dialog" aria-modal="true" :aria-label="accion.etiqueta" data-modal-accion @keydown.esc="cerrar">
          <div class="w-full max-w-lg bg-white rounded-[2rem] shadow-xl p-6 space-y-4 max-h-[90vh] overflow-y-auto">
            <div class="flex items-start justify-between gap-4">
              <h3 class="text-lg font-extrabold text-slate-900">{{ accion.etiqueta }}</h3>
              <button type="button" class="p-1 rounded-full text-slate-400 hover:bg-slate-100" aria-label="Cerrar" :disabled="form.processing" @click="cerrar"><X class="w-5 h-5" /></button>
            </div>
            <p class="text-sm font-semibold text-slate-600">{{ accion.texto }}</p>

            <div v-if="accion.requiere_archivo" class="space-y-2" data-campo-archivo>
              <label class="block">
                <span :class="ETQ">Archivo de la plantilla ({{ accion.archivo.formatos.join(" o ") }}, máximo {{ accion.archivo.max_mb }} MB)</span>
                <input type="file" accept="image/png,image/jpeg" class="mt-1 block w-full text-sm font-semibold text-slate-700" data-input-archivo @change="elegirArchivo" />
              </label>
              <dl v-if="resumenArchivo" class="grid grid-cols-3 gap-2 text-[12px] font-semibold text-slate-600 bg-slate-50 rounded-2xl p-3" data-resumen-archivo>
                <div><dt :class="ETQ">Tipo</dt><dd>{{ resumenArchivo.tipo }}</dd></div>
                <div><dt :class="ETQ">Tamaño</dt><dd>{{ resumenArchivo.tamano }}</dd></div>
                <div><dt :class="ETQ">Dimensiones</dt><dd>{{ resumenArchivo.dimensiones ?? "calculando…" }}</dd></div>
              </dl>
              <p class="text-[12px] font-semibold text-slate-400">El servidor vuelve a comprobar el tipo real, el tamaño y las dimensiones por el contenido, no por el nombre del archivo.</p>
              <p v-if="form.errors.archivo" class="text-sm font-semibold text-rose-600" data-error-archivo>{{ form.errors.archivo }}</p>
            </div>

            <fieldset v-if="accion.opciones" class="space-y-2" data-opciones-canonico>
              <legend :class="ETQ">¿Cuál variante queda como canónica?</legend>
              <label v-for="o in accion.opciones" :key="o.id" class="flex items-start gap-2 text-sm font-semibold text-slate-700 rounded-2xl border border-slate-200 px-3 py-2">
                <input v-model="form.canonico_id" type="radio" name="canonico_id" :value="o.id" class="mt-1" :data-opcion="o.id" />
                <span>{{ o.nombre }}</span>
              </label>
              <p v-if="form.errors.canonico_id" class="text-sm font-semibold text-rose-600">{{ form.errors.canonico_id }}</p>
            </fieldset>

            <label class="block">
              <span :class="ETQ">Motivo (obligatorio, mínimo {{ accion.motivo_min }} caracteres)</span>
              <textarea v-model="form.motivo" rows="3" :maxlength="accion.motivo_max" class="mt-1 w-full rounded-2xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-primary-vinotinto/30 focus:border-primary-vinotinto" data-input-motivo />
              <span v-if="form.errors.motivo" class="text-sm font-semibold text-rose-600">{{ form.errors.motivo }}</span>
            </label>

            <label class="flex items-start gap-2 text-sm font-semibold text-slate-700">
              <input v-model="form.confirmo" type="checkbox" class="mt-1 rounded border-slate-300" data-input-confirmo />
              <span>Entiendo que esta decisión queda registrada y cambia el estado de {{ n(c.afectados) }} certificados.</span>
            </label>
            <p v-if="form.errors.confirmo" class="text-sm font-semibold text-rose-600">{{ form.errors.confirmo }}</p>

            <div class="flex justify-end gap-2 pt-2">
              <button type="button" class="px-4 py-2 rounded-2xl bg-slate-100 text-slate-600 text-sm font-bold hover:bg-slate-200" :disabled="form.processing" @click="cerrar">Cancelar</button>
              <button type="button" class="inline-flex items-center gap-2 px-5 py-2 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold disabled:opacity-40" :disabled="!puedeConfirmar" data-confirmar-accion @click="enviar">
                <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin" /> Confirmar
              </button>
            </div>
          </div>
        </div>

        <section :class="[CAJA, 'lg:col-span-2']" data-bitacora>
          <h3 class="text-base font-extrabold text-slate-900">Bitácora</h3>
          <ol class="mt-3 space-y-2 text-sm font-semibold text-slate-600">
            <li v-for="(b, i) in c.bitacora" :key="i">{{ fecha(b.fecha) }} · {{ b.accion }} · {{ b.actor }}<span v-if="b.estado_nuevo"> → {{ b.estado_nuevo }}</span><span v-if="b.motivo"> · {{ b.motivo }}</span></li>
          </ol>
          <p v-if="!esPlantilla && !accion && c.tipo !== 'identidad_ambigua' && c.estado !== 'resuelto'" class="mt-4 text-[13px] font-semibold text-slate-400" data-sin-acciones>Las acciones para resolver este tipo de caso todavía no están disponibles.</p>
        </section>
      </div>
    </Sidebar>
  </AuthenticatedLayout>
</template>
