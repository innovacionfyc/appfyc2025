<script setup>
import { computed, ref, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { TriangleAlert, Info } from "lucide-vue-next";

// Decisiones de identidad (10B-3A). REGISTRAN una decisión humana; NO cambian el acceso al portal. Nombres y correos llegan enmascarados.
const props = defineProps({ identidad: { type: Object, required: true } });
const id = props.identidad;

const CAJA = "bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6";
const ETQ = "text-[11px] uppercase tracking-wide font-black text-slate-400";
const CAMPO = "mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700";
const TIPOS = {
  misma_persona: "Misma persona",
  personas_distintas: "Personas distintas",
  correo_autorizado: "Autorizar un correo para un grupo",
  requiere_soporte: "Requiere soporte",
  no_resoluble: "No resoluble con los datos actuales",
};
const ESTADOS = { vigente: "Vigente", revocada: "Revocada" };
// Caso de un GRUPO SIN VÍA (10B-3C-1): solo se ofrece autorizar el correo compartido para ese grupo (o soporte); no hace falta «misma persona».
const sinVia = id.modo === "grupo_sin_via";
const gruposElegibles = sinVia ? id.grupos.filter((g) => (id.objetivos ?? []).includes(g.grupo_hash)) : id.grupos;
const etiquetaTipo = (t) => (sinVia && t === "correo_autorizado" ? "Autorizar correo para este grupo" : TIPOS[t]);

const form = useForm({ tipo: id.tipos[0] ?? "", motivo: "", grupos: [], grupo: "", correo_hmac: "", evidencia: "", confirmo: false, reforzada: false, evidencia_externa: false, alcance_masivo: false, fuente_evidencia: "" });
// Autorización MASIVA con doble control (10B-3C-3): un grupo con >= umbral certificados NO se autoriza de inmediato; se SOLICITA y otro administrador la aprueba.
const infoGrupo = computed(() => id.masivo?.grupos?.[form.grupo] ?? null);
const masivoSolicitud = computed(() => form.tipo === "correo_autorizado" && !!infoGrupo.value);
const grupoElegido = computed(() => id.grupos.find((g) => g.grupo_hash === form.grupo) ?? null);
const correoElegido = computed(() => grupoElegido.value?.correos.find((c) => c.hmac === form.correo_hmac) ?? null);
const afectados = computed(() => {
  if (form.tipo === "correo_autorizado") return grupoElegido.value?.certificados ?? 0;
  return id.grupos.filter((g) => form.grupos.includes(g.grupo_hash)).reduce((s, g) => s + g.certificados, 0);
});
// Modelo 1 acotado (misma regla que ModeloAcotado en el servidor): correos que podrían habilitar el acceso del scope elegido, sin autorización adicional.
const candidatos = computed(() => {
  const sel = id.grupos.filter((g) => form.grupos.includes(g.grupo_hash));
  const gruposDe = {};
  id.grupos.forEach((g) => g.correos.forEach((c) => ((gruposDe[c.hmac] ??= new Set()).add(g.grupo_hash))));
  const out = new Map();
  sel.forEach((g) => g.correos.forEach((c) => { if ([...gruposDe[c.hmac]].every((h) => form.grupos.includes(h))) out.set(c.hmac, c.mascara); }));
  return [...out.entries()].map(([hmac, mascara]) => ({ hmac, mascara }));
});
const exigeCorreoExplicito = computed(() => form.tipo === "misma_persona" && form.grupos.length >= 2 && (candidatos.value.length !== 1 || afectados.value >= id.umbral_masivo));
const masivo = computed(() => ["misma_persona", "correo_autorizado"].includes(form.tipo) && afectados.value >= id.umbral_masivo);
const pideExterna = computed(() => form.tipo === "misma_persona" && (id.riesgos.mismo_evento || id.riesgos.nombres_distintos));
const pideEvidencia = computed(() => ["misma_persona", "correo_autorizado"].includes(form.tipo));
const motivoOk = computed(() => form.motivo.trim().length >= id.motivo_min);
const evidenciaOk = computed(() => !pideEvidencia.value || (form.evidencia.trim().length >= id.evidencia_min && form.evidencia.trim().length <= id.evidencia_max));
const puede = computed(() => {
  if (form.processing || !motivoOk.value || !evidenciaOk.value) return false;
  if (form.tipo === "misma_persona") return form.grupos.length >= 2 && form.confirmo && (!pideExterna.value || form.evidencia_externa) && (!masivo.value || form.alcance_masivo);
  if (form.tipo === "personas_distintas") return form.grupos.length >= 2 && form.confirmo;
  if (form.tipo === "correo_autorizado") return !!form.grupo && !!form.correo_hmac && (!correoElegido.value?.compartido || form.reforzada) && (!masivo.value || form.alcance_masivo) && (!masivoSolicitud.value || (form.evidencia_externa && !!form.fuente_evidencia));
  return true;
});
watch(() => form.grupo, () => (form.correo_hmac = ""));
watch(() => form.tipo, () => form.reset("grupos", "grupo", "correo_hmac", "confirmo", "reforzada", "evidencia_externa", "alcance_masivo", "fuente_evidencia"));

const enviar = () => form.post(id.ruta_crear, { preserveScroll: true, onSuccess: () => form.reset() });

const revocar = ref(null);
const formRevocar = useForm({ motivo: "" });
const revocarOk = computed(() => formRevocar.motivo.trim().length >= id.motivo_min && !formRevocar.processing);
const confirmarRevocar = () => formRevocar.post(id.rutas_revocar[revocar.value], { preserveScroll: true, onSuccess: () => { revocar.value = null; formRevocar.reset(); } });
// Segunda aprobación / revocación de la aprobación (solo decisiones masivas).
const aprobando = ref(null);
const formAprobar = useForm({ motivo: "", confirmo_masiva: false });
const aprobarOk = computed(() => formAprobar.motivo.trim().length >= id.motivo_min && formAprobar.confirmo_masiva && !formAprobar.processing);
const confirmarAprobar = () => formAprobar.post(id.rutas_aprobar[aprobando.value], { preserveScroll: true, onSuccess: () => { aprobando.value = null; formAprobar.reset(); } });
const revocandoAprob = ref(null);
const formRevAprob = useForm({ motivo: "" });
const revAprobOk = computed(() => formRevAprob.motivo.trim().length >= id.motivo_min && !formRevAprob.processing);
const confirmarRevAprob = () => formRevAprob.post(id.rutas_revocar_aprobacion[revocandoAprob.value], { preserveScroll: true, onSuccess: () => { revocandoAprob.value = null; formRevAprob.reset(); } });
const APROB = { pendiente: "Pendiente de segunda aprobación", aprobada: "Aprobada", revocada: "Aprobación revocada" };
const fecha = (v) => (v ? String(v).slice(0, 16).replace("T", " ") : "—");
</script>

<template>
  <section :class="[CAJA, 'lg:col-span-2']" data-identidad-decisiones>
    <h3 class="text-base font-extrabold text-slate-900">Decisiones de identidad</h3>
    <p class="mt-2 text-sm font-semibold text-slate-600 flex items-start gap-2" data-registra-no-autoriza><Info class="w-4 h-4 mt-0.5 shrink-0" /> {{ id.registra_no_autoriza }}</p>
    <p v-for="(m, i) in id.mensajes_sin_via ?? []" :key="'sv' + i" class="mt-3 flex items-start gap-2 text-sm font-semibold text-slate-700" data-mensaje-sin-via><Info class="w-4 h-4 mt-0.5 shrink-0" /> {{ m }}</p>
    <p v-for="a in id.avisos" :key="a.codigo" class="mt-3 flex items-start gap-2 text-sm font-semibold text-rose-700" :data-aviso="a.codigo"><TriangleAlert class="w-4 h-4 mt-0.5 shrink-0" /> {{ a.texto }}</p>

    <!-- Evidencia por grupo: solo contexto; ninguna de estas señales prueba identidad por sí sola. -->
    <div class="mt-5 grid gap-3 md:grid-cols-2" data-grupos-identidad>
      <article v-for="g in id.grupos" :key="g.grupo_hash" class="rounded-2xl border border-slate-100 p-4 text-sm" :data-grupo="g.ref">
        <p class="font-extrabold text-slate-800">{{ g.etiqueta }} · <span class="font-mono text-xs text-slate-500">{{ g.ref }}</span><span v-if="sinVia && (id.objetivos ?? []).includes(g.grupo_hash)" class="ml-2 text-xs font-bold text-amber-700" data-grupo-objetivo>grupo sin vía</span></p>
        <p class="mt-1 font-semibold text-slate-600">{{ g.nombre }}</p>
        <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs font-semibold text-slate-600">
          <div><dt :class="ETQ">Certificados</dt><dd>{{ g.certificados }}</dd></div>
          <div><dt :class="ETQ">Eventos</dt><dd>{{ g.eventos }}<span v-if="g.anios.desde"> · {{ g.anios.desde }}<template v-if="g.anios.hasta !== g.anios.desde">–{{ g.anios.hasta }}</template></span></dd></div>
          <div><dt :class="ETQ">Descargas</dt><dd>{{ g.descargas }}</dd></div>
          <div><dt :class="ETQ">Códigos</dt><dd>{{ g.codigos }}</dd></div>
          <div><dt :class="ETQ">Encuestas</dt><dd>{{ g.encuestas }}</dd></div>
          <div><dt :class="ETQ">Vía propia</dt><dd>{{ g.via_individual ? "Sí" : "No" }}</dd></div>
        </dl>
        <p class="mt-2 text-xs font-semibold text-slate-500">Estados: <span v-for="(n, e) in g.estados" :key="e" class="mr-2">{{ e }} ({{ n }})</span></p>
        <p class="mt-1 text-xs font-semibold text-slate-500">Correos:
          <span v-if="!g.correos.length">ninguno válido</span>
          <span v-for="c in g.correos" :key="c.hmac" class="mr-2 font-mono">{{ c.mascara }}<em v-if="c.compartido" class="not-italic text-amber-700"> (compartido)</em></span>
        </p>
      </article>
    </div>

    <!-- Nueva decisión -->
    <form v-if="id.tipos.length" class="mt-6 space-y-4" data-form-decision @submit.prevent="enviar">
      <div>
        <label :class="ETQ" for="tipo-decision">Decisión</label>
        <select id="tipo-decision" v-model="form.tipo" :class="CAMPO"><option v-for="t in id.tipos" :key="t" :value="t">{{ etiquetaTipo(t) }}</option></select>
      </div>

      <div v-if="['misma_persona', 'personas_distintas'].includes(form.tipo)" data-campo-grupos>
        <p :class="ETQ">Grupos</p>
        <label v-for="g in id.grupos" :key="g.grupo_hash" class="mt-1 flex items-center gap-2 text-sm font-semibold text-slate-700">
          <input v-model="form.grupos" type="checkbox" :value="g.grupo_hash" /> {{ g.etiqueta }} · {{ g.nombre }} ({{ g.certificados }} cert.)
        </label>
        <div v-if="form.tipo === 'misma_persona' && form.grupos.length >= 2" class="mt-3 rounded-xl bg-slate-50 p-3 text-xs font-semibold text-slate-600" data-correos-admisibles>
          <p :class="ETQ">Correos que podrían habilitar acceso</p>
          <p v-if="!candidatos.length" class="mt-1">Ninguno: todos los correos de estos grupos también aparecen fuera de ellos.</p>
          <ul v-else class="mt-1 font-mono"><li v-for="c in candidatos" :key="c.hmac">{{ c.mascara }}</li></ul>
          <p v-if="exigeCorreoExplicito" class="mt-2 text-amber-700" data-requiere-correo-autorizado>Este caso requerirá además una autorización explícita de correo<template v-if="afectados >= id.umbral_masivo"> (afecta {{ afectados }} certificados)</template>.</p>
          <p v-else class="mt-2 text-slate-500">Con un único correo candidato, esta decisión bastará para que ese correo habilite el acceso cuando el portal aplique las decisiones.</p>
        </div>
        <p v-if="form.tipo === 'misma_persona'" class="mt-2 text-xs font-semibold text-slate-500">Esta decisión puede permitir que una misma sesión acceda a estos grupos. No se fusionan los grupos ni se modifica ningún nombre.</p>
        <p v-else class="mt-2 text-xs font-semibold text-amber-700" data-aviso-distintas>Esta decisión NO otorgará acceso si los grupos continúan compartiendo el único correo disponible.</p>
      </div>

      <div v-if="form.tipo === 'correo_autorizado'" class="grid gap-3 md:grid-cols-2" data-campo-correo>
        <div>
          <label :class="ETQ" for="grupo-decision">Grupo autorizado</label>
          <select id="grupo-decision" v-model="form.grupo" :class="CAMPO"><option value="">Elige…</option><option v-for="g in gruposElegibles" :key="g.grupo_hash" :value="g.grupo_hash">{{ g.etiqueta }} · {{ g.nombre }}</option></select>
        </div>
        <div>
          <label :class="ETQ" for="correo-decision">Correo (enmascarado)</label>
          <select id="correo-decision" v-model="form.correo_hmac" :class="CAMPO" :disabled="!grupoElegido"><option value="">Elige…</option><option v-for="c in grupoElegido?.correos ?? []" :key="c.hmac" :value="c.hmac">{{ c.mascara }}</option></select>
        </div>
        <p v-if="correoElegido?.compartido" class="md:col-span-2 text-xs font-semibold text-amber-700" data-aviso-compartido>Este correo aparece también en otro registro histórico.</p>
        <label v-if="correoElegido?.compartido" class="md:col-span-2 flex items-start gap-2 text-sm font-semibold text-slate-700"><input v-model="form.reforzada" type="checkbox" class="mt-1" data-confirmo-reforzada /> Confirmo que existe evidencia suficiente para permitir que este correo acceda únicamente a este grupo.</label>
      </div>

      <div v-if="pideEvidencia">
        <label :class="ETQ" for="evidencia-decision">Evidencia ({{ id.evidencia_min }}–{{ id.evidencia_max }} caracteres)</label>
        <textarea id="evidencia-decision" v-model="form.evidencia" rows="3" :class="CAMPO" maxlength="1000"></textarea>
        <p v-if="form.errors.evidencia" class="text-xs font-semibold text-rose-700">{{ form.errors.evidencia }}</p>
      </div>

      <label v-if="form.tipo === 'misma_persona'" class="flex items-start gap-2 text-sm font-semibold text-slate-700"><input v-model="form.confirmo" type="checkbox" class="mt-1" data-confirmo /> Confirmo que existe evidencia suficiente para considerar estos grupos de la misma persona.</label>
      <label v-if="form.tipo === 'personas_distintas'" class="flex items-start gap-2 text-sm font-semibold text-slate-700"><input v-model="form.confirmo" type="checkbox" class="mt-1" data-confirmo /> Entiendo que esta decisión no otorga acceso por sí sola.</label>
      <label v-if="pideExterna" class="flex items-start gap-2 text-sm font-semibold text-rose-700" data-evidencia-externa><input v-model="form.evidencia_externa" type="checkbox" class="mt-1" /> Declaro que existe evidencia EXTERNA suficiente (no basta la similitud de los nombres).</label>
      <label v-if="masivo && !masivoSolicitud" class="flex items-start gap-2 text-sm font-semibold text-rose-700" data-alcance-masivo><input v-model="form.alcance_masivo" type="checkbox" class="mt-1" /> Entiendo que esta decisión podría afectar el acceso a {{ afectados }} certificados.</label>

      <div v-if="masivoSolicitud" class="rounded-2xl border-2 border-rose-200 bg-rose-50 p-4 space-y-3" data-bloque-masivo>
        <p class="flex items-start gap-2 text-sm font-extrabold text-rose-800"><TriangleAlert class="w-4 h-4 mt-0.5 shrink-0" /> Riesgo masivo: esta autorización puede habilitar {{ infoGrupo.descargables }} certificados descargables del grupo seleccionado.</p>
        <p class="text-sm font-semibold text-rose-800" data-fuera-alcance>{{ infoGrupo.fuera_de_alcance }} registro(s) histórico(s) adicional(es) quedarán fuera y seguirán en revisión. No se unen grupos ni personas.</p>
        <dl class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs font-semibold text-slate-700" data-radio-masivo>
          <div><dt :class="ETQ">Registros</dt><dd>{{ infoGrupo.certificados }}</dd></div>
          <div><dt :class="ETQ">Certificados lógicos</dt><dd>{{ infoGrupo.logicos }}</dd></div>
          <div><dt :class="ETQ">Eventos</dt><dd>{{ infoGrupo.eventos }}</dd></div>
          <div><dt :class="ETQ">Descargables</dt><dd>{{ infoGrupo.descargables }}</dd></div>
        </dl>
        <p class="text-xs font-semibold text-slate-600" data-requiere-segunda>Esto solo crea una SOLICITUD: no concede acceso hasta que un segundo administrador, distinto de ti, la apruebe.</p>
        <div>
          <label :class="ETQ" for="fuente-evidencia">Fuente de la evidencia externa</label>
          <select id="fuente-evidencia" v-model="form.fuente_evidencia" :class="CAMPO" data-fuente-evidencia><option value="">Elige…</option><option v-for="(t, k) in id.masivo.fuentes" :key="k" :value="k">{{ t }}</option></select>
        </div>
        <label class="flex items-start gap-2 text-sm font-semibold text-rose-800" data-evidencia-externa-masiva><input v-model="form.evidencia_externa" type="checkbox" class="mt-1" /> Declaro que existe evidencia EXTERNA suficiente de que este correo pertenece a la persona de este grupo.</label>
        <label class="flex items-start gap-2 text-sm font-semibold text-rose-800" data-alcance-masivo><input v-model="form.alcance_masivo" type="checkbox" class="mt-1" /> {{ id.masivo.texto_confirmacion }}</label>
      </div>

      <div>
        <label :class="ETQ" for="motivo-decision">Motivo (obligatorio)</label>
        <textarea id="motivo-decision" v-model="form.motivo" rows="2" :class="CAMPO" maxlength="500"></textarea>
      </div>
      <p v-if="form.errors.motivo" class="text-xs font-semibold text-rose-700">{{ form.errors.motivo }}</p>
      <button type="submit" :disabled="!puede" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90 disabled:opacity-40" data-registrar-decision>{{ masivoSolicitud ? "Solicitar autorización masiva" : "Registrar decisión" }}</button>
    </form>
    <p v-else class="mt-4 text-sm font-semibold text-slate-500">Este caso ya no admite nuevas decisiones.</p>

    <!-- Decisiones registradas -->
    <div class="mt-6" data-decisiones-registradas>
      <p :class="ETQ">Decisiones registradas</p>
      <p v-if="!id.decisiones.length" class="mt-1 text-sm font-semibold text-slate-500">Ninguna todavía.</p>
      <ul class="mt-2 space-y-2">
        <li v-for="d in id.decisiones" :key="d.id" class="rounded-2xl border border-slate-100 p-3 text-sm" :data-decision="d.id">
          <p class="font-extrabold text-slate-800">{{ TIPOS[d.tipo] }} · {{ ESTADOS[d.estado] }} <span class="text-xs font-semibold text-slate-400">#{{ d.id }} · {{ fecha(d.creada_at) }}</span></p>
          <p class="font-semibold text-slate-600">{{ d.motivo }}</p>
          <p v-if="d.grupos.length" class="text-xs font-semibold text-slate-500">Grupos: {{ d.grupos.join(", ") }}</p>
          <p v-if="d.correo" class="text-xs font-semibold text-slate-500">Correo {{ d.correo.mascara }} → grupo {{ d.correo.grupo }}</p>
          <p v-if="d.estado === 'revocada'" class="text-xs font-semibold text-slate-500">Revocada {{ fecha(d.revocada_at) }}: {{ d.motivo_revocacion }}</p>
          <div v-if="d.aprobacion" class="mt-2 rounded-xl bg-rose-50 p-3 text-xs font-semibold text-slate-700" data-aprobacion-masiva :data-estado-aprobacion="d.aprobacion.estado">
            <p class="font-extrabold text-rose-800">Autorización masiva · {{ APROB[d.aprobacion.estado] }}</p>
            <p>{{ d.aprobacion.descargables }} certificados descargables · {{ d.aprobacion.certificados }} registros · {{ d.aprobacion.logicos }} certificados lógicos · {{ d.aprobacion.eventos }} eventos · {{ d.aprobacion.fuera_de_alcance }} fuera de alcance</p>
            <p>Fuente de la evidencia: {{ d.aprobacion.fuente_evidencia }} · solicitada {{ fecha(d.aprobacion.solicitada_at) }}<span v-if="d.aprobacion.solicitada_por_usted"> por ti</span></p>
            <p v-if="d.aprobacion.estado === 'pendiente' && d.aprobacion.solicitada_por_usted" data-espera-segundo>Necesita la aprobación de OTRO administrador: no puedes aprobarla tú.</p>
            <p v-if="d.aprobacion.aprobada_at">Aprobada {{ fecha(d.aprobacion.aprobada_at) }}: {{ d.aprobacion.motivo_aprobacion }}</p>
            <div v-if="d.aprobacion.puede_aprobar && id.rutas_aprobar[d.id]" class="mt-2">
              <button v-if="aprobando !== d.id" type="button" class="font-bold text-rose-800 underline" data-aprobar-masiva @click="aprobando = d.id">Aprobar autorización masiva</button>
              <form v-else class="flex flex-col gap-2" @submit.prevent="confirmarAprobar">
                <textarea v-model="formAprobar.motivo" rows="2" :class="CAMPO" placeholder="Motivo de la aprobación" maxlength="500"></textarea>
                <label class="flex items-start gap-2 text-rose-800"><input v-model="formAprobar.confirmo_masiva" type="checkbox" class="mt-1" data-confirmo-masiva /> {{ id.masivo?.texto_confirmacion }}</label>
                <div class="flex gap-2"><button type="submit" :disabled="!aprobarOk" class="px-4 py-2 rounded-xl bg-rose-700 text-white font-bold disabled:opacity-40" data-confirmar-aprobacion>Confirmar aprobación</button><button type="button" class="font-bold text-slate-500" @click="aprobando = null">Cancelar</button></div>
              </form>
            </div>
            <div v-if="d.aprobacion.puede_revocar && id.rutas_revocar_aprobacion[d.id]" class="mt-2">
              <button v-if="revocandoAprob !== d.id" type="button" class="font-bold text-rose-800 underline" data-revocar-aprobacion @click="revocandoAprob = d.id">Revocar solo la aprobación</button>
              <form v-else class="flex flex-col gap-2" @submit.prevent="confirmarRevAprob">
                <textarea v-model="formRevAprob.motivo" rows="2" :class="CAMPO" placeholder="Motivo de la revocación" maxlength="500"></textarea>
                <div class="flex gap-2"><button type="submit" :disabled="!revAprobOk" class="px-4 py-2 rounded-xl bg-rose-700 text-white font-bold disabled:opacity-40">Confirmar revocación de la aprobación</button><button type="button" class="font-bold text-slate-500" @click="revocandoAprob = null">Cancelar</button></div>
              </form>
            </div>
          </div>
          <div v-if="d.estado === 'vigente' && id.rutas_revocar[d.id]" class="mt-2">
            <button v-if="revocar !== d.id" type="button" class="text-xs font-bold text-rose-700 underline" data-revocar @click="revocar = d.id">Revocar</button>
            <form v-else class="flex flex-col gap-2" @submit.prevent="confirmarRevocar">
              <textarea v-model="formRevocar.motivo" rows="2" :class="CAMPO" placeholder="Motivo de la revocación" maxlength="500"></textarea>
              <div class="flex gap-2"><button type="submit" :disabled="!revocarOk" class="px-4 py-2 rounded-xl bg-rose-700 text-white text-xs font-bold disabled:opacity-40">Confirmar revocación</button><button type="button" class="text-xs font-bold text-slate-500" @click="revocar = null">Cancelar</button></div>
            </form>
          </div>
        </li>
      </ul>
    </div>
  </section>
</template>
