<script setup>
import { computed } from "vue";
import { AlignLeft, AlignCenter, AlignRight, Type, MousePointer2 } from "lucide-vue-next";
import { AVISO_HEREDADA } from "@/Composables/CredentialFlow/fuentesCredential";

const props = defineProps({
  elemento: { type: Object, default: null },
  elementos: { type: Array, default: () => [] },
  schema: { type: Object, required: true },
  pagina: { type: Object, required: true },
  // Estado del autoajuste del elemento seleccionado (solo campos dinámicos): { size, reducido, noCabe }
  ajuste: { type: Object, default: null },
  // Qué se puede dibujar del elemento seleccionado: { estado, faltantes } (ver useCamposDinamicos.renderDe)
  render: { type: Object, default: () => ({ estado: "lista", faltantes: [] }) },
});

const emit = defineEmits(["actualizar", "seleccionar", "cambiar-origen"]);

const ALINEACIONES = [
  { valor: "left", etiqueta: "Izquierda", icono: AlignLeft },
  { valor: "center", etiqueta: "Centro", icono: AlignCenter },
  { valor: "right", etiqueta: "Derecha", icono: AlignRight },
];

const NOMBRES_PESO = { 300: "Ligera (300)", 400: "Normal (400)", 500: "Media (500)", 600: "Seminegrita (600)", 700: "Negrita (700)", 800: "Extranegrita (800)" };

// ── Fuente ────────────────────────────────────────────────────────────────────
// Solo se ofrecen familias reproducibles; una fuente heredada aparece únicamente si el elemento
// ya la usa (para poder verla y cambiarla), nunca como opción para elegir de nuevo.
const familiaActual = computed(() => props.schema.fuentes.familias.find((f) => f.valor === props.elemento?.fontFamily) ?? null);
const opcionesFamilia = computed(() =>
  props.schema.fuentes.familias.filter((f) => !f.heredada || f.valor === props.elemento?.fontFamily)
);
const pesosDisponibles = computed(() => familiaActual.value?.pesos ?? props.schema.pesos);
const esHeredada = computed(() => familiaActual.value?.heredada === true);

// ── Contenido: texto fijo o campo dinámico ────────────────────────────────────
const catalogo = computed(() => Object.fromEntries(props.schema.campos.map((c) => [c.key, c])));
const campoActual = computed(() => props.elemento?.field ?? null);
const esDinamico = computed(() => campoActual.value !== null);
const esDesconocido = computed(() => esDinamico.value && !catalogo.value[campoActual.value]);
const infoCampo = computed(() => catalogo.value[campoActual.value] ?? null);
const porcentajeMinimo = computed(() => Math.round(props.schema.escalaMinima * 100));

const cambiar = (campo, valor) => emit("actualizar", { [campo]: valor });
// Mientras se teclea solo se aplican valores ya válidos (así "3" de camino a "30" no se convierte
// en el mínimo 4); al salir del campo se ajusta al rango y se sincroniza lo que muestra.
const cambiarNumero = (campo, e, min, max) => {
  const v = e.target.value;
  if (v === "" || !Number.isFinite(Number(v))) return;
  const n = Number(v);
  if (n < min || n > max) return;
  cambiar(campo, n);
};
const confirmarNumero = (campo, e, min, max) => {
  const v = e.target.value;
  if (v !== "" && Number.isFinite(Number(v))) cambiar(campo, Math.min(Math.max(Number(v), min), max));
  e.target.value = props.elemento?.[campo] ?? "";
};

// El selector de color solo acepta #rrggbb; el campo de texto permite escribirlo a mano.
const cambiarColorTexto = (e) => {
  const v = e.target.value.trim();
  if (/^#[0-9a-fA-F]{6}$/.test(v)) cambiar("color", v.toLowerCase());
};

const resumen = (el) => {
  // Un campo dinámico se muestra por su etiqueta, no por el texto (que siempre está vacío).
  if (el.field !== null && el.field !== undefined) return `⟨${catalogo.value[el.field]?.etiqueta ?? "Campo desconocido"}⟩`;
  return el.text?.trim() ? el.text.trim().split("\n")[0].slice(0, 32) : "(texto vacío)";
};

const campo =
  "w-full px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-sm font-semibold text-slate-800 focus:outline-none focus:ring-2 focus:ring-primary-vinotinto/30 focus:border-primary-vinotinto/40 transition-all";
const etiqueta = "block text-[11px] font-black uppercase tracking-widest text-slate-400 mb-1.5";

const posicion = computed(() => [
  { clave: "x", nombre: "X", min: 0, max: props.pagina.width },
  { clave: "y", nombre: "Y", min: 0, max: props.pagina.height },
  { clave: "width", nombre: "Ancho", min: props.schema.elementoMin, max: props.pagina.width },
  { clave: "height", nombre: "Alto", min: props.schema.elementoMin, max: props.pagina.height },
]);
</script>

<template>
  <aside class="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-5 flex flex-col gap-5" aria-label="Propiedades">
    <template v-if="elemento">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-primary-vinotinto/10 text-primary-vinotinto flex items-center justify-center">
          <Type class="w-5 h-5" />
        </div>
        <div>
          <h3 class="text-base font-extrabold text-slate-900 leading-tight">Texto</h3>
          <p class="text-[11px] font-semibold text-slate-400">Elemento seleccionado</p>
        </div>
      </div>

      <div>
        <label :class="etiqueta" for="cf-origen">Contenido</label>
        <select
          id="cf-origen"
          :value="campoActual ?? ''"
          :class="campo"
          @change="emit('cambiar-origen', $event.target.value)"
        >
          <option value="">Texto fijo</option>
          <option v-for="c in schema.campos" :key="c.key" :value="c.key">{{ c.etiqueta }}</option>
          <option v-if="esDesconocido" :value="campoActual" disabled>Campo desconocido ({{ campoActual }})</option>
        </select>
      </div>

      <!-- Texto fijo: el texto manual de siempre -->
      <div v-if="!esDinamico">
        <label :class="etiqueta" for="cf-texto">Texto</label>
        <textarea
          id="cf-texto"
          rows="3"
          :maxlength="schema.textoMax"
          :value="elemento.text"
          :class="[campo, 'resize-none']"
          @input="cambiar('text', $event.target.value)"
        />
      </div>

      <!-- Campo dinámico: solo lectura (el valor de ejemplo nunca se guarda en el diseño) -->
      <div
        v-else-if="!esDesconocido"
        class="rounded-2xl border border-sky-200 bg-sky-50 p-4 space-y-1.5"
        data-tarjeta-campo
      >
        <p class="text-[11px] font-black uppercase tracking-widest text-sky-600">{{ infoCampo.etiqueta }}</p>
        <p class="text-sm font-bold text-slate-800 break-words">{{ infoCampo.preview }}</p>
        <p class="text-[12px] font-medium text-slate-500 leading-snug">
          Se reemplaza con los datos de cada participante al generar
        </p>
      </div>

      <div
        v-else
        class="rounded-2xl border border-red-200 bg-red-50 p-4 space-y-1.5 text-red-700"
        role="alert"
        data-tarjeta-desconocido
      >
        <p class="text-[11px] font-black uppercase tracking-widest">Campo desconocido</p>
        <p class="text-[13px] font-semibold break-words">«{{ campoActual }}» ya no existe en el catálogo.</p>
        <p class="text-[12px] font-medium leading-snug">Elige una opción válida en «Contenido» para poder guardar. No se ha cambiado nada.</p>
      </div>

      <div
        v-if="ajuste?.noCabe"
        class="rounded-2xl border border-red-200 bg-red-50 p-3 text-[12px] font-semibold text-red-700 leading-snug"
        role="alert"
        data-estado-ajuste="no-cabe"
      >
        No cabe: aun reducido al {{ porcentajeMinimo }} % ({{ ajuste.size }} pt) el texto excede el ancho de la caja.
        Ensancha la caja o baja el tamaño.
      </div>
      <div
        v-else-if="ajuste?.reducido"
        class="rounded-2xl border border-sky-200 bg-white p-3 text-[12px] font-semibold text-slate-600 leading-snug"
        data-estado-ajuste="reducido"
      >
        Se reduce a {{ ajuste.size }} pt (configurado: {{ elemento.fontSize }} pt) para caber en la caja.
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div class="col-span-2">
          <label :class="etiqueta" for="cf-fuente">Fuente</label>
          <select id="cf-fuente" :value="elemento.fontFamily" :class="campo" @change="cambiar('fontFamily', $event.target.value)">
            <option v-for="f in opcionesFamilia" :key="f.valor" :value="f.valor">{{ f.etiqueta }}{{ f.heredada ? " (heredada)" : "" }}</option>
          </select>
          <p v-if="esHeredada" class="mt-1.5 text-[11px] font-semibold text-amber-700 leading-snug" data-aviso-heredada>
            {{ AVISO_HEREDADA }}
          </p>
          <p v-else-if="render.estado === 'error'" class="mt-1.5 text-[11px] font-semibold text-red-600 leading-snug" data-aviso-fuente>
            No se pudo cargar la fuente. Recarga la página; no se puede guardar hasta entonces.
          </p>
          <p v-else-if="render.estado === 'cargando'" class="mt-1.5 text-[11px] font-semibold text-slate-500 leading-snug" data-aviso-fuente>
            Cargando fuente…
          </p>
          <p v-else-if="render.estado === 'noSoportado'" class="mt-1.5 text-[11px] font-semibold text-red-600 leading-snug" data-aviso-fuente>
            La fuente no tiene: {{ render.faltantes.map((c) => `«${c}»`).join(" ") }}. Corrígelo o quítalo: no se dibuja con otra fuente y no se puede guardar mientras tanto.
          </p>
        </div>
        <div>
          <label :class="etiqueta" for="cf-tamano">Tamaño (pt)</label>
          <input
            id="cf-tamano"
            type="number"
            :min="schema.fontSizeMin"
            :max="schema.fontSizeMax"
            step="1"
            :value="elemento.fontSize"
            :class="campo"
            @input="cambiarNumero('fontSize', $event, schema.fontSizeMin, schema.fontSizeMax)"
            @change="confirmarNumero('fontSize', $event, schema.fontSizeMin, schema.fontSizeMax)"
          />
        </div>
        <div>
          <label :class="etiqueta" for="cf-peso">Grosor</label>
          <select id="cf-peso" :value="elemento.fontWeight" :class="campo" @change="cambiar('fontWeight', Number($event.target.value))">
            <option v-for="p in pesosDisponibles" :key="p" :value="p">{{ NOMBRES_PESO[p] ?? p }}</option>
          </select>
        </div>
      </div>

      <div>
        <label :class="etiqueta" for="cf-color-texto">Color</label>
        <div class="flex items-center gap-2">
          <input
            type="color"
            :value="elemento.color"
            class="w-11 h-10 rounded-lg border border-slate-200 bg-white p-1 cursor-pointer"
            aria-label="Elegir color"
            @input="cambiar('color', $event.target.value)"
          />
          <input
            id="cf-color-texto"
            type="text"
            maxlength="7"
            :value="elemento.color"
            :class="[campo, 'font-mono uppercase']"
            @change="cambiarColorTexto"
          />
        </div>
      </div>

      <div>
        <span :class="etiqueta">Alineación</span>
        <div class="grid grid-cols-3 gap-2" role="group" aria-label="Alineación del texto">
          <button
            v-for="a in ALINEACIONES"
            :key="a.valor"
            type="button"
            :aria-pressed="elemento.align === a.valor"
            :title="a.etiqueta"
            :class="[
              'flex items-center justify-center py-2.5 rounded-xl border transition-all',
              elemento.align === a.valor
                ? 'bg-primary-vinotinto text-white border-primary-vinotinto shadow-md shadow-rose-900/20'
                : 'bg-slate-50 text-slate-500 border-slate-200 hover:bg-slate-100',
            ]"
            @click="cambiar('align', a.valor)"
          >
            <component :is="a.icono" class="w-4 h-4" />
          </button>
        </div>
      </div>

      <div>
        <span :class="etiqueta">Posición y tamaño (pt)</span>
        <div class="grid grid-cols-2 gap-3">
          <div v-for="p in posicion" :key="p.clave">
            <label class="block text-[11px] font-bold text-slate-400 mb-1" :for="`cf-${p.clave}`">{{ p.nombre }}</label>
            <input
              :id="`cf-${p.clave}`"
              type="number"
              step="0.5"
              :min="p.min"
              :max="p.max"
              :value="elemento[p.clave]"
              :class="campo"
              @input="cambiarNumero(p.clave, $event, p.min, p.max)"
              @change="confirmarNumero(p.clave, $event, p.min, p.max)"
            />
          </div>
        </div>
        <p class="mt-2 text-[11px] font-medium text-slate-400">
          Página de {{ pagina.width }} × {{ pagina.height }} pt. Origen arriba a la izquierda.
        </p>
      </div>
    </template>

    <template v-else>
      <div class="text-center py-6 space-y-3">
        <div class="w-14 h-14 mx-auto rounded-2xl bg-slate-100 flex items-center justify-center text-slate-400">
          <MousePointer2 class="w-6 h-6" />
        </div>
        <p class="font-bold text-slate-600">Ningún elemento seleccionado</p>
        <p class="text-[13px] text-slate-400 leading-snug">
          Agrega un texto o haz clic sobre uno del PDF para editar sus propiedades.
        </p>
      </div>
    </template>

    <div v-if="elementos.length" class="border-t border-slate-100 pt-4">
      <span :class="etiqueta">Elementos ({{ elementos.length }}/{{ schema.maxElementos }})</span>
      <ul class="space-y-1 max-h-44 overflow-y-auto pr-1">
        <li v-for="e in elementos" :key="e.id">
          <button
            type="button"
            :class="[
              'w-full text-left px-3 py-2 rounded-xl text-[13px] font-semibold truncate transition-all',
              e.id === elemento?.id ? 'bg-primary-vinotinto/10 text-primary-vinotinto' : 'text-slate-500 hover:bg-slate-50',
            ]"
            @click="emit('seleccionar', e.id)"
          >
            {{ resumen(e) }}
          </button>
        </li>
      </ul>
    </div>
  </aside>
</template>
