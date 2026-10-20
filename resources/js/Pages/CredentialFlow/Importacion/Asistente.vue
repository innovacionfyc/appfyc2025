<script setup>
// Asistente GUIADO de importación inteligente: SUBIR EXCEL → CONFIRMAR EVENTO → DATOS DEL CERTIFICADO → REVISAR PERSONAS → VER EL PDF → CREAR LA BASE.
// Son etapas de una misma pantalla: en cada una se muestra una decisión principal, qué acabas de hacer y qué sigue.
// Solo existe con la bandera CREDENTIAL_FLOW_IMPORTACION_INTELIGENTE encendida (apagada por defecto). Analizar, revisar y ver la vista previa NO crean nada:
// la base solo se crea con el botón «Crear base de participantes» del último paso.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import Pasos from "@/Components/CredentialFlow/Importacion/Pasos.vue";
import PasoSubir from "@/Components/CredentialFlow/Importacion/PasoSubir.vue";
import Guia from "@/Components/CredentialFlow/Importacion/Guia.vue";
import EtapaEvento from "@/Components/CredentialFlow/Importacion/EtapaEvento.vue";
import EtapaDatos from "@/Components/CredentialFlow/Importacion/EtapaDatos.vue";
import EtapaPersonas from "@/Components/CredentialFlow/Importacion/EtapaPersonas.vue";
import PasoVistaPrevia from "@/Components/CredentialFlow/Importacion/PasoVistaPrevia.vue";
import PasoConfirmar from "@/Components/CredentialFlow/Importacion/PasoConfirmar.vue";
import { ArrowLeft, ArrowRight, LoaderCircle, TriangleAlert, Trash2 } from "lucide-vue-next";
import { PASOS, crearAsistente } from "@/Composables/CredentialFlow/importacionInteligente";

const props = defineProps({
  plantillas: { type: Array, default: () => [] },
  plantilla_sugerida: { type: Number, default: null },
  borradores: { type: Array, default: () => [] },
  borrador_id: { type: Number, default: null },
  limites: { type: Object, default: () => ({ archivoMb: 2, filas: 1000, retencionDias: 7 }) },
  qa: { type: Object, default: () => ({ listo: true, motivos: [], activos: 0, esperados: 0 }) },
  qa_automatico: { type: Boolean, default: true },
});

const a = crearAsistente(props);
const indice = computed(() => PASOS.findIndex((p) => p.id === a.paso.value));
const descartando = ref(false);
const cargandoInicial = ref(!!props.borrador_id || !!Number(new URLSearchParams(window.location.search).get("borrador")));

// ── Evitar pérdida accidental de cambios ──────────────────────────────────────
const alSalir = (e) => {
  if (a.sinGuardar.value && !a.lote.value) {
    e.preventDefault();
    e.returnValue = "";
  }
};
let quitarInertia = null;
onMounted(async () => {
  window.addEventListener("beforeunload", alSalir);
  quitarInertia = router.on("before", (evento) => {
    if (a.sinGuardar.value && !a.lote.value && !window.confirm("Tienes cambios sin guardar en este borrador. ¿Salir sin guardarlos?")) evento.preventDefault();
  });
  // El borrador de la dirección (por si la pantalla se vuelve a montar sin recargar la página).
  const deUrl = Number(new URLSearchParams(window.location.search).get('borrador')) || null;
  const abierto = props.borrador_id ?? deUrl;
  if (abierto) {
    await a.abrir(abierto);
    cargandoInicial.value = false;
  }
});
onBeforeUnmount(() => {
  window.removeEventListener("beforeunload", alSalir);
  quitarInertia?.();
});

// El borrador abierto queda en la dirección: recargar la página lo recupera.
watch(
  () => a.borrador.value?.id ?? null,
  (id) => {
    const url = new URL(window.location.href);
    id ? url.searchParams.set("borrador", id) : url.searchParams.delete("borrador");
    window.history.replaceState(window.history.state, "", url);
  }
);

// Al cambiar de paso, el foco va al título (lectores de pantalla) y la página vuelve arriba.
watch(
  () => a.paso.value,
  async () => {
    await nextTick();
    const titulo = document.querySelector("h2[tabindex='-1']");
    titulo?.focus({ preventScroll: true });
    // El contenedor que se desplaza no siempre es la ventana: se lleva el título a la vista.
    (document.querySelector("[data-pasos]") ?? titulo)?.scrollIntoView({ behavior: "smooth", block: "start" });
  }
);

// UNA sola acción principal por pantalla: la registra la etapa que está a la vista (ver `registrarPrincipal`) y se muestra aquí, junto a «Atrás». Si algo impide
// usarla, el botón queda apagado SOLO mientras se guarda y siempre dice qué falta.
const principal = computed(() => a.principal.value);
const anterior = () => indice.value > 0 && a.irA(PASOS[indice.value - 1].id);
const descartar = async () => {
  await a.descartar();
  descartando.value = false;
};
const hayBarra = computed(() => a.borrador.value && !a.lote.value && a.paso.value !== "subir");
</script>

<template>
  <Head title="Credential Flow · Importación inteligente" />

  <AuthenticatedLayout>
    <Sidebar>
      <nav class="mb-3 flex flex-wrap items-center gap-2 pr-16 sm:pr-0 text-[12px] font-bold text-slate-400" aria-label="Ruta de navegación">
        <Link :href="route('credential-flow.index')" class="hover:text-primary-vinotinto transition-colors">Credential Flow</Link>
        <span aria-hidden="true">/</span>
        <Link :href="route('credential-flow.lotes.index')" class="hover:text-primary-vinotinto transition-colors">Bases de participantes</Link>
        <span aria-hidden="true">/</span>
        <span class="text-slate-600">Importación inteligente</span>
      </nav>

      <div class="max-w-5xl mx-auto pb-28">
        <header class="mb-5 flex flex-wrap items-end justify-between gap-3">
          <div>
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900">Importación inteligente</h1>
            <p v-if="!a.borrador.value" class="text-sm font-medium text-slate-500">Te llevamos paso a paso: sube el Excel, confirma lo que encontramos y crea la base de participantes.</p>
          </div>
          <Link v-if="!a.borrador.value" :href="route('credential-flow.lotes.nuevo')" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-white border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 transition-all">
            <ArrowLeft class="w-4 h-4" aria-hidden="true" /> Importación de siempre
          </Link>
        </header>

        <div class="rounded-3xl border border-slate-200 bg-white p-3 sm:p-4 mb-4"><Pasos /></div>
        <div class="mb-6"><Guia /></div>

        <p class="sr-only" role="status" aria-live="polite" data-aviso-vivo>{{ a.aviso.value }}</p>

        <div v-if="a.desactualizado.value" class="mb-5 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 flex flex-wrap items-center justify-between gap-3" role="alert" data-desactualizado>
          <p class="flex items-start gap-2 text-sm font-bold text-amber-900"><TriangleAlert class="w-4 h-4 shrink-0 mt-0.5" aria-hidden="true" /> Este borrador cambió en otra pestaña o acción, así que tu revisión anterior ya no vale.</p>
          <button type="button" class="rounded-xl bg-amber-600 px-4 py-2 text-[13px] font-extrabold text-white hover:bg-amber-700" data-recargar @click="a.recargar()">Cargar la versión actual</button>
        </div>
        <p v-else-if="a.error.value && a.paso.value !== 'subir' && a.paso.value !== 'confirmar'" class="mb-5 rounded-2xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm font-bold text-rose-700" role="alert" data-error-general>
          {{ a.error.value.mensaje }}
        </p>

        <div v-if="cargandoInicial" class="flex items-center gap-2 py-10 justify-center text-sm font-semibold text-slate-500" role="status"><LoaderCircle class="w-4 h-4 animate-spin" aria-hidden="true" /> Abriendo tu borrador…</div>
        <template v-else>
          <PasoSubir v-if="a.paso.value === 'subir'" />
          <EtapaEvento v-else-if="a.paso.value === 'evento'" />
          <EtapaDatos v-else-if="a.paso.value === 'datos'" />
          <EtapaPersonas v-else-if="a.paso.value === 'personas'" />
          <PasoVistaPrevia v-else-if="a.paso.value === 'vista'" />
          <PasoConfirmar v-else />
        </template>

        <div v-if="a.borrador.value && !a.lote.value && a.paso.value !== 'subir'" class="mt-8 text-[12px] font-semibold text-slate-400">
          <template v-if="!descartando"><button type="button" class="inline-flex items-center gap-1 underline-offset-2 hover:underline hover:text-rose-600" data-descartar @click="descartando = true"><Trash2 class="w-3.5 h-3.5" aria-hidden="true" /> Descartar este borrador</button></template>
          <template v-else>
            ¿Descartar el borrador? Sus datos personales se borran y no se puede deshacer.
            <button type="button" class="ml-2 rounded-lg bg-rose-600 px-3 py-1 font-extrabold text-white" data-descartar-si @click="descartar">Sí, descartar</button>
            <button type="button" class="ml-1 rounded-lg px-3 py-1 font-extrabold text-slate-600 ring-1 ring-slate-300" @click="descartando = false">Cancelar</button>
          </template>
        </div>
      </div>

      <!-- Barra de acciones: siempre a mano, también en el celular -->
      <div v-if="hayBarra" class="fixed bottom-0 right-0 left-0 md:left-[6.5rem] z-30 border-t border-slate-200 bg-white/95 backdrop-blur px-3 py-3 sm:px-6" data-barra-acciones>
        <div class="max-w-5xl mx-auto flex flex-wrap items-center justify-between gap-2">
          <button type="button" :disabled="indice === 0 || a.guardando.value" class="inline-flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-sm font-extrabold text-slate-600 ring-1 ring-slate-300 hover:bg-slate-50 disabled:opacity-50" data-accion="atras" @click="anterior">
            <ArrowLeft class="w-4 h-4" aria-hidden="true" /> Atrás
          </button>
          <div class="order-last sm:order-none w-full sm:w-auto text-center space-y-0.5" data-estado-guardado>
            <p class="text-[12px] font-bold" :class="a.sinGuardar.value ? 'text-amber-700' : 'text-slate-400'">
              {{ a.guardando.value ? "Guardando…" : a.sinGuardar.value ? "Tienes cambios sin guardar; se guardan al continuar" : "Todo guardado en el borrador" }}
              <button v-if="a.sinGuardar.value && !a.guardando.value" type="button" class="ml-1 underline underline-offset-2 hover:text-amber-900" data-accion="guardar" @click="a.guardar()">Guardar ahora</button>
            </p>
            <p v-if="principal?.razon" class="text-[12px] font-bold text-amber-700" data-razon-principal>{{ principal.razon }}</p>
          </div>
          <button v-if="principal" type="button" :disabled="principal.deshabilitado || a.guardando.value" class="inline-flex items-center gap-1.5 rounded-xl bg-primary-vinotinto px-5 py-2.5 text-sm font-extrabold text-white hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 disabled:opacity-60 disabled:cursor-not-allowed" data-accion="principal" @click="principal.accion()">
            {{ principal.texto }} <ArrowRight class="w-4 h-4" aria-hidden="true" />
          </button>
        </div>
      </div>
    </Sidebar>
  </AuthenticatedLayout>
</template>
