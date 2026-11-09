<script setup>
import { computed, nextTick, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import { CircleAlert, History, Info, Layers, Loader2, ShieldCheck, ShieldOff, X } from "lucide-vue-next";

// Doble certificación por modalidad del evento. Todos los administradores ven el estado; solo un super-admin ve impacto, bloqueos e historial y puede
// autorizar o revocar (con motivo y confirmación). El servidor vuelve a validar todo (rol incluido): esta pantalla solo muestra y pide.
const props = defineProps({
  evento: { type: Object, required: true }, // { id, nombre }
  estado: { type: Object, required: true }, // { autorizado, origen, desde, puede_gestionar, disponible, impacto?, bloqueos_autorizar?, bloqueos_revocar?, historial?, motivo_min?, motivo_max? }
});

const ORIGENES = {
  base_de_datos: "autorizado desde el panel",
  configuracion_heredada: "autorizado por la configuración heredada del servidor",
  ambos: "autorizado desde el panel y por la configuración heredada del servidor",
};
const ORIGEN_FILA = { panel: "Panel", configuracion_heredada: "Configuración heredada" };

const formatFecha = (iso) => {
  if (!iso) return "—";
  const d = new Date(iso);
  return isNaN(d.getTime()) ? "—" : new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit", timeZone: "America/Bogota" }).format(d);
};

const gestiona = computed(() => props.estado.puede_gestionar === true && props.estado.disponible === true);
const vigenteEnPanel = computed(() => ["base_de_datos", "ambos"].includes(props.estado.origen));
const historial = computed(() => props.estado.historial ?? []);

// ── Ventana de confirmación ──────────────────────────────────────────────────
const modo = ref(null); // "autorizar" | "revocar" | null
const motivo = ref("");
const confirmado = ref(false);
const enviando = ref(false);
const MIN = computed(() => props.estado.motivo_min ?? 10);
const MAX = computed(() => props.estado.motivo_max ?? 500);
const bloqueos = computed(() => (modo.value === "revocar" ? props.estado.bloqueos_revocar : props.estado.bloqueos_autorizar) ?? []);
const motivoOk = computed(() => motivo.value.trim().length >= MIN.value && motivo.value.trim().length <= MAX.value);
const puedeEnviar = computed(() => !!modo.value && bloqueos.value.length === 0 && motivoOk.value && confirmado.value && !enviando.value);

const abrir = (m) => {
  modo.value = m;
  motivo.value = "";
  confirmado.value = false;
  enviando.value = false;
};
const cerrar = () => !enviando.value && (modo.value = null);

const campoMotivo = ref(null);
watch(motivo, () =>
  nextTick(() => {
    const el = campoMotivo.value;
    if (!el) return;
    el.style.height = "auto";
    el.style.height = `${el.scrollHeight}px`;
  })
);

const enviar = () => {
  if (!puedeEnviar.value) return;
  const ruta = modo.value === "autorizar" ? "credential-flow.eventos.doble-modalidad.autorizar" : "credential-flow.eventos.doble-modalidad.revocar";
  router.post(route(ruta, props.evento.id), { motivo: motivo.value.trim(), confirmacion: true }, {
    preserveScroll: true,
    onStart: () => (enviando.value = true),
    onFinish: () => {
      enviando.value = false;
      modo.value = null;
    },
  });
};
</script>

<template>
  <section class="mt-8 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6" data-doble-modalidad>
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h2 class="flex items-center gap-2 text-sm font-extrabold text-slate-700"><Layers class="w-4 h-4 text-slate-400" /> Doble certificación por modalidad</h2>
        <p class="mt-1 text-[13px] font-medium text-slate-500">
          Si está autorizada, una misma persona puede tener en una base de este evento una participación por modalidad (por ejemplo presencial y virtual), cada una con su certificado.
          Sigue exigiendo el mismo documento, el mismo nombre y modalidades distintas. Sin autorización, cada documento aparece una sola vez por base.
        </p>
      </div>
      <span
        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[12px] font-bold"
        :class="estado.autorizado ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'"
        :data-estado-doble="estado.autorizado ? 'autorizada' : 'no-autorizada'"
      >
        <ShieldCheck v-if="estado.autorizado" class="w-3.5 h-3.5" />
        <ShieldOff v-else class="w-3.5 h-3.5" />
        {{ estado.autorizado ? "Autorizada" : "No autorizada" }}
      </span>
    </div>

    <p v-if="estado.autorizado" class="mt-3 text-[12px] font-semibold text-slate-500" data-origen-doble>
      Evento {{ ORIGENES[estado.origen] ?? "autorizado" }}<template v-if="estado.desde"> · desde {{ formatFecha(estado.desde) }}</template>.
    </p>

    <template v-if="gestiona">
      <dl v-if="estado.impacto" class="mt-4 grid grid-cols-2 sm:grid-cols-5 gap-2 text-center" data-impacto-doble>
        <div class="rounded-2xl bg-slate-50 px-2 py-2"><dt class="text-[11px] font-bold text-slate-400">Bases</dt><dd class="font-black text-slate-900" data-valor="bases">{{ estado.impacto.bases }}</dd></div>
        <div class="rounded-2xl bg-slate-50 px-2 py-2"><dt class="text-[11px] font-bold text-slate-400">Certificados vigentes</dt><dd class="font-black text-slate-900" data-valor="emisiones">{{ estado.impacto.emisiones_vigentes }}</dd></div>
        <div class="rounded-2xl bg-slate-50 px-2 py-2"><dt class="text-[11px] font-bold text-slate-400">Personas con dos participaciones</dt><dd class="font-black text-slate-900" data-valor="dobles">{{ estado.impacto.documentos_dobles }}</dd></div>
        <div class="rounded-2xl bg-slate-50 px-2 py-2"><dt class="text-[11px] font-bold text-slate-400">Programaciones activas</dt><dd class="font-black text-slate-900" data-valor="programaciones">{{ estado.impacto.programaciones_activas }}</dd></div>
        <div class="rounded-2xl bg-slate-50 px-2 py-2"><dt class="text-[11px] font-bold text-slate-400">Borradores en revisión</dt><dd class="font-black text-slate-900" data-valor="borradores">{{ estado.impacto.borradores_en_revision }}</dd></div>
      </dl>

      <div class="mt-4 flex flex-wrap items-center gap-2">
        <button v-if="!vigenteEnPanel" type="button" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90" data-accion="autorizar-doble" @click="abrir('autorizar')">
          <ShieldCheck class="w-4 h-4" /> Autorizar…
        </button>
        <button v-else type="button" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-700 hover:bg-slate-50" data-accion="revocar-doble" @click="abrir('revocar')">
          <ShieldOff class="w-4 h-4" /> Revocar…
        </button>
      </div>

      <div v-if="historial.length" class="mt-5" data-historial-doble>
        <h3 class="flex items-center gap-1.5 text-[12px] font-extrabold text-slate-500"><History class="w-3.5 h-3.5" /> Historial de autorizaciones</h3>
        <ul class="mt-2 divide-y divide-slate-100 text-[12px]">
          <li v-for="h in historial" :key="h.id" class="py-2 break-words" :data-autorizacion="h.id">
            <p class="font-bold text-slate-700">
              #{{ h.id }} · {{ h.estado === "vigente" ? "Vigente" : "Revocada" }} · {{ ORIGEN_FILA[h.origen] ?? h.origen }}
            </p>
            <p class="text-slate-500 font-semibold">Autorizada por {{ h.autorizada_por }} el {{ formatFecha(h.autorizada_at) }}: «{{ h.motivo }}»</p>
            <p v-if="h.revocada_at" class="text-slate-500 font-semibold">Revocada por {{ h.revocada_por }} el {{ formatFecha(h.revocada_at) }}: «{{ h.motivo_revocacion }}»</p>
          </li>
        </ul>
      </div>
    </template>
    <p v-else-if="!estado.puede_gestionar" class="mt-3 flex items-start gap-1.5 text-[12px] font-semibold text-slate-400" data-solo-lectura-doble>
      <Info class="w-3.5 h-3.5 mt-0.5 shrink-0" /> Solo un super-admin puede autorizarla o revocarla.
    </p>

    <Teleport to="body">
      <div v-if="modo" class="fixed inset-0 z-[110] flex items-center justify-center p-3 sm:p-4" @keydown.esc="cerrar">
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" @click="cerrar" />
        <div
          role="dialog"
          aria-modal="true"
          aria-labelledby="cf-doble-titulo"
          class="relative z-50 w-full max-w-lg max-h-[calc(100dvh-1.5rem)] sm:max-h-[92vh] overflow-y-auto overflow-x-hidden overscroll-contain bg-white rounded-[1.5rem] sm:rounded-[2rem] shadow-2xl p-5 sm:p-8 break-words [overflow-wrap:anywhere]"
          data-modal-doble-modalidad
        >
          <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
              <h3 id="cf-doble-titulo" class="text-lg font-black text-slate-900">{{ modo === "autorizar" ? "Autorizar la doble certificación por modalidad" : "Revocar la doble certificación por modalidad" }}</h3>
              <p class="text-xs font-semibold text-slate-400 mt-0.5 break-words">#{{ evento.id }} · {{ evento.nombre }}</p>
            </div>
            <button type="button" class="w-9 h-9 shrink-0 flex items-center justify-center rounded-2xl text-slate-400 hover:text-slate-700 hover:bg-slate-100" aria-label="Cerrar" :disabled="enviando" @click="cerrar">
              <X class="w-5 h-5" />
            </button>
          </div>

          <p class="mt-4 text-[13px] font-medium text-slate-600" data-texto-principal>
            <template v-if="modo === 'autorizar'">Desde ahora, en las bases de este evento se podrá registrar una participación por modalidad de la misma persona (mismo documento y mismo nombre, modalidades distintas).</template>
            <template v-else>Desde ahora, cada documento volverá a aparecer una sola vez por base en este evento.</template>
            <strong> No se cambia ningún participante ni certificado.</strong>
          </p>

          <dl v-if="estado.impacto" class="mt-4 rounded-2xl border border-slate-100 divide-y divide-slate-100 text-sm" data-resumen-doble>
            <div class="flex items-center justify-between gap-3 px-3 sm:px-4 py-2"><dt class="font-semibold text-slate-500">Bases del evento</dt><dd class="font-black text-slate-900">{{ estado.impacto.bases }}</dd></div>
            <div class="flex items-center justify-between gap-3 px-3 sm:px-4 py-2"><dt class="font-semibold text-slate-500">Certificados vigentes (se conservan)</dt><dd class="font-black text-slate-900">{{ estado.impacto.emisiones_vigentes }}</dd></div>
            <div class="flex items-center justify-between gap-3 px-3 sm:px-4 py-2"><dt class="font-semibold text-slate-500">Personas con dos participaciones</dt><dd class="font-black text-slate-900">{{ estado.impacto.documentos_dobles }}</dd></div>
          </dl>

          <ul v-if="bloqueos.length" class="mt-4 space-y-2" data-bloqueos>
            <li v-for="b in bloqueos" :key="b.code" class="flex items-start gap-2 px-3 py-2 rounded-xl bg-red-50 text-red-700 text-[13px] font-semibold" role="alert" :data-bloqueo="b.code">
              <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" /> {{ b.message }}
            </li>
          </ul>

          <template v-else>
            <label class="mt-5 block text-[12px] font-bold text-slate-600" for="cf-doble-motivo">Motivo (queda en el historial)</label>
            <textarea
              id="cf-doble-motivo"
              ref="campoMotivo"
              v-model="motivo"
              rows="2"
              :maxlength="MAX"
              class="mt-1 w-full resize-none rounded-xl border border-slate-200 text-sm font-medium text-slate-700 px-3 py-2"
              data-campo="motivo"
            />
            <p class="mt-1 text-[11px] font-semibold" :class="motivoOk || motivo.length === 0 ? 'text-slate-400' : 'text-red-600'">Entre {{ MIN }} y {{ MAX }} caracteres.</p>
            <label class="mt-4 flex items-start gap-2 text-[13px] font-semibold text-slate-600">
              <input v-model="confirmado" type="checkbox" class="mt-0.5 rounded border-slate-300" data-campo="confirmacion" />
              <span>{{ modo === "autorizar" ? "Confirmo que este evento debe admitir una participación por modalidad de la misma persona." : "Confirmo que este evento ya no debe admitir participaciones dobles." }}</span>
            </label>
          </template>

          <div class="mt-6 flex flex-wrap justify-end gap-2">
            <button type="button" class="px-4 py-2 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50" :disabled="enviando" @click="cerrar">Cancelar</button>
            <button
              type="button"
              class="inline-flex items-center gap-1.5 px-4 py-2 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold disabled:opacity-40"
              :disabled="!puedeEnviar"
              data-accion="confirmar-doble"
              @click="enviar"
            >
              <Loader2 v-if="enviando" class="w-4 h-4 animate-spin" />
              {{ modo === "autorizar" ? "Autorizar" : "Revocar" }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </section>
</template>
