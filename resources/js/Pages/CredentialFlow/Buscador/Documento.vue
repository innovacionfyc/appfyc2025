<script setup>
import { computed, onBeforeUnmount, reactive, ref } from "vue";
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import { Search, SearchX, ScanSearch, TriangleAlert, Link2, FlaskConical, Archive, Sparkles, Ban, Info, Lock } from "lucide-vue-next";

// La pantalla llega SIN datos. El resultado vive solo en memoria de este componente: no va en la URL, ni en los props de Inertia (que se guardan en el historial
// del navegador), ni en almacenamiento local. Al salir de la pantalla se borra.
const props = defineProps({
  tipos: { type: Array, required: true }, // [{ valor, etiqueta }]
  minimo: { type: Number, required: true },
  limites: { type: Object, required: true }, // { minuto, dia }
});

const form = reactive({ documento: "", tipo: "" });
const resultado = ref(null);
const error = ref(null);
const cargando = ref(false);

const buscar = async () => {
  if (cargando.value) return;
  error.value = null;
  cargando.value = true;
  try {
    const { data } = await window.axios.post(route("credential-flow.buscador-documento.buscar"), { documento: form.documento.trim(), tipo: form.tipo || null });
    resultado.value = data.resultado;
  } catch (e) {
    resultado.value = null;
    const estado = e?.response?.status;
    error.value =
      estado === 422
        ? e.response.data?.message ?? "Revisa el número de documento."
        : estado === 429
          ? `Llegaste al límite de búsquedas (${props.limites.minuto} por minuto y ${props.limites.dia} por día). Espera un momento antes de volver a intentarlo.`
          : estado === 403 || estado === 404 || estado === 401
            ? "No tienes acceso a este buscador o tu sesión terminó. Recarga la página."
            : "No fue posible completar la búsqueda. Inténtalo de nuevo.";
  } finally {
    cargando.value = false;
  }
};

const limpiar = () => {
  form.documento = "";
  form.tipo = "";
  resultado.value = null;
  error.value = null;
};
onBeforeUnmount(limpiar);

const resumen = computed(() => resultado.value?.resumen ?? null);
const tipoElegido = computed(() => props.tipos.find((t) => t.valor === resultado.value?.tipo)?.etiqueta ?? null);

const ESTADO = {
  vigente: "bg-emerald-50 text-emerald-700 border-emerald-100",
  reemplazado: "bg-amber-50 text-amber-700 border-amber-100",
  revocado: "bg-rose-50 text-rose-700 border-rose-100",
};
const TONO = {
  emerald: "bg-emerald-50 text-emerald-700",
  sky: "bg-sky-50 text-sky-700",
  amber: "bg-amber-50 text-amber-700",
  rose: "bg-rose-50 text-rose-700",
  slate: "bg-slate-100 text-slate-600",
};
// Por qué un registro NO se podrá descargar desde el panel (la descarga llega en la siguiente etapa).
const MOTIVO = {
  NO_CONGELADO: "El PDF histórico aún no se ha generado; el buscador no lo genera.",
  DUPLICADO: "Duplicado histórico: cuenta el certificado principal de su grupo.",
  IDENTIDAD_AMBIGUA: "Identidad ambigua por tipo de documento: requiere revisión.",
  VERSION_REEMPLAZADA: "Versión anterior: la reemplazó una versión nueva.",
  REVOCADA: "Certificado revocado.",
  PRUEBA_INTERNA: "Prueba interna (QA): se muestra, pero no se descarga desde el buscador.",
  CLASIFICACION_NO_DISPONIBLE: "No se pudo confirmar la naturaleza de este documento: la descarga está bloqueada.",
  REVOCADO: "Certificado revocado.",
  REEMPLAZADO: "Reemplazado por un certificado nuevo.",
  REVISION_DOCUMENTO: "Documento en revisión.",
  PENDIENTE_CONCILIACION: "Pendiente de conciliación.",
  PENDIENTE_PLANTILLA: "Plantilla histórica pendiente.",
  NO_CONCILIADO: "No conciliado.",
  DATOS_FALTANTES: "Faltan datos del certificado.",
  SIN_CANONICO: "No se encontró su certificado principal.",
};
const motivo = (c) => MOTIVO[c.descarga?.motivo] ?? "No disponible para descarga.";

const tarjetasResumen = computed(() =>
  resumen.value === null
    ? []
    : [
        { etiqueta: "Certificados", valor: resumen.value.certificados },
        { etiqueta: "Históricos", valor: resumen.value.historicos },
        { etiqueta: "Nuevos", valor: resumen.value.modernos },
        { etiqueta: "Vigentes", valor: resumen.value.vigentes },
        { etiqueta: "Eventos", valor: resumen.value.eventos },
        { etiqueta: "Identidades", valor: resumen.value.identidades },
      ],
);

const CAMPO = "w-full rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-primary-vinotinto/30 focus:border-primary-vinotinto";
</script>

<template>
  <Head title="Credential Flow · Buscar por documento" />

  <AuthenticatedLayout>
    <Sidebar>
      <nav class="mb-4 text-[12px] font-bold text-slate-400" aria-label="Migas">
        <Link :href="route('credential-flow.index')" class="hover:text-primary-vinotinto transition-colors">Credential Flow</Link>
        <span class="mx-1.5">/</span>
        <span class="text-slate-600">Buscar por documento</span>
      </nav>

      <DashboardHeader title="Buscar por documento" subtitle="Todos los certificados de un número de documento, históricos y nuevos, con su estado. Solo lectura." />

      <form class="mt-6 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-5 grid grid-cols-1 sm:grid-cols-6 gap-4" autocomplete="off" data-form-buscar @submit.prevent="buscar">
        <label class="block sm:col-span-3">
          <span class="block mb-1.5 text-[12px] font-bold text-slate-500">Número de documento</span>
          <span class="relative block">
            <Search class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
            <input
              v-model="form.documento"
              type="text"
              inputmode="text"
              maxlength="40"
              autocomplete="off"
              spellcheck="false"
              placeholder="Número completo, con o sin puntos"
              :class="[CAMPO, 'pl-10']"
              data-documento
            />
          </span>
        </label>
        <label class="block sm:col-span-2">
          <span class="block mb-1.5 text-[12px] font-bold text-slate-500">Tipo (opcional)</span>
          <select v-model="form.tipo" :class="CAMPO" data-tipo>
            <option value="">Cualquiera</option>
            <option v-for="t in tipos" :key="t.valor" :value="t.valor">{{ t.etiqueta }}</option>
          </select>
        </label>
        <div class="flex items-end gap-2">
          <button
            type="submit"
            :disabled="cargando || form.documento.trim() === ''"
            class="flex-1 inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
            data-buscar
          >
            {{ cargando ? "Buscando…" : "Buscar" }}
          </button>
        </div>
      </form>
      <p class="mt-2 px-2 text-[12px] font-semibold text-slate-400">
        Búsqueda exacta del número completo (mínimo {{ minimo }} caracteres): no se buscan partes del número. Cada búsqueda queda registrada. Límite: {{ limites.minuto }} por minuto y
        {{ limites.dia }} por día.
      </p>

      <div v-if="error" class="mt-6 flex items-start gap-3 rounded-[1.5rem] border border-rose-100 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-700" role="alert" data-error>
        <TriangleAlert class="w-5 h-5 shrink-0 mt-0.5" />
        <span>{{ error }}</span>
      </div>

      <div v-if="resultado === null && !error" class="mt-8 text-center py-16 bg-white rounded-[2rem] border border-dashed border-slate-200 text-slate-400 font-semibold" data-sin-busqueda>
        <ScanSearch class="w-8 h-8 mx-auto mb-3 text-slate-300" />
        Escribe un número de documento para ver sus certificados.
      </div>

      <template v-if="resultado">
        <div class="mt-6 flex items-center justify-between gap-3 px-2">
          <p class="text-[13px] font-bold text-slate-500">
            Resultado de la búsqueda<span v-if="tipoElegido"> · tipo {{ tipoElegido }}</span>
          </p>
          <button type="button" class="text-[12px] font-bold text-slate-400 hover:text-primary-vinotinto transition-colors" data-limpiar @click="limpiar">Limpiar</button>
        </div>

        <div v-if="resumen.certificados === 0" class="mt-4 text-center py-16 space-y-3 bg-white rounded-[2rem] border border-dashed border-slate-200" data-sin-resultados>
          <div class="w-16 h-16 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto"><SearchX class="w-8 h-8 text-slate-400" /></div>
          <p class="text-slate-600 font-bold text-lg">No encontramos certificados con ese número</p>
          <p class="text-slate-400 text-sm max-w-md mx-auto px-4">
            Revisa el número.<span v-if="resumen.otros_tipos > 0"> Hay {{ resumen.otros_tipos }} registro(s) con otro tipo de documento: busca con «Cualquiera».</span>
          </p>
        </div>

        <template v-else>
          <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3" data-resumen>
            <div v-for="t in tarjetasResumen" :key="t.etiqueta" class="bg-white rounded-[1.5rem] border border-slate-100 shadow-sm px-4 py-3">
              <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">{{ t.etiqueta }}</p>
              <p class="text-2xl font-black text-slate-800">{{ t.valor }}</p>
            </div>
          </div>
          <p v-if="resumen.qa > 0 || resumen.otros_tipos > 0 || resumen.participaciones_sin_emision > 0" class="mt-2 px-2 text-[12px] font-semibold text-slate-400">
            <span v-if="resumen.qa > 0">{{ resumen.qa }} prueba(s) interna(s) QA incluidas. </span>
            <span v-if="resumen.otros_tipos > 0">{{ resumen.otros_tipos }} registro(s) de otro tipo de documento no se muestran. </span>
            <span v-if="resumen.participaciones_sin_emision > 0">{{ resumen.participaciones_sin_emision }} participación(es) aún sin certificado emitido.</span>
          </p>

          <div v-if="resumen.conflicto" class="mt-5 flex items-start gap-3 rounded-[1.5rem] border border-amber-200 bg-amber-50 px-5 py-4 text-sm font-semibold text-amber-800" role="status" data-conflicto>
            <TriangleAlert class="w-5 h-5 shrink-0 mt-0.5" />
            <span>
              Posible conflicto de identidad: este número tiene
              <template v-if="resumen.identidades > 1">{{ resumen.identidades }} grafías de nombre distintas</template>
              <template v-if="resumen.identidades > 1 && resumen.excluidos > 0"> y </template>
              <template v-if="resumen.excluidos > 0">{{ resumen.excluidos }} registro(s) con tipos de documento que no coinciden</template>
              <template v-if="resumen.identidades <= 1 && resumen.excluidos === 0">tipos de documento distintos dentro de la misma identidad</template>.
              Se muestran por separado y nunca se unen solos: confirma la persona antes de entregar un certificado.
            </span>
          </div>

          <section v-for="identidad in resultado.identidades" :key="identidad.etiqueta" class="mt-6 bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden" :data-identidad="identidad.etiqueta">
            <header class="px-6 py-4 border-b border-slate-100 flex flex-wrap items-center gap-x-3 gap-y-2">
              <span class="w-9 h-9 rounded-2xl bg-primary-vinotinto/10 text-primary-vinotinto flex items-center justify-center text-sm font-black">{{ identidad.etiqueta }}</span>
              <div class="min-w-0">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Identidad {{ identidad.etiqueta }}</p>
                <p class="text-base font-extrabold text-slate-900 break-words">{{ identidad.nombres.join(" · ") }}</p>
              </div>
              <div class="ml-auto flex flex-wrap gap-2">
                <span v-for="t in identidad.tipos" :key="t" class="px-2.5 py-1 rounded-full bg-slate-100 text-[11px] font-bold text-slate-600">{{ t }}</span>
                <span v-if="identidad.con_vinculo" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-sky-50 text-[11px] font-bold text-sky-700" data-con-vinculo>
                  <Link2 class="w-3.5 h-3.5" /> Vínculo de identidad vigente
                </span>
                <span v-if="identidad.tipos_distintos" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-amber-50 text-[11px] font-bold text-amber-700" data-tipos-distintos>
                  <TriangleAlert class="w-3.5 h-3.5" /> Tipos de documento distintos
                </span>
              </div>
            </header>

            <ul class="divide-y divide-slate-100">
              <li v-for="c in identidad.certificados" :key="c.origen + c.id" class="px-6 py-4 grid grid-cols-1 lg:grid-cols-12 gap-3" :data-certificado="c.origen + '-' + c.id">
                <div class="lg:col-span-5 min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <span :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold', c.origen === 'historico' ? 'bg-slate-100 text-slate-600' : 'bg-primary-vinotinto/10 text-primary-vinotinto']">
                      <component :is="c.origen === 'historico' ? Archive : Sparkles" class="w-3.5 h-3.5" />
                      {{ c.origen === "historico" ? "Histórico" : "Nuevo" }}
                    </span>
                    <span v-if="c.qa" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-violet-50 text-[11px] font-bold text-violet-700" data-qa>
                      <FlaskConical class="w-3.5 h-3.5" /> QA · sin validez académica
                    </span>
                    <span v-if="c.clasificacion === 'indeterminada'" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-50 text-[11px] font-bold text-amber-700" data-clasificacion-no-disponible>
                      <TriangleAlert class="w-3.5 h-3.5" /> Clasificación no disponible
                    </span>
                  </div>
                  <p class="mt-1.5 text-sm font-bold text-slate-800 break-words">{{ c.evento?.nombre || "Evento sin nombre" }}</p>
                  <p class="text-[12px] font-semibold text-slate-400">{{ c.evento?.fecha || (c.anio ?? "Sin fecha") }}<span v-if="c.lote"> · Base: {{ c.lote.nombre }}</span></p>
                </div>

                <dl class="lg:col-span-4 grid grid-cols-3 gap-2 text-[12px]">
                  <div>
                    <dt class="font-bold text-slate-400">Modalidad</dt>
                    <dd class="font-semibold text-slate-700">{{ c.modalidad || "—" }}</dd>
                  </div>
                  <div>
                    <dt class="font-bold text-slate-400">Versión</dt>
                    <dd class="font-semibold text-slate-700">{{ c.version ? "v" + c.version : "—" }}</dd>
                  </div>
                  <div>
                    <dt class="font-bold text-slate-400">Código</dt>
                    <dd class="font-semibold text-slate-700 break-all">{{ c.codigo || "—" }}</dd>
                  </div>
                  <div class="col-span-3">
                    <dt class="font-bold text-slate-400">Documento</dt>
                    <dd class="font-semibold text-slate-700">{{ c.tipo ? c.tipo + " " : "" }}{{ c.documento }}</dd>
                  </div>
                </dl>

                <div class="lg:col-span-3 flex flex-col items-start gap-1.5">
                  <span :class="['px-2.5 py-1 rounded-full border text-[11px] font-bold', ESTADO[c.estado]]" data-estado>{{ c.estado_etiqueta }}</span>
                  <span v-if="c.conciliacion && c.conciliacion.estado !== 'ok'" :class="['px-2.5 py-1 rounded-full text-[11px] font-bold', TONO[c.conciliacion.tono] ?? TONO.slate]">{{ c.conciliacion.etiqueta }}</span>
                  <span v-if="c.origen === 'historico'" class="text-[11px] font-semibold text-slate-400">{{ c.pdf_congelado ? "PDF generado" : "PDF aún no generado" }}</span>
                  <span v-if="c.reemplazada_por" class="text-[11px] font-semibold text-slate-400">Reemplazada por la emisión #{{ c.reemplazada_por }}</span>
                  <!-- Descarga: solo un estado informativo. El botón de descarga llega en la siguiente etapa. -->
                  <span v-if="c.descarga.permitida" class="inline-flex items-center gap-1 text-[11px] font-semibold text-slate-400" data-descarga="pendiente">
                    <Info class="w-3.5 h-3.5" /> Descarga desde el panel: próximamente
                  </span>
                  <span v-else class="inline-flex items-start gap-1 text-[11px] font-semibold text-slate-400" data-descarga="no">
                    <Lock class="w-3.5 h-3.5 shrink-0 mt-px" /> {{ motivo(c) }}
                  </span>
                </div>
              </li>
            </ul>
          </section>

          <section v-if="resultado.excluidos.length > 0" class="mt-6 bg-white rounded-[2rem] border border-rose-100 shadow-sm overflow-hidden" data-excluidos>
            <header class="px-6 py-4 border-b border-rose-100 flex items-start gap-3">
              <Ban class="w-5 h-5 text-rose-500 shrink-0 mt-0.5" />
              <div>
                <p class="text-base font-extrabold text-slate-900">Registros excluidos por identidad ambigua</p>
                <p class="text-[12px] font-semibold text-slate-500">
                  El mismo número y nombre aparecen con tipos de documento distintos. No se asignan a ninguna identidad ni se pueden descargar hasta que se revisen.
                </p>
              </div>
            </header>
            <ul class="divide-y divide-slate-100">
              <li v-for="c in resultado.excluidos" :key="c.origen + c.id" class="px-6 py-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px]" :data-excluido="c.origen + '-' + c.id">
                <span class="font-bold text-slate-600">{{ c.origen === "historico" ? "Histórico" : "Nuevo" }}</span>
                <span class="font-semibold text-slate-700">{{ c.nombre }}</span>
                <span class="font-semibold text-slate-500">{{ c.tipo ? c.tipo + " " : "" }}{{ c.documento }}</span>
                <span class="font-semibold text-slate-500">{{ c.evento?.nombre }}</span>
                <span :class="['px-2 py-0.5 rounded-full border text-[11px] font-bold', ESTADO[c.estado]]">{{ c.estado_etiqueta }}</span>
              </li>
            </ul>
          </section>
        </template>
      </template>
    </Sidebar>
  </AuthenticatedLayout>
</template>
