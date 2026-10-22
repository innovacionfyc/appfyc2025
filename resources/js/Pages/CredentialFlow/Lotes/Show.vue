<script setup>
import { ref, computed, watch, onBeforeUnmount } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import ConfirmacionesPop from "@/Components/Modales/Confirmaciones/ConfirmacionesPop.vue";
import ParticipanteModal from "@/Components/CredentialFlow/Lotes/ParticipanteModal.vue";
import LoteEditModal from "@/Components/CredentialFlow/Lotes/LoteEditModal.vue";
import MotivoEmisionModal from "@/Components/CredentialFlow/Lotes/MotivoEmisionModal.vue";
import HistorialEmisiones from "@/Components/CredentialFlow/Lotes/HistorialEmisiones.vue";
import EliminarDefinitivamenteModal from "@/Components/CredentialFlow/EliminarDefinitivamenteModal.vue";
import { useConfirmationModal } from "@/Composables/useConfirmationModal";
import { useDescargaPdf } from "@/Composables/CredentialFlow/useDescargaPdf";
import { useEmisiones } from "@/Composables/CredentialFlow/useEmisiones";
import {
  ArrowLeft, Search, UserPlus, SquarePen, Trash2, FileDown, Loader2, CircleAlert, ChevronLeft, ChevronRight, LayoutTemplate, FileSpreadsheet, CalendarDays, BadgeCheck, RefreshCw, Ban, Archive, CircleCheck, FlaskConical, Mail,
} from "lucide-vue-next";

const props = defineProps({
  lote: { type: Object, required: true },
  participantes: { type: Object, required: true }, // paginador de Laravel
  filtros: { type: Object, default: () => ({ q: "" }) },
});

const { confirmationState, openConfirmationModal, closeConfirmationModal, handleConfirm } = useConfirmationModal();
const { generando, error: errorPdf, descargar } = useDescargaPdf();

const { enCurso, error: errorEmision, ejecutar } = useEmisiones();

const procesando = ref(false);
const versionHistorial = ref(0); // se incrementa para recargar el historial
const avisoExito = ref(null);
const motivoModal = ref({ abierto: false, modo: "revocar", participante: null });
const modalParticipante = ref({ abierto: false, participante: null });
const modalLote = ref(false);
const modalDefinitivo = ref(false);

// «Participantes» = los reales; los contactos de control (QA) van aparte y no cuentan como asistentes ni como pendientes.
const headerStats = computed(() => [
  { label: "Participantes", value: props.lote.emision.participantes, icon: "groups", color: "text-primary-vinotinto", bg: "bg-primary-vinotinto/10" },
  ...(props.lote.emision.en_validacion ? [{ label: "Por validar", value: props.lote.emision.en_validacion, icon: "pending", color: "text-amber-600", bg: "bg-amber-50" }] : []),
  ...(props.lote.emision.no_elegibles ? [{ label: "No elegibles", value: props.lote.emision.no_elegibles, icon: "block", color: "text-slate-500", bg: "bg-slate-100" }] : []),
  ...(props.lote.emision.qa ? [{ label: etiquetaQa(props.lote.emision.qa), value: contactosQa(props.lote.emision.qa), icon: "science", color: "text-violet-600", bg: "bg-violet-50" }] : []),
]);

const plural = (n, uno, varios) => (n === 1 ? uno : varios);

// Contactos QA: personas DISTINTAS. Con modalidades por persona, cada contacto tiene una prueba interna por modalidad (8 contactos · 16 pruebas).
function contactosQa(qa) {
  return qa?.contactos ?? qa?.participantes ?? 0;
}
function etiquetaQa(qa) {
  const pruebas = qa?.participantes ?? 0;
  return pruebas > contactosQa(qa) ? `Contactos QA · ${pruebas} pruebas` : "Contactos QA";
}

// En la tabla, las pruebas QA del MISMO contacto (una por modalidad) van juntas: la primera fila muestra a la persona y las siguientes solo su otra prueba.
const sigueContactoQa = (i) => {
  const p = props.participantes.data[i];
  const antes = props.participantes.data[i - 1];
  return !!(p?.es_control_qa && antes?.es_control_qa && antes.documento === p.documento);
};
const pruebasDelContacto = (i) => {
  const p = props.participantes.data[i];
  if (!p?.es_control_qa) return 0;
  let n = 1;
  while (props.participantes.data[i + n]?.es_control_qa && props.participantes.data[i + n].documento === p.documento) n++;
  return n;
};

const formatFecha = (iso) => {
  if (!iso) return "—";
  const d = new Date(iso);
  if (isNaN(d.getTime())) return "—";
  return new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "short", year: "numeric", hour: "numeric", minute: "2-digit", timeZone: "America/Bogota" }).format(d);
};

// ── Búsqueda en el servidor con debounce ──────────────────────────────────────
const q = ref(props.filtros.q ?? "");
let temporizador = null;
watch(q, (valor) => {
  clearTimeout(temporizador);
  temporizador = setTimeout(() => {
    if ((valor ?? "") === (props.filtros.q ?? "")) return;
    router.get(route("credential-flow.lotes.show", props.lote.id), valor ? { q: valor } : {}, { preserveState: true, preserveScroll: true, replace: true, only: ["participantes", "filtros"] });
  }, 350);
});
onBeforeUnmount(() => clearTimeout(temporizador));

const irA = (url) => url && router.get(url, {}, { preserveState: true, preserveScroll: true, only: ["participantes", "filtros"] });

// ── Acciones ──────────────────────────────────────────────────────────────────
const abrirAlta = () => (modalParticipante.value = { abierto: true, participante: null });
const abrirEdicion = (p) => (modalParticipante.value = { abierto: true, participante: p });

const eliminarParticipante = (p) => {
  openConfirmationModal({
    title: "Eliminar participante",
    icon: "delete",
    confirmText: "Eliminar",
    message: `Se quitará a "${p.nombre_completo}" de la base. Podrás volver a agregarlo con el mismo documento.`,
    onConfirm: () =>
      router.delete(route("credential-flow.participantes.destroy", [props.lote.id, p.id]), {
        preserveScroll: true,
        onStart: () => (procesando.value = true),
        onFinish: () => (procesando.value = false),
      }),
  });
};

const eliminarLote = () => {
  openConfirmationModal({
    title: "Eliminar base",
    icon: "delete",
    confirmText: "Eliminar",
    message: `La base "${props.lote.nombre}" y sus ${props.lote.total} participantes dejarán de mostrarse. La plantilla no se modifica.`,
    onConfirm: () =>
      router.delete(route("credential-flow.lotes.destroy", props.lote.id), {
        onStart: () => (procesando.value = true),
        onFinish: () => (procesando.value = false),
      }),
  });
};

const generarPdf = (p) =>
  descargar(p.id, route("credential-flow.participantes.pdf", [props.lote.id, p.id]), `vista-previa-${p.id}.pdf`);

// ── Emisiones oficiales ───────────────────────────────────────────────────────
const resumen = computed(() => props.lote.emision);
const flujo = computed(() => props.lote.flujo);
// Emitir exige la vista previa confirmada (salvo bases que ya tienen emisiones); el servidor lo vuelve a comprobar.
const vistaLista = computed(() => !flujo.value.vista_previa.exigida || flujo.value.vista_previa.confirmada || flujo.value.vista_previa.exenta_por_emisiones);
const plantillaElegida = ref("");
const recargar = () => {
  versionHistorial.value++;
  router.reload({ only: ["lote", "participantes"], preserveScroll: true });
};
const hayError = computed(() => errorEmision.value ?? errorPdf.value);
const cerrarError = () => {
  errorEmision.value = null;
  errorPdf.value = null;
};

const emitir = async (p) => {
  avisoExito.value = null;
  const r = await ejecutar(`emitir-${p.id}`, route("credential-flow.participantes.emitir", [props.lote.id, p.id]));
  if (r) {
    avisoExito.value = `Certificado emitido (versión ${r.emision.version}).`;
    recargar();
  }
};

// Participante con historial pero sin emisión vigente (todas revocadas): se usa el mismo endpoint de emitir, que crea la
// siguiente versión con los datos actuales. La revocada histórica no se toca.
const volverAEmitir = (p) => {
  openConfirmationModal({
    title: "Volver a emitir",
    icon: "check",
    confirmText: "Volver a emitir",
    message:
      `Se creará una nueva versión del certificado de ${p.nombre_completo} con los datos actuales del participante, ` +
      "de la base y de la plantilla. La versión revocada anterior se conserva en el historial sin cambios.",
    onConfirm: async () => {
      procesando.value = true;
      avisoExito.value = null;
      const r = await ejecutar(`emitir-${p.id}`, route("credential-flow.participantes.emitir", [props.lote.id, p.id]));
      procesando.value = false;
      closeConfirmationModal();
      if (r) {
        avisoExito.value = `Certificado emitido de nuevo (versión ${r.emision.version}).`;
        recargar();
      }
    },
  });
};

// «Emitir certificados»: los oficiales pendientes y, en el mismo flujo, las pruebas internas (QA) pendientes, cada grupo con su plantilla y por separado.
const qaPendientes = computed(() => resumen.value.qa?.pendientes ?? 0);
const puedeEmitir = computed(() => (resumen.value.pendientes > 0 && vistaLista.value) || (resumen.value.pendientes === 0 && qaPendientes.value > 0));
const avisoQa = ref(null);

const emitirPendientes = () => {
  const n = resumen.value.pendientes > 0 ? resumen.value.a_emitir : 0;
  const resto = resumen.value.pendientes - n;
  const nQa = resumen.value.qa?.a_emitir ?? 0;
  const qaLista = !!resumen.value.qa?.plantilla_lista;
  const partes = [];
  if (n > 0) partes.push(`${n === 1 ? "Se emitirá 1 certificado oficial" : `Se emitirán ${n} certificados oficiales`}. Si algo falla, no se emite ninguno.`);
  if (resto > 0) partes.push(`${resto === 1 ? "Quedará 1 pendiente" : `Quedarán ${resto} pendientes`} (se emiten máximo ${resumen.value.limite} por vez).`);
  if (nQa > 0) {
    partes.push(
      qaLista
        ? `${n > 0 ? "Además, aparte, " : nQa === 1 ? "Se emitirá " : "Se emitirán "}${nQa === 1 ? "1 certificado de PRUEBA INTERNA" : `${nQa} certificados de PRUEBA INTERNA`} para los contactos de control, con la plantilla de prueba (sin validez académica; no entran al ZIP).`
        : "Las pruebas internas (QA) no se podrán emitir: falta designar la plantilla de prueba de esta base.",
    );
  }
  partes.push("Los certificados emitidos no cambian aunque luego edites los datos.");
  openConfirmationModal({
    title: "Emitir certificados",
    icon: "check",
    confirmText: n > 0 ? `Emitir ${n}${nQa > 0 && qaLista ? ` + ${nQa} QA` : ""}` : `Emitir ${nQa} QA`,
    message: partes.join(" "),
    onConfirm: async () => {
      procesando.value = true;
      avisoExito.value = null;
      avisoQa.value = null;
      const r = await ejecutar("emitir-lote", route("credential-flow.lotes.emitir", props.lote.id));
      procesando.value = false;
      closeConfirmationModal();
      if (r) {
        const ok = [];
        if (r.resultado) {
          ok.push(
            (r.resultado.total === 1 ? "Se emitió 1 certificado oficial." : `Se emitieron ${r.resultado.total} certificados oficiales.`) +
              (r.resultado.restantes > 0 ? (r.resultado.restantes === 1 ? " Queda 1 pendiente." : ` Quedan ${r.resultado.restantes} pendientes.`) : ""),
          );
        }
        if (r.qa) ok.push(r.qa.total === 1 ? "Se emitió 1 certificado de prueba interna (QA)." : `Se emitieron ${r.qa.total} certificados de prueba interna (QA).`);
        avisoExito.value = ok.join(" ") || null;
        // Las pruebas QA fallaron: los oficiales quedaron emitidos, pero NO se informa un éxito completo.
        if (r.qa_error) avisoQa.value = `Las pruebas internas (QA) no se emitieron: ${r.qa_error.message} Los certificados oficiales sí quedaron emitidos. Puedes reintentar solo las pruebas con «Emitir pruebas internas».`;
        recargar();
      }
    },
  });
};

const emitirPruebasQa = () => {
  const n = resumen.value.qa.a_emitir;
  openConfirmationModal({
    title: "Emitir pruebas internas (QA)",
    icon: "check",
    confirmText: `Emitir ${n}`,
    message: `Se emitirán ${n} ${n === 1 ? "certificado" : "certificados"} de PRUEBA INTERNA, sin validez académica, solo para los contactos de control. Los participantes reales no se tocan.`,
    onConfirm: async () => {
      procesando.value = true;
      avisoExito.value = null;
      const r = await ejecutar("emitir-qa", route("credential-flow.lotes.emitir-qa", props.lote.id));
      procesando.value = false;
      closeConfirmationModal();
      if (r) {
        avisoQa.value = null;
        avisoExito.value = r.resultado.total === 1 ? "Se emitió 1 certificado de prueba interna." : `Se emitieron ${r.resultado.total} certificados de prueba interna.`;
        recargar();
      }
    },
  });
};

const agregarContactosQa = () => router.post(route("credential-flow.lotes.qa.incorporar", props.lote.id), {}, { preserveScroll: true, onSuccess: recargar });

const descargarEmision = (emision) =>
  descargar(`e${emision.id}`, route("credential-flow.emisiones.descargar", emision.id), `credencial-${emision.id}-v${emision.version}.pdf`);

// ── Recorrido: Evento → Importar → Revisar → Plantilla → Vista previa → Emitir → Descargar ──
const primeroParaVistaPrevia = () => props.participantes.data.find((p) => !p.es_control_qa && p.elegibilidad === "elegible") ?? props.participantes.data.find((p) => !p.es_control_qa) ?? null;
const abrirVistaPrevia = async () => {
  const p = primeroParaVistaPrevia();
  if (!p) return;
  await generarPdf(p);
  recargar();
};
const confirmarVistaPrevia = () => router.post(route("credential-flow.lotes.vista-previa.confirmar", props.lote.id), {}, { preserveScroll: true, onSuccess: recargar });
const usarPlantilla = () => plantillaElegida.value && router.put(route("credential-flow.lotes.plantilla", props.lote.id), { plantilla_id: plantillaElegida.value }, { preserveScroll: true, onSuccess: recargar });
const irARevisar = () => document.querySelector("[data-tabla-participantes]")?.scrollIntoView({ behavior: "smooth" });
const ejecutarSiguiente = () => {
  switch (flujo.value.siguiente.accion.tipo) {
    case "agregar":
      return (modalParticipante.value = { abierto: true, participante: null });
    case "abrir_vista_previa":
      return abrirVistaPrevia();
    case "confirmar_vista_previa":
      return confirmarVistaPrevia();
    case "emitir":
      return emitirPendientes();
    case "descargar":
      return descargarZip();
    case "revisar":
      return irARevisar();
  }
};
const ESTADO_PASO = {
  hecho: "bg-emerald-50 text-emerald-700 border-emerald-100",
  actual: "bg-primary-vinotinto text-white border-primary-vinotinto",
  atencion: "bg-amber-50 text-amber-700 border-amber-100",
  pendiente: "bg-white text-slate-400 border-slate-200",
};

const descargarZip = () => descargar("zip", route("credential-flow.lotes.zip", props.lote.id), `credenciales-lote-${props.lote.id}.zip`);

const abrirMotivo = (modo, p) => {
  errorEmision.value = null;
  motivoModal.value = { abierto: true, modo, participante: p };
};
const confirmarMotivo = async (motivo) => {
  const { modo, participante } = motivoModal.value;
  const r = await ejecutar(`${modo}-${participante.id}`, route(`credential-flow.emisiones.${modo}`, participante.emision.id), { motivo });
  if (r) {
    motivoModal.value.abierto = false;
    avisoExito.value = modo === "revocar" ? "Certificado revocado." : `Certificado reemitido (versión ${r.emision.version}).`;
    recargar();
  }
};
</script>

<template>
  <Head :title="`Credential Flow · ${lote.nombre}`" />

  <AuthenticatedLayout>
    <Sidebar>
      <nav class="mb-3 flex flex-wrap items-center gap-2 text-[12px] font-bold text-slate-400" aria-label="Ruta de navegación">
        <Link :href="route('credential-flow.index')" class="hover:text-primary-vinotinto transition-colors">Credential Flow</Link>
        <span>/</span>
        <Link :href="route('credential-flow.lotes.index')" class="hover:text-primary-vinotinto transition-colors">Bases de participantes</Link>
        <span>/</span>
        <span class="text-slate-600">{{ lote.nombre }}</span>
      </nav>

      <DashboardHeader :title="lote.nombre" :subtitle="lote.descripcion || 'Base de participantes'" :stats="headerStats">
        <template #actions>
          <button type="button" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90 transition-all disabled:opacity-50" :disabled="enCurso !== null || !puedeEmitir" :title="resumen.pendientes > 0 && !vistaLista ? 'Antes de emitir, revisa la vista previa y confirma la plantilla' : ''" data-accion="emitir-pendientes" @click="emitirPendientes">
            <Loader2 v-if="enCurso === 'emitir-lote'" class="w-4 h-4 animate-spin" />
            <BadgeCheck v-else class="w-4 h-4" />
            Emitir certificados<span v-if="resumen.pendientes > 0"> · {{ resumen.pendientes }}</span><span v-if="qaPendientes > 0" class="opacity-80"> + {{ qaPendientes }} QA</span>
          </button>
          <button type="button" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 transition-all disabled:opacity-50" :disabled="generando !== null || resumen.vigentes === 0" data-accion="descargar-zip" @click="descargarZip">
            <Loader2 v-if="generando === 'zip'" class="w-4 h-4 animate-spin" />
            <Archive v-else class="w-4 h-4" />
            {{ generando === "zip" ? "Preparando…" : "Descargar todos (ZIP)" }}
          </button>
          <Link :href="route('credential-flow.lotes.index')" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 transition-all">
            <ArrowLeft class="w-4 h-4" />
            Volver a las bases
          </Link>
        </template>
      </DashboardHeader>

      <!-- Recorrido de la base: siguiente paso y lo que falta -->
      <section class="mt-8 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-5 sm:p-6 space-y-4" data-siguiente-paso :data-paso="flujo.siguiente.clave">
        <ol class="flex flex-wrap items-center gap-2" aria-label="Pasos para certificar esta base">
          <li v-for="(paso, i) in flujo.pasos" :key="paso.clave" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full border text-[12px] font-bold" :class="ESTADO_PASO[paso.estado]" :data-paso-estado="paso.estado" :data-paso-clave="paso.clave">
            <span class="opacity-70">{{ i + 1 }}</span> {{ paso.rotulo }}
          </li>
        </ol>

        <div class="flex flex-wrap items-center justify-between gap-4">
          <div class="min-w-[min(100%,18rem)] flex-1">
            <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">Siguiente paso</p>
            <p class="text-lg font-extrabold text-slate-900" data-siguiente-titulo>{{ flujo.siguiente.titulo }}</p>
            <p class="text-[13px] font-medium text-slate-500">{{ flujo.siguiente.detalle }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <Link v-if="flujo.siguiente.accion.tipo === 'enlace'" :href="route(flujo.siguiente.accion.ruta)" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90 transition-all" data-accion="siguiente-paso">{{ flujo.siguiente.titulo }}</Link>
            <template v-else-if="flujo.siguiente.accion.tipo === 'plantilla'">
              <select v-model="plantillaElegida" class="rounded-xl border border-slate-200 bg-white text-sm font-semibold text-slate-700 px-3 py-2" data-campo="plantilla-base">
                <option value="">Elegir plantilla…</option>
                <option v-for="p in flujo.plantillas_disponibles" :key="p.id" :value="p.id">{{ p.nombre }}</option>
              </select>
              <button type="button" :disabled="!plantillaElegida" class="px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold disabled:opacity-40" data-accion="siguiente-paso" @click="usarPlantilla">Usar esta plantilla</button>
            </template>
            <button v-else type="button" :disabled="enCurso !== null || generando !== null" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90 transition-all disabled:opacity-50" data-accion="siguiente-paso" @click="ejecutarSiguiente">{{ flujo.siguiente.titulo }}</button>
          </div>
        </div>

        <ul v-if="flujo.faltan.length" class="rounded-2xl bg-amber-50/70 border border-amber-100 px-4 py-3 space-y-1" data-faltan>
          <li class="text-[11px] font-black uppercase tracking-widest text-amber-700">Para completar el recorrido falta</li>
          <li v-for="f in flujo.faltan" :key="f" class="text-[12px] font-semibold text-amber-800">• {{ f }}</li>
        </ul>

        <div v-if="flujo.plantilla.puede_cambiar && flujo.siguiente.accion.tipo !== 'plantilla' && flujo.plantillas_disponibles.length > 1" class="flex flex-wrap items-center gap-2 text-[12px] font-semibold text-slate-500" data-cambiar-plantilla>
          Plantilla actual: <span class="font-extrabold text-slate-700">{{ flujo.plantilla.nombre }}</span>. Puedes cambiarla mientras la base no tenga certificados emitidos:
          <select v-model="plantillaElegida" class="rounded-xl border border-slate-200 bg-white text-[12px] font-semibold text-slate-700 px-2 py-1">
            <option value="">Elegir otra…</option>
            <option v-for="p in flujo.plantillas_disponibles.filter((x) => x.id !== lote.plantilla?.id)" :key="p.id" :value="p.id">{{ p.nombre }}</option>
          </select>
          <button type="button" :disabled="!plantillaElegida" class="px-3 py-1 rounded-full bg-white border border-slate-200 text-[12px] font-bold text-slate-700 disabled:opacity-40" @click="usarPlantilla">Cambiar</button>
        </div>
      </section>

      <!-- Datos de la base -->
      <section class="mt-8 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6 sm:p-8" data-datos-lote>
        <div class="flex flex-wrap items-start justify-between gap-4">
          <dl class="grid gap-x-10 gap-y-3 sm:grid-cols-2 xl:grid-cols-3 text-sm min-w-[min(100%,18rem)] flex-1">
            <div class="sm:col-span-2 xl:col-span-3">
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Evento</dt>
              <dd class="font-extrabold text-slate-900 break-words" data-dato="evento">{{ lote.datos_comunes.evento }}</dd>
            </div>
            <div>
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Fecha</dt>
              <dd class="font-bold text-slate-700 break-words" data-dato="fecha">{{ lote.datos_comunes.fecha }}</dd>
            </div>
            <div>
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Intensidad horaria</dt>
              <dd class="font-bold text-slate-700" data-dato="intensidad">{{ lote.datos_comunes.intensidad_horaria }}</dd>
            </div>
            <div v-if="lote.datos_comunes.modalidad">
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Modalidad</dt>
              <dd class="font-bold text-slate-700 break-words" data-dato="modalidad">{{ lote.datos_comunes.modalidad }}</dd>
            </div>
            <div v-if="lote.datos_comunes.ciudad">
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Ciudad</dt>
              <dd class="font-bold text-slate-700 break-words" data-dato="ciudad">{{ lote.datos_comunes.ciudad }}</dd>
            </div>
            <div v-if="lote.datos_comunes.texto_certificacion" class="sm:col-span-2 xl:col-span-3">
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Texto de certificación</dt>
              <dd class="font-semibold text-slate-700 whitespace-pre-line break-words" data-dato="texto-certificacion">{{ lote.datos_comunes.texto_certificacion }}</dd>
            </div>
            <div v-if="lote.datos_comunes.texto_complementario" class="sm:col-span-2 xl:col-span-3">
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Texto complementario</dt>
              <dd class="font-semibold text-slate-700 whitespace-pre-line break-words" data-dato="texto-complementario">{{ lote.datos_comunes.texto_complementario }}</dd>
            </div>
            <div>
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Plantilla</dt>
              <dd class="font-bold text-slate-700 inline-flex items-center gap-1.5">
                <LayoutTemplate class="w-3.5 h-3.5 text-slate-400" />
                <Link v-if="lote.plantilla" :href="route('credential-flow.plantillas.editor', lote.plantilla.id)" class="hover:text-primary-vinotinto underline-offset-2 hover:underline">{{ lote.plantilla.nombre }}</Link>
                <span v-else>—</span>
              </dd>
            </div>
            <div v-if="lote.archivo_nombre">
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Archivo cargado</dt>
              <dd class="font-bold text-slate-700 inline-flex items-center gap-1.5 break-all"><FileSpreadsheet class="w-3.5 h-3.5 text-slate-400 shrink-0" />{{ lote.archivo_nombre }}</dd>
            </div>
            <div>
              <dt class="text-[11px] font-black uppercase tracking-widest text-slate-400">Creado</dt>
              <dd class="font-bold text-slate-700 inline-flex items-center gap-1.5"><CalendarDays class="w-3.5 h-3.5 text-slate-400" />{{ formatFecha(lote.created_at) }}</dd>
            </div>
          </dl>

          <div class="flex flex-wrap gap-2">
            <button type="button" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-slate-100 text-[13px] font-bold text-slate-600 hover:bg-slate-200 transition-all" data-accion="editar-lote" @click="modalLote = true">
              <SquarePen class="w-4 h-4" /> Editar base
            </button>
            <button type="button" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-rose-50 text-[13px] font-bold text-rose-500 hover:bg-rose-100 transition-all" data-accion="eliminar-lote" @click="eliminarLote">
              <Trash2 class="w-4 h-4" /> Eliminar base
            </button>
          </div>
        </div>

        <!-- Zona aparte: no es una acción de todos los días -->
        <div class="mt-6 pt-5 border-t border-dashed border-slate-200 flex flex-wrap items-center justify-between gap-x-6 gap-y-3" data-zona-liberar-espacio>
          <p class="text-[12px] font-semibold text-slate-500 max-w-2xl min-w-[min(100%,16rem)] flex-1">
            <span class="font-black text-slate-600">Liberar espacio.</span> Si ya descargaste lo que querías conservar, puedes eliminar esta base con todos sus certificados y archivos. No se puede deshacer.
          </p>
          <button type="button" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl border border-slate-200 bg-white text-[12px] font-bold text-rose-600 hover:bg-rose-50 hover:border-rose-200 transition-all" data-accion="eliminar-definitivamente" @click="modalDefinitivo = true">
            <Trash2 class="w-3.5 h-3.5" /> Eliminar definitivamente
          </button>
        </div>
      </section>

      <!-- Barra de búsqueda y alta -->
      <div class="mt-6 bg-slate-900 p-5 rounded-xl border border-white/5 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="relative w-full md:w-96 group">
          <Search class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600 group-focus-within:text-secondary-vinotinto2 transition-colors" />
          <input v-model="q" type="text" placeholder="Buscar por nombre o documento..." aria-label="Buscar participantes" data-buscar class="w-full pl-11 pr-4 py-3 bg-white/5 border-none rounded-2xl text-white placeholder:text-slate-600 focus:ring-1 focus:ring-secondary-vinotinto2/40 focus:bg-white/10 transition-all text-xs font-medium" />
        </div>
        <button type="button" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90 transition-all" data-accion="agregar-participante" @click="abrirAlta">
          <UserPlus class="w-4 h-4" /> Agregar participante
        </button>
      </div>

      <div v-if="hayError && !motivoModal.abierto" class="mt-4 px-4 py-3 rounded-2xl bg-red-50 text-red-700 text-[13px] font-semibold" role="alert" data-aviso="error-pdf" :data-codigo="hayError.code">
        <div class="flex items-start gap-3">
          <CircleAlert class="w-4 h-4 mt-0.5 shrink-0" />
          <span class="flex-1">{{ hayError.message }}</span>
          <button type="button" class="text-[12px] underline" @click="cerrarError">Cerrar</button>
        </div>
        <ul v-if="hayError.detalles?.length" class="mt-2 ml-7 list-disc space-y-0.5 text-[12px] font-medium max-h-48 overflow-y-auto" data-detalles-error>
          <li v-for="d in hayError.detalles" :key="d.participante_id"><strong>{{ d.nombre }}</strong>: {{ d.mensaje }}</li>
        </ul>
      </div>

      <div v-if="avisoQa" class="mt-4 flex items-start gap-3 px-4 py-3 rounded-2xl bg-amber-50 text-amber-800 text-[13px] font-semibold" role="alert" data-aviso="qa-fallido">
        <span class="flex-1">{{ avisoQa }}</span>
        <button type="button" class="text-[12px] underline" @click="avisoQa = null">Cerrar</button>
      </div>
      <div v-if="avisoExito" class="mt-4 flex items-start gap-3 px-4 py-3 rounded-2xl bg-emerald-50 text-emerald-700 text-[13px] font-semibold" role="status" data-aviso="exito">
        <CircleCheck class="w-4 h-4 mt-0.5 shrink-0" />
        <span class="flex-1">{{ avisoExito }}</span>
        <button type="button" class="text-[12px] underline" @click="avisoExito = null">Cerrar</button>
      </div>

      <!-- Contactos de control (QA): aparte de los participantes reales -->
      <section class="mt-4 rounded-2xl border border-violet-100 bg-violet-50/60 px-4 py-3 flex flex-wrap items-center justify-between gap-3" data-seccion-qa>
        <p class="text-[12px] font-semibold text-violet-800 flex-1 min-w-[min(100%,16rem)]">
          <FlaskConical class="inline w-3.5 h-3.5 mr-1" />
          <template v-if="resumen.qa">
            <span class="font-black" data-qa-resumen>Contactos de control (QA): {{ contactosQa(resumen.qa) }}<template v-if="resumen.qa.participantes > contactosQa(resumen.qa)"> · {{ resumen.qa.participantes }} pruebas internas (una por modalidad)</template>.</span> No cuentan como participantes reales. Sus certificados son de prueba interna, sin validez académica
            ({{ resumen.qa.vigentes }} {{ plural(resumen.qa.vigentes, "emitido", "emitidos") }}, {{ resumen.qa.pendientes }} sin emitir).
            <span v-if="!resumen.qa.plantilla_lista" class="text-amber-700">Falta designar la plantilla de pruebas internas en Plantillas.</span>
          </template>
          <template v-else>Esta base aún no tiene los contactos de control (QA) del equipo.</template>
        </p>
        <div class="flex flex-wrap gap-2">
          <Link :href="route('credential-flow.qa.contactos')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-white border border-violet-200 text-[12px] font-bold text-violet-700 hover:bg-violet-100 transition-all" data-accion="ver-contactos-qa">Contactos QA</Link>
          <button type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-white border border-violet-200 text-[12px] font-bold text-violet-700 hover:bg-violet-100 transition-all" data-accion="agregar-qa" @click="agregarContactosQa">
            <UserPlus class="w-3.5 h-3.5" /> Agregar contactos QA
          </button>
          <button v-if="resumen.qa" type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-violet-600 text-[12px] font-bold text-white hover:bg-violet-700 transition-all disabled:opacity-50" :disabled="enCurso !== null || resumen.qa.pendientes === 0 || !resumen.qa.plantilla_lista" data-accion="emitir-qa" @click="emitirPruebasQa">
            <Loader2 v-if="enCurso === 'emitir-qa'" class="w-3.5 h-3.5 animate-spin" />
            <BadgeCheck v-else class="w-3.5 h-3.5" />
            Emitir pruebas internas<span v-if="resumen.qa.pendientes > 0"> · {{ resumen.qa.pendientes }}</span>
          </button>
        </div>
      </section>

      <p class="mt-4 px-1 text-[12px] font-semibold text-slate-500" data-resumen-emision>
        {{ resumen.vigentes }} {{ plural(resumen.vigentes, "vigente", "vigentes") }} · {{ resumen.pendientes }} sin emitir · {{ resumen.revocados }} {{ plural(resumen.revocados, "revocado", "revocados") }}. Los certificados ya emitidos no cambian si luego editas al participante, la base o la plantilla. Para actualizar uno, usa «Reemitir».
      </p>

      <!-- Tabla -->
      <div v-if="participantes.data.length === 0" class="mt-6 text-center py-16 bg-white rounded-[2rem] border border-dashed border-slate-200" data-sin-participantes>
        <p class="text-slate-600 font-bold text-lg">{{ filtros.q ? "Sin resultados" : "Esta base no tiene participantes" }}</p>
        <p class="text-slate-400 text-sm mt-1">{{ filtros.q ? `Nadie coincide con "${filtros.q}".` : "Agrega el primero con «Agregar participante»." }}</p>
      </div>

      <div v-else class="mt-6 space-y-4">
        <div class="overflow-x-auto bg-white rounded-[2rem] border border-slate-100 shadow-sm">
          <table class="min-w-full text-sm" data-tabla-participantes>
            <thead class="text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
              <tr>
                <th class="text-left font-black px-6 py-4">Nombre completo</th>
                <th class="text-left font-black px-4 py-4">Documento</th>
                <th class="text-left font-black px-4 py-4">Correo</th>
                <th class="text-left font-black px-4 py-4">Certificado</th>
                <th class="text-left font-black px-4 py-4 w-28">Fila del archivo</th>
                <th class="px-6 py-4 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="(p, i) in participantes.data" :key="p.id" class="hover:bg-slate-50/60 transition-colors" :class="p.es_control_qa ? 'bg-violet-50/30' : ''" :style="sigueContactoQa(i) ? 'border-top-color: transparent' : ''" :data-participante="p.id" :data-sigue-contacto-qa="sigueContactoQa(i) ? '' : undefined">
                <td class="px-6 py-3 font-extrabold text-slate-900 break-words" :class="sigueContactoQa(i) ? 'pl-12' : ''">
                  <template v-if="sigueContactoQa(i)">
                    <span class="text-[12px] font-bold text-slate-400">↳ Misma persona, otra prueba</span>
                  </template>
                  <template v-else>
                    {{ p.nombre_completo }}
                  </template>
                  <span v-if="p.es_control_qa" class="ml-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-violet-100 text-violet-700 text-[10px] font-black align-middle" data-qa>QA<template v-if="!sigueContactoQa(i) && pruebasDelContacto(i) > 1"> · {{ pruebasDelContacto(i) }} pruebas</template></span>
                  <span v-if="p.elegibilidad === 'pendiente'" class="ml-2 inline-flex px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[10px] font-black align-middle" :title="p.elegibilidad_motivo ?? ''" data-elegibilidad="pendiente">Pendiente de validación</span>
                  <span v-else-if="p.elegibilidad === 'no_elegible'" class="ml-2 inline-flex px-2 py-0.5 rounded-full bg-slate-200 text-slate-600 text-[10px] font-black align-middle" :title="p.elegibilidad_motivo ?? ''" data-elegibilidad="no_elegible">No elegible</span>
                  <span v-if="p.tipo_documento_confirmado === false" class="ml-2 inline-flex px-2 py-0.5 rounded-full bg-rose-100 text-rose-700 text-[10px] font-black align-middle" title="El tipo de documento no está confirmado: no se emite hasta confirmarlo" data-tipo-documento="pendiente">Tipo de documento por confirmar</span>
                  <span v-if="p.intensidad_horaria" class="ml-2 inline-flex px-2 py-0.5 rounded-full bg-sky-100 text-sky-700 text-[10px] font-black align-middle" title="Intensidad horaria propia (excepción aprobada)" data-intensidad>{{ p.intensidad_horaria }}</span>
                  <span v-if="p.modalidad" class="ml-2 inline-flex px-2 py-0.5 rounded-full bg-violet-100 text-violet-700 text-[10px] font-black align-middle" title="Modalidad de esta persona" data-modalidad>{{ p.modalidad }}</span>
                </td>
                <td class="px-4 py-3 font-semibold text-slate-600 break-words"><span v-if="!sigueContactoQa(i)">{{ p.documento }}</span></td>
                <td class="px-4 py-3 text-[12px] font-semibold break-all max-w-[14rem]" data-correo>
                  <span v-if="sigueContactoQa(i)" class="sr-only">{{ p.correo }}</span>
                  <span v-else-if="p.correo" class="text-slate-600"><Mail class="inline w-3 h-3 mr-1 text-slate-400" />{{ p.correo }}</span>
                  <span v-else class="text-amber-600">Sin correo</span>
                </td>
                <td class="px-4 py-3" data-estado-emision :data-estado="p.estado_emision">
                  <span v-if="p.estado_emision === 'emitida'" class="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-black">Emitida v{{ p.emision.version }}</span>
                  <span v-else-if="p.estado_emision === 'revocada'" class="px-2.5 py-1 rounded-full bg-rose-50 text-rose-600 text-[11px] font-black">Revocada</span>
                  <span v-else class="px-2.5 py-1 rounded-full bg-slate-100 text-slate-500 text-[11px] font-black">Sin emitir</span>
                </td>
                <td class="px-4 py-3 font-mono text-[12px] text-slate-400">{{ p.fila_origen ?? "manual" }}</td>
                <td class="px-6 py-3">
                  <div class="flex items-center justify-end gap-1.5">
                    <button type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full whitespace-nowrap bg-primary-vinotinto/10 text-[12px] font-bold text-primary-vinotinto hover:bg-primary-vinotinto/20 transition-all disabled:opacity-50" :disabled="generando !== null" :aria-label="`Ver vista previa del certificado de ${p.nombre_completo}`" data-accion="pdf" @click="generarPdf(p)">
                      <Loader2 v-if="generando === p.id" class="w-3.5 h-3.5 animate-spin" />
                      <FileDown v-else class="w-3.5 h-3.5" />
                      {{ generando === p.id ? "Generando…" : "Vista previa" }}
                    </button>
                    <button v-if="p.estado_emision === 'sin_emitir'" type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full whitespace-nowrap bg-emerald-600 text-[12px] font-bold text-white hover:bg-emerald-700 transition-all disabled:opacity-50" :disabled="enCurso !== null || !vistaLista || p.tipo_documento_confirmado === false || (p.elegibilidad && p.elegibilidad !== 'elegible')" :title="p.tipo_documento_confirmado === false ? 'Confirma primero el tipo de documento' : p.elegibilidad && p.elegibilidad !== 'elegible' ? 'No se emite hasta que la persona sea elegible' : !vistaLista ? 'Antes de emitir, revisa la vista previa y confirma la plantilla' : ''" :aria-label="`Emitir certificado de ${p.nombre_completo}`" data-accion="emitir" @click="emitir(p)">
                      <Loader2 v-if="enCurso === `emitir-${p.id}`" class="w-3.5 h-3.5 animate-spin" />
                      <BadgeCheck v-else class="w-3.5 h-3.5" />
                      {{ p.es_control_qa ? "Emitir prueba (QA)" : "Emitir certificado" }}
                    </button>
                    <button v-if="p.estado_emision === 'revocada'" type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full whitespace-nowrap bg-emerald-600 text-[12px] font-bold text-white hover:bg-emerald-700 transition-all disabled:opacity-50" :disabled="enCurso !== null" :aria-label="`Volver a emitir el certificado de ${p.nombre_completo}`" data-accion="volver-a-emitir" @click="volverAEmitir(p)">
                      <Loader2 v-if="enCurso === `emitir-${p.id}`" class="w-3.5 h-3.5 animate-spin" />
                      <RefreshCw v-else class="w-3.5 h-3.5" />
                      Volver a emitir
                    </button>
                    <template v-if="p.estado_emision === 'emitida'">
                      <button type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full whitespace-nowrap bg-emerald-50 text-[12px] font-bold text-emerald-700 hover:bg-emerald-100 transition-all disabled:opacity-50" :disabled="generando !== null" :aria-label="`Descargar el certificado de ${p.nombre_completo}`" data-accion="descargar" @click="descargarEmision({ id: p.emision.id, version: p.emision.version })">
                        <FileDown class="w-3.5 h-3.5" /> Descargar
                      </button>
                      <button type="button" class="p-2 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-all disabled:opacity-50" :disabled="enCurso !== null" title="Reemitir" :aria-label="`Reemitir el certificado de ${p.nombre_completo}`" data-accion="reemitir" @click="abrirMotivo('reemitir', p)">
                        <RefreshCw class="w-4 h-4" />
                      </button>
                      <button type="button" class="p-2 rounded-xl text-rose-400 hover:text-rose-600 hover:bg-rose-50 transition-all disabled:opacity-50" :disabled="enCurso !== null" title="Revocar" :aria-label="`Revocar el certificado de ${p.nombre_completo}`" data-accion="revocar" @click="abrirMotivo('revocar', p)">
                        <Ban class="w-4 h-4" />
                      </button>
                    </template>
                    <button type="button" class="p-2 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-all" title="Editar" :aria-label="`Editar a ${p.nombre_completo}`" data-accion="editar" @click="abrirEdicion(p)">
                      <SquarePen class="w-4 h-4" />
                    </button>
                    <button type="button" class="p-2 rounded-xl text-rose-400 hover:text-rose-600 hover:bg-rose-50 transition-all" title="Eliminar" :aria-label="`Eliminar a ${p.nombre_completo}`" data-accion="eliminar" @click="eliminarParticipante(p)">
                      <Trash2 class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="participantes.last_page > 1" class="flex items-center justify-between px-2" data-paginacion>
          <button type="button" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-40" :disabled="!participantes.prev_page_url" @click="irA(participantes.prev_page_url)">
            <ChevronLeft class="w-4 h-4" /> Anterior
          </button>
          <span class="text-[13px] font-bold text-slate-500">Página {{ participantes.current_page }} de {{ participantes.last_page }} · {{ participantes.total }} {{ plural(participantes.total, "resultado", "resultados") }}</span>
          <button type="button" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-40" :disabled="!participantes.next_page_url" @click="irA(participantes.next_page_url)">
            Siguiente <ChevronRight class="w-4 h-4" />
          </button>
        </div>
      </div>

      <HistorialEmisiones :lote-id="lote.id" :version="versionHistorial" :descargando="generando" @descargar="descargarEmision" />
    </Sidebar>
  </AuthenticatedLayout>

  <ParticipanteModal :show="modalParticipante.abierto" :lote-id="lote.id" :participante="modalParticipante.participante" @close="modalParticipante.abierto = false" />
  <LoteEditModal :show="modalLote" :lote="lote" @close="modalLote = false" />
  <EliminarDefinitivamenteModal :show="modalDefinitivo" tipo="base" :nombre="lote.nombre" :resumen-url="route('credential-flow.lotes.eliminacion.resumen', lote.id)" :eliminar-url="route('credential-flow.lotes.destroy-definitivo', lote.id)" @close="modalDefinitivo = false" />
  <MotivoEmisionModal :show="motivoModal.abierto" :modo="motivoModal.modo" :participante="motivoModal.participante?.nombre_completo ?? ''" :procesando="enCurso !== null" :error="errorEmision" @close="motivoModal.abierto = false" @confirmar="confirmarMotivo" />

  <ConfirmacionesPop
    :is-open="confirmationState.isOpen"
    :title="confirmationState.title"
    :message="confirmationState.message"
    :icon="confirmationState.icon"
    :confirm-text="confirmationState.confirmText"
    :is-loading="procesando"
    @close="closeConfirmationModal"
    @confirm="handleConfirm"
  />
</template>
