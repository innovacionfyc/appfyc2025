<script setup>
import { Head } from "@inertiajs/vue3";
</script>

<template>
  <Head title="Bienvenido a F&C Consultores" />

  <div>
    <div class="flex w-full">
      <div class="mx-5 bg-mono-blanco p-2">
        <h1>Dashboard White:</h1>

        <h1>Monocromaticos:</h1>

        <div class="paleta flex gap-2 flex-wrap">
          <div class="w-[165px] h-[165px] rounded-xl bg-mono-negro"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-mono-negro_opacity_medio"></div>

          <div class="w-[165px] h-[165px] rounded-xl bg-mono-blanco border"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-mono-blanco_opacity border"></div>
        </div>

        <h1>Primarios:</h1>

        <div class="paleta flex gap-2 flex-wrap">
          <div class="w-[165px] h-[165px] rounded-xl bg-primary-vinotinto"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-primary-verde"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-primary-naranja"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-primary-gris"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-primary-toronja"></div>
        </div>

        <h1>Secundarios:</h1>

        <div class="paleta flex gap-2 flex-wrap">
          <div class="w-[165px] h-[165px] rounded-xl bg-secondary-vinotinto2"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-secondary-verde2"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-secondary-naranja2"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-secondary-gris2"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-secondary-toronja2"></div>
        </div>

        <h1>Gradientes:</h1>

        <div class="paleta flex gap-2 flex-wrap">
          <div class="w-[165px] h-[165px] rounded-xl bg-gradient-vinotinto"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-gradient-verde"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-gradient-naranja"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-gradient-gris"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-gradient-toronja"></div>
        </div>

        <h1>Semaforos</h1>

        <div class="paleta flex gap-2 flex-wrap">
          <div class="w-[165px] h-[165px] rounded-xl bg-semaforo-verde"></div>

          <div class="w-[165px] h-[165px] rounded-xl bg-semaforo-verde_opacity"></div>

          <div class="w-[165px] h-[165px] rounded-xl bg-semaforo-amarillo"></div>

          <div class="w-[165px] h-[165px] rounded-xl bg-semaforo-amarillo_opacity"></div>

          <div class="w-[165px] h-[165px] rounded-xl bg-semaforo-rojo"></div>

          <div class="w-[165px] h-[165px] rounded-xl bg-semaforo-rojo_opacity"></div>
        </div>

        <h1>Extra</h1>

        <div class="paleta flex gap-2 flex-wrap">
          <div class="w-[165px] h-[165px] rounded-xl bg-extra-default"></div>

          <div class="w-[165px] h-[165px] rounded-xl bg-extra-light"></div>

          <div class="w-[165px] h-[165px] rounded-xl bg-extra-opacity"></div>
        </div>
      </div>

      <div class="mx-5 bg-mono-negro text-mono-blanco p-2">
        <h1>Dashboard White:</h1>

        <h1>Monocromaticos:</h1>

        <div class="paleta flex gap-2 flex-wrap">
          <div
            class="w-[165px] h-[165px] rounded-xl bg-mono-negro border border-extra-light"
          ></div>
          <div
            class="w-[165px] h-[165px] rounded-xl bg-mono-negro_opacity_medio border border-extra-light"
          ></div>

          <div class="w-[165px] h-[165px] rounded-xl bg-mono-blanco border"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-mono-blanco_opacity border"></div>
        </div>

        <h1>Primarios:</h1>

        <div class="paleta flex gap-2 flex-wrap">
          <div class="w-[165px] h-[165px] rounded-xl bg-primary-vinotinto"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-primary-verde"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-primary-naranja"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-primary-gris"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-primary-toronja"></div>
        </div>

        <h1>Secundarios:</h1>

        <div class="paleta flex gap-2 flex-wrap">
          <div class="w-[165px] h-[165px] rounded-xl bg-secondary-vinotinto2"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-secondary-verde2"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-secondary-naranja2"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-secondary-gris2"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-secondary-toronja2"></div>
        </div>

        <h1>Gradientes:</h1>

        <div class="paleta flex gap-2 flex-wrap">
          <div class="w-[165px] h-[165px] rounded-xl bg-gradient-vinotinto"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-gradient-verde"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-gradient-naranja"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-gradient-gris"></div>
          <div class="w-[165px] h-[165px] rounded-xl bg-gradient-toronja"></div>
        </div>

        <h1>Semaforos</h1>

        <div class="paleta flex gap-2 flex-wrap">
          <div class="w-[165px] h-[165px] rounded-xl bg-semaforo-verde"></div>

          <div class="w-[165px] h-[165px] rounded-xl bg-semaforo-verde_opacity"></div>

          <div class="w-[165px] h-[165px] rounded-xl bg-semaforo-amarillo"></div>

          <div class="w-[165px] h-[165px] rounded-xl bg-semaforo-amarillo_opacity"></div>

          <div class="w-[165px] h-[165px] rounded-xl bg-semaforo-rojo"></div>

          <div class="w-[165px] h-[165px] rounded-xl bg-semaforo-rojo_opacity"></div>
        </div>

        <h1>Extra</h1>

        <div class="paleta flex gap-2 flex-wrap">
          <div class="w-[165px] h-[165px] rounded-xl bg-extra-default"></div>

          <div class="w-[165px] h-[165px] rounded-xl bg-extra-light"></div>

          <div
            class="w-[165px] h-[165px] rounded-xl bg-extra-opacity border border-extra-light"
          ></div>
        </div>
      </div>
    </div>
  </div>
</template>