<script setup>
import { useForm } from "@inertiajs/vue3";
import { FileSpreadsheet, X, Loader2, Save, CheckSquare, Square } from "lucide-vue-next";

const props = defineProps({
  show: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "success"]);

const form = useForm({
  nombre_plantilla: "",
  tipo_persona: "Ambas",
  solicitar_cargo: false,
  solicitar_empresa: false,
  solicitar_correo_corp: false,
  solicitar_soporte: false,
});

const closeModal = () => {
  emit("close");
  setTimeout(() => {
    form.reset();
    form.clearErrors();
  }, 300);
};

const submit = () => {
  form.post(route("formularios.store"), {
    preserveScroll: true,
    onSuccess: () => {
      closeModal();
      emit("success"); // Avisamos al padre (Dashboard) para reabrir el de Eventos
    },
  });
};
</script>

<template>
  <Teleport to="body">
    <transition
      enter-active-class="transition ease-out duration-300"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition ease-in duration-200"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="show"
        class="fixed inset-0 z-[110] flex items-center justify-center p-4 sm:p-6 bg-slate-900/70 backdrop-blur-md overflow-y-auto"
      >
        <div class="absolute inset-0" @click="closeModal"></div>

        <div
          class="relative bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden flex flex-col transform transition-all"
          @click.stop
        >
          <div
            class="px-8 py-6 border-b border-slate-100 bg-white flex justify-between items-center"
          >
            <div class="flex items-center gap-3">
              <div
                class="w-10 h-10 bg-indigo-50 rounded-xl flex items-center justify-center"
              >
                <FileSpreadsheet class="w-5 h-5 text-indigo-600" />
              </div>
              <div>
                <h2 class="text-xl font-bold text-slate-900">Plantilla de Inscripción</h2>
                <p class="text-slate-500 text-xs mt-0.5">
                  Configure los campos a solicitar a los asistentes.
                </p>
              </div>
            </div>
            <button
              @click="closeModal"
              class="p-2 text-slate-400 hover:bg-slate-100 rounded-xl transition"
            >
              <X class="w-5 h-5" />
            </button>
          </div>

          <div class="p-8 overflow-y-auto flex-1 custom-scrollbar bg-slate-50/50">
            <form id="plantillaForm" @submit.prevent="submit" class="space-y-8">
              <div class="space-y-5">
                <div>
                  <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase"
                    >Nombre de la Plantilla *</label
                  >
                  <input
                    v-model="form.nombre_plantilla"
                    type="text"
                    placeholder="Ej. Formulario Estándar para Empresas"
                    class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600"
                    :class="{ 'border-red-500': form.errors.nombre_plantilla }"
                  />
                  <p
                    v-if="form.errors.nombre_plantilla"
                    class="mt-1 text-xs text-red-600"
                  >
                    {{ form.errors.nombre_plantilla }}
                  </p>
                </div>

                <div>
                  <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase"
                    >Enfoque del Formulario *</label
                  >
                  <select
                    v-model="form.tipo_persona"
                    class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600 cursor-pointer"
                  >
                    <option value="Natural">Solo Personas Naturales</option>
                    <option value="Jurídica">Solo Personas Jurídicas (Empresas)</option>
                    <option value="Ambas">Mixto (Ambas)</option>
                  </select>
                </div>
              </div>

              <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                <h4
                  class="text-sm font-bold text-slate-900 mb-4 border-b border-slate-100 pb-3 uppercase tracking-wider"
                >
                  Campos Dinámicos a Solicitar
                </h4>
                <p class="text-xs text-slate-500 mb-5">
                  Nota: Nombres, Apellidos, Cédula, Correo Personal y Celular siempre son
                  obligatorios.
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <label
                    class="flex items-center p-4 border border-slate-200 rounded-xl cursor-pointer hover:bg-slate-50 transition-colors"
                    :class="{ 'border-indigo-500 bg-indigo-50/30': form.solicitar_cargo }"
                  >
                    <div class="flex-1">
                      <p class="font-bold text-sm text-slate-900">Cargo / Profesión</p>
                    </div>
                    <input
                      type="checkbox"
                      v-model="form.solicitar_cargo"
                      class="hidden"
                    />
                    <CheckSquare
                      v-if="form.solicitar_cargo"
                      class="w-6 h-6 text-indigo-600"
                    />
                    <Square v-else class="w-6 h-6 text-slate-300" />
                  </label>

                  <label
                    class="flex items-center p-4 border border-slate-200 rounded-xl cursor-pointer hover:bg-slate-50 transition-colors"
                    :class="{
                      'border-indigo-500 bg-indigo-50/30': form.solicitar_empresa,
                    }"
                  >
                    <div class="flex-1">
                      <p class="font-bold text-sm text-slate-900">Empresa / Entidad</p>
                    </div>
                    <input
                      type="checkbox"
                      v-model="form.solicitar_empresa"
                      class="hidden"
                    />
                    <CheckSquare
                      v-if="form.solicitar_empresa"
                      class="w-6 h-6 text-indigo-600"
                    />
                    <Square v-else class="w-6 h-6 text-slate-300" />
                  </label>

                  <label
                    class="flex items-center p-4 border border-slate-200 rounded-xl cursor-pointer hover:bg-slate-50 transition-colors"
                    :class="{
                      'border-indigo-500 bg-indigo-50/30': form.solicitar_correo_corp,
                    }"
                  >
                    <div class="flex-1">
                      <p class="font-bold text-sm text-slate-900">Correo Corporativo</p>
                    </div>
                    <input
                      type="checkbox"
                      v-model="form.solicitar_correo_corp"
                      class="hidden"
                    />
                    <CheckSquare
                      v-if="form.solicitar_correo_corp"
                      class="w-6 h-6 text-indigo-600"
                    />
                    <Square v-else class="w-6 h-6 text-slate-300" />
                  </label>

                  <label
                    class="flex items-center p-4 border border-slate-200 rounded-xl cursor-pointer hover:bg-slate-50 transition-colors"
                    :class="{
                      'border-indigo-500 bg-indigo-50/30': form.solicitar_soporte,
                    }"
                  >
                    <div class="flex-1">
                      <p class="font-bold text-sm text-slate-900">
                        Soporte de Pago/Asistencia
                      </p>
                    </div>
                    <input
                      type="checkbox"
                      v-model="form.solicitar_soporte"
                      class="hidden"
                    />
                    <CheckSquare
                      v-if="form.solicitar_soporte"
                      class="w-6 h-6 text-indigo-600"
                    />
                    <Square v-else class="w-6 h-6 text-slate-300" />
                  </label>
                </div>
              </div>
            </form>
          </div>

          <div
            class="px-8 py-5 border-t border-slate-100 bg-white flex justify-end gap-3 rounded-b-3xl"
          >
            <button
              type="button"
              @click="closeModal"
              class="px-5 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-100 rounded-xl transition-colors"
            >
              Cancelar
            </button>
            <button
              type="submit"
              form="plantillaForm"
              :disabled="form.processing"
              class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 px-6 rounded-xl shadow-md transition-all flex items-center disabled:opacity-70"
            >
              <Loader2 v-if="form.processing" class="w-4 h-4 mr-2 animate-spin" />
              <Save v-else class="w-4 h-4 mr-2" />
              Guardar Plantilla
            </button>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>
