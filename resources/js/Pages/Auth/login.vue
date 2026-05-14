<script setup>
import BtnUniversal from "@/Components/BtnUniversal.vue";
import RevealSection from "@/Components/RevealSection.vue";
import FormInput from "@/Components/Shared/inputs/FormInput.vue";
import GuestLayout from "@/Layouts/GuestLayout.vue";
import MensajesLayout from "@/Layouts/MensajesLayout.vue";
import { useForm, Head } from "@inertiajs/vue3";
import { Loader2, Mail, Lock, ChevronRight, Building2 } from "lucide-vue-next";

const form = useForm({
  correo_principal: "",
  contrasena: "",
  recordar: false,
});

const submit = () => {
  form.post(route("login"), {
    onFinish: () => form.reset("contrasena"),
  });
};
</script>

<template>
  <Head title="Acceso al portal | F&C Consultores" />

  <GuestLayout>
    <RevealSection class="snap-start h-dvh overflow-hidden w-full">
      <div class="min-h-screen flex bg-white">
        <div class="hidden lg:flex lg:w-1/2 relative bg-slate-900 overflow-hidden">
          <div
            class="absolute inset-0 bg-gradient-to-br from-slate-900 via-rose-950 to-slate-900 opacity-90"
          ></div>
          <div
            class="absolute -bottom-32 -left-32 w-96 h-96 rounded-full bg-rose-600/20 blur-3xl"
          ></div>
          <div
            class="absolute top-32 -right-32 w-96 h-96 rounded-full bg-red-600/20 blur-3xl"
          ></div>

          <div
            class="relative z-10 flex flex-col justify-between p-16 w-full h-full text-white"
          >
            <div class="relative z-20 flex-shrink-0">
              <a href="/" class="group flex items-center outline-none">
                <div
                  class="relative inline-block transition-transform duration-300 group-active:scale-95"
                >
                  <div
                    class="absolute inset-[-15px] -z-10 rounded-full bg-white/100 blur-xl transition-opacity duration-300"
                    :class="isScrolled || isMenuOpen ? 'opacity-100' : 'opacity-60'"
                  ></div>
                  <img
                    src="/images/logo-fyc.png"
                    alt="F&C Consultores"
                    class="w-auto object-contain transition-all duration-500 drop-shadow-sm"
                    :class="isScrolled || isMenuOpen ? 'h-12 md:h-16' : 'h-16 md:h-20'"
                  />
                </div>
              </a>
            </div>

            <div>
              <h2 class="text-4xl font-bold leading-tight mb-6">
                Plataforma profesional de<br />
                <span
                  class="text-transparent bg-clip-text bg-gradient-to-r from-rose-400 to-rose-700"
                >
                  Consultoría académica
                </span>
              </h2>
              <p class="text-slate-400 text-lg max-w-md leading-relaxed">
                Acceda a su entorno corporativo - aprendizaje para la administración
                segura de sus servicios.
              </p>
            </div>

            <div class="text-sm text-slate-500 font-medium">
              &copy; 2026 F&C Consultores S.A.S. Todos los derechos reservados.
            </div>
          </div>
        </div>

        <div
          class="w-full lg:w-1/2 flex items-center justify-center p-8 sm:p-12 lg:p-24 relative"
        >
          <div class="absolute top-8 left-8 lg:hidden flex items-center gap-2">
            <div
              class="w-10 h-10 bg-rose-600 rounded-lg flex items-center justify-center"
            >
              <Building2 class="w-5 h-5 text-white" />
            </div>
            <div>
              <span class="font-bold text-slate-900 leading-none block">F&C</span>
              <span class="text-xs text-rose-600 font-bold uppercase tracking-wider block"
                >Consultores</span
              >
            </div>
          </div>

          <div class="w-full max-w-md space-y-10 relative">
            <div
              class="fixed z-[55] pointer-events-none transition-all duration-1000 ease-[cubic-bezier(0.68,-0.55,0.27,1.55)] -bottom-14 right-0 w-40 md:w-[280px] rotate-[-10deg] -translate-x-2"
            >
              <img
                src="/images/buho_fyc.png"
                alt="Búho F&C"
                class="w-full h-auto drop-shadow-2xl transition-transform duration-700"
              />
            </div>
            <div>
              <a href="/">
                <div
                  class="flex items-center my-3 text-[14px] hover:text-rose-900 cursor-pointer hover:font-semibold transition-all"
                >
                  <span class="material-symbols-rounded">chevron_left</span> Volver al
                  inicio
                </div>
              </a>

              <h1 class="text-3xl font-bold text-slate-900 tracking-tight">
                Acceso al Sistema
              </h1>
              <p class="text-slate-500 mt-2 text-sm">
                Ingrese sus credenciales corporativas para continuar.
              </p>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
              <FormInput
                label="Correo Corporativo"
                type="email"
                v-model="form.correo_principal"
                icon="email"
                placeholder="Ingrese su correo asignado"
                required
                :max="50"
                activeColor="#e11d48"
                :error="form.errors.correo_principal"
              />

              <FormInput
                label="Tu Contraseña"
                type="password"
                v-model="form.contrasena"
                icon="lock"
                :error="form.errors.contrasena"
                @clear-error="form.clearErrors('contrasena')"
                placeholder="Ingresa tu clave"
                required
                activeColor="#e11d48"
              />

              <!-- <div class="flex justify-between mb-2 items-center">
                <a
                  href="#"
                  class="text-xs font-semibold text-rose-600 hover:text-rose-800 transition-colors"
                  >¿Olvidó su contraseña?</a
                >
              </div> -->

              <!-- <div class="flex items-center">
                <input
                  type="checkbox"
                  id="recordar"
                  v-model="form.recordar"
                  class="w-4.5 h-4.5 text-rose-600 border-slate-300 rounded focus:ring-rose-600 transition-colors cursor-pointer"
                />
                <label
                  for="recordar"
                  class="ml-2.5 block text-sm font-medium text-slate-600 cursor-pointer select-none"
                >
                  Mantener sesión iniciada
                </label>
              </div> -->

              <BtnUniversal
                label="Ingresar al Panel"
                icon="arrow_forward_ios"
                icon-position="right"
                size="lg"
                activeColor="#e11d48"
                :disabled="form.processing"
                process="Autenticando al usuario..."
                @click="submit"
              />
            </form>
          </div>

          <div
            class="absolute bottom-8 text-center w-full lg:hidden text-xs text-slate-400 font-medium"
          >
            &copy; 2026 F&C Consultores
          </div>
        </div>
      </div>
    </RevealSection>
  </GuestLayout>
</template>
