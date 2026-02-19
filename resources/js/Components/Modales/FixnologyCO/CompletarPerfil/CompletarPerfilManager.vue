<script setup>
import { computed, defineAsyncComponent } from "vue";
import { usePage } from "@inertiajs/vue3";

// --- IMPORTACIÓN DINÁMICA (LAZY LOADING) ---
// Esto hace que solo se cargue el código necesario para cada app
const CompletarPerfilRepos = defineAsyncComponent(() => 
  import("@/Components/Modales/FixnologyCO/CompletarPerfil/CompletarPerfilRepos.vue")
);

const CompletarPerfilMyParty = defineAsyncComponent(() => 
  import("@/Components/Modales/FixnologyCO/CompletarPerfil/CompletarPerfilMyParty.vue")
);

const page = usePage();

// --- DETECTAR APLICACIÓN ACTUAL ---
const currentApp = computed(() => {
  // Opción 1: Prioridad a la variable que ya tienes en el usuario
  const appRoute = page.props.auth.user?.app_name_route;
  
  // Opción 2: Si no viene en el user, mirar la URL (parametro 'aplicacion')
  const urlApp = route().params.aplicacion;

  // Normalizamos a minusculas para comparar
  return (appRoute || urlApp || '').toLowerCase();
});

// Computed para saber si debemos mostrar ALGO (para no ensuciar el template)
const shouldShowModal = computed(() => !!page.props.auth.user?.mustCompleteProfile);
</script>

<template>
  <div v-if="shouldShowModal">
    <CompletarPerfilRepos 
      v-if="currentApp === 'repos' || currentApp === 'repos.dashboard'" 
    />
    
    <CompletarPerfilMyParty 
      v-else-if="currentApp === 'myparty' || currentApp === 'myparty.dashboard'" 
    />
    
    <CompletarPerfilRepos v-else />
  </div>
</template>