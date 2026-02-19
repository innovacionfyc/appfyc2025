<script setup>
import { computed } from "vue";

const props = defineProps({
 mesa: {
   type: Object,
   required: true,
 },
});

const emit = defineEmits([
 "cambiar-estado",
 "eliminar",
 "editar",
 "unir",
 "ver-pedido",
 "close",
]);

const estados = [
 {
   id: 29,
   label: "Libre",
   color: "bg-blue-500",
   text: "text-blue-500",
   icon: "check_circle",
 },
 {
   id: 33,
   label: "Ocupada",
   color: "bg-emerald-500",
   text: "text-emerald-500",
   icon: "restaurant",
 },
 {
   id: 31,
   label: "Reservada",
   color: "bg-rose-500",
   text: "text-rose-500",
   icon: "bookmark",
 },
 {
   id: 35,
   label: "Inactiva",
   color: "bg-gray-500",
   text: "text-gray-500",
   icon: "block",
 },
  {
    id: 41,
    label: "Facturar",
    color: "bg-cyan-500",
    text: "text-cyan-500",
    icon: "request_quote",
},
];

const cambiarEstado = (id) => {
 emit("cambiar-estado", props.mesa.id, id);
};

const verPedido = () => {
 emit("ver-pedido", props.mesa);
 emit("close"); // Esto disparará el openMenuId = null en el padre
};

const eliminarMesa = () => {
 emit("eliminar", props.mesa);
 emit("close");
};

const esEstadoDeshabilitado = (estadoId) => {
  const estadoActual = Number(props.mesa.estado_id);
  const targetId = Number(estadoId);

  // REGLA DE ORO: Si es el estado en el que ya está la mesa, 
  // debe estar habilitado para que se vea "marcado" en la UI.
  if (estadoActual === targetId) return false;

  // CASO 1: La mesa está LIBRE (29)
  if (estadoActual === 29) {
    // Bloqueamos manualmente "Ocupado" (33) y "Facturar" (41)
    // porque estos estados deberían activarse solo al crear un pedido.
    if (targetId === 33 || targetId === 41) return true;
  }

  if (estadoActual === 31) {
    // Bloqueamos manualmente "Ocupado" (33) y "Facturar" (41)
    // porque estos estados deberían activarse solo al crear un pedido.
    if (targetId === 33 || targetId === 41) return true;
  }

  if (estadoActual === 35) {
    // Bloqueamos manualmente "Ocupado" (33) y "Facturar" (41)
    // porque estos estados deberían activarse solo al crear un pedido.
    if (targetId === 33 || targetId === 41) return true;
  }

  // CASO 2: La mesa está en proceso de FACTURACIÓN (41)
  // Inhabilitar cualquier cambio manual (Todo bloqueado)
  if (estadoActual === 41) return true;

  // CASO 3: La mesa está OCUPADA (33)
  // Inhabilitar cualquier cambio manual (Todo bloqueado)
  if (estadoActual === 33) return true;

  // Por defecto, cualquier otro estado no mencionado queda habilitado
  return false;
};
</script>

<template>
<div
class="flex flex-col w-auto text-left select-none bg-white dark:bg-gray-900 shadow-2xl rounded-2xl overflow-hidden border border-gray-100 dark:border-gray-700"
>
<div
class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50 flex items-center justify-between"
>
<div class="flex items-center gap-2">
<div
class="w-2 h-2 rounded-full animate-pulse"
:class="estados.find((e) => e.id === mesa.estado_id)?.color"
></div>
<h4
class="text-sm font-black text-gray-800 dark:text-gray-100 truncate max-w-[120px]"
>
{{ mesa.nombre_o_numero }}
</h4>
</div>
<button
@click="emit('editar', mesa)"
class="p-1.5 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg text-gray-400 hover:text-primary transition-colors"
title="Editar Mesa"
>
<span class="material-symbols-rounded text-[18px]">edit</span>
</button>
</div>

<div class="p-3">
<p class="px-1 text-[9px] text-gray-400 font-black uppercase tracking-widest mb-2">
Estado de Mesa
</p>
<div class="flex justify-between gap-1 bg-gray-100 dark:bg-gray-800 p-1 rounded-xl">
       <button
  v-for="estado in estados"
  :key="estado.id"
  @click="cambiarEstado(estado.id)"
  :disabled="esEstadoDeshabilitado(estado.id)"
  class="flex-1 flex flex-col items-center justify-center py-2 rounded-lg transition-all duration-300 relative group"
  :class="[
    // Lógica de estado seleccionado
    mesa.estado_id === estado.id
      ? 'bg-white dark:bg-gray-700 shadow-sm scale-105 z-10'
      : 'hover:bg-white/50 dark:hover:bg-gray-700/50',
    
    // Lógica visual de deshabilitado
    esEstadoDeshabilitado(estado.id) 
      ? 'opacity-30 cursor-not-allowed grayscale' 
      : 'opacity-100 cursor-pointer'
  ]"
>
  <span
    class="material-symbols-rounded text-[20px] transition-transform"
    :class="[
        mesa.estado_id === estado.id ? estado.text : 'text-gray-400',
        !esEstadoDeshabilitado(estado.id) ? 'group-hover:scale-110' : ''
    ]"
  >
    {{ estado.icon }}
  </span>
  
  <span
    class="text-[8px] font-bold mt-0.5"
    :class="
      mesa.estado_id === estado.id
        ? 'text-gray-900 dark:text-white'
        : 'text-gray-500'
    "
  >
    {{ estado.label }}
  </span>

  <div
    v-if="mesa.estado_id === estado.id"
    class="absolute -bottom-1 w-1 h-1 bg-primary rounded-full"
  ></div>
</button>
</div>
</div>
<button
    @click="verPedido"
    :disabled="!mesa.orden_activa" 
    :class="[
       'flex mx-3 items-center justify-center gap-1 p-1 rounded-md transition-all border',
        mesa.orden_activa
            ? 'bg-indigo-50 text-indigo-600 border-indigo-100 hover:bg-indigo-100'
            : 'bg-gray-50 text-gray-300 border-gray-100 cursor-not-allowed opacity-50',
    ]"
>
    <span class="material-symbols-rounded text-xl">receipt_long</span>
    <span class="text-[10px] font-black uppercase">Ver pedido asignado</span>
</button>

<button
      @click="emit('mover', mesa)"
:disabled="!mesa.orden_actual"
:class="[
       'flex mx-3 items-center justify-center gap-1 p-1 rounded-md transition-all border',
       mesa.orden_actual
         ? 'bg-orange-50 text-orange-600 border-orange-100 hover:bg-orange-100'
         : 'bg-gray-50 text-gray-300 border-gray-100 cursor-not-allowed opacity-50',
     ]"
>
<span class="material-symbols-rounded text-xl">move_up</span>
<span class="text-[10px] font-black uppercase">Mover pedido a otra mesa</span>
</button>

<div class="px-2 pb-2 mt-auto">
<button
@click="emit('eliminar', mesa)"
class="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-colors group border border-transparent hover:border-rose-100 dark:hover:border-rose-900/50"
>
<span
class="material-symbols-rounded text-[18px] group-hover:rotate-12 transition-transform"
>delete</span
>
<span class="text-xs font-black uppercase tracking-tight">Eliminar mesa</span>
</button>
</div>
</div>
</template>

<style scoped>
/* Transición suave para los cambios de estado */
button {
 -webkit-tap-highlight-color: transparent;
}
</style>