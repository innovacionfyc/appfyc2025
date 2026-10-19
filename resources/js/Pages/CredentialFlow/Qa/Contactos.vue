<script setup>
import { computed, reactive, ref } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import DashboardHeader from "@/Components/Shared/header/DashboardHeader.vue";
import { ShieldCheck, AlertTriangle, Pencil, Power, X } from "lucide-vue-next";

const props = defineProps({
  disponible: { type: Boolean, default: false },
  catalogo: { type: Object, default: () => ({ listo: false, motivos: [], activos: 0, esperados: 8 }) }, // ¿está listo para incorporarse solo a las bases nuevas?
  contactos: { type: Array, default: () => [] },
  resumen: { type: Object, default: () => ({ total: 0, activos: 0, verificados: 0, esperados: 8 }) },
  inicializacion: { type: Object, default: () => ({ disponible: false, candidatas: [], seleccion: [], verificacion: null }) },
});

const headerStats = computed(() => [
  { label: "Contactos", value: props.resumen.total, icon: "groups", color: "text-primary-vinotinto", bg: "bg-primary-vinotinto/10" },
  { label: "Activos", value: props.resumen.activos, icon: "toggle_on", color: "text-emerald-700", bg: "bg-emerald-50" },
  { label: "Verificados", value: props.resumen.verificados, icon: "verified", color: "text-sky-700", bg: "bg-sky-50" },
]);

// Un único panel de acción: corregir un contacto o activarlo/desactivarlo. Siempre con motivo y confirmación.
const accion = ref(null); // { tipo: 'editar' | 'activo', contacto }
const form = reactive({ nombre_completo: "", documento: "", correo: "", motivo: "", confirmo: false });
const enviando = ref(false);

const abrir = (tipo, c) => {
  accion.value = { tipo, contacto: c };
  Object.assign(form, { nombre_completo: c.nombre_completo, documento: c.documento, correo: c.correo, motivo: "", confirmo: false });
};
const cerrar = () => {
  if (!enviando.value) accion.value = null;
};
const puedeEnviar = computed(() => form.confirmo && form.motivo.trim().length >= 10 && !enviando.value);

const enviar = () => {
  if (!puedeEnviar.value || !accion.value) return;
  const { tipo, contacto } = accion.value;
  enviando.value = true;
  const opciones = { preserveScroll: true, onSuccess: () => (accion.value = null), onFinish: () => (enviando.value = false) };
  if (tipo === "editar") {
    router.put(route("credential-flow.qa.contactos.update", contacto.id), { ...form }, opciones);
  } else {
    router.put(route("credential-flow.qa.contactos.activo", contacto.id), { activo: !contacto.activo, motivo: form.motivo, confirmo: form.confirmo }, opciones);
  }
};

// Inicialización del catálogo desde las bases QA oficiales (solo si el catálogo está vacío y las bases coinciden).
const elegidas = ref([...(props.inicializacion.seleccion ?? [])]);
const verificar = () => router.get(route("credential-flow.qa.contactos"), { bases: elegidas.value }, { preserveScroll: true, preserveState: false });
const init = reactive({ motivo: "", confirmo: false });
const inicializar = () => {
  if (!props.inicializacion.verificacion?.ok || !init.confirmo || init.motivo.trim().length < 10) return;
  router.post(route("credential-flow.qa.contactos.inicializar"), { bases: elegidas.value, motivo: init.motivo, confirmo: init.confirmo }, { preserveScroll: true });
};
</script>

<template>
  <Head title="Credential Flow · Contactos QA" />

  <AuthenticatedLayout>
    <Sidebar>
      <nav class="mb-3 flex flex-wrap items-center gap-x-2 pr-14 md:pr-0 text-[12px] font-bold text-slate-400" aria-label="Ruta de navegación">
        <Link :href="route('credential-flow.index')" class="hover:text-primary-vinotinto transition-colors">Credential Flow</Link>
        <span>/</span>
        <span class="text-slate-600">Contactos QA</span>
      </nav>

      <DashboardHeader title="Contactos QA" subtitle="Las personas del equipo que entran solas a cada base nueva para probar la descarga y el acceso" :stats="headerStats" />

      <p class="mt-4 rounded-2xl bg-slate-50 px-4 py-3 text-[12px] font-semibold text-slate-500" data-aviso-qa>
        Corregir un contacto no reescribe las bases que ya lo tienen: solo afecta a las bases nuevas. Desactivarlo tampoco borra nada. Todo cambio pide un motivo y una confirmación y queda en la bitácora sin datos completos.
      </p>

      <div class="mt-4 rounded-2xl px-4 py-3 text-[13px] font-bold" :class="catalogo.listo ? 'bg-emerald-50 border border-emerald-100 text-emerald-700' : 'bg-amber-50 border border-amber-200 text-amber-800'" data-estado-catalogo :data-listo="catalogo.listo ? 1 : 0">
        <template v-if="catalogo.listo">Catálogo listo: {{ catalogo.activos }} contactos activos y verificados. Entran solos a cada base nueva.</template>
        <template v-else>
          Catálogo incompleto: mientras no esté listo no se puede crear ninguna base nueva.
          <ul class="mt-1 list-disc pl-5 font-semibold"><li v-for="m in catalogo.motivos" :key="m">{{ m }}</li></ul>
        </template>
      </div>

      <div v-if="!disponible" class="mt-8 text-center py-12 bg-white rounded-[2rem] border border-dashed border-slate-200" data-sin-tabla>
        <p class="text-slate-600 font-bold">Los contactos QA todavía no están disponibles en este entorno.</p>
        <p class="text-slate-400 text-sm mt-1">Falta ejecutar la migración de contactos de control.</p>
      </div>

      <template v-else>
        <div v-if="contactos.length === 0" class="mt-8 text-center py-10 bg-white rounded-[2rem] border border-dashed border-slate-200" data-sin-contactos>
          <p class="text-slate-600 font-bold">Aún no hay contactos QA cargados</p>
          <p class="text-slate-400 text-sm max-w-xl mx-auto px-4 mt-1">Inicialízalos desde las bases QA oficiales que ya existen (abajo). Nunca se inventan ni se reemplazan solos.</p>
        </div>

        <div v-else class="relative mt-8 overflow-x-auto bg-white rounded-[2rem] border border-slate-100 shadow-sm">
          <table class="min-w-full text-sm" data-tabla-contactos>
            <thead class="text-[11px] uppercase tracking-wide text-slate-400 border-b border-slate-100">
              <tr>
                <th class="text-left font-black px-6 py-4">Contacto</th>
                <th class="text-left font-black px-4 py-4">Documento</th>
                <th class="text-left font-black px-4 py-4">Correo</th>
                <th class="text-left font-black px-4 py-4">Verificación</th>
                <th class="text-left font-black px-4 py-4">Bases</th>
                <th class="px-6 py-4"><span class="sr-only">Acciones</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="c in contactos" :key="c.id" :data-contacto="c.id" :class="c.activo ? '' : 'opacity-60'">
                <td class="px-6 py-3 font-extrabold text-slate-900 break-words">
                  {{ c.nombre_completo }}
                  <span v-if="!c.activo" class="ml-2 inline-flex px-2 py-0.5 rounded-full bg-slate-200 text-slate-600 text-[10px] font-black align-middle" data-inactivo>Inactivo</span>
                </td>
                <td class="px-4 py-3 font-semibold text-slate-600 break-words">{{ c.documento }}</td>
                <td class="px-4 py-3 text-[12px] font-semibold text-slate-600 break-all max-w-[14rem]">{{ c.correo }}</td>
                <td class="px-4 py-3" data-verificacion :data-verificado="c.verificado ? 1 : 0">
                  <span v-if="c.verificado" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-black"><ShieldCheck class="w-3.5 h-3.5" /> Verificado</span>
                  <ul v-else class="space-y-1">
                    <li v-for="a in c.avisos" :key="a" class="flex items-start gap-1.5 text-[11px] font-bold text-amber-700"><AlertTriangle class="w-3.5 h-3.5 shrink-0 mt-px" /><span>{{ a }}</span></li>
                  </ul>
                </td>
                <td class="px-4 py-3 text-[12px] font-semibold text-slate-500" data-bases>
                  <span v-if="c.bases.length === 0" class="text-slate-400">En ninguna base</span>
                  <ul v-else class="space-y-1">
                    <li v-for="b in c.bases" :key="b.lote_id">
                      <Link :href="route('credential-flow.lotes.show', b.lote_id)" class="hover:text-primary-vinotinto underline-offset-2 hover:underline">#{{ b.lote_id }} · {{ b.nombre }}</Link>
                      <span v-if="!b.coincide" class="ml-1 text-amber-700" title="La fila de esta base tiene datos distintos a los del catálogo">≠</span>
                    </li>
                  </ul>
                </td>
                <td class="px-6 py-3">
                  <div class="flex items-center justify-end gap-1.5">
                    <button type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-primary-vinotinto/10 text-[12px] font-bold text-primary-vinotinto hover:bg-primary-vinotinto/20 transition-all" data-accion="corregir" @click="abrir('editar', c)"><Pencil class="w-3.5 h-3.5" /> Corregir</button>
                    <button type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-white border border-slate-200 text-[12px] font-bold text-slate-700 hover:bg-slate-50 transition-all" data-accion="activo" @click="abrir('activo', c)"><Power class="w-3.5 h-3.5" /> {{ c.activo ? "Desactivar" : "Activar" }}</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Inicialización segura -->
        <section v-if="inicializacion.disponible" class="mt-10 bg-white rounded-[2rem] border border-slate-100 shadow-sm p-4 sm:p-6" data-inicializar>
          <h2 class="text-sm font-extrabold text-slate-700">Inicializar desde las bases QA oficiales</h2>
          <p class="mt-1 text-[13px] font-medium text-slate-500">Elige las bases que contienen solo a los {{ resumen.esperados }} contactos oficiales. Solo se carga si todas contienen exactamente los mismos contactos, con documento y correo válidos. No se inventa ni se reemplaza ninguna identidad.</p>

          <p v-if="inicializacion.candidatas.length === 0" class="mt-3 text-[12px] font-semibold text-slate-400">No hay bases formadas solo por contactos de control.</p>
          <ul v-else class="mt-3 space-y-2">
            <li v-for="b in inicializacion.candidatas" :key="b.id" class="flex items-center gap-3 text-sm">
              <input :id="`base-${b.id}`" v-model="elegidas" type="checkbox" :value="b.id" class="rounded border-slate-300" :data-base="b.id" />
              <label :for="`base-${b.id}`" class="font-semibold text-slate-700">#{{ b.id }} · {{ b.nombre }} <span class="text-slate-400">({{ b.contactos }} contactos)</span></label>
            </li>
          </ul>
          <button type="button" :disabled="elegidas.length === 0" class="mt-4 px-4 py-2 rounded-full bg-white border border-slate-200 text-[12px] font-bold text-slate-700 disabled:opacity-40" data-accion="verificar-bases" @click="verificar">Verificar bases elegidas</button>

          <div v-if="inicializacion.verificacion" class="mt-4 space-y-3" data-veredicto :data-ok="inicializacion.verificacion.ok ? 1 : 0">
            <div v-if="inicializacion.verificacion.ok" class="rounded-2xl bg-emerald-50 border border-emerald-100 p-3 text-[13px] font-bold text-emerald-700">Las bases coinciden: {{ inicializacion.verificacion.contactos.length }} contactos idénticos con documento y correo válidos.</div>
            <ul v-else class="rounded-2xl bg-amber-50 border border-amber-100 p-3 space-y-1">
              <li v-for="m in inicializacion.verificacion.motivos" :key="m" class="text-[12px] font-bold text-amber-700">{{ m }}</li>
            </ul>
            <div v-if="inicializacion.verificacion.ok" class="space-y-2">
              <input v-model="init.motivo" type="text" maxlength="255" placeholder="Motivo (mínimo 10 caracteres)" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700" data-campo="motivo-inicializar" />
              <label class="flex items-center gap-2 text-[12px] font-bold text-slate-600"><input v-model="init.confirmo" type="checkbox" class="rounded border-slate-300" data-campo="confirmo-inicializar" /> Confirmo que estas son las personas reales del equipo</label>
              <button type="button" :disabled="!init.confirmo || init.motivo.trim().length < 10" class="px-4 py-2 rounded-full bg-primary-vinotinto text-white text-[12px] font-bold disabled:opacity-40" data-accion="inicializar" @click="inicializar">Cargar los contactos</button>
            </div>
          </div>
        </section>
      </template>

      <!-- Panel de acción (corregir / activar / desactivar) -->
      <Teleport to="body">
        <div v-if="accion" class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4" data-panel-accion>
          <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" @click="cerrar" />
          <form role="dialog" aria-modal="true" class="relative z-50 w-full max-w-lg bg-white rounded-[2rem] shadow-2xl p-6 sm:p-8 space-y-4 max-h-[92dvh] overflow-y-auto" @submit.prevent="enviar">
            <div class="flex items-start justify-between gap-3">
              <h3 class="text-lg font-black text-slate-900">{{ accion.tipo === "editar" ? "Corregir contacto" : accion.contacto.activo ? "Desactivar contacto" : "Activar contacto" }}</h3>
              <button type="button" class="w-9 h-9 flex items-center justify-center rounded-2xl text-slate-400 hover:bg-slate-100" aria-label="Cerrar" @click="cerrar"><X class="w-5 h-5" /></button>
            </div>

            <template v-if="accion.tipo === 'editar'">
              <label class="block text-[12px] font-bold text-slate-500">Nombre completo<input v-model="form.nombre_completo" type="text" maxlength="200" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700" data-campo="nombre" /></label>
              <label class="block text-[12px] font-bold text-slate-500">Documento<input v-model="form.documento" type="text" maxlength="100" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700" data-campo="documento" /></label>
              <label class="block text-[12px] font-bold text-slate-500">Correo<input v-model="form.correo" type="text" maxlength="300" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700" data-campo="correo" /></label>
            </template>
            <p v-else class="text-[13px] font-medium text-slate-500">{{ accion.contacto.activo ? "Dejará de incorporarse a las bases nuevas. Las bases que ya lo tienen no cambian." : "Volverá a incorporarse a las bases nuevas." }}</p>

            <label class="block text-[12px] font-bold text-slate-500">Motivo (queda en la bitácora)<input v-model="form.motivo" type="text" maxlength="255" placeholder="Mínimo 10 caracteres" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700" data-campo="motivo" /></label>
            <label class="flex items-center gap-2 text-[12px] font-bold text-slate-600"><input v-model="form.confirmo" type="checkbox" class="rounded border-slate-300" data-campo="confirmo" /> Confirmo este cambio</label>

            <div class="flex items-center justify-end gap-3 pt-2">
              <button type="button" class="px-4 py-2 rounded-xl text-sm font-bold text-slate-500 hover:bg-slate-100" @click="cerrar">Cancelar</button>
              <button type="submit" :disabled="!puedeEnviar" class="px-5 py-2.5 rounded-2xl bg-primary-vinotinto text-white text-sm font-bold disabled:opacity-40" data-accion="guardar-contacto">Guardar</button>
            </div>
          </form>
        </div>
      </Teleport>
    </Sidebar>
  </AuthenticatedLayout>
</template>
