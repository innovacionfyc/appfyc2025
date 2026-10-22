<script setup>
// Etapa 5 · Ver el PDF. Una decisión (la plantilla) y, enseguida, el certificado dibujado. Muestra los datos exactos que se imprimirían y, si ya se puede, el PDF dibujado con el motor de siempre.
// Es solo una VISTA: no emite nada, no crea códigos oficiales, no guarda el PDF y no modifica ninguna plantilla.
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { Link } from "@inertiajs/vue3";
import { FileSearch, LoaderCircle, ExternalLink, CircleAlert, Info } from "lucide-vue-next";
import { errorDeApi, mensajeDeErrorBlob, pedirPdfVistaPrevia, pedirVistaPrevia, registrarPrincipal, useAsistente } from "@/Composables/CredentialFlow/importacionInteligente";

const a = useAsistente();
const plantillas = a.props.plantillas;

const vp = ref(null);
const fila = ref(null);
const cargando = ref(false);
const errorDatos = ref("");
const pdfUrl = ref("");
const pdfError = ref("");
const cargandoPdf = ref(false);
let solicitud = 0;

const plantillaActual = computed(() => a.plantillaId.value ?? "");
const hayPdf = computed(() => vp.value?.pdf_disponible === true);
// Datos que faltan para dibujar el PDF, cada uno con su botón «Revisar este dato».
const faltantes = computed(() => (vp.value?.campos ?? []).filter((c) => c.falta));
const COMUNES = ["fecha", "intensidad_horaria", "modalidad", "ciudad", "texto_certificacion", "texto_complementario"];
async function revisarFaltante(c) {
  if (COMUNES.includes(c.clave)) return a.irADato(c.clave);
  if (c.clave === "evento") return a.irA("evento");
  if (await a.irA("personas")) a.verPersonas("faltan");
}
async function continuar() {
  if (await a.irA("confirmar")) a.hecho.value = "Elegiste la plantilla y viste el PDF.";
}
// Plantilla que el sistema SUGIERE (si hay una configurada y es válida). Nunca se elige sola: se confirma con el botón principal o se cambia en la lista.
const sugerida = computed(() => plantillas.find((p) => p.id === a.props.plantilla_sugerida) ?? null);
const enfocarPlantilla = () => document.querySelector("#plantilla")?.focus();

registrarPrincipal(a, () => {
  if (plantillaActual.value === "") {
    return sugerida.value
      ? { texto: `Usar la plantilla sugerida`, accion: () => cambiarPlantilla(sugerida.value.id) }
      : { texto: "Elegir la plantilla", accion: enfocarPlantilla, razon: plantillas.length ? "Elige la plantilla en la lista para ver el certificado." : "Todavía no hay plantillas con diseño guardado." };
  }
  if (!vp.value) return { texto: "Preparando la vista previa…", accion: () => {}, deshabilitado: true, razon: "Estamos dibujando el certificado…" };
  if (vp.value.problemas.length && faltantes.value.length) return { texto: `Revisar «${faltantes.value[0].etiqueta}»`, accion: () => revisarFaltante(faltantes.value[0]) };
  if (vp.value.problemas.length) return { texto: "Revisar los datos", accion: () => a.irA("datos"), razon: "Falta algo para dibujar el PDF." };
  if (a.origen.value) return { texto: `Volver a «${a.origen.value.titulo}»`, accion: () => a.volverAlOrigen() };

  return { texto: "Continuar", accion: continuar };
});

function liberar() {
  if (pdfUrl.value) URL.revokeObjectURL(pdfUrl.value);
  pdfUrl.value = "";
}

async function refrescar() {
  if (!a.borrador.value) return;
  const mia = ++solicitud;
  cargando.value = true;
  errorDatos.value = "";
  pdfError.value = "";
  liberar();
  try {
    const datos = await pedirVistaPrevia(a.borrador.value.id, fila.value);
    if (mia !== solicitud) return;
    vp.value = datos;
    fila.value = datos.participante?.fila ?? fila.value;
  } catch (e) {
    if (mia === solicitud) errorDatos.value = errorDeApi(e).mensaje;
    return;
  } finally {
    if (mia === solicitud) cargando.value = false;
  }
  if (vp.value?.pdf_disponible) {
    cargandoPdf.value = true;
    try {
      const url = await pedirPdfVistaPrevia(a.borrador.value.id, fila.value);
      if (mia !== solicitud) URL.revokeObjectURL(url);
      else pdfUrl.value = url;
    } catch (e) {
      if (mia === solicitud) pdfError.value = await mensajeDeErrorBlob(e);
    } finally {
      if (mia === solicitud) cargandoPdf.value = false;
    }
  }
}

async function cambiarPlantilla(valor) {
  a.elegirPlantilla(valor);
  if (await a.guardar()) {
    if (valor !== "") a.hecho.value = `Elegiste la plantilla «${plantillas.find((p) => String(p.id) === String(valor))?.nombre ?? ""}».`;
    await refrescar();
  }
}

function cambiarPersona(valor) {
  fila.value = Number(valor);
  refrescar();
}

onMounted(refrescar);
onBeforeUnmount(() => {
  solicitud++;
  liberar();
});
</script>

<template>
  <section class="space-y-5" aria-labelledby="titulo-vista">
    <div>
      <h2 id="titulo-vista" tabindex="-1" class="text-xl font-extrabold text-slate-900 focus:outline-none">Mira cómo quedará el certificado</h2>
      <p class="mt-1 text-sm font-medium text-slate-500">
        <template v-if="plantillaActual === ''"><strong>Elige la plantilla</strong> y dibujamos un certificado de ejemplo con los datos que confirmaste.</template>
        <template v-else>Revisa que el nombre, la fecha y los textos se vean bien. Es solo una vista previa: no es un certificado emitido, no tiene código oficial y no se guarda.</template>
      </p>
    </div>

    <p v-if="sugerida && plantillaActual === ''" class="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-semibold text-sky-900" data-plantilla-sugerida>
      <strong class="font-extrabold">Plantilla sugerida:</strong> «{{ sugerida.nombre }}». No se elige sola: confírmala con el botón de abajo o escoge otra en la lista.
    </p>

    <div class="grid gap-4 md:grid-cols-2 rounded-3xl border border-slate-200 bg-white p-5">
      <div>
        <label for="plantilla" class="block text-[11px] font-black uppercase tracking-widest text-slate-500 mb-1">Plantilla del certificado</label>
        <select id="plantilla" :value="plantillaActual" :disabled="a.guardando.value || plantillas.length === 0" class="w-full rounded-xl border-slate-300 text-sm font-semibold focus:border-primary-vinotinto focus:ring-primary-vinotinto/30" data-select-plantilla :data-recomendado="plantillaActual === '' ? '' : undefined" @change="cambiarPlantilla($event.target.value)">
          <option value="">Elige una plantilla…</option>
          <option v-for="p in plantillas" :key="p.id" :value="p.id">{{ p.nombre }}</option>
        </select>
        <p v-if="plantillas.length === 0" class="mt-2 text-[13px] font-semibold text-rose-700" role="alert" data-sin-plantillas>
          Todavía no hay plantillas con diseño guardado. <Link :href="route('credential-flow.plantillas.index')" class="underline">Crea o termina una en Plantillas</Link> y vuelve.
        </p>
      </div>
      <details class="md:col-span-1 self-start" data-otra-persona>
        <summary class="cursor-pointer text-[13px] font-extrabold text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 rounded">Probar con otra persona</summary>
        <label for="persona" class="block text-[11px] font-black uppercase tracking-widest text-slate-500 mb-1 mt-2">Ver el certificado de</label>
        <select id="persona" :value="fila ?? ''" :disabled="!vp?.participantes?.length" class="w-full rounded-xl border-slate-300 text-sm font-semibold focus:border-primary-vinotinto focus:ring-primary-vinotinto/30" data-select-persona @change="cambiarPersona($event.target.value)">
          <option v-for="p in vp?.participantes ?? []" :key="p.fila" :value="p.fila">{{ p.nombre_completo }}{{ p.modalidad ? ` — ${p.modalidad}` : "" }}</option>
        </select>
        <button v-if="vp?.fila_nombre_mas_largo && vp.fila_nombre_mas_largo !== fila" type="button" class="mt-2 text-[12px] font-extrabold text-primary-vinotinto underline-offset-2 hover:underline" data-nombre-largo @click="cambiarPersona(vp.fila_nombre_mas_largo)">
          Probar con el nombre más largo
        </button>
      </details>
    </div>

    <!-- Un ejemplo de CADA modalidad del Excel: revisa cómo sale el certificado presencial y el virtual antes de crear la base. -->
    <div v-if="(vp?.una_por_modalidad?.length ?? 0) > 1" class="flex flex-wrap items-center gap-2 rounded-2xl bg-violet-50/60 border border-violet-200 px-4 py-3" data-una-por-modalidad>
      <span class="text-[13px] font-extrabold text-violet-900">Ver un certificado de cada modalidad:</span>
      <button
        v-for="m in vp.una_por_modalidad"
        :key="m.fila"
        type="button"
        class="rounded-full px-3 py-1 text-[12px] font-extrabold ring-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50"
        :class="m.fila === fila ? 'bg-violet-700 text-white ring-violet-700' : 'bg-white text-violet-800 ring-violet-300 hover:bg-violet-100'"
        :aria-pressed="m.fila === fila"
        :data-ver-modalidad="m.modalidad"
        @click="cambiarPersona(m.fila)"
      >
        {{ m.modalidad }}
      </button>
    </div>

    <p v-if="errorDatos" class="rounded-2xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm font-bold text-rose-700" role="alert">{{ errorDatos }}</p>

    <div v-if="cargando && !vp" class="flex items-center gap-2 text-sm font-semibold text-slate-500" role="status"><LoaderCircle class="w-4 h-4 animate-spin" aria-hidden="true" /> Preparando la vista previa…</div>

    <template v-if="vp">
      <ul v-if="vp.avisos.length" class="space-y-2" data-avisos-vista>
        <li v-for="m in vp.avisos" :key="m" class="flex items-start gap-2 rounded-2xl bg-amber-50 border border-amber-200 px-4 py-3 text-[13px] font-semibold text-amber-900"><Info class="w-4 h-4 shrink-0 mt-0.5" aria-hidden="true" />{{ m }}</li>
      </ul>


      <section v-if="plantillaActual === ''" class="rounded-3xl border border-slate-200 bg-slate-50 p-5" data-sin-plantilla-elegida>
        <p class="text-sm font-extrabold text-slate-800">Todavía no elegiste la plantilla.</p>
        <p class="text-[13px] font-semibold text-slate-500">Elígela en la lista de arriba para ver el certificado. Hasta entonces no se dibuja ningún PDF.</p>
      </section>

      <section v-else-if="vp.problemas.length" class="rounded-3xl border border-amber-300 bg-amber-50 p-5 space-y-3" aria-labelledby="titulo-falta" data-falta-vista>
        <h3 id="titulo-falta" class="flex items-center gap-2 text-base font-extrabold text-amber-900"><CircleAlert class="w-5 h-5" aria-hidden="true" /> {{ vp.problemas.length === 1 ? "Falta 1 dato para dibujar el PDF" : `Faltan ${vp.problemas.length} datos para dibujar el PDF` }}</h3>
        <p class="text-sm font-semibold text-amber-900/90">Esta plantilla lo necesita y todavía no lo tenemos. No se simula ningún PDF.</p>
        <ul v-if="faltantes.length" class="space-y-2">
          <li v-for="c in faltantes" :key="c.clave" class="flex flex-wrap items-center justify-between gap-2 rounded-2xl bg-white/80 px-4 py-2.5" :data-falta-dato="c.clave">
            <span class="text-sm font-extrabold text-amber-950">{{ c.etiqueta }}</span>
            <button type="button" class="text-[13px] font-extrabold text-primary-vinotinto underline underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 rounded" data-recomendado data-accion-dato="revisar-dato-pdf" @click="revisarFaltante(c)">Revisar este dato</button>
          </li>
        </ul>
        <details class="text-[12.5px] font-semibold text-amber-900"><summary class="cursor-pointer font-extrabold">Ver detalles</summary><ul class="mt-1 list-disc pl-5"><li v-for="m in vp.problemas" :key="m">{{ m }}</li></ul></details>
      </section>

      <section v-else class="rounded-3xl border border-slate-200 bg-white p-5 space-y-3" aria-labelledby="titulo-pdf" data-pdf-vista>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h3 id="titulo-pdf" class="text-base font-extrabold text-slate-900">Vista previa del PDF</h3>
          <a v-if="pdfUrl" :href="pdfUrl" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-[13px] font-extrabold text-primary-vinotinto hover:underline"><ExternalLink class="w-4 h-4" aria-hidden="true" /> Abrir en otra pestaña</a>
        </div>
        <div v-if="cargandoPdf" class="flex items-center gap-2 text-sm font-semibold text-slate-500" role="status"><LoaderCircle class="w-4 h-4 animate-spin" aria-hidden="true" /> Dibujando el certificado…</div>
        <p v-else-if="pdfError" class="rounded-2xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm font-bold text-rose-700" role="alert" data-error-pdf>{{ pdfError }}</p>
        <iframe v-else-if="pdfUrl" :src="pdfUrl" title="Vista previa del certificado" class="w-full h-[70vh] min-h-[420px] rounded-2xl border border-slate-200 bg-slate-50" data-iframe-pdf></iframe>
        <p class="text-[12px] font-semibold text-slate-400">Si tu navegador no muestra el PDF aquí (algunos celulares), usa «Abrir en otra pestaña».</p>
      </section>
      <details class="rounded-3xl border border-slate-200 bg-white px-5 py-3" data-detalles-vista>
        <summary class="cursor-pointer text-[13px] font-extrabold text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 rounded">Ver detalles de lo que se imprime</summary>
        <div class="mt-3">
      <section class="space-y-3" aria-labelledby="titulo-campos" data-campos-vista>
        <h3 id="titulo-campos" class="flex items-center gap-2 text-base font-extrabold text-slate-900"><FileSearch class="w-5 h-5 text-primary-vinotinto" aria-hidden="true" /> Datos que se imprimirían</h3>
        <dl class="divide-y divide-slate-100">
          <div v-for="c in vp.campos" :key="c.clave" class="grid gap-1 py-2.5 sm:grid-cols-[11rem_minmax(0,1fr)]" :data-campo-vista="c.clave" :class="c.se_imprime === false || (c.valor === null && c.se_imprime !== true) ? 'opacity-60' : ''">
            <dt class="text-[12px] font-black uppercase tracking-widest text-slate-400">{{ c.etiqueta }}</dt>
            <dd class="text-sm">
              <span v-if="c.valor !== null" class="font-bold text-slate-900 break-words" :class="c.varias_lineas ? 'whitespace-pre-line block' : ''">{{ c.valor }}</span>
              <span v-else class="font-semibold" :class="c.falta ? 'text-rose-600' : 'text-slate-400'">{{ c.falta ? "Falta este dato" : "Sin dato" }}</span>
              <span v-if="c.se_imprime === true" class="ml-2 text-[11px] font-extrabold text-emerald-700">· se imprime</span>
              <span v-else-if="c.se_imprime === false" class="ml-2 text-[11px] font-semibold text-slate-400">· esta plantilla no lo usa</span>
            </dd>
          </div>
        </dl>
      </section>
        </div>
      </details>

      <p v-if="!vp.problemas.length && plantillaActual !== ''" class="text-sm font-semibold text-slate-600" data-nota-vista>¿Se ve bien? Si está correcto, pulsa el botón de abajo. Si no, corrige el dato y regresarás directo aquí.</p>
    </template>
  </section>
</template>
