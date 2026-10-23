<script setup>
// Paso 2 · Acciones sobre varias personas (F5). Se selecciona, se elige QUÉ proponer, se revisa EXACTAMENTE a quién afecta y solo entonces se guarda en el borrador.
// Nada se aprueba solo: «Completo» no vuelve elegible a nadie por sí mismo y el tipo de documento nunca tiene un valor por defecto. Todo ocurre en el borrador:
// no se crea la base ni se emite ningún certificado. El servidor vuelve a comprobar cada regla; esta pantalla solo la explica.
import { computed, nextTick, ref, watch } from "vue";
import { CircleCheck, ListChecks, RotateCcw, TriangleAlert, X } from "lucide-vue-next";
import { ACCIONES_MASIVAS, ELEGIBILIDADES, MOTIVOS_OMISION, TIPOS_DOCUMENTO, etiquetaEstado, useAsistente } from "@/Composables/CredentialFlow/importacionInteligente";

const props = defineProps({
  lista: { type: Array, required: true }, // personas del filtro actual (con la búsqueda aplicada)
  visibles: { type: Number, required: true }, // cuántas de esas se están mostrando
  filtroNombre: { type: String, default: "" }, // nombre del filtro activo, para decir «las 16 de “Completo”»
  hayFiltro: { type: Boolean, default: false },
  // «barra»: solo la selección y los botones; «panel»: solo la revisión de la acción, su resultado y el historial; «todo»: ambas.
  parte: { type: String, default: "todo" },
});
const emit = defineEmits(["ver-seleccionadas"]);

const a = useAsistente();
const m = a.masiva;
const MUESTRA = 100;
const hayBarra = computed(() => props.parte !== "panel");
const hayPanel = computed(() => props.parte !== "barra"); // personas que se listan en el detalle (el resumen cuenta a todas)

const seleccionables = computed(() => props.lista.filter(a.esSeleccionable));
const enPantalla = computed(() => props.lista.slice(0, props.visibles).filter(a.esSeleccionable));
const total = computed(() => a.seleccionadas.value.length);
const visiblesMarcadas = computed(() => enPantalla.value.filter((p) => a.seleccion.value.has(p.fila)).length);
const delFiltroMarcadas = computed(() => seleccionables.value.filter((p) => a.seleccion.value.has(p.fila)).length);
const otras = computed(() => total.value - visiblesMarcadas.value);
const todasLasVisibles = computed(() => enPantalla.value.length > 0 && visiblesMarcadas.value === enPantalla.value.length);
const algunasLasVisibles = computed(() => visiblesMarcadas.value > 0 && !todasLasVisibles.value);
const noSeleccionables = computed(() => props.lista.length - seleccionables.value.length);

const casilla = ref(null);
watch([todasLasVisibles, algunasLasVisibles], () => {
  if (casilla.value) casilla.value.indeterminate = algunasLasVisibles.value;
}, { flush: "post" });

function alternarVisibles() {
  const filas = enPantalla.value.map((p) => p.fila);
  todasLasVisibles.value ? a.quitarSeleccion(filas) : a.seleccionar(filas);
}
const textoSeleccionarFiltro = computed(() => {
  const n = seleccionables.value.length;
  if (!props.hayFiltro) return n === 1 ? "Seleccionar a la única persona" : `Seleccionar las ${n} personas`;

  return n === 1 ? `Seleccionar a la única persona de «${props.filtroNombre}»` : `Seleccionar las ${n} de «${props.filtroNombre}»`;
});
const seleccionarFiltro = () => a.seleccionar(seleccionables.value.map((p) => p.fila));

// ── Panel de la acción ────────────────────────────────────────────────────────
const def = computed(() => ACCIONES_MASIVAS[m.campo] ?? null);
const v = computed(() => m.vista);
const titulo = computed(() => (m.campo === "tipo_documento" ? `Asignar el tipo de documento a ${total.value} ${total.value === 1 ? "persona" : "personas"}` : `Proponer la elegibilidad de ${total.value} ${total.value === 1 ? "persona" : "personas"}`));
const conTipo = computed(() => a.seleccionadas.value.filter((p) => p.tipo_documento).length);
const sinTipo = computed(() => total.value - conTipo.value);

const grupos = computed(() => {
  const g = new Map();
  for (const c of v.value?.cambios ?? []) {
    const k = c.anterior ?? "__ninguno";
    g.set(k, (g.get(k) ?? 0) + 1);
  }
  return [...g.entries()].map(([k, n]) => ({ anterior: k === "__ninguno" ? null : k, n }));
});
const motivos = computed(() => {
  const g = new Map();
  for (const o of v.value?.omitidos ?? []) (g.get(o.motivo) ?? g.set(o.motivo, []).get(o.motivo)).push(o);
  return [...g.entries()].map(([motivo, personas]) => ({ motivo, texto: MOTIVOS_OMISION[motivo] ?? motivo, personas }));
});
const etiqueta = (valor) => etiquetaEstado(m.campo, valor);
const persona = (p) => p.nombre || `Fila ${p.fila}`;

const vistaTitulo = ref(null);
// Al aparecer el detalle de lo que cambiaría, el foco va a su título (lectores de pantalla y teclado). La acción «Revisar cambios» la dispara el botón principal de la barra de abajo.
watch(
  () => m.vista,
  async (v) => {
    if (!v) return;
    await nextTick();
    vistaTitulo.value?.focus();
  }
);
const entrada = ref(null);
// Al abrirse el panel (también desde la revisión guiada) el foco va a la primera opción.
watch(
  () => m.abierta,
  async (abierta) => {
    if (!abierta || !hayPanel.value) return;
    await nextTick();
    entrada.value?.focus({ preventScroll: true });
  }
);
async function abrir(campo) {
  a.abrirMasiva(campo);
  await nextTick();
  entrada.value?.focus();
}

// ── Historial ─────────────────────────────────────────────────────────────────
const razonAplicar = computed(() => a.razonAplicarMasiva.value);
const recientes = computed(() => [...a.historial.value].reverse().slice(0, 5));
const hora = (iso) => {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? "" : d.toLocaleTimeString("es-CO", { hour: "2-digit", minute: "2-digit" });
};
const describir = (h) => `${ACCIONES_MASIVAS[h.campo]?.titulo ?? h.campo}: ${etiquetaEstado(h.campo, h.valor)}`;
const personasTexto = (n) => `${n} ${n === 1 ? "persona" : "personas"}`;
const boton = "inline-flex items-center justify-center gap-1.5 rounded-xl px-3.5 py-2 text-[13px] font-extrabold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 disabled:opacity-50 disabled:cursor-not-allowed";
</script>

<template>
  <section v-if="hayBarra || m.abierta || m.resultado || recientes.length" class="rounded-2xl border border-sky-200 bg-sky-50/50 p-4 space-y-3" :aria-labelledby="hayBarra ? 'titulo-acciones-masivas' : undefined" :aria-label="hayBarra ? undefined : 'Revisión de varias personas'" :data-barra-seleccion="hayBarra ? '' : undefined" :data-panel-revision="hayPanel ? '' : undefined">
    <div v-if="hayBarra">
      <h4 id="titulo-acciones-masivas" class="flex items-center gap-2 text-[14px] font-extrabold text-slate-900"><ListChecks class="w-4 h-4 text-sky-700" aria-hidden="true" /> Seleccionar para revisar</h4>
      <p class="mt-0.5 text-[12.5px] font-semibold text-slate-600">
        Marca personas para revisarlas juntas: <strong>seleccionar no cambia quién entra a la base</strong> (eso se decide con «Incluir en la base» en cada persona). Elige qué proponer y revisa a quién afecta antes de guardarlo. Solo cambia el borrador: no crea la base ni emite certificados, y nada se aprueba solo.
      </p>
    </div>

    <div v-if="hayBarra" class="flex flex-wrap items-center gap-x-4 gap-y-2">
      <label class="inline-flex items-center gap-2 text-[13px] font-extrabold text-slate-700 cursor-pointer">
        <input ref="casilla" type="checkbox" class="rounded accent-[#942934]" :checked="todasLasVisibles" :disabled="enPantalla.length === 0" data-seleccionar-visibles @change="alternarVisibles" />
        Seleccionar las {{ enPantalla.length }} {{ enPantalla.length === 1 ? "visible" : "visibles" }}
      </label>
      <button
        type="button"
        :class="[boton, 'bg-white text-primary-vinotinto ring-1 ring-primary-vinotinto/30 hover:bg-primary-vinotinto/5']"
        :disabled="seleccionables.length === 0 || delFiltroMarcadas === seleccionables.length"
        data-seleccionar-filtro
        @click="seleccionarFiltro"
      >
        {{ textoSeleccionarFiltro }}
      </button>
      <button v-if="total" type="button" :class="[boton, 'text-slate-600 hover:bg-white']" data-quitar-seleccion @click="a.limpiarSeleccion()"><X class="w-3.5 h-3.5" aria-hidden="true" /> Quitar la selección</button>
    </div>
    <p v-if="hayBarra && noSeleccionables" class="text-[12px] font-semibold text-slate-500">
      {{ noSeleccionables }} {{ noSeleccionables === 1 ? "persona de esta lista no se puede seleccionar" : "personas de esta lista no se pueden seleccionar" }} (no se importan o se parecen a un contacto de control QA).
    </p>

    <p v-if="hayBarra" class="text-[13px] font-extrabold" :class="total ? 'text-sky-900' : 'text-slate-500'" role="status" aria-live="polite" data-conteo-seleccion>
      <template v-if="total === 0">Ninguna persona seleccionada.</template>
      <template v-else>
        {{ total }} {{ total === 1 ? "persona seleccionada" : "personas seleccionadas" }}
        <span class="font-semibold text-slate-600">
          · {{ visiblesMarcadas }} en la lista que ves<template v-if="otras"> y {{ otras }} en otros filtros, búsquedas o por cargar</template>
        </span>
        <button v-if="otras" type="button" class="ml-1 underline underline-offset-2 text-primary-vinotinto focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 rounded" data-ver-seleccionadas @click="emit('ver-seleccionadas')">Ver solo las seleccionadas</button>
      </template>
    </p>

    <div v-if="hayBarra && total && !m.abierta" class="flex flex-wrap gap-2" role="group" aria-label="Qué quieres hacer con la selección">
      <button type="button" :class="[boton, 'bg-primary-vinotinto text-white hover:bg-primary-vinotinto/90']" data-accion-masiva="elegibilidad" @click="abrir('elegibilidad')">Cambiar elegibilidad…</button>
      <button type="button" :class="[boton, 'bg-primary-vinotinto text-white hover:bg-primary-vinotinto/90']" data-accion-masiva="tipo_documento" @click="abrir('tipo_documento')">Asignar tipo de documento…</button>
    </div>

    <!-- Resultado de lo último que se aplicó -->
    <p v-if="hayPanel && m.resultado && !m.abierta" class="flex items-start gap-2 rounded-xl bg-emerald-50 px-3 py-2 text-[13px] font-extrabold text-emerald-800 ring-1 ring-emerald-200" role="status" data-resultado-masivo>
      <CircleCheck class="w-4 h-4 shrink-0 mt-0.5" aria-hidden="true" />
      <span v-if="m.resultado.repetida">Ese cambio ya estaba aplicado: no se repitió.</span>
      <span v-else>
        {{ ACCIONES_MASIVAS[m.resultado.campo]?.titulo }} → {{ etiquetaEstado(m.resultado.campo, m.resultado.valor) }}: se aplicó a {{ personasTexto(m.resultado.aplicados) }}<template v-if="m.resultado.omitidos"> ({{ m.resultado.omitidos }} no se {{ m.resultado.omitidos === 1 ? "tocó" : "tocaron" }})</template>. Quedó guardado en el borrador; todavía no se creó ninguna base.
      </span>
    </p>

    <!-- Panel de la acción -->
    <div v-if="hayPanel && m.abierta" class="rounded-2xl border border-slate-200 bg-white p-4 space-y-4" role="region" :aria-label="titulo" data-panel-masivo>
      <div>
        <h5 class="text-[14px] font-extrabold text-slate-900">{{ titulo }}</h5>
        <p class="text-[12.5px] font-semibold text-slate-500">Primero eliges, después revisas exactamente qué cambia, y solo entonces se guarda en el borrador. <button type="button" class="font-extrabold text-primary-vinotinto underline underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 rounded" data-ver-quienes @click="a.verPersonas('seleccionadas')">Ver quiénes son</button></p>
      </div>

      <!-- Resumen de quiénes son, ANTES de proponer nada -->
      <div class="rounded-xl bg-slate-50 px-3 py-2.5 space-y-1" data-resumen-seleccion>
        <p class="text-[13px] font-extrabold text-slate-800">Seleccionaste a {{ personasTexto(a.resumenSeleccion.value.total) }}</p>
        <ul class="flex flex-wrap gap-x-4 gap-y-0.5 text-[12.5px] font-semibold text-slate-600">
          <li v-if="a.resumenSeleccion.value.completos">{{ a.resumenSeleccion.value.completos }} con asistencia «Completo»</li>
          <li v-if="a.resumenSeleccion.value.cupos" class="text-amber-700">{{ a.resumenSeleccion.value.cupos }} con «Cupos»</li>
          <li v-if="a.resumenSeleccion.value.otros">{{ a.resumenSeleccion.value.otros }} sin asistencia clara</li>
          <li>{{ a.resumenSeleccion.value.pendientes }} con elegibilidad pendiente<template v-if="a.resumenSeleccion.value.elegibles">, {{ a.resumenSeleccion.value.elegibles }} ya elegibles</template><template v-if="a.resumenSeleccion.value.noElegibles">, {{ a.resumenSeleccion.value.noElegibles }} no elegibles</template></li>
          <li>{{ a.resumenSeleccion.value.sinTipo }} con el tipo de documento por confirmar</li>
        </ul>
        <p class="text-[12.5px] font-semibold text-slate-500 break-words">{{ a.resumenSeleccion.value.muestra.join(", ") }}<template v-if="a.resumenSeleccion.value.total > a.resumenSeleccion.value.muestra.length"> y {{ a.resumenSeleccion.value.total - a.resumenSeleccion.value.muestra.length }} más</template>.</p>
      </div>

      <fieldset v-if="m.campo === 'elegibilidad'" class="space-y-2">
        <legend class="text-[11px] font-black uppercase tracking-widest text-slate-400">¿Qué elegibilidad propones?</legend>
        <div class="flex flex-wrap gap-2">
          <label v-for="(e, i) in ELEGIBILIDADES" :key="e.valor" class="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-[13px] font-extrabold ring-1 cursor-pointer" :class="m.valor === e.valor ? 'bg-primary-vinotinto/10 text-primary-vinotinto ring-primary-vinotinto' : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50'">
            <input :ref="(el) => { if (i === 0) entrada = el; }" type="radio" name="elegibilidad-masiva" class="accent-[#942934]" :value="e.valor" :checked="m.valor === e.valor" :data-valor-masivo="e.valor" @change="a.elegirValorMasivo(e.valor)" />
            {{ e.etiqueta }}
          </label>
        </div>
        <ul class="space-y-1 text-[12px] font-semibold text-slate-500">
          <li>«Elegible» solo propone que la persona podrá certificarse: <strong>no emite nada</strong>. Las personas del Excel ya nacen elegibles; esto sirve para decidir a propósito, por ejemplo, a quienes el Excel declara «No elegible».</li>
        </ul>
      </fieldset>

      <div v-else class="space-y-2">
        <label for="tipo-masivo" class="block text-[11px] font-black uppercase tracking-widest text-slate-400">¿Qué tipo de documento tienen estas personas?</label>
        <select id="tipo-masivo" :ref="(el) => (entrada = el)" class="w-full sm:w-64 rounded-lg border-slate-300 text-[13px] font-semibold py-1.5 focus:border-primary-vinotinto focus:ring-primary-vinotinto/30" :value="m.valor" data-valor-masivo="tipo" @change="a.elegirValorMasivo($event.target.value)">
          <option value="">Elige un tipo…</option>
          <option v-for="t in TIPOS_DOCUMENTO" :key="t" :value="t">{{ t }}</option>
        </select>
        <ul class="space-y-1 text-[12px] font-semibold text-slate-500">
          <li>No se asigna ninguno por defecto ni se deduce del número del documento: tú lo compruebas y lo eliges.</li>
          <li :class="conTipo ? 'text-amber-700' : ''">De la selección, {{ conTipo }} {{ conTipo === 1 ? "ya tiene" : "ya tienen" }} un tipo y {{ sinTipo }} {{ sinTipo === 1 ? "está" : "están" }} por confirmar.<template v-if="conTipo"> Se te mostrará cuáles se reemplazarían y tendrás que confirmarlo.</template></li>
        </ul>
      </div>

      <p v-if="!m.valor" class="text-[12.5px] font-bold text-amber-700" data-razon-revisar>{{ m.campo === "tipo_documento" ? "Elige un tipo de documento arriba y pulsa «Revisar cambios» (botón de abajo)." : "Elige una elegibilidad arriba y pulsa «Revisar cambios» (botón de abajo)." }}</p>
      <p v-else-if="m.cargando" class="text-[12.5px] font-semibold text-slate-500" role="status">Revisando…</p>
      <p v-else-if="!v" class="text-[12.5px] font-semibold text-slate-500" data-sugerencia-revisar>Pulsa «Revisar cambios» (botón de abajo) para ver exactamente qué cambiaría. Todavía no se guarda nada.</p>

      <!-- Qué cambiaría, antes de guardar nada -->
      <div v-if="v" class="space-y-3 border-t border-slate-100 pt-4" data-vista-masiva>
        <h6 ref="vistaTitulo" tabindex="-1" class="text-[14px] font-extrabold text-slate-900 focus:outline-none">Esto es lo que cambiará</h6>

        <dl class="grid grid-cols-2 sm:grid-cols-4 gap-2 [&>div]:min-w-0 [&_dt]:break-words">
          <div class="rounded-xl bg-slate-50 px-3 py-2"><dd class="text-xl font-black tabular-nums text-slate-900" data-cuenta="cambian">{{ v.se_modifican }}</dd><dt class="text-[11.5px] font-semibold text-slate-500 leading-tight break-words">{{ v.se_modifican === 1 ? "persona cambiará" : "personas cambiarán" }}</dt></div>
          <div class="rounded-xl bg-slate-50 px-3 py-2"><dd class="text-xl font-black tabular-nums text-slate-900" data-cuenta="sin-cambio">{{ v.sin_cambio }}</dd><dt class="text-[11.5px] font-semibold text-slate-500 leading-tight">ya estaban así</dt></div>
          <div class="rounded-xl bg-slate-50 px-3 py-2"><dd class="text-xl font-black tabular-nums" :class="v.omitidos_total ? 'text-amber-700' : 'text-slate-900'" data-cuenta="omitidas">{{ v.omitidos_total }}</dd><dt class="text-[11.5px] font-semibold text-slate-500 leading-tight">no se tocarán</dt></div>
          <div class="rounded-xl bg-slate-50 px-3 py-2"><dd class="text-xl font-black tabular-nums" :class="v.sobrescriben ? 'text-amber-700' : 'text-slate-900'" data-cuenta="reemplazan">{{ v.sobrescriben }}</dd><dt class="text-[11.5px] font-semibold text-slate-500 leading-tight">reemplazan algo ya decidido</dt></div>
        </dl>

        <div v-if="v.se_modifican" class="space-y-2">
          <p class="text-[13px] font-extrabold text-slate-800">{{ def?.campo }}:</p>
          <ul class="space-y-0.5 text-[13px] font-semibold text-slate-700" data-grupos-masivo>
            <li v-for="g in grupos" :key="g.anterior ?? 'ninguno'">{{ etiqueta(g.anterior) }} → <strong>{{ etiqueta(v.valor) }}</strong> · {{ personasTexto(g.n) }}</li>
          </ul>
          <details class="rounded-xl bg-slate-50 px-3 py-2 text-[12.5px]" data-detalle-masivo>
            <summary class="cursor-pointer font-extrabold text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 rounded">Ver {{ v.se_modifican === 1 ? "la persona" : `las ${v.se_modifican} personas` }} que cambiarán</summary>
            <ul class="mt-2 max-h-72 overflow-y-auto space-y-1 pr-1">
              <li v-for="c in v.cambios.slice(0, MUESTRA)" :key="c.fila" class="flex flex-wrap items-baseline gap-x-2 font-semibold text-slate-700" :data-cambio-fila="c.fila">
                <span class="text-slate-400 font-black">Fila {{ c.fila }}</span>
                <span class="min-w-0 break-words">{{ persona(c) }}</span>
                <span class="text-slate-500">{{ etiqueta(c.anterior) }} → <strong class="text-slate-800">{{ etiqueta(c.propuesto) }}</strong></span>
                <span v-if="c.sobrescribe" class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-extrabold text-amber-800">Reemplaza una decisión</span>
              </li>
            </ul>
            <p v-if="v.se_modifican > MUESTRA" class="mt-1 text-slate-500">…y {{ v.se_modifican - MUESTRA }} más con el mismo cambio.</p>
          </details>
        </div>
        <p v-else class="rounded-xl bg-amber-50 px-3 py-2 text-[13px] font-extrabold text-amber-900 ring-1 ring-amber-200">Ninguna de las personas seleccionadas cambiaría: no hay nada que aplicar.</p>

        <div v-if="motivos.length" class="space-y-1.5 rounded-xl bg-amber-50/70 px-3 py-2 ring-1 ring-amber-200" data-omitidas-masivo>
          <p class="flex items-center gap-1.5 text-[13px] font-extrabold text-amber-900"><TriangleAlert class="w-4 h-4" aria-hidden="true" /> {{ v.omitidos_total === 1 ? "No se tocará" : "No se tocarán" }} {{ personasTexto(v.omitidos_total) }}</p>
          <ul class="space-y-1 text-[12.5px] font-semibold text-amber-950">
            <li v-for="g in motivos" :key="g.motivo" :data-motivo="g.motivo">
              {{ g.texto }} ({{ g.personas.length }}):
              <span class="font-normal">{{ g.personas.slice(0, 5).map(persona).join(", ") }}<template v-if="g.personas.length > 5"> y {{ g.personas.length - 5 }} más</template>.</span>
            </li>
          </ul>
        </div>

        <p v-if="v.sin_tipo_de_documento" class="flex items-start gap-1.5 text-[12.5px] font-semibold text-slate-600" data-aviso-sin-tipo>
          <TriangleAlert class="w-3.5 h-3.5 shrink-0 mt-0.5 text-amber-600" aria-hidden="true" />
          {{ v.sin_tipo_de_documento }} de ellas {{ v.sin_tipo_de_documento === 1 ? "quedaría elegible" : "quedarían elegibles" }} pero aún sin tipo de documento: no se podrán emitir hasta que lo confirmes.
        </p>

        <div v-if="v.se_modifican" class="space-y-2">
          <label v-if="v.requiere_verificacion" class="flex items-start gap-2 rounded-xl bg-white px-3 py-2 text-[13px] font-extrabold text-slate-800 ring-1 ring-slate-200 cursor-pointer">
            <input type="checkbox" class="mt-0.5 rounded accent-[#942934]" :checked="m.verificado" data-confirmar-verificacion @change="m.verificado = $event.target.checked" />
            Confirmo que ya verifiqué la asistencia de estas {{ v.se_modifican }} personas y cumplen los requisitos.
          </label>
          <label v-if="v.requiere_confirmar_sobrescritura" class="flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-2 text-[13px] font-extrabold text-amber-900 ring-1 ring-amber-200 cursor-pointer">
            <input type="checkbox" class="mt-0.5 rounded accent-[#942934]" :checked="m.sobrescribir" data-confirmar-sobrescritura @change="m.sobrescribir = $event.target.checked" />
            Entiendo que esto reemplaza lo que ya había decidido en {{ personasTexto(v.sobrescriben) }}.
          </label>
        </div>

        <p v-if="razonAplicar" class="text-[12.5px] font-bold text-amber-700" data-razon-aplicar>{{ razonAplicar }}</p>
        <p class="text-[11.5px] font-semibold text-slate-400">Aplicar los cambios al borrador no es importar ni emitir: la base se crea en el último paso.</p>
      </div>

      <button type="button" :class="[boton, 'text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50']" data-cancelar-masivo @click="a.cancelarMasiva()">Cancelar y no cambiar nada</button>

      <div v-if="m.error" class="rounded-xl bg-rose-50 px-3 py-2 text-[13px] font-bold text-rose-700 ring-1 ring-rose-200" role="alert" data-error-masivo>
        {{ m.error.mensaje }}
      </div>
    </div>

    <!-- Historial de lo aplicado (se puede deshacer lo último) -->
    <div v-if="hayPanel && recientes.length" class="space-y-1" data-historial-masivo>
      <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">Cambios en bloque aplicados a este borrador</p>
      <ul class="space-y-1">
        <li v-for="h in recientes" :key="h.id" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-lg bg-white/70 px-3 py-1.5 text-[12.5px] font-semibold" :class="h.estado === 'deshecha' ? 'text-slate-400 line-through' : 'text-slate-700'" :data-historial="h.estado">
          <span class="min-w-0">
            {{ describir(h) }} · {{ personasTexto(h.aplicados) }}<template v-if="h.omitidos"> · {{ h.omitidos }} sin tocar</template><template v-if="hora(h.creado_at)"> · {{ hora(h.creado_at) }}</template>
            <span v-if="h.estado === 'deshecha'" class="no-underline"> (deshecho)</span>
          </span>
          <button v-if="h.deshacible" type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-0.5 font-extrabold text-primary-vinotinto hover:bg-primary-vinotinto/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 disabled:opacity-50" :disabled="m.aplicando" data-deshacer-masivo @click="a.deshacerMasiva(h.id)">
            <RotateCcw class="w-3.5 h-3.5" aria-hidden="true" /> Deshacer
          </button>
        </li>
      </ul>
      <p v-if="m.error && !m.abierta" class="rounded-lg bg-rose-50 px-3 py-1.5 text-[12.5px] font-bold text-rose-700" role="alert" data-error-masivo>{{ m.error.mensaje }}</p>
    </div>
  </section>
</template>
