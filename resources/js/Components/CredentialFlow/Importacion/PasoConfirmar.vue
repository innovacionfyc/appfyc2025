<script setup>
// Etapa 6 · Crear la base. Resume qué va a pasar y qué NO. La base solo se crea al pulsar «Crear base de participantes»; cambiar de paso, cerrar o recargar no importan nada.
// Crear la base NO emite certificados. Aquí se separan DOS clases de pendientes:
//  · los que IMPIDEN CREAR la base (los dicta el servidor: bloqueos), y
//  · los que NO impiden crearla pero IMPIDEN EMITIR certificados (tipo de documento y elegibilidad por confirmar).
import { computed, ref } from "vue";
import { Link } from "@inertiajs/vue3";
import { CircleCheck, CircleAlert, LoaderCircle, ShieldCheck, ArrowRight, Clock3 } from "lucide-vue-next";
import { BLOQUEOS, DATOS, useAsistente } from "@/Composables/CredentialFlow/importacionInteligente";

const a = useAsistente();
const entiendo = ref(false);
const POR_GRUPO = 4;
const abiertos = ref({});

const r = computed(() => a.resumenParticipantes.value);
const v = computed(() => a.validacion.value);
// Modalidades propias del Excel (los contactos QA se prueban en cada una).
const modalidades = computed(() => Object.keys(v.value?.resumen?.por_modalidad ?? {}));
const bloqueos = computed(() => v.value?.bloqueos ?? []);
const agrupados = computed(() => {
  const m = new Map();
  for (const b of bloqueos.value) {
    const def = BLOQUEOS[b.codigo] ?? { titulo: b.mensaje, paso: "personas" };
    const g = m.get(b.codigo) ?? { codigo: b.codigo, titulo: def.titulo, paso: def.paso, items: [] };
    g.items.push(b);
    m.set(b.codigo, g);
  }
  return [...m.values()];
});
const nombreDato = (campo) => DATOS.find((d) => d.campo === campo)?.etiqueta ?? campo;
const etiquetaItem = (b) => (b.fila != null ? `Fila ${b.fila}` : b.campo ? `Dato «${nombreDato(b.campo)}»` : null);
// El servidor nombra los datos por su clave (intensidad_horaria): aquí se muestran con su nombre de pantalla.
const mensajeItem = (b) => (b.campo && b.fila == null ? b.mensaje.split(`«${b.campo}»`).join(`«${nombreDato(b.campo)}»`) : b.mensaje);
const textoIr = (g, b) => (b.fila != null ? `Revisar la fila ${b.fila}` : b.campo ? "Revisar este dato" : g.paso === "vista" ? "Elegir la plantilla" : "Revisar este dato");

const evento = computed(() => {
  const d = a.evento.value?.decision;
  const c = a.evento.value?.propuesta?.candidatos?.find((x) => x.id === d?.evento_id);
  if (d?.decision === "asociar") return { ok: true, texto: `Se usará el evento que ya existe: ${c?.nombre ?? "evento elegido"}.` };
  if (d?.decision === "crear") return { ok: true, texto: `Se creará un evento nuevo: ${d.nombre ?? a.evento.value?.propuesta?.nombre_excel ?? ""}.` };

  return { ok: false, texto: "Todavía no decidiste a qué evento pertenece." };
});
const plantilla = computed(() => a.props.plantillas.find((p) => p.id === a.plantillaId.value) ?? null);
const qa = a.props.qa;
const sinResolver = computed(() => bloqueos.value.length);
const razones = computed(() => {
  const m = [];
  if (a.desactualizado.value) m.push("El borrador cambió: carga la versión actual.");
  if (a.sinGuardar.value) m.push("Guarda tus cambios.");
  if (sinResolver.value) m.push(`Resuelve ${sinResolver.value === 1 ? "el pendiente" : `los ${sinResolver.value} pendientes`} que impiden crear la base.`);
  if (!entiendo.value) m.push("Marca la casilla de confirmación.");

  return m;
});
const puede = computed(() => razones.value.length === 0 && v.value?.listo === true && !a.guardando.value && !a.confirmando.value && !a.lote.value);
const paraEmitir = computed(() => [
  { id: "tipo", cantidad: r.value.tipoPendiente, texto: (n) => `${n} ${n === 1 ? "persona con tipo de documento por confirmar" : "personas con tipo de documento por confirmar"}`, filtro: "tipo" },
  { id: "eleg", cantidad: r.value.elegibilidadPendiente, texto: (n) => `${n} ${n === 1 ? "persona con elegibilidad pendiente" : "personas con elegibilidad pendiente"}`, filtro: "elegibilidad" },
].filter((x) => x.cantidad > 0));

const crear = () => puede.value && a.confirmar();
const irAFiltro = async (filtro) => {
  if (await a.irA("personas")) a.verPersonas(filtro);
};
</script>

<template>
  <section class="space-y-5" aria-labelledby="titulo-confirmar">
    <div>
      <h2 id="titulo-confirmar" tabindex="-1" class="text-xl font-extrabold text-slate-900 focus:outline-none">Revisa y crea la base</h2>
      <p class="mt-1 text-sm font-medium text-slate-500">Esto crea la lista de participantes. <strong>No emite certificados</strong> ni envía correos.</p>
    </div>

    <div v-if="a.lote.value" class="rounded-3xl border border-emerald-300 bg-emerald-50 p-6 space-y-4" role="status" data-exito>
      <p class="flex items-center gap-2 text-lg font-extrabold text-emerald-900"><CircleCheck class="w-6 h-6" aria-hidden="true" /> La base de participantes ya está creada</p>
      <p class="text-sm font-semibold text-emerald-900/90">Se creó una sola vez. No se emitió ningún certificado: eso se hace después, desde la base.</p>
      <div class="flex flex-wrap gap-3">
        <Link :href="a.lote.value.url" class="inline-flex items-center gap-2 rounded-2xl bg-primary-vinotinto px-5 py-3 text-sm font-extrabold text-white hover:brightness-110" data-ver-base>Ver la base creada <ArrowRight class="w-4 h-4" aria-hidden="true" /></Link>
        <button type="button" class="rounded-2xl px-5 py-3 text-sm font-extrabold text-slate-700 ring-1 ring-slate-300 hover:bg-white" @click="a.nuevoArchivo()">Importar otro Excel</button>
      </div>
    </div>

    <template v-else>
      <!-- Estado general: ¿se puede crear la base? -->
      <div v-if="sinResolver === 0" class="flex items-start gap-3 rounded-2xl border border-emerald-300 bg-emerald-50 px-4 py-3" role="status" data-estado-listo>
        <CircleCheck class="w-5 h-5 shrink-0 mt-0.5 text-emerald-600" aria-hidden="true" />
        <p class="text-sm font-extrabold text-emerald-900">Todo lo necesario para crear la base está resuelto.</p>
      </div>
      <div v-else class="flex items-start gap-3 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3" role="status" data-estado-listo>
        <CircleAlert class="w-5 h-5 shrink-0 mt-0.5 text-amber-600" aria-hidden="true" />
        <p class="text-sm font-extrabold text-amber-900">Faltan {{ sinResolver }} {{ sinResolver === 1 ? "cosa" : "cosas" }} para poder crear la base (abajo, «Antes de crear la base»).</p>
      </div>

      <ul class="space-y-2" data-resumen-confirmar>
        <li class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3" data-resumen="evento">
          <component :is="evento.ok ? CircleCheck : CircleAlert" class="w-5 h-5 shrink-0 mt-0.5" :class="evento.ok ? 'text-emerald-600' : 'text-amber-600'" aria-hidden="true" />
          <span class="text-sm"><strong class="text-slate-800">Evento.</strong> <span class="font-semibold text-slate-600 break-words">{{ evento.texto }}</span></span>
        </li>
        <li class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3" data-resumen="participantes">
          <CircleCheck class="w-5 h-5 shrink-0 mt-0.5 text-emerald-600" aria-hidden="true" />
          <span class="text-sm"><strong class="text-slate-800">Participantes.</strong> <span class="font-semibold text-slate-600">Se importarán {{ r.incluidos }}<template v-if="r.excluidos"> (excluiste {{ r.excluidos }})</template>.</span></span>
        </li>
        <li class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3" data-resumen="revisiones">
          <component :is="bloqueos.some((b) => ['valores_desactualizados_sin_revisar', 'valores_ambiguos_sin_revisar', 'dato_comun_sin_confirmar'].includes(b.codigo)) ? CircleAlert : CircleCheck" class="w-5 h-5 shrink-0 mt-0.5" :class="bloqueos.some((b) => ['valores_desactualizados_sin_revisar', 'valores_ambiguos_sin_revisar', 'dato_comun_sin_confirmar'].includes(b.codigo)) ? 'text-amber-600' : 'text-emerald-600'" aria-hidden="true" />
          <span class="text-sm"><strong class="text-slate-800">Advertencias.</strong>{{ " " }}<span class="font-semibold text-slate-600">{{ bloqueos.some((b) => ['valores_desactualizados_sin_revisar', 'valores_ambiguos_sin_revisar', 'dato_comun_sin_confirmar'].includes(b.codigo)) ? "Todavía hay datos dudosos o posiblemente desactualizados por revisar." : "Ya revisaste los datos dudosos o posiblemente desactualizados." }}</span></span>
        </li>
        <li class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3" data-resumen="plantilla">
          <component :is="plantilla ? CircleCheck : CircleAlert" class="w-5 h-5 shrink-0 mt-0.5" :class="plantilla ? 'text-emerald-600' : 'text-amber-600'" aria-hidden="true" />
          <span class="text-sm"><strong class="text-slate-800">Plantilla.</strong> <span class="font-semibold text-slate-600">{{ plantilla ? plantilla.nombre : "Todavía no elegiste la plantilla." }}</span></span>
        </li>
        <li class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3" data-resumen="qa">
          <ShieldCheck class="w-5 h-5 shrink-0 mt-0.5" :class="qa.listo ? 'text-emerald-600' : 'text-amber-600'" aria-hidden="true" />
          <span class="text-sm"><strong class="text-slate-800">Contactos de control (QA).</strong>{{ " " }}<span class="font-semibold text-slate-600">
              <template v-if="a.props.qa_automatico">
                Se agregarán solos los {{ qa.activos }} contactos de control a la base<template v-if="modalidades.length > 1">, una vez por cada modalidad: {{ qa.activos * modalidades.length }} pruebas internas ({{ modalidades.map((m) => `${qa.activos} ${m}`).join(" y ") }})</template>; si alguno no entra, la base no se crea. No cuentan como participantes reales.
              </template>
              <template v-else>La incorporación automática de contactos de control está apagada.</template>
            </span>
          </span>
        </li>
      </ul>

      <!-- 1 · Pendientes que IMPIDEN crear la base -->
      <section v-if="agrupados.length" class="rounded-3xl border border-amber-300 bg-amber-50 p-5 space-y-3" aria-labelledby="titulo-bloqueos" data-bloqueos>
        <div>
          <h3 id="titulo-bloqueos" class="flex items-center gap-2 text-base font-extrabold text-amber-900"><CircleAlert class="w-5 h-5" aria-hidden="true" /> Antes de crear la base</h3>
          <p class="text-[13px] font-semibold text-amber-900/80">{{ sinResolver === 1 ? "Falta 1 cosa" : `Faltan ${sinResolver} cosas` }} que <strong>impiden crear la base</strong>. Cada botón «Revisar este dato» te lleva al lugar exacto.</p>
        </div>
        <ul class="space-y-2">
          <li v-for="g in agrupados" :key="g.codigo" class="rounded-2xl bg-white/80 px-4 py-3" :data-bloqueo="g.codigo">
            <p class="text-sm font-extrabold text-amber-950">{{ g.titulo }}<span v-if="g.items.length > 1" class="ml-1 font-semibold text-amber-800">({{ g.items.length }})</span></p>
            <ul class="mt-1 space-y-1">
              <li v-for="(b, i) in g.items.slice(0, abiertos[g.codigo] ? g.items.length : POR_GRUPO)" :key="i" class="flex flex-wrap items-start justify-between gap-x-3 gap-y-0.5">
                <span class="min-w-0 flex-1 basis-56 text-[12px] font-semibold text-amber-900/80 break-words"><strong v-if="etiquetaItem(b)" class="text-amber-950">{{ etiquetaItem(b) }}: </strong>{{ mensajeItem(b) }}</span>
                <button v-if="g.paso !== 'confirmar'" type="button" class="shrink-0 rounded-lg px-2 py-0.5 text-[12px] font-extrabold text-primary-vinotinto underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50" :data-ir="g.codigo" @click="a.resolver(b)">{{ textoIr(g, b) }}</button>
                <Link v-else :href="route('credential-flow.qa.contactos')" class="shrink-0 rounded-lg px-2 py-0.5 text-[12px] font-extrabold text-primary-vinotinto underline-offset-2 hover:underline">Revisar contactos QA</Link>
              </li>
            </ul>
            <button v-if="g.items.length > POR_GRUPO && !abiertos[g.codigo]" type="button" class="mt-1 text-[12px] font-extrabold text-amber-900 underline-offset-2 hover:underline" @click="abiertos[g.codigo] = true">Ver los {{ g.items.length - POR_GRUPO }} restantes</button>
          </li>
        </ul>
      </section>

      <!-- 2 · Elegibilidad y tipo de documento: se resuelven ANTES de crear la base, para que todos puedan recibir su certificado -->
      <section class="rounded-3xl border border-slate-200 bg-slate-50 p-5 space-y-2" aria-labelledby="titulo-emitir" data-pendientes-emitir>
        <h3 id="titulo-emitir" class="flex items-center gap-2 text-base font-extrabold text-slate-800"><Clock3 class="w-5 h-5 text-slate-500" aria-hidden="true" /> Listos para emitir</h3>
        <p class="text-[13px] font-semibold text-slate-600">Las personas del Excel quedan elegibles. La elegibilidad «Pendiente» y el tipo de documento por confirmar se resuelven <strong>antes de crear la base</strong>, para que nadie se quede sin certificado sin que lo veas.</p>
        <ul v-if="paraEmitir.length" class="space-y-1">
          <li v-for="x in paraEmitir" :key="x.id" class="flex flex-wrap items-start justify-between gap-x-3 text-[13px] font-semibold text-slate-700" :data-para-emitir="x.id">
            <span class="min-w-0 flex-1 basis-56">{{ x.texto(x.cantidad) }}</span>
            <button type="button" class="shrink-0 rounded-lg px-2 py-0.5 text-[12px] font-extrabold text-primary-vinotinto underline-offset-2 hover:underline" @click="irAFiltro(x.filtro)">Resolver ahora</button>
          </li>
        </ul>
        <p v-else class="text-[13px] font-semibold text-emerald-700">Todas las personas podrán recibir su certificado.</p>
      </section>

      <details class="rounded-3xl border border-slate-200 bg-white p-5" data-no-se-guarda>
        <summary class="cursor-pointer text-sm font-extrabold text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 rounded">Ver detalles: lo que NO quedará guardado</summary>
        <ul class="mt-2 list-disc pl-5 text-[13px] font-semibold text-slate-600 space-y-1">
          <li>El archivo Excel original: solo se recuerda su huella para detectar si se vuelve a subir.</li>
          <li>Las observaciones del Excel (todavía no hay dónde conservarlas).</li>
          <li>La columna «Diploma» y el detalle de sesiones: se usaron solo para orientarte.</li>
          <li>Las fórmulas y las hojas externas del Excel.</li>
          <li>El borrador: sus datos personales se borran al crear la base.</li>
        </ul>
      </details>

      <p v-if="a.error.value && a.paso.value === 'confirmar'" class="rounded-2xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm font-bold text-rose-700" role="alert" data-error-confirmar>{{ a.error.value.mensaje }}</p>

      <div class="rounded-3xl border border-slate-200 bg-white p-5 space-y-4">
        <label class="flex items-start gap-3 text-sm font-semibold text-slate-700 cursor-pointer">
          <input v-model="entiendo" type="checkbox" class="mt-0.5 rounded accent-[#942934]" data-entiendo />
          <span>Entiendo que se creará la base de participantes y que <strong>no se emitirá ningún certificado</strong>. Esta acción se hace una sola vez.</span>
        </label>
        <button
          type="button"
          :disabled="!puede"
          class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-2xl bg-primary-vinotinto px-7 py-3.5 text-sm font-extrabold text-white shadow-sm transition-all hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 disabled:opacity-50 disabled:cursor-not-allowed"
          data-accion="crear-base"
          @click="crear"
        >
          <LoaderCircle v-if="a.confirmando.value" class="w-4 h-4 animate-spin" aria-hidden="true" />
          {{ a.confirmando.value ? "Creando la base…" : "Crear base de participantes" }}
        </button>
        <div v-if="razones.length" class="text-[12px] font-semibold text-slate-500" data-razones>
          <p class="font-extrabold text-slate-600">Para activar el botón:</p>
          <ul class="list-disc pl-5"><li v-for="m in razones" :key="m">{{ m }}</li></ul>
        </div>
      </div>
    </template>
  </section>
</template>
