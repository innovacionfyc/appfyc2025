<script setup>
// Paso 2 · Revisar datos: evento, datos del certificado y participantes. Todo se guarda en el borrador; no se crea ninguna base.
// Las fichas separan lo que IMPIDE crear la base (datos por revisar) de lo que solo impide EMITIR (tipo de documento y elegibilidad por confirmar).
import { computed } from "vue";
import TarjetaEvento from "./TarjetaEvento.vue";
import TarjetaDatos from "./TarjetaDatos.vue";
import TablaParticipantes from "./TablaParticipantes.vue";
import { useAsistente } from "@/Composables/CredentialFlow/importacionInteligente";

const a = useAsistente();
const r = computed(() => a.resumenParticipantes.value);
const porRevisar = computed(() => a.datos.value.filter((d) => d.estado === "revisar" || d.estado === "faltante").length + (a.evento.value?.decision?.decision ? 0 : 1));
const fichas = computed(() => [
  { id: "total", valor: r.value.incluidos, texto: "participantes se importarán", nota: null, tono: "text-slate-900" },
  { id: "tipo", valor: r.value.tipoPendiente, texto: "con tipo de documento por confirmar", nota: "No impide crear la base; impide emitir", tono: r.value.tipoPendiente ? "text-amber-700" : "text-emerald-700" },
  { id: "eleg", valor: r.value.elegibilidadPendiente, texto: "con elegibilidad pendiente", nota: "No impide crear la base; impide emitir", tono: r.value.elegibilidadPendiente ? "text-amber-700" : "text-emerald-700" },
  { id: "datos", valor: porRevisar.value, texto: "datos del evento por revisar", nota: porRevisar.value ? "Impide crear la base" : "Todo revisado", tono: porRevisar.value ? "text-rose-700" : "text-emerald-700", bloquea: porRevisar.value > 0 },
]);
</script>

<template>
  <section class="space-y-5" aria-labelledby="titulo-revisar">
    <div>
      <h2 id="titulo-revisar" tabindex="-1" class="text-xl font-extrabold text-slate-900 focus:outline-none">Revisa lo que encontramos</h2>
      <p class="mt-1 text-sm font-medium text-slate-500">
        Corrige lo que haga falta. Tus cambios se guardan en el borrador; la base de participantes solo se crea en el último paso.
      </p>
    </div>

    <dl class="grid grid-cols-2 lg:grid-cols-4 gap-3" data-fichas>
      <div v-for="f in fichas" :key="f.id" class="rounded-2xl border bg-white px-4 py-3" :class="f.bloquea ? 'border-rose-200' : 'border-slate-200'" :data-ficha="f.id">
        <dt class="sr-only">{{ f.texto }}</dt>
        <dd class="text-2xl font-black tabular-nums" :class="f.tono">{{ f.valor }}</dd>
        <dd class="text-[12px] font-semibold text-slate-500 leading-tight">{{ f.texto }}</dd>
        <dd v-if="f.nota" class="mt-1 text-[11px] font-extrabold leading-tight" :class="f.bloquea ? 'text-rose-600' : f.valor ? 'text-slate-400' : 'text-emerald-600'" :data-nota-ficha="f.id">{{ f.nota }}</dd>
      </div>
    </dl>

    <TarjetaEvento />
    <TarjetaDatos />
    <TablaParticipantes />
  </section>
</template>
