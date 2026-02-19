<script setup>
defineProps({
    app: Object,
    visible: Boolean
})
defineEmits(['cerrar'])
const { appName, bgClase, bgOpacity, focus, textoClase, borderClase, buttonFocus, hoverClase, hoverTexto, buttonClase, buttonSecundario } = Colors();

import useEstadoClass from '@/Composables/useEstado';

const { getEstadoClass } = useEstadoClass();

function getIniciales(nombreCompleto) {
    if (!nombreCompleto) return '';
    const partes = nombreCompleto.trim().split(' ');
    const iniciales = partes[0]?.charAt(0).toUpperCase() + (partes[1]?.charAt(0).toUpperCase() || '');
    return iniciales;
}

</script>

<template>
    <Teleport to="body">
        <transition name="fade">
            <div v-if="visible"
                class="fixed inset-0 bg- bg-mono-negro bg-opacity-65 backdrop-blur-[2px] pr-5 flex items-center justify-end z-50">
                <!-- Modal deslizante -->
                <transition name="slide">
                    <div
                        class="bg-secundary-default border border-secundary-light p-6 rounded-lg w-full max-w-lg relative text-mono-blanco transform transition-transform duration-300">
                        <div class="headerPop flex items-center justify-between">
                            <h2 class="text-xl font-bold flex items-center gap-2">
                                Detalles de la aplicación
                            </h2>
                            <i class="material-symbols-rounded cursor-pointer" @click="$emit('cerrar')"
                                :class="[hoverTexto]">cancel</i>
                        </div>

                        <div class="encan flex items-end justify-between">
                            <div class=" text-center app flex flex-col items-center justify-center mt-5 gap-1">
                                <div class="icono grid place-content-center h-[50px] w-[50px] rounded-lg"
                                    :class="app.color_fondo">
                                    <i class="material-symbols-rounded text-mono-blanco">{{ app.icono_app || 'help'
                                        }}</i>
                                </div>
                                <p class="text-secundary-light font-semibold">{{ app.descripcion }}</p>

                            </div>
                            <div class="usuarios text-center">
                                <h4 class="text-[50px] font-bold">{{ app.usuarios_en_tiendas }}</h4>
                                <p class="text-[14px] -mt-[10px]">Clientes activos</p>
                            </div>
                        </div>

                        <div class="tags flex items-center gap-3 mt-5 justify-between">
                            <div class="estado rounded-md p-1  flex items-center gap-1">
                                <div class="h-3 w-4 rounded-full" :class="getEstadoClass(app.estado.tipo_estado)"></div>
                                {{ app.estado.tipo_estado }}
                            </div>

                            <div class="plan rounded-md p-1  flex items-center gap-1">
                                <div class="h-3 w-4 rounded-full" :class="[bgClase]"></div>{{ app.plan.nombre_plan }}
                            </div>
                            <div class="membresia rounded-md p-1  flex items-center gap-1">
                                <div class="h-3 w-4 rounded-full" :class="[bgClase]"></div> {{
                                    app.membresia.nombre_membresia }}
                            </div>
                        </div>

                        <!-- Usuarios -->
                        <h2 class="mt-6 font-bold">Usuarios usándola:</h2>

                        <!-- Si hay usuarios -->
                        <div v-if="app.detalle_usuarios && app.detalle_usuarios.length > 0">
                            <div v-for="usuario in app.detalle_usuarios" :key="usuario.nombres"
                                class="flex items-center gap-4 p-2">
                                <!-- Avatar del usuario -->
                                <div class="foto">
                                    <div v-if="usuario.foto"
                                        class="w-10 h-10 rounded-full flex items-center justify-center">
                                        <img :src="usuario.foto" alt="Foto"
                                            class="w-10 h-10 rounded-full object-cover" />
                                    </div>
                                    <div v-else
                                        class="w-10 h-10 rounded-full flex items-center justify-center font-bold"
                                        :class="[bgClase]">
                                        {{ getIniciales(usuario.nombres) }}
                                    </div>
                                </div>

                                <div class="flex items-center justify-between w-full">
                                    <p class="font-semibold">{{ usuario.nombres }}</p>
                                    <p class="text-sm text-secundary-light">{{ usuario.tienda }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Si NO hay usuarios -->
                        <div v-else class="text-center text-secundary-light mt-4">
                            Aún no se ha registrado ningún usuario en esta aplicación.
                        </div>




                    </div>
                </transition>
            </div>
        </transition>
    </Teleport>
</template>
