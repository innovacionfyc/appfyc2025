<script setup>
import { computed } from "vue";
import { Head } from "@inertiajs/vue3";
import GuestLayout from "@/Layouts/GuestLayout.vue";
import { Calendar, Clock, ExternalLink, VideoOff, ShieldOff } from "lucide-vue-next";

const props = defineProps({
  acceso: { type: Object, required: true },
});

const estaActivo = computed(() => props.acceso.estado_id === 1);

const formatFecha = () => {
  if (!props.acceso.fecha) return null;
  const dateStr = String(props.acceso.fecha).substring(0, 10);
  const d = new Date(dateStr + "T00:00:00");
  if (isNaN(d.getTime())) return null;
  return d.toLocaleDateString("es-CO", {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
  });
};

const fechaFormateada = computed(formatFecha);

const entrar = () => {
  if (props.acceso.url_zoom) {
    window.open(props.acceso.url_zoom, "_blank", "noopener,noreferrer");
  }
};
</script>

<template>
  <Head :title="acceso.nombre ?? 'Acceso Virtual — F&C Consultores'" />

  <GuestLayout>
    <div class="min-h-screen bg-slate-50 flex flex-col">

      <!-- ── Banner superior ──────────────────────────────────────────────── -->
      <div class="relative h-64 md:h-80 bg-slate-900 overflow-hidden">
        <!-- Imagen de banner -->
        <img
          v-if="acceso.imagen_banner_url"
          :src="acceso.imagen_banner_url"
          :alt="acceso.nombre"
          class="w-full h-full object-cover opacity-70"
          @error="(e) => e.target.style.display = 'none'"
        />

        <!-- Fallback: gradiente vinotinto -->
        <div
          class="absolute inset-0 bg-gradient-to-br from-primary-vinotinto via-secondary-vinotinto2 to-slate-900"
          :class="acceso.imagen_banner_url ? 'opacity-60' : 'opacity-100'"
        />

        <!-- Patrón decorativo sobre el banner -->
        <div class="absolute inset-0 opacity-10"
          style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 32px 32px;"
        />

        <!-- Logo y nombre de empresa -->
        <div class="absolute top-0 left-0 right-0 flex items-center justify-between px-6 py-5">
          <div class="flex items-center gap-3">
            <img
              src="/images/logo-fyc.png"
              alt="F&C Consultores"
              class="h-8 object-contain drop-shadow-lg brightness-0 invert"
              @error="(e) => e.target.style.display = 'none'"
            />
          </div>
          <span
            class="px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-widest border backdrop-blur-md"
            :class="estaActivo
              ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30'
              : 'bg-red-500/20 text-red-300 border-red-500/30'"
          >
            {{ estaActivo ? "Activo" : "No disponible" }}
          </span>
        </div>

        <!-- Título del evento sobre el banner -->
        <div class="absolute bottom-0 left-0 right-0 px-6 pb-8 pt-16 bg-gradient-to-t from-slate-900/90 to-transparent">
          <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">
            Acceso virtual · F&C Consultores
          </p>
          <h1 class="text-2xl md:text-3xl font-black text-white leading-tight drop-shadow-lg">
            {{ acceso.nombre }}
          </h1>
        </div>
      </div>

      <!-- ── Contenido principal ──────────────────────────────────────────── -->
      <div class="flex-grow flex items-start justify-center px-4 py-10">
        <div class="w-full max-w-lg space-y-5">

          <!-- Card principal -->
          <div class="bg-white rounded-[2.5rem] shadow-xl border border-slate-100 overflow-hidden">

            <!-- Acceso ACTIVO ──────────────────────────────── -->
            <template v-if="estaActivo">
              <!-- Descripción -->
              <div v-if="acceso.descripcion" class="px-8 pt-7 pb-0">
                <p class="text-sm text-slate-500 leading-relaxed">
                  {{ acceso.descripcion }}
                </p>
              </div>

              <!-- Fecha y hora -->
              <div class="px-8 py-6 space-y-3">
                <div
                  v-if="fechaFormateada || acceso.hora"
                  class="flex flex-col sm:flex-row gap-3"
                >
                  <div
                    v-if="fechaFormateada"
                    class="flex items-center gap-3 px-4 py-3 bg-slate-50 rounded-2xl flex-1"
                  >
                    <div class="w-9 h-9 bg-primary-vinotinto/10 rounded-xl flex items-center justify-center shrink-0">
                      <Calendar class="w-4 h-4 text-primary-vinotinto" />
                    </div>
                    <div>
                      <p class="text-[9px] font-black uppercase tracking-wider text-slate-400 mb-0.5">Fecha</p>
                      <p class="text-sm font-bold text-slate-800 capitalize leading-tight">
                        {{ fechaFormateada }}
                      </p>
                    </div>
                  </div>

                  <div
                    v-if="acceso.hora"
                    class="flex items-center gap-3 px-4 py-3 bg-slate-50 rounded-2xl sm:w-36 shrink-0"
                  >
                    <div class="w-9 h-9 bg-primary-vinotinto/10 rounded-xl flex items-center justify-center shrink-0">
                      <Clock class="w-4 h-4 text-primary-vinotinto" />
                    </div>
                    <div>
                      <p class="text-[9px] font-black uppercase tracking-wider text-slate-400 mb-0.5">Hora</p>
                      <p class="text-sm font-bold text-slate-800">
                        {{ String(acceso.hora).substring(0, 5) }}
                      </p>
                    </div>
                  </div>
                </div>

                <!-- Sin fecha ni hora -->
                <div
                  v-else
                  class="flex items-center gap-3 px-4 py-3 bg-slate-50 rounded-2xl"
                >
                  <Calendar class="w-4 h-4 text-slate-400" />
                  <span class="text-sm font-semibold text-slate-400">Sin fecha ni hora definidas</span>
                </div>
              </div>

              <!-- Divisor -->
              <div class="mx-8 border-t border-slate-100" />

              <!-- Botón Entrar -->
              <div class="px-8 py-6">
                <button
                  @click="entrar"
                  class="w-full flex items-center justify-center gap-3 py-4 bg-gradient-to-br from-primary-vinotinto to-secondary-vinotinto2 text-white font-black text-base rounded-2xl shadow-lg shadow-red-900/20 hover:shadow-xl hover:shadow-red-900/30 hover:-translate-y-0.5 active:scale-[0.98] transition-all duration-300"
                >
                  <ExternalLink class="w-5 h-5" />
                  Entrar a la reunión
                </button>
                <p class="text-center text-[11px] font-medium text-slate-400 mt-3">
                  Al hacer clic serás redirigido a la plataforma virtual
                </p>
              </div>
            </template>

            <!-- Acceso INACTIVO ────────────────────────────── -->
            <template v-else>
              <div class="px-8 py-10 text-center space-y-5">
                <div class="w-16 h-16 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto">
                  <ShieldOff class="w-8 h-8 text-slate-400" />
                </div>
                <div>
                  <h2 class="text-lg font-black text-slate-800 mb-2">Acceso no disponible</h2>
                  <p class="text-sm text-slate-500 leading-relaxed max-w-xs mx-auto">
                    Este acceso virtual no está activo actualmente. Si crees que es un error, contacta al organizador del evento.
                  </p>
                </div>
                <div class="p-4 bg-amber-50 rounded-2xl border border-amber-100 text-left">
                  <p class="text-[12px] font-semibold text-amber-700">
                    Estado: {{ acceso.estado?.tipo_estado ?? "Inactivo" }}
                  </p>
                </div>
              </div>
            </template>
          </div>

          <!-- Footer de marca -->
          <div class="text-center pb-4">
            <p class="text-[11px] font-bold text-slate-400">
              Powered by <span class="text-primary-vinotinto font-black">F&C Consultores</span>
            </p>
          </div>
        </div>
      </div>
    </div>
  </GuestLayout>
</template>
