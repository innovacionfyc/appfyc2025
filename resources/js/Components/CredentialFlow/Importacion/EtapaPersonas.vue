<script setup>
// Etapa 4 · Revisar personas. No se muestran decenas de fichas de golpe: primero un resumen en palabras simples y DOS grupos claros:
//  · «Antes de crear la base»: lo que impide crear la base (los dicta el servidor).
//  · «Elegibilidad y tipo de documento»: herramientas para decidir en bloque. Quien está en el Excel nace elegible (la asistencia «Cupos» o «Completo» es solo
//    informativa); lo que el Excel declara «No elegible» y los tipos de documento por confirmar SÍ impiden crear la base (nadie queda fuera sin que se vea).
// Las acciones sobre varias personas solo PREPARAN la selección (no deciden nada): tú eliges el valor, revisas a quién afecta y confirmas.
import { computed } from "vue";
import { ChevronDown, CircleCheck, CircleAlert, Clock3, Users } from "lucide-vue-next";
import AccionesMasivas from "./AccionesMasivas.vue";
import TablaParticipantes from "./TablaParticipantes.vue";
import { registrarPrincipal, useAsistente } from "@/Composables/CredentialFlow/importacionInteligente";

const a = useAsistente();
const r = computed(() => a.resumenParticipantes.value);
const bloqueos = computed(() => a.bloqueosPersonas.value);
const hayAmbiguos = computed(() => bloqueos.value.some((b) => b.codigo === "valores_ambiguos_sin_revisar"));
const personasConAmbiguos = computed(() => a.participantes.value.filter((p) => !p.excluir && p.valores_ambiguos.length).length);
// Todo lo demás se agrupa en una sola frase: «Hay 3 personas con datos por corregir».
const porCorregir = computed(() => bloqueos.value.filter((b) => b.codigo !== "valores_ambiguos_sin_revisar"));
const filasConProblema = computed(() => [...new Set(porCorregir.value.flatMap((b) => (b.fila != null ? [b.fila] : (b.filas ?? []))))]);
const sinPersonas = computed(() => bloqueos.value.some((b) => b.codigo === "sin_participantes"));
// Cuántas «cosas» (grupos) quedan por resolver: no una por cada fila.
const hayPorCorregir = computed(() => porCorregir.value.some((b) => b.codigo !== "sin_participantes"));
const totalPorResolver = computed(() => (hayAmbiguos.value ? 1 : 0) + (hayPorCorregir.value ? 1 : 0) + (sinPersonas.value ? 1 : 0));

// Elegibilidad y tipo: cuántos esperan una decisión tuya.
const completosPorRevisar = computed(() => a.participantes.value.filter((p) => !p.excluir && a.esSeleccionable(p) && p.senal === "completo" && p.elegibilidad !== "elegible").length);
const sinTipo = computed(() => a.participantes.value.filter((p) => !p.excluir && a.esSeleccionable(p) && !p.tipo_documento).length);
const porDecidir = computed(() => a.participantes.value.filter((p) => !p.excluir && p.elegibilidad === "pendiente").length);
const cupos = computed(() => r.value.cupos);
const personas = (n) => `${n} ${n === 1 ? "persona" : "personas"}`;

const primerBloqueo = computed(() => porCorregir.value[0] ?? null);
// El tipo de documento de muchas personas se asigna en bloque (con vista previa y confirmación), no fila por fila.
const revisarPrimero = () => primerBloqueo.value && (primerBloqueo.value.codigo === "tipo_documento_sin_confirmar" ? empezarTipo() : a.resolver(primerBloqueo.value));
const empezarElegibilidad = () => a.iniciarRevisionMasiva("elegibilidad") && scrollAlPanel();
const empezarTipo = () => a.iniciarRevisionMasiva("tipo_documento") && scrollAlPanel();
function scrollAlPanel() {
  requestAnimationFrame(() => requestAnimationFrame(() => document.querySelector("[data-panel-revision]")?.scrollIntoView({ behavior: "smooth", block: "start" })));
}
async function continuar() {
  if (await a.irA("vista")) a.hecho.value = "Revisaste a las personas.";
}

// La ÚNICA acción principal de la etapa (barra de abajo): sigue el hilo de lo que estés haciendo.
registrarPrincipal(a, () => {
  const m = a.masiva;
  if (m.abierta) {
    const que = m.campo === "tipo_documento" ? "un tipo de documento" : "una elegibilidad";
    if (m.aplicando) return { texto: "Aplicando…", accion: () => {}, deshabilitado: true, razon: "Guardando los cambios en el borrador…" };
    if (!m.valor) return { texto: "Revisar cambios", accion: () => {}, deshabilitado: true, razon: `Elige ${que} arriba para poder revisar.` };
    if (!m.vista) return { texto: m.cargando ? "Revisando…" : "Revisar cambios", accion: () => a.revisarMasiva(), deshabilitado: m.cargando, razon: m.cargando ? "Calculando a quién afecta…" : "" };
    if (m.vista.se_modifican === 0) return { texto: "Cerrar sin cambios", accion: () => a.cancelarMasiva(), razon: "Con esta selección ninguna persona cambiaría." };
    if (!a.puedeAplicarMasiva.value) return { texto: "Aplicar cambios al borrador", accion: () => {}, deshabilitado: true, razon: a.razonAplicarMasiva.value };

    return { texto: "Aplicar cambios al borrador", accion: () => a.aplicarMasiva() };
  }
  if (totalPorResolver.value) return { texto: "Revisar este dato", accion: primerBloqueo.value ? revisarPrimero : () => a.verPersonas("todos"), razon: "Antes de seguir hay algo por corregir en esta etapa." };
  if (a.origen.value) return { texto: `Volver a «${a.origen.value.titulo}»`, accion: () => a.volverAlOrigen() };

  return { texto: "Continuar", accion: continuar };
});
</script>

<template>
  <section class="space-y-5" aria-labelledby="titulo-etapa-personas" data-seccion="personas">
    <div>
      <h2 id="titulo-etapa-personas" tabindex="-1" class="text-xl font-extrabold text-slate-900 focus:outline-none">Revisa a las personas</h2>
      <p class="mt-1 text-sm font-medium text-slate-500" data-resumen-personas>
        Encontramos <strong class="text-slate-800">{{ personas(r.total) }}</strong> en tu Excel<template v-if="r.completos || r.cupos">: {{ r.completos }} con asistencia «Completo» y {{ r.cupos }} con «Cupos»</template>.
        Quien está en tu Excel <strong>queda listo para certificarse</strong>; solo decides si el Excel declara a alguien «No elegible» y el tipo de documento si falta.
      </p>
    </div>

    <!-- 1 · Lo que impide crear la base -->
    <section class="rounded-3xl border bg-white p-5 space-y-3" :class="totalPorResolver ? 'border-amber-300' : 'border-emerald-300'" aria-labelledby="titulo-antes" data-antes-crear>
      <h3 id="titulo-antes" class="flex items-center gap-2 text-base font-extrabold" :class="totalPorResolver ? 'text-amber-900' : 'text-emerald-900'">
        <component :is="totalPorResolver ? CircleAlert : CircleCheck" class="w-5 h-5" :class="totalPorResolver ? 'text-amber-600' : 'text-emerald-600'" aria-hidden="true" />
        Antes de crear la base
      </h3>

      <p v-if="!totalPorResolver" class="text-sm font-semibold text-emerald-900/90" data-nada-por-corregir>Aquí no falta nada para poder crear la base.</p>

      <template v-else>
        <p class="text-[13px] font-semibold text-slate-600">Hay {{ totalPorResolver === 1 ? "1 cosa" : `${totalPorResolver} cosas` }} por resolver para poder crear la base:</p>
        <ul class="space-y-2">
          <li v-if="sinPersonas" class="rounded-2xl bg-amber-50 px-4 py-3 text-sm font-extrabold text-amber-950" data-grupo="sin_participantes">
            No queda ninguna persona por importar: marca al menos una en la lista.
            <button type="button" class="ml-2 font-extrabold text-primary-vinotinto underline" @click="a.verPersonas('todos')">Ver la lista</button>
          </li>
          <li v-if="hayPorCorregir" class="rounded-2xl bg-amber-50 px-4 py-3" data-grupo="fila_invalida">
            <p class="text-sm font-extrabold text-amber-950">{{ filasConProblema.length === 1 ? "Hay 1 persona con datos por corregir" : `Hay ${filasConProblema.length || porCorregir.length} personas con datos por corregir` }}</p>
            <p v-if="primerBloqueo" class="mt-0.5 text-[12.5px] font-semibold text-amber-900/80 break-words">Por ejemplo<template v-if="primerBloqueo.fila != null">, la fila {{ primerBloqueo.fila }}</template>: {{ primerBloqueo.mensaje }}</p>
            <button type="button" class="mt-1 text-[13px] font-extrabold text-primary-vinotinto underline underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 rounded" data-recomendado data-accion-dato="revisar-dato" @click="revisarPrimero">Ver este dato</button>
          </li>
          <li v-if="hayAmbiguos" class="rounded-2xl bg-amber-50 px-4 py-3 space-y-2" data-grupo="valores_ambiguos">
            <p class="text-sm font-extrabold text-amber-950">{{ personasConAmbiguos === 1 ? "1 persona tiene" : `${personasConAmbiguos} personas tienen` }} datos que no se detectaron con certeza</p>
            <button type="button" class="text-[13px] font-extrabold text-primary-vinotinto underline underline-offset-2" data-ver-ambiguos @click="a.verPersonas('aviso:valores_ambiguos')">Ver {{ personasConAmbiguos === 1 ? "a esa persona" : `a esas ${personasConAmbiguos} personas` }}</button>
            <label class="flex items-start gap-2 rounded-xl bg-white/80 px-3 py-2 text-[13px] font-extrabold text-amber-950 cursor-pointer">
              <input type="checkbox" class="mt-0.5 rounded accent-[#942934]" :checked="a.confirmados.value.valores_ambiguos === true" data-confirmar-ambiguos @change="a.marcarConfirmacion('valores_ambiguos', $event.target.checked)" />
              Ya los revisé y están bien.
            </label>
          </li>
        </ul>
      </template>
    </section>

    <!-- 2 · Lo que puede esperar -->
    <section class="rounded-3xl border border-slate-200 bg-slate-50 p-5 space-y-3" aria-labelledby="titulo-esperar" data-puede-esperar>
      <div>
        <h3 id="titulo-esperar" class="flex items-center gap-2 text-base font-extrabold text-slate-800"><Clock3 class="w-5 h-5 text-slate-500" aria-hidden="true" /> Elegibilidad y tipo de documento</h3>
        <p class="text-[13px] font-semibold text-slate-600">Las personas del Excel quedan <strong>elegibles</strong>. Si alguna queda «Pendiente» o sin tipo de documento, hay que resolverlo antes de crear la base: así nadie se queda sin certificado sin que lo veas.</p>
      </div>

      <ul class="space-y-2">
        <li class="rounded-2xl bg-white px-4 py-3 space-y-1.5" data-tarea-personas="elegibilidad">
          <p class="text-sm font-extrabold text-slate-800">Elegibilidad</p>
          <template v-if="completosPorRevisar">
            <p class="text-[13px] font-semibold text-slate-600">{{ personas(completosPorRevisar) }} con asistencia «Completo» {{ completosPorRevisar === 1 ? "espera" : "esperan" }} tu confirmación. Tú revisas la lista y confirmas que verificaste su asistencia; no se aprueba sola.</p>
            <button type="button" class="rounded-xl px-4 py-2 text-[13px] font-extrabold text-primary-vinotinto ring-1 ring-primary-vinotinto/40 hover:bg-primary-vinotinto/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50" data-iniciar-elegibilidad @click="empezarElegibilidad">Revisar a {{ completosPorRevisar === 1 ? "la persona" : `las ${completosPorRevisar}` }} con «Completo»</button>
          </template>
          <p v-else-if="porDecidir" class="text-[13px] font-semibold text-amber-800" data-por-decidir>{{ personas(porDecidir) }} {{ porDecidir === 1 ? "queda" : "quedan" }} «Pendiente»: decide en cada una si se certifica.</p>
          <p v-else class="flex items-center gap-1.5 text-[13px] font-semibold text-emerald-700"><CircleCheck class="w-4 h-4" aria-hidden="true" /> Todas las personas quedan elegibles.</p>
          <p v-if="cupos" class="text-[12.5px] font-semibold text-amber-800" data-nota-cupos>
            {{ personas(cupos) }} con «Cupos» en el Excel: es solo informativo, {{ cupos === 1 ? "queda elegible" : "quedan elegibles" }} igual.
            <button type="button" class="ml-1 font-extrabold text-primary-vinotinto underline underline-offset-2" data-ver-cupos @click="a.verPersonas('cupos')">Ver {{ cupos === 1 ? "a esa persona" : "a esas personas" }}</button>
          </p>
        </li>
        <li class="rounded-2xl bg-white px-4 py-3 space-y-1.5" data-tarea-personas="tipo_documento">
          <p class="text-sm font-extrabold text-slate-800">Tipo de documento</p>
          <template v-if="sinTipo">
            <p class="text-[13px] font-semibold text-amber-800">{{ personas(sinTipo) }} {{ sinTipo === 1 ? "tiene" : "tienen" }} el tipo por confirmar. No lo suponemos: elige el tipo después de comprobarlo (hace falta para crear la base).</p>
            <button type="button" class="rounded-xl px-4 py-2 text-[13px] font-extrabold text-primary-vinotinto ring-1 ring-primary-vinotinto/40 hover:bg-primary-vinotinto/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50" data-iniciar-tipo @click="empezarTipo">Asignar el tipo de documento</button>
          </template>
          <p v-else class="flex items-center gap-1.5 text-[13px] font-semibold text-emerald-700"><CircleCheck class="w-4 h-4" aria-hidden="true" /> Todas las personas tienen su tipo de documento.</p>
        </li>
      </ul>
    </section>

    <!-- Revisión guiada de lo seleccionado (aparece solo cuando empiezas una acción) -->
    <AccionesMasivas parte="panel" :lista="[]" :visibles="0" />

    <!-- Lista completa: cerrada hasta que la pidas -->
    <section class="rounded-3xl border border-slate-200 bg-white p-4 sm:p-5 space-y-4" data-lista-completa>
      <button type="button" class="flex w-full items-center justify-between gap-3 rounded-xl text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50" :aria-expanded="a.listaAbierta.value" aria-controls="lista-personas" data-abrir-lista @click="a.listaAbierta.value = !a.listaAbierta.value">
        <span class="flex items-center gap-2 text-base font-extrabold text-slate-900"><Users class="w-5 h-5 text-primary-vinotinto" aria-hidden="true" /> {{ a.listaAbierta.value ? "Ocultar la lista de personas" : `Ver las ${r.total} personas` }}</span>
        <ChevronDown class="w-5 h-5 text-slate-400 transition-transform" :class="a.listaAbierta.value ? 'rotate-180' : ''" aria-hidden="true" />
      </button>
      <p v-if="!a.listaAbierta.value" class="text-[12.5px] font-semibold text-slate-500">Ábrela si quieres corregir un nombre, un documento o un correo, o decidir a alguien en particular. No hace falta para seguir.</p>
      <div v-if="a.listaAbierta.value" id="lista-personas"><TablaParticipantes /></div>
    </section>

    <p class="text-[13px] font-semibold" :class="totalPorResolver ? 'text-amber-800' : 'text-slate-500'" data-nota-personas :data-razon-personas="totalPorResolver ? '' : undefined">
      <template v-if="!totalPorResolver">Cuando termines de revisar, pulsa el botón de abajo.</template>
      <template v-else>Para seguir, resuelve {{ totalPorResolver === 1 ? "la cosa marcada" : "las cosas marcadas" }} en «Antes de crear la base».</template>
    </p>
  </section>
</template>
