<script setup>
import { useForm } from "@inertiajs/vue3";
import {
  UserPlus,
  X,
  Loader2,
  Save,
  FileText,
  Camera,
  Phone,
  Mail,
} from "lucide-vue-next";

const props = defineProps({
  show: { type: Boolean, default: false },
  areas: { type: Array, default: () => [] }, // Necesitamos las áreas para asignarlo
});

const emit = defineEmits(["close", "success"]);

const form = useForm({
  primer_nombre: "",
  segundo_nombre: "",
  primer_apellido: "",
  segundo_apellido: "",
  telefono: "",
  correo: "",
  area_encargada_id: "",
  biografia: "",
  foto: null,
  url_hv: null,
});

const closeModal = () => {
  emit("close");
  setTimeout(() => {
    form.reset();
    form.clearErrors();
  }, 300);
};

const submit = () => {
  form.post(route("conferencistas.store"), {
    preserveScroll: true,
    forceFormData: true, // Vital por la foto y el PDF
    onSuccess: () => {
      closeModal();
      emit("success"); // Avisamos al padre que se guardó para reabrir el modal de evento si queremos
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
          class="relative bg-white rounded-3xl shadow-2xl w-full max-w-3xl overflow-hidden flex flex-col"
          @click.stop
        >
          <div
            class="px-8 py-6 border-b border-slate-100 bg-white flex justify-between items-center"
          >
            <div class="flex items-center gap-3">
              <div
                class="w-10 h-10 bg-indigo-50 rounded-xl flex items-center justify-center"
              >
                <UserPlus class="w-5 h-5 text-indigo-600" />
              </div>
              <div>
                <h2 class="text-xl font-bold text-slate-900">Registrar Conferencista</h2>
                <p class="text-slate-500 text-xs mt-0.5">
                  Añada un nuevo perfil al equipo académico.
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
            <form id="speakerForm" @submit.prevent="submit" class="space-y-6">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                  <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase"
                    >Primer Nombre *</label
                  >
                  <input
                    v-model="form.primer_nombre"
                    type="text"
                    class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600"
                    :class="{ 'border-red-500': form.errors.primer_nombre }"
                  />
                  <p v-if="form.errors.primer_nombre" class="mt-1 text-xs text-red-600">
                    {{ form.errors.primer_nombre }}
                  </p>
                </div>
                <div>
                  <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase"
                    >Segundo Nombre</label
                  >
                  <input
                    v-model="form.segundo_nombre"
                    type="text"
                    class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600"
                  />
                </div>
                <div>
                  <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase"
                    >Primer Apellido *</label
                  >
                  <input
                    v-model="form.primer_apellido"
                    type="text"
                    class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600"
                    :class="{ 'border-red-500': form.errors.primer_apellido }"
                  />
                </div>
                <div>
                  <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase"
                    >Segundo Apellido</label
                  >
                  <input
                    v-model="form.segundo_apellido"
                    type="text"
                    class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600"
                  />
                </div>

                <div>
                  <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase"
                    >Correo Electrónico *</label
                  >
                  <div class="relative">
                    <Mail
                      class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"
                    />
                    <input
                      v-model="form.correo"
                      type="email"
                      class="w-full pl-10 pr-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600"
                    />
                  </div>
                  <p v-if="form.errors.correo" class="mt-1 text-xs text-red-600">
                    {{ form.errors.correo }}
                  </p>
                </div>
                <div>
                  <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase"
                    >Teléfono Móvil *</label
                  >
                  <div class="relative">
                    <Phone
                      class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"
                    />
                    <input
                      v-model="form.telefono"
                      type="tel"
                      class="w-full pl-10 pr-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600"
                    />
                  </div>
                </div>

                <div class="md:col-span-2">
                  <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase"
                    >Área Encargada *</label
                  >
                  <select
                    v-model="form.area_encargada_id"
                    class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600"
                  >
                    <option value="" disabled>Seleccione el área académica...</option>
                    <option v-for="area in areas" :key="area.id" :value="area.id">
                      {{ area.nombre }}
                    </option>
                  </select>
                </div>

                <div class="md:col-span-2">
                  <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase"
                    >Breve Biografía</label
                  >
                  <textarea
                    v-model="form.biografia"
                    rows="3"
                    class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600"
                    placeholder="Perfil profesional para mostrar en la página..."
                  ></textarea>
                </div>

                <div>
                  <label
                    class="block text-xs font-bold text-slate-700 mb-1.5 uppercase flex items-center gap-1"
                    ><Camera class="w-4 h-4 text-slate-400" /> Foto de Perfil</label
                  >
                  <input
                    @input="form.foto = $event.target.files[0]"
                    type="file"
                    accept="image/*"
                    class="w-full px-4 py-2 bg-white border border-slate-200 rounded-xl file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-indigo-50 file:text-indigo-700"
                  />
                </div>
                <div>
                  <label
                    class="block text-xs font-bold text-slate-700 mb-1.5 uppercase flex items-center gap-1"
                    ><FileText class="w-4 h-4 text-slate-400" /> Hoja de Vida (PDF)</label
                  >
                  <input
                    @input="form.url_hv = $event.target.files[0]"
                    type="file"
                    accept=".pdf"
                    class="w-full px-4 py-2 bg-white border border-slate-200 rounded-xl file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-indigo-50 file:text-indigo-700"
                  />
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
              form="speakerForm"
              :disabled="form.processing"
              class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 px-6 rounded-xl shadow-md transition-all flex items-center disabled:opacity-70"
            >
              <Loader2 v-if="form.processing" class="w-4 h-4 mr-2 animate-spin" />
              <Save v-else class="w-4 h-4 mr-2" />
              Guardar Conferencista
            </button>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>
