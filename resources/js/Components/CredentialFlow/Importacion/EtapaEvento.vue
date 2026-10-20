<script setup>
// Etapa 2 · Confirmar la capacitación. UNA pregunta; el asistente muestra la más parecida, pero la decisión es tuya: nada se elige ni se crea por ti,
// y la capacitación nueva solo se crea al final, al «Crear base de participantes». El botón para continuar es el principal de la barra de abajo.
import { computed } from "vue";
import TarjetaEvento from "./TarjetaEvento.vue";
import { registrarPrincipal, useAsistente } from "@/Composables/CredentialFlow/importacionInteligente";

const a = useAsistente();
const d = computed(() => a.evento.value?.decision ?? {});
const nombre = computed(() => {
  const ev = a.evento.value;
  if (d.value.decision === "asociar") return ev?.propuesta?.candidatos?.find((c) => c.id === d.value.evento_id)?.nombre ?? "la capacitación elegida";

  return d.value.nombre ?? ev?.propuesta?.nombre_excel ?? "la capacitación nueva";
});

async function confirmar() {
  if (!a.eventoListo.value || a.guardando.value) return;
  const texto = d.value.decision === "asociar" ? `Confirmaste que es la capacitación «${nombre.value}».` : `Confirmaste que es una capacitación nueva: «${nombre.value}».`;
  if (!(await a.guardar())) return;
  if (a.origen.value) return a.volverAlOrigen();
  await a.irA("datos");
  a.hecho.value = texto;
}

registrarPrincipal(a, () =>
  a.eventoListo.value
    ? { texto: a.origen.value ? `Guardar y volver a «${a.origen.value.titulo}»` : "Confirmar y seguir", accion: confirmar }
    : { texto: "Elegir una opción", accion: a.irARecomendado, razon: "Responde la pregunta de arriba para poder continuar." }
);
</script>

<template>
  <section class="space-y-5" aria-labelledby="titulo-etapa-evento">
    <div>
      <h2 id="titulo-etapa-evento" tabindex="-1" class="text-xl font-extrabold text-slate-900 focus:outline-none">Confirma la capacitación</h2>
      <p class="mt-1 text-sm font-medium text-slate-500">
        Leímos el nombre en tu Excel y lo buscamos entre las capacitaciones ya registradas, para no crear una repetida. <strong>Tú decides.</strong>
      </p>
    </div>

    <TarjetaEvento />

    <p v-if="a.eventoListo.value" class="rounded-2xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900" data-evento-elegido>
      <strong class="font-extrabold">Elegiste:</strong>
      {{ d.decision === "asociar" ? `agregar las personas a «${nombre}».` : `crear una capacitación nueva («${nombre}»).` }}
      Todavía no se creó ni se cambió nada. Para seguir, pulsa el botón de abajo.
    </p>
    <p v-else class="text-sm font-semibold text-slate-500" data-falta-evento>Cuando elijas una respuesta, el botón de abajo te dejará continuar.</p>
  </section>
</template>
