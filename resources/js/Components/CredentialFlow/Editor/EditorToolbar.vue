<script setup>
import { Plus, QrCode, AlignHorizontalJustifyCenter, AlignVerticalJustifyCenter, Trash2, Check, CircleAlert, FileDown, Loader2 } from "lucide-vue-next";
import BtnUniversal from "@/Components/BtnUniversal.vue";

defineProps({
  haySeleccion: { type: Boolean, default: false },
  sinGuardar: { type: Boolean, default: false },
  guardando: { type: Boolean, default: false },
  puedeAgregar: { type: Boolean, default: true },
  // QR de verificación (opcional): solo uno por plantilla; el botón se deshabilita si ya existe.
  puedeAgregarQr: { type: Boolean, default: true },
  deshabilitado: { type: Boolean, default: false },
  // Hay un campo desconocido o una fuente sin cargar: no se puede guardar hasta corregirlo.
  guardarBloqueado: { type: Boolean, default: false },
  // PDF de prueba: se genera siempre desde el diseño GUARDADO. `motivoNoGenerar` explica por qué el
  // botón está deshabilitado (null = disponible).
  generando: { type: Boolean, default: false },
  motivoNoGenerar: { type: String, default: null },
});

defineEmits(["agregar-texto", "agregar-qr", "centrar-horizontal", "centrar-vertical", "eliminar", "guardar", "generar-prueba"]);

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

    <button
      type="button"
      :class="[boton, 'bg-slate-50 text-slate-700 hover:bg-slate-100']"
      :disabled="deshabilitado || !puedeAgregarQr"
      :title="puedeAgregarQr ? 'Agrega un QR que lleva a la página pública de verificación de cada credencial. Es opcional: sin este elemento las credenciales no llevan QR.' : 'Esta plantilla ya tiene su QR de verificación (máximo uno).'"
      data-accion="agregar-qr"
      @click="$emit('agregar-qr')"
    >
      <QrCode class="w-4 h-4" />
      QR de verificación (opcional)
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

      <button
        type="button"
        :class="[boton, 'bg-slate-100 text-slate-600 hover:bg-slate-200']"
        :disabled="deshabilitado || generando || !!motivoNoGenerar"
        :title="motivoNoGenerar ?? 'Genera un PDF con el diseño guardado y datos de prueba'"
        data-accion="generar-pdf-prueba"
        @click="$emit('generar-prueba')"
      >
        <Loader2 v-if="generando" class="w-4 h-4 animate-spin" />
        <FileDown v-else class="w-4 h-4" />
        {{ generando ? "Generando…" : "Generar PDF de prueba" }}
      </button>

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
        :title="guardarBloqueado ? 'Corrige los elementos marcados en rojo para poder guardar' : undefined"
        @click="$emit('guardar')"
      />
    </div>
  </div>
</template>
