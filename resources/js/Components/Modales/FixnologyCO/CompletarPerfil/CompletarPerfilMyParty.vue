<script setup>
import { useForm, usePage } from "@inertiajs/vue3";
import { ref, computed, watch, onMounted } from "vue";
import BaseModalSteps from "@/Components/Shared/Modales/BaseModalSteps.vue";
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";
import Selects from "@/Components/Shared/inputs/Selects.vue";
import NumberInput from "@/Components/Shared/inputs/NumberInput.vue";
import { handleInput, limitesCaracteres } from "@/Utils/formateoInputs";

const page = usePage();
const isOpen = computed(() => !!page.props.auth.user?.mustCompleteProfile);

// --- 1. LÓGICA PARA DETECTAR EL ROL ---
const esPropietario = computed(() => {
  // Accedemos a los datos del usuario logueado
  const user = page.props.auth.user?.data;

  // Verificamos si el rol es 3 (Propietario)
  // Buscamos en perfil_empleado o perfil_usuario según como lo envíe tu Resource
  return user?.perfil_empleado?.rol_id === 3 || user?.perfil_usuario?.rol_id === 3;
});

const props = defineProps({
  aplicacion: String,
  rol: String,
  empleado: { type: Object, default: () => null },
  cargosDisponibles: { type: Array, default: () => [] },
  bancoDisponibles: { type: Array, default: () => [] },
  tiposContrato: { type: Array, default: () => [] },
  tipoDocumentoDisponibles: { type: Array, default: () => [] },
  indicativosDisponibles: { type: Array, default: () => [] },
  generosDisponibles: { type: Array, default: () => [] },
});

// --- CONFIGURACIÓN DEL WIZARD ---
const currentStep = ref(1);
const totalSteps = computed(() => (esPropietario.value ? 4 : 3));
const transitionName = ref("slide-next");

// --- FORMULARIO ---
const form = useForm({
  primer_nombre: "",
  segundo_nombre: "",
  primer_apellido: "",
  segundo_apellido: "",
  genero: "",
  tipo_documento_id: "",
  correo: "",
  indicativo_id: "",
  telefono: "",
  ciudad_residencia: "",
  barrio_residencia: "",
  direccion_residencia: "",

  numero_documento: "",
  password: "",
  
  cargo: "",
  tipo_contrato: "",
  salario_base: 0,
  horario: "",
  banco: "",
  cuenta_ahorros: "",

  contiene_mesas: "",
  nit: "",
  nombre_establecimiento: "",
  tipo_establecimiento: "",
  email_establecimiento: "",
  telefono_establecimiento: "",
  direccion_establecimiento: "",
  barrio_establecimiento: "",
  ciudad_establecimiento: "",
  ip_puente_impresion: "",
});

// --- LÓGICA DE AUTO-LLENADO ---
// Vigilamos cuando el modal se abre o cuando cambian las props
watch(
  () => isOpen.value,
  (opened) => {
    if (opened) {
      // Fuente de verdad: Si no hay un empleado por props, usamos el usuario autenticado
      // Recordando que tu UserResource envuelve el modelo en 'data'
      const userAuth = page.props.auth.user?.data;
      const source = props.empleado || userAuth;
      if (source) {
        // Accedemos a las relaciones si existen (perfil_usuario, perfil_empleado)
        const perfil = source.perfil_usuario || source;
        const laboral = source.perfil_empleado || source;
        const establecimiento = source.establecimientoAsignado;

        form.primer_nombre = perfil.primer_nombre || "";
        form.segundo_nombre = perfil.segundo_nombre || "";
        form.primer_apellido = perfil.primer_apellido || "";
        form.segundo_apellido = perfil.segundo_apellido || "";
        form.tipo_documento_id = perfil.tipo_documento_id || "";
        form.indicativo_id = perfil.indicativo_id || "";
        form.telefono = perfil.telefono || "";
        form.correo = perfil.correo || source.email || "";
        form.genero = perfil.genero || "";

        form.ciudad_residencia = perfil.ciudad_residencia || "";
        form.barrio_residencia = perfil.barrio_residencia || "";
        form.direccion_residencia = perfil.direccion_residencia || "";

        form.cargo = laboral.cargo || "";
        form.salario_base = laboral.salario_base || 0;
        form.tipo_contrato = laboral.tipo_contrato || "";
        form.banco = laboral.banco || "";
        form.cuenta_ahorros = laboral.cuenta_ahorros || "";
        form.horario = laboral.horario || "";

        form.numero_documento = source.numero_documento || perfil.numero_documento || "";

        form.contiene_mesas = source.establecimiento?.contiene_mesas ?? false;
        form.nit = source.establecimiento?.nit;
        form.nombre_establecimiento = source.establecimiento?.nombre_establecimiento;
        form.tipo_establecimiento = source.establecimiento?.tipo_establecimiento;
        form.email_establecimiento = source.establecimiento?.email_establecimiento;
        form.telefono_establecimiento = source.establecimiento?.telefono_establecimiento;
        form.direccion_establecimiento = source.establecimiento?.direccion_establecimiento;
        form.barrio_establecimiento = source.establecimiento?.barrio_establecimiento;
        form.ciudad_establecimiento = source.establecimiento?.ciudad_establecimiento;
        form.ip_puente_impresion = source.establecimiento?.ip_puente_impresion;
      }
    }
  },
  { immediate: true }
);

// Asegúrate de que use .value ya que totalSteps ahora es un computed
function handleNext() {
  transitionName.value = "slide-next";
  form.clearErrors();
  // Agregamos .value a totalSteps
  if (currentStep.value < totalSteps.value) {
    currentStep.value++;
  }
}

function handlePrev() {
  transitionName.value = "slide-back";
  if (currentStep.value > 1) {
    currentStep.value--;
  }
}

const hasErrors = ref(false);

function submit() {
  // Cambia form.put por form.post
  form.put(
    route("fixnologyco.perfil.completar", {
      aplicacion: props.aplicacion,
      rol: props.rol,
    }),
    {
      preserveScroll: true,
      onSuccess: () => closeModal(true),
    }
  );
}

function closeModal(force = false) {
  emit("close");
  setTimeout(() => {
    form.reset();
    currentStep.value = 1;
  }, 300);
}

const inicialesDeProductoFormulario = computed(() => {
  const nombre = form.primer_nombre || "";
  const apellido = form.primer_apellido || "";
  return (nombre.charAt(0) + apellido.charAt(0)).toUpperCase();
});

const nombreCompleto = computed(() => {
  return (
    `${form.primer_nombre} ${form.primer_apellido}`.trim() ||
    "¡Hola Fixco!, completa tu perfil."
  );
});

// 1. LOS DATOS PEQUEÑOS (Ya vienen en la página)
const generos = page.props.lookups.generos;
const tiposDocumento = page.props.lookups.tiposDocumento;
const tipoContratos = page.props.lookups.tipoContratos;
const indicativos = page.props.lookups.indicativos;
const cargos = page.props.lookups.cargos;
const bancos = page.props.lookups.bancos;
const contiene_mesas = page.props.lookups.booleano;

</script>

<template>
  <BaseModalSteps
    :isOpen="isOpen"
    v-model:currentStep="currentStep"
    :totalSteps="totalSteps"
    title="Continuar creando tu perfil"
    :isSubmitting="form.processing"
    finalButtonText="Actualizar"
    @next="handleNext"
    @prev="handlePrev"
    @submit="submit"
  >
    <!-- HEADER VISUAL EN EL CONTENIDO (Opcional, pero se ve genial) -->

    <template #header>
      <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
        Completa tu perfil para continuar en
        <span class="text-primary font-bold uppercase">{{
          page.props.auth.user?.app_name_route
        }}</span>
      </p>
      <div
        class="flex items-center gap-4 mb-8 p-4 bg-gray-50 dark:bg-white/5 rounded-2xl border border-gray-100 dark:border-white/5"
      >
        <div
          v-if="currentStep < 4"
          class="w-14 h-14 rounded-full bg-gradient-to-br from-primary to-rose-600 flex items-center justify-center text-white text-xl font-black shadow-xs shadow-primary shrink-0 transition-all"
        >
          {{ inicialesDeProductoFormulario }}
        </div>
        <div
          v-else
          class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-500 to-primary flex items-center justify-center text-white shadow-xs shadow-indigo-500/50 shrink-0 transition-all animate-in zoom-in"
        >
          <span class="material-symbols-rounded text-3xl">store</span>
        </div>

        <div class="flex-grow min-w-0">
          <h3 class="text-lg font-bold text-gray-900 dark:text-white truncate">
            {{
              currentStep < 4
                ? nombreCompleto
                : form.nombre_establecimiento || "Mi Establecimiento"
            }}
          </h3>
          <p class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1">
            <span class="material-symbols-rounded text-sm text-primary">
              {{ currentStep < 4 ? "badge" : "location_on" }}
            </span>
            {{
              currentStep < 4
                ? form.cargo || "Cargo por definir"
                : form.ciudad_establecimiento || "Ubicación del negocio"
            }}
          </p>
        </div>

        <div class="text-right hidden sm:block">
          <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">
            Paso {{ currentStep }} de {{ totalSteps }}
          </p>
          <p class="text-[10px] font-black text-primary uppercase">
            {{ currentStep < 4 ? "Información Personal" : "Datos del Negocio" }}
          </p>
        </div>
      </div>

      <div
        v-if="hasErrors"
        class="mb-4 p-3 bg-red-100 border border-red-200 text-red-700 rounded-lg flex items-center gap-2 text-sm animate-pulse"
      >
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
              class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
            >
              <InputTexto
                v-model="form.primer_nombre"
                label="Primer nombre"
                icon="format_italic"
                type="text"
                placeholder="Ej: Juan"
                :maxLength="limitesCaracteres.nombresUsuario"
                :error="form.errors.primer_nombre"
                @input="(e) => handleInput(e, form, 'primer_nombre')"
              />

              <InputTexto
                v-model="form.segundo_nombre"
                label="Segundo nombre"
                icon="format_italic"
                type="text"
                placeholder="Opcional*"
                :maxLength="limitesCaracteres.nombresUsuario"
                :error="form.errors.segundo_nombre"
                @input="(e) => handleInput(e, form, 'segundo_nombre')"
              />
            </div>
            <div
              class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
            >
              <InputTexto
                v-model="form.primer_apellido"
                label="Primer apellido"
                icon="format_italic"
                type="text"
                placeholder="Ej: Medina"
                :maxLength="limitesCaracteres.nombresUsuario"
                :error="form.errors.primer_apellido"
                @input="(e) => handleInput(e, form, 'primer_apellido')"
              />

              <InputTexto
                v-model="form.segundo_apellido"
                label="Segundo apellido"
                icon="format_italic"
                type="text"
                placeholder="Opcional*"
                :maxLength="limitesCaracteres.nombresUsuario"
                :error="form.errors.segundo_nombre"
                @input="(e) => handleInput(e, form, 'segundo_apellido')"
              />
            </div>
            <div
              class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
            >
              <Selects
                v-model="form.genero"
                :options="generos"
                :error="form.errors.genero"
                label="Género:"
                placeholder="Seleccione un género"
                id="genero"
              />
            </div>

            <div class="h-px bg-gray-100 dark:bg-gray-700"></div>

            <div
              class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
            >
              <Selects
                v-model="form.tipo_documento_id"
                :options="tiposDocumento"
                :error="form.errors.tipo_documento_id"
                label="Tipo documento:"
                placeholder="Seleccione un tipo"
                id="tipo_documento_id"
              />

              <InputTexto
                v-model="form.correo"
                label="Correo electrónico:"
                icon="email"
                type="email"
                placeholder="example@dominio.com"
                :maxLength="limitesCaracteres.nombresUsuario"
                :error="form.errors.correo"
              />
            </div>

            <div
              class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
            >
              <Selects
                v-model="form.indicativo_id"
                :options="indicativos"
                :error="form.errors.indicativo_id"
                label="Indicativo:"
                placeholder="Selecciona un indicativo"
                id="indicativo"
              />

              <InputTexto
                v-model="form.telefono"
                label="Número celular:"
                icon="phone"
                type="number"
                placeholder="316511****"
                :maxLength="limitesCaracteres.telefono"
                :error="form.errors.telefono"
                @input="(e) => handleInput(e, form, 'telefono')"
              />
            </div>

            <div
              class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
            >
              <InputTexto
                v-model="form.ciudad_residencia"
                label="Ciudad residencia:"
                icon="map"
                type="text"
                placeholder="Bogotá D.C."
                :maxLength="limitesCaracteres.direccion"
                :error="form.errors.ciudad_residencia"
                @input="(e) => handleInput(e, form, 'ciudad_residencia')"
              />

              <InputTexto
                v-model="form.barrio_residencia"
                label="Barrio residencia:"
                icon="distance"
                type="text"
                placeholder="Luna Park"
                :maxLength="limitesCaracteres.direccion"
                :error="form.errors.barrio_residencia"
                @input="(e) => handleInput(e, form, 'barrio_residencia')"
              />

              <InputTexto
                v-model="form.direccion_residencia"
                label="Dirección residencia:"
                icon="format_italic"
                type="text"
                placeholder="Calle 1a #2b-3 sur"
                :maxLength="limitesCaracteres.direccion"
                :error="form.errors.direccion_residencia"
              />
            </div>
          </div>

          <!-- PASO 2: CONTRATACIÓN -->
          <div v-else-if="currentStep === 2" key="step2" class="space-y-6">
            <div
              class="bg-indigo-50 dark:bg-indigo-900/20 p-4 rounded-xl border border-indigo-100 dark:border-indigo-800/50"
            >
              <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-rounded text-primary">work</span>
                <h4
                  class="text-sm font-bold text-indigo-800 dark:text-indigo-300 uppercase tracking-wide"
                >
                  Datos Laborales
                </h4>
              </div>
              <div
                class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
              >
                <Selects
                  v-model="form.cargo"
                  :options="cargos"
                  :error="form.errors.cargo"
                  label="Selecciona un cargo:"
                  placeholder="Elije su labor"
                  id="cargo"
                />
                <Selects
                  v-model="form.tipo_contrato"
                  :options="tipoContratos"
                  :error="form.errors.tipo_contrato"
                  label="Selecciona tipo de contrato:"
                  placeholder="Elije su periodo"
                  id="tipo_cargo"
                />
              </div>
            </div>

            <div
              class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
            >
              <NumberInput
                v-model="form.salario_base"
                label="Salario base"
                icon="attach_money"
                placeholder="1.600.000"
                :maxLength="limitesCaracteres.precio_producto"
                :error="form.errors.salario_base"
                @input="(e) => handleInput(e, form, 'salario_base')"
                class="w-full"
              />

              <InputTexto
                v-model="form.horario"
                label="Horario:"
                icon="event"
                type="text"
                placeholder="Lunes a Viernes: 3pm a 6pm"
                :maxLength="limitesCaracteres.direccion"
                :error="form.errors.horario"
              />
            </div>

            <div class="h-px bg-gray-100 dark:bg-gray-700"></div>

            <div>
              <p class="text-xs font-bold text-gray-400 uppercase mb-3 ml-1">
                Datos de Nómina
              </p>
              <div
                class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
              >
                <Selects
                  v-model="form.banco"
                  :options="bancos"
                  :error="form.errors.banco"
                  label="Selecciona el banco"
                  id="banco"
                />
                <InputTexto
                  v-model="form.cuenta_ahorros"
                  label="cuenta de ahorros:"
                  icon="account_balance"
                  type="text"
                  placeholder="escribe cuenta la cuenta"
                  :maxLength="limitesCaracteres.precio_producto"
                  :error="form.errors.cuenta_ahorros"
                />
              </div>
            </div>
          </div>

          <!-- PASO 3: ACCESO -->
          <div v-else-if="currentStep === 3" key="step3" class="space-y-6">
            <div class="flex flex-col items-center justify-center py-6 text-center">
              <div
                class="w-16 h-16 bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 rounded-full flex items-center justify-center mb-3"
              >
                <span class="material-symbols-rounded text-3xl">lock_person</span>
              </div>
              <h3 class="text-lg font-bold text-gray-900 dark:text-white">
                Credenciales de Acceso
              </h3>
              <p class="text-sm text-gray-500 dark:text-gray-400 max-w-xs">
                Estos datos permitirán al empleado ingresar al sistema POS.
              </p>
            </div>

            <div class="max-w-md mx-auto space-y-4">
              <InputTexto
                v-model="form.numero_documento"
                label="Número documento:"
                icon="pin"
                type="number"
                placeholder="10135****"
                :maxLength="limitesCaracteres.numero_documento"
                :error="form.errors.numero_documento"
                @input="(e) => handleInput(e, form, 'numero_documento')"
              />

              <div class="relative">
                <InputTexto
                  v-model="form.password"
                  label="Contraseña:"
                  icon="password"
                  type="password"
                  placeholder="Genera una aleatoriamente"
                  :maxLength="limitesCaracteres.password"
                  :error="form.errors.password"
                />
                <p class="text-[10px] text-gray-400 mt-1 ml-1">
                  <span class="material-symbols-rounded text-[10px] align-middle mr-0.5"
                    >info</span
                  >
                  Mínimo 6 caracteres. Recomienda cambiarla al primer ingreso.
                </p>
              </div>
            </div>

            <div
              class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg border border-blue-100 dark:border-blue-800/30 flex gap-3 items-start mt-4"
            >
              <span class="material-symbols-rounded text-blue-500 mt-0.5"
                >verified_user</span
              >
              <div>
                <p class="text-xs font-bold text-blue-700 dark:text-blue-300">
                  Confirmación
                </p>
                <p class="text-[11px] text-blue-600 dark:text-blue-400 leading-snug">
                  Al finalizar, el empleado quedará activo y asignado a este
                  establecimiento inmediatamente.
                </p>
              </div>
            </div>
          </div>

          <!-- PASO 4: ESTABLECIMIENTO -->

          <div v-else key="step4" class="space-y-6">
            <div
              class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
            >
              <InputTexto
                v-model="form.nit"
                label="NIT de establecimiento"
                icon="license"
                type="text"
                placeholder="Ej: 1013580754-3"
                :maxLength="limitesCaracteres.nombresUsuario"
                :error="form.errors.nit"
                @input="(e) => handleInput(e, form, 'nit')"
              />
              <Selects
                  v-model="form.contiene_mesas"
                  :options="contiene_mesas"
                  :error="form.errors.contiene_mesas"
                  label="Tu establecimiento ¿Contiene mesas?:"
                  placeholder="Selecciona, Sí o No"
                  id="contiene_mesas"
                />
            </div>
            <div
              class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
            >
              <InputTexto
                v-model="form.nombre_establecimiento"
                label="Nombre de establecimiento"
                icon="store"
                type="text"
                placeholder="Ej: Juan"
                :maxLength="limitesCaracteres.nombresUsuario"
                :error="form.errors.nombre_establecimiento"
                @input="(e) => handleInput(e, form, 'nombre_establecimiento')"
              />

              <InputTexto
                v-model="form.tipo_establecimiento"
                label="Tipo de establecimiento"
                icon="category"
                type="text"
                placeholder="Ej: Gastrobar"
                :maxLength="limitesCaracteres.nombresUsuario"
                :error="form.errors.tipo_establecimiento"
                @input="(e) => handleInput(e, form, 'tipo_establecimiento')"
              />
            </div>
            <div
              class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
            >
              <InputTexto
                v-model="form.telefono_establecimiento"
                label="Télefono coorporativo"
                icon="phone"
                type="number"
                placeholder="Ej: 6011234567"
                :maxLength="limitesCaracteres.telefono"
                :error="form.errors.telefono_establecimiento"
                @input="(e) => handleInput(e, form, 'telefono_establecimiento')"
              />
              <InputTexto
                v-model="form.email_establecimiento"
                label="Correo electrónico del negocio"
                icon="email"
                type="email"
                :placeholder="
                  form.nombre_establecimiento
                    ? form.nombre_establecimiento.toLowerCase().replace(/\s+/g, '') +
                      '@gmail.com'
                    : 'negocio@correo.com'
                "
                :maxLength="limitesCaracteres.email"
                :error="form.errors.email_establecimiento"
                @input="(e) => handleInput(e, form, 'email_establecimiento')"
              />
            </div>
            <div
              class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
            >
              <InputTexto
                v-model="form.direccion_establecimiento"
                label="Dirección del establecimiento"
                icon="map"
                type="text"
                placeholder="Ej: Calle 1 sur #2-34"
                :maxLength="limitesCaracteres.direccion"
                :error="form.errors.direccion_establecimiento"
                @input="(e) => handleInput(e, form, 'direccion_establecimiento')"
              />

              <InputTexto
                v-model="form.barrio_establecimiento"
                label="Barrio del establecimiento"
                icon="map"
                type="text"
                placeholder="Ej: Ciudadela"
                :maxLength="limitesCaracteres.direccion"
                :error="form.errors.barrio_establecimiento"
                @input="(e) => handleInput(e, form, 'barrio_establecimiento')"
              />

              <InputTexto
                v-model="form.ciudad_establecimiento"
                label="Ciudad del establecimiento"
                icon="map"
                type="text"
                placeholder="Ej: Bogotá D.C."
                :maxLength="limitesCaracteres.direccion"
                :error="form.errors.ciudad_establecimiento"
                @input="(e) => handleInput(e, form, 'ciudad_establecimiento')"
              />
            </div>

            <div class="h-px bg-gray-100 dark:bg-gray-700"></div>

            <div
              class="bg-indigo-50 dark:bg-indigo-900/20 p-4 rounded-xl border border-indigo-100 dark:border-indigo-800/50"
            >
              <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-rounded text-primary">wifi</span>
                <h4
                  class="text-sm font-bold text-indigo-800 dark:text-indigo-300 uppercase tracking-wide"
                >
                  Configuracion del puerto
                </h4>
              </div>
              <p class="text-indigo-800 dark:text-indigo-300">
                Validar con su equipo o pedir soporte directo con Fixnology Comunity.
              </p>
              <div
                class="2xl:flex 2xl:flex-row 2xl:justify-between 2xl:items-center 2xl:gap-2 xl:flex xl:flex-row xl:justify-between xl:items-center xl:gap-2 gap-3 flex flex-col items-center"
              >
                <InputTexto
                  v-model="form.ip_puente_impresion"
                  label="Dirección IP de tu internet"
                  icon="pin"
                  type="text"
                  placeholder="Ej: 192.168.1.5"
                  :maxLength="limitesCaracteres.direccion"
                  :error="form.errors.ip_puente_impresion"
                  @input="(e) => handleInput(e, form, 'ip_puente_impresion')"
                />
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
