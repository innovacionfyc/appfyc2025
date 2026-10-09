<script setup>
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import NavHistorico from "@/Components/CredentialFlow/Historico/NavHistorico.vue";
import Insignia from "@/Components/CredentialFlow/Historico/Insignia.vue";
import AvisoHistorico from "@/Components/CredentialFlow/Historico/AvisoHistorico.vue";
import { Layers, Star } from "lucide-vue-next";

// Detalle de un certificado histórico para el administrador. SOLO LECTURA: sin editar, conciliar, borrar ni generar PDF.
// Aquí sí se muestra la información completa (documento y correos); en los listados va enmascarada.
const props = defineProps({
  certificado: { type: Object, required: true },
});

const c = props.certificado;
const fecha = (v) => {
  if (!v) return "—";
  const d = new Date(String(v).replace(" ", "T"));
  return isNaN(d.getTime()) ? v : new Intl.DateTimeFormat("es-CO", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }).format(d);
};
const migas = [
  ...(c.evento ? [{ texto: c.evento.nombre, href: route("credential-flow.historico.eventos.show", c.evento.id) }] : []),
  { texto: c.nombre || "Certificado" },
];

const CAJA = "bg-white rounded-[2rem] border border-slate-100 shadow-sm p-6";
const ETQ = "text-[11px] uppercase tracking-wide font-black text-slate-400";
</script>

<template>
  <Head title="Credential Flow · Certificado histórico" />

  <AuthenticatedLayout>
    <Sidebar>
      <NavHistorico actual="eventos" :migas="migas" />

      <header class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
          <Insignia :etiqueta="c.conciliacion.etiqueta" :tono="c.conciliacion.tono" />
          <Insignia :etiqueta="c.estado" tono="slate" />
          <Insignia v-if="c.correos_multiples" etiqueta="Múltiples correos históricos" tono="sky" />
          <Insignia v-if="!c.visible_portal" etiqueta="No visible en el portal" tono="slate" />
        </div>
        <h2 class="text-3xl md:text-4xl font-extrabold text-slate-900 tracking-tight break-words">{{ c.nombre || "Sin nombre" }}</h2>
        <p class="text-sm font-semibold text-slate-400">Solo lectura · Identificador en el sistema anterior: <span class="font-mono text-slate-500">{{ c.old_id ?? "—" }}</span></p>
      </header>

      <div v-if="c.avisos.length" class="mt-6 space-y-3" data-avisos>
        <AvisoHistorico v-for="(a, i) in c.avisos" :key="i" :aviso="a" />
      </div>

      <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-5">
        <section :class="CAJA" data-datos-persona>
          <h3 class="text-base font-extrabold text-slate-900">Datos del registro</h3>
          <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-4 text-sm font-semibold text-slate-700">
            <div><dt :class="ETQ">Tipo de documento</dt><dd>{{ c.tipo_documento || "—" }}</dd></div>
            <div><dt :class="ETQ">Documento</dt><dd class="font-mono break-all" data-documento>{{ c.documento || "—" }}</dd></div>
            <div class="col-span-2"><dt :class="ETQ">Correo original</dt><dd class="break-all">{{ c.correo_original || "—" }}</dd></div>
            <div><dt :class="ETQ">Estado del correo</dt><dd>{{ c.correo_estado }}</dd></div>
            <div><dt :class="ETQ">Código</dt><dd class="font-mono" data-codigo :data-origen-codigo="c.codigo?.origen">{{ c.codigo?.texto ?? c.codigo_legado ?? "—" }}</dd></div>
            <div v-if="c.evento" class="col-span-2">
              <dt :class="ETQ">Evento</dt>
              <dd><Link :href="route('credential-flow.historico.eventos.show', c.evento.id)" class="text-primary-vinotinto hover:underline">{{ c.evento.nombre }}</Link> · {{ c.evento.anio_etiqueta }}</dd>
            </div>
          </dl>
        </section>

        <section :class="CAJA" data-estado-certificado>
          <h3 class="text-base font-extrabold text-slate-900">Estado del certificado</h3>
          <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-4 text-sm font-semibold text-slate-700">
            <div><dt :class="ETQ">Estado</dt><dd>{{ c.estado }}</dd></div>
            <div><dt :class="ETQ">Conciliación</dt><dd>{{ c.conciliacion.etiqueta }}</dd></div>
            <div><dt :class="ETQ">PDF</dt><dd>{{ c.pdf.estado }}<span v-if="c.pdf.materializado_at"> · {{ fecha(c.pdf.materializado_at) }}</span></dd></div>
            <div><dt :class="ETQ">Plantilla utilizable</dt><dd>{{ c.plantilla_usable ? "Sí" : "No" }}</dd></div>
            <div v-if="c.reemplazado_por" class="col-span-2"><dt :class="ETQ">Reemplazado por</dt><dd>Emisión #{{ c.reemplazado_por.emision_id }}</dd></div>
            <div><dt :class="ETQ">Corrida de migración</dt><dd>#{{ c.corrida?.id ?? "—" }} <span v-if="c.corrida" class="text-slate-400">({{ c.corrida.estado }})</span></dd></div>
            <div><dt :class="ETQ">Migrado el</dt><dd>{{ fecha(c.corrida?.fecha ?? c.creado_at) }}</dd></div>
          </dl>
        </section>
      </div>

      <!-- Plantilla -->
      <section v-if="c.plantilla" :class="[CAJA, 'mt-5']" data-plantilla>
        <h3 class="text-base font-extrabold text-slate-900">Plantilla</h3>
        <div class="mt-3 flex flex-wrap items-center gap-2">
          <Insignia :etiqueta="c.plantilla.estado_info.etiqueta" :tono="c.plantilla.estado_info.tono" />
          <span class="text-sm font-semibold text-slate-600 break-words">{{ c.plantilla.nombre }}</span>
        </div>
        <dl class="mt-3 grid grid-cols-2 md:grid-cols-4 gap-x-6 gap-y-3 text-sm font-semibold text-slate-600">
          <div><dt :class="ETQ">Tipo</dt><dd>{{ c.plantilla.mime ?? "—" }}</dd></div>
          <div><dt :class="ETQ">Tamaño</dt><dd>{{ c.plantilla.dimensiones ?? "—" }}</dd></div>
          <div><dt :class="ETQ">Peso</dt><dd>{{ c.plantilla.bytes ?? "—" }}</dd></div>
          <div><dt :class="ETQ">Huella</dt><dd class="font-mono">{{ c.plantilla.sha ?? "—" }}</dd></div>
        </dl>
      </section>

      <!-- Duplicados -->
      <section v-if="c.duplicado" :class="[CAJA, 'mt-5']" data-duplicados>
        <div class="flex items-center gap-2"><Layers class="w-4 h-4 text-sky-500" /><h3 class="text-base font-extrabold text-slate-900">Este registro pertenece a un grupo duplicado histórico.</h3></div>
        <p v-if="c.duplicado.clasificacion === 'identico'" class="mt-2 text-sm font-semibold text-slate-600">
          Los {{ c.duplicado.total }} registros tienen exactamente los mismos datos.
          Este es el registro <strong>{{ c.duplicado.canonico ? "canónico" : "no canónico" }}</strong>. Se conservan todos.
        </p>
        <p v-else class="mt-2 text-sm font-semibold text-slate-600">
          Hay {{ c.duplicado.total }} variantes con diferencias<span v-if="c.duplicado.diferencias.length"> en {{ c.duplicado.diferencias.join(", ") }}</span>. Se conservan todas.
        </p>
        <ul class="mt-4 divide-y divide-slate-100" data-variantes>
          <li v-for="(v, i) in c.duplicado.variantes" :key="v.id" class="flex flex-wrap items-center justify-between gap-3 py-2.5">
            <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
              Variante {{ i + 1 }} <span class="font-mono text-slate-400">· registro {{ v.old_id ?? "—" }}</span>
              <Star v-if="v.canonico" class="w-3.5 h-3.5 text-amber-500" title="Registro canónico" aria-label="Registro canónico" />
              <span v-if="v.actual" class="text-[11px] text-slate-400">(este)</span>
            </div>
            <div class="flex items-center gap-3">
              <Insignia :etiqueta="v.conciliacion.etiqueta" :tono="v.conciliacion.tono" />
              <Link v-if="!v.actual" :href="route('credential-flow.historico.certificados.show', v.id)" class="text-[12px] font-bold text-primary-vinotinto hover:underline">Ver</Link>
            </div>
          </li>
        </ul>
      </section>

      <div class="mt-5 grid grid-cols-1 lg:grid-cols-2 gap-5">
        <!-- Correos -->
        <section :class="CAJA" data-correos>
          <h3 class="text-base font-extrabold text-slate-900">Correos asociados</h3>
          <p v-if="c.correos.length === 0" class="mt-3 text-sm font-semibold text-slate-400">Este registro no tiene correos.</p>
          <div v-else class="mt-3 overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="text-[11px] uppercase tracking-wide text-slate-400"><tr><th class="text-left font-black py-2 pr-3">#</th><th class="text-left font-black py-2 pr-3">Correo</th><th class="text-left font-black py-2 pr-3">Estado</th><th class="text-left font-black py-2 pr-3">Principal</th><th class="text-left font-black py-2">Origen</th></tr></thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="x in c.correos" :key="x.orden">
                  <td class="py-2 pr-3 font-bold text-slate-400">{{ x.orden }}</td>
                  <td class="py-2 pr-3 font-semibold text-slate-700 break-all">{{ x.correo }}</td>
                  <td class="py-2 pr-3"><Insignia :etiqueta="x.estado" :tono="x.valido ? 'emerald' : 'rose'" /></td>
                  <td class="py-2 pr-3 font-semibold text-slate-600">{{ x.principal ? "Sí" : "No" }}</td>
                  <td class="py-2 font-semibold text-slate-600">{{ x.origen }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Descargas -->
        <section :class="CAJA" data-descargas>
          <h3 class="text-base font-extrabold text-slate-900">Descargas históricas</h3>
          <p v-if="c.descargas.total === 0" class="mt-3 text-sm font-semibold text-slate-400">Este certificado no tiene descargas registradas.</p>
          <template v-else>
            <p class="mt-1 text-[12px] font-semibold text-slate-400">Mostrando {{ c.descargas.mostradas }} de {{ c.descargas.total }}<span v-if="c.descargas.total > c.descargas.mostradas"> (las más recientes)</span></p>
            <div class="mt-3 overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead class="text-[11px] uppercase tracking-wide text-slate-400"><tr><th class="text-left font-black py-2 pr-3">Fecha</th><th class="text-left font-black py-2 pr-3">Vía</th><th class="text-left font-black py-2">Origen</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="(d, i) in c.descargas.items" :key="i">
                    <td class="py-2 pr-3 font-semibold text-slate-700 whitespace-nowrap">{{ fecha(d.fecha) }}</td>
                    <td class="py-2 pr-3 font-semibold text-slate-600">{{ d.via }}</td>
                    <td class="py-2 font-semibold text-slate-600">{{ d.origen }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </section>
      </div>
    </Sidebar>
  </AuthenticatedLayout>
</template>
