<script setup>
import {
  defineProps,
  defineEmits,
  watch,
  reactive,
  ref,
  onMounted,
  onBeforeUnmount,
  computed,
  watchEffect,
} from "vue";
import { Head, usePage, useForm, router } from "@inertiajs/vue3";
import "dayjs/locale/es";

import { handleInput, limitesCaracteres } from "@/Utils/formateoInputs";
import { iconosDisponibles } from "@/Utils/iconos.js";

const {
  appName,
  bgClase,
  bgOpacity,
  focus,
  textoClase,
  borderClase,
  buttonFocus,
  hoverClase,
  hoverTexto,
  buttonClase,
  buttonSecundario,
} = Colors();

const props = defineProps({
  auth: {
    type: Object,
  },
  mostrar: Boolean,
  estados: {
    type: Array,
    required: true,
  },
  plan_aplicaciones: {
    type: Array,
    required: true,
  },
  membresias: {
    type: Array,
    required: true,
  },
});

const emit = defineEmits(["cerrar"]);

const form = useForm({
  nombre_app: "",
  descripcion_app: "",
  id_estado: 1,
  id_plan_aplicacion: "",
  id_membresia: "",
  color_fondo: "bg-red-400",
  color_texto: "bg-red-400",
  color_shadow: "shadow-0px 8px 20px red-400",
  color_border: "border-red-400",
  color_hover: "hover:text-red-400",
  icono_app: "bolt",
});

const page = usePage();
const user = ref(page.props.auth.user);
const aplicacion = props.auth?.user?.tienda?.aplicacion?.nombre_app || "Sin app";
const rol = props.auth.user.rol?.tipo_rol || "Sin rol"; // Obtén el tipo de rol

const submitForm = () => {
  form.post(
    route("registro.app", {
      aplicacion: aplicacion,
      rol: rol,
    }),
    {
      onSuccess: () => {
        form.reset();
        emit("cerrar");
      },
    }
  );
};

const mostrarSelector = ref(false);

const coloresDisponibles = [
  "bg-red-400",
  "bg-blue-400",
  "bg-green-400",
  "bg-yellow-400",
  "bg-orange-400",
  "bg-gray-900",
  "bg-lime-400",
  "bg-purple-400",
  "bg-pink-400",
  "bg-indigo-400",
  "bg-teal-400",
  "bg-cyan-400",
  "bg-emerald-400",
  "bg-amber-400",
  "bg-violet-400",
];

const seleccion = reactive({
  color: "bg-red-400",
  icono: "bolt",
});

function seleccionarColor(color) {
  seleccion.color = color;
  form.color_fondo = color;
  form.color_texto = color.replace("bg-", "text-");
  form.color_shadow = color.replace(
    "bg-",
    "shadow-[0px 8px 20px ".concat(color.replace("bg-", "")) + "]"
  );
  form.color_border = color.replace("bg-", "border-b-2 border-");
  form.color_hover = color.replace("bg-", "hover:text-");
}
const clickFuera = (e) => {
  if (
    !e.target.closest(".iconoApp") &&
    !e.target.closest(".material-symbols-rounded") &&
    !e.target.closest(".cajaSelector")
  ) {
    mostrarSelector.value = false;
  }
};

onMounted(() => document.addEventListener("click", clickFuera));
onBeforeUnmount(() => document.removeEventListener("click", clickFuera));

const filtroIcono = ref("");
const iconosFiltrados = computed(() =>
  iconosDisponibles.filter((icon) =>
    icon.toLowerCase().includes(filtroIcono.value.toLowerCase())
  )
);
</script>

<template>
  <transition name="fade">
    <div
      v-if="props.mostrar"
      @click.self="emit('cerrar')"
      class="fixed inset-0 bg- bg-mono-negro bg-opacity-65 backdrop-blur-[2px] pr-5 flex items-center justify-end z-50"
    >
      <!-- Modal deslizante -->
      <transition name="slide">
        <div
          class="bg-secundary-default border border-secundary-light p-6 rounded-lg w-full max-w-lg relative text-mono-blanco transform transition-transform duration-300"
        >
          <div class="headerPop flex items-center justify-between">
            <h2 class="text-xl font-bold">Crear nueva aplicación</h2>
            <i
              class="material-symbols-rounded cursor-pointer"
              @click.self="emit('cerrar')"
              :class="[hoverTexto]"
              >cancel</i
            >
          </div>

          <div class="caja my-4">
            <div class="flex items-center justify-between">
              <label
                for="rol-usuario"
                class="text-[14px] text-secundary-default dark:text-secundary-light"
                >Nombre de aplicación:</label
              >

              <p
                class="2xl:text-[10px] xl:text-[12px] text-[8px] text-right text-secundary-light"
              >
                {{ form.nombre_app.length }} / {{ limitesCaracteres.nombre_app }}
              </p>
            </div>

            <div
              class="flex items-center titulo-icono w-full rounded-lg p-5 border border-secundary-light my-4 gap-3"
            >
              <div class="flex items-center gap-1 relative">
                <!-- Ícono principal -->
                <div
                  class="iconoApp w-[50px] h-[50px] rounded-md grid place-content-center"
                  :class="seleccion.color"
                  @click="mostrarSelector = !mostrarSelector"
                >
                  <span class="material-symbols-rounded text-[35px]">{{
                    seleccion.icono
                  }}</span>
                </div>

                <span
                  class="material-symbols-rounded cursor-pointer"
                  @click="mostrarSelector = !mostrarSelector"
                >
                  keyboard_arrow_down
                </span>

                <!-- Caja flotante -->
                <div
                  v-if="mostrarSelector"
                  class="cajaSelector absolute top-[55px] left-5 bg-white dark:bg-secundary-default border border-gray-300 dark:border-secundary-light rounded-lg p-4 z-50 w-[360px] max-w-[380px] shadow-lg ..."
                >
                  <!-- Selector de color -->
                  <div class="mb-3">
                    <p
                      class="text-sm font-semibold text-mono-blac k dark:text-mono-blanco mb-1"
                    >
                      Color de fondo:
                    </p>
                    <div class="flex gap-2 flex-wrap">
                      <div
                        v-for="color in coloresDisponibles"
                        :key="color"
                        class="w-6 h-6 rounded-md cursor-pointer border border-white hover:ring-2"
                        :class="color"
                        @click="seleccionarColor(color)"
                      ></div>
                    </div>
                  </div>

                  <!-- Selector de ícono -->
                  <div class="">
                    <p
                      class="text-sm font-semibold text-mono-black dark:text-mono-blanco mb-1"
                    >
                      Ícono:
                    </p>
                    <!-- Buscador de íconos -->
                    <input
                      v-model="filtroIcono"
                      type="text"
                      placeholder="Buscar ícono..."
                      class="mb-2 px-2 py-1 border rounded w-full dark:bg-secundary-default"
                    />

                    <!-- Íconos filtrados -->
                    <div
                      class="flex gap-1.5 flex-wrap max-h-48 overflow-auto scrollbar-custom"
                    >
                      <span
                        v-for="icon in iconosFiltrados"
                        :key="icon"
                        :title="icon"
                        class="material-symbols-rounded cursor-pointer p-2 rounded hover:bg-gray-200 dark:hover:bg-secundary-light"
                        @click="
                          () => {
                            seleccion.icono = icon;
                            form.icono_app = icon;
                            mostrarSelector = false;
                          }
                        "
                      >
                        {{ icon }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>

              <input
                type="text"
                class="w-full border border-secundary-light rounded-md px-2 py-1 focus:outline-none focus:border-none font-normal bg-transparent text-secundary-default dark:text-mono-blanco"
                placeholder="Ingresa el nombre de la app"
                v-model="form.nombre_app"
              
                @input="(e) => handleInput(e, form, 'nombre_app')"
              />
            </div>

            <div class="flex items-center justify-between">
              <label
                for="rol-usuario"
                class="text-[14px] text-secundary-default dark:text-secundary-light"
                >descripción de la aplicación:</label
              >

              <p
                class="2xl:text-[10px] xl:text-[12px] text-[8px] text-right text-secundary-light"
              >
                {{ form.descripcion_app.length }} /
                {{ limitesCaracteres.descripcion_app }}
              </p>
            </div>
            <div class="">
              <input
                type="text"
                class="w-full border border-secundary-light rounded-md px-2 py-1 my-2 focus:outline-none focus:border-none font-normal bg-transparent text-secundary-default dark:text-mono-blanco"
                placeholder="Ingresa una breve descripción"
                v-model="form.descripcion_app"
                @input="(e) => handleInput(e, form, 'descripcion_app')"
              />
            </div>

            <div class="tags flex gap-2 my-2">
              <div class="desplegar-estados w-[33.3%]">
                <div class="flex items-center justify-between">
                  <label
                    for="rol-usuario"
                    class="text-[14px] text-secundary-default dark:text-secundary-light"
                    >Estado:</label
                  >
                </div>

                <div class="custom-select">
                  <select
                    v-model="form.id_estado"
                    class="w-full 2xl:p-2 border border-secundary-light rounded-md"
                  >
                    <option value="" disabled>Disponibles: {{ estados.length }}</option>
                    <option v-for="estado in estados" :key="estado.id" :value="estado.id">
                      {{ estado.tipo_estado }}
                    </option>
                  </select>
                  <div class="select-arrow"></div>
                </div>
              </div>

              <div class="desplegar-plan w-[33.3%]">
                <div class="flex items-center justify-between">
                  <label
                    for="rol-usuario"
                    class="text-[14px] text-secundary-default dark:text-secundary-light"
                    >Plan aplicacion:</label
                  >
                </div>

                <div class="custom-select">
                  <select
                    v-model="form.id_plan_aplicacion"
                    class="w-full 2xl:p-2 border border-secundary-light rounded-md"
                  >
                    <option value="" disabled>
                      Disponibles: {{ plan_aplicaciones.length }}
                    </option>
                    <option
                      v-for="plan in plan_aplicaciones"
                      :key="plan.id"
                      :value="plan.id"
                    >
                      {{ plan.nombre_plan }}
                    </option>
                  </select>
                  <div class="select-arrow"></div>
                </div>
              </div>

              <div class="desplegar-membresia w-[33.3%]">
                <div class="flex items-center justify-between">
                  <label
                    for="rol-usuario"
                    class="text-[14px] text-secundary-default dark:text-secundary-light"
                    >Membresía:</label
                  >
                </div>

                <div class="custom-select">
                  <select
                    v-model="form.id_membresia"
                    class="w-full 2xl:p-2 border border-secundary-light rounded-md"
                  >
                    <option value="" disabled>
                      Disponibles: {{ membresias.length }}
                    </option>
                    <option
                      v-for="membresia in membresias"
                      :key="membresia.id"
                      :value="membresia.id"
                    >
                      {{ membresia.nombre_membresia }}
                    </option>
                  </select>
                  <div class="select-arrow"></div>
                </div>
              </div>
            </div>

            <button @click="submitForm" class="w-full mt-10" :class="[buttonClase]">
              Crear aplicación <span class="material-symbols-rounded">send</span>
            </button>
          </div>
        </div>
      </transition>
    </div>
  </transition>
</template>

<style scoped>
/* Fade para fondo */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* Slide para el modal */
.slide-enter-active,
.slide-leave-active {
  transition: transform 0.3s ease;
}

.slide-enter-from {
  transform: translateX(100%);
}

.slide-leave-to {
  transform: translateX(100%);
}
</style>
