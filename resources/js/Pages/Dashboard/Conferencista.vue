<script setup>
import { Head, Link } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import Sidebar from "@/Components/Sidebar/Sidebar.vue";
import { LogOut, User, FileText, Calendar } from "lucide-vue-next";

const props = defineProps({
  auth: {
    type: Object,
    default: () => ({}),
  },
  perfil: {
    type: Object,
    default: null,
  },
});

const nombreCompleto = props.perfil
  ? [
      props.perfil.primer_nombre,
      props.perfil.segundo_nombre,
      props.perfil.primer_apellido,
      props.perfil.segundo_apellido,
    ]
      .filter(Boolean)
      .join(" ")
  : null;
</script>

<template>
  <Head title="Panel Conferencista" />

  <AuthenticatedLayout>
    <Sidebar :auth="auth" />

    <div class="py-12">
      <div class="mx-auto max-w-4xl sm:px-6 lg:px-8 space-y-6">

        <div class="bg-white rounded-2xl shadow-sm px-8 py-6 border border-gray-100">
          <h1 class="text-2xl font-bold text-gray-800">
            Bienvenido, {{ nombreCompleto ?? auth.user?.correo_principal ?? 'Conferencista' }}
          </h1>
          <p class="mt-1 text-sm text-gray-500">Panel de conferencista — F&amp;C Consultores</p>
        </div>

        <div v-if="!perfil" class="bg-amber-50 border border-amber-200 rounded-2xl px-8 py-6">
          <div class="flex items-start gap-4">
            <User class="h-6 w-6 text-amber-500 mt-0.5 shrink-0" />
            <div>
              <p class="font-semibold text-amber-800">Perfil pendiente</p>
              <p class="mt-1 text-sm text-amber-700">
                Aún no tienes un perfil de conferencista asociado a esta cuenta.
                Contacta al administrador para completar tu registro.
              </p>
            </div>
          </div>
        </div>

        <div v-if="perfil" class="bg-white rounded-2xl shadow-sm border border-gray-100 divide-y divide-gray-100">
          <div class="px-8 py-5 flex items-center gap-4">
            <img
              v-if="perfil.foto"
              :src="`/storage/${perfil.foto}`"
              :alt="nombreCompleto"
              class="h-16 w-16 rounded-full object-cover border border-gray-200"
            />
            <div v-else class="h-16 w-16 rounded-full bg-gray-100 flex items-center justify-center border border-gray-200">
              <User class="h-8 w-8 text-gray-400" />
            </div>
            <div>
              <p class="text-lg font-semibold text-gray-800">{{ nombreCompleto }}</p>
              <p class="text-sm text-gray-500">{{ perfil.correo }}</p>
            </div>
          </div>

          <div class="px-8 py-5 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm text-gray-700">
            <div class="flex items-center gap-2">
              <FileText class="h-4 w-4 text-gray-400" />
              <span class="font-medium">Teléfono:</span>
              <span>{{ perfil.telefono ?? '—' }}</span>
            </div>
            <div class="flex items-center gap-2">
              <Calendar class="h-4 w-4 text-gray-400" />
              <span class="font-medium">HV:</span>
              <a
                v-if="perfil.url_hv"
                :href="perfil.url_hv"
                target="_blank"
                class="text-blue-600 underline truncate max-w-xs"
              >Ver hoja de vida</a>
              <span v-else>—</span>
            </div>
          </div>

          <div v-if="perfil.biografia" class="px-8 py-5">
            <p class="text-sm font-medium text-gray-600 mb-1">Biografía</p>
            <p class="text-sm text-gray-700 leading-relaxed">{{ perfil.biografia }}</p>
          </div>
        </div>

        <div class="flex justify-end">
          <Link
            :href="route('logout')"
            method="post"
            as="button"
            class="flex items-center gap-2 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 rounded-xl transition-all duration-200"
          >
            <LogOut class="h-4 w-4" />
            Finalizar sesión
          </Link>
        </div>

      </div>
    </div>
  </AuthenticatedLayout>
</template>
