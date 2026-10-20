<script setup>
// Paso 2 · Evento. Compara el nombre del Excel con el evento ya registrado y exige una decisión EXPLÍCITA: usar el existente o confirmar que es otro.
// Nunca se crea ni se modifica un evento por ver esta tarjeta: el evento nuevo solo se crea al final, al «Crear base de participantes».
import { computed } from "vue";
import { CalendarDays, Info } from "lucide-vue-next";
import EstadoDato from "./EstadoDato.vue";
import { partirResaltado, useAsistente } from "@/Composables/CredentialFlow/importacionInteligente";

const a = useAsistente();
const p = computed(() => a.evento.value?.propuesta ?? null);
const d = computed(() => a.evento.value?.decision ?? { decision: null, evento_id: null, nombre: null, confirmar_distinto: false });
const candidatos = computed(() => (p.value?.candidatos ?? []).slice(0, 4));
const principal = computed(() => candidatos.value[0] ?? null);

const seleccion = computed(() => (d.value.decision === "asociar" ? `asociar:${d.value.evento_id}` : d.value.decision === "crear" ? "crear" : ""));
const estado = computed(() => {
  if (!d.value.decision) return "revisar";
  if (d.value.decision === "crear" && (!nombreNuevo.value.trim() || (necesitaConfirmarDistinto.value && !d.value.confirmar_distinto))) return "revisar";

  return "confirmado";
});
const necesitaConfirmarDistinto = computed(() => candidatos.value.length > 0 || p.value?.homonimo_historico === true);
const nombreNuevo = computed(() => d.value.nombre ?? p.value?.nombre_excel ?? "");
const trozos = computed(() => partirResaltado(p.value?.nombre_excel ?? "", principal.value?.diferencia?.adicional_en_excel ?? null));

function elegir(valor) {
  if (valor === "crear") a.decidirEvento({ decision: "crear", evento_id: null, nombre: nombreNuevo.value, confirmar_distinto: d.value.confirmar_distinto === true });
  else a.decidirEvento({ decision: "asociar", evento_id: Number(valor.split(":")[1]), nombre: null, confirmar_distinto: false });
}
</script>

<template>
  <section class="rounded-3xl border border-slate-200 bg-white p-5 sm:p-6 space-y-4" aria-labelledby="titulo-evento" data-seccion="evento">
    <header class="flex flex-wrap items-center justify-between gap-2">
      <h3 id="titulo-evento" class="flex items-center gap-2 text-base font-extrabold text-slate-900"><CalendarDays class="w-5 h-5 text-primary-vinotinto" aria-hidden="true" /> ¿A qué evento pertenece?</h3>
      <EstadoDato :estado="estado" />
    </header>

    <div class="grid gap-3 md:grid-cols-2">
      <div class="rounded-2xl bg-slate-50 p-4" data-nombre-excel>
        <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">Así lo escribe el Excel</p>
        <p v-if="p?.nombre_excel" class="mt-1 text-sm font-bold text-slate-800 break-words">
          <template v-for="(t, i) in trozos" :key="i"><mark v-if="t.resaltar" class="rounded bg-amber-200 px-1 text-slate-900" data-diferencia>{{ t.texto }}</mark><template v-else>{{ t.texto }}</template></template>
        </p>
        <p v-else class="mt-1 text-sm font-semibold text-rose-600">El Excel no trae el nombre del evento.</p>
      </div>
      <div class="rounded-2xl bg-slate-50 p-4" data-nombre-registrado>
        <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">Evento ya registrado</p>
        <p v-if="principal" class="mt-1 text-sm font-bold text-slate-800 break-words">{{ principal.nombre }}</p>
        <p v-else class="mt-1 text-sm font-semibold text-slate-500">No encontramos un evento parecido.</p>
      </div>
    </div>

    <p v-if="principal && principal.diferencia && !principal.diferencia.iguales" class="flex items-start gap-2 rounded-2xl bg-amber-50 border border-amber-200 px-4 py-3 text-[13px] font-semibold text-amber-900" data-aviso-diferencia>
      <Info class="w-4 h-4 shrink-0 mt-0.5" aria-hidden="true" />
      <span>
        Los nombres no son idénticos<template v-if="principal.diferencia.adicional_en_excel">: el Excel agrega <strong>«{{ principal.diferencia.adicional_en_excel }}»</strong></template
        ><template v-if="principal.diferencia.faltante_en_excel"> y no trae «{{ principal.diferencia.faltante_en_excel }}»</template>. Si es el mismo evento, <strong>no crees otro</strong>: usa el que ya existe.
      </span>
    </p>

    <fieldset class="space-y-2">
      <legend class="text-sm font-extrabold text-slate-800">Tu decisión <span class="text-rose-600">*</span></legend>
      <label
        v-for="c in candidatos"
        :key="c.id"
        class="flex items-start gap-3 rounded-2xl border px-4 py-3 cursor-pointer transition-colors"
        :class="seleccion === `asociar:${c.id}` ? 'border-primary-vinotinto bg-primary-vinotinto/5' : 'border-slate-200 hover:bg-slate-50'"
      >
        <input type="radio" name="decision-evento" class="mt-1 accent-[#942934]" :value="`asociar:${c.id}`" :checked="seleccion === `asociar:${c.id}`" :data-decision="`asociar-${c.id}`" @change="elegir(`asociar:${c.id}`)" />
        <span class="min-w-0 text-sm">
          <span class="font-extrabold text-slate-800">Usar el evento existente</span>
          <span class="block font-semibold text-slate-600 break-words">{{ c.nombre }}<template v-if="c.fecha"> · {{ c.fecha }}</template></span>
        </span>
      </label>
      <label class="flex items-start gap-3 rounded-2xl border px-4 py-3 cursor-pointer transition-colors" :class="seleccion === 'crear' ? 'border-primary-vinotinto bg-primary-vinotinto/5' : 'border-slate-200 hover:bg-slate-50'">
        <input type="radio" name="decision-evento" class="mt-1 accent-[#942934]" value="crear" :checked="seleccion === 'crear'" data-decision="crear" @change="elegir('crear')" />
        <span class="text-sm">
          <span class="font-extrabold text-slate-800">{{ candidatos.length ? "Es otro evento distinto" : "Crear este evento nuevo" }}</span>
          <span class="block font-semibold text-slate-500">El evento se creará solo cuando confirmes el último paso.</span>
        </span>
      </label>
    </fieldset>

    <div v-if="seleccion === 'crear'" class="space-y-3 rounded-2xl bg-slate-50 p-4" data-evento-nuevo>
      <label class="block text-[11px] font-black uppercase tracking-widest text-slate-500" for="nombre-evento-nuevo">Nombre del evento nuevo</label>
      <input id="nombre-evento-nuevo" type="text" maxlength="255" :value="nombreNuevo" class="w-full rounded-xl border-slate-300 text-sm font-semibold focus:border-primary-vinotinto focus:ring-primary-vinotinto/30" @input="a.decidirEvento({ nombre: $event.target.value })" />
      <label v-if="necesitaConfirmarDistinto" class="flex items-start gap-2 text-[13px] font-semibold text-slate-700">
        <input type="checkbox" class="mt-0.5 rounded accent-[#942934]" :checked="d.confirmar_distinto === true" data-confirmar-distinto @change="a.decidirEvento({ confirmar_distinto: $event.target.checked })" />
        Confirmo que realmente es un evento distinto del que ya existe.
      </label>
    </div>
  </section>
</template>
