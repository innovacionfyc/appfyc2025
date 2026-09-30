<script setup>
import { computed } from "vue";
import { CircleCheck, CircleAlert, TriangleAlert, Info } from "lucide-vue-next";

const props = defineProps({
  resultado: { type: Object, required: true },
});

const LISTA_MAX = 200;

const r = computed(() => props.resultado);
const valido = computed(() => r.value.valido === true);

// Errores del archivo (sin fila) y errores de filas.
const erroresArchivo = computed(() => r.value.errores.filter((e) => e.fila === null));
const erroresFilas = computed(() => r.value.errores.filter((e) => e.fila !== null));

const nombreColumna = (c) => ({ nombre_completo: "Nombre", documento: "Documento" }[c] ?? c ?? "—");
</script>

<template>
  <section class="space-y-5" data-preview-importacion :data-valido="valido ? 'si' : 'no'" aria-live="polite">
    <!-- Resumen -->
    <div
      :class="[
        'flex items-start gap-3 px-4 py-3 rounded-2xl text-[13px] font-semibold',
        valido ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700',
      ]"
      role="status"
    >
      <component :is="valido ? CircleCheck : CircleAlert" class="w-4 h-4 mt-0.5 shrink-0" />
      <span v-if="valido">El archivo es válido. Revisa el preview y confirma la importación: solo entonces se crea el lote.</span>
      <span v-else>El archivo tiene errores. No se importará nada hasta corregirlos y volver a validar.</span>
    </div>

    <dl class="grid grid-cols-2 lg:grid-cols-4 gap-3 text-center">
      <div class="rounded-2xl bg-slate-50 border border-slate-100 p-3">
        <dd class="text-2xl font-black text-slate-900" data-total="detectadas">{{ r.filas_detectadas }}</dd>
        <dt class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Filas detectadas</dt>
      </div>
      <div class="rounded-2xl bg-emerald-50 border border-emerald-100 p-3">
        <dd class="text-2xl font-black text-emerald-700" data-total="validas">{{ r.filas_validas }}</dd>
        <dt class="text-[11px] font-bold uppercase tracking-wide text-emerald-600/70">Filas válidas</dt>
      </div>
      <div class="rounded-2xl bg-red-50 border border-red-100 p-3">
        <dd class="text-2xl font-black text-red-700" data-total="con-error">{{ r.filas_con_error }}</dd>
        <dt class="text-[11px] font-bold uppercase tracking-wide text-red-600/70">Filas con error</dt>
      </div>
      <div class="rounded-2xl bg-amber-50 border border-amber-100 p-3">
        <dd class="text-2xl font-black text-amber-700" data-total="avisos">{{ r.avisos_total }}</dd>
        <dt class="text-[11px] font-bold uppercase tracking-wide text-amber-600/70">Avisos</dt>
      </div>
    </dl>

    <!-- Columnas -->
    <div v-if="r.columnas_ignoradas.length" class="flex items-start gap-2 text-[12px] font-semibold text-amber-700 bg-amber-50 rounded-2xl px-4 py-3" data-columnas-ignoradas>
      <TriangleAlert class="w-4 h-4 mt-0.5 shrink-0" />
      <span>Columnas ignoradas (no se guardan): {{ r.columnas_ignoradas.join(", ") }}.</span>
    </div>
    <div v-if="r.columnas_faltantes.length" class="flex items-start gap-2 text-[12px] font-semibold text-red-700 bg-red-50 rounded-2xl px-4 py-3" data-columnas-faltantes>
      <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" />
      <span>Columnas faltantes: {{ r.columnas_faltantes.join(", ") }}.</span>
    </div>

    <!-- Errores del archivo -->
    <ul v-if="erroresArchivo.length" class="space-y-2" data-errores-archivo>
      <li v-for="e in erroresArchivo" :key="e.codigo" class="flex items-start gap-2 text-[13px] font-semibold text-red-700 bg-red-50 rounded-2xl px-4 py-3">
        <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" />
        <span><code class="font-mono text-[11px] bg-white/70 rounded px-1.5 py-0.5 mr-1">{{ e.codigo }}</code>{{ e.mensaje }}</span>
      </li>
    </ul>

    <!-- Preview de filas normalizadas -->
    <div v-if="r.preview.length">
      <h4 class="text-[12px] font-black uppercase tracking-widest text-slate-500 mb-2 px-1">
        Primeras {{ r.preview.length }} filas (como se guardarán)
      </h4>
      <div class="overflow-x-auto rounded-2xl border border-slate-100">
        <table class="min-w-full text-sm" data-tabla-preview>
          <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-400">
            <tr>
              <th class="text-left font-black px-4 py-2.5 w-16">Fila</th>
              <th class="text-left font-black px-4 py-2.5">Nombre completo</th>
              <th class="text-left font-black px-4 py-2.5">Documento</th>
              <th class="text-left font-black px-4 py-2.5 w-28">Estado</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="f in r.preview" :key="f.fila" :class="f.valida ? '' : 'bg-red-50/60'">
              <td class="px-4 py-2 font-mono text-[12px] text-slate-400">{{ f.fila }}</td>
              <td class="px-4 py-2 font-semibold text-slate-800 break-words">{{ f.nombre_completo || "—" }}</td>
              <td class="px-4 py-2 font-semibold text-slate-800 break-words">{{ f.documento || "—" }}</td>
              <td class="px-4 py-2">
                <span :class="['text-[11px] font-bold px-2 py-0.5 rounded-full whitespace-nowrap', f.valida ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700']">
                  {{ f.valida ? "Válida" : "Con error" }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Errores por fila -->
    <div v-if="erroresFilas.length">
      <h4 class="text-[12px] font-black uppercase tracking-widest text-red-600 mb-2 px-1">
        Errores por fila ({{ r.errores_total - erroresArchivo.length }})
      </h4>
      <div class="overflow-x-auto rounded-2xl border border-red-100">
        <table class="min-w-full text-sm" data-tabla-errores>
          <thead class="bg-red-50 text-[11px] uppercase tracking-wide text-red-400">
            <tr>
              <th class="text-left font-black px-4 py-2.5 w-16">Fila</th>
              <th class="text-left font-black px-4 py-2.5">Columna</th>
              <th class="text-left font-black px-4 py-2.5">Código</th>
              <th class="text-left font-black px-4 py-2.5">Detalle</th>
              <th class="text-left font-black px-4 py-2.5">Valor</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-red-50">
            <tr v-for="(e, i) in erroresFilas" :key="i">
              <td class="px-4 py-2 font-mono text-[12px] text-slate-500">{{ e.fila }}</td>
              <td class="px-4 py-2 font-semibold text-slate-700">{{ nombreColumna(e.columna) }}</td>
              <td class="px-4 py-2"><code class="font-mono text-[11px] bg-red-50 text-red-700 rounded px-1.5 py-0.5">{{ e.codigo }}</code></td>
              <td class="px-4 py-2 text-slate-600 break-words">{{ e.mensaje }}</td>
              <td class="px-4 py-2 font-mono text-[12px] text-slate-500 break-all">{{ e.valor ?? "—" }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="r.errores_total > LISTA_MAX" class="mt-2 px-1 text-[12px] font-semibold text-slate-500">
        Se muestran los primeros {{ LISTA_MAX }} de {{ r.errores_total }} errores. Corrige estos y vuelve a validar.
      </p>
    </div>

    <!-- Avisos -->
    <div v-if="r.avisos.length">
      <h4 class="text-[12px] font-black uppercase tracking-widest text-amber-600 mb-2 px-1">Avisos ({{ r.avisos_total }}) — no bloquean la importación</h4>
      <ul class="space-y-1.5" data-lista-avisos>
        <li v-for="(a, i) in r.avisos" :key="i" class="flex items-start gap-2 text-[12px] font-semibold text-amber-800 bg-amber-50 rounded-xl px-3 py-2">
          <Info class="w-3.5 h-3.5 mt-0.5 shrink-0" />
          <span>
            <code class="font-mono text-[11px] bg-white/70 rounded px-1.5 py-0.5 mr-1">{{ a.codigo }}</code>
            <template v-if="a.fila">Fila {{ a.fila }}<template v-if="a.columna"> · {{ nombreColumna(a.columna) }}</template>: </template>{{ a.mensaje }}
            <span v-if="a.valor" class="font-mono text-amber-700/80"> ({{ a.valor }})</span>
          </span>
        </li>
      </ul>
      <p v-if="r.avisos_total > LISTA_MAX" class="mt-2 px-1 text-[12px] font-semibold text-slate-500">
        Se muestran los primeros {{ LISTA_MAX }} de {{ r.avisos_total }} avisos.
      </p>
    </div>
  </section>
</template>
