<script setup>
import { Plus, AlignHorizontalJustifyCenter, AlignVerticalJustifyCenter, Trash2, Check, CircleAlert } from "lucide-vue-next";
import BtnUniversal from "@/Components/BtnUniversal.vue";

defineProps({
  haySeleccion: { type: Boolean, default: false },
  sinGuardar: { type: Boolean, default: false },
  guardando: { type: Boolean, default: false },
  puedeAgregar: { type: Boolean, default: true },
  deshabilitado: { type: Boolean, default: false },
  // Hay un campo dinámico desconocido: no se puede guardar hasta elegir uno válido.
  guardarBloqueado: { type: Boolean, default: false },
});

defineEmits(["agregar-texto", "centrar-horizontal", "centrar-vertical", "eliminar", "guardar"]);

const COLOR = "#942934";

const boton =
  "inline-flex items-center gap-2 px-3.5 py-2.5 rounded-xl text-[13px] font-bold transition-all disabled:opacity-40 disabled:cursor-not-allowed";
</script>

<template>
  <div
    class="bg-white rounded-[1.5rem] border border-slate-100 shadow-sm px-4 py-3 flex flex-wrap items-center gap-2 sm:gap-3"
    role="toolbar"
    aria-label="Herramientas del editor"
  >
    <button
      type="button"
      :class="[boton, 'bg-primary-vinotinto/10 text-primary-vinotinto hover:bg-primary-vinotinto/20']"
      :disabled="deshabilitado || !puedeAgregar"
      @click="$emit('agregar-texto')"
    >
      <Plus class="w-4 h-4" />
      Agregar texto
    </button>

    <span class="hidden sm:block w-px h-6 bg-slate-200" />

    <button
      type="button"
      :class="[boton, 'bg-slate-50 text-slate-600 hover:bg-slate-100']"
      :disabled="deshabilitado || !haySeleccion"
      title="Centrar horizontalmente en la página"
      @click="$emit('centrar-horizontal')"
    >
      <AlignHorizontalJustifyCenter class="w-4 h-4" />
      Centrar H
    </button>
    <button
      type="button"
      :class="[boton, 'bg-slate-50 text-slate-600 hover:bg-slate-100']"
      :disabled="deshabilitado || !haySeleccion"
      title="Centrar verticalmente en la página"
      @click="$emit('centrar-vertical')"
    >
      <AlignVerticalJustifyCenter class="w-4 h-4" />
      Centrar V
    </button>
    <button
      type="button"
      :class="[boton, 'bg-rose-50 text-rose-500 hover:bg-rose-100']"
      :disabled="deshabilitado || !haySeleccion"
      title="Eliminar el elemento seleccionado (Supr)"
      @click="$emit('eliminar')"
    >
      <Trash2 class="w-4 h-4" />
      Eliminar
    </button>

    <div class="ml-auto flex items-center gap-3">
      <span
        v-if="sinGuardar"
        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-500/10 text-amber-600 text-[12px] font-bold ring-1 ring-amber-500/20"
        data-estado="sin-guardar"
      >
        <CircleAlert class="w-3.5 h-3.5" />
        Cambios sin guardar
      </span>
      <span
        v-else
        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-emerald-500/10 text-emerald-600 text-[12px] font-bold ring-1 ring-emerald-500/20"
        data-estado="guardado"
      >
        <Check class="w-3.5 h-3.5" />
        Guardado
      </span>

      <BtnUniversal
        label="Guardar diseño"
        icon="save"
        icon-position="right"
        size="md"
        :activeColor="COLOR"
        :loading="guardando"
        :disabled="guardando"
        process="Guardando..."
        :class="['sm:w-auto', !guardando && (deshabilitado || guardarBloqueado || !sinGuardar) ? 'opacity-50 pointer-events-none' : '']"
        :aria-disabled="!guardando && (deshabilitado || guardarBloqueado || !sinGuardar)"
        :title="guardarBloqueado ? 'Elige un campo válido en los elementos marcados para poder guardar' : undefined"
        @click="$emit('guardar')"
      />
    </div>
  </div>
</template>
