<script setup>
// «Ese evento ya existe»: aparece cuando se intenta crear una capacitación con el nombre exacto de una ya registrada. Muestra la capacitación encontrada
// (nombre y fecha) y ofrece «Usar esta capacitación». Si hay varias con ese nombre, el administrador elige cuál. Usarla no crea ni modifica ningún evento:
// es una corrección más del borrador, así que el Excel y todo lo revisado se conservan.
import { ref } from "vue";
import { CalendarCheck, Loader2 } from "lucide-vue-next";
import { useAsistente } from "@/Composables/CredentialFlow/importacionInteligente";

const a = useAsistente();
const usando = ref(null);

async function usar(e) {
  if (usando.value !== null) return;
  usando.value = e.id;
  try {
    await a.usarEvento(e);
  } finally {
    usando.value = null;
  }
}
</script>

<template>
  <div v-if="a.eventosYaExistentes.value.length" class="space-y-3 rounded-2xl border border-amber-300 bg-amber-50 p-4" role="alert" data-evento-ya-existe>
    <div>
      <p class="flex items-center gap-2 text-sm font-extrabold text-amber-950">
        <CalendarCheck class="w-4 h-4 shrink-0" aria-hidden="true" /> Ese evento ya existe
      </p>
      <p class="mt-1 text-[13px] font-semibold text-amber-900/90">
        {{ a.eventosYaExistentes.value.length === 1 ? "Ya hay una capacitación registrada con ese nombre." : `Hay ${a.eventosYaExistentes.value.length} capacitaciones registradas con ese nombre: elige cuál es.` }}
        Úsala para agregar estas personas: no se crea otra y se conservan tu Excel y todo lo que ya revisaste.
      </p>
    </div>
    <ul class="space-y-2">
      <li v-for="e in a.eventosYaExistentes.value" :key="e.id" class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white px-3 py-2.5" :data-evento-encontrado="e.id">
        <span class="min-w-0 flex-1 basis-56">
          <span class="block text-sm font-extrabold text-slate-900 break-words">{{ e.nombre }}</span>
          <span class="block text-[12px] font-semibold text-slate-500">{{ e.fecha || "Sin fecha registrada" }}</span>
        </span>
        <button
          type="button"
          class="inline-flex shrink-0 items-center gap-1.5 rounded-xl bg-primary-vinotinto px-3.5 py-2 text-[13px] font-bold text-white hover:opacity-90 disabled:opacity-50"
          :disabled="usando !== null || a.guardando.value"
          :data-usar-evento="e.id"
          @click="usar(e)"
        >
          <Loader2 v-if="usando === e.id" class="w-4 h-4 animate-spin" aria-hidden="true" />
          Usar esta capacitación
        </button>
      </li>
    </ul>
  </div>
</template>
