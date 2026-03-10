<script setup>
import { computed, ref, onMounted, onUnmounted, watch } from "vue";

const props = defineProps({
  modelValue: [String, Number],
  type: { type: String, default: "text" },
  label: String,
  placeholder: String,
  options: { type: Array, default: () => [] },
  icon: String,
  activeColor: String,
  required: Boolean,
  max: { type: Number, default: 50 },
  min: { type: Number, default: 4 },
  error: String,
  rows: { type: Number, default: 4 },
});

const emit = defineEmits(["update:modelValue", "clearError"]);

const isFocused = ref(false);
const isOpen = ref(false);
const showPassword = ref(false);
const selectRef = ref(null);
const localError = ref(props.error);
const dynamicStyle = computed(() => ({
  "--focused-color": props.activeColor || "#6366f1",
}));

watch(
  () => props.error,
  (newVal) => {
    localError.value = newVal;
  }
);

const toggleDropdown = () => {
  if (props.type === "select") {
    isOpen.value = !isOpen.value;
    isFocused.value = isOpen.value;
    if (localError.value) {
      localError.value = null;
      emit("clearError");
    }
  }
};

const togglePassword = () => {
  showPassword.value = !showPassword.value;
};

const selectOption = (option) => {
  const value =
    typeof option === "object"
      ? option.id !== undefined
        ? option.id
        : option.value
      : option;

  emit("update:modelValue", value);
  isOpen.value = false;
  isFocused.value = false;
};

const selectedLabel = computed(() => {
  if (
    props.modelValue === null ||
    props.modelValue === undefined ||
    props.modelValue === ""
  )
    return null;

  const found = props.options.find((opt) => {
    const val = typeof opt === "object" ? opt.id ?? opt.value : opt;
    return val == props.modelValue;
  });

  if (!found) return null;

  return typeof found === "object" ? found.nombre ?? found.label : found;
});

const handleClickOutside = (event) => {
  if (selectRef.value && !selectRef.value.contains(event.target)) {
    isOpen.value = false;
    isFocused.value = false;
  }
};

onMounted(() => document.addEventListener("click", handleClickOutside));
onUnmounted(() => document.removeEventListener("click", handleClickOutside));
const currentCount = computed(() => {
  if (props.type === "select") {
    return props.options?.length || 0;
  }
  return props.modelValue?.toString().length || 0;
});

const onInput = (e) => {
  let value = e.target.value;

  if (localError.value) {
    localError.value = null;
    emit("clearError");
  }

  if (value.length > props.max) {
    value = value.slice(0, props.max);
    e.target.value = value; 
  }

  if (props.type === "text") {
    value = value.charAt(0).toUpperCase() + value.slice(1);
  } else if (props.type === "number") {
    value = value.replace(/\D/g, "");
  } else if (props.type === "price") {
    let rawValue = value.replace(/\D/g, "");
    
    if (rawValue.length > props.max) {
      rawValue = rawValue.slice(0, props.max);
    }

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
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(props.modelValue);
});
</script>

<template>
  <div class="space-y-2 w-full group relative" ref="selectRef">
    <div class="flex justify-between items-end px-1">
      <label
        v-if="label"
        class="text-[13px] font-bold transition-colors"
        :style="{ color: localError ? '#dc2626' : isFocused ? activeColor : '#94a3b8' }"
      >
        {{ label }}: <span v-if="required" class="text-red-400">*</span>
      </label>
      <span
        class="text-[11px] font-bold transition-all"
        :class="[
          localError
            ? 'text-red-400'
            : type !== 'select' && currentCount >= max 
            ? 'text-red-500 animate-pulse'
            : 'text-slate-400',
        ]"
      >
        <template v-if="type === 'select'">
          {{ currentCount }} {{ currentCount === 1 ? "opción" : "opciones" }}
        </template>

        <template v-else> {{ currentCount }}/{{ max }} </template>
      </span>
    </div>

    <div
      @click="toggleDropdown"
      class="relative flex gap-2 px-4 py-3 bg-slate-50 border-2 rounded-xl transition-all duration-300"
      :class="[
        { 'bg-white shadow-xl ring-4 ring-opacity-10': isFocused },
        { 'cursor-pointer': type === 'select' },
        { 'items-start': type === 'textarea' ? 'items-start' : 'items-center' },
      ]"
      :style="[
        localError
          ? { borderColor: '#ef4444', background: '#fef2f2' }
          : isFocused
          ? { borderColor: activeColor, '--tw-ring-color': activeColor + '30' }
          : { borderColor: '#d1d5db' },
      ]"
    >
      <span
        v-if="icon"
        class="material-symbols-rounded shrink-0 transition-colors duration-300 select-none pointer-events-none"
        :style="{
          color: localError ? '#dc2626' : isFocused ? activeColor : '#cbd5e1',
          fontSize: '22px',
        }"
      >
        {{ icon }}
      </span>

      <textarea
        v-if="type === 'textarea'"
        :value="modelValue"
        :maxlength="max"
        @input="onInput"
        @focus="isFocused = true"
        @blur="isFocused = false"
        :placeholder="placeholder"
        :rows="rows"
        class="w-full bg-transparent border-none p-0 focus:ring-0 outline-none font-semibold text-slate-700 placeholder:text-slate-300 text-sm resize-none custom-scroll"
      ></textarea>

      <input
        v-else-if="type !== 'select'"
        :type="
          type === 'password'
            ? showPassword
              ? 'text'
              : 'password'
            : type === 'price'
            ? 'text'
            : type
        "
        :value="modelValue"
        :maxlength="max"
        @input="onInput"
        @focus="isFocused = true"
        @blur="isFocused = false"
        :placeholder="placeholder"
        class="w-full bg-transparent border-none p-0 focus:ring-0 outline-none font-semibold text-slate-700 placeholder:text-slate-300 text-sm"
      />

      <div v-else class="w-full flex items-center justify-between select-none">
        <span
          class="font-semibold text-sm transition-colors"
          :class="selectedLabel ? 'text-slate-700' : 'text-slate-300'"
        >
          {{ selectedLabel || placeholder || "Seleccione..." }}
        </span>
        <span
          class="material-symbols-rounded transition-transform duration-300 opacity-40"
          :class="{ 'rotate-180': isOpen }"
          >expand_more</span
        >
      </div>

      <div class="flex items-center gap-2">
        <button
          v-if="type === 'password' && modelValue"
          type="button"
          @click.stop="togglePassword"
          class="flex items-center justify-center hover:opacity-100 transition-opacity"
          :class="showPassword ? 'opacity-100' : 'opacity-30'"
        >
          <span
            class="material-symbols-rounded text-xl"
            :style="{ color: isFocused ? activeColor : '' }"
          >
            {{ showPassword ? "visibility" : "visibility_off" }}
          </span>
        </button>

        <span
          v-if="localError"
          class="material-symbols-rounded text-red-500 animate-in zoom-in text-xl"
          >error</span
        >
        <div
          v-else-if="type === 'email' && modelValue && isEmailValid"
          class="text-emerald-500 animate-in zoom-in flex items-center"
        >
          <span class="material-symbols-rounded text-xl">check_circle</span>
        </div>
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
      <div
        v-if="isOpen && type === 'select'"
        class="absolute left-0 right-0 mt-2 z-[110] bg-white border border-slate-100 shadow-[0_20px_50px_rgba(0,0,0,0.1)] rounded-[1.5rem] overflow-hidden p-1.5"
      >
        <div class="max-h-[220px] overflow-y-auto custom-scroll">
          <div
            v-for="opt in options"
            :key="typeof opt === 'object' ? opt.id ?? opt.value : opt"
            @click.stop="selectOption(opt)"
            class="flex items-center justify-between px-4 py-3 rounded-xl transition-all cursor-pointer mb-1 last:mb-0 group/opt"
            :class="
              modelValue === (typeof opt === 'object' ? opt.id ?? opt.value : opt)
                ? 'bg-slate-50'
                : 'hover:bg-slate-50'
            "
          >
            <span
              class="font-bold text-sm transition-colors"
              :style="{
                color:
                  modelValue === (typeof opt === 'object' ? opt.id ?? opt.value : opt)
                    ? activeColor
                    : '#64748b',
              }"
            >
              {{ typeof opt === "object" ? opt.nombre ?? opt.label : opt }}
            </span>

            <span
              v-if="modelValue === (typeof opt === 'object' ? opt.id ?? opt.value : opt)"
              class="material-symbols-rounded text-lg"
              :style="{ color: activeColor }"
            >
              done
            </span>
          </div>
        </div>
      </div>
    </transition>

    <p
      v-if="localError"
      class="mt-2 text-[12px] text-red-600 font-medium flex items-center gap-1 animate-in fade-in slide-in-from-top-1"
    >
      <span class="material-symbols-rounded text-sm">warning</span>
      {{ localError }}
    </p>
  </div>
</template>
