<script setup>
import { useForm } from "@inertiajs/vue3";
import { ref, computed } from "vue";
import BaseModalSteps from '@/Components/Shared/Modales/BaseModalSteps.vue';
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";

const props = defineProps({ 
   isOpen: { type: Boolean, required: true } 
});

const emit = defineEmits(['close']);

const form = useForm({
   nombre: '',
   descripcion: '',
   color_hex: '#6366f1',
    imagen_plano_url: 'storefront', 
});

// Paleta de colores vibrante y moderna
const coloresPreset = [
    '#6366f1', '#8b5cf6', '#ec4899', '#ef4444', 
    '#f59e0b', '#10b981', '#06b6d4', '#3b82f6',
    '#374151', '#78350f'
];

// Iconos expandidos para todo tipo de ambientes
const iconosPreset = [
   { name: 'Salón', icon: 'storefront' },
    { name: 'Terraza', icon: 'deck' },
   { name: 'Barra', icon: 'local_bar' },
   { name: 'VIP', icon: 'diamond' },
   { name: 'Patio', icon: 'yard' },
    { name: 'Balcón', icon: 'balcony' },
    { name: 'Piscina', icon: 'pool' },
    { name: 'Café', icon: 'coffee' },
    { name: 'Cocina', icon: 'soup_kitchen' },
    { name: 'Evento', icon: 'celebration' },
];

const submit = () => {
   form.post(route('repos.zonas.store'), {
       preserveScroll: true,
       onSuccess: () => {
           form.reset();
           emit('close');
       }
   });
};

// Computada para el estilo dinámico de la previsualización
const previewStyle = computed(() => ({
    backgroundColor: form.color_hex,
    boxShadow: `0 10px 30px -10px ${form.color_hex}80`
}));
</script>

<template>
<BaseModalSteps 
:isOpen="isOpen" 
        title="Arquitecto de Zonas" 
        description="Crea ambientes únicos para organizar tu distribución de mesas."
finalButtonText="Crear Zona"
:isSubmitting="form.processing"
@close="emit('close')" 
@submit="submit"
        :currentStep="1"    :totalSteps="1"
>
        <div class="flex flex-col lg:flex-row gap-8 py-6">
            <div class="flex-1 space-y-8">
                <section class="space-y-4">
                    <header class="flex items-center gap-3">
                        <div class="w-1.5 h-5 bg-primary rounded-full"></div>
                        <h4 class="text-[10px] font-black uppercase tracking-widest text-slate-400">Datos Principales</h4>
                    </header>
                    <div class="grid grid-cols-1 gap-4">
                        <InputTexto 
                            v-model="form.nombre" 
                            label="Nombre de la Zona" 
                            placeholder="Ej: Terraza VIP, Salón Principal..." 
                            :error="form.errors.nombre" 
                        />
                        <InputTexto 
                            v-model="form.descripcion" 
                            label="Descripción Corta" 
                            placeholder="Ej: Área de fumadores con vista al mar" 
                            :error="form.errors.descripcion" 
                        />
                    </div>
                </section>

                <section class="space-y-4">
                    <header class="flex items-center gap-3">
                        <div class="w-1.5 h-5 bg-emerald-500 rounded-full"></div>
                        <h4 class="text-[10px] font-black uppercase tracking-widest text-slate-400">Identificador Cromático</h4>
                    </header>
                    <div class="p-5 bg-slate-50 dark:bg-slate-950 rounded-[2rem] border border-slate-200/50 dark:border-slate-800">
                        <div class="flex flex-wrap gap-3">
                            <button 
                                v-for="color in coloresPreset" 
                                :key="color" 
                                type="button"
                                @click="form.color_hex = color"
                                :style="{ backgroundColor: color }" 
                                class="w-9 h-9 rounded-full border-4 transition-all hover:scale-110 flex items-center justify-center text-white"
                                :class="form.color_hex === color ? 'border-white dark:border-slate-800 ring-4 ring-primary/20 scale-110' : 'border-transparent opacity-80'"
                            >
                                <span v-if="form.color_hex === color" class="material-symbols-rounded text-sm">check</span>
                            </button>
                            <label class="w-9 h-9 rounded-full border-2 border-dashed border-slate-300 dark:border-slate-700 flex items-center justify-center cursor-pointer hover:border-primary transition-colors overflow-hidden">
                                <input type="color" v-model="form.color_hex" class="absolute opacity-0 w-8 h-8 cursor-pointer" />
                                <span class="material-symbols-rounded text-slate-400 text-sm">colorize</span>
                            </label>
                        </div>
                    </div>
                </section>
</div>

            <aside class="w-full lg:w-[320px] space-y-8">
                <section class="space-y-4">
                    <header class="flex items-center gap-3">
                        <div class="w-1.5 h-5 bg-amber-500 rounded-full"></div>
                        <h4 class="text-[10px] font-black uppercase tracking-widest text-slate-400">Iconografía</h4>
                    </header>
                    <div class="grid grid-cols-5 gap-2">
                        <button 
                            v-for="item in iconosPreset" 
                            :key="item.icon" 
                            type="button"
                            @click="form.imagen_plano_url = item.icon"
                            class="aspect-square flex flex-col items-center justify-center rounded-xl border-2 transition-all relative group"
                            :class="form.imagen_plano_url === item.icon 
                                ? 'bg-primary/5 border-primary text-primary shadow-sm' 
                                : 'bg-white dark:bg-slate-900 border-slate-100 dark:border-slate-800 text-slate-400 hover:border-slate-200'"
                        >
                            <span class="material-symbols-rounded text-xl group-hover:scale-110 transition-transform">
                                {{ item.icon }}
                            </span>
                            <span class="absolute -top-8 bg-slate-800 text-white text-[8px] px-2 py-1 rounded opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none uppercase font-bold">
                                {{ item.name }}
                            </span>
                        </button>
                    </div>
                </section>

                <section class="space-y-4">
                    <header class="flex items-center gap-3">
                        <div class="w-1.5 h-5 bg-blue-500 rounded-full"></div>
                        <h4 class="text-[10px] font-black uppercase tracking-widest text-slate-400">Vista Previa</h4>
                    </header>
                    
                    <div class="relative h-48 bg-slate-100 dark:bg-slate-950 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 flex items-center justify-center overflow-hidden">
                        <div class="absolute inset-0 opacity-10" 
                             style="background-image: radial-gradient(#000 1px, transparent 1px); background-size: 15px 15px;"></div>
                        
                        <div 
                            class="px-6 py-4 rounded-[2rem] flex items-center gap-4 text-white transition-all duration-500"
                            :style="previewStyle"
                        >
                            <div class="w-12 h-12 bg-white/20 backdrop-blur-md rounded-2xl flex items-center justify-center">
                                <span class="material-symbols-rounded text-3xl">{{ form.imagen_plano_url }}</span>
                            </div>
                            <div class="space-y-0.5">
                                <p class="text-[10px] font-black uppercase tracking-widest opacity-70">Zona</p>
                                <p class="text-xl font-black leading-none truncate max-w-[140px]">
                                    {{ form.nombre || 'Nombre...' }}
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
            </aside>
</div>
</BaseModalSteps>
</template>

<style scoped>
/* Animaciones de escala para botones de color */
button:active {
    transform: scale(0.9) !important;
}

/* Scrollbar para listas largas si fuera necesario */
::-webkit-scrollbar {
    width: 4px;
}
::-webkit-scrollbar-thumb {
    background: #e2e8f0;
    border-radius: 10px;
}
</style>