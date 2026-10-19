<script setup>
import { computed, ref, watch } from "vue";
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

const form = useForm({ nombre_completo: "", documento: "", correo: "", elegibilidad: "elegible", intensidad_horaria: "", elegibilidad_motivo: "", tipo_documento_pendiente: true });

// Tipo y número se piden por separado, pero se guardan juntos como siempre («C.C. 1.023.456.789»): la clave canónica, el portal y el OTP no cambian.
const TIPOS = ["C.C.", "C.E.", "T.I.", "PASAPORTE", "NIT", "PEP", "PPT"];
const opcionesTipo = TIPOS.map((t) => ({ id: t, nombre: t }));
const opcionesElegibilidad = [
  { id: "elegible", nombre: "Elegible — recibe certificado" },
  { id: "pendiente", nombre: "Pendiente de validación — aún no se emite" },
  { id: "no_elegible", nombre: "No elegible — no se emite" },
];
const tipo = ref("");
const numero = ref("");
const descomponer = (texto) => {
  const t = (texto ?? "").trim();
  const m = t.match(/^(C\.?\s?C\.?|C\.?\s?E\.?|T\.?\s?I\.?|PASAPORTE|NIT|PEP|PPT)\s*(.*)$/i);
  if (!m) return { tipo: "", numero: t };
  const norm = m[1].toUpperCase().replace(/\s/g, "");
  const tipoCanon = TIPOS.find((x) => x.replace(/\./g, "") === norm.replace(/\./g, "")) ?? "";
  return tipoCanon ? { tipo: tipoCanon, numero: m[2].trim() } : { tipo: "", numero: t };
};
// Se recompone SOLO cuando la persona edita tipo o número: abrir el formulario nunca reescribe un documento ya guardado.
const componer = () => {
  form.documento = [tipo.value, numero.value.trim()].filter(Boolean).join(" ");
  form.clearErrors("documento");
};
const cambiarTipo = (v) => {
  tipo.value = v ?? "";
  form.tipo_documento_pendiente = !tipo.value;
  componer();
};
const cambiarNumero = (v) => {
  numero.value = v ?? "";
  componer();
};
const requiereMotivo = computed(() => form.elegibilidad !== "elegible");

watch(
  () => props.show,
  (show) => {
    if (!show) return;
    form.clearErrors();
    form.nombre_completo = props.participante?.nombre_completo ?? "";
    const d = descomponer(props.participante?.documento);
    tipo.value = d.tipo;
    numero.value = d.numero;
    form.documento = props.participante?.documento ?? "";
    form.correo = props.participante?.correo ?? "";
    form.elegibilidad = props.participante?.elegibilidad ?? "elegible";
    form.intensidad_horaria = props.participante?.intensidad_horaria ?? "";
    form.elegibilidad_motivo = props.participante?.elegibilidad_motivo ?? "";
    // Un participante nuevo queda «por confirmar» hasta elegir su tipo; uno existente conserva lo que ya tiene (editar otros datos no lo cambia).
    form.tipo_documento_pendiente = props.participante ? props.participante.tipo_documento_confirmado === false : true;
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
              <div class="grid gap-5 sm:grid-cols-[11rem_1fr]">
                <FormInput :modelValue="tipo" @update:modelValue="cambiarTipo" label="Tipo de documento" type="select" icon="badge" placeholder="Elige el tipo" :options="opcionesTipo" :activeColor="COLOR" data-campo="tipo-documento" />
                <FormInput :modelValue="numero" @update:modelValue="cambiarNumero" label="Número de documento" type="text" icon="pin" placeholder="1.023.456.789" :max="40" :required="true" :activeColor="COLOR" :error="form.errors.documento" @clearError="form.clearErrors('documento')" data-campo="numero-documento" />
              </div>
              <p v-if="form.tipo_documento_pendiente" class="-mt-3 text-[12px] font-semibold text-amber-700" data-aviso-tipo>Tipo de documento por confirmar: la persona se guarda, pero no se emitirá hasta que elijas su tipo (no se supone «C.C.»).</p>
              <FormInput v-model="form.correo" label="Correo electrónico" type="text" icon="mail" placeholder="nombre@dominio.com" :max="300" :activeColor="COLOR" :error="form.errors.correo" @clearError="form.clearErrors('correo')" />
              <div class="rounded-2xl border border-slate-100 bg-slate-50/60 p-4 space-y-4" data-bloque-elegibilidad>
                <FormInput v-model="form.elegibilidad" label="Estado de certificación" type="select" icon="verified" placeholder="Elige el estado" :options="opcionesElegibilidad" :activeColor="COLOR" :error="form.errors.elegibilidad" data-campo="elegibilidad" />
                <FormInput v-if="requiereMotivo" v-model="form.elegibilidad_motivo" label="Motivo (queda en el historial)" type="text" icon="notes" placeholder="Ej: cupos — asistencia por validar" :max="255" :required="true" :activeColor="COLOR" :error="form.errors.elegibilidad_motivo" @clearError="form.clearErrors('elegibilidad_motivo')" data-campo="elegibilidad-motivo" />
                <FormInput v-model="form.intensidad_horaria" label="Intensidad horaria propia (opcional)" type="text" icon="schedule" placeholder="Déjalo vacío para usar la del evento" :max="60" :activeColor="COLOR" :error="form.errors.intensidad_horaria" @clearError="form.clearErrors('intensidad_horaria')" data-campo="intensidad-participante" />
                <p class="text-[12px] font-semibold text-slate-400">Solo las personas «Elegibles» entran a la emisión oficial. Las pendientes o no elegibles nunca se emiten en masa. La intensidad propia es una excepción aprobada; no se inventan horas.</p>
              </div>
              <p class="text-[12px] font-semibold text-slate-400">
                El nombre se guarda en mayúsculas. El documento se guarda tal como lo escribas (así se imprime).
                El correo es el que la persona usa para entrar a fycconsultores.com/certificados: si lo corriges, el correo anterior deja de dar acceso y los certificados ya emitidos no cambian. Varios correos se separan con «;».
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
