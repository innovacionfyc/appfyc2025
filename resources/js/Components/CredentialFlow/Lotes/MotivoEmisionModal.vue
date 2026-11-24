<script setup>
import { ref, watch, computed } from "vue";
import { X, CircleAlert, Loader2 } from "lucide-vue-next";

// Modal de motivo para revocar o reemitir (5–500 caracteres). El padre hace la petición y devuelve el error.
const props = defineProps({
  show: { type: Boolean, default: false },
  modo: { type: String, default: "revocar" }, // revocar | reemitir
  participante: { type: String, default: "" },
  procesando: { type: Boolean, default: false },
  error: { type: Object, default: null },
  // Al reemitir: el certificado vigente que se reemplaza ({ codigo, version, emitido_at }) y si es una prueba interna (QA).
  certificado: { type: Object, default: null },
  esQa: { type: Boolean, default: false },
});
const emit = defineEmits(["close", "confirmar"]);

const motivo = ref("");
watch(() => props.show, (v) => v && (motivo.value = ""));

const textos = computed(() =>
  props.modo === "revocar"
    ? { titulo: "Revocar certificado", boton: "Revocar", ayuda: "El certificado dejará de ser válido. Su archivo se conserva en el historial." }
    : { titulo: "Reemitir certificado", boton: "Reemitir certificado", ayuda: "Se creará una versión nueva con los datos que la persona tiene ahora (incluidas las correcciones que ya guardaste)." }
);
const emitido = computed(() => {
  const iso = props.certificado?.emitido_at;
  if (!iso) return null;
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? null : new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "long", year: "numeric", timeZone: "America/Bogota" }).format(d);
});
const largo = computed(() => motivo.value.trim().length);
const valido = computed(() => largo.value >= 5 && largo.value <= 500);

const confirmar = () => {
  if (!valido.value || props.procesando) return;
  emit("confirmar", motivo.value.trim());
};
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
      <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" @click="!procesando && emit('close')" />
      <div role="dialog" aria-modal="true" aria-labelledby="cf-motivo-titulo" class="relative z-50 w-full max-w-lg bg-white rounded-[2rem] shadow-2xl p-6 sm:p-8" data-modal-motivo>
        <div class="flex items-start justify-between gap-4">
          <div>
            <h3 id="cf-motivo-titulo" class="text-lg font-black text-slate-900">{{ textos.titulo }}</h3>
            <p class="text-xs font-semibold text-slate-400 mt-0.5 break-words">{{ participante }}</p>
          </div>
          <button type="button" class="w-9 h-9 flex items-center justify-center rounded-2xl text-slate-400 hover:text-slate-700 hover:bg-slate-100" aria-label="Cerrar" :disabled="procesando" @click="emit('close')">
            <X class="w-5 h-5" />
          </button>
        </div>

        <p class="mt-4 text-[13px] font-medium text-slate-600">{{ textos.ayuda }}</p>

        <div v-if="modo === 'reemitir' && certificado" class="mt-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-950" data-certificado-reemplazado>
          <p class="font-extrabold">Se reemplaza este certificado{{ esQa ? " de prueba interna (QA)" : "" }}:</p>
          <p class="mt-1 font-semibold">
            Código <span class="font-mono font-bold" data-codigo-anterior>{{ certificado.codigo }}</span> · versión {{ certificado.version }}<template v-if="emitido"> · emitido el {{ emitido }}</template>
          </p>
          <p class="mt-1 font-medium">Ese código quedará <strong>revocado</strong> y el certificado nuevo tendrá otro código. <strong>No se envía ningún correo</strong>: el envío es una decisión aparte.</p>
        </div>

        <label class="mt-4 block text-[11px] font-black uppercase tracking-widest text-slate-400" for="cf-motivo">Motivo (obligatorio)</label>
        <textarea id="cf-motivo" v-model="motivo" rows="3" maxlength="500" class="mt-1 w-full rounded-2xl border-slate-200 text-sm font-medium focus:ring-primary-vinotinto/30 focus:border-primary-vinotinto" placeholder="Cuéntanos el motivo (mínimo 5 caracteres)" data-motivo />
        <p class="text-right text-[11px] font-bold" :class="valido || largo === 0 ? 'text-slate-400' : 'text-rose-500'">{{ largo }}/500</p>

        <div v-if="error" class="mt-3 flex items-start gap-2 px-3 py-2 rounded-xl bg-red-50 text-red-700 text-[13px] font-semibold" role="alert" :data-codigo="error.code">
          <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" />
          <span>{{ error.message }}</span>
        </div>

        <div class="mt-6 flex justify-end gap-2">
          <button type="button" class="px-4 py-2.5 rounded-2xl bg-slate-100 text-[13px] font-bold text-slate-600 hover:bg-slate-200 disabled:opacity-50" :disabled="procesando" @click="emit('close')">Cancelar</button>
          <button type="button" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl text-white text-[13px] font-bold disabled:opacity-50" :class="modo === 'revocar' ? 'bg-rose-600 hover:bg-rose-700' : 'bg-primary-vinotinto hover:opacity-90'" :disabled="!valido || procesando" data-accion="confirmar-motivo" @click="confirmar">
            <Loader2 v-if="procesando" class="w-4 h-4 animate-spin" />
            {{ procesando ? "Procesando…" : textos.boton }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
