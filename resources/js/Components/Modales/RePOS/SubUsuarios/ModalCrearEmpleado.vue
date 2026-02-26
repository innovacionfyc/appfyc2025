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
});

const emit = defineEmits(['close']);


// --- CONFIGURACIÓN DEL WIZARD ---
const currentStep = ref(1);
const activeTab = ref(0);
const totalSteps = 3;


// Sincronizar Tabs con Pasos
watch(activeTab, (newIndex) => {
    currentStep.value = newIndex + 1;
});
const tabs = [
    { id: 0, label: "1. Datos Personales", icon: "person" },
    { id: 1, label: "2. Contratación", icon: "work" },
    { id: 2, label: "3. Credenciales", icon: "badge" },
];

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

// Lógica de Navegación
const handleNext = () => {
    if (currentStep.value < totalSteps) {
        currentStep.value++;
        activeTab.value = currentStep.value - 1;
    }
};

const handlePrev = () => {
    if (currentStep.value > 1) {
        currentStep.value--;
        activeTab.value = currentStep.value - 1;
    }
};

const hasErrors = ref(false); // Variable para mostrar alerta visual

const submit = () => {
    hasErrors.value = false;

    form.post(route('repos.sub_usuarios.store_empleado', { rol: props.rol, aplicacion: props.aplicacion }), {
        preserveScroll: true,
        onSuccess: () => closeModal(true),
        onError: (errors) => {
            hasErrors.value = true;
            
            // 1. Mapeo EXACTO de tus campos según dónde están en el HTML
            // Revisa que 'numero_documento' ahora está en el paso 3
            const step1Fields = [
                'primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido', 
                'genero', 'tipo_documento_id', 'correo', 'indicativo_id', 
                'telefono', 'ciudad_residencia', 'barrio_residencia', 'direccion_residencia'
            ];
            
            const step2Fields = [
                'cargo', 'salario_base', 'tipo_contrato', 'horario', 
                'banco', 'cuenta_ahorros'
            ];

            const step3Fields = [
                'numero_documento', 'password'
            ];

            // 2. Lógica de redirección prioritaria (Va al primer paso que tenga error)
            const errorKeys = Object.keys(errors);

            if (errorKeys.some(field => step1Fields.includes(field))) {
                activeTab.value = 0; 
                currentStep.value = 1;
            } else if (errorKeys.some(field => step2Fields.includes(field))) {
                activeTab.value = 1; 
                currentStep.value = 2;
            } else if (errorKeys.some(field => step3Fields.includes(field))) {
                activeTab.value = 2; 
                currentStep.value = 3;
            }
            
            // Opcional: Vibrar el celular si es móvil
            if (navigator.vibrate) navigator.vibrate(200);
        }
    });
};

const closeModal = (force = false) => {
    if (!force && form.isDirty && !confirm('¿Descartar cambios?')) return;
    
    // ✅ CORRECCIÓN AL RESETEAR: Aseguramos que vuelva a 0
    form.reset();
    form.salario_base = 0; 
    
    form.clearErrors();
    currentStep.value = 1;
    activeTab.value = 0;
    emit('close');
};

// Avatar Dinámico
const iniciales = computed(() => {
    const n = form.primer_nombre || '';
    const a = form.primer_apellido || '';
    return (n.charAt(0) + a.charAt(0)).toUpperCase() || 'N';
});

const nombreCompleto = computed(() => {
    return `${form.primer_nombre} ${form.primer_apellido}`.trim() || 'Nuevo Empleado';
});
</script>

<template>
     <BaseModalSteps :isOpen="isOpen" v-model:currentStep="currentStep" :totalSteps="totalSteps"
    :isSubmitting="form.processing" finalButtonText="¡Contratar!" @close="closeModal" @next="handleNext"
    @prev="handlePrev" @submit="submit">

        <!-- HEADER VISUAL EN EL CONTENIDO (Opcional, pero se ve genial) -->
        <template #header>
            <div
                class="flex items-center gap-4 mb-8 p-4 bg-gray-50 dark:bg-white/5 rounded-2xl border border-gray-100 dark:border-white/5">
                <!-- Avatar Generado -->
                <div
                    class="w-14 h-14 rounded-full bg-gradient-to-br from-primary to-rose-600 flex items-center justify-center text-white text-xl font-black shadow-xs shadow-primary shrink-0">
                    {{ iniciales }}
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
                    <p class="text-xs font-semibold text-primary">{{ tabs[activeTab].label }}</p>
                </div>
            </div>
            <div v-if="hasErrors" class="mb-4 p-3 bg-red-100 border border-red-200 text-red-700 rounded-lg flex items-center gap-2 text-sm animate-pulse">
    <span class="material-symbols-rounded">warning</span>
    <p>Por favor revisa los campos marcados en rojo en esta pestaña.</p>
</div>
        </template>

        <div class="min-h-[350px]">
            <Transition name="slide-fade" mode="out-in">

                <!-- PASO 1: PERSONAL -->
                <div v-if="activeTab === 0" key="step1" class="space-y-5">
                    <div
                        class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
                        <InputTexto v-model="form.primer_nombre" label="Primer nombre" icon="format_italic" type="text"
                            placeholder="Ej: Juan" :maxLength="limitesCaracteres.nombresUsuario"
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
                        <Selects v-model="form.genero" :options="props.generosDisponibles" :error="form.errors.genero"
                            label="Genero:" placeholder="Seleccione un genero" id="genero" />
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
                        <InputTexto v-model="form.ciudad_residencia" label="Ciudad residencia:" icon="map" type="text"
                            placeholder="Bogotá D.C." :maxLength="limitesCaracteres.direccion"
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
                <div v-else-if="activeTab === 1" key="step2" class="space-y-6">

                    <div
                        class="bg-indigo-50 dark:bg-indigo-900/20 p-4 rounded-xl border border-indigo-100 dark:border-indigo-800/50">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="material-symbols-rounded text-primary">work</span>
                            <h4 class="text-sm font-bold text-indigo-800 dark:text-indigo-300 uppercase tracking-wide">
                                Datos
                                Laborales</h4>
                        </div>
                        <div
                            class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
                            <Selects v-model="form.cargo" :options="props.cargosDisponibles" :error="form.errors.cargo"
                                label="Selecciona un cargo:" placeholder="Elije su labor" id="cargo" />
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
                            :error="form.errors.horario"  />
                    </div>

                    <div class="h-px bg-gray-100 dark:bg-gray-700"></div>

                    <div>
                        <p class="text-xs font-bold text-gray-400 uppercase mb-3 ml-1">Datos de Nómina</p>
                        <div
                            class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center">
                            <Selects v-model="form.banco" :options="props.bancoDisponibles" :error="form.errors.banco"
                                label="Selecciona el banco" id="banco" />
                            <InputTexto v-model="form.cuenta_ahorros" label="cuenta de ahorros:" icon="account_balance"
                                type="text" placeholder="escribe cuenta la cuenta"
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
                        <InputTexto v-model="form.numero_documento" label="Número documento:" icon="pin" type="number"
                            placeholder="10135****" :maxLength="limitesCaracteres.numero_documento"
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

            </Transition>
        </div>

    </BaseModalSteps>
</template>

<style scoped>
/* Animación de deslizamiento entre pasos */
.slide-fade-enter-active,
.slide-fade-leave-active {
    transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
}

.slide-fade-enter-from {
    opacity: 0;
    transform: translateX(20px);
}

.slide-fade-leave-to {
    opacity: 0;
    transform: translateX(-20px);
}
</style>