<script setup>
import { X, MapPin, Calendar, FileText, CheckCircle2, Eye } from "lucide-vue-next";
import { computed } from "vue";

const props = defineProps({
  show: Boolean,
  evento: Object
});

const activeColor = computed(() => props.evento?.area_formacion?.color_hex_principal || '#f97316');
</script>

<template>
  <div v-if="show" class="fixed inset-0 z-[150] flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-md">
    <div class="bg-white w-full max-w-5xl h-[85vh] rounded-[3rem] overflow-hidden flex flex-col shadow-2xl relative animate-in zoom-in duration-300">
      
      <div class="px-8 py-6 border-b border-slate-100 flex justify-between items-center bg-white z-10">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white shadow-lg" :style="{ background: activeColor }">
            <Eye class="w-6 h-6" />
          </div>
          <h2 class="text-xl font-black text-slate-900">Previsualización de Publicación</h2>
        </div>
        <button @click="$emit('close')" class="p-2 hover:bg-slate-100 rounded-full transition-all text-slate-400">
          <X class="w-6 h-6" />
        </button>
      </div>

      <div class="flex-1 overflow-y-auto bg-slate-50 p-8 custom-scroll">
        <div class="bg-white rounded-[2.5rem] shadow-xl overflow-hidden border border-slate-200">
          <div class="h-64 relative flex items-center justify-center text-center p-6 bg-[#0B192C]">
            <img :src="'/storage/' + evento.imagen_relacionada" class="absolute inset-0 w-full h-full object-cover opacity-40" />
            <div class="relative z-10">
              <span class="text-[10px] font-black uppercase tracking-[0.3em]" :style="{ color: activeColor }">{{ evento.area_formacion?.nombre }}</span>
              <h1 class="text-4xl font-black text-white mt-2">{{ evento.titulo }}</h1>
            </div>
          </div>

          <div class="p-10 grid grid-cols-1 lg:grid-cols-2 gap-10">
            <div class="space-y-6">
               <h3 class="text-sm font-black uppercase text-slate-400 tracking-widest flex items-center gap-2">
                 <FileText class="w-4 h-4" /> Temario del Evento
               </h3>
               <div v-for="(modulo, i) in evento.contenido_tematico?.modulos" :key="i" class="p-4 bg-slate-50 rounded-2xl">
                 <p class="text-xs font-black text-slate-700 uppercase">Módulo 0{{ i+1 }}: {{ modulo.tema }}</p>
               </div>
            </div>
            <div class="space-y-6">
               <div class="bg-slate-900 p-6 rounded-[2rem] text-white">
                  <p class="text-[10px] font-black text-orange-500 uppercase mb-4">Registro en línea habilitado</p>
                  <button class="w-full py-3 rounded-xl font-black uppercase text-[10px]" :style="{ background: activeColor }">
                    Inscribirme Ahora
                  </button>
               </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>