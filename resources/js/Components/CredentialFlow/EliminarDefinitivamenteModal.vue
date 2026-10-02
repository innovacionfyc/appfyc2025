<script setup>
import { ref, computed, watch } from "vue";
import { router } from "@inertiajs/vue3";
import { X, CircleAlert, Loader2, TriangleAlert, Info } from "lucide-vue-next";

// «Eliminar definitivamente» de una base o de una plantilla. Muestra primero QUÉ se va a borrar (lo calcula el
// servidor) y exige escribir ELIMINAR. La confirmación real la vuelve a validar el servidor.
const PALABRA = "ELIMINAR";

const props = defineProps({
  show: { type: Boolean, default: false },
  tipo: { type: String, default: "base" }, // base | plantilla
  nombre: { type: String, default: "" },
  resumenUrl: { type: String, default: "" },
  eliminarUrl: { type: String, default: "" },
});
const emit = defineEmits(["close"]);

const cargando = ref(false);
const errorCarga = ref(false);
const resumen = ref(null);
const escrito = ref("");
const enviando = ref(false);

const esBase = computed(() => props.tipo === "base");
const coincide = computed(() => escrito.value === PALABRA);
const bloqueada = computed(() => resumen.value?.bloqueada ?? null);
const puedeEliminar = computed(() => coincide.value && !!resumen.value && !bloqueada.value && !enviando.value && !cargando.value);

const plural = (n, uno, varios) => (n === 1 ? uno : varios);
const espacio = (bytes) => {
  if (!bytes) return "0 MB";
  const mb = bytes / 1048576;
  return mb < 0.1 ? "menos de 0,1 MB" : `${mb.toLocaleString("es-CO", { minimumFractionDigits: 1, maximumFractionDigits: 1 })} MB`;
};

const cargar = async () => {
  cargando.value = true;
  errorCarga.value = false;
  resumen.value = null;
  try {
    const r = await fetch(props.resumenUrl, { headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" }, credentials: "same-origin" });
    if (!r.ok) throw new Error("fallo");
    resumen.value = (await r.json()).resumen;
  } catch {
    errorCarga.value = true;
  } finally {
    cargando.value = false;
  }
};

watch(
  () => props.show,
  (v) => {
    if (!v) return;
    escrito.value = "";
    enviando.value = false;
    cargar();
  }
);

const cerrar = () => !enviando.value && emit("close");

const confirmar = () => {
  if (!puedeEliminar.value) return;
  router.delete(props.eliminarUrl, {
    data: { confirmacion: escrito.value },
    preserveScroll: true,
    onStart: () => (enviando.value = true),
    onFinish: () => {
      enviando.value = false;
      emit("close");
    },
  });
};

const filas = computed(() => {
  const r = resumen.value;
  if (!r) return [];
  if (esBase.value) {
    return [
      { clave: "participantes", etiqueta: plural(r.participantes, "Participante", "Participantes"), valor: r.participantes },
      { clave: "vigentes", etiqueta: plural(r.vigentes, "Certificado vigente", "Certificados vigentes"), valor: r.vigentes },
      { clave: "historicos", etiqueta: plural(r.historicos, "Certificado anterior o revocado", "Certificados anteriores o revocados"), valor: r.historicos },
      { clave: "archivos", etiqueta: plural(r.archivos, "Archivo PDF", "Archivos PDF"), valor: r.archivos },
      { clave: "espacio", etiqueta: "Espacio que se liberará", valor: espacio(r.bytes) },
    ];
  }
  return [
    { clave: "bases", etiqueta: plural(r.bases, "Base relacionada", "Bases relacionadas"), valor: r.bases },
    { clave: "certificados", etiqueta: plural(r.certificados, "Certificado relacionado", "Certificados relacionados"), valor: r.certificados },
    { clave: "espacio", etiqueta: "Espacio que se liberará", valor: espacio(r.bytes) },
  ];
});
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
      <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" @click="cerrar" />
      <div role="dialog" aria-modal="true" aria-labelledby="cf-def-titulo" class="relative z-50 w-full max-w-lg max-h-[92vh] overflow-y-auto bg-white rounded-[2rem] shadow-2xl p-6 sm:p-8" data-modal-eliminar-definitivo>
        <div class="flex items-start justify-between gap-4">
          <div class="flex items-start gap-3 min-w-0">
            <div class="w-11 h-11 shrink-0 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center"><TriangleAlert class="w-5 h-5" /></div>
            <div class="min-w-0">
              <h3 id="cf-def-titulo" class="text-lg font-black text-slate-900">{{ esBase ? "Eliminar esta base definitivamente" : "Eliminar esta plantilla definitivamente" }}</h3>
              <p class="text-xs font-semibold text-slate-400 mt-0.5 break-words" data-nombre>{{ nombre }}</p>
            </div>
          </div>
          <button type="button" class="w-9 h-9 shrink-0 flex items-center justify-center rounded-2xl text-slate-400 hover:text-slate-700 hover:bg-slate-100" aria-label="Cerrar" :disabled="enviando" @click="cerrar">
            <X class="w-5 h-5" />
          </button>
        </div>

        <p v-if="esBase" class="mt-4 text-[13px] font-medium text-slate-600" data-texto-principal>
          Se eliminarán los participantes, los certificados emitidos, las versiones anteriores y los archivos de esta base. La plantilla no se toca. <strong>Esta acción no se puede deshacer.</strong>
        </p>
        <p v-else class="mt-4 text-[13px] font-medium text-slate-600" data-texto-principal>
          Se eliminarán la plantilla y su archivo de diseño. <strong>Esta acción no se puede deshacer.</strong>
        </p>
        <p v-if="esBase" class="mt-2 flex items-start gap-2 text-[13px] font-semibold text-amber-700" data-recomendacion>
          <Info class="w-4 h-4 mt-0.5 shrink-0" /> Descarga primero los certificados que quieras conservar.
        </p>

        <div v-if="cargando" class="mt-5 flex items-center gap-2 text-[13px] font-semibold text-slate-500" data-cargando><Loader2 class="w-4 h-4 animate-spin" /> Calculando lo que se va a eliminar…</div>
        <div v-else-if="errorCarga" class="mt-5 flex items-start gap-2 px-3 py-2 rounded-xl bg-red-50 text-red-700 text-[13px] font-semibold" role="alert" data-error-carga>
          <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" /> No se pudo calcular lo que se eliminará. Cierra esta ventana e inténtalo de nuevo.
        </div>

        <dl v-else-if="resumen" class="mt-5 rounded-2xl border border-slate-100 divide-y divide-slate-100 text-sm" data-resumen-eliminacion>
          <div v-for="f in filas" :key="f.clave" class="flex items-center justify-between gap-4 px-4 py-2.5">
            <dt class="font-semibold text-slate-500">{{ f.etiqueta }}</dt>
            <dd class="font-black text-slate-900" :data-valor="f.clave">{{ f.valor }}</dd>
          </div>
        </dl>

        <div v-if="bloqueada" class="mt-4 flex items-start gap-2 px-3 py-2 rounded-xl bg-red-50 text-red-700 text-[13px] font-semibold" role="alert" data-bloqueada>
          <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" /> {{ bloqueada }}
        </div>

        <template v-if="resumen && !bloqueada">
          <label class="mt-5 block text-[11px] font-black uppercase tracking-widest text-slate-400" for="cf-def-confirmacion">Para confirmar, escribe {{ PALABRA }}</label>
          <input id="cf-def-confirmacion" v-model="escrito" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" class="mt-1 w-full rounded-2xl border-slate-200 text-sm font-bold tracking-widest focus:ring-rose-300 focus:border-rose-400" :placeholder="PALABRA" data-confirmacion @keyup.enter="confirmar" />
        </template>

        <div class="mt-6 flex justify-end gap-2">
          <button type="button" class="px-4 py-2.5 rounded-2xl bg-slate-100 text-[13px] font-bold text-slate-600 hover:bg-slate-200 disabled:opacity-50" :disabled="enviando" data-accion="cancelar-eliminacion" @click="cerrar">{{ bloqueada ? "Entendido" : "Cancelar" }}</button>
          <button v-if="!bloqueada" type="button" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-rose-600 text-white text-[13px] font-bold hover:bg-rose-700 disabled:opacity-40 disabled:cursor-not-allowed" :disabled="!puedeEliminar" data-accion="confirmar-eliminacion" @click="confirmar">
            <Loader2 v-if="enviando" class="w-4 h-4 animate-spin" />
            {{ enviando ? "Eliminando…" : "Eliminar definitivamente" }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
