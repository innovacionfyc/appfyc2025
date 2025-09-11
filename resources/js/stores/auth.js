// resources/js/stores/auth.js

import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

// Define el store con un nombre único 'auth'
export const useAuthStore = defineStore('auth', () => {
    // --- STATE ---
    // Almacenamos los datos del usuario. Inicia como null.
    const user = ref(null);

    // --- GETTERS ---
    // Un getter para saber si el usuario está autenticado.
    const isAuthenticated = computed(() => !!user.value);

    // Un getter para obtener el nombre del usuario fácilmente.
    const userName = computed(() => user.value?.name || 'Invitado');

    // Un getter para verificar si es administrador.
    const isAdmin = computed(() => user.value?.role === 'Administrador');

    // --- ACTIONS ---
    // Una acción para establecer los datos del usuario.
    function setUser(userData) {
        user.value = userData;
    }

    // Una acción para limpiar los datos al cerrar sesión.
    function clearUser() {
        user.value = null;
    }

    return {
        user,
        isAuthenticated,
        userName,
        isAdmin,
        setUser,
        clearUser,
    };
});