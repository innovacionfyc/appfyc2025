<script setup>
import { computed } from "vue";
import { Search, X } from "lucide-vue-next";
import { useFiltrosHistorico } from "@/Composables/CredentialFlow/useFiltrosHistorico";

// Filtros combinables de Encuestas. SIEMPRE se aplican en el servidor (GET con la consulta en la URL) y se conservan al paginar y al cambiar de pestaña.
// `campos`: cuáles se muestran. `url`: función que devuelve la URL base (con el evento fijo en el detalle).
const props = defineProps({
  filtros: { type: Object, required: true },
  opciones: { type: Object, required: true }, // { anios, versiones }
  url: { type: Function, required: true },
  campos: { type: Array, default: () => ["q", "anio", "evento_fechas", "resp_fechas", "version", "origen"] },
});

const CLAVES = ["q", "anio", "evento_desde", "evento_hasta", "resp_desde", "resp_hasta", "version", "origen"];
const iniciales = Object.fromEntries(CLAVES.map((k) => [k, props.filtros[k] ?? ""]));
const { filtros: f, aplicar, aplicarConEspera, limpiar, hayFiltros } = useFiltrosHistorico(props.url, iniciales);
const ver = (c) => props.campos.includes(c);
const activos = computed(() => hayFiltros());

const CAMPO = "w-full rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-primary-vinotinto/30 focus:border-primary-vinotinto";
const ETIQ = "block text-[11px] uppercase tracking-wide font-black text-slate-400 mb-1";
</script>

<template>
  <form class="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-5 mb-6" role="search" aria-label="Filtros de encuestas" data-filtros-encuestas @submit.prevent="aplicar">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <div v-if="ver('q')" class="sm:col-span-2">
        <label :class="ETIQ" for="f-q">Evento (nombre)</label>
        <div class="relative">
          <Search class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
          <input id="f-q" v-model="f.q" type="search" maxlength="80" placeholder="Buscar por nombre del evento" :class="[CAMPO, 'pl-10']" data-filtro="q" @input="aplicarConEspera" />
        </div>
      </div>
      <div v-if="ver('anio')">
        <label :class="ETIQ" for="f-anio">Año del evento</label>
        <select id="f-anio" v-model="f.anio" :class="CAMPO" data-filtro="anio" @change="aplicar">
          <option value="">Todos</option>
          <option v-for="a in opciones.anios" :key="a" :value="a">{{ a }}</option>
        </select>
      </div>
      <div v-if="ver('version')">
        <label :class="ETIQ" for="f-version">Versión de la encuesta</label>
        <select id="f-version" v-model="f.version" :class="CAMPO" data-filtro="version" @change="aplicar">
          <option value="">Todas</option>
          <option v-for="v in opciones.versiones" :key="v.id" :value="v.id">Versión {{ v.numero }} · {{ v.titulo }}</option>
        </select>
      </div>
      <div v-if="ver('origen')">
        <label :class="ETIQ" for="f-origen">Origen</label>
        <select id="f-origen" v-model="f.origen" :class="CAMPO" data-filtro="origen" @change="aplicar">
          <option value="">Histórico y moderno</option>
          <option value="legado">Histórico (sistema anterior)</option>
          <option value="credential_flow">Moderno (portal)</option>
        </select>
      </div>
      <div v-if="ver('evento_fechas')" class="grid grid-cols-2 gap-2 sm:col-span-2">
        <div>
          <label :class="ETIQ" for="f-ed">Evento desde</label>
          <input id="f-ed" v-model="f.evento_desde" type="date" :class="CAMPO" data-filtro="evento_desde" @change="aplicar" />
        </div>
        <div>
          <label :class="ETIQ" for="f-eh">Evento hasta</label>
          <input id="f-eh" v-model="f.evento_hasta" type="date" :class="CAMPO" data-filtro="evento_hasta" @change="aplicar" />
        </div>
      </div>
      <div v-if="ver('resp_fechas')" class="grid grid-cols-2 gap-2 sm:col-span-2">
        <div>
          <label :class="ETIQ" for="f-rd">Respuesta desde</label>
          <input id="f-rd" v-model="f.resp_desde" type="date" :class="CAMPO" data-filtro="resp_desde" @change="aplicar" />
        </div>
        <div>
          <label :class="ETIQ" for="f-rh">Respuesta hasta</label>
          <input id="f-rh" v-model="f.resp_hasta" type="date" :class="CAMPO" data-filtro="resp_hasta" @change="aplicar" />
        </div>
      </div>
    </div>
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
      <p class="text-[12px] font-semibold text-slate-500">Las fechas del evento usan la fecha de inicio registrada; las fechas de respuesta, el momento en que se contestó.</p>
      <button v-if="activos" type="button" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-2xl bg-white border border-slate-200 text-[13px] font-bold text-slate-600 hover:bg-slate-50" data-limpiar-filtros @click="limpiar">
        <X class="w-4 h-4" /> Limpiar filtros
      </button>
    </div>
  </form>
</template>
