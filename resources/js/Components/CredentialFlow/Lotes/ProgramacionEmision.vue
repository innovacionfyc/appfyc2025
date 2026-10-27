<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from "vue";
import { CalendarClock, X, CircleAlert, Loader2, TriangleAlert, Activity, RefreshCw, CircleCheck, Ban } from "lucide-vue-next";
import { useEmisiones } from "@/Composables/CredentialFlow/useEmisiones";

// Emisión programada de una base: tarjeta de estado, programar (con resumen y confirmación explícita), reprogramar, reconfirmar y cancelar.
// Todas las reglas las decide el servidor (ServicioProgramacion); aquí solo se muestran y se piden. Un solo candado por componente evita el doble clic,
// y la versión de la programación evita pisar el cambio de otra persona.
const props = defineProps({
  loteId: { type: Number, required: true },
  programacion: { type: Object, default: null }, // lote.programacion (null si la función aún no existe en esta instalación)
  faltan: { type: Array, default: () => [] }, // requisitos que la pantalla ya sabe que faltan (el servidor vuelve a validar)
  bloqueado: { type: Boolean, default: false }, // otra acción de la pantalla está en curso
});
const emit = defineEmits(["cambio"]);
const ocupada = defineModel("ocupada", { type: Boolean, default: false });

const { enCurso, error, ejecutar } = useEmisiones();
watch(enCurso, (v) => (ocupada.value = v !== null));

const activa = computed(() => props.programacion?.activa ?? null);
const ultima = computed(() => props.programacion?.ultima ?? null);
const tarjeta = computed(() => activa.value ?? ultima.value);
const limites = computed(() => props.programacion?.limites ?? {});
const programador = computed(() => props.programacion?.programador ?? null);
const puedeGestionar = computed(() => !!props.programacion?.puede_gestionar);
const habilitada = computed(() => !!props.programacion?.habilitada);
const puedeProgramar = computed(() => habilitada.value && puedeGestionar.value && !activa.value);

const TONO = {
  info: "bg-sky-50 text-sky-800 border-sky-100",
  aviso: "bg-amber-50 text-amber-800 border-amber-100",
  exito: "bg-emerald-50 text-emerald-800 border-emerald-100",
  error: "bg-rose-50 text-rose-800 border-rose-100",
  neutro: "bg-slate-50 text-slate-600 border-slate-200",
};
const PROGRAMADOR = {
  activo: "bg-emerald-50 text-emerald-700 border-emerald-100",
  sin_senal: "bg-amber-50 text-amber-800 border-amber-100",
  detenido: "bg-amber-50 text-amber-800 border-amber-100",
  desactivado: "bg-slate-100 text-slate-600 border-slate-200",
};
const plural = (n, uno, varios) => `${n} ${n === 1 ? uno : varios}`;
const previstos = (p) => [p.oficiales_esperados > 0 ? plural(p.oficiales_esperados, "certificado oficial", "certificados oficiales") : null, p.qa_esperados > 0 ? plural(p.qa_esperados, "prueba interna (QA)", "pruebas internas (QA)") : null].filter(Boolean).join(" + ") || "Ninguno";

// ── Modal de programar / reprogramar: elegir la hora → ver el resumen → confirmar ──
const modal = ref({ abierto: false, modo: "programar", paso: "fecha" }); // modo: programar | reprogramar | reconfirmar | cancelar
const fecha = ref("");
const previa = ref(null);
const confirmo = ref(false);
const motivo = ref("");
const campoFecha = ref(null);
const campoMotivo = ref(null);
const dialogo = ref(null);

const abrir = async (modo) => {
  error.value = null;
  previa.value = null;
  confirmo.value = false;
  motivo.value = "";
  fecha.value = modo === "reprogramar" ? activa.value?.programada_para ?? "" : "";
  modal.value = { abierto: true, modo, paso: modo === "programar" || modo === "reprogramar" ? "fecha" : "confirmar" };
  await nextTick();
  const campo = modo === "cancelar" ? campoMotivo.value : modo === "reconfirmar" ? null : campoFecha.value;
  (campo ?? dialogo.value)?.focus();
};
const cerrar = () => {
  if (enCurso.value !== null) return;
  modal.value.abierto = false;
};
const alTeclear = (e) => {
  if (e.key === "Escape") cerrar();
};

const fechaValida = computed(() => /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(fecha.value));
const revisar = async () => {
  if (!fechaValida.value) {
    error.value = { code: "FECHA_INVALIDA", message: "Elige la fecha y la hora." };
    return;
  }
  const r = await ejecutar("previa", route("credential-flow.lotes.programacion.previa", props.loteId), { programada_para: fecha.value });
  if (r) {
    previa.value = r.previa;
    confirmo.value = false;
    modal.value.paso = "resumen";
    await nextTick();
    dialogo.value?.focus();
  }
};
const volverAFecha = async () => {
  error.value = null;
  modal.value.paso = "fecha";
  await nextTick();
  campoFecha.value?.focus();
};

const confirmar = async () => {
  if (enCurso.value !== null) return;
  const { modo } = modal.value;
  let r = null;
  if (modo === "programar") {
    if (!confirmo.value) return;
    r = await ejecutar("programar", route("credential-flow.lotes.programacion.store", props.loteId), { programada_para: fecha.value, sello: previa.value.sello });
  } else if (modo === "reprogramar") {
    if (!confirmo.value) return;
    r = await ejecutar("reprogramar", route("credential-flow.lotes.programacion.reprogramar", [props.loteId, activa.value.id]), { programada_para: fecha.value, version: activa.value.version, sello: previa.value.sello });
  } else if (modo === "reconfirmar") {
    if (!confirmo.value) return;
    r = await ejecutar("reconfirmar", route("credential-flow.lotes.programacion.reconfirmar", [props.loteId, activa.value.id]), { version: activa.value.version, sello: activa.value.sello });
  } else if (modo === "cancelar") {
    if (!motivoValido.value) return;
    r = await ejecutar("cancelar", route("credential-flow.lotes.programacion.cancelar", [props.loteId, activa.value.id]), { motivo: motivo.value.trim(), version: activa.value.version });
  }
  if (r) {
    modal.value.abierto = false;
    const nueva = r.programacion?.activa;
    emit("cambio", {
      programar: nueva ? `Emisión programada para el ${nueva.programada_para_texto} (hora de Colombia).` : null,
      reprogramar: nueva ? `Emisión reprogramada para el ${nueva.programada_para_texto} (hora de Colombia).` : null,
      reconfirmar: nueva ? `Programación confirmada de nuevo: se emitirá el ${nueva.programada_para_texto} (hora de Colombia).` : null,
      cancelar: "La emisión programada se canceló. No se emitirá nada automáticamente.",
    }[modo]);
  } else if (["CAMBIO_CONCURRENTE", "NO_MODIFICABLE", "YA_EXISTE_ACTIVA", "RESUMEN_DESACTUALIZADO"].includes(error.value?.code)) {
    // Otra persona (o el programador) cambió la programación: se recarga para mostrar lo vigente; el error queda a la vista.
    emit("cambio", null);
  }
};

const largoMotivo = computed(() => motivo.value.trim().length);
const motivoValido = computed(() => largoMotivo.value >= 5 && largoMotivo.value <= 255);

const titulos = {
  programar: "Programar emisión",
  reprogramar: "Reprogramar emisión",
  reconfirmar: "Revisar y confirmar la programación",
  cancelar: "Cancelar la emisión programada",
};

// Mientras el programador está emitiendo, la pantalla se actualiza sola cada 20 s (sin animaciones) para mostrar el resultado.
let temporizador = null;
watch(
  () => activa.value?.estado,
  (estado) => {
    clearInterval(temporizador);
    temporizador = estado === "ejecutando" ? setInterval(() => !modal.value.abierto && emit("cambio", null), 20000) : null;
  },
  { immediate: true },
);
onBeforeUnmount(() => clearInterval(temporizador));
</script>

<template>
  <section v-if="!tarjeta && puedeProgramar" class="mt-4 bg-white rounded-[2rem] border border-slate-100 shadow-sm px-5 py-4 sm:px-6 flex flex-wrap items-center justify-between gap-3" data-programacion-ofrecer>
    <div class="flex items-start gap-3 min-w-[min(100%,18rem)] flex-1">
      <div class="w-10 h-10 shrink-0 rounded-2xl bg-primary-vinotinto/10 text-primary-vinotinto flex items-center justify-center"><CalendarClock class="w-5 h-5" /></div>
      <div class="min-w-0">
        <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">Emisión programada</p>
        <p class="text-[13px] font-semibold text-slate-600">¿Prefieres que se emitan solos? Elige el día y la hora (hora de Colombia) y los certificados pendientes se emitirán automáticamente.</p>
      </div>
    </div>
    <button type="button" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-white border border-primary-vinotinto/30 text-sm font-bold text-primary-vinotinto hover:bg-primary-vinotinto/5 transition-all disabled:opacity-50" :disabled="bloqueado || enCurso !== null" data-accion="programar-emision" @click="abrir('programar')">
      <CalendarClock class="w-4 h-4" /> Programar emisión
    </button>
  </section>

  <section v-if="tarjeta" class="mt-8 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-5 sm:p-6 space-y-4" data-programacion :data-estado="tarjeta.estado" :data-activa="activa ? '' : undefined">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex items-start gap-3 min-w-[min(100%,16rem)] flex-1">
        <div class="w-10 h-10 shrink-0 rounded-2xl bg-primary-vinotinto/10 text-primary-vinotinto flex items-center justify-center"><CalendarClock class="w-5 h-5" /></div>
        <div class="min-w-0">
          <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">{{ activa ? "Emisión programada" : "Última emisión programada" }}</p>
          <p class="text-base sm:text-lg font-extrabold text-slate-900 break-words" data-programacion-cuando>
            {{ activa ? "Se emitirá el" : "Programada para el" }} {{ tarjeta.programada_para_texto }} <span class="font-semibold text-slate-500">(hora de Colombia)</span>
          </p>
        </div>
      </div>
      <span class="inline-flex items-center px-3 py-1 rounded-full border text-[12px] font-black" :class="TONO[tarjeta.situacion.tono]" data-programacion-estado>{{ tarjeta.situacion.titulo }}</span>
    </div>

    <p class="text-[13px] font-medium text-slate-600">{{ tarjeta.situacion.texto }}</p>

    <dl class="grid gap-x-8 gap-y-3 sm:grid-cols-2 text-sm">
      <div>
        <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Certificados previstos</dt>
        <dd class="font-bold text-slate-700" data-programacion-previstos>{{ previstos(tarjeta) }}</dd>
      </div>
      <div v-if="tarjeta.programada_por">
        <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Programada por</dt>
        <dd class="font-bold text-slate-700 break-words">{{ tarjeta.programada_por }}<span v-if="tarjeta.confirmada_por && tarjeta.confirmada_por !== tarjeta.programada_por" class="font-semibold text-slate-500"> · confirmada por {{ tarjeta.confirmada_por }}</span></dd>
      </div>
      <div v-if="tarjeta.plantilla">
        <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Plantilla oficial</dt>
        <dd class="font-bold text-slate-700 break-words">{{ tarjeta.plantilla }}</dd>
      </div>
      <div v-if="tarjeta.variante_qa">
        <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Variante de pruebas (QA)</dt>
        <dd class="font-bold text-slate-700 break-words">{{ tarjeta.variante_qa }}</dd>
      </div>
      <div v-if="['completada', 'fallida'].includes(tarjeta.estado) || tarjeta.parcial" class="sm:col-span-2">
        <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Resultado</dt>
        <dd class="font-bold text-slate-700" data-programacion-resultado>
          {{ plural(tarjeta.oficiales_emitidos, "oficial emitido", "oficiales emitidos") }} · {{ plural(tarjeta.qa_emitidos, "prueba QA emitida", "pruebas QA emitidas") }}<template v-if="tarjeta.terminada_texto"> · terminó el {{ tarjeta.terminada_texto }}</template>
        </dd>
      </div>
      <div v-if="tarjeta.estado === 'cancelada' && tarjeta.motivo_cancelacion" class="sm:col-span-2">
        <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Motivo de la cancelación</dt>
        <dd class="font-semibold text-slate-700 break-words">{{ tarjeta.motivo_cancelacion }}</dd>
      </div>
    </dl>

    <div v-if="tarjeta.error_mensaje && tarjeta.estado !== 'completada'" class="flex items-start gap-2 px-4 py-3 rounded-2xl border text-[13px] font-semibold" :class="tarjeta.estado === 'fallida' ? TONO.error : TONO.aviso" role="status" data-programacion-error :data-codigo="tarjeta.error_codigo">
      <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" />
      <span>{{ tarjeta.error_mensaje }}<template v-if="tarjeta.proximo_intento_texto"> Próximo intento hacia las {{ tarjeta.proximo_intento_texto }}.</template></span>
    </div>

    <div v-if="activa && activa.cambios.length" class="px-4 py-3 rounded-2xl border text-[13px] font-semibold" :class="TONO.aviso" data-programacion-cambios>
      <p class="flex items-start gap-2"><TriangleAlert class="w-4 h-4 mt-0.5 shrink-0" /> Desde que se aprobó cambió:</p>
      <ul class="mt-1 ml-6 list-disc space-y-0.5 font-medium">
        <li v-for="c in activa.cambios" :key="c">{{ c }}</li>
      </ul>
      <p class="mt-2 font-medium">No se emitirá nada con estos cambios sin una nueva confirmación. Revisa la base y confírmala (o reprográmala).</p>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <p v-if="programador" class="inline-flex items-start gap-2 px-3 py-1.5 rounded-2xl border text-[12px] font-semibold max-w-full" :class="PROGRAMADOR[programador.estado]" data-programador :data-estado="programador.estado">
        <Activity class="w-3.5 h-3.5 mt-0.5 shrink-0" /><span class="break-words">{{ programador.texto }}</span>
      </p>
      <div v-if="activa && puedeGestionar && activa.modificable" class="flex flex-wrap gap-2">
        <button v-if="activa.estado === 'requiere_confirmacion' || activa.cambios.length" type="button" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-primary-vinotinto text-white text-[13px] font-bold hover:opacity-90 disabled:opacity-50" :disabled="bloqueado || enCurso !== null || !habilitada || !activa.puede_reconfirmar" :title="!activa.puede_reconfirmar ? 'La hora programada ya pasó o está muy cerca: reprográmala' : ''" data-accion="reconfirmar-programacion" @click="abrir('reconfirmar')">
          <CircleCheck class="w-4 h-4" /> Revisar y confirmar
        </button>
        <button type="button" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-[13px] font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50" :disabled="bloqueado || enCurso !== null || !habilitada" data-accion="reprogramar" @click="abrir('reprogramar')">
          <RefreshCw class="w-4 h-4" /> Reprogramar
        </button>
        <button type="button" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-white border border-rose-200 text-[13px] font-bold text-rose-600 hover:bg-rose-50 disabled:opacity-50" :disabled="bloqueado || enCurso !== null" data-accion="cancelar-programacion" @click="abrir('cancelar')">
          <Ban class="w-4 h-4" /> Cancelar
        </button>
      </div>
      <button v-else-if="!activa && puedeProgramar" type="button" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-white border border-primary-vinotinto/30 text-[13px] font-bold text-primary-vinotinto hover:bg-primary-vinotinto/5 disabled:opacity-50" :disabled="bloqueado || enCurso !== null" data-accion="programar-emision" @click="abrir('programar')">
        <CalendarClock class="w-4 h-4" /> Programar emisión
      </button>
      <button v-else-if="activa?.estado === 'ejecutando'" type="button" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-[13px] font-bold text-slate-700 hover:bg-slate-50" data-accion="actualizar-programacion" @click="emit('cambio', null)">
        <RefreshCw class="w-4 h-4" /> Actualizar estado
      </button>
    </div>
  </section>

  <Teleport to="body">
    <div v-if="modal.abierto" class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4" @keydown="alTeclear">
      <div class="fixed inset-0 bg-slate-900/60" @click="cerrar" />
      <div ref="dialogo" role="dialog" aria-modal="true" aria-labelledby="cf-programacion-titulo" tabindex="-1" class="relative z-50 w-full max-w-lg max-h-[calc(100dvh-1.5rem)] overflow-y-auto bg-white rounded-[2rem] shadow-2xl p-5 sm:p-8 outline-none" data-modal-programacion :data-modo="modal.modo" :data-paso="modal.paso">
        <div class="flex items-start justify-between gap-4">
          <h3 id="cf-programacion-titulo" class="text-lg font-black text-slate-900">{{ titulos[modal.modo] }}</h3>
          <button type="button" class="w-9 h-9 shrink-0 flex items-center justify-center rounded-2xl text-slate-400 hover:text-slate-700 hover:bg-slate-100" aria-label="Cerrar" :disabled="enCurso !== null" @click="cerrar">
            <X class="w-5 h-5" />
          </button>
        </div>

        <!-- Paso 1: fecha y hora -->
        <template v-if="modal.paso === 'fecha'">
          <p class="mt-3 text-[13px] font-medium text-slate-600">
            Los certificados pendientes (oficiales y pruebas internas) se emitirán solos a la hora elegida, con las mismas validaciones de «Emitir certificados».
          </p>
          <ul v-if="faltan.length" class="mt-3 px-4 py-3 rounded-2xl border space-y-1 text-[12px] font-semibold" :class="TONO.aviso" data-programacion-faltan>
            <li class="flex items-start gap-2"><TriangleAlert class="w-4 h-4 shrink-0" /> Antes de programar falta:</li>
            <li v-for="f in faltan" :key="f" class="ml-6">• {{ f }}</li>
          </ul>
          <label for="cf-programacion-fecha" class="mt-4 block text-[11px] font-black uppercase tracking-widest text-slate-400">Fecha y hora</label>
          <input id="cf-programacion-fecha" ref="campoFecha" v-model="fecha" type="datetime-local" step="60" :min="limites.min" :max="limites.max" class="mt-1 w-full rounded-2xl border-slate-200 text-sm font-semibold text-slate-800 focus:ring-primary-vinotinto/30 focus:border-primary-vinotinto" aria-describedby="cf-programacion-ayuda" data-campo="programada-para" @keydown.enter.prevent="revisar" />
          <p id="cf-programacion-ayuda" class="mt-1.5 text-[12px] font-semibold text-slate-500">
            <span class="font-black text-slate-700">{{ limites.zona }}.</span> Mínimo {{ limites.minimo_minutos }} minutos de anticipación y máximo {{ limites.maximo_dias }} días.
          </p>
          <p v-if="programador && programador.estado !== 'activo'" class="mt-3 flex items-start gap-2 px-3 py-2 rounded-xl border text-[12px] font-semibold" :class="PROGRAMADOR[programador.estado]" data-programador-aviso>
            <Activity class="w-3.5 h-3.5 mt-0.5 shrink-0" /> {{ programador.texto }}
          </p>
        </template>

        <!-- Paso 2: resumen antes de confirmar -->
        <template v-else-if="modal.paso === 'resumen' && previa">
          <p class="mt-3 text-[13px] font-medium text-slate-600">Revisa el resumen. No se guarda nada hasta que confirmes.</p>
          <dl class="mt-4 grid gap-3 text-sm rounded-2xl bg-slate-50 border border-slate-100 p-4" data-programacion-resumen>
            <div>
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Fecha y hora</dt>
              <dd class="font-extrabold text-slate-900">{{ previa.programada_para_texto }} <span class="font-semibold text-slate-500">(hora de Colombia)</span></dd>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Oficiales</dt>
                <dd class="font-extrabold text-slate-900" data-resumen-oficiales>{{ previa.oficiales }}</dd>
              </div>
              <div>
                <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Pruebas QA</dt>
                <dd class="font-extrabold text-slate-900" data-resumen-qa>{{ previa.qa }}</dd>
              </div>
            </div>
            <div>
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Plantilla oficial</dt>
              <dd class="font-bold text-slate-700 break-words">{{ previa.plantilla ?? "—" }}</dd>
            </div>
            <div>
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Variante de pruebas (QA)</dt>
              <dd class="font-bold text-slate-700 break-words">{{ previa.variante_qa ?? "—" }}</dd>
            </div>
          </dl>
          <ul v-if="previa.avisos.length" class="mt-3 px-4 py-3 rounded-2xl border space-y-1 text-[12px] font-semibold" :class="TONO.aviso" data-resumen-avisos>
            <li v-for="a in previa.avisos" :key="a" class="flex items-start gap-2"><TriangleAlert class="w-3.5 h-3.5 mt-0.5 shrink-0" /> {{ a }}</li>
          </ul>
          <label class="mt-4 flex items-start gap-3 text-[13px] font-semibold text-slate-700 cursor-pointer">
            <input v-model="confirmo" type="checkbox" class="mt-0.5 rounded border-slate-300 text-primary-vinotinto focus:ring-primary-vinotinto/30" data-campo="confirmo" />
            <span>Revisé la base y el resumen. Autorizo que estos certificados se emitan automáticamente a esa hora.</span>
          </label>
        </template>

        <!-- Reconfirmar -->
        <template v-else-if="modal.modo === 'reconfirmar' && activa">
          <p class="mt-3 text-[13px] font-medium text-slate-600">Se volverá a validar la base tal como está ahora y se guardará como la nueva versión aprobada. La hora no cambia: {{ activa.programada_para_texto }} (hora de Colombia).</p>
          <div v-if="activa.cambios.length" class="mt-3 px-4 py-3 rounded-2xl border text-[12px] font-semibold" :class="TONO.aviso">
            <p>Cambió desde la última aprobación:</p>
            <ul class="mt-1 ml-4 list-disc space-y-0.5 font-medium">
              <li v-for="c in activa.cambios" :key="c">{{ c }}</li>
            </ul>
          </div>
          <p v-if="activa.error_mensaje" class="mt-3 text-[12px] font-semibold text-slate-600">Motivo de la pausa: {{ activa.error_mensaje }}</p>
          <label class="mt-4 flex items-start gap-3 text-[13px] font-semibold text-slate-700 cursor-pointer">
            <input v-model="confirmo" type="checkbox" class="mt-0.5 rounded border-slate-300 text-primary-vinotinto focus:ring-primary-vinotinto/30" data-campo="confirmo" />
            <span>Revisé los cambios y la base. Autorizo que se emita con los datos actuales.</span>
          </label>
        </template>

        <!-- Cancelar -->
        <template v-else-if="modal.modo === 'cancelar'">
          <p class="mt-3 text-[13px] font-medium text-slate-600">La emisión programada no se ejecutará. Los certificados ya emitidos no cambian y podrás emitir a mano o programar de nuevo.</p>
          <label for="cf-programacion-motivo" class="mt-4 block text-[11px] font-black uppercase tracking-widest text-slate-400">Motivo (obligatorio)</label>
          <textarea id="cf-programacion-motivo" ref="campoMotivo" v-model="motivo" rows="3" maxlength="255" class="mt-1 w-full rounded-2xl border-slate-200 text-sm font-medium focus:ring-primary-vinotinto/30 focus:border-primary-vinotinto" placeholder="Cuéntanos el motivo (mínimo 5 caracteres)" data-campo="motivo-cancelacion" />
          <p class="text-right text-[11px] font-bold" :class="motivoValido || largoMotivo === 0 ? 'text-slate-400' : 'text-rose-500'">{{ largoMotivo }}/255</p>
        </template>

        <div v-if="error" class="mt-4 flex items-start gap-2 px-3 py-2 rounded-xl bg-red-50 text-red-700 text-[13px] font-semibold" role="alert" data-programacion-error-modal :data-codigo="error.code">
          <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" />
          <div class="min-w-0">
            <p class="break-words">{{ error.message }}</p>
            <ul v-if="error.detalles?.length" class="mt-1 ml-4 list-disc space-y-0.5 text-[12px] font-medium max-h-40 overflow-y-auto">
              <li v-for="d in error.detalles" :key="d.participante_id ?? d.mensaje"><strong v-if="d.nombre">{{ d.nombre }}</strong><template v-if="d.nombre">: </template>{{ d.mensaje }}</li>
            </ul>
          </div>
        </div>

        <div class="mt-6 flex flex-wrap justify-end gap-2">
          <button v-if="modal.paso === 'resumen'" type="button" class="px-4 py-2.5 rounded-2xl bg-slate-100 text-[13px] font-bold text-slate-600 hover:bg-slate-200 disabled:opacity-50" :disabled="enCurso !== null" @click="volverAFecha">Cambiar la hora</button>
          <button v-else type="button" class="px-4 py-2.5 rounded-2xl bg-slate-100 text-[13px] font-bold text-slate-600 hover:bg-slate-200 disabled:opacity-50" :disabled="enCurso !== null" @click="cerrar">{{ modal.modo === "cancelar" ? "Volver" : "Cancelar" }}</button>

          <button v-if="modal.paso === 'fecha'" type="button" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-[13px] font-bold hover:opacity-90 disabled:opacity-50" :disabled="enCurso !== null || !fechaValida" data-accion="revisar-programacion" @click="revisar">
            <Loader2 v-if="enCurso === 'previa'" class="w-4 h-4 animate-spin" />
            {{ enCurso === "previa" ? "Revisando…" : "Ver resumen" }}
          </button>
          <button v-else-if="modal.modo === 'cancelar'" type="button" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-rose-600 text-white text-[13px] font-bold hover:bg-rose-700 disabled:opacity-50" :disabled="enCurso !== null || !motivoValido" data-accion="confirmar-programacion" @click="confirmar">
            <Loader2 v-if="enCurso !== null" class="w-4 h-4 animate-spin" />
            {{ enCurso !== null ? "Cancelando…" : "Cancelar la programación" }}
          </button>
          <button v-else type="button" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-[13px] font-bold hover:opacity-90 disabled:opacity-50" :disabled="enCurso !== null || !confirmo" data-accion="confirmar-programacion" @click="confirmar">
            <Loader2 v-if="enCurso !== null" class="w-4 h-4 animate-spin" />
            {{ enCurso !== null ? "Guardando…" : modal.modo === "reconfirmar" ? "Confirmar programación" : modal.modo === "reprogramar" ? "Reprogramar" : "Programar emisión" }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
