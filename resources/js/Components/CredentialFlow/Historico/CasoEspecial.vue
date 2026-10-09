<script setup>
import { computed, ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import { TriangleAlert, Info } from "lucide-vue-next";

// Caso ESPECIAL (Fase 10B-3C-4): no se resuelve automáticamente. Muestra el motivo, la evidencia disponible, qué falta y la siguiente acción posible. Registrar evidencia
// externa NO cambia el estado del caso ni el acceso al portal. Nada de datos personales: el resumen lo escribe el administrador (se le pide no incluirlos).
const props = defineProps({ especial: { type: Object, required: true } });
const e = props.especial;

const CAJA = "bg-white rounded-[2rem] border-2 border-amber-200 shadow-sm p-6";
const ETQ = "text-[11px] uppercase tracking-wide font-black text-slate-400";
const CAMPO = "mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700";
const fecha = (v) => (v ? String(v).slice(0, 16).replace("T", " ") : "—");

const form = useForm({ fuente: "", resumen: "" });
const okEvidencia = computed(() => form.fuente !== "" && form.resumen.trim().length >= e.resumen_min && form.resumen.trim().length <= e.resumen_max && !form.processing);
const enviar = () => form.post(e.rutas.evidencia, { preserveScroll: true, onSuccess: () => form.reset() });

const invalidando = ref(null);
const formInv = useForm({ motivo: "" });
const invOk = computed(() => formInv.motivo.trim().length >= e.motivo_min && !formInv.processing);
const confirmarInv = () => formInv.post(e.rutas.invalidar[invalidando.value], { preserveScroll: true, onSuccess: () => { invalidando.value = null; formInv.reset(); } });

const reabriendo = ref(false);
const formReabrir = useForm({ motivo: "" });
const reabrirOk = computed(() => formReabrir.motivo.trim().length >= e.motivo_min && !formReabrir.processing);
const confirmarReabrir = () => formReabrir.post(e.rutas.reabrir, { preserveScroll: true, onSuccess: () => { reabriendo.value = false; formReabrir.reset(); } });
</script>

<template>
  <section :class="[CAJA, 'lg:col-span-2']" data-caso-especial :data-categoria="e.categoria">
    <h3 class="flex items-start gap-2 text-base font-extrabold text-slate-900"><TriangleAlert class="w-5 h-5 mt-0.5 shrink-0 text-amber-600" /> {{ e.titulo }}</h3>
    <dl class="mt-4 grid gap-x-6 gap-y-3 md:grid-cols-2 text-sm font-semibold text-slate-700">
      <div><dt :class="ETQ">Clasificación</dt><dd data-especial-etiqueta>{{ e.etiqueta }}</dd></div>
      <div><dt :class="ETQ">Destino</dt><dd data-especial-destino>{{ e.destino_etiqueta }}</dd></div>
      <div v-if="e.motivo"><dt :class="ETQ">Motivo de origen</dt><dd>{{ e.motivo }}</dd></div>
      <div><dt :class="ETQ">Evidencia disponible</dt><dd data-especial-evidencia-resumen>{{ e.evidencias.filter((x) => x.estado === 'vigente').length }} vigente(s) · {{ e.evidencias.length }} registrada(s)</dd></div>
      <div v-if="e.falta" class="md:col-span-2"><dt :class="ETQ">Qué falta</dt><dd data-especial-falta>{{ e.falta }}</dd></div>
      <div v-if="e.siguiente" class="md:col-span-2"><dt :class="ETQ">Siguiente acción posible</dt><dd data-especial-siguiente>{{ e.siguiente }}</dd></div>
    </dl>
    <p class="mt-3 flex items-start gap-2 text-[13px] font-semibold text-slate-500"><Info class="w-4 h-4 mt-0.5 shrink-0" /> Registrar evidencia no cambia el estado de este caso ni da acceso al portal: solo deja constancia.</p>

    <ul v-if="e.evidencias.length" class="mt-4 space-y-2" data-especial-evidencias>
      <li v-for="v in e.evidencias" :key="v.id" class="rounded-2xl border border-slate-100 p-3 text-sm" :data-evidencia="v.id" :data-estado-evidencia="v.estado">
        <p class="font-extrabold text-slate-800">{{ v.fuente_etiqueta }} · {{ v.estado === "vigente" ? "Vigente" : "Invalidada" }} <span class="text-xs font-semibold text-slate-400">#{{ v.id }} · {{ fecha(v.fecha) }} · {{ v.longitud }} car. · {{ v.sha256 }}</span></p>
        <p class="font-semibold text-slate-600">{{ v.resumen }}</p>
        <div v-if="v.estado === 'vigente' && e.rutas.invalidar[v.id]" class="mt-1">
          <button v-if="invalidando !== v.id" type="button" class="text-xs font-bold text-rose-700 underline" data-invalidar-evidencia @click="invalidando = v.id">Invalidar</button>
          <form v-else class="flex flex-col gap-2" @submit.prevent="confirmarInv">
            <textarea v-model="formInv.motivo" rows="2" :class="CAMPO" placeholder="Motivo de la invalidación" maxlength="500"></textarea>
            <div class="flex gap-2"><button type="submit" :disabled="!invOk" class="px-4 py-2 rounded-xl bg-rose-700 text-white text-xs font-bold disabled:opacity-40">Confirmar invalidación</button><button type="button" class="text-xs font-bold text-slate-500" @click="invalidando = null">Cancelar</button></div>
          </form>
        </div>
      </li>
    </ul>

    <form v-if="e.puede_registrar_evidencia" class="mt-5 space-y-3" data-form-evidencia @submit.prevent="enviar">
      <p :class="ETQ">Registrar evidencia externa</p>
      <div>
        <label :class="ETQ" for="fuente-especial">Fuente</label>
        <select id="fuente-especial" v-model="form.fuente" :class="CAMPO" data-fuente><option value="">Elige…</option><option v-for="(t, k) in e.fuentes" :key="k" :value="k">{{ t }}</option></select>
      </div>
      <div>
        <label :class="ETQ" for="resumen-especial">Resumen ({{ e.resumen_min }}–{{ e.resumen_max }} caracteres; sin datos personales)</label>
        <textarea id="resumen-especial" v-model="form.resumen" rows="3" :class="CAMPO" :maxlength="e.resumen_max" data-resumen></textarea>
        <p v-if="form.errors.resumen" class="text-xs font-semibold text-rose-700">{{ form.errors.resumen }}</p>
      </div>
      <button type="submit" :disabled="!okEvidencia" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold hover:opacity-90 disabled:opacity-40" data-registrar-evidencia>Registrar evidencia</button>
    </form>

    <div v-if="e.puede_reabrir" class="mt-5" data-reabrir-bloque>
      <p class="text-sm font-semibold text-slate-700">Llegó evidencia nueva después de marcar este caso como soporte. Puedes reabrirlo (el historial del soporte se conserva).</p>
      <button v-if="!reabriendo" type="button" class="mt-2 text-sm font-bold text-primary-vinotinto underline" data-reabrir @click="reabriendo = true">Reabrir caso</button>
      <form v-else class="mt-2 flex flex-col gap-2" @submit.prevent="confirmarReabrir">
        <textarea v-model="formReabrir.motivo" rows="2" :class="CAMPO" placeholder="Motivo de la reapertura" maxlength="500"></textarea>
        <div class="flex gap-2"><button type="submit" :disabled="!reabrirOk" class="px-4 py-2 rounded-xl bg-primary-vinotinto text-white text-xs font-bold disabled:opacity-40">Confirmar reapertura</button><button type="button" class="text-xs font-bold text-slate-500" @click="reabriendo = false">Cancelar</button></div>
      </form>
    </div>
  </section>
</template>
