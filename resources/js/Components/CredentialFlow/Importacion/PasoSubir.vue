<script setup>
// Paso 1 · Subir el Excel. El archivo se analiza y SE DESCARTA: en el servidor solo queda el borrador (cifrado, caduca solo). No se guarda el Excel original.
import { computed, ref } from "vue";
import { FileSpreadsheet, UploadCloud, X, LoaderCircle, History, ShieldCheck } from "lucide-vue-next";
import { formatearTamano, useAsistente } from "@/Composables/CredentialFlow/importacionInteligente";

const a = useAsistente();
const { limites, borradores } = a.props;

const archivo = ref(null);
const arrastrando = ref(false);
const errorLocal = ref("");
const entrada = ref(null);

const mensaje = computed(() => errorLocal.value || (a.error.value && a.paso.value === "subir" ? a.error.value.mensaje : ""));

function aceptar(f) {
  errorLocal.value = "";
  a.error.value = null;
  if (!f) return;
  if (/\.csv$/i.test(f.name)) {
    errorLocal.value = "El asistente lee archivos de Excel (.xlsx). Para un CSV usa «Nueva base» con el procedimiento de siempre.";
    return;
  }
  if (!/\.xlsx$/i.test(f.name)) {
    errorLocal.value = "Usa el Excel original del cliente (.xlsx).";
    return;
  }
  if (f.size > limites.archivoMb * 1024 * 1024) {
    errorLocal.value = `El archivo pesa más de ${limites.archivoMb} MB.`;
    return;
  }
  archivo.value = f;
}

const alSeleccionar = (e) => {
  aceptar(e.target.files?.[0] ?? null);
  if (entrada.value) entrada.value.value = "";
};
const alSoltar = (e) => {
  arrastrando.value = false;
  if (!a.cargando.value) aceptar(e.dataTransfer?.files?.[0] ?? null);
};
const analizar = async () => {
  if (!archivo.value || a.cargando.value) return;
  const r = await a.analizar(archivo.value);
  if (r) archivo.value = null;
};
const fechaCorta = (iso) => (iso ? new Date(iso).toLocaleString("es-CO", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : "");
</script>

<template>
  <section aria-labelledby="titulo-subir" class="space-y-6">
    <div>
      <h2 id="titulo-subir" tabindex="-1" class="text-xl font-extrabold text-slate-900 focus:outline-none">Sube el Excel original del cliente</h2>
      <p class="mt-1 text-sm font-medium text-slate-500">
        Lo leemos por ti: buscamos el evento, la fecha, la modalidad y la lista de participantes. No se crea nada hasta que tú lo confirmes.
      </p>
    </div>

    <input ref="entrada" type="file" class="hidden" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" data-input-excel :disabled="a.cargando.value" @change="alSeleccionar" />

    <div
      v-if="!archivo"
      role="button"
      tabindex="0"
      :aria-disabled="a.cargando.value"
      aria-label="Elegir o soltar el archivo de Excel"
      class="rounded-3xl border-2 border-dashed px-5 py-10 sm:py-14 text-center cursor-pointer transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50"
      :class="arrastrando ? 'border-primary-vinotinto bg-primary-vinotinto/5' : mensaje ? 'border-rose-300 bg-rose-50/40' : 'border-slate-300 bg-white hover:border-primary-vinotinto/60 hover:bg-slate-50'"
      data-zona-carga
      @click="entrada?.click()"
      @keydown.enter.prevent="entrada?.click()"
      @keydown.space.prevent="entrada?.click()"
      @dragover.prevent="arrastrando = true"
      @dragleave.prevent="arrastrando = false"
      @drop.prevent="alSoltar"
    >
      <div class="w-14 h-14 mx-auto rounded-2xl bg-primary-vinotinto/10 text-primary-vinotinto flex items-center justify-center">
        <UploadCloud class="w-7 h-7" aria-hidden="true" />
      </div>
      <p class="mt-4 text-base font-extrabold text-slate-800">Arrastra aquí el Excel o <span class="text-primary-vinotinto underline">elígelo en tu equipo</span></p>
      <p class="mt-1 text-[13px] font-semibold text-slate-400">Archivo .xlsx de hasta {{ limites.archivoMb }} MB</p>
    </div>

    <div v-else class="flex items-center gap-4 rounded-3xl border border-slate-200 bg-white p-4" data-archivo-elegido>
      <div class="w-12 h-12 shrink-0 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center">
        <FileSpreadsheet class="w-6 h-6" aria-hidden="true" />
      </div>
      <div class="min-w-0 flex-1">
        <p class="text-sm font-bold text-slate-900 truncate" :title="archivo.name">{{ archivo.name }}</p>
        <p class="text-[12px] font-semibold text-slate-400">{{ formatearTamano(archivo.size) }}</p>
      </div>
      <button type="button" :disabled="a.cargando.value" class="w-10 h-10 flex items-center justify-center rounded-xl text-slate-400 hover:text-rose-600 hover:bg-rose-50 disabled:opacity-50" aria-label="Quitar archivo" @click="archivo = null">
        <X class="w-5 h-5" aria-hidden="true" />
      </button>
    </div>

    <p v-if="mensaje" class="rounded-2xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm font-bold text-rose-700" role="alert" data-error-subir>{{ mensaje }}</p>

    <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-3">
      <p class="flex items-start gap-2 text-[12px] font-semibold text-slate-500 sm:max-w-md">
        <ShieldCheck class="w-4 h-4 shrink-0 mt-0.5 text-emerald-600" aria-hidden="true" />
        El Excel no se guarda. El borrador con los datos queda cifrado y se borra solo a los {{ limites.retencionDias }} días.
      </p>
      <button
        type="button"
        :disabled="!archivo || a.cargando.value"
        class="inline-flex items-center justify-center gap-2 rounded-2xl bg-primary-vinotinto px-6 py-3 text-sm font-extrabold text-white shadow-sm transition-all hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-vinotinto/50 disabled:opacity-50 disabled:cursor-not-allowed"
        data-accion="analizar"
        @click="analizar"
      >
        <LoaderCircle v-if="a.cargando.value" class="w-4 h-4 animate-spin" aria-hidden="true" />
        {{ a.cargando.value ? "Analizando el Excel…" : "Analizar Excel" }}
      </button>
    </div>

    <div v-if="borradores.length" class="rounded-3xl border border-slate-200 bg-white p-5" data-borradores>
      <h3 class="flex items-center gap-2 text-sm font-extrabold text-slate-800"><History class="w-4 h-4 text-slate-400" aria-hidden="true" /> Tus borradores anteriores</h3>
      <p class="mt-1 text-[12px] font-semibold text-slate-400">Si vuelves a subir el mismo Excel, recuperamos su borrador.</p>
      <ul class="mt-3 divide-y divide-slate-100">
        <li v-for="b in borradores" :key="b.id" class="flex flex-wrap items-center justify-between gap-2 py-2.5">
          <div class="min-w-0">
            <p class="text-sm font-bold text-slate-800 truncate">{{ b.archivo || "Excel" }}</p>
            <p class="text-[12px] font-semibold text-slate-400">{{ b.participantes ?? "?" }} participantes · creado {{ fechaCorta(b.creado_at) }} · vence {{ fechaCorta(b.expira_at) }}</p>
          </div>
          <button type="button" :disabled="a.cargando.value" class="rounded-xl px-4 py-2 text-[13px] font-extrabold text-primary-vinotinto ring-1 ring-primary-vinotinto/30 hover:bg-primary-vinotinto/5 disabled:opacity-50" :data-abrir-borrador="b.id" @click="a.abrir(b.id)">
            Continuar
          </button>
        </li>
      </ul>
    </div>
  </section>
</template>
