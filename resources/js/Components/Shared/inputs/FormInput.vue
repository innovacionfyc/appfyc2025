<script setup>
import { computed, ref, onMounted, onUnmounted, watch } from "vue";
import "@vuepic/vue-datepicker/dist/main.css";
import { VueDatePicker } from "@vuepic/vue-datepicker";

const props = defineProps({
  modelValue: [String, Number, Array],
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
  range: { type: Boolean, default: false },
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

const today = new Date();

const formatRangeFull = (range) => {
  if (!range || !range[0]) return "";

  const start = new Date(range[0]);
  const end = range[1] ? new Date(range[1]) : null;

  const getDayName = (d) => d.toLocaleString("es-ES", { weekday: "long" });
  const getDayNum = (d) => d.getDate();
  const getMonth = (d) => d.toLocaleString("es-ES", { month: "long" });
  const getYear = (d) => d.getFullYear();

  if (!end || start.getTime() === end.getTime()) {
    return `${getDayName(start)} ${getDayNum(start)} de ${getMonth(start)} de ${getYear(
      start
    )} | todo el día`;
  }

  if (start.getMonth() === end.getMonth() && start.getFullYear() === end.getFullYear()) {
    return `${getDayName(start)} ${getDayNum(start)} al ${getDayName(end)} ${getDayNum(
      end
    )} de ${getMonth(start)} de ${getYear(start)}`;
  }

  return `${getDayName(start)} ${getDayNum(start)} de ${getMonth(start)} — ${getDayName(
    end
  )} ${getDayNum(end)} de ${getMonth(end)} de ${getYear(end)}`;
};

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
  if (!props.modelValue) return null;
  const found = props.options.find((opt) => {
    const val = typeof opt === "object" ? opt.id ?? opt.value : opt;
    return val == props.modelValue;
  });
  return found ? (typeof found === "object" ? found.nombre ?? found.label : found) : null;
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
  if (props.type === "select") return props.options?.length || 0;

  if (props.type === "date-range" && Array.isArray(props.modelValue)) {
    const [start, end] = props.modelValue;

    if (!start) return 0;
    if (!end) return 1;

    const d1 = new Date(start);
    d1.setHours(0, 0, 0, 0);

    const d2 = new Date(end);
    d2.setHours(0, 0, 0, 0);

    const diffTime = Math.abs(d2 - d1);

    const diffDays = Math.round(diffTime / (1000 * 60 * 60 * 24));

    return diffDays + 1;
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

  if (props.type === "text") value = value.charAt(0).toUpperCase() + value.slice(1);
  else if (props.type === "number") value = value.replace(/\D/g, "");
  emit("update:modelValue", value);
};

const isEmailValid = computed(() => {
  if (props.type !== "email" || !props.modelValue) return true;
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(props.modelValue);
});

const MAX_FILE_SIZE_MB = 16;

const isFileTooHeavy = computed(() => {
  if (props.type === "file" && props.modelValue instanceof File) {
    return props.modelValue.size > MAX_FILE_SIZE_MB * 1024 * 1024;
  }
  return false;
});

const fileInputRef = ref(null);

const formatFileSize = (bytes) => {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};

const triggerFileSelect = () => {
  if (props.type === 'file') fileInputRef.value.click();
};

const onFileChange = (e) => {
  const file = e.target.files[0];
  if (!file) return;
  
  if (localError.value) {
    localError.value = null;
    emit("clearError");
  }
  
  emit("update:modelValue", file);
};

const clearFile = (e) => {
  e.stopPropagation();
  emit("update:modelValue", null);
  if (fileInputRef.value) fileInputRef.value.value = '';
};

// Mejoramos el objeto de metadata para el diseño
const fileMetadata = computed(() => {
  if (props.type === "file" && props.modelValue instanceof File) {
    const file = props.modelValue;
    const extension = file.name.split(".").pop().toLowerCase();

    const typeColors = {
      pdf: "#ef4444",
      png: "#3b82f6",
      jpg: "#3b82f6",
      jpeg: "#3b82f6",
      zip: "#a855f7",
      doc: "#2563eb",
      docx: "#2563eb",
    };

    return {
      name: file.name,
      size: formatFileSize(file.size),
      ext: extension,
      color: typeColors[extension] || props.activeColor,
      isImage: ["jpg", "jpeg", "png", "webp", "svg"].includes(extension),
    };
  }
  return null;
});
</script>

<template>
  <div class="space-y-2 w-full group relative" ref="selectRef" :style="dynamicStyle">
    <div class="flex justify-between items-end px-1">
      <label
        v-if="label"
        class="text-[13px] font-bold transition-colors"
        :style="{ color: localError ? '#dc2626' : isFocused ? activeColor : '#94a3b8' }"
      >
        {{ label }}: <span v-if="required" class="text-red-400">*</span>
      </label>
      <span
        class="text-[11px] font-bold transition-all duration-300"
        :class="[localError || isFileTooHeavy ? 'text-red-400' : 'text-slate-400']"
      >
        <template v-if="type === 'file' && fileMetadata">
          <span :class="{ 'animate-pulse text-red-500': isFileTooHeavy }">
            {{ isFileTooHeavy ? "Archivo muy pesado" : fileMetadata.size }}
          </span>
        </template>

        <template v-else-if="props.type === 'date-range'">
          {{ currentCount }} {{ currentCount === 1 ? "día" : "días" }}
        </template>

        <template v-else-if="props.type === 'select'">
          {{ currentCount }} opciones
        </template>

        <template v-else> {{ currentCount }}/{{ max }} </template>
      </span>
    </div>

    <div
      @click="toggleDropdown"
      class="relative flex gap-2 px-4 py-3 bg-slate-50 border-2 rounded-xl transition-all duration-300"
      :class="[
        { 'bg-white shadow-xl ring-4 ring-opacity-10': isFocused },
        { 'cursor-pointer': type === 'select' || type === 'date-range' },
        type === 'textarea' ? 'items-start' : 'items-center',
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
        v-if="icon && (type !== 'file' || !fileMetadata)"
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

      <div v-else-if="type === 'date-range'" class="w-full">
        <vue-date-picker
          :model-value="modelValue"
          @update:model-value="(val) => emit('update:modelValue', val)"
          range
          :min-date="today"
          :multi-calendars="{ solo: true }"
          :teleport="true"
          :enable-time-picker="false"
          @open="isFocused = true"
          @closed="isFocused = false"
          auto-apply
          :close-on-auto-apply="true"
          hide-offset-dates
          no-today
          menu-class-name="fyc-datepicker-menu"
        >
          <template #trigger>
            <div
              class="font-semibold text-sm transition-colors w-full  leading-tight"
              :class="modelValue ? 'text-slate-700' : 'text-slate-300'"
            >
              <template v-if="modelValue && modelValue[0]">
                {{ formatRangeFull(modelValue) }}
              </template>
              <template v-else>
                {{ placeholder || "Seleccione fechas..." }}
              </template>
            </div>
          </template>
        </vue-date-picker>
      </div>

      <div
        v-else-if="type === 'select'"
        class="w-full flex items-center justify-between select-none"
      >
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

      <div
        v-else-if="type === 'file'"
        class="w-full flex items-center group/file"
        @click="triggerFileSelect"
      >
        <input type="file" ref="fileInputRef" class="hidden" @change="onFileChange" />

        <div class="flex-1 flex items-center gap-3 min-w-0">
          <template v-if="fileMetadata">
            <div
              class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 transition-transform group-hover/file:scale-110"
              :style="{
                backgroundColor: fileMetadata.color + '15',
                color: fileMetadata.color,
              }"
            >
              <span class="material-symbols-rounded">
                {{
                  fileMetadata.ext === "pdf"
                    ? "picture_as_pdf"
                    : fileMetadata.isImage
                    ? "image"
                    : "draft"
                }}
              </span>
            </div>

            <div class="flex flex-col min-w-0">
              <span class="text-sm font-bold text-slate-700 truncate tracking-tight">
                {{ fileMetadata.name }}
              </span>
              <div class="flex items-center gap-2">
                <span
                  class="text-[9px] font-black uppercase px-1.5 py-0.5 rounded-md border"
                  :style="{
                    borderColor: fileMetadata.color + '30',
                    color: fileMetadata.color,
                  }"
                >
                  {{ fileMetadata.ext }}
                </span>
                <span class="text-[10px] font-medium text-slate-400">{{
                  fileMetadata.size
                }}</span>
              </div>
            </div>
          </template>

          <template v-else>
            <div class="flex flex-col">
              <span
                class="text-sm font-semibold text-slate-300 group-hover/file:text-slate-400 transition-colors"
              >
                {{ placeholder || "Haga clic para adjuntar archivo" }}
              </span>
              <span
                class="text-[10px] font-medium text-slate-400 uppercase tracking-widest"
                >Máximo {{ MAX_FILE_SIZE_MB }}MB</span
              >
            </div>
          </template>
        </div>

        <button
          v-if="fileMetadata"
          @click.stop="clearFile"
          class="ml-2 w-8 h-8 flex items-center justify-center rounded-full hover:bg-red-50 text-slate-300 hover:text-red-500 transition-all"
        >
          <span class="material-symbols-rounded text-lg font-bold">close</span>
        </button>
      </div>

      <input
        v-else
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

      <div class="flex items-center gap-2">
        <button
          v-if="type === 'password' && modelValue"
          type="button"
          @click.stop="togglePassword"
          class="flex items-center justify-center transition-opacity"
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
        class="absolute left-0 right-0 mt-2 z-[110] bg-white border border-slate-100 shadow-2xl rounded-[1.5rem] overflow-hidden p-1.5"
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
              >done</span
            >
          </div>
        </div>
      </div>
    </transition>

    <p
      v-if="localError"
      class="mt-2 text-[12px] text-red-600 font-medium flex items-center gap-1 animate-in fade-in slide-in-from-top-1"
    >
      <span class="material-symbols-rounded text-sm">warning</span> {{ localError }}
    </p>
  </div>
</template>

<style>
.fyc-datepicker-menu {
  --dp-primary-color: var(--focused-color) !important;
  --dp-menu-border-radius: 1.5rem !important;
  --dp-border-color: transparent !important;
  --dp-font-family: inherit !important;
  border: none !important;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.2) !important;
  padding: 10px !important;
}

/* EL RANGO: Pintarlo del mismo color sólido */
.fyc-datepicker-menu .dp__range_between {
  background: var(--focused-color) !important;
  color: #ffffff !important;
  opacity: 0.8;
}

.fyc-datepicker-menu .dp__range_start {
  border-radius: 12px 0 0 12px !important;
}
.fyc-datepicker-menu .dp__range_end {
  border-radius: 0 12px 12px 0 !important;
}

.fyc-datepicker-menu .dp__cell_inner {
  border-radius: 10px !important;
  transition: all 0.2s ease;
}

.fyc-datepicker-menu .dp__month_year_wrap {
  font-weight: 800 !important;
  color: #1e293b !important;
}

.fyc-datepicker-menu .dp__calendar_header_item {
  font-weight: 700 !important;
  color: #94a3b8 !important;
}

.fyc-datepicker-menu .dp__calendar_header_separator {
  display: none !important;
}
.fyc-datepicker-menu .dp__action_row {
  display: none !important;
}
</style>

<style scoped>
.custom-scroll::-webkit-scrollbar {
  width: 4px;
}
.custom-scroll::-webkit-scrollbar-thumb {
  background-color: #cbd5e1;
  border-radius: 20px;
}
:deep(.dp__main) {
  width: 100%;
}
:deep(.dp__input_wrap) {
  display: none;
}
</style>
