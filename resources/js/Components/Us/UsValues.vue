<script setup>
const values = [
  {
    title: 'Claridad radical',
    desc: 'Lo técnico se puede explicar bien. Si no se entiende, no sirve.',
    tagClass: 'bg-primary-vinotinto/10 text-primary-vinotinto',
  },
  {
    title: 'Rigor y trazabilidad',
    desc: 'Metodología, evidencia y entregables. Menos “opinión”, más soporte.',
    tagClass: 'bg-primary-verde/10 text-primary-verde',
  },
  {
    title: 'Practicidad',
    desc: 'Formación y consultoría pensadas para ejecutar, no solo para “cumplir”.',
    tagClass: 'bg-primary-naranja/10 text-primary-naranja',
  },
  {
    title: 'Respeto por el tiempo',
    desc: 'Sesiones enfocadas, materiales útiles y comunicación directa.',
    tagClass: 'bg-gray-100 text-gray-800',
  },
]
</script>

<template>
  <section class="max-w-7xl mx-auto px-6 py-16">
    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-6">
      <div>
        <h2 class="text-3xl md:text-4xl font-black tracking-tight text-gray-900">
          Principios que se notan en el trabajo
        </h2>
        <p class="mt-3 text-lg text-gray-600">
          No son frases para la pared. Son reglas de juego para entregar bien.
        </p>
      </div>
    </div>

    <div class="mt-10 grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
      <div
        v-for="(v, i) in values"
        :key="i"
        class="group rounded-[28px] border border-gray-200 bg-white shadow-2xl p-7 transition-all duration-300 hover:-translate-y-0.5"
      >
        <div class="flex items-center justify-between">
          <span class="text-sm font-bold px-3 py-1 rounded-full" :class="v.tagClass">Valor</span>
          <span class="text-gray-400 group-hover:text-gray-600 transition">↗</span>
        </div>
        <h3 class="mt-5 text-xl font-black text-gray-900">{{ v.title }}</h3>
        <p class="mt-3 text-gray-600 leading-relaxed">{{ v.desc }}</p>
      </div>
    </div>
  </section>
</template>
