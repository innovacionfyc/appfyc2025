<script setup>
// «Corregir toda la base»: revisa qué certificados vigentes de ESTA base ya no coinciden con sus datos, muestra el PDF anterior y el propuesto de una persona,
// y al confirmar los reemite: primero los oficiales y después los QA, cada grupo todo o nada. El resultado se muestra por grupo (COMPLETO, PARCIAL o SIN CAMBIOS).
// Revisar no escribe nada. Nada de esto envía correos: el envío es una decisión aparte.
import { ref, computed, watch } from "vue";
import axios from "axios";
import { X, CircleAlert, CircleCheck, Loader2, RefreshCw, SquarePen, TriangleAlert, ExternalLink, Mail } from "lucide-vue-next";

const props = defineProps({
  show: { type: Boolean, default: false },
  loteId: { type: Number, required: true },
});
const emit = defineEmits(["close", "editar", "hecho"]);

const plan = ref(null);
const cargando = ref(false);
const error = ref(null);
const elegida = ref(null); // emision_id de la persona para el antes y el después
const motivo = ref("");
const confirmo = ref(false);
const enviando = ref(false);
const resultado = ref(null);

const GRUPOS = [
  { clave: "oficiales", titulo: "Oficiales" },
  { clave: "qa", titulo: "Pruebas internas (QA)" },
];
const MOTIVOS = { datos: "datos de la base o de la persona", plantilla: "plantilla", diseno: "diseño", pdf_base: "PDF base" };

async function revisar() {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await axios.get(route("credential-flow.lotes.correccion", props.loteId));
    plan.value = data.plan;
    const personas = [...(data.plan.grupos.oficiales?.personas ?? []), ...(data.plan.grupos.qa?.personas ?? [])];
    if (!personas.some((p) => p.emision_id === elegida.value)) elegida.value = personas[0]?.emision_id ?? null;
  } catch (e) {
    error.value = e.response?.data?.error ?? { code: "ERROR", message: "No se pudo revisar la base. Inténtalo de nuevo." };
  } finally {
    cargando.value = false;
  }
}

watch(
  () => props.show,
  (v) => {
    if (!v) return;
    resultado.value = null;
    motivo.value = "";
    confirmo.value = false;
    revisar();
  },
);

const grupo = (clave) => plan.value?.grupos?.[clave] ?? null;
const n = (clave) => grupo(clave)?.a_reemitir ?? 0;
const persona = computed(() => {
  for (const g of GRUPOS) {
    const p = grupo(g.clave)?.personas?.find((x) => x.emision_id === elegida.value);
    if (p) return { ...p, grupo: g.clave };
  }
  return null;
});
const urlAnterior = computed(() => (persona.value ? route("credential-flow.lotes.correccion.anterior", [props.loteId, persona.value.emision_id]) : null));
const urlPropuesto = computed(() => (persona.value ? route("credential-flow.lotes.correccion.propuesto", [props.loteId, persona.value.emision_id]) : null));

const largo = computed(() => motivo.value.trim().length);
const motivoValido = computed(() => largo.value >= 10 && largo.value <= 500);
const puedeConfirmar = computed(() => !!plan.value && plan.value.total > 0 && !plan.value.bloqueo && motivoValido.value && confirmo.value && !enviando.value && !cargando.value);
const textoBoton = computed(() => {
  const partes = [];
  if (n("oficiales")) partes.push(`${n("oficiales")} ${n("oficiales") === 1 ? "oficial" : "oficiales"}`);
  if (n("qa")) partes.push(`${n("qa")} QA`);
  return `Reemitir ${partes.join(" + ")}`;
});

const fecha = (iso) => {
  if (!iso) return "";
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? "" : new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "short", year: "numeric", timeZone: "America/Bogota" }).format(d);
};

async function confirmar() {
  if (!puedeConfirmar.value) return;
  enviando.value = true;
  error.value = null;
  try {
    const { data } = await axios.post(route("credential-flow.lotes.correccion.reemitir", props.loteId), { huella: plan.value.huella, motivo: motivo.value.trim(), confirmo: true });
    resultado.value = data;
  } catch (e) {
    const r = e.response;
    if (r?.status === 422 && r.data?.errors) error.value = { code: "VALIDACION", message: Object.values(r.data.errors).flat().join(" ") };
    else if (r?.status === 429) error.value = { code: "DEMASIADAS_SOLICITUDES", message: "Demasiadas solicitudes seguidas. Espera un minuto e inténtalo de nuevo." };
    else if (r?.data?.estado) resultado.value = r.data;
    else error.value = r?.data?.error ?? { code: "ERROR", message: "No se pudo completar la corrección. Inténtalo de nuevo." };
  } finally {
    enviando.value = false;
  }
  if (resultado.value && Object.values(resultado.value.grupos ?? {}).some((g) => g.estado === "reemitido")) emit("hecho");
}

async function revisarDeNuevo() {
  resultado.value = null;
  confirmo.value = false;
  await revisar();
}

const ESTADOS = {
  COMPLETO: { titulo: "Corrección completa", clase: "border-emerald-200 bg-emerald-50 text-emerald-900" },
  PARCIAL: { titulo: "Corrección parcial: falta un grupo", clase: "border-amber-300 bg-amber-50 text-amber-950" },
  SIN_CAMBIOS: { titulo: "Sin cambios", clase: "border-slate-200 bg-slate-50 text-slate-800" },
};
function textoGrupo(g) {
  switch (g?.estado) {
    case "reemitido":
      return `Reemitidos ${g.total} de ${g.esperados}.` + (g.restantes ? ` Quedan ${g.restantes} por corregir: vuelve a revisar.` : "");
    case "al_dia":
      return "Ya estaban al día: no se reemitió nada.";
    case "fallido":
      return `No se reemitió ninguno (${g.esperados} pendientes): sus certificados vigentes y sus PDF siguen intactos.`;
    case "no_intentado":
      return g.esperados ? `No se intentó (${g.esperados} pendientes): nada cambió.` : "Nada que reemitir.";
    default:
      return "—";
  }
}
const cerrar = () => !enviando.value && emit("close");
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="fixed inset-0 z-[110] flex items-start sm:items-center justify-center p-2 sm:p-4 overflow-y-auto">
      <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" @click="cerrar" />
      <div role="dialog" aria-modal="true" aria-labelledby="cf-corregir-titulo" class="relative z-50 w-full max-w-5xl bg-white rounded-[2rem] shadow-2xl p-4 sm:p-7 my-2 max-h-[calc(100vh-1rem)] overflow-y-auto" data-modal-corregir-base>
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <h3 id="cf-corregir-titulo" class="text-lg font-black text-slate-900">Corregir toda la base</h3>
            <p class="text-[13px] font-semibold text-slate-500 mt-0.5">Reemite los certificados vigentes que ya no coinciden con los datos de esta base. Solo afecta a esta base.</p>
          </div>
          <button type="button" class="w-9 h-9 shrink-0 flex items-center justify-center rounded-2xl text-slate-400 hover:text-slate-700 hover:bg-slate-100" aria-label="Cerrar" :disabled="enviando" @click="cerrar">
            <X class="w-5 h-5" />
          </button>
        </div>

        <!-- 1. Dato común -->
        <div class="mt-5 rounded-2xl border border-slate-200 px-4 py-3 flex flex-wrap items-center justify-between gap-3" data-paso-dato>
          <p class="text-[13px] font-medium text-slate-600 min-w-0 flex-1 basis-72">
            <span class="font-black text-slate-800">1. Corrige el dato común</span> (evento, fecha, intensidad, modalidad o ciudad) con «Editar base». Solo cambia esta base: la capacitación asociada y sus otras bases no se tocan. Guardar no reemite nada.
          </p>
          <button type="button" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-slate-100 text-[13px] font-bold text-slate-600 hover:bg-slate-200 disabled:opacity-50" :disabled="enviando" data-accion="ir-editar-base" @click="emit('editar')">
            <SquarePen class="w-4 h-4" /> Editar base
          </button>
        </div>

        <div v-if="cargando && !plan" class="mt-6 flex items-center gap-2 text-sm font-bold text-slate-500"><Loader2 class="w-4 h-4 animate-spin" /> Revisando los certificados de la base…</div>

        <div v-if="error" class="mt-4 flex items-start gap-2 px-3 py-2 rounded-xl bg-red-50 text-red-700 text-[13px] font-semibold" role="alert" :data-codigo="error.code">
          <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ error.message }}</span>
        </div>

        <template v-if="plan && !resultado">
          <!-- 2. Cantidades -->
          <div class="mt-5">
            <p class="text-[13px] font-black text-slate-800">2. Qué se reemplazará</p>
            <div class="mt-2 grid gap-3 sm:grid-cols-2">
              <div v-for="g in GRUPOS" :key="g.clave" class="rounded-2xl border border-slate-200 px-4 py-3" :data-grupo="g.clave">
                <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">{{ g.titulo }}</p>
                <p class="mt-1 text-sm font-extrabold text-slate-900">
                  <span :data-a-reemitir="n(g.clave)">Se reemplazarán {{ n(g.clave) }}</span>
                  <span class="font-semibold text-slate-500"> · ya correctos {{ grupo(g.clave)?.ya_correctas ?? 0 }}</span>
                </p>
                <p v-if="n(g.clave)" class="mt-1 text-[12px] font-semibold text-slate-500">
                  Por: {{ Object.entries(grupo(g.clave).motivos).filter(([, v]) => v > 0).map(([k, v]) => `${MOTIVOS[k] ?? k} (${v})`).join(", ") }}
                </p>
                <p v-if="grupo(g.clave)?.desactualizadas > n(g.clave)" class="mt-1 text-[12px] font-semibold text-amber-700">Hay {{ grupo(g.clave).desactualizadas }}: se hacen {{ n(g.clave) }} por vez; repite para el resto.</p>
                <div v-if="grupo(g.clave)?.bloqueo && n(g.clave)" class="mt-2 flex items-start gap-2 rounded-xl bg-red-50 px-3 py-2 text-[12px] font-semibold text-red-700" role="alert" :data-bloqueo="grupo(g.clave).bloqueo.code">
                  <TriangleAlert class="w-4 h-4 mt-0.5 shrink-0" /><span>{{ grupo(g.clave).bloqueo.message }}</span>
                </div>
              </div>
            </div>
            <div v-if="plan.total === 0" class="mt-3 flex flex-wrap items-start gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-[13px] font-semibold text-emerald-900" data-sin-cambios>
              <CircleCheck class="w-4 h-4 mt-0.5 shrink-0" />
              <span class="min-w-0 flex-1 basis-60">Todos los certificados vigentes ya coinciden con los datos de esta base: no hay nada que reemitir. Si acabas de corregir un dato, guárdalo con «Editar base» y pulsa «Revisar de nuevo».</span>
              <button type="button" class="shrink-0 inline-flex items-center gap-1.5 rounded-xl bg-white px-3 py-1.5 text-[12px] font-bold text-emerald-800 hover:bg-emerald-100 disabled:opacity-50" :disabled="cargando" data-accion="revisar-de-nuevo" @click="revisarDeNuevo">
                <RefreshCw class="w-3.5 h-3.5" :class="cargando ? 'animate-spin' : ''" /> Revisar de nuevo
              </button>
            </div>
            <p v-if="plan.bloqueo" class="mt-2 text-[12px] font-bold text-red-700">Mientras haya un bloqueo no se puede continuar: no se reemitirá ningún grupo.</p>
          </div>

          <!-- 3. Antes y después -->
          <div v-if="plan.total > 0" class="mt-6" data-antes-despues>
            <div class="flex flex-wrap items-end justify-between gap-3">
              <p class="text-[13px] font-black text-slate-800">3. Antes y después</p>
              <label class="flex items-center gap-2 text-[12px] font-bold text-slate-500 min-w-0">
                Persona
                <select v-model="elegida" class="rounded-xl border-slate-200 text-[13px] font-semibold max-w-[16rem] sm:max-w-xs" data-elegir-persona>
                  <template v-for="g in GRUPOS" :key="g.clave">
                    <optgroup v-if="grupo(g.clave)?.personas?.length" :label="g.titulo">
                      <option v-for="p in grupo(g.clave).personas" :key="p.emision_id" :value="p.emision_id">{{ p.nombre }}</option>
                    </optgroup>
                  </template>
                </select>
              </label>
            </div>
            <div v-if="persona" class="mt-2 grid gap-3 lg:grid-cols-2">
              <div class="rounded-2xl border border-slate-200 overflow-hidden" data-pdf="anterior">
                <div class="flex items-center justify-between gap-2 bg-slate-50 px-3 py-2">
                  <p class="text-[12px] font-bold text-slate-600 min-w-0">
                    <span class="font-black text-slate-800">Anterior (vigente)</span> · <span class="font-mono">{{ persona.codigo }}</span> · v{{ persona.version }} · {{ fecha(persona.emitido_at) }}
                  </p>
                  <a :href="urlAnterior" target="_blank" rel="noopener" class="shrink-0 inline-flex items-center gap-1 text-[12px] font-bold text-primary-vinotinto hover:underline">Abrir <ExternalLink class="w-3.5 h-3.5" /></a>
                </div>
                <iframe :key="'a' + persona.emision_id" :src="urlAnterior" title="Certificado anterior" class="w-full h-[22rem] sm:h-[26rem] bg-slate-100" />
              </div>
              <div class="rounded-2xl border border-primary-vinotinto/30 overflow-hidden" data-pdf="propuesto">
                <div class="flex items-center justify-between gap-2 bg-primary-vinotinto/5 px-3 py-2">
                  <p class="text-[12px] font-bold text-slate-600 min-w-0"><span class="font-black text-slate-800">Propuesto</span> · con los datos actuales · el código nuevo se asigna al confirmar</p>
                  <a :href="urlPropuesto" target="_blank" rel="noopener" class="shrink-0 inline-flex items-center gap-1 text-[12px] font-bold text-primary-vinotinto hover:underline">Abrir <ExternalLink class="w-3.5 h-3.5" /></a>
                </div>
                <iframe :key="'p' + persona.emision_id" :src="urlPropuesto" title="Certificado propuesto" class="w-full h-[22rem] sm:h-[26rem] bg-slate-100" />
              </div>
            </div>
          </div>

          <!-- 4. Confirmación -->
          <div v-if="plan.total > 0" class="mt-6 rounded-2xl border border-amber-200 bg-amber-50/60 px-4 py-4" data-confirmacion>
            <p class="text-[13px] font-black text-slate-800">4. Confirmar</p>
            <p class="mt-1 text-[13px] font-medium text-slate-600">
              Primero se reemiten los oficiales y después los QA; cada grupo se hace completo o no se hace. Los certificados anteriores quedan <strong>revocados</strong> como
              «reemplazados» (sus PDF se conservan) y los nuevos tienen códigos nuevos. <strong>No se envía ningún correo.</strong>
            </p>
            <label class="mt-3 block text-[11px] font-black uppercase tracking-widest text-slate-500" for="cf-corregir-motivo">Motivo (obligatorio)</label>
            <textarea id="cf-corregir-motivo" v-model="motivo" rows="2" maxlength="500" class="mt-1 w-full rounded-2xl border-slate-200 text-sm font-medium focus:ring-primary-vinotinto/30 focus:border-primary-vinotinto" placeholder="Por ejemplo: nombre del evento mal escrito en la base (mínimo 10 caracteres)" data-motivo-correccion />
            <p class="text-right text-[11px] font-bold" :class="motivoValido || largo === 0 ? 'text-slate-400' : 'text-rose-500'">{{ largo }}/500</p>
            <label class="mt-1 flex items-start gap-2 text-[13px] font-semibold text-slate-700">
              <input v-model="confirmo" type="checkbox" class="mt-0.5 rounded border-slate-300 text-primary-vinotinto focus:ring-primary-vinotinto/30" data-casilla-confirmo />
              <span>Entiendo que los códigos anteriores quedan revocados.</span>
            </label>
            <div class="mt-4 flex flex-wrap justify-end gap-2">
              <button type="button" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-slate-100 text-[13px] font-bold text-slate-600 hover:bg-slate-200 disabled:opacity-50" :disabled="enviando || cargando" data-accion="revisar-de-nuevo" @click="revisarDeNuevo">
                <RefreshCw class="w-4 h-4" :class="cargando ? 'animate-spin' : ''" /> Revisar de nuevo
              </button>
              <button type="button" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-[13px] font-bold hover:opacity-90 disabled:opacity-50" :disabled="!puedeConfirmar" data-accion="confirmar-correccion" @click="confirmar">
                <Loader2 v-if="enviando" class="w-4 h-4 animate-spin" />
                {{ enviando ? "Reemitiendo…" : textoBoton }}
              </button>
            </div>
          </div>
        </template>

        <!-- Resultado -->
        <div v-if="resultado" class="mt-5 space-y-3" data-resultado :data-estado="resultado.estado">
          <div class="rounded-2xl border px-4 py-3" :class="ESTADOS[resultado.estado]?.clase">
            <p class="text-sm font-black">{{ ESTADOS[resultado.estado]?.titulo ?? resultado.estado }}</p>
            <p v-if="resultado.error" class="mt-1 text-[13px] font-semibold">{{ resultado.error.message }}</p>
            <p v-if="resultado.estado === 'PARCIAL'" class="mt-1 text-[13px] font-semibold">Pulsa «Revisar de nuevo»: aparecerá solo lo pendiente y lo ya reemitido no se repite.</p>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <div v-for="g in GRUPOS" :key="g.clave" class="rounded-2xl border border-slate-200 px-4 py-3" :data-resultado-grupo="g.clave" :data-estado-grupo="resultado.grupos?.[g.clave]?.estado">
              <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">{{ g.titulo }}</p>
              <p class="mt-1 text-sm font-extrabold" :class="resultado.grupos?.[g.clave]?.estado === 'fallido' ? 'text-red-700' : 'text-slate-900'">{{ textoGrupo(resultado.grupos?.[g.clave]) }}</p>
              <p v-if="resultado.grupos?.[g.clave]?.error" class="mt-1 text-[12px] font-semibold text-red-700">{{ resultado.grupos[g.clave].error.message }}</p>
            </div>
          </div>
          <p class="flex items-start gap-2 text-[13px] font-semibold text-slate-600" data-sin-correos>
            <Mail class="w-4 h-4 mt-0.5 shrink-0" /> No se envió ningún correo. Enviar los certificados nuevos es una decisión aparte.
          </p>
          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-slate-100 text-[13px] font-bold text-slate-600 hover:bg-slate-200" data-accion="revisar-de-nuevo" @click="revisarDeNuevo">
              <RefreshCw class="w-4 h-4" /> Revisar de nuevo
            </button>
            <button type="button" class="px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-[13px] font-bold hover:opacity-90" @click="cerrar">Cerrar</button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
