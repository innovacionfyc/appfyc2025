<script setup>
// Etapa «Confirmar evento», en lenguaje cotidiano: «¡Encontramos esta capacitación!» y UNA pregunta con dos respuestas honestas. Nada viene preseleccionado: la decisión es de quien revisa.
// Los nombres originales (el del Excel y el registrado) quedan en «Ver detalles», pero cualquier diferencia relevante se dice aquí, a la vista.
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
const diferencia = computed(() => (principal.value && principal.value.diferencia && !principal.value.diferencia.iguales ? principal.value.diferencia : null));

const seleccion = computed(() => (d.value.decision === "asociar" ? `asociar:${d.value.evento_id}` : d.value.decision === "crear" ? "crear" : ""));
const necesitaConfirmarDistinto = computed(() => candidatos.value.length > 0 || p.value?.homonimo_historico === true);
const nombreNuevo = computed(() => d.value.nombre ?? p.value?.nombre_excel ?? "");
const estado = computed(() => {
  if (!d.value.decision) return "revisar";
  if (d.value.decision === "crear" && (!nombreNuevo.value.trim() || (necesitaConfirmarDistinto.value && !d.value.confirmar_distinto))) return "revisar";

  return "confirmado";
});
const trozos = computed(() => partirResaltado(p.value?.nombre_excel ?? "", principal.value?.diferencia?.adicional_en_excel ?? null));

function elegir(valor) {
  if (valor === "crear") a.decidirEvento({ decision: "crear", evento_id: null, nombre: nombreNuevo.value, confirmar_distinto: d.value.confirmar_distinto === true });
  else a.decidirEvento({ decision: "asociar", evento_id: Number(valor.split(":")[1]), nombre: null, confirmar_distinto: false });
}
</script>

<template>
  <section class="rounded-3xl border border-slate-200 bg-white p-5 sm:p-6 space-y-4" aria-labelledby="titulo-evento" data-seccion="evento">
    <header class="flex flex-wrap items-center justify-between gap-2">
      <h3 id="titulo-evento" class="flex items-center gap-2 text-lg font-extrabold text-slate-900">
        <CalendarDays class="w-5 h-5 text-primary-vinotinto" aria-hidden="true" />
        {{ principal ? "¡Encontramos esta capacitación!" : "Esta capacitación todavía no está registrada" }}
      </h3>
      <EstadoDato :estado="estado" />
    </header>

    <!-- La capacitación encontrada (o la que trae el Excel, si no hay ninguna parecida) -->
    <div class="rounded-2xl bg-slate-50 p-4" data-capacitacion>
      <template v-if="principal">
        <p class="text-base font-extrabold text-slate-900 break-words">{{ principal.nombre }}</p>
        <p v-if="principal.fecha" class="mt-0.5 text-[13px] font-semibold text-slate-500">{{ principal.fecha }}</p>
      </template>
      <template v-else>
        <p v-if="p?.nombre_excel" class="text-base font-extrabold text-slate-900 break-words">{{ p.nombre_excel }}</p>
        <p v-else class="text-sm font-semibold text-rose-600">El Excel no trae el nombre de la capacitación.</p>
        <p class="mt-1 text-[13px] font-semibold text-slate-500">No encontramos ninguna capacitación registrada con ese nombre.</p>
      </template>
    </div>

    <p v-if="diferencia" class="flex items-start gap-2 rounded-2xl bg-amber-50 border border-amber-200 px-4 py-3 text-[13px] font-semibold text-amber-900" data-aviso-diferencia>
      <Info class="w-4 h-4 shrink-0 mt-0.5" aria-hidden="true" />
      <span>
        Ojo, el nombre del Excel no es idéntico<template v-if="diferencia.adicional_en_excel">: agrega <strong>«{{ diferencia.adicional_en_excel }}»</strong></template
        ><template v-if="diferencia.faltante_en_excel"> y no trae «{{ diferencia.faltante_en_excel }}»</template>. Si es la misma capacitación, <strong>no crees otra</strong>: elige «Sí, es la misma».
      </span>
    </p>

    <fieldset class="space-y-2">
      <legend class="text-base font-extrabold text-slate-800">
        {{ principal ? "¿Quieres agregar estas personas a la capacitación que ya existe?" : "¿Creamos esta capacitación al terminar?" }}
      </legend>

      <label
        v-for="(c, i) in candidatos"
        :key="c.id"
        class="flex items-start gap-3 rounded-2xl border px-4 py-3 cursor-pointer transition-colors"
        :class="seleccion === `asociar:${c.id}` ? 'border-primary-vinotinto bg-primary-vinotinto/5' : 'border-slate-200 hover:bg-slate-50'"
      >
        <input type="radio" name="decision-evento" class="mt-1 accent-[#942934]" :value="`asociar:${c.id}`" :checked="seleccion === `asociar:${c.id}`" :data-decision="`asociar-${c.id}`" :data-recomendado="!seleccion && i === 0 ? '' : undefined" @change="elegir(`asociar:${c.id}`)" />
        <span class="min-w-0 text-sm">
          <span class="font-extrabold text-slate-800">{{ i === 0 ? "Sí, es la misma capacitación" : `Sí, es esta otra: ${c.nombre}` }}</span>
          <span v-if="i === 0" class="block font-semibold text-slate-500">Las personas se agregan a la que ya existe; no se crea una repetida.</span>
        </span>
      </label>

      <label class="flex items-start gap-3 rounded-2xl border px-4 py-3 cursor-pointer transition-colors" :class="seleccion === 'crear' ? 'border-primary-vinotinto bg-primary-vinotinto/5' : 'border-slate-200 hover:bg-slate-50'">
        <input type="radio" name="decision-evento" class="mt-1 accent-[#942934]" value="crear" :checked="seleccion === 'crear'" data-decision="crear" :data-recomendado="!seleccion && !principal ? '' : undefined" @change="elegir('crear')" />
        <span class="text-sm">
          <span class="font-extrabold text-slate-800">{{ principal ? "No, es otra capacitación" : "Sí, crear esta capacitación nueva" }}</span>
          <span class="block font-semibold text-slate-500">La capacitación nueva se crea solo al final, cuando crees la base.</span>
        </span>
      </label>
    </fieldset>

    <div v-if="seleccion === 'crear'" class="space-y-3 rounded-2xl bg-slate-50 p-4" data-evento-nuevo>
      <label class="block text-[11px] font-black uppercase tracking-widest text-slate-500" for="nombre-evento-nuevo">Nombre de la capacitación nueva</label>
      <input id="nombre-evento-nuevo" type="text" maxlength="255" :value="nombreNuevo" class="w-full rounded-xl border-slate-300 text-sm font-semibold focus:border-primary-vinotinto focus:ring-primary-vinotinto/30" @input="a.decidirEvento({ nombre: $event.target.value })" />
      <label v-if="necesitaConfirmarDistinto" class="flex items-start gap-2 text-[13px] font-semibold text-slate-700">
        <input type="checkbox" class="mt-0.5 rounded accent-[#942934]" :checked="d.confirmar_distinto === true" data-confirmar-distinto @change="a.decidirEvento({ confirmar_distinto: $event.target.checked })" />
        Confirmo que realmente es una capacitación distinta de la que ya existe.
      </label>
    </div>

    <details class="rounded-xl bg-slate-50 px-3 py-2 text-[13px]" data-detalles-evento>
      <summary class="cursor-pointer font-extrabold text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 rounded">Ver detalles</summary>
      <div class="mt-2 grid gap-3 md:grid-cols-2">
        <div data-nombre-excel>
          <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">Nombre tal como viene en el Excel</p>
          <p v-if="p?.nombre_excel" class="mt-1 font-bold text-slate-800 break-words">
            <template v-for="(t, i) in trozos" :key="i"><mark v-if="t.resaltar" class="rounded bg-amber-200 px-1 text-slate-900" data-diferencia>{{ t.texto }}</mark><template v-else>{{ t.texto }}</template></template>
          </p>
          <p v-else class="mt-1 font-semibold text-rose-600">El Excel no trae el nombre.</p>
        </div>
        <div data-nombre-registrado>
          <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">Capacitación ya registrada</p>
          <p v-if="principal" class="mt-1 font-bold text-slate-800 break-words">{{ principal.nombre }}</p>
          <p v-else class="mt-1 font-semibold text-slate-500">No encontramos una parecida.</p>
        </div>
      </div>
    </details>
  </section>
</template>
