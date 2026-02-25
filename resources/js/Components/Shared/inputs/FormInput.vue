<script setup>
import { computed, ref, onMounted, onUnmounted } from "vue";

const props = defineProps({
  modelValue: [String, Number],
  type: { type: String, default: "text" }, // text, number, email, price, select
  label: String,
  placeholder: String,
  options: { type: Array, default: () => [] }, 
  icon: String, // Nombre del icono de Google
  activeColor: String,
  required: Boolean,
  max: { type: Number, default: 50 }, 
});

const emit = defineEmits(["update:modelValue"]);

// --- ESTADOS Y REFERENCIAS ---
const isFocused = ref(false);
const isOpen = ref(false);
const selectRef = ref(null);

// --- LÓGICA DE SELECCIÓN PERSONALIZADA ---
const toggleDropdown = () => {
  if (props.type === "select") {
    isOpen.value = !isOpen.value;
    isFocused.value = isOpen.value;
  }
};

const selectOption = (option) => {
  const value = typeof option === "object" ? option.value : option;
  emit("update:modelValue", value);
  isOpen.value = false;
  isFocused.value = false;
};

const selectedLabel = computed(() => {
  if (!props.modelValue) return null;
  const found = props.options.find(
    (opt) => (typeof opt === "object" ? opt.value : opt) === props.modelValue
  );
  return typeof found === "object" ? found.label : found;
});

const handleClickOutside = (event) => {
  if (selectRef.value && !selectRef.value.contains(event.target)) {
    isOpen.value = false;
    isFocused.value = false;
  }
};

onMounted(() => document.addEventListener("click", handleClickOutside));
onUnmounted(() => document.removeEventListener("click", handleClickOutside));

// --- LÓGICA DE FORMATEO Y CONTEO ---
const currentCount = computed(() => {
  if (!props.modelValue) return 0;
  return props.modelValue.toString().length;
});

const onInput = (e) => {
  let value = e.target.value;
  if (value.length > props.max) value = value.slice(0, props.max);

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
</script>

<template>
  <div class="space-y-2 w-full group relative" ref="selectRef">
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
        class="text-[10px] font-bold text-slate-400"
      >
        {{ options.length }} opciones
      </span>
    </div>

    <div
      @click="toggleDropdown"
      class="relative flex gap-2 items-center px-3 py-2 bg-slate-50 border-2  border-transparent rounded-lg transition-all duration-300"
      
      :style="isFocused ? { borderColor: activeColor} : { borderColor: '#d1d5db'}"
    >
      <span
        v-if="icon"
        class="material-symbols-rounded shrink-0 transition-colors duration-300 select-none pointer-events-none"
        :style="{ color: isFocused ? activeColor : '#cbd5e1', fontSize: '22px' }"
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
        class="w-full bg-transparent border-none p-0 focus:ring-0 outline-none font-semibold text-slate-700 placeholder:text-slate-300 placeholder:font-medium text-sm"
      />

      <div v-else class="w-full flex items-center justify-between select-none">
        <span class="font-semibold text-sm transition-colors" :class="selectedLabel ? 'text-slate-700' : 'text-slate-300'">
          {{ selectedLabel || placeholder || "Seleccione una opción" }}
        </span>
        <span 
          class="material-symbols-rounded transition-transform duration-300 opacity-40" 
          :class="{ 'rotate-180': isOpen }"
          :style="{ color: isFocused ? activeColor : '' }"
        >
          expand_more
        </span>
      </div>

      <div v-if="type === 'email' && modelValue && isEmailValid" class="text-emerald-500 animate-in zoom-in flex items-center">
        <span class="material-symbols-rounded text-xl">check_circle</span>
      </div>
    </div>

    <transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="transform scale-95 opacity-0 -translate-y-2"
      enter-to-class="transform scale-100 opacity-100 translate-y-0"
      leave-active-class="transition duration-150 ease-in"
      leave-from-class="transform scale-100 opacity-100 translate-y-0"
      leave-to-class="transform scale-95 opacity-0 -translate-y-2"
    >
      <div v-if="isOpen && type === 'select'" 
           class="absolute left-0 right-0 mt-2 z-[110] bg-white border border-slate-100 shadow-[0_20px_50px_rgba(0,0,0,0.1)] rounded-[1.5rem] overflow-hidden p-1.5">
        <div class="max-h-[220px] overflow-y-auto custom-scroll">
          <div 
            v-for="opt in options" :key="typeof opt === 'object' ? opt.value : opt"
            @click.stop="selectOption(opt)"
            class="flex items-center justify-between px-2 py-2 rounded-xl transition-all cursor-pointer mb-1 last:mb-0 group/opt"
            :class="modelValue === (typeof opt === 'object' ? opt.value : opt) ? 'bg-slate-50' : 'hover:bg-slate-50'"
          >
            <span class="font-bold text-sm transition-colors" 
                  :style="{ color: modelValue === (typeof opt === 'object' ? opt.value : opt) ? activeColor : '#64748b' }">
              {{ typeof opt === 'object' ? opt.label : opt }}
            </span>
            
            <span v-if="modelValue === (typeof opt === 'object' ? opt.value : opt)" 
                  class="material-symbols-rounded text-lg" :style="{ color: activeColor }">
              done
            </span>
          </div>
        </div>
      </div>
    </transition>

    <p v-if="type === 'email' && !isEmailValid && modelValue"
       class="text-[12px] font-bold text-red-500 ml-2 animate-in fade-in slide-in-from-top-1 flex items-center gap-1">
      <span class="material-symbols-rounded text-xs">error</span>
      Correo electrónico inválido
    </p>
  </div>
</template>

<style scoped>
/* Scrollbar estético para el desplegable */
.custom-scroll::-webkit-scrollbar {
  width: 4px;
}
.custom-scroll::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scroll::-webkit-scrollbar-thumb {
  background: #f1f5f9;
  border-radius: 10px;
}
.custom-scroll::-webkit-scrollbar-thumb:hover {
  background: #e2e8f0;
}
</style>