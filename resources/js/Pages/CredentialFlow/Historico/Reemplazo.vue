<script setup>
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import NavHistorico from "@/Components/CredentialFlow/Historico/NavHistorico.vue";
import Insignia from "@/Components/CredentialFlow/Historico/Insignia.vue";
import { ref, computed, watch, onBeforeUnmount } from "vue";
import { Info, TriangleAlert, Loader2, FileSearch, CheckCircle2, X } from "lucide-vue-next";

// Asistente «Emitir certificado corregido» (Fase 10B-2B-2B). Siete pasos; NADA se persiste hasta el último: la vista previa se genera con un POST
// que devuelve el PDF (no hay URL permanente: se muestra desde memoria) y la emisión exige que esa vista previa corresponda a los datos actuales.
const props = defineProps({ datos: { type: Object, required: true } });
const d = computed(() => props.datos);
const page = usePage();

const CAJA = "bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6";
const ETQ = "text-[11px] uppercase tracking-wide font-black text-slate-400";
const INPUT = "mt-1 w-full rounded-2xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-primary-vinotinto/30 focus:border-primary-vinotinto";
const BTN = "inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90 transition-all disabled:opacity-40";
const BTN2 = "inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl border border-slate-200 text-slate-700 text-sm font-bold hover:bg-slate-50 transition-all disabled:opacity-40";
const n = (v) => (v ?? 0).toLocaleString("es-CO");
const fecha = (v) => {
  if (!v) return "—";
  const f = new Date(String(v).replace(" ", "T"));
  return isNaN(f.getTime()) ? v : new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }).format(f);
};
const mascara = (v) => {
  const t = String(v ?? "");
  return t.length <= 3 ? "•".repeat(t.length) : "•".repeat(t.length - 3) + t.slice(-3);
};

// ── Paso 2: dato aprobado ─────────────────────────────────────────────────────────────────────────────
const reglaId = ref(d.value.valor.reglas[0]?.regla ?? null);
const regla = computed(() => d.value.valor.reglas.find((r) => r.regla === reglaId.value) ?? null);
const valorDoc = ref(regla.value?.valor ?? "");
const confirmadoValor = ref(false);
const tengoEvidencia = ref(false);
const evidencia = ref("");
watch(reglaId, () => {
  valorDoc.value = regla.value?.valor ?? "";
  confirmadoValor.value = false;
  tengoEvidencia.value = false;
  evidencia.value = "";
});
const esManual = computed(() => regla.value?.regla === "valor_confirmado_manual");

// ── DIF_NOMBRE (Fase 10B-3C-2): el nombre se APRUEBA a propósito; nunca hay una variante preseleccionada ni «sugerida» ───────────────
const esDif = computed(() => d.value.modo === "dif_nombre");
const dif = computed(() => d.value.dif_nombre);
const nombreModo = ref(null); // id de una variante histórica, o "externo"
const nombreExterno = ref("");
const valorNombre = computed(() => {
  if (!esDif.value || !nombreModo.value) return null;
  if (nombreModo.value === "externo") return nombreExterno.value.trim() === "" ? null : nombreExterno.value;
  return dif.value.variantes.find((v) => String(v.id) === String(nombreModo.value))?.nombre ?? null;
});
const nombreHistoricoMasc = computed(() => (esDif.value ? dif.value.variantes.map((v) => v.nombre_enmascarado).join(" / ") : ""));
watch([nombreModo, nombreExterno], () => { confirmadoValor.value = false; });
const valorFinal = computed(() => (regla.value?.editable ? valorDoc.value : regla.value?.valor ?? ""));
const datoListo = computed(() => {
  const r = regla.value;
  if (!r || !valorFinal.value.trim()) return false;
  if (esDif.value) return !!valorNombre.value && confirmadoValor.value && evidencia.value.trim().length >= d.value.valor.evidencia_min;
  if (r.requiere_confirmacion && !confirmadoValor.value) return false;
  if (r.requiere_evidencia && (!tengoEvidencia.value || evidencia.value.trim().length < d.value.valor.evidencia_min)) return false;
  return true;
});

// ── Pasos 3 y 4: plantilla y diseño ───────────────────────────────────────────────────────────────────
const plantillaId = ref(d.value.plantilla.clon?.id ?? null);
watch(() => d.value.plantilla.clon?.id, (id) => { if (id && !plantillaId.value) plantillaId.value = id; });
const candidatas = computed(() => [d.value.plantilla.clon, ...d.value.plantilla.otras].filter(Boolean));
const plantilla = computed(() => candidatas.value.find((p) => p.id === plantillaId.value) ?? null);
const fechaTxt = ref("");
const intensidadTxt = ref("");
const camposListos = computed(() => !plantilla.value || ((!plantilla.value.requiere_fecha || fechaTxt.value.trim()) && (!plantilla.value.requiere_intensidad || intensidadTxt.value.trim())));
const disenoListo = computed(() => !!plantilla.value && plantilla.value.tiene_diseno && plantilla.value.diseno_confirmado);

const cargandoClon = ref(false);
const cargandoDiseno = ref(false);
const prepararClon = () => router.post(d.value.rutas.clon, {}, { preserveScroll: true, onStart: () => (cargandoClon.value = true), onFinish: () => (cargandoClon.value = false) });
const confirmarDiseno = () => router.post(d.value.rutas.diseno, { plantilla_id: plantilla.value.id }, { preserveScroll: true, onStart: () => (cargandoDiseno.value = true), onFinish: () => (cargandoDiseno.value = false) });
const actualizar = () => router.reload({ only: ["datos"], preserveScroll: true });

// ── Paso 5: vista previa (en memoria; se invalida si cambia cualquier dato) ───────────────────────────
const payloadDatos = computed(() => ({
  plantilla_id: plantilla.value?.id ?? null,
  regla_documento: reglaId.value,
  valor_documento: regla.value?.editable ? valorDoc.value : null,
  regla_nombre: esDif.value ? "nombre_confirmado" : null,
  valor_nombre: esDif.value ? valorNombre.value : null,
  confirmado_valor: confirmadoValor.value,
  tengo_evidencia: esDif.value ? true : tengoEvidencia.value,
  evidencia: esDif.value || tengoEvidencia.value ? evidencia.value : null,
  fecha: plantilla.value?.requiere_fecha ? fechaTxt.value : null,
  intensidad_horaria: plantilla.value?.requiere_intensidad ? intensidadTxt.value : null,
}));
const firma = computed(() => JSON.stringify([payloadDatos.value, plantilla.value?.diseno_confirmado]));
const previewUrl = ref(null);
const huella = ref(null);
const firmaPreview = ref(null);
const cargandoPreview = ref(false);
const errorPreview = ref(null);
const avisoCaducada = ref(false);
const invalidarPreview = () => {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
  previewUrl.value = null;
  huella.value = null;
  firmaPreview.value = null;
};
watch(firma, (nueva) => {
  if (previewUrl.value && nueva !== firmaPreview.value) {
    invalidarPreview();
    avisoCaducada.value = true;
  }
});
onBeforeUnmount(invalidarPreview);
const puedePreview = computed(() => datoListo.value && camposListos.value && !!plantilla.value && plantilla.value.tiene_diseno && !cargandoPreview.value);
const generarPreview = async () => {
  cargandoPreview.value = true;
  errorPreview.value = null;
  avisoCaducada.value = false;
  invalidarPreview();
  try {
    const r = await window.axios.post(d.value.rutas.preview, payloadDatos.value, { responseType: "blob", headers: { Accept: "application/pdf, application/json" } });
    previewUrl.value = URL.createObjectURL(new Blob([r.data], { type: "application/pdf" }));
    huella.value = r.headers["x-huella-preview"];
    firmaPreview.value = firma.value;
  } catch (e) {
    let msg = "No se pudo generar la vista previa.";
    try {
      msg = JSON.parse(await e.response.data.text()).mensaje ?? msg;
    } catch (_) {}
    errorPreview.value = msg;
  } finally {
    cargandoPreview.value = false;
  }
};

// ── Pasos 6 y 7: motivo, doble confirmación y emisión ─────────────────────────────────────────────────
const motivo = ref("");
const confirmoRevision = ref(false);
const modal = ref(false);
const confirmoFinal = ref(false);
const emitiendo = ref(false);
const errores = computed(() => page.props.errors ?? {});
const motivoOk = computed(() => motivo.value.trim().length >= d.value.motivo.min);
const puedeEmitir = computed(() => !!huella.value && confirmoRevision.value && motivoOk.value && disenoListo.value && datoListo.value && camposListos.value && !emitiendo.value);
const emitir = () =>
  router.post(
    d.value.rutas.emitir,
    { ...payloadDatos.value, huella: huella.value, motivo: motivo.value, confirmo_revision: confirmoRevision.value, confirmo_final: confirmoFinal.value },
    { preserveScroll: true, onStart: () => (emitiendo.value = true), onFinish: () => { emitiendo.value = false; modal.value = false; } },
  );
const pasos = computed(() => [
  { n: 1, t: "Evidencia", ok: true },
  { n: 2, t: "Dato aprobado", ok: datoListo.value },
  { n: 3, t: "Plantilla", ok: !!plantilla.value && camposListos.value },
  { n: 4, t: "Diseño", ok: disenoListo.value },
  { n: 5, t: "Vista previa", ok: !!huella.value },
  { n: 6, t: "Motivo y confirmación", ok: confirmoRevision.value && motivoOk.value },
  { n: 7, t: "Emisión", ok: false },
]);
</script>

<template>
  <Head title="Credential Flow · Emitir certificado corregido" />

  <AuthenticatedLayout>
    <Sidebar>
      <NavHistorico
        actual="casos"
        :migas="[{ texto: 'Casos por revisar', href: route('credential-flow.historico.casos.index') }, { texto: 'Caso ' + d.caso.id, href: d.rutas.caso }, { texto: 'Emitir certificado corregido' }]"
      />

      <header class="space-y-3" data-reemplazo>
        <div class="flex flex-wrap items-center gap-2">
          <Insignia :etiqueta="d.caso.estado_info.etiqueta" :tono="d.caso.estado_info.tono" />
          <Insignia :etiqueta="d.evidencia.categoria.etiqueta" tono="slate" />
        </div>
        <h2 class="text-3xl md:text-4xl font-extrabold text-slate-900 tracking-tight">Emitir certificado corregido</h2>
        <p class="flex items-start gap-2 text-sm font-semibold text-slate-600 max-w-3xl" data-aviso-inmutable><Info class="w-4 h-4 mt-0.5 shrink-0 text-primary-vinotinto" /> {{ d.aviso }}</p>
        <ol class="flex flex-wrap gap-2 pt-1" data-pasos>
          <li v-for="p in pasos" :key="p.n" class="flex items-center gap-1.5 text-[12px] font-bold rounded-full px-3 py-1" :class="p.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">
            <CheckCircle2 v-if="p.ok" class="w-3.5 h-3.5" />{{ p.n }}. {{ p.t }}
          </li>
        </ol>
      </header>

      <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-5">
        <!-- PASO 1 · Evidencia -->
        <section :class="CAJA" data-paso="1">
          <h3 class="text-base font-extrabold text-slate-900">1 · Revisar la evidencia</h3>
          <p class="mt-2 text-sm font-semibold text-slate-500">{{ d.evidencia.categoria.ayuda }}</p>
          <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 text-sm font-semibold text-slate-700">
            <div><dt :class="ETQ">Documento histórico</dt><dd class="font-mono" data-doc-historico>{{ d.evidencia.tipo_documento || "—" }} {{ d.evidencia.documento_historico }}</dd></div>
            <div><dt :class="ETQ">Estado del certificado</dt><dd>{{ d.evidencia.estado_certificado }}</dd></div>
            <div class="col-span-2"><dt :class="ETQ">Qué detectó la migración</dt><dd>{{ d.evidencia.motivo_tecnico }}</dd></div>
            <div class="col-span-2"><dt :class="ETQ">Comportamiento histórico</dt><dd data-comportamiento>{{ d.evidencia.comportamiento_historico }}</dd></div>
            <div><dt :class="ETQ">Estructura</dt><dd>{{ d.evidencia.estructura.caracteres_historico }} caracteres · {{ d.evidencia.estructura.caracteres_clave }} letras o dígitos<span v-if="d.evidencia.estructura.misma_clave_solo_letras_digitos"> · la clave coincide</span></dd></div>
            <div><dt :class="ETQ">Descargas históricas</dt><dd>{{ n(d.evidencia.descargas_historicas) }}</dd></div>
            <div class="col-span-2" data-corroboracion>
              <dt :class="ETQ">Registros corroborantes (mismo documento en otros eventos)</dt>
              <dd v-if="d.evidencia.corroboracion.otros_registros === 0">Ninguno: este es el único registro con este documento.</dd>
              <dd v-else>{{ n(d.evidencia.corroboracion.otros_registros) }} registro(s) · {{ n(d.evidencia.corroboracion.con_documento_limpio) }} con el documento limpio · {{ n(d.evidencia.corroboracion.mismo_nombre) }} con el mismo nombre</dd>
            </div>
            <div class="col-span-2" v-if="d.evidencia.evento"><dt :class="ETQ">Evento</dt><dd>{{ d.evidencia.evento.nombre }} · {{ d.evidencia.evento.anio_etiqueta }}</dd></div>
          </dl>
          <ul class="mt-4 space-y-1 text-[13px] font-semibold text-slate-500">
            <li v-for="(b, i) in d.evidencia.bitacora" :key="i">{{ fecha(b.fecha) }} · {{ b.accion }} · {{ b.actor }}</li>
          </ul>
        </section>

        <!-- PASO 2 (DIF_NOMBRE) · Nombre aprobado -->
        <section v-if="esDif" :class="CAJA" data-paso="2" data-dif-nombre>
          <h3 class="text-base font-extrabold text-slate-900">2 · Aprobar el nombre que se imprimirá</h3>
          <p class="mt-2 flex items-start gap-2 text-sm font-semibold text-amber-800" data-aviso-nombre><TriangleAlert class="w-4 h-4 mt-0.5 shrink-0" /> El sistema no sabe cuál es el nombre correcto. Elige exactamente una variante histórica o escribe un nombre confirmado, y registra la evidencia.</p>
          <p class="mt-2 text-[13px] font-semibold text-slate-500" data-aviso-identidad>{{ dif.identidad.aviso }}</p>
          <ul v-if="dif.identidad.casos.length" class="mt-2 text-[13px] font-semibold text-slate-500" data-identidad-casos>
            <li v-for="c in dif.identidad.casos" :key="c.id">Identidad: caso <Link :href="c.url" class="underline">{{ c.id }}</Link> · {{ c.estado }} · {{ dif.identidad.decisiones_vigentes }} decisión(es) vigente(s)</li>
          </ul>
          <p class="mt-3 text-sm font-semibold text-slate-600">Documento (sin cambios): <strong class="font-mono" data-documento-sin-cambio>{{ dif.documento_sin_cambio }}</strong></p>
          <fieldset class="mt-4 space-y-2" data-variantes>
            <legend :class="ETQ">Nombre aprobado</legend>
            <label v-for="v in dif.variantes" :key="v.id" class="flex items-start gap-2 text-sm font-semibold text-slate-700 rounded-2xl border border-slate-200 px-3 py-2 cursor-pointer">
              <input v-model="nombreModo" type="radio" name="nombre" :value="String(v.id)" class="mt-1" :data-variante="v.id" />
              <span><span class="font-bold" data-nombre-variante>{{ v.nombre }}</span><span class="block text-[13px] text-slate-500">Variante histórica #{{ v.id }} · {{ n(v.descargas) }} descarga(s) · {{ v.tiene_codigo ? "con código" : "sin código" }}</span></span>
            </label>
            <label class="flex items-start gap-2 text-sm font-semibold text-slate-700 rounded-2xl border border-slate-200 px-3 py-2 cursor-pointer">
              <input v-model="nombreModo" type="radio" name="nombre" value="externo" class="mt-1" data-nombre-externo-opcion />
              <span class="font-bold">Otro nombre confirmado con evidencia externa</span>
            </label>
          </fieldset>
          <label v-if="nombreModo === 'externo'" class="mt-3 block"><span :class="ETQ">Nombre (en MAYÚSCULAS, solo caracteres que la fuente imprime)</span><input v-model="nombreExterno" type="text" maxlength="255" :class="INPUT" data-input-nombre-externo /></label>
          <div class="mt-4 grid grid-cols-2 gap-3 text-sm font-semibold text-slate-700">
            <div class="rounded-2xl bg-slate-50 p-3" data-historico><div :class="ETQ">Histórico (no editable)</div><div class="mt-1">{{ nombreHistoricoMasc }}</div></div>
            <div class="rounded-2xl bg-emerald-50 p-3" data-corregido><div :class="ETQ">Certificado corregido</div><div class="mt-1" data-valor-final>{{ valorNombre || "—" }}</div></div>
          </div>
          <label class="mt-4 block"><span :class="ETQ">Evidencia: describe la fuente del nombre (entre {{ d.valor.evidencia_min }} y {{ d.valor.evidencia_max }} caracteres)</span><textarea v-model="evidencia" rows="3" :maxlength="d.valor.evidencia_max" :class="INPUT" data-input-evidencia /></label>
          <label class="mt-4 flex items-start gap-2 text-sm font-bold text-slate-700" data-confirmar-valor>
            <input v-model="confirmadoValor" type="checkbox" class="mt-1" />
            <span>Confirmo que este es el nombre que debe quedar impreso y que existe evidencia suficiente. Esto no decide la identidad ni autoriza ningún correo.</span>
          </label>
        </section>

        <!-- PASO 2 · Dato aprobado -->
        <section v-else :class="CAJA" data-paso="2">
          <h3 class="text-base font-extrabold text-slate-900">2 · Confirmar el dato que se usará</h3>
          <div class="mt-3 grid grid-cols-2 gap-3 text-sm font-semibold text-slate-700">
            <div class="rounded-2xl bg-slate-50 p-3" data-historico><div :class="ETQ">Histórico (no editable)</div><div class="font-mono mt-1">{{ d.evidencia.documento_historico }}</div></div>
            <div class="rounded-2xl bg-emerald-50 p-3" data-corregido><div :class="ETQ">Certificado corregido</div><div class="font-mono mt-1" data-valor-final>{{ valorFinal || "—" }}</div></div>
          </div>
          <p class="mt-3 text-sm font-semibold text-slate-600">Nombre que se imprimirá (el histórico, sin cambios): <strong data-nombre-impreso>{{ d.valor.nombre_impreso }}</strong></p>

          <fieldset class="mt-4 space-y-2" data-reglas>
            <legend :class="ETQ">Cómo se corregirá el documento</legend>
            <label v-for="r in d.valor.reglas" :key="r.regla" class="flex items-start gap-2 text-sm font-semibold text-slate-700 rounded-2xl border border-slate-200 px-3 py-2 cursor-pointer">
              <input v-model="reglaId" type="radio" name="regla" :value="r.regla" class="mt-1" :data-regla="r.regla" />
              <span><span class="font-bold">{{ r.etiqueta }}</span><span class="block text-[13px] text-slate-500">{{ r.ayuda }}</span></span>
            </label>
          </fieldset>

          <div v-if="regla?.editable" class="mt-4">
            <label class="block"><span :class="ETQ">{{ esManual ? "Valor confirmado" : "Valor aprobado" }}</span><input v-model="valorDoc" type="text" maxlength="40" :class="INPUT" data-input-valor /></label>
          </div>
          <div v-if="esManual" class="mt-4 space-y-3 rounded-2xl border border-amber-200 bg-amber-50 p-4" data-bloque-evidencia>
            <p class="flex items-start gap-2 text-sm font-semibold text-amber-800"><TriangleAlert class="w-4 h-4 mt-0.5 shrink-0" /> No hay una corrección automática segura para este documento. Solo puede emitirse con evidencia externa del valor correcto.</p>
            <label class="flex items-center gap-2 text-sm font-bold text-slate-700"><input v-model="tengoEvidencia" type="checkbox" data-tengo-evidencia /> Tengo evidencia externa del valor correcto</label>
            <label v-if="tengoEvidencia" class="block"><span :class="ETQ">Describe la evidencia (mínimo {{ d.valor.evidencia_min }} caracteres)</span><textarea v-model="evidencia" rows="3" :maxlength="d.valor.evidencia_max" :class="INPUT" data-input-evidencia /></label>
          </div>
          <label v-if="regla?.requiere_confirmacion" class="mt-4 flex items-start gap-2 text-sm font-bold" :class="regla.reforzada ? 'text-rose-700' : 'text-slate-700'" data-confirmar-valor>
            <input v-model="confirmadoValor" type="checkbox" class="mt-1" />
            <span v-if="regla.reforzada">Confirmo de forma reforzada que el signo NO forma parte del número y que el valor corregido es exactamente el que quedará impreso.</span>
            <span v-else>Confirmo que este es el valor que debe quedar impreso en el certificado corregido.</span>
          </label>
          <p v-else class="mt-4 text-[13px] font-semibold text-slate-500">Quitar un carácter invisible no cambia ninguna letra ni dígito; igualmente revisa la vista previa antes de emitir.</p>
        </section>

        <!-- PASO 3 · Plantilla -->
        <section :class="[CAJA, 'lg:col-span-2']" data-paso="3">
          <h3 class="text-base font-extrabold text-slate-900">3 · Seleccionar o preparar la plantilla moderna</h3>
          <div v-if="d.plantilla.clon" class="mt-3 rounded-2xl border border-slate-200 p-4 text-sm font-semibold text-slate-700" data-clon>
            <div class="flex flex-wrap items-center gap-2"><strong>{{ d.plantilla.clon.nombre }}</strong><Insignia etiqueta="Clon de la plantilla histórica" tono="slate" /><Insignia :etiqueta="d.plantilla.clon.diseno_confirmado ? 'Diseño confirmado' : 'Diseño pendiente de revisión'" :tono="d.plantilla.clon.diseno_confirmado ? 'emerald' : 'amber'" /></div>
            <p class="mt-1 text-[13px] text-slate-500" v-if="d.plantilla.clon.meta">Imagen histórica {{ n(d.plantilla.clon.meta.ancho_px) }}×{{ n(d.plantilla.clon.meta.alto_px) }} px ({{ d.plantilla.clon.meta.mime }}) · {{ d.plantilla.clon.meta.algoritmo === "incrustacion_directa" ? "incrustada sin pérdida" : "reescalada" }} · PDF base {{ n(Math.round((d.plantilla.clon.meta.bytes_pdf ?? 0) / 1024)) }} KB · generada {{ fecha(d.plantilla.clon.meta.generado_at) }} · origen {{ d.plantilla.clon.meta.sha_origen }}</p>
            <p v-if="d.plantilla.clon.advertencias.includes('IMAGEN_MUY_GRANDE')" class="mt-3 flex items-start gap-2 text-amber-800 bg-amber-50 border border-amber-200 rounded-2xl p-3" data-advertencia-imagen><TriangleAlert class="w-4 h-4 mt-0.5 shrink-0" /> Esta plantilla proviene de una imagen histórica de alta resolución. Revise visualmente el certificado antes de emitir.</p>
          </div>
          <div v-else class="mt-3 text-sm font-semibold text-slate-600" data-sin-clon>
            <p>Todavía no existe una plantilla moderna para la imagen de este certificado. «Preparar» crea una (una sola vez por imagen) a partir de la imagen histórica, sin modificarla. Las imágenes grandes pueden tardar unos segundos.</p>
            <button type="button" :class="[BTN, 'mt-3']" :disabled="cargandoClon" data-preparar-clon @click="prepararClon"><Loader2 v-if="cargandoClon" class="w-4 h-4 animate-spin" /> {{ cargandoClon ? "Preparando…" : "Preparar plantilla moderna" }}</button>
          </div>

          <label class="mt-4 block"><span :class="ETQ">Plantilla que se usará</span>
            <select v-model="plantillaId" :class="INPUT" data-select-plantilla>
              <option :value="null" disabled>Elige una plantilla</option>
              <option v-for="p in candidatas" :key="p.id" :value="p.id">{{ p.nombre }}{{ p.es_clon ? " (clon histórico)" : "" }}</option>
            </select>
          </label>
          <div v-if="plantilla" class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3" data-campos-plantilla>
            <label v-if="plantilla.requiere_fecha" class="block"><span :class="ETQ">Fecha (la plantilla la imprime: obligatoria)</span><input v-model="fechaTxt" type="text" maxlength="80" :class="INPUT" data-input-fecha /></label>
            <label v-if="plantilla.requiere_intensidad" class="block"><span :class="ETQ">Intensidad horaria (la plantilla la imprime: obligatoria)</span><input v-model="intensidadTxt" type="text" maxlength="255" :class="INPUT" data-input-intensidad /></label>
            <p v-if="!plantilla.requiere_fecha && !plantilla.requiere_intensidad" class="text-[13px] font-semibold text-slate-500 md:col-span-2" data-sin-campos-extra>Esta plantilla no imprime fecha ni intensidad horaria: no hace falta ningún dato adicional.</p>
            <p v-if="d.evidencia.evento && !plantilla.imprime_evento" class="text-[13px] font-semibold text-slate-500 md:col-span-2">El evento (<strong>{{ d.evidencia.evento.nombre }}</strong>) es informativo: ya está en la imagen de la plantilla y no se vuelve a imprimir.</p>
          </div>
        </section>

        <!-- PASO 4 · Diseño -->
        <section :class="[CAJA, 'lg:col-span-2']" data-paso="4">
          <h3 class="text-base font-extrabold text-slate-900">4 · Revisar el diseño</h3>
          <p class="mt-2 text-sm font-semibold text-slate-500">El clon no se considera listo automáticamente: abre el editor, revisa la posición del nombre, el documento y el QR, y guarda. Luego vuelve aquí y confirma el diseño.</p>
          <div v-if="plantilla" class="mt-3 flex flex-wrap items-center gap-3">
            <a :href="plantilla.url_editor" target="_blank" rel="noopener" :class="BTN2" data-editar-diseno>Editar diseño (abre el editor)</a>
            <button type="button" :class="BTN2" data-actualizar @click="actualizar">Ya lo revisé: actualizar</button>
            <button v-if="plantilla.es_clon" type="button" :class="BTN" :disabled="cargandoDiseno || plantilla.diseno_confirmado" data-confirmar-diseno @click="confirmarDiseno"><Loader2 v-if="cargandoDiseno" class="w-4 h-4 animate-spin" /> {{ plantilla.diseno_confirmado ? "Diseño confirmado" : "Confirmar diseño para reemplazos" }}</button>
          </div>
          <p v-else class="mt-3 text-sm font-semibold text-slate-400">Elige primero una plantilla.</p>
        </section>

        <!-- PASO 5 · Vista previa -->
        <section :class="[CAJA, 'lg:col-span-2']" data-paso="5">
          <h3 class="text-base font-extrabold text-slate-900">5 · Vista previa</h3>
          <p class="mt-2 text-sm font-semibold text-slate-500">Se genera con los datos aprobados y no guarda nada: ni emisión, ni código, ni archivo. Contiene datos personales: solo es visible aquí.</p>
          <button type="button" :class="[BTN, 'mt-3']" :disabled="!puedePreview" data-generar-preview @click="generarPreview"><Loader2 v-if="cargandoPreview" class="w-4 h-4 animate-spin" /><FileSearch v-else class="w-4 h-4" /> {{ cargandoPreview ? "Generando…" : previewUrl ? "Regenerar vista previa" : "Generar vista previa" }}</button>
          <p v-if="avisoCaducada" class="mt-3 text-sm font-bold text-amber-700" data-preview-caducada>Cambiaste un dato: la vista previa anterior ya no es válida. Genérala de nuevo.</p>
          <p v-if="errorPreview" class="mt-3 flex items-start gap-2 text-sm font-bold text-rose-700" data-error-preview><TriangleAlert class="w-4 h-4 mt-0.5 shrink-0" /> {{ errorPreview }}</p>
          <iframe v-if="previewUrl" :src="previewUrl" title="Vista previa del certificado" class="mt-4 w-full h-[32rem] rounded-2xl border border-slate-200" data-preview-frame />
        </section>

        <!-- PASOS 6 y 7 · Motivo, confirmaciones, emisión -->
        <section :class="[CAJA, 'lg:col-span-2']" data-paso="6">
          <h3 class="text-base font-extrabold text-slate-900">6 · Motivo y confirmación</h3>
          <label class="mt-3 block"><span :class="ETQ">Motivo (obligatorio, mínimo {{ d.motivo.min }} caracteres; sin datos personales)</span><textarea v-model="motivo" rows="3" :maxlength="d.motivo.max" :class="INPUT" data-input-motivo /></label>
          <p v-if="errores.motivo" class="text-sm font-semibold text-rose-600">{{ errores.motivo }}</p>
          <label class="mt-3 flex items-start gap-2 text-sm font-bold text-slate-700"><input v-model="confirmoRevision" type="checkbox" class="mt-1" data-confirmo-revision /> He revisado el dato corregido y la vista previa.</label>
          <p v-if="errores.confirmo_revision" class="text-sm font-semibold text-rose-600">{{ errores.confirmo_revision }}</p>
          <button type="button" :class="[BTN, 'mt-4']" :disabled="!puedeEmitir" data-abrir-emision @click="modal = true; confirmoFinal = false">Continuar a la emisión</button>
          <p v-if="!disenoListo && plantilla" class="mt-2 text-[13px] font-semibold text-slate-400">Falta confirmar el diseño de la plantilla (paso 4).</p>
        </section>
      </div>

      <div v-if="modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50" role="dialog" aria-modal="true" aria-label="Confirmar emisión del reemplazo" data-modal-emision @keydown.esc="modal = false">
        <div class="w-full max-w-lg bg-white rounded-[2rem] shadow-xl p-6 space-y-4">
          <div class="flex items-start justify-between gap-3">
            <h3 class="text-lg font-extrabold text-slate-900">Confirmar emisión del reemplazo</h3>
            <button type="button" class="p-1 text-slate-400 hover:text-slate-700" aria-label="Cerrar" @click="modal = false"><X class="w-5 h-5" /></button>
          </div>
          <dl class="text-sm font-semibold text-slate-700 space-y-2" data-resumen-final>
            <div><dt :class="ETQ">Certificado histórico</dt><dd class="font-mono">{{ d.evidencia.documento_historico }} · caso {{ d.caso.id }}</dd></div>
            <div v-if="d.evidencia.evento"><dt :class="ETQ">Evento</dt><dd>{{ d.evidencia.evento.nombre }}</dd></div>
            <div><dt :class="ETQ">Plantilla</dt><dd>{{ plantilla?.nombre }}</dd></div>
            <div v-if="esDif"><dt :class="ETQ">Dato que cambia</dt><dd>Nombre: {{ nombreHistoricoMasc }} → <span data-resumen-valor>{{ mascara(valorNombre ?? "") }}</span> · documento sin cambios</dd></div>
            <div v-else><dt :class="ETQ">Dato que cambia</dt><dd>Documento: <span class="font-mono">{{ d.evidencia.documento_historico }}</span> → <span class="font-mono" data-resumen-valor>{{ mascara(valorFinal) }}</span></dd></div>
            <div><dt :class="ETQ">Consecuencia</dt><dd class="text-rose-700">El certificado histórico quedará reemplazado y se emitirá un certificado moderno nuevo. Esta acción no se deshace: si hay un error se revoca o se reemite.</dd></div>
          </dl>
          <label class="flex items-start gap-2 text-sm font-bold text-slate-700"><input v-model="confirmoFinal" type="checkbox" class="mt-1" data-confirmo-final /> Entiendo que el histórico quedará reemplazado.</label>
          <div class="flex justify-end gap-3">
            <button type="button" :class="BTN2" @click="modal = false">Volver</button>
            <button type="button" :class="BTN" :disabled="!confirmoFinal || emitiendo" data-emitir-reemplazo @click="emitir"><Loader2 v-if="emitiendo" class="w-4 h-4 animate-spin" /> Emitir reemplazo</button>
          </div>
        </div>
      </div>
    </Sidebar>
  </AuthenticatedLayout>
</template>
