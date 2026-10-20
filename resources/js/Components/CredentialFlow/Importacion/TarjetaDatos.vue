<script setup>
// Paso 2 · Datos del certificado (fecha, modalidad, intensidad, ciudad, textos). Cada dato dice si fue DETECTADO, hay que REVISARLO o está FALTANTE.
// Las advertencias de datos que vienen de otra hoja (IMPORTRANGE) van AGRUPADAS en una sola tarjeta, no una por dato.
import { computed } from "vue";
import { FileText, TriangleAlert, RotateCcw } from "lucide-vue-next";
import EstadoDato from "./EstadoDato.vue";
import { useAsistente } from "@/Composables/CredentialFlow/importacionInteligente";

const a = useAsistente();
const generales = computed(() => a.borrador.value?.avisos_generales ?? { desactualizables_evento: 0, desactualizables_participantes: 0 });
const totalDesactualizables = computed(() => generales.value.desactualizables_evento + generales.value.desactualizables_participantes);
const datosDesactualizables = computed(() => a.datos.value.filter((d) => d.desactualizable && d.origen === "excel" && d.valor !== ""));
const revisados = computed(() => a.confirmados.value.valores_desactualizados === true && datosDesactualizables.value.every((d) => d.confirmado));

function marcarRevisados(si) {
  a.marcarConfirmacion("valores_desactualizados", si);
  datosDesactualizables.value.forEach((d) => a.confirmarDato(d.campo, si));
}
</script>

<template>
  <div class="space-y-4">
    <section v-if="totalDesactualizables > 0" class="rounded-3xl border border-amber-300 bg-amber-50 p-5 space-y-3" aria-labelledby="titulo-aviso-importrange" data-aviso-importrange>
      <h3 id="titulo-aviso-importrange" class="flex items-start gap-2 text-base font-extrabold text-amber-900">
        <TriangleAlert class="w-5 h-5 shrink-0 mt-0.5" aria-hidden="true" />
        <span>{{ totalDesactualizables }} datos de este Excel pueden no estar al día</span>
      </h3>
      <p class="text-sm font-semibold text-amber-900/90">
        Estos datos no están escritos en el Excel: los trae de <strong>otra hoja de Google Sheets</strong>. Si esa hoja cambió después de exportar el archivo, aquí pueden verse datos viejos.
        Compáralos con la fuente antes de continuar.
      </p>
      <details class="text-[13px] font-semibold text-amber-900">
        <summary class="cursor-pointer font-extrabold underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 rounded">Ver el detalle</summary>
        <ul class="mt-2 list-disc pl-5 space-y-1">
          <li>{{ generales.desactualizables_evento }} del evento: {{ datosDesactualizables.map((d) => d.etiqueta.toLowerCase()).join(", ") || "datos del evento" }} y el nombre del evento.</li>
          <li>{{ generales.desactualizables_participantes }} de los participantes: nombres, documentos, correos y la información de asistencia.</li>
        </ul>
      </details>
      <label class="flex items-start gap-2 rounded-2xl bg-white/70 px-4 py-3 text-sm font-extrabold text-amber-950 cursor-pointer">
        <input type="checkbox" class="mt-0.5 rounded accent-[#942934]" :checked="revisados" data-confirmar-importrange @change="marcarRevisados($event.target.checked)" />
        Ya los revisé y confirmo que están al día.
      </label>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-5 sm:p-6 space-y-5" aria-labelledby="titulo-datos" data-seccion="datos">
      <h3 id="titulo-datos" class="flex items-center gap-2 text-base font-extrabold text-slate-900"><FileText class="w-5 h-5 text-primary-vinotinto" aria-hidden="true" /> Datos del certificado</h3>

      <div v-for="d in a.datos.value" :key="d.campo" class="space-y-1.5" :data-dato="d.campo">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <label :for="`dato-${d.campo}`" class="text-sm font-extrabold text-slate-800">{{ d.etiqueta }}<span v-if="d.requerido" class="text-rose-600"> *</span></label>
          <EstadoDato :estado="d.estado" />
        </div>

        <textarea
          v-if="d.largo"
          :id="`dato-${d.campo}`"
          rows="3"
          maxlength="600"
          :value="d.valor"
          :aria-invalid="!!a.erroresCampo[`dato:${d.campo}`]"
          :aria-describedby="`ayuda-${d.campo}`"
          class="w-full rounded-xl border-slate-300 text-sm font-semibold focus:border-primary-vinotinto focus:ring-primary-vinotinto/30"
          @input="a.editarDato(d.campo, $event.target.value)"
        ></textarea>
        <input
          v-else
          :id="`dato-${d.campo}`"
          type="text"
          maxlength="255"
          :value="d.valor"
          :aria-invalid="!!a.erroresCampo[`dato:${d.campo}`]"
          :aria-describedby="`ayuda-${d.campo}`"
          class="w-full rounded-xl border-slate-300 text-sm font-semibold focus:border-primary-vinotinto focus:ring-primary-vinotinto/30"
          @input="a.editarDato(d.campo, $event.target.value)"
        />
        <p :id="`ayuda-${d.campo}`" class="text-[12px] font-medium text-slate-400">{{ d.ayuda }}</p>
        <p v-if="a.erroresCampo[`dato:${d.campo}`]" class="text-[13px] font-bold text-rose-600" role="alert">{{ a.erroresCampo[`dato:${d.campo}`] }}</p>

        <div v-if="d.origen === 'admin' && d.propuesta" class="flex flex-wrap items-center gap-2 text-[12px] font-semibold text-slate-500">
          <span>El Excel decía: <span class="font-bold text-slate-700">{{ d.propuesta }}</span></span>
          <button type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 font-extrabold text-primary-vinotinto hover:bg-primary-vinotinto/5" @click="a.editarDato(d.campo, '')"><RotateCcw class="w-3.5 h-3.5" aria-hidden="true" /> Restaurar</button>
        </div>
        <p v-else-if="d.origen === 'excel' && d.referencia" class="text-[12px] font-semibold text-slate-400">Detectado en la celda {{ d.referencia }} del Excel.</p>

        <label v-if="d.estado === 'revisar' || (d.estado === 'confirmado' && d.origen === 'excel')" class="flex items-start gap-2 text-[13px] font-semibold text-slate-700 cursor-pointer">
          <input type="checkbox" class="mt-0.5 rounded accent-[#942934]" :checked="d.confirmado" :data-confirmar-dato="d.campo" @change="a.confirmarDato(d.campo, $event.target.checked)" />
          {{ d.desactualizable ? "Está al día y es correcto." : "Lo revisé y es correcto." }}
        </label>
      </div>
    </section>
  </div>
</template>
