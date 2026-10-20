<script setup>
// Campo de una sola línea lógica (nombre, correo) que CRECE hacia abajo para mostrar el texto completo sin cortarlo. Se edita directamente, como un campo normal;
// la tecla Enter no inserta saltos de línea.
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";

const props = defineProps({
  modelValue: { type: String, default: "" },
  id: { type: String, required: true },
  maxlength: { type: Number, default: 255 },
  disabled: { type: Boolean, default: false },
  invalido: { type: Boolean, default: false },
  inputmode: { type: String, default: "text" },
  campo: { type: String, default: "" },
});
const emit = defineEmits(["update:modelValue"]);

const el = ref(null);
let observador = null;

function ajustar() {
  const t = el.value;
  if (!t) return;
  t.style.height = "auto";
  t.style.height = `${t.scrollHeight + 2}px`;
}

onMounted(() => {
  ajustar();
  // Si cambia el ancho (otra ventana, otro paso), se vuelve a medir.
  if (typeof ResizeObserver !== "undefined") {
    observador = new ResizeObserver(() => ajustar());
    observador.observe(el.value);
  }
});
onBeforeUnmount(() => observador?.disconnect());
watch(() => props.modelValue, () => nextTick(ajustar));
</script>

<template>
  <textarea
    :id="id"
    ref="el"
    rows="1"
    :value="modelValue"
    :maxlength="maxlength"
    :disabled="disabled"
    :inputmode="inputmode"
    :aria-invalid="invalido"
    :data-campo-fila="campo || undefined"
    spellcheck="false"
    class="block w-full resize-none overflow-hidden rounded-lg border-slate-300 text-[13px] font-semibold leading-snug py-1.5 break-words focus:border-primary-vinotinto focus:ring-primary-vinotinto/30 disabled:bg-slate-50"
    @input="emit('update:modelValue', $event.target.value.replace(/[\r\n]+/g, ' '))"
    @keydown.enter.prevent
  ></textarea>
</template>
