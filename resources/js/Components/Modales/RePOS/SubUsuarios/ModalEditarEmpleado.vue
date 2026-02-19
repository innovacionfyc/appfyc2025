<script setup>
import { useForm } from "@inertiajs/vue3";
import { ref, computed, watch } from "vue";
import BaseModalSteps from '@/Components/Shared/Modales/BaseModalSteps.vue';
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";
import Selects from "@/Components/Shared/inputs/Selects.vue";
import NumberInput from "@/Components/Shared/inputs/NumberInput.vue"; // Asumiendo que tienes este componente, si no usa InputTexto
import {  handleInput, limitesCaracteres } from "@/Utils/formateoInputs";

const props = defineProps({
    isOpen: Boolean,
    aplicacion: String,
    rol: String,
    cargosDisponibles: { type: Array, default: () => [] },
    bancoDisponibles: { type: Array, default: () => [] },
    tiposContrato: { type: Array, default: () => [] },
    tipoDocumentoDisponibles: { type: Array, default: () => [] },
    indicativosDisponibles: { type: Array, default: () => [] },
    generosDisponibles: { type: Array, default: () => [] },
    empleado: { type: Object, required: true },

});

const emit = defineEmits(['close']);


// --- CONFIGURACIÓN DEL WIZARD ---
const currentStep = ref(1);
const totalSteps = 3;
const transitionName = ref('slide-next');



// --- FORMULARIO ---
const form = useForm({
    primer_nombre: "",
    segundo_nombre: "",
    primer_apellido: "",
    segundo_apellido: "",
    indicativo_id: "",
    telefono: "",
    correo: "",
    ciudad_residencia: "",
    barrio_residencia: "",
    direccion_residencia: "",
    tipo_documento_id: "",
    numero_documento: "",
    password: "",
    genero: "",
    cargo: "",
    salario_base: 0,
    tipo_contrato: "",
    banco: "",
    cuenta_ahorros: "",
    horario: ""
});

watch(() => props.empleado, (data) => {
    // Verificamos que data exista
    if (data && Object.keys(data).length > 0) {
        
        // 1. DEFINIR ORIGENES DE DATOS
        // Si viene anidado (Eloquent), usamos la relación. Si viene plano, usamos el objeto raíz.
        const perfil = data.perfil_usuario || data; 
        const laboral = data.perfil_empleado || data;

        // 2. MAPEO DE DATOS PERSONALES
        form.primer_nombre = perfil.primer_nombre || '';
        form.segundo_nombre = perfil.segundo_nombre || '';
        form.primer_apellido = perfil.primer_apellido || '';
        form.segundo_apellido = perfil.segundo_apellido || '';
        form.tipo_documento_id = perfil.tipo_documento_id || '';
        form.indicativo_id = perfil.indicativo_id || '';
        form.telefono = perfil.telefono || '';
        form.correo = perfil.correo || data.email || ''; // A veces el correo está en User
        form.genero = perfil.genero || '';
        
        // Dirección
        form.ciudad_residencia = perfil.ciudad_residencia || '';
        form.barrio_residencia = perfil.barrio_residencia || '';
        form.direccion_residencia = perfil.direccion_residencia || '';

        // 3. MAPEO DE DATOS LABORALES
        form.cargo = laboral.cargo || '';
        form.salario_base = laboral.salario_base || 0;
        form.tipo_contrato = laboral.tipo_contrato || '';
        form.banco = laboral.banco || '';
        form.cuenta_ahorros = laboral.cuenta_ahorros || '';
        form.horario = laboral.horario || '';

        // 4. DATOS DE ACCESO (Tabla User raiz)
        form.numero_documento = data.numero_documento || perfil.numero_documento || '';
        
        // IMPORTANTE: NO llenar password por seguridad
        form.password = ''; 

        form.clearErrors();
    }
}, { immediate: true, deep: true });

function handleNext() {
    transitionName.value = 'slide-next';
    form.clearErrors();
    // Aquí tu lógica de validación
    if (currentStep.value < totalSteps) {
        currentStep.value++;
    }
}

function handlePrev() {
    transitionName.value = 'slide-back';
    if (currentStep.value > 1) {
        currentStep.value--;
    }
}

const hasErrors = ref(false); // Variable para mostrar alerta visual

function submit() {
    // Usamos PUT y la ruta de update pasando el ID del cliente
    form.put(route("repos.sub_usuarios.update_empleado", {
        aplicacion: props.aplicacion,
        rol: props.rol,
        id: props.empleado.id
    }), {
        preserveScroll: true,
        onSuccess: () => closeModal(true),
    });
}

function closeModal(force = false) {
  if (form.isDirty && !force) {
    if (confirm('¿Estás seguro de que quieres salir? Perderás los cambios no guardados.')) {
      emit('close');
      setTimeout(() => {
        form.reset();
        currentStep.value = 1;
      }, 300);
    }
  } else {
    emit('close');
    setTimeout(() => {
      form.reset();
      currentStep.value = 1;
    }, 300);
  }
}

// Avatar Dinámico
const inicialesDeProductoFormulario = computed(() => {
    const nombre = form.primer_nombre || "";
    return nombre ? nombre.charAt(0).toUpperCase() : "";
});

const nombreCompleto = computed(() => {
    return `${form.primer_nombre} ${form.primer_apellido}`.trim() || 'Nuevo Empleado';
});


</script>

<template>
    <BaseModalSteps :isOpen="isOpen" v-model:currentStep="currentStep" :totalSteps="totalSteps"
        :isSubmitting="form.processing" finalButtonText="Actualizar" @close="closeModal" @next="handleNext"
        @prev="handlePrev" @submit="submit">

        <!-- HEADER VISUAL EN EL CONTENIDO (Opcional, pero se ve genial) -->
        <template #header>
            <div
                class="flex items-center gap-4 mb-8 p-4 bg-gray-50 dark:bg-white/5 rounded-2xl border border-gray-100 dark:border-white/5">
                <!-- Avatar Generado -->
                <div
                    class="w-14 h-14 rounded-full bg-gradient-to-br from-primary to-rose-600 flex items-center justify-center text-white text-xl font-black shadow-xs shadow-primary shrink-0">
                    {{ inicialesDeProductoFormulario }}
                </div>
                <div class="flex-grow min-w-0">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white truncate">
                        {{ nombreCompleto }}
                    </h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1">
                        <span class="material-symbols-rounded text-sm text-primary">badge</span>
                        {{ form.cargo || 'Cargo por definir' }}
                    </p>
                </div>
                <!-- Indicador de Progreso Texto -->
                <div class="text-right hidden sm:block">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Paso {{ currentStep }} de {{
                        totalSteps }}</p>

                </div>
            </div>
            <div v-if="hasErrors"
                class="mb-4 p-3 bg-red-100 border border-red-200 text-red-700 rounded-lg flex items-center gap-2 text-sm animate-pulse">
                <span class="material-symbols-rounded">warning</span>
                <p>Por favor revisa los campos marcados en rojo en esta pestaña.</p>
            </div>
        </template>

        <div class="min-h-[350px]">
            <Transition :name="transitionName" mode="out-in">
                <div :key="currentStep">

                    <!-- PASO 1: PERSONAL -->
                    <div v-if="currentStep === 1" key="step1" class="space-y-5">
                        <div
                            class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
                            <InputTexto v-model="form.primer_nombre" label="Primer nombre" icon="format_italic"
                                type="text" placeholder="Ej: Juan" :maxLength="limitesCaracteres.nombresUsuario"
                                :error="form.errors.primer_nombre" 
                                @input="(e) => handleInput(e, form, 'primer_nombre')" />

                            <InputTexto v-model="form.segundo_nombre" label="Segundo nombre" icon="format_italic"
                                type="text" placeholder="Opcional*" :maxLength="limitesCaracteres.nombresUsuario"
                                :error="form.errors.segundo_nombre" 
                                @input="(e) => handleInput(e, form, 'segundo_nombre')" />

                        </div>
                        <div
                            class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
                            <InputTexto v-model="form.primer_apellido" label="Primer apellido" icon="format_italic"
                                type="text" placeholder="Ej: Medina" :maxLength="limitesCaracteres.nombresUsuario"
                                :error="form.errors.primer_apellido" 
                                @input="(e) => handleInput(e, form, 'primer_apellido')" />

                            <InputTexto v-model="form.segundo_apellido" label="Segundo apellido" icon="format_italic"
                                type="text" placeholder="Opcional*" :maxLength="limitesCaracteres.nombresUsuario"
                                :error="form.errors.segundo_nombre" 
                                @input="(e) => handleInput(e, form, 'segundo_apellido')" />

                        </div>
                        <div
                            class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
                            <Selects v-model="form.genero" :options="props.generosDisponibles"
                                :error="form.errors.genero" label="Genero:" placeholder="Seleccione un genero"
                                id="genero" />
                        </div>

                        <div class="h-px bg-gray-100 dark:bg-gray-700"></div>

                        <div
                            class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">

                            <Selects v-model="form.tipo_documento_id" :options="props.tipoDocumentoDisponibles"
                                :error="form.errors.tipo_documento_id" label="Tipo documento:"
                                placeholder="Seleccione un tipo" id="tipo_documento_id" />

                            <InputTexto v-model="form.correo" label="Correo electrónico:" icon="email" type="email"
                                placeholder="example@dominio.com" :maxLength="limitesCaracteres.nombresUsuario"
                                :error="form.errors.correo"  />
                        </div>

                        <div
                            class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
                            <Selects v-model="form.indicativo_id" :options="props.indicativosDisponibles"
                                :error="form.errors.indicativo_id" label="Indicativo:"
                                placeholder="Selecciona un indicativo" id="indicativo" />

                            <InputTexto v-model="form.telefono" label="Número celular:" icon="phone" type="number"
                                placeholder="316511****" :maxLength="limitesCaracteres.telefono"
                                :error="form.errors.telefono" 
                                @input="(e) => handleInput(e, form, 'telefono')" />

                        </div>

                        <div
                            class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
                            <InputTexto v-model="form.ciudad_residencia" label="Ciudad residencia:" icon="map"
                                type="text" placeholder="Bogotá D.C." :maxLength="limitesCaracteres.direccion"
                                :error="form.errors.ciudad_residencia"  
                                @input="(e) => handleInput(e, form, 'ciudad_residencia')" />

                            <InputTexto v-model="form.barrio_residencia" label="Barrio residencia:" icon="distance"
                                type="text" placeholder="Luna Park" :maxLength="limitesCaracteres.direccion"
                                :error="form.errors.barrio_residencia" 
                                @input="(e) => handleInput(e, form, 'barrio_residencia')" />

                            <InputTexto v-model="form.direccion_residencia" label="Dirección residencia:"
                                icon="format_italic" type="text" placeholder="Calle 1a #2b-3 sur"
                                :maxLength="limitesCaracteres.direccion" :error="form.errors.direccion_residencia"
                                  />
                        </div>
                    </div>

                    <!-- PASO 2: CONTRATACIÓN -->
                    <div v-else-if="currentStep === 2" key="step2" class="space-y-6">

                        <div
                            class="bg-indigo-50 dark:bg-indigo-900/20 p-4 rounded-xl border border-indigo-100 dark:border-indigo-800/50">
                            <div class="flex items-center gap-2 mb-4">
                                <span class="material-symbols-rounded text-primary">work</span>
                                <h4
                                    class="text-sm font-bold text-indigo-800 dark:text-indigo-300 uppercase tracking-wide">
                                    Datos
                                    Laborales</h4>
                            </div>
                            <div
                                class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
                                <Selects v-model="form.cargo" :options="props.cargosDisponibles"
                                    :error="form.errors.cargo" label="Selecciona un cargo:" placeholder="Elije su labor"
                                    id="cargo" />
                                <Selects v-model="form.tipo_contrato" :options="props.tiposContrato"
                                    :error="form.errors.tipo_contrato" label="Selecciona tipo de contrato:"
                                    placeholder="Elije su periodo" id="tipo_cargo" />
                            </div>
                        </div>

                        <div
                            class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
                            <NumberInput v-model="form.salario_base" label="Salario base" icon="attach_money"
                                placeholder="1.600.000" :maxLength="limitesCaracteres.precio_producto"
                                :error="form.errors.salario_base"  
                                @input="(e) => handleInput(e, form, 'salario_base')" class="w-full" />

                            <InputTexto v-model="form.horario" label="Horario:" icon="event" type="text"
                                placeholder="Lunes a Viernes: 3pm a 6pm" :maxLength="limitesCaracteres.direccion"
                                :error="form.errors.horario"   />
                        </div>

                        <div class="h-px bg-gray-100 dark:bg-gray-700"></div>

                        <div>
                            <p class="text-xs font-bold text-gray-400 uppercase mb-3 ml-1">Datos de Nómina</p>
                            <div
                                class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
                                <Selects v-model="form.banco" :options="props.bancoDisponibles"
                                    :error="form.errors.banco" label="Selecciona el banco" id="banco" />
                                <InputTexto v-model="form.cuenta_ahorros" label="cuenta de ahorros:"
                                    icon="account_balance" type="text" placeholder="escribe cuenta la cuenta"
                                    :maxLength="limitesCaracteres.precio_producto" :error="form.errors.cuenta_ahorros"
                                     />
                            </div>
                        </div>
                    </div>

                    <!-- PASO 3: ACCESO -->
                    <div v-else key="step3" class="space-y-6">

                        <div class="flex flex-col items-center justify-center py-6 text-center">
                            <div
                                class="w-16 h-16 bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 rounded-full flex items-center justify-center mb-3">
                                <span class="material-symbols-rounded text-3xl">lock_person</span>
                            </div>
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Credenciales de Acceso</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 max-w-xs">
                                Estos datos permitirán al empleado ingresar al sistema POS.
                            </p>
                        </div>

                        <div class="max-w-md mx-auto space-y-4">
                            <InputTexto v-model="form.numero_documento" label="Número documento:" icon="pin"
                                type="number" placeholder="10135****" :maxLength="limitesCaracteres.numero_documento"
                                :error="form.errors.numero_documento"  
                                @input="(e) => handleInput(e, form, 'numero_documento')" />

                            <div class="relative">
                                <InputTexto v-model="form.password" label="Contraseña:" icon="password" type="password"
                                    placeholder="Genera una aleatoriamente" :maxLength="limitesCaracteres.password"
                                    :error="form.errors.password"  />
                                <p class="text-[10px] text-gray-400 mt-1 ml-1">
                                    <span class="material-symbols-rounded text-[10px] align-middle mr-0.5">info</span>
                                    Mínimo 6 caracteres. Recomienda cambiarla al primer ingreso.
                                </p>
                            </div>
                        </div>

                        <div
                            class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg border border-blue-100 dark:border-blue-800/30 flex gap-3 items-start mt-4">
                            <span class="material-symbols-rounded text-blue-500 mt-0.5">verified_user</span>
                            <div>
                                <p class="text-xs font-bold text-blue-700 dark:text-blue-300">Confirmación</p>
                                <p class="text-[11px] text-blue-600 dark:text-blue-400 leading-snug">
                                    Al finalizar, el empleado quedará activo y asignado a este establecimiento
                                    inmediatamente.
                                </p>
                            </div>
                        </div>

                    </div>
                </div>
            </Transition>
        </div>

    </BaseModalSteps>
</template>

<style scoped>
/*
  AÑADIDO: Estilos para la transición direccional.
  Puedes ponerlos en el <style scoped> de tu CreateProductModal.vue
*/

/* --- Estilos para ir HACIA ADELANTE --- */
.slide-next-enter-active,
.slide-next-leave-active {
    /* Usamos una curva de aceleración suave para un look más profesional */
    transition: all 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
}

/* El nuevo paso entra desde la derecha */
.slide-next-enter-from {
    opacity: 0;
    transform: translateX(50px);
}

/* El paso antiguo sale hacia la izquierda */
.slide-next-leave-to {
    opacity: 0;
    transform: translateX(-50px);
}


/* --- Estilos para ir HACIA ATRÁS --- */
.slide-back-enter-active,
.slide-back-leave-active {
    transition: all 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
}

/* El nuevo paso entra desde la izquierda */
.slide-back-enter-from {
    opacity: 0;
    transform: translateX(-50px);
}

/* El paso antiguo sale hacia la derecha */
.slide-back-leave-to {
    opacity: 0;
    transform: translateX(50px);
}

.list-fade-enter-active,
.list-fade-leave-active {
    transition: all 0.4s ease;
}

.list-fade-enter-from,
.list-fade-leave-to {
    opacity: 0;
    transform: translateY(20px);
}
</style>