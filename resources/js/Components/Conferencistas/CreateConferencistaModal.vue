<script setup>
import { ref } from 'vue';
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
  AlertCircle
} from "lucide-vue-next";

const props = defineProps({
  show: { type: Boolean, default: false },
  areas: { type: Array, default: () => [] }, 
});

const emit = defineEmits(["close", "success"]);

// Variable reactiva para la previsualización de la imagen
const fotoPreview = ref(null);

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

// Función mejorada para manejar la selección de la foto
const handleFotoUpload = (event) => {
  const file = event.target.files[0];
  if (!file) return;

  // 1. Guardamos el archivo real en el form de Inertia
  form.foto = file;

  // 2. Creamos una URL temporal para mostrar la previsualización
  fotoPreview.value = URL.createObjectURL(file);
};

// Función para limpiar la previsualización si el usuario cancela
const clearFoto = () => {
    form.foto = null;
    fotoPreview.value = null;
    // Resetea el input file físicamente
    const fileInput = document.getElementById('fotoInput');
    if(fileInput) fileInput.value = '';
}

const closeModal = () => {
  emit("close");
  setTimeout(() => {
    form.reset();
    form.clearErrors();
    fotoPreview.value = null; // Limpiar la imagen temporal
  }, 300);
};

const submit = () => {
  form.post(route("conferencistas.store"), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => {
      closeModal();
      emit("success"); 
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
          class="relative bg-white rounded-3xl shadow-2xl w-full max-w-4xl overflow-hidden flex flex-col"
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
                  Añada un nuevo perfil al equipo académico de F&C Consultores.
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
              
              <div v-if="Object.keys(form.errors).length > 0" class="p-4 bg-red-50 border border-red-200 rounded-xl flex items-start gap-3">
                  <AlertCircle class="w-5 h-5 text-red-500 shrink-0 mt-0.5" />
                  <div>
                      <h4 class="text-sm font-bold text-red-800">No se pudo guardar el perfil</h4>
                      <ul class="mt-1 text-xs text-red-600 list-disc list-inside pl-4">
                          <li v-for="(error, key) in form.errors" :key="key">{{ error }}</li>
                      </ul>
                  </div>
              </div>

              <div class="flex flex-col md:flex-row gap-8">
                
                <div class="w-full md:w-1/3 flex flex-col items-center justify-start space-y-4 pt-2">
                    
                    <div class="relative group">
                        <div class="w-40 h-40 rounded-full border-4 border-white shadow-xl bg-slate-100 overflow-hidden flex items-center justify-center flex-shrink-0">
                            <img v-if="fotoPreview" :src="fotoPreview" class="w-full h-full object-cover" />
                            
                            <template v-else>
                                <img v-if="form.primer_nombre" :src="`https://ui-avatars.com/api/?name=${form.primer_nombre}+${form.primer_apellido}&background=4f46e5&color=fff&size=160`" class="w-full h-full object-cover opacity-50" />
                                <Camera v-else class="w-12 h-12 text-slate-300" />
                            </template>
                        </div>
                        
                        <button v-if="fotoPreview" @click="clearFoto" type="button" class="absolute top-0 right-0 bg-red-500 text-white p-2 rounded-full shadow-md hover:bg-red-600 transition-colors tooltip" title="Quitar foto">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <div class="w-full text-center">
                        <label for="fotoInput" class="cursor-pointer inline-flex items-center justify-center px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-sm font-bold text-indigo-600 hover:bg-indigo-50 transition-colors w-full shadow-sm">
                            <Camera class="w-4 h-4 mr-2" />
                            {{ fotoPreview ? 'Cambiar Fotografía' : 'Subir Foto de Perfil' }}
                        </label>
                        <input id="fotoInput" @change="handleFotoUpload" type="file" accept="image/*" class="hidden" />
                        <p class="text-[10px] text-slate-400 mt-2 font-medium">Recomendado: 1:1 (Cuadrada). Max 2MB.</p>
                    </div>
                </div>

                <div class="w-full md:w-2/3 grid grid-cols-1 md:grid-cols-2 gap-5">
                  <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase">Primer Nombre *</label>
                    <input v-model="form.primer_nombre" type="text" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600 outline-none transition-all" :class="{ 'border-red-500': form.errors.primer_nombre }" />
                  </div>
                  <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase">Segundo Nombre</label>
                    <input v-model="form.segundo_nombre" type="text" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600 outline-none transition-all" />
                  </div>
                  <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase">Primer Apellido *</label>
                    <input v-model="form.primer_apellido" type="text" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600 outline-none transition-all" :class="{ 'border-red-500': form.errors.primer_apellido }" />
                  </div>
                  <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase">Segundo Apellido</label>
                    <input v-model="form.segundo_apellido" type="text" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600 outline-none transition-all" />
                  </div>

                  <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase">Correo Electrónico *</label>
                    <div class="relative">
                      <Mail class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                      <input v-model="form.correo" type="email" class="w-full pl-10 pr-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600 outline-none transition-all" :class="{ 'border-red-500': form.errors.correo }" />
                    </div>
                  </div>
                  <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase">Teléfono Móvil *</label>
                    <div class="relative">
                      <Phone class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                      <input v-model="form.telefono" type="tel" class="w-full pl-10 pr-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600 outline-none transition-all" :class="{ 'border-red-500': form.errors.telefono }" />
                    </div>
                  </div>

                  <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase">Área Encargada *</label>
                    <select v-model="form.area_encargada_id" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600 outline-none transition-all cursor-pointer" :class="{ 'border-red-500': form.errors.area_encargada_id }">
                      <option value="" disabled>Seleccione el área académica...</option>
                      <option v-for="area in areas" :key="area.id" :value="area.id">
                        {{ area.nombre }}
                      </option>
                    </select>
                  </div>

                  <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase">Breve Biografía</label>
                    <textarea v-model="form.biografia" rows="4" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-600 outline-none transition-all" placeholder="Perfil profesional que se mostrará a los asistentes en la página del evento..."></textarea>
                  </div>

                  <div class="md:col-span-2 bg-slate-100 p-4 rounded-xl border border-slate-200 border-dashed">
                    <label class="block text-xs font-bold text-slate-700 mb-2 uppercase flex items-center gap-1"><FileText class="w-4 h-4 text-slate-400" /> Anexar Hoja de Vida Completa (Opcional)</label>
                    <input @input="form.url_hv = $event.target.files[0]" type="file" accept=".pdf" class="w-full text-sm text-slate-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-bold file:bg-white file:text-indigo-600 hover:file:bg-indigo-50 file:cursor-pointer file:transition-colors file:shadow-sm" />
                  </div>
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
              class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 px-6 rounded-xl shadow-md transition-all flex items-center disabled:opacity-70 disabled:cursor-not-allowed hover:-translate-y-0.5"
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

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
  width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: #cbd5e1;
  border-radius: 20px;
}
</style>