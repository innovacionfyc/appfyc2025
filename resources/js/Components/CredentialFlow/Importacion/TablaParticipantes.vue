<script setup>
// Paso 2 · Participantes editables. Nada se presume: la elegibilidad empieza en «Pendiente» y el tipo de documento queda «Por confirmar» hasta que tú lo decidas.
// Cada persona es una ficha que se adapta al ancho: los nombres y correos se muestran COMPLETOS (el campo crece) y se editan ahí mismo; los avisos y lo que decía el Excel
// van dentro de «Detalles». Las correcciones se guardan en el borrador (no en la base).
import { computed, nextTick, ref, watch } from "vue";
import { Search, RotateCcw, TriangleAlert, UserX, ChevronRight, X } from "lucide-vue-next";
import CampoAutoajustable from "./CampoAutoajustable.vue";
import { ASISTENCIA, AVISOS_FILA, ELEGIBILIDADES, TIPOS_DOCUMENTO, destacar, useAsistente } from "@/Composables/CredentialFlow/importacionInteligente";

const a = useAsistente();
const LOTE = 50;

const busqueda = ref("");
const filtro = ref("todos");
const visibles = ref(LOTE);

const norm = (s) => String(s ?? "").normalize("NFD").replace(/\p{Mn}/gu, "").toLowerCase();

const FILTROS = [
  { id: "todos", etiqueta: "Todos", test: () => true },
  { id: "tipo", etiqueta: "Tipo por confirmar", test: (p) => !p.excluir && !p.tipo_documento },
  { id: "elegibilidad", etiqueta: "Elegibilidad pendiente", test: (p) => !p.excluir && p.elegibilidad === "pendiente" },
  { id: "faltan", etiqueta: "Faltan datos", test: (p) => !p.excluir && (p.faltan.length > 0 || !p.correo) },
  { id: "cupos", etiqueta: "Cupos", test: (p) => !p.excluir && p.senal === "cupo" },
  { id: "excluidos", etiqueta: "Excluidos", test: (p) => p.excluir },
];
const filtroAviso = computed(() => (filtro.value.startsWith("aviso:") ? filtro.value.slice(6) : null));
const probar = (p) => {
  if (filtroAviso.value) return !p.excluir && p.avisos.includes(filtroAviso.value);

  return (FILTROS.find((f) => f.id === filtro.value)?.test ?? (() => true))(p);
};
const conteo = (f) => a.participantes.value.filter(f.test).length;

const lista = computed(() => {
  const q = norm(busqueda.value).trim();
  return a.participantes.value.filter((p) => probar(p) && (!q || norm(p.nombre_completo).includes(q) || norm(p.documento).includes(q)));
});
watch([busqueda, filtro], () => (visibles.value = LOTE));

// Errores que devuelve el servidor para una fila (documento repetido, correo inválido…).
const erroresServidor = computed(() => {
  const m = {};
  for (const b of a.validacion.value?.bloqueos ?? []) {
    if (b.fila != null && ["fila_invalida", "documento_de_contacto_qa", "intensidad_individual_invalida"].includes(b.codigo)) (m[b.fila] ??= []).push(b.mensaje);
  }
  return m;
});

const mostrarIntensidad = computed(() => a.participantes.value.some((p) => p.intensidad_horaria) || Object.values(a.edicion.participantes).some((c) => "intensidad_horaria" in c));
const error = (fila, campo) => a.erroresCampo[`${fila}:${campo}`];
const aviso = (c) => AVISOS_FILA[c] ?? c;
// Los avisos de la fila: los que no tienen su propio lugar en la pantalla (el tipo por confirmar tiene su filtro; los datos desactualizados, su tarjeta).
const avisosFila = (p) => p.avisos.filter((x) => x !== "tipo_documento_por_confirmar");
// Un aviso que afecta a la mitad o más de las personas (y a 3 o más) es GENERAL: se explica una vez arriba y no se repite como insignia en cada ficha (sigue en «Detalles»).
const codigosGenerales = computed(() => new Set(a.avisosResumen.value.filter((r) => r.cantidad >= 3 && r.cantidad * 2 >= a.resumenParticipantes.value.incluidos).map((r) => r.codigo)));
const otrosAvisos = (p) => avisosFila(p).filter((x) => x !== "valores_posiblemente_desactualizados" && !codigosGenerales.value.has(x));
const entrada = "w-full rounded-lg border-slate-300 text-[13px] font-semibold py-1.5 focus:border-primary-vinotinto focus:ring-primary-vinotinto/30 disabled:bg-slate-50";
const etiqueta = "block text-[11px] font-black uppercase tracking-widest text-slate-400 mb-0.5";

function verAviso(codigo) {
  busqueda.value = "";
  filtro.value = `aviso:${codigo}`;
}

// «Ir a resolverlo» desde el último paso: deja visible la fila (o el filtro) y lleva el foco al campo.
watch(
  () => a.foco.value,
  async (f) => {
    if (!f) return;
    a.foco.value = null;
    if (f.tipo === "filtro") {
      busqueda.value = "";
      filtro.value = f.id;
      await nextTick();
      destacar(document.querySelector("[data-lista-participantes]"), false);

      return;
    }
    busqueda.value = "";
    filtro.value = "todos";
    await nextTick();
    const i = lista.value.findIndex((p) => p.fila === f.fila);
    if (i >= visibles.value) visibles.value = i + 1;
    await nextTick();
    await nextTick();
    const ficha = document.querySelector(`[data-fila="${f.fila}"]`);
    if (!ficha) return;
    const campo = f.campo ? ficha.querySelector(`[data-campo-fila="${f.campo}"]`) : null;
    destacar(ficha, false);
    (campo ?? ficha.querySelector("input:not([type=checkbox]), textarea, select"))?.focus({ preventScroll: true });
  }
);
</script>

<template>
  <section class="rounded-3xl border border-slate-200 bg-white p-5 sm:p-6 space-y-4" aria-labelledby="titulo-participantes" data-seccion="participantes">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h3 id="titulo-participantes" class="text-base font-extrabold text-slate-900">Participantes ({{ a.resumenParticipantes.value.incluidos }} de {{ a.resumenParticipantes.value.total }} se importarán)</h3>
        <p class="text-[13px] font-semibold text-slate-500">
          {{ a.resumenParticipantes.value.completos }} con asistencia «Completo» · {{ a.resumenParticipantes.value.cupos }} «Cupos» · ninguno es elegible hasta que tú lo decidas.
        </p>
      </div>
      <div class="relative w-full sm:w-72">
        <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" aria-hidden="true" />
        <label for="buscar-participante" class="sr-only">Buscar por nombre o documento</label>
        <input id="buscar-participante" v-model="busqueda" type="search" placeholder="Buscar por nombre o documento" class="w-full rounded-xl border-slate-300 pl-9 text-sm font-semibold focus:border-primary-vinotinto focus:ring-primary-vinotinto/30" data-buscar />
      </div>
    </header>

    <!-- Avisos generales: una sola vez, con la cantidad y acceso a las personas afectadas -->
    <div v-if="a.avisosResumen.value.length" class="rounded-2xl border border-amber-200 bg-amber-50/70 px-4 py-3 space-y-1.5" data-avisos-resumen>
      <p class="flex items-center gap-2 text-[13px] font-extrabold text-amber-900"><TriangleAlert class="w-4 h-4" aria-hidden="true" /> Avisos sobre los participantes</p>
      <ul class="space-y-1">
        <li v-for="r in a.avisosResumen.value" :key="r.codigo" class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1 text-[13px] font-semibold text-amber-950" :data-aviso-resumen="r.codigo">
          <span class="min-w-0 flex-1 basis-64">{{ r.texto }}</span>
          <button type="button" class="shrink-0 rounded-lg px-2 py-0.5 text-[12px] font-extrabold text-primary-vinotinto underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50" @click="verAviso(r.codigo)">
            Ver {{ r.cantidad === 1 ? "la fila" : `las ${r.cantidad} filas` }}
          </button>
        </li>
      </ul>
    </div>

    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filtrar participantes">
      <button
        v-for="f in FILTROS"
        :key="f.id"
        type="button"
        :aria-pressed="filtro === f.id"
        class="rounded-full px-3 py-1.5 text-[12px] font-extrabold ring-1 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50"
        :class="filtro === f.id ? 'bg-primary-vinotinto text-white ring-primary-vinotinto' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50'"
        :data-filtro="f.id"
        @click="filtro = f.id"
      >
        {{ f.etiqueta }} <span class="opacity-70">({{ conteo(f) }})</span>
      </button>
      <button v-if="filtroAviso" type="button" class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-3 py-1.5 text-[12px] font-extrabold text-amber-900 ring-1 ring-amber-300" data-filtro-aviso aria-label="Quitar el filtro de avisos" @click="filtro = 'todos'">
        Con aviso ({{ lista.length }}) <X class="w-3.5 h-3.5" aria-hidden="true" />
      </button>
    </div>

    <label v-if="a.participantes.value.some((p) => p.valores_ambiguos.length && !p.excluir)" class="flex items-start gap-2 rounded-2xl bg-amber-50 border border-amber-200 px-4 py-3 text-[13px] font-extrabold text-amber-900 cursor-pointer">
      <input type="checkbox" class="mt-0.5 rounded accent-[#942934]" :checked="a.confirmados.value.valores_ambiguos === true" data-confirmar-ambiguos @change="a.marcarConfirmacion('valores_ambiguos', $event.target.checked)" />
      Algunos datos no se detectaron con certeza (están marcados). Ya los revisé y están bien.
    </label>

    <p v-if="a.borrador.value?.avisos_generales?.observaciones_no_se_conservan" class="flex items-start gap-2 rounded-2xl bg-slate-50 border border-slate-200 px-4 py-3 text-[13px] font-semibold text-slate-600" data-aviso-observaciones>
      <TriangleAlert class="w-4 h-4 shrink-0 mt-0.5 text-amber-600" aria-hidden="true" />
      <span>Las observaciones que trae el Excel se pueden ver aquí (en «Detalles»), pero <strong>no se guardarán</strong> en la base: todavía no hay dónde conservarlas.</span>
    </p>

    <ul class="space-y-3" data-lista-participantes>
      <li
        v-for="p in lista.slice(0, visibles)"
        :key="p.fila"
        class="rounded-2xl border px-4 py-3 space-y-3 transition-shadow"
        :class="p.excluir ? 'border-slate-200 bg-slate-50 opacity-75' : error(p.fila, 'nombre_completo') || erroresServidor[p.fila] ? 'border-rose-300 bg-rose-50/30' : 'border-slate-200 bg-white'"
        :data-fila="p.fila"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="text-[12px] font-black text-slate-400">Fila del Excel: {{ p.fila }}<span v-if="p.modificados.length" class="ml-2 rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-extrabold text-sky-700 ring-1 ring-sky-200" data-corregido>Corregido</span></span>
          <div class="flex flex-wrap items-center gap-3">
            <span v-if="p.excluir" class="inline-flex items-center gap-1 text-[11px] font-extrabold text-slate-500"><UserX class="w-3.5 h-3.5" aria-hidden="true" /> No se importará</span>
          <label class="inline-flex items-center gap-2 text-[12px] font-extrabold text-slate-600 cursor-pointer">
            <input type="checkbox" class="rounded accent-[#942934]" :checked="!p.excluir" :aria-label="`Incluir a la fila ${p.fila} en la importación`" :data-incluir="p.fila" @change="a.editarFila(p.fila, 'excluir', !$event.target.checked)" />
            Importar a esta persona
          </label>
          </div>
        </div>

        <div class="ficha-campos"><div class="ficha-rejilla">
          <div class="ficha-ancho ficha-c6">
            <label :for="`n-${p.fila}`" :class="etiqueta">Nombre</label>
            <CampoAutoajustable :id="`n-${p.fila}`" :model-value="p.nombre_completo ?? ''" :maxlength="200" :disabled="p.excluir" :invalido="!!error(p.fila, 'nombre_completo')" campo="nombre_completo" @update:model-value="a.editarFila(p.fila, 'nombre_completo', $event)" />
            <p v-if="error(p.fila, 'nombre_completo')" class="mt-0.5 text-[12px] font-bold text-rose-600" role="alert">{{ error(p.fila, "nombre_completo") }}</p>
          </div>

          <div class="ficha-c3">
            <label :for="`d-${p.fila}`" :class="etiqueta">Documento</label>
            <input :id="`d-${p.fila}`" type="text" inputmode="text" maxlength="60" :value="p.documento ?? ''" :disabled="p.excluir" :aria-invalid="!!error(p.fila, 'documento')" data-campo-fila="documento" :class="entrada" @input="a.editarFila(p.fila, 'documento', $event.target.value)" />
            <p v-if="error(p.fila, 'documento')" class="mt-0.5 text-[12px] font-bold text-rose-600" role="alert">{{ error(p.fila, "documento") }}</p>
          </div>

          <div class="ficha-c3">
            <label :for="`t-${p.fila}`" :class="etiqueta">Tipo de documento</label>
            <select :id="`t-${p.fila}`" :value="p.tipo_documento ?? ''" :disabled="p.excluir" data-campo-fila="tipo_documento" :class="[entrada, !p.tipo_documento && !p.excluir ? 'border-amber-400 bg-amber-50' : '']" :data-tipo="p.fila" @change="a.editarFila(p.fila, 'tipo_documento', $event.target.value === '' ? null : $event.target.value)">
              <option value="">Por confirmar</option>
              <option v-for="t in TIPOS_DOCUMENTO" :key="t" :value="t">{{ t }}</option>
            </select>
            <p v-if="error(p.fila, 'tipo_documento')" class="mt-0.5 text-[12px] font-bold text-rose-600" role="alert">{{ error(p.fila, "tipo_documento") }}</p>
          </div>

          <div class="ficha-ancho ficha-c6">
            <label :for="`c-${p.fila}`" :class="etiqueta">Correo</label>
            <CampoAutoajustable :id="`c-${p.fila}`" :model-value="p.correo ?? ''" :maxlength="320" inputmode="email" :disabled="p.excluir" :invalido="!!error(p.fila, 'correo')" campo="correo" @update:model-value="a.editarFila(p.fila, 'correo', $event)" />
            <p v-if="error(p.fila, 'correo')" class="mt-0.5 text-[12px] font-bold text-rose-600" role="alert">{{ error(p.fila, "correo") }}</p>
          </div>

          <div class="ficha-c3">
            <span :class="etiqueta">Asistencia en el Excel</span>
            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-black ring-1" :class="(ASISTENCIA[p.senal] ?? ASISTENCIA.sin_senal).clase" :data-asistencia="p.senal">{{ (ASISTENCIA[p.senal] ?? ASISTENCIA.sin_senal).etiqueta }}</span>
          </div>

          <div class="ficha-c3">
            <label :for="`e-${p.fila}`" :class="etiqueta">Elegibilidad</label>
            <select :id="`e-${p.fila}`" :value="p.elegibilidad" :disabled="p.excluir" data-campo-fila="elegibilidad" :class="entrada" :data-elegibilidad="p.fila" @change="a.editarFila(p.fila, 'elegibilidad', $event.target.value)">
              <option v-for="e in ELEGIBILIDADES" :key="e.valor" :value="e.valor">{{ e.etiqueta }}</option>
            </select>
          </div>
        </div></div>

        <ul v-if="erroresServidor[p.fila]" class="text-[12px] font-bold text-rose-600" role="alert">
          <li v-for="(m, i) in erroresServidor[p.fila]" :key="i">{{ m }}</li>
        </ul>

        <details class="group rounded-xl bg-slate-50/80 px-3 py-2 text-[12px]" :data-detalles="p.fila">
          <summary class="flex cursor-pointer list-none items-center gap-2 font-extrabold text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 rounded">
            <ChevronRight class="w-4 h-4 transition-transform group-open:rotate-90" aria-hidden="true" />
            Detalles y lo que decía el Excel
            <span v-if="otrosAvisos(p).length" class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-extrabold text-amber-800"><TriangleAlert class="w-3 h-3" aria-hidden="true" /> {{ otrosAvisos(p).length }} {{ otrosAvisos(p).length === 1 ? "aviso" : "avisos" }}</span>
          </summary>
          <div class="mt-2 space-y-2 font-semibold text-slate-600">
            <dl class="grid gap-x-4 gap-y-1 sm:grid-cols-[8rem_minmax(0,1fr)]">
              <dt class="font-black uppercase tracking-widest text-slate-400">Nombre</dt><dd class="break-words">{{ p.originales?.nombre_completo ?? "—" }}</dd>
              <dt class="font-black uppercase tracking-widest text-slate-400">Documento</dt><dd class="break-words">{{ p.originales?.documento ?? "—" }}</dd>
              <dt class="font-black uppercase tracking-widest text-slate-400">Correo</dt><dd class="break-words">{{ p.originales?.correo ?? "—" }}</dd>
              <template v-if="p.sesion"><dt class="font-black uppercase tracking-widest text-slate-400">Sesión</dt><dd>{{ p.sesion }}</dd></template>
              <template v-if="p.observaciones"><dt class="font-black uppercase tracking-widest text-slate-400">Observaciones</dt><dd class="break-words">{{ p.observaciones }} <span class="text-amber-700">(no se guardará)</span></dd></template>
            </dl>
            <ul v-if="avisosFila(p).length" class="space-y-0.5">
              <li v-for="av in avisosFila(p)" :key="av" class="flex items-start gap-1.5 text-amber-800"><TriangleAlert class="w-3.5 h-3.5 shrink-0 mt-0.5" aria-hidden="true" />{{ aviso(av) }}</li>
            </ul>
            <div v-if="mostrarIntensidad && !p.excluir" class="max-w-xs">
              <label :for="`i-${p.fila}`" :class="etiqueta">Intensidad individual</label>
              <input :id="`i-${p.fila}`" type="text" maxlength="255" :value="p.intensidad_horaria ?? ''" placeholder="Solo si es distinta a la del evento" data-campo-fila="intensidad_horaria" :class="entrada" @input="a.editarFila(p.fila, 'intensidad_horaria', $event.target.value === '' ? null : $event.target.value)" />
            </div>
            <button v-if="p.modificados.length" type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 font-extrabold text-primary-vinotinto hover:bg-primary-vinotinto/5" @click="p.modificados.forEach((c) => a.restaurarFila(p.fila, c))">
              <RotateCcw class="w-3.5 h-3.5" aria-hidden="true" /> Restaurar lo del Excel
            </button>
          </div>
        </details>
      </li>
    </ul>

    <p v-if="lista.length === 0" class="rounded-2xl bg-slate-50 px-4 py-6 text-center text-sm font-semibold text-slate-500">Ningún participante coincide con ese filtro.</p>
    <div v-if="lista.length > visibles" class="text-center">
      <button type="button" class="rounded-xl px-5 py-2 text-[13px] font-extrabold text-primary-vinotinto ring-1 ring-primary-vinotinto/30 hover:bg-primary-vinotinto/5" data-mostrar-mas @click="visibles += LOTE">Mostrar más ({{ lista.length - visibles }} restantes)</button>
    </div>
  </section>
</template>

<style scoped>
/* La ficha se adapta al ANCHO DE SU CAJA (no al de la pantalla): así funciona igual con el menú lateral abierto, en el celular y en pantallas grandes. */
.ficha-campos {
  container-type: inline-size;
}
.ficha-rejilla {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 0.75rem 1rem;
  align-items: start;
}
@container (min-width: 28rem) {
  .ficha-rejilla {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .ficha-ancho {
    grid-column: span 2;
  }
}
@container (min-width: 44rem) {
  .ficha-rejilla {
    grid-template-columns: repeat(12, minmax(0, 1fr));
  }
  .ficha-ancho {
    grid-column: auto;
  }
  .ficha-c6 {
    grid-column: span 6;
  }
  .ficha-c3 {
    grid-column: span 3;
  }
}
</style>
