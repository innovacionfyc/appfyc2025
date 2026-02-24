<script setup>
import { computed, ref } from "vue";

const props = defineProps({
  modelValue: [String, Number],
  type: { type: String, default: "text" }, // text, number, email, price, select
  label: String,
  placeholder: String,
  options: { type: Array, default: () => [] }, // Para el tipo select
  icon: Object, // Icono de Lucide
  activeColor: String,
  required: Boolean,
  max: { type: Number, default: 50 }, // Tope de caracteres
});

const emit = defineEmits(["update:modelValue"]);

// --- LÓGICA DE FORMATEO Y CONTEO ---

const currentCount = computed(() => {
  if (!props.modelValue) return 0;
  // Si es precio, contamos el valor real (sin puntos/signos) o el visual?
  // Normalmente el usuario se guía por lo que ve, así que contamos el string actual.
  return props.modelValue.toString().length;
});

const onInput = (e) => {
  let value = e.target.value;

  // Bloqueo de seguridad si supera el max (por si el maxlength falla)
  if (value.length > props.max) {
    value = value.slice(0, props.max);
  }

  if (props.type === "text") {
    value = value.charAt(0).toUpperCase() + value.slice(1);
  } else if (props.type === "number") {
    value = value.replace(/\D/g, "");
  } else if (props.type === "price") {
    let rawValue = value.replace(/\D/g, "");
    if (rawValue) {
      value = new Intl.NumberFormat("es-CO", {
        style: "currency",
        currency: "COP",
        maximumFractionDigits: 0,
      }).format(rawValue);
    } else {
      value = "";
    }
    emit("update:modelValue", rawValue);
    return;
  }

  emit("update:modelValue", value);
};

const isEmailValid = computed(() => {
  if (props.type !== "email" || !props.modelValue) return true;
  const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return regex.test(props.modelValue);
});

const isFocused = ref(false);
</script>

<template>
  <div class="space-y-2 w-full group">
    <div class="flex justify-between items-end px-1">
      <label
        v-if="label"
        class="text-[13px] font-bold text-slate-400 transition-colors"
        :style="{ color: isFocused ? activeColor : '' }"
      >
        {{ label }} <span v-if="required" class="text-red-400">*</span>
        <span class="text-[10px] opacity-50 font-medium" v-else>(Opcional)</span>
      </label>

      <span
        v-if="type !== 'select'"
        class="text-[10px] font-bold tracking-widest transition-all"
        :class="currentCount >= max ? 'text-red-500 animate-pulse' : 'text-slate-300'"
      >
        {{ currentCount }}/{{ max }}
      </span>

      <span
        v-else
        class="text-[10px] font-bold text-slate-300 uppercase tracking-tighter"
      >
        {{ options.length }} opciones disponibles
      </span>
    </div>

    <div
      class="relative flex gap-2 items-center px-3 py-2 bg-slate-50 border-transparent rounded-lg transition-all duration-300"
      :class="{ 'bg-white shadow-xl ring-2 ring-opacity-10': isFocused }"
      :style="
        isFocused ? { borderColor: activeColor, '--tw-ring-color': activeColor } : {}
      "
    >
      <span
        v-if="icon"
        class="material-symbols-rounded shrink-0 transition-colors duration-300 select-none pointer-events-none"
        :style="{
          color: isFocused ? activeColor : '#cbd5e1',
          fontSize: '20px',
        }"
      >
        {{ icon }}
      </span>

      <input
        v-if="type !== 'select'"
        :type="type === 'price' ? 'text' : type"
        :value="modelValue"
        :maxlength="max"
        @input="onInput"
        @focus="isFocused = true"
        @blur="isFocused = false"
        :placeholder="placeholder"
        class="w-full bg-transparent border-none p-0 focus:ring-0 outline-none font-semibold text-slate-700 placeholder:text-slate-300 placeholder:font-medium"
      />

      <select
        v-else
        :value="modelValue"
        @change="emit('update:modelValue', $event.target.value)"
        @focus="isFocused = true"
        @blur="isFocused = false"
        class="w-full bg-transparent border-none p-0 focus:ring-0 outline-none font-semibold text-slate-700 appearance-none cursor-pointer"
      >
        <option value="" disabled selected>
          {{ placeholder || "Seleccione una opción" }}
        </option>
        <option v-for="opt in options" :key="opt.value || opt" :value="opt.value || opt">
          {{ opt.label || opt }}
        </option>
      </select>

      <div v-if="type === 'select'" class="ml-2 pointer-events-none opacity-30">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="3"
            d="M19 9l-7 7-7-7"
          />
        </svg>
      </div>

      <div
        v-if="type === 'email' && modelValue && isEmailValid"
        class=" text-emerald-500 animate-in zoom-in flex items-center justify-center"
      >
        <span class="material-symbols-rounded">check</span>
      </div>
    </div>

    <p
      v-if="type === 'email' && !isEmailValid && modelValue"
      class="text-[10px] font-bold text-red-500 ml-2 animate-in fade-in slide-in-from-top-1 flex items-center gap-1"
    > 
        <span class="material-symbols-rounded text-xs">cancel</span>
      Por favor ingresa un correo electrónico válido
    </p>
  </div>
</template>
