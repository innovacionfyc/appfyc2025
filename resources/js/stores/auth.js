import { defineStore, acceptHMRUpdate } from "pinia";
import axios from "axios";

export const useAuthStore = defineStore("auth", {
  state: () => ({
    // Aquí vivirá el objeto User junto con sus relaciones cargadas (Eager Loading)
    user: null,
  }),

  getters: {
    // ==========================================
    // 1. DATOS BÁSICOS DEL USUARIO
    // ==========================================
    isAuthenticated: (state) => !!state.user,
    uuid: (state) => state.user?.uuid || null,
    name: (state) => state.user?.name || '',
    email: (state) => state.user?.email || '',
    isActive: (state) => state.user?.is_active || false,

    // ==========================================
    // 2. RELACIÓN: ROL (User -> BelongsTo -> Role)
    // ==========================================
    role: (state) => state.user?.role || null,
    roleName: (state) => state.user?.role?.name || null,
    roleDisplayName: (state) => state.user?.role?.display_name || 'Sin Rol',

    // Utilidad: Verificar si el usuario tiene un rol específico (Ej: authStore.hasRole('admin'))
    hasRole: (state) => {
      return (roleName) => state.user?.role?.name === roleName;
    },

    // ==========================================
    // 3. RELACIÓN: EVENTOS GESTIONADOS (User -> HasMany -> Event)
    // ==========================================
    managedEvents: (state) => state.user?.managed_events || [],
    managedEventsCount: (state) => state.user?.managed_events?.length || 0,

    // Utilidad: Filtrar eventos gestionados que estén "publicados"
    publishedManagedEvents: (state) => {
      return (state.user?.managed_events || []).filter(event => event.status === 'published');
    },

    // Utilidad: Buscar un evento específico por su UUID
    getEventByUuid: (state) => {
      return (uuid) => (state.user?.managed_events || []).find(event => event.uuid === uuid) || null;
    },

    // ==========================================
    // 4. RELACIÓN PROFUNDA: SPEAKERS (User -> HasMany -> Event -> BelongsToMany -> Speaker)
    // ==========================================
    // Obtiene todos los conferencistas únicos de todos los eventos que gestiona este usuario
    allSpeakersInMyEvents: (state) => {
      const events = state.user?.managed_events || [];
      const uniqueSpeakers = new Map();

      events.forEach(event => {
        if (event.speakers) {
          event.speakers.forEach(speaker => {
            // Usamos el UUID del speaker como llave para evitar duplicados 
            // si un speaker participa en múltiples eventos del mismo manager
            if (!uniqueSpeakers.has(speaker.uuid)) {
              uniqueSpeakers.set(speaker.uuid, {
                ...speaker,
                // Opcional: Guardamos en qué evento(s) participa
                event_title: event.title,
                role_in_event: speaker.pivot?.role_in_event || 'No especificado'
              });
            }
          });
        }
      });

      return Array.from(uniqueSpeakers.values());
    }
  },

  actions: {
    setUser(user) {
      this.user = user;
    },
    
    clearUser() {
      this.user = null;
    },

    // Acción opcional: Si necesitas refrescar los eventos sin recargar toda la página
    async fetchMyEvents() {
      if (!this.user) return;
      try {
        // Asumiendo que tienes una ruta en Laravel que devuelve los eventos del usuario autenticado
        const response = await axios.get('/api/user/managed-events');
        
        // Actualizamos de forma reactiva solo la relación de eventos dentro del usuario
        this.user.managed_events = response.data;
      } catch (error) {
        console.error("Error al obtener los eventos:", error);
      }
    }
  },
});

// Soporte para Hot Module Replacement (HMR) en Vite
if (import.meta.hot) {
  import.meta.hot.accept(acceptHMRUpdate(useAuthStore, import.meta.hot));
}