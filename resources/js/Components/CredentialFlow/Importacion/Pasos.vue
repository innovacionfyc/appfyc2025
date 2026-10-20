<script setup>
// Los cuatro pasos del asistente. Solo permite saltar a un paso ya alcanzado (hay borrador); la importación NUNCA se dispara por avanzar de paso.
import { computed } from "vue";
import { Check } from "lucide-vue-next";
import { PASOS, useAsistente } from "@/Composables/CredentialFlow/importacionInteligente";

const a = useAsistente();
const indice = computed(() => PASOS.findIndex((p) => p.id === a.paso.value));
const puedeIr = (p, i) => !a.lote.value && (i === 0 || a.borrador.value !== null) && a.borrador.value?.estado !== "confirmado";
</script>

<template>
  <nav aria-label="Pasos de la importación">
    <ol class="grid grid-cols-4 gap-1 sm:gap-3" data-pasos>
      <li v-for="(p, i) in PASOS" :key="p.id" class="min-w-0">
        <button
          type="button"
          :disabled="!puedeIr(p, i) && i !== indice"
          :aria-current="i === indice ? 'step' : undefined"
          :data-paso="p.id"
          class="group w-full flex flex-col sm:flex-row items-center sm:items-center gap-1.5 sm:gap-3 rounded-2xl px-1.5 sm:px-3 py-2 text-center sm:text-left transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 disabled:cursor-default"
          :class="i === indice ? 'bg-primary-vinotinto/5' : puedeIr(p, i) ? 'hover:bg-slate-50' : ''"
          @click="a.irA(p.id)"
        >
          <span
            class="w-8 h-8 shrink-0 rounded-full flex items-center justify-center text-[13px] font-black ring-2 transition-colors"
            :class="
              i < indice || a.lote.value
                ? 'bg-emerald-500 text-white ring-emerald-500'
                : i === indice
                  ? 'bg-primary-vinotinto text-white ring-primary-vinotinto'
                  : 'bg-white text-slate-400 ring-slate-200'
            "
          >
            <Check v-if="i < indice || a.lote.value" class="w-4 h-4" aria-hidden="true" />
            <span v-else>{{ p.numero }}</span>
          </span>
          <span class="min-w-0">
            <span class="block text-[11px] sm:text-[13px] lg:text-sm font-extrabold leading-tight" :class="i === indice ? 'text-primary-vinotinto' : 'text-slate-600'">
              <span class="sm:hidden">{{ p.corto }}</span>
              <span class="hidden sm:inline">{{ p.titulo }}</span>
            </span>
          </span>
        </button>
      </li>
    </ol>
  </nav>
</template>
