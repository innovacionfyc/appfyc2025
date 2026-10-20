<script setup>
// Etapa 3 · Datos del certificado, UNA tarea a la vez («Confirma la fecha», «Confirma la intensidad»…). Solo se piden los datos que necesitan tu ojo:
// los dudosos, los que se obtuvieron de otra hoja y los obligatorios que faltan. Nada se confirma solo: cada «Sí, es correcta» es un clic tuyo y se guarda al instante.
// La acción principal de esta etapa vive en la barra fija de abajo (una sola); aquí adentro solo se muestra y se explica el dato.
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { ArrowLeft, ArrowRight, CircleCheck, TriangleAlert } from "lucide-vue-next";
import EstadoDato from "./EstadoDato.vue";
import TarjetaDatos from "./TarjetaDatos.vue";
import { NOMBRES_DATOS, nombreEnFrase, registrarPrincipal, useAsistente } from "@/Composables/CredentialFlow/importacionInteligente";

const a = useAsistente();
const IMPORTRANGE = "__personas";

// La lista de tareas se arma al entrar (así no cambia mientras escribes) y solo crece si aparece algo nuevo por revisar.
const lista = ref([]);
const idx = ref(null); // null = ninguna tarea abierta (todo listo)
const verTodos = ref(false);

const agregar = (campo) => campo && !lista.value.includes(campo) && lista.value.push(campo);
const sincronizar = () => {
  a.datosPendientes.value.forEach((d) => agregar(d.campo));
  if (a.importrangePendiente.value) agregar(IMPORTRANGE);
};
const hecha = (campo) => (campo === IMPORTRANGE ? !a.importrangePendiente.value : !a.datosPendientes.value.some((d) => d.campo === campo));
const dato = (campo) => a.datos.value.find((d) => d.campo === campo) ?? null;
const etiquetaTarea = (campo) => (campo === IMPORTRANGE ? "Datos de otra hoja" : (dato(campo)?.etiqueta ?? campo));
const enfocarEntrada = async () => {
  await nextTick();
  document.querySelector("[data-tarea-input]")?.focus({ preventScroll: true });
};

function abrirPrimeraPendiente() {
  const i = lista.value.findIndex((c) => !hecha(c));
  idx.value = i >= 0 ? i : null;
}

onMounted(async () => {
  sincronizar();
  const pedido = a.datoActivo.value;
  a.datoActivo.value = null;
  if (pedido) {
    agregar(pedido);
    idx.value = lista.value.indexOf(pedido);
    await enfocarEntrada();
  } else abrirPrimeraPendiente();
});
// Si desde otro lugar piden «Revisar este dato», se abre esa tarea.
watch(
  () => a.datoActivo.value,
  async (campo) => {
    if (!campo) return;
    a.datoActivo.value = null;
    agregar(campo);
    idx.value = lista.value.indexOf(campo);
    await enfocarEntrada();
  }
);
watch(() => a.datosPendientes.value.length + (a.importrangePendiente.value ? 1 : 0), sincronizar);

const campo = computed(() => (idx.value === null ? null : lista.value[idx.value]));
const d = computed(() => (campo.value && campo.value !== IMPORTRANGE ? dato(campo.value) : null));
const total = computed(() => lista.value.length);
const hechas = computed(() => lista.value.filter(hecha).length);

const pregunta = computed(() => {
  if (campo.value === IMPORTRANGE) return "Parte de la información se obtuvo de otra hoja";
  const x = d.value;
  if (!x) return "";
  if (x.valor === "" || x.valor === null) return `Escribe ${nombreEnFrase(x)}`;

  return x.estado === "revisar" ? `¿Es ${NOMBRES_DATOS[x.campo]?.[1] ?? "correcto"} ${nombreEnFrase(x)}?` : `Revisa ${nombreEnFrase(x)}`;
});
const vacio = computed(() => d.value && String(d.value.valor ?? "").trim() === "");
const botonConfirmar = computed(() => (d.value?.estado === "revisar" && !d.value?.editado ? "Sí, es correcta" : "Guardar y seguir"));

function siguiente() {
  const i = idx.value;
  const resto = lista.value.findIndex((c, j) => j > i && !hecha(c));
  if (resto >= 0) idx.value = resto;
  else {
    const antes = lista.value.findIndex((c, j) => j < i && !hecha(c));
    idx.value = antes >= 0 ? antes : null;
  }
}

/** Tras guardar: si venías corregir algo desde otra etapa (por ejemplo, el PDF), regresas directo; si no, sigues con la siguiente tarea. */
async function despuesDeGuardar() {
  if (a.origen.value) return a.volverAlOrigen();
  siguiente();
  await enfocarEntrada();
}

async function confirmar() {
  const x = d.value;
  if (!x || vacio.value || a.guardando.value) return;
  if (x.origen !== "admin") a.confirmarDato(x.campo, true);
  const texto = `Confirmaste ${nombreEnFrase(x)}: «${String(x.valor).length > 60 ? String(x.valor).slice(0, 60) + "…" : x.valor}».`;
  if (!(await a.guardar())) return;
  a.hecho.value = texto;
  await despuesDeGuardar();
}

async function confirmarOtraHoja() {
  if (a.guardando.value) return;
  a.marcarConfirmacion("valores_desactualizados", true);
  if (!(await a.guardar())) return;
  a.hecho.value = "Comprobaste que la información de otra hoja está actualizada.";
  await despuesDeGuardar();
}

function ir(delta) {
  const j = (idx.value ?? lista.value.length) + delta;
  if (j >= 0 && j < lista.value.length) idx.value = j;
}
const continuar = async () => {
  if (await a.irA("personas")) a.hecho.value = "Confirmaste los datos del certificado.";
};

// La ÚNICA acción principal de la etapa (barra de abajo).
registrarPrincipal(a, () => {
  const c = campo.value;
  const pendientes = lista.value.some((t) => !hecha(t));
  const salida = () => {
    if (a.origen.value) return { texto: `Volver a «${a.origen.value.titulo}»`, accion: () => a.volverAlOrigen() };
    if (pendientes) return { texto: "Ir a la siguiente tarea", accion: () => abrirPrimeraPendiente() };

    return { texto: "Continuar", accion: continuar };
  };
  if (c === null) return salida();
  if (c === IMPORTRANGE) return hecha(c) ? salida() : { texto: "Ya lo comprobé: está actualizado", accion: confirmarOtraHoja };
  const x = d.value;
  if (!x) return salida();
  if (vacio.value) return { texto: `Escribir ${nombreEnFrase(x)}`, accion: enfocarEntrada, razon: `Escribe ${nombreEnFrase(x)} en el cuadro para poder guardarlo.` };
  if (!hecha(c) || x.editado) return { texto: a.origen.value ? `Guardar y volver a «${a.origen.value.titulo}»` : botonConfirmar.value, accion: confirmar };

  return salida();
});
</script>

<template>
  <section class="space-y-5" aria-labelledby="titulo-etapa-datos">
    <div>
      <h2 id="titulo-etapa-datos" tabindex="-1" class="text-xl font-extrabold text-slate-900 focus:outline-none">Confirma lo que dirá el certificado</h2>
      <p class="mt-1 text-sm font-medium text-slate-500">
        <template v-if="total">Solo te pedimos revisar {{ total === 1 ? "1 cosa" : `${total} cosas` }}, una por una. Lo que detectamos con seguridad ya está listo.</template>
        <template v-else>Detectamos todos los datos con seguridad. Revísalos abajo si quieres y continúa.</template>
      </p>
    </div>

    <!-- Una tarea a la vez -->
    <section v-if="campo" class="rounded-3xl border border-slate-200 bg-white p-5 sm:p-6 space-y-4" aria-labelledby="titulo-tarea" data-tarea>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-[12px] font-black uppercase tracking-widest text-slate-400" data-tarea-contador>Tarea {{ idx + 1 }} de {{ total }} <span class="normal-case tracking-normal font-semibold text-slate-400">· {{ hechas }} {{ hechas === 1 ? "lista" : "listas" }}</span></p>
        <EstadoDato v-if="d" :estado="d.estado" />
        <span v-else-if="hecha(campo)" class="inline-flex items-center gap-1 text-[12px] font-extrabold text-emerald-700"><CircleCheck class="w-4 h-4" aria-hidden="true" /> Confirmado</span>
      </div>

      <!-- Información de las personas que se obtuvo de otra hoja (IMPORTRANGE de Google Sheets) -->
      <div v-if="campo === IMPORTRANGE" class="space-y-3" data-tarea-importrange>
        <h3 id="titulo-tarea" class="flex items-start gap-2 text-lg font-extrabold text-slate-900"><TriangleAlert class="w-5 h-5 shrink-0 mt-0.5 text-amber-600" aria-hidden="true" /> {{ pregunta }}</h3>
        <p class="text-sm font-semibold text-slate-600">
          {{ a.personasDesactualizadas.value }} datos de las personas (nombre, documento, correo y asistencia) vienen de otra hoja de Google Sheets. <strong>Comprueba que estén actualizados antes de continuar.</strong>
        </p>
        <details class="rounded-xl bg-slate-50 px-3 py-2 text-[13px]" data-como-compruebo>
          <summary class="cursor-pointer font-extrabold text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 rounded">¿Cómo lo compruebo?</summary>
          <p class="mt-2 font-semibold text-slate-600">Abre la hoja de Google Sheets de donde sale esta lista y compara a unas cuantas personas: nombre, documento, correo y asistencia. Si algo no coincide, descarga el Excel actualizado y súbelo otra vez.</p>
        </details>
        <p v-if="hecha(campo)" class="flex items-center gap-2 text-sm font-extrabold text-emerald-700"><CircleCheck class="w-5 h-5" aria-hidden="true" /> Ya lo comprobaste.</p>
        <p v-else class="text-[13px] font-semibold text-slate-500" data-razon-otra-hoja>Cuando lo hayas comprobado, pulsa «Ya lo comprobé: está actualizado» (botón de abajo).</p>
      </div>

      <!-- Un dato del certificado -->
      <div v-else-if="d" class="space-y-3" :data-tarea-dato="d.campo">
        <h3 id="titulo-tarea" class="text-lg font-extrabold text-slate-900">{{ pregunta }}</h3>
        <label :for="`tarea-${d.campo}`" class="sr-only">{{ d.etiqueta }}</label>
        <textarea v-if="d.largo" :id="`tarea-${d.campo}`" rows="3" maxlength="600" :value="d.valor" class="w-full rounded-xl border-slate-300 text-base font-semibold focus:border-primary-vinotinto focus:ring-primary-vinotinto/30" data-tarea-input data-recomendado @input="a.editarDato(d.campo, $event.target.value)"></textarea>
        <input v-else :id="`tarea-${d.campo}`" type="text" maxlength="255" :value="d.valor" class="w-full rounded-xl border-slate-300 text-base font-semibold focus:border-primary-vinotinto focus:ring-primary-vinotinto/30" data-tarea-input data-recomendado @input="a.editarDato(d.campo, $event.target.value)" />
        <p class="text-[12.5px] font-medium text-slate-500">{{ d.ayuda }}</p>
        <p v-if="a.erroresCampo[`dato:${d.campo}`]" class="text-[13px] font-bold text-rose-600" role="alert">{{ a.erroresCampo[`dato:${d.campo}`] }}</p>
        <p v-if="d.desactualizable && d.origen === 'excel'" class="flex items-start gap-2 text-[13px] font-semibold text-amber-800"><TriangleAlert class="w-4 h-4 shrink-0 mt-0.5" aria-hidden="true" /> Este dato se obtuvo de otra hoja. Comprueba que esté actualizado.</p>
        <p v-else-if="d.estado === 'revisar'" class="flex items-start gap-2 text-[13px] font-semibold text-amber-800"><TriangleAlert class="w-4 h-4 shrink-0 mt-0.5" aria-hidden="true" /> No lo detectamos con total seguridad: confírmalo o corrígelo.</p>
        <p v-if="vacio" class="text-[12.5px] font-bold text-amber-700" data-razon-dato>Escribe {{ nombreEnFrase(d) }} en el cuadro para poder guardarlo.</p>
        <p v-else-if="!hecha(campo) || d.editado" class="text-[12.5px] font-semibold text-slate-500">Cuando esté bien, pulsa «{{ botonConfirmar }}» (botón de abajo).</p>

        <details class="rounded-xl bg-slate-50 px-3 py-2 text-[12.5px]" data-detalles-dato>
          <summary class="cursor-pointer font-extrabold text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 rounded">Ver detalles</summary>
          <dl class="mt-2 space-y-1 font-semibold text-slate-600">
            <div v-if="d.original"><dt class="inline font-black uppercase tracking-widest text-slate-400">El Excel decía: </dt><dd class="inline break-words">{{ d.original }}</dd></div>
            <div v-if="d.referencia"><dt class="inline font-black uppercase tracking-widest text-slate-400">Celda: </dt><dd class="inline">{{ d.referencia }}</dd></div>
            <div v-if="d.origen === 'admin' && d.propuesta"><dt class="inline font-black uppercase tracking-widest text-slate-400">Lo que traía el Excel: </dt><dd class="inline break-words">{{ d.propuesta }}</dd> <button type="button" class="ml-1 font-extrabold text-primary-vinotinto underline" @click="a.editarDato(d.campo, '')">Restaurar</button></div>
          </dl>
        </details>
      </div>

      <div class="flex items-center justify-between gap-2 border-t border-slate-100 pt-3 text-[12.5px] font-extrabold">
        <button type="button" :disabled="idx === 0" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-slate-600 hover:bg-slate-50 disabled:opacity-40" data-tarea-anterior @click="ir(-1)"><ArrowLeft class="w-3.5 h-3.5" aria-hidden="true" /> Tarea anterior</button>
        <button type="button" :disabled="idx >= total - 1" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-slate-600 hover:bg-slate-50 disabled:opacity-40" data-tarea-siguiente @click="ir(1)">Tarea siguiente <ArrowRight class="w-3.5 h-3.5" aria-hidden="true" /></button>
      </div>
    </section>

    <!-- Todo listo -->
    <section v-else class="rounded-3xl border border-emerald-300 bg-emerald-50/60 p-5 sm:p-6 space-y-3" aria-labelledby="titulo-datos-listos" data-datos-listos>
      <h3 id="titulo-datos-listos" class="flex items-center gap-2 text-lg font-extrabold text-emerald-900"><CircleCheck class="w-6 h-6 text-emerald-600" aria-hidden="true" /> Los datos del certificado están confirmados</h3>
      <ul class="space-y-1 text-sm font-semibold text-slate-700">
        <li v-for="x in a.datos.value.filter((y) => y.valor !== '' && y.valor !== null)" :key="x.campo" class="flex flex-wrap items-baseline gap-x-2" :data-resumen-dato="x.campo">
          <span class="font-black uppercase tracking-widest text-[11px] text-slate-400">{{ x.etiqueta }}</span>
          <span class="break-words">{{ x.valor }}</span>
        </li>
      </ul>
      <p class="text-[13px] font-semibold text-slate-500">¿Algo no está bien? Ábrelo abajo en «Ver y editar todos los datos». Lo que corrijas se guarda. Para seguir, pulsa el botón de abajo.</p>
    </section>

    <!-- Lista corta de tareas -->
    <ol v-if="total > 1" class="flex flex-wrap gap-2" aria-label="Tareas de esta etapa" data-lista-tareas>
      <li v-for="(c, i) in lista" :key="c">
        <button type="button" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[12px] font-extrabold ring-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50" :class="i === idx ? 'bg-primary-vinotinto text-white ring-primary-vinotinto' : hecha(c) ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-white text-slate-600 ring-slate-300'" :aria-current="i === idx ? 'step' : undefined" @click="idx = i">
          <CircleCheck v-if="hecha(c)" class="w-3.5 h-3.5" aria-hidden="true" />{{ etiquetaTarea(c) }}
        </button>
      </li>
    </ol>

    <details class="rounded-2xl border border-slate-200 bg-white px-4 py-3" :open="verTodos" @toggle="verTodos = $event.target.open" data-ver-todos-datos>
      <summary class="cursor-pointer text-[13px] font-extrabold text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 rounded">Ver y editar todos los datos del certificado (opcional)</summary>
      <p class="mt-2 mb-3 text-[12.5px] font-semibold text-slate-500">Aquí también puedes agregar la ciudad o los textos de certificación, si tu plantilla los imprime. Si falta alguno, te lo avisamos cuando veas el PDF.</p>
      <TarjetaDatos v-if="verTodos" />
    </details>
  </section>
</template>
