<script setup>
import { watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { X, UserPlus, UserPen } from "lucide-vue-next";
import FormInput from "@/Components/Shared/inputs/FormInput.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";

const props = defineProps({
  show: { type: Boolean, default: false },
  loteId: { type: Number, required: true },
  participante: { type: Object, default: null }, // null = alta manual
});

const emit = defineEmits(["close"]);

const COLOR = "#942934";

const form = useForm({ nombre_completo: "", documento: "" });

watch(
  () => props.show,
  (show) => {
    if (!show) return;
    form.clearErrors();
    form.nombre_completo = props.participante?.nombre_completo ?? "";
    form.documento = props.participante?.documento ?? "";
  }
);

const cerrar = () => {
  if (!form.processing) emit("close");
};

const submit = () => {
  if (form.processing) return;
  const opciones = { preserveScroll: true, onSuccess: () => emit("close") };

  if (props.participante) {
    form.put(route("credential-flow.participantes.update", [props.loteId, props.participante.id]), opciones);
  } else {
    form.post(route("credential-flow.participantes.store", props.loteId), opciones);
  }
};
</script>

<template>
  <Teleport to="body">
    <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0" leave-active-class="transition duration-150 ease-in" leave-to-class="opacity-0">
      <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" @click="cerrar" />

        <div role="dialog" aria-modal="true" aria-labelledby="cf-participante-titulo" class="relative z-50 w-full max-w-lg bg-white rounded-[2rem] shadow-2xl overflow-hidden flex flex-col max-h-[92dvh]" data-modal-participante>
          <div class="px-6 sm:px-8 py-5 border-b border-slate-100 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 bg-gradient-to-tr to-primary-vinotinto from-secondary-vinotinto2 rounded-2xl flex items-center justify-center shadow-md">
                <component :is="participante ? UserPen : UserPlus" class="w-5 h-5 text-white" />
              </div>
              <div>
                <h3 id="cf-participante-titulo" class="text-lg font-black text-slate-900">{{ participante ? "Editar participante" : "Agregar participante" }}</h3>
                <p class="text-xs font-medium text-slate-400">Escribe los datos como deben verse en el certificado</p>
              </div>
            </div>
            <button type="button" class="w-9 h-9 flex items-center justify-center rounded-2xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-all" aria-label="Cerrar" :disabled="form.processing" @click="cerrar">
              <X class="w-5 h-5" />
            </button>
          </div>

          <form class="flex flex-col min-h-0" @submit.prevent="submit">
            <div class="flex-grow overflow-y-auto px-6 sm:px-8 py-6 space-y-5">
              <FormInput v-model="form.nombre_completo" label="Nombre completo" type="text" icon="person" placeholder="Nombre y apellidos" :max="100" :required="true" :activeColor="COLOR" :error="form.errors.nombre_completo" @clearError="form.clearErrors('nombre_completo')" />
              <FormInput v-model="form.documento" label="Documento" type="text" icon="badge" placeholder="C.C. 1.023.456.789" :max="40" :required="true" :activeColor="COLOR" :error="form.errors.documento" @clearError="form.clearErrors('documento')" />
              <p class="text-[12px] font-semibold text-slate-400">
                El nombre se guarda en mayúsculas. El documento se guarda tal como lo escribas (así se imprime).
              </p>
            </div>

            <div class="px-6 sm:px-8 py-4 border-t border-slate-100 bg-slate-50/50 flex items-center justify-between gap-4 shrink-0">
              <button type="button" :disabled="form.processing" class="text-sm font-bold text-slate-500 hover:text-slate-800 transition-colors px-4 py-2 rounded-xl hover:bg-slate-100 disabled:opacity-50" @click="cerrar">
                Cancelar
              </button>
              <BtnUniversal type="submit" :label="participante ? 'Guardar cambios' : 'Agregar'" icon="save" icon-position="right" size="md" :activeColor="COLOR" :loading="form.processing" :disabled="form.processing" process="Guardando..." class="sm:w-auto" />
            </div>
          </form>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
