<script setup>
import { Link } from "@inertiajs/vue3";
import { ref, onMounted, onBeforeUnmount } from "vue";
import BtnUniversal from "./BtnUniversal.vue";

const props = defineProps({
  delay: { type: Number, default: 0 },
  repeat: { type: Boolean, default: false },
  class: { type: String, default: "" },
});

const target = ref(null);
const isVisible = ref(false);
let observer = null;

onMounted(() => {
  observer = new IntersectionObserver(
    ([entry]) => {
   
      if (entry.isIntersecting) {
        isVisible.value = true;
     
        if (!props.repeat) observer.disconnect();
      } else {
 
        if (props.repeat) isVisible.value = false;
      }
    },
    { threshold: 0.15, rootMargin: "0px" } 
  );

  if (target.value) observer.observe(target.value);
});

onBeforeUnmount(() => {
  if (observer) observer.disconnect();
});
</script>

<template>
  <section
    class="relative w-full min-h-[100dvh] overflow-hidden bg-[#FAFAFA] grid"
  >

    <div
      class="absolute top-[40%] right-[30%] lg:right-[25%] w-[350px] h-[350px] bg-[#F4D6C1] rounded-[40px] transform -translate-y-1/2 rotate-45 pointer-events-none z-0 hidden lg:block"
    ></div>
     <div
      class="absolute top-[40%] right-[-10%] lg:right-[-10%] w-[750px] h-[750px] bg-[#DE6B27] rounded-[80px] transform -translate-y-1/2 rotate-45 pointer-events-none z-0 hidden lg:block"
    ></div>

    <div
      class="w-full p-44 relative z-10 flex flex-col-reverse lg:grid lg:grid-cols-[1.1fr_0.9fr] gap-12 lg:gap-8 items-center"
    >
      <div class="w-full">
        <div class="flex items-center gap-2 text-md font-semibold text-gray-800 mb-8">
         
          <p class="material-symbols-rounded text-[18px] text-primary-naranja" >home</p>
           
          <Link href="/contacto" class="group transition-transform active:scale-95 outline-none hover:text-primary-naranja">
          <span>Inicio</span>
          </Link>

          <p class="material-symbols-rounded text-[18px] text-primary-naranja" >chevron_right</p>
         
          <span>Aspectos prácticos del proceso...</span>
        </div>

        <h2 class="text-[36px] font-medium text-[#1A1A1A] mb-2 tracking-tight">
          Seminario de actualización
        </h2>
        <h1
          class="text-4xl sm:text-5xl lg:text-[80px] font-extrabold text-[#DE6B27] leading-[1.1] mb-10 tracking-tight"
        >
          Aspectos prácticos del proceso disciplinario
        </h1>

        <div
          class="flex justify-between text-[#1A1A1A] font-bold text-[18px] mb-1"
        >
          <div class="flex items-center gap-3">
            <svg
              class="w-5 h-5 text-[#DE6B27]"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
              stroke-width="2"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
              ></path>
            </svg>
            <span>19 y 20 de febrero de 2026</span>
          </div>
          <div class="flex items-center gap-3">
            <svg
              class="w-5 h-5 text-[#DE6B27]"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
              stroke-width="2"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
              ></path>
            </svg>
            <span>8:00 a.m. a 12:30 p.m.</span>
          </div>
         
        </div>
          <div
          class="flex justify-between gap-y-5 gap-x-6 text-[#1A1A1A] font-bold text-[18px] mb-10"
        >
         
         
          <div class="flex items-center gap-3 sm:col-span-2 mt-1">
            <svg
              class="w-5 h-5 text-[#DE6B27]"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
              stroke-width="2"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
              ></path>
            </svg>
            <span>Vía Streaming - Plataforma Zoom</span>
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-6">
          
          <Link href="/contacto" class="group transition-transform active:scale-95 outline-none">
          <btn-universal 
            label=" Formulario de inscripción" 
            icon="chevron_right" 
            icon-position="right" 
            size="md" 
            class="shadow-lg shadow-primary-naranja/20 group-hover:shadow-primary-naranja/40 transition-shadow duration-300"
          />
        </Link>

         <a target="_blank" 
          href="https://fycconsultores.com/inicio/wp-content/uploads/2026/01/Folleto-Jor-Disciplinario.pdf" 
          class="text-md font-bold text-gray-700 hover:text-primary-naranja transition-colors duration-300 outline-none focus-visible:text-primary-naranja relative group"
        >
          Descargar folleto
          <span class="absolute -bottom-1 left-0 w-0 h-[2px] rounded-full bg-primary-naranja transition-all duration-300 group-hover:w-full"></span>
        </a>
        </div>
      </div>

      <div class="w-full flex justify-end relative">
        <div class="relative w-full max-w-[450px]">
          <div
            class="rounded-[50px] overflow-hidden border-4 border-white shadow-2xl bg-white aspect-[4/4.5]"
          >
            <img
              src="https://images.unsplash.com/photo-1589829545856-d10d557cf95f?w=500&auto=format&fit=crop&q=60&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxzZWFyY2h8Mnx8YWJvZ2FkYXxlbnwwfHwwfHx8MA%3D%3D0"
              
              alt="Abogada en seminario"
              class="w-full h-full object-cover object-top"
            />
          </div>

          <div
            class="absolute -bottom-8 left-1/2 -translate-x-1/2 bg-white rounded-[20px] pt-2 pb-3 px-2 shadow-[0_10px_25px_rgba(0,0,0,0.1)] flex flex-col items-center justify-center w-[90px]"
          >
           <img src="https://fycconsultores.com/inicio/wp-content/uploads/2026/02/Recurso-43.png" alt="">
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
<style scoped>
/* Esto agrega profundidad 3D. 
  Hace que el elemento parezca venir "desde el fondo" de la pantalla.
*/
.perspective-container {
  perspective: 1200px;
  perspective-origin: center center;
}

/* Clase utilitaria para rotación en X (si no usas Tailwind plugin de 3d).
  Esto inclina la sección hacia atrás cuando está oculta.
*/
.rotate-x-6 {
  transform: rotateX(6deg);
}
.rotate-x-0 {
  transform: rotateX(0deg);
}
</style>
