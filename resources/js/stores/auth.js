import { defineStore, acceptHMRUpdate } from "pinia";
import axios from "axios";

export const useAuthStore = defineStore("auth", {
  state: () => ({
    // Aquí vivirá el objeto Usuario junto con sus perfiles y relaciones anidadas
    user: null,
  }),

  getters: {
    // ==========================================
    // 1. DATOS BÁSICOS DEL USUARIO (Tabla: usuarios)
    // ==========================================
    isAuthenticated: (state) => !!state.user,
    id: (state) => state.user?.id || null,
    correoPrincipal: (state) => state.user?.correo_principal || '',
    numeroDocumentoUsuario: (state) => state.user?.numero_documento || '',
    perfilCompleto: (state) => state.user?.perfil_completo || false,
    ultimaSesion: (state) => state.user?.ultima_sesion || null,
    
    // Relación Directa: Estado
    estadoUsuario: (state) => state.user?.estado?.categoria_estado || 'Desconocido',

    // ==========================================
    // 2. DATOS DEL PERFIL DINÁMICO (Organizador o Conferencista)
    // ==========================================
    perfilActivo: (state) => state.user?.perfil_organizador || state.user?.perfil_conferencista || null,

    // Nombres
    primerNombre: (state) => state.user?.perfil_organizador?.primer_nombre || state.user?.perfil_conferencista?.primer_nombre || '',
    segundoNombre: (state) => state.user?.perfil_organizador?.segundo_nombre || state.user?.perfil_conferencista?.segundo_nombre || '',
    primerApellido: (state) => state.user?.perfil_organizador?.primer_apellido || state.user?.perfil_conferencista?.primer_apellido || '',
    segundoApellido: (state) => state.user?.perfil_organizador?.segundo_apellido || state.user?.perfil_conferencista?.segundo_apellido || '',

    nombreCompleto: (state) => {
      const perfil = state.user?.perfil_organizador || state.user?.perfil_conferencista;
      if (!perfil) return 'Usuario del Sistema';
      return [perfil.primer_nombre, perfil.segundo_nombre, perfil.primer_apellido, perfil.segundo_apellido]
        .filter(Boolean)
        .join(' ');
    },

    // Multimedia
    fotoPerfil: (state) => state.user?.perfil_organizador?.foto || state.user?.perfil_conferencista?.foto || '/images/default-avatar.png',

    // Datos de Contacto Unificados
    telefonoPrincipal: (state) => state.user?.perfil_organizador?.telefono_personal || state.user?.perfil_conferencista?.telefono || '',
    telefonoCorporativo: (state) => state.user?.perfil_organizador?.telefono_corporativo || '',
    correoSecundario: (state) => state.user?.perfil_organizador?.correo_corporativo || state.user?.perfil_conferencista?.correo || '',

    // ==========================================
    // 3. DATOS CORPORATIVOS (Exclusivo Perfil Organizador)
    // ==========================================
    cargo: (state) => state.user?.perfil_organizador?.cargo || 'Conferencista',
    tipoDocumento: (state) => state.user?.perfil_organizador?.tipo_documento?.sigla || 'CC', // Carga relación tipo_documento
    equipoNombre: (state) => state.user?.perfil_organizador?.equipo?.nombre || 'Sin Equipo', // Carga relación equipo_fyc
    equipoSlug: (state) => state.user?.perfil_organizador?.equipo?.slug || null,

    // ==========================================
    // 4. DATOS ACADÉMICOS (Exclusivo Perfil Conferencista)
    // ==========================================
    biografia: (state) => state.user?.perfil_conferencista?.biografia || null,
    urlHojaVida: (state) => state.user?.perfil_conferencista?.url_hv || null,

    // Área encargada (Aplica para ambos)
    areaFormacion: (state) => state.user?.perfil_organizador?.area_encargada?.nombre || state.user?.perfil_conferencista?.area_encargada?.nombre || 'Área General',
    areaColorPrincipal: (state) => state.user?.perfil_organizador?.area_encargada?.color_hex_principal || state.user?.perfil_conferencista?.area_encargada?.color_hex_principal || '#4F46E5',

    // ==========================================
    // 5. VALIDACIÓN DE ROLES
    // ==========================================
    rolActual: (state) => state.user?.rol || null,
    
    // Obtiene el nombre real del rol (ej. "Administrador de Sistema") si viene en la relación
    rolDescripcion: (state) => state.user?.perfil_organizador?.rol?.descripcion || 'Acceso Académico',

    hasRole: (state) => (slugEsperado) => state.user?.rol === slugEsperado,
    isSuperAdmin: (state) => state.user?.rol === 'super-admin',
    isComercial: (state) => state.user?.rol === 'comercial',
    isConferencista: (state) => state.user?.rol === 'conferencista',

    // ==========================================
    // 6. RELACIONES DE EVENTOS
    // ==========================================
    eventosOrganizados: (state) => state.user?.eventos_organizados || [],
    cantidadEventosOrganizados: (state) => state.user?.eventos_organizados?.length || 0,

    eventosPublicados: (state) => {
      return (state.user?.eventos_organizados || []).filter(evento => evento.estado?.categoria_estado === 'Publicado');
    },

    getEventoById: (state) => (id) => (state.user?.eventos_organizados || []).find(evento => evento.id === id) || null,

    todosMisConferencistas: (state) => {
      const eventos = state.user?.eventos_organizados || [];
      const conferencistasUnicos = new Map();

      eventos.forEach(evento => {
        if (evento.conferencistas) {
          evento.conferencistas.forEach(conferencista => {
            if (!conferencistasUnicos.has(conferencista.id)) {
              conferencistasUnicos.set(conferencista.id, {
                ...conferencista,
                evento_titulo: evento.titulo
              });
            }
          });
        }
      });
      return Array.from(conferencistasUnicos.values());
    }
  },

  actions: {
    setUser(user) {
      this.user = user;
    },
    
    clearUser() {
      this.user = null;
    },

    async fetchMisEventos() {
      if (!this.user) return;
      try {
        const response = await axios.get('/api/eventos/mis-eventos');
        this.user.eventos_organizados = response.data;
      } catch (error) {
        console.error("Error al obtener los eventos:", error);
      }
    }
  },
});

if (import.meta.hot) {
  import.meta.hot.accept(acceptHMRUpdate(useAuthStore, import.meta.hot));
}