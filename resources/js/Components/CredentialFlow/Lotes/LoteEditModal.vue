<script setup>
import { watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { X, SquarePen } from "lucide-vue-next";
import FormInput from "@/Components/Shared/inputs/FormInput.vue";
import BtnUniversal from "@/Components/BtnUniversal.vue";

const props = defineProps({
  show: { type: Boolean, default: false },
  lote: { type: Object, required: true },
});

const emit = defineEmits(["close"]);

const COLOR = "#942934";

const form = useForm({ nombre: "", descripcion: "", evento: "", fecha: "", intensidad_horaria: "" });

watch(
  () => props.show,
  (show) => {
    if (!show) return;
    form.clearErrors();
    form.nombre = props.lote.nombre ?? "";
    form.descripcion = props.lote.descripcion ?? "";
    form.evento = props.lote.datos_comunes?.evento ?? "";
    form.fecha = props.lote.datos_comunes?.fecha ?? "";
    form.intensidad_horaria = props.lote.datos_comunes?.intensidad_horaria ?? "";
  }
);

const cerrar = () => {
  if (!form.processing) emit("close");
};

const submit = () => {
  if (form.processing) return;
  form.put(route("credential-flow.lotes.update", props.lote.id), { preserveScroll: true, onSuccess: () => emit("close") });
};
</script>

<template>
  <Teleport to="body">
    <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0" leave-active-class="transition duration-150 ease-in" leave-to-class="opacity-0">
      <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" @click="cerrar" />

        <div role="dialog" aria-modal="true" aria-labelledby="cf-lote-titulo" class="relative z-50 w-full max-w-xl bg-white rounded-[2rem] shadow-2xl overflow-hidden flex flex-col max-h-[92dvh]" data-modal-lote>
          <div class="px-6 sm:px-8 py-5 border-b border-slate-100 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 bg-gradient-to-tr to-primary-vinotinto from-secondary-vinotinto2 rounded-2xl flex items-center justify-center shadow-md">
                <SquarePen class="w-5 h-5 text-white" />
              </div>
              <div>
                <h3 id="cf-lote-titulo" class="text-lg font-black text-slate-900">Editar lote</h3>
                <p class="text-xs font-medium text-slate-400">La plantilla no se puede cambiar</p>
              </div>
            </div>
            <button type="button" class="w-9 h-9 flex items-center justify-center rounded-2xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-all" aria-label="Cerrar" :disabled="form.processing" @click="cerrar">
              <X class="w-5 h-5" />
            </button>
          </div>

          <form class="flex flex-col min-h-0" @submit.prevent="submit">
            <div class="flex-grow overflow-y-auto px-6 sm:px-8 py-6 space-y-5">
              <FormInput v-model="form.nombre" label="Nombre del lote" type="text" icon="badge" :max="200" :required="true" :activeColor="COLOR" :error="form.errors.nombre" @clearError="form.clearErrors('nombre')" />
              <FormInput v-model="form.descripcion" label="Descripción (opcional)" type="text" icon="notes" :max="1000" :activeColor="COLOR" :error="form.errors.descripcion" @clearError="form.clearErrors('descripcion')" />
              <FormInput v-model="form.evento" label="Evento" type="text" icon="event" :max="200" :required="true" :activeColor="COLOR" :error="form.errors.evento" @clearError="form.clearErrors('evento')" />
              <div class="grid gap-5 sm:grid-cols-2">
                <FormInput v-model="form.fecha" label="Fecha" type="text" icon="calendar_month" :max="80" :required="true" :activeColor="COLOR" :error="form.errors.fecha" @clearError="form.clearErrors('fecha')" />
                <FormInput v-model="form.intensidad_horaria" label="Intensidad horaria" type="text" icon="schedule" :max="30" :required="true" :activeColor="COLOR" :error="form.errors.intensidad_horaria" @clearError="form.clearErrors('intensidad_horaria')" />
              </div>
            </div>

            <div class="px-6 sm:px-8 py-4 border-t border-slate-100 bg-slate-50/50 flex items-center justify-between gap-4 shrink-0">
              <button type="button" :disabled="form.processing" class="text-sm font-bold text-slate-500 hover:text-slate-800 transition-colors px-4 py-2 rounded-xl hover:bg-slate-100 disabled:opacity-50" @click="cerrar">
                Cancelar
              </button>
              <BtnUniversal type="submit" label="Guardar cambios" icon="save" icon-position="right" size="md" :activeColor="COLOR" :loading="form.processing" :disabled="form.processing" process="Guardando..." class="sm:w-auto" />
            </div>
          </form>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
