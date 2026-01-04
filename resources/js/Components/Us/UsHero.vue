<script setup>
import BtnUniversal from '@/Components/BtnUniversal.vue'
import { Link } from '@inertiajs/vue3'
</script>

<template>
  <section class="relative overflow-hidden">
    <!-- Fondo suave -->
    <div class="absolute inset-0">
      <div class="absolute -top-40 -left-40 w-[520px] h-[520px] rounded-full blur-3xl opacity-30 bg-primary-vinotinto"></div>
      <div class="absolute -bottom-48 -right-48 w-[620px] h-[620px] rounded-full blur-3xl opacity-25 bg-primary-naranja"></div>
      <div class="absolute inset-0 bg-gradient-to-b from-white via-white/70 to-white"></div>
    </div>

    <div class="relative max-w-7xl mx-auto px-6 py-16 md:py-24">
      <div class="grid lg:grid-cols-2 gap-10 items-center">
        <!-- Texto -->
        <div>
          <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full border border-gray-200 bg-white/70 backdrop-blur">
            <span class="w-2 h-2 rounded-full bg-primary-vinotinto"></span>
            <span class="text-sm font-bold tracking-wide text-gray-700">SOBRE F&amp;C CONSULTORES</span>
          </div>

          <!-- Título con degradado corporativo -->
          <h1 class="mt-6 text-4xl md:text-5xl font-black tracking-tight text-gray-900 leading-tight">
            <span class="text-transparent bg-clip-text bg-gradient-to-r from-primary-vinotinto to-primary-naranja">
              Rigor técnico
            </span>
            , lenguaje claro y resultados que sí se pueden sostener.
          </h1>

          <p class="mt-5 text-lg text-gray-600 leading-relaxed">
            Acompañamos entidades públicas y organizaciones privadas con formación y consultoría aplicada.
            Menos diapositiva “bonita”, más herramientas que funcionan en el mundo real.
          </p>

          <div class="mt-6 flex flex-wrap gap-3">
            <span class="px-4 py-2 rounded-full bg-primary-vinotinto/10 text-primary-vinotinto font-bold text-sm">
              Sector público
            </span>
            <span class="px-4 py-2 rounded-full bg-primary-naranja/10 text-primary-naranja font-bold text-sm">
              Sector privado
            </span>
            <span class="px-4 py-2 rounded-full bg-primary-verde/10 text-primary-verde font-bold text-sm">
              Metodología práctica
            </span>
          </div>

          <div class="mt-10 flex flex-col sm:flex-row gap-3 sm:items-center">
            <Link href="/contacto">
              <BtnUniversal label="Hablemos" icon="rocket_launch" icon-position="right" size="lg" />
            </Link>

            <a href="#metodo" class="inline-flex items-center justify-center px-5 py-3 rounded-2xl border border-gray-200 bg-white/70 backdrop-blur font-bold text-gray-800 hover:border-gray-300 transition">
              Ver nuestro enfoque
            </a>
          </div>
        </div>

        <!-- Tarjeta visual -->
        <div class="relative">
          <div class="rounded-[28px] border border-gray-200 bg-white/80 backdrop-blur shadow-2xl p-8">
            <div class="flex items-start justify-between">
              <div class="w-12 h-12 rounded-2xl bg-primary-vinotinto/10 flex items-center justify-center">
                <span class="text-primary-vinotinto font-black">F&amp;C</span>
              </div>
              <div class="w-10 h-10 rounded-full border border-gray-200 flex items-center justify-center text-gray-500">
                ↗
              </div>
            </div>

            <h3 class="mt-6 text-2xl font-black text-gray-900">
              Lo que hacemos,
              <span class="text-transparent bg-clip-text bg-gradient-to-r from-primary-vinotinto to-primary-naranja">
                sin adornos
              </span>
            </h3>

            <p class="mt-3 text-gray-600">
              Fortalecemos capacidades con formación, acompañamiento y consultoría. El objetivo es que tu equipo
              salga con claridad, decisiones mejor soportadas y un plan ejecutable.
            </p>

            <div class="mt-6 space-y-3">
              <div class="flex items-center gap-3 p-4 rounded-2xl border border-gray-200">
                <div class="w-10 h-10 rounded-xl bg-primary-naranja/10 flex items-center justify-center">
                  <span class="text-primary-naranja font-black">✓</span>
                </div>
                <div>
                  <p class="font-bold text-gray-900">Capacitación aplicada</p>
                  <p class="text-sm text-gray-600">Casos reales + ejercicios + guías</p>
                </div>
              </div>

              <div class="flex items-center gap-3 p-4 rounded-2xl border border-gray-200">
                <div class="w-10 h-10 rounded-xl bg-primary-verde/10 flex items-center justify-center">
                  <span class="text-primary-verde font-black">✓</span>
                </div>
                <div>
                  <p class="font-bold text-gray-900">Consultoría con método</p>
                  <p class="text-sm text-gray-600">Diagnóstico → ruta → entregables</p>
                </div>
              </div>

              <div class="flex items-center gap-3 p-4 rounded-2xl border border-gray-200">
                <div class="w-10 h-10 rounded-xl bg-primary-vinotinto/10 flex items-center justify-center">
                  <span class="text-primary-vinotinto font-black">✓</span>
                </div>
                <div>
                  <p class="font-bold text-gray-900">Acompañamiento estratégico</p>
                  <p class="text-sm text-gray-600">Decisiones con sustento y trazabilidad</p>
                </div>
              </div>
            </div>

            <p class="mt-6 text-xs text-gray-500">
              *Esta es una versión base de “Nosotros” para lanzar la página. Ajustamos con tu info real cuando la tengas.
            </p>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
