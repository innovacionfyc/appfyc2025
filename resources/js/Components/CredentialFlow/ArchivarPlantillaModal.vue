<script setup>
import { ref, computed, watch, nextTick } from "vue";
import { router } from "@inertiajs/vue3";
import { X, CircleAlert, Loader2, Archive, ArchiveRestore, Info } from "lucide-vue-next";

// Archivar (o restaurar) una plantilla. Archivar muestra primero QUÉ depende de ella (lo calcula el servidor), exige motivo y confirmación y permite indicar
// una plantilla sucesora. Nada se borra: diseño, PDF base, bases y certificados se conservan. El servidor vuelve a validar todo (rol super-admin incluido).
const MOTIVO_MIN = 10;
const MOTIVO_MAX = 500;

const props = defineProps({
  show: { type: Boolean, default: false },
  modo: { type: String, default: "archivar" }, // archivar | restaurar
  plantilla: { type: Object, default: null }, // { id, nombre }
});
const emit = defineEmits(["close"]);

const cargando = ref(false);
const errorCarga = ref(false);
const resumen = ref(null);
const motivo = ref("");
const sucesoraId = ref("");
const confirmado = ref(false);
const enviando = ref(false);

const esArchivar = computed(() => props.modo === "archivar");
const bloqueos = computed(() => resumen.value?.bloqueos ?? []);
const motivoOk = computed(() => motivo.value.trim().length >= MOTIVO_MIN && motivo.value.trim().length <= MOTIVO_MAX);
const puedeEnviar = computed(
  () => !!props.plantilla && motivoOk.value && confirmado.value && !enviando.value && (!esArchivar.value || (!!resumen.value && bloqueos.value.length === 0 && !cargando.value))
);

const cargar = async () => {
  cargando.value = true;
  errorCarga.value = false;
  resumen.value = null;
  try {
    const r = await fetch(route("credential-flow.plantillas.archivo.resumen", props.plantilla.id), {
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
      credentials: "same-origin",
    });
    if (!r.ok) throw new Error("fallo");
    resumen.value = await r.json();
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
    motivo.value = "";
    sucesoraId.value = "";
    confirmado.value = false;
    enviando.value = false;
    if (esArchivar.value && props.plantilla) cargar();
  }
);

const cerrar = () => !enviando.value && emit("close");

// El motivo crece con el texto (hasta 500 caracteres) para que se lea completo; la ventana es la que se desplaza en pantallas pequeñas.
const campoMotivo = ref(null);
const ajustarMotivo = () => {
  const el = campoMotivo.value;
  if (!el) return;
  el.style.height = "auto";
  el.style.height = `${el.scrollHeight}px`;
};
watch(motivo, () => nextTick(ajustarMotivo));

const enviar = () => {
  if (!puedeEnviar.value) return;
  const datos = { motivo: motivo.value.trim(), confirmacion: true };
  if (esArchivar.value && sucesoraId.value) datos.sucesora_id = Number(sucesoraId.value);
  router.post(route(esArchivar.value ? "credential-flow.plantillas.archivar" : "credential-flow.plantillas.restaurar", props.plantilla.id), datos, {
    preserveScroll: true,
    onStart: () => (enviando.value = true),
    onFinish: () => {
      enviando.value = false;
      emit("close");
    },
  });
};
</script>

<template>
  <Teleport to="body">
    <!-- Responsiva: en el móvil ocupa el ancho disponible (con 12 px de margen) y su alto se limita a la pantalla visible (dvh); el contenido se desplaza
         en vertical dentro de la ventana y nunca en horizontal. z-[110]: por encima del botón flotante del menú móvil (z-[100]) para que no tape el contenido. -->
    <div v-if="show && plantilla" class="fixed inset-0 z-[110] flex items-center justify-center p-3 sm:p-4" @keydown.esc="cerrar">
      <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" @click="cerrar" />
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="cf-arch-titulo"
        class="relative z-50 w-full max-w-lg max-h-[calc(100dvh-1.5rem)] sm:max-h-[92vh] overflow-y-auto overflow-x-hidden overscroll-contain bg-white rounded-[1.5rem] sm:rounded-[2rem] shadow-2xl p-5 sm:p-8 break-words [overflow-wrap:anywhere]"
        data-modal-archivo-plantilla
      >
        <div class="flex items-start justify-between gap-4">
          <div class="flex items-start gap-3 min-w-0">
            <div class="w-11 h-11 shrink-0 rounded-2xl bg-amber-50 text-amber-700 flex items-center justify-center">
              <Archive v-if="esArchivar" class="w-5 h-5" />
              <ArchiveRestore v-else class="w-5 h-5" />
            </div>
            <div class="min-w-0">
              <h3 id="cf-arch-titulo" class="text-lg font-black text-slate-900">{{ esArchivar ? "Archivar esta plantilla" : "Restaurar esta plantilla" }}</h3>
              <p class="text-xs font-semibold text-slate-400 mt-0.5 break-words" data-nombre>#{{ plantilla.id }} · {{ plantilla.nombre }}</p>
            </div>
          </div>
          <button type="button" class="w-9 h-9 shrink-0 flex items-center justify-center rounded-2xl text-slate-400 hover:text-slate-700 hover:bg-slate-100" aria-label="Cerrar" :disabled="enviando" @click="cerrar">
            <X class="w-5 h-5" />
          </button>
        </div>

        <p v-if="esArchivar" class="mt-4 text-[13px] font-medium text-slate-600" data-texto-principal>
          La plantilla dejará de ofrecerse para bases nuevas y no generará certificados nuevos ni reemisiones.
          <strong>No se borra nada:</strong> su diseño, su fondo, sus bases y los certificados ya emitidos se conservan. Se puede restaurar.
        </p>
        <p v-else class="mt-4 text-[13px] font-medium text-slate-600" data-texto-principal>
          La plantilla volverá a ofrecerse para bases nuevas y a generar certificados. Si su variante de pruebas internas se archivó con ella, también se restaurará.
        </p>

        <template v-if="esArchivar">
          <div v-if="cargando" class="mt-5 flex items-center gap-2 text-[13px] font-semibold text-slate-500" data-cargando><Loader2 class="w-4 h-4 animate-spin" /> Revisando qué depende de esta plantilla…</div>
          <div v-else-if="errorCarga" class="mt-5 flex items-start gap-2 px-3 py-2 rounded-xl bg-red-50 text-red-700 text-[13px] font-semibold" role="alert" data-error-carga>
            <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" /> No se pudo revisar la plantilla. Cierra esta ventana e inténtalo de nuevo.
          </div>
          <template v-else-if="resumen">
            <dl class="mt-5 rounded-2xl border border-slate-100 divide-y divide-slate-100 text-sm" data-resumen-archivo>
              <div class="flex items-center justify-between gap-3 px-3 sm:px-4 py-2.5">
                <dt class="min-w-0 font-semibold text-slate-500">Bases que la usan</dt>
                <dd class="shrink-0 font-black text-slate-900" data-valor="bases">{{ resumen.bases?.total ?? 0 }}</dd>
              </div>
              <div class="flex items-center justify-between gap-3 px-3 sm:px-4 py-2.5">
                <dt class="min-w-0 font-semibold text-slate-500">Certificados emitidos con ella (se conservan)</dt>
                <dd class="shrink-0 font-black text-slate-900" data-valor="emisiones">{{ resumen.emisiones?.total ?? 0 }}</dd>
              </div>
              <div v-if="resumen.variante" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-3 sm:px-4 py-2.5">
                <dt class="min-w-0 font-semibold text-slate-500">Variante de pruebas internas #{{ resumen.variante.id }}</dt>
                <dd class="font-black text-slate-900 text-right" data-valor="variante">{{ resumen.variante.se_archiva_con_ella ? "Se archiva con ella" : resumen.variante.archivada ? "Ya archivada" : "No se archiva (compartida)" }}</dd>
              </div>
            </dl>

            <ul v-if="bloqueos.length" class="mt-4 space-y-2" data-bloqueos>
              <li v-for="b in bloqueos" :key="b.code" class="flex items-start gap-2 px-3 py-2 rounded-xl bg-red-50 text-red-700 text-[13px] font-semibold" role="alert" :data-bloqueo="b.code">
                <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" /> {{ b.message }}
              </li>
            </ul>
            <ul v-if="resumen.avisos?.length" class="mt-3 space-y-1.5" data-avisos>
              <li v-for="(a, i) in resumen.avisos" :key="i" class="flex items-start gap-2 text-[12px] font-semibold text-amber-700">
                <Info class="w-4 h-4 mt-0.5 shrink-0" /> {{ a }}
              </li>
            </ul>

            <template v-if="!bloqueos.length">
              <label class="mt-5 block text-[11px] font-black uppercase tracking-widest text-slate-400" for="cf-arch-sucesora">Plantilla que la reemplaza (opcional)</label>
              <select id="cf-arch-sucesora" v-model="sucesoraId" class="mt-1 block w-full min-w-0 max-w-full truncate rounded-2xl border-slate-200 text-sm font-semibold" data-sucesora>
                <option value="">Sin indicar</option>
                <option v-for="s in resumen.sucesoras_posibles" :key="s.id" :value="s.id">#{{ s.id }} · {{ s.nombre }}</option>
              </select>
            </template>
          </template>
        </template>

        <template v-if="!esArchivar || (resumen && !bloqueos.length)">
          <label class="mt-5 block text-[11px] font-black uppercase tracking-widest text-slate-400" for="cf-arch-motivo">Motivo (obligatorio)</label>
          <textarea
            id="cf-arch-motivo"
            ref="campoMotivo"
            v-model="motivo"
            rows="3"
            :maxlength="MOTIVO_MAX"
            class="mt-1 block w-full min-h-[5.5rem] resize-none overflow-hidden rounded-2xl border-slate-200 text-sm font-medium"
            :placeholder="esArchivar ? 'Por ejemplo: fondo con errata reemplazado por la versión corregida' : 'Por qué vuelve a estar disponible'"
            data-motivo
          />
          <p class="mt-1 text-[11px] font-semibold" :class="motivoOk || !motivo ? 'text-slate-400' : 'text-red-600'" data-contador-motivo>
            Entre {{ MOTIVO_MIN }} y {{ MOTIVO_MAX }} caracteres ({{ motivo.trim().length }}/{{ MOTIVO_MAX }}) · queda en el registro de movimientos.
          </p>
          <label class="mt-4 flex items-start gap-2 text-[13px] font-semibold text-slate-700">
            <input v-model="confirmado" type="checkbox" class="mt-0.5 shrink-0 rounded border-slate-300" data-confirmacion />
            <span class="min-w-0">{{ esArchivar ? "Confirmo que quiero archivar esta plantilla. Entiendo que sus bases no podrán emitir con ella hasta cambiar a una plantilla vigente." : "Confirmo que quiero restaurar esta plantilla." }}</span>
          </label>
        </template>

        <!-- En el móvil los botones van a todo el ancho y apilados (la acción principal arriba); desde sm, en fila a la derecha. -->
        <div class="mt-6 flex flex-col-reverse sm:flex-row sm:justify-end gap-2" data-botones-archivo>
          <button type="button" class="w-full sm:w-auto px-4 py-2.5 rounded-2xl bg-slate-100 text-[13px] font-bold text-slate-600 hover:bg-slate-200 disabled:opacity-50" :disabled="enviando" data-accion="cancelar-archivo" @click="cerrar">{{ esArchivar && bloqueos.length ? "Entendido" : "Cancelar" }}</button>
          <button v-if="!esArchivar || !bloqueos.length" type="button" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-2xl bg-amber-600 text-white text-[13px] font-bold hover:bg-amber-700 disabled:opacity-40 disabled:cursor-not-allowed" :disabled="!puedeEnviar" data-accion="confirmar-archivo" @click="enviar">
            <Loader2 v-if="enviando" class="w-4 h-4 animate-spin" />
            {{ enviando ? (esArchivar ? "Archivando…" : "Restaurando…") : esArchivar ? "Archivar plantilla" : "Restaurar plantilla" }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
