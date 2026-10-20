<script setup>
// Progreso del recorrido guiado: una barra sencilla («Paso 3 de 6») y las seis etapas. Solo se puede volver a una etapa ya alcanzada (hay borrador);
// la importación NUNCA se dispara por avanzar o retroceder: lo que ya está guardado se conserva.
import { computed } from "vue";
import { Check } from "lucide-vue-next";
import { PASOS, useAsistente } from "@/Composables/CredentialFlow/importacionInteligente";

const a = useAsistente();
const indice = computed(() => a.indicePaso.value);
const p = computed(() => a.progreso.value);
const puedeIr = (i) => !a.lote.value && a.borrador.value !== null && a.borrador.value?.estado !== "confirmado" && i <= a.maxEtapa.value;
const hecho = (i) => i < indice.value || !!a.lote.value;
</script>

<template>
  <nav aria-label="Progreso de la importación" class="space-y-3">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
      <p class="text-[13px] font-extrabold text-slate-800" data-progreso-texto>
        Paso {{ p.actual }} de {{ p.total }} · <span class="text-primary-vinotinto">{{ p.titulo }}</span>
      </p>
      <p v-if="a.borrador.value && !a.lote.value" class="text-[12px] font-bold" :class="p.faltan ? 'text-amber-700' : 'text-emerald-700'" data-progreso-faltan>
        {{ p.faltan ? (p.faltan === 1 ? "Para crear la base falta 1 cosa" : `Para crear la base faltan ${p.faltan} cosas`) : "Ya tienes todo lo necesario para crear la base" }}
      </p>
    </div>

    <div class="h-2 rounded-full bg-slate-100 overflow-hidden" role="progressbar" :aria-valuenow="p.actual" aria-valuemin="1" :aria-valuemax="p.total" :aria-label="`Paso ${p.actual} de ${p.total}`" data-barra-progreso>
      <div class="h-full rounded-full bg-primary-vinotinto transition-all duration-500" :style="{ width: `${Math.max(6, p.porcentaje)}%` }"></div>
    </div>

    <ol class="grid grid-cols-6 gap-1 sm:gap-2" data-pasos>
      <li v-for="(e, i) in PASOS" :key="e.id" class="min-w-0">
        <button
          type="button"
          :disabled="!puedeIr(i) && i !== indice"
          :aria-current="i === indice ? 'step' : undefined"
          :data-paso="e.id"
          class="w-full flex flex-col items-center gap-1 rounded-xl px-0.5 py-1.5 text-center transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 disabled:cursor-default"
          :class="i === indice ? 'bg-primary-vinotinto/5' : puedeIr(i) ? 'hover:bg-slate-50' : ''"
          @click="a.irA(e.id)"
        >
          <span
            class="w-7 h-7 shrink-0 rounded-full flex items-center justify-center text-[12px] font-black ring-2 transition-colors"
            :class="hecho(i) ? 'bg-emerald-500 text-white ring-emerald-500' : i === indice ? 'bg-primary-vinotinto text-white ring-primary-vinotinto' : 'bg-white text-slate-400 ring-slate-200'"
          >
            <Check v-if="hecho(i)" class="w-3.5 h-3.5" aria-hidden="true" />
            <span v-else>{{ e.numero }}</span>
          </span>
          <span class="block text-[10.5px] sm:text-[12px] font-extrabold leading-tight break-words" :class="i === indice ? 'text-primary-vinotinto' : 'text-slate-500'">{{ e.corto }}</span>
        </button>
      </li>
    </ol>
  </nav>
</template>
