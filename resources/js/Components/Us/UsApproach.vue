<script setup>
const steps = [
  {
    t: 'Diagnóstico',
    d: 'Escuchamos y revisamos el contexto: objetivos, riesgos, procesos y capacidades del equipo.',
    badge: 'bg-primary-vinotinto/10 text-primary-vinotinto',
  },
  {
    t: 'Diseño',
    d: 'Definimos ruta, metodología y entregables: lo que se hará y cómo se medirá.',
    badge: 'bg-primary-naranja/10 text-primary-naranja',
  },
  {
    t: 'Ejecución',
    d: 'Talleres, sesiones y acompañamiento. Enfoque práctico, aplicable y con evidencia.',
    badge: 'bg-primary-verde/10 text-primary-verde',
  },
  {
    t: 'Cierre',
    d: 'Entregables claros: plantillas, guías, recomendaciones y próximos pasos.',
    badge: 'bg-gray-100 text-gray-800',
  },
]
</script>

<template>
  <section id="metodo" class="max-w-7xl mx-auto px-6 py-16 scroll-mt-32">
    <div class="grid lg:grid-cols-2 gap-10 items-start">
      <div>
        <h2 class="text-3xl md:text-4xl font-black tracking-tight text-gray-900">
          Un enfoque que se puede explicar, ejecutar y auditar.
        </h2>
        <p class="mt-4 text-lg text-gray-600 leading-relaxed">
          Trabajamos con una metodología simple y consistente. La idea es que el avance sea visible,
          medible y defendible.
        </p>
      </div>

      <div class="grid sm:grid-cols-2 gap-6">
        <div v-for="(s, i) in steps" :key="i" class="rounded-[28px] border border-gray-200 bg-white shadow-2xl p-7">
          <span class="inline-flex text-sm font-bold px-3 py-1 rounded-full" :class="s.badge">
            Paso {{ i + 1 }}
          </span>
          <h3 class="mt-4 text-xl font-black text-gray-900">{{ s.t }}</h3>
          <p class="mt-3 text-gray-600">{{ s.d }}</p>
        </div>
      </div>
    </div>
  </section>
</template>
