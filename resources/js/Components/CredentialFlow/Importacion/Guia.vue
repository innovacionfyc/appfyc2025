<script setup>
// Tarjeta guía: en cada etapa dice, en pocas palabras, 1) qué acabas de hacer, 2) qué estás revisando ahora y 3) qué sigue.
// RECOMIENDA el siguiente paso con palabras (el botón principal de la barra de abajo te lleva ahí) pero nunca confirma nada por ti.
import { computed } from "vue";
import { ArrowRight, Check, Eye } from "lucide-vue-next";
import { useAsistente } from "@/Composables/CredentialFlow/importacionInteligente";

const a = useAsistente();
const g = computed(() => a.guia.value);
</script>

<template>
  <section v-if="a.borrador.value && !a.lote.value && a.paso.value !== 'subir'" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 space-y-2" aria-label="Dónde vas" data-guia>
    <p v-if="g.hecho" class="flex items-start gap-2 text-[13px] font-semibold text-slate-600" data-guia-hecho>
      <Check class="w-4 h-4 shrink-0 mt-0.5 text-emerald-600" aria-hidden="true" />
      <span><strong class="text-slate-800">Acabas de:</strong> {{ g.hecho }}</span>
    </p>
    <p class="flex items-start gap-2 text-[13px] font-semibold text-slate-600" data-guia-ahora>
      <Eye class="w-4 h-4 shrink-0 mt-0.5 text-sky-600" aria-hidden="true" />
      <span><strong class="text-slate-800">Ahora:</strong> {{ g.ahora }}</span>
    </p>
    <div v-if="g.sigue" class="rounded-xl bg-primary-vinotinto/5 px-3 py-2" data-guia-sigue>
      <p class="flex items-start gap-2 text-[13px] font-extrabold text-primary-vinotinto">
        <ArrowRight class="w-4 h-4 shrink-0 mt-0.5" aria-hidden="true" />
        <span><span class="text-slate-800">Sigue:</span> {{ g.sigue.texto }}</span>
      </p>
    </div>
  </section>
</template>
