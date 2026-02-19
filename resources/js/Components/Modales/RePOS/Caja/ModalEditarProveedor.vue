<script setup>
import { defineProps, defineEmits, ref, watch, computed } from "vue";
import { useForm } from "@inertiajs/vue3";
import { handleInput, limitesCaracteres } from "@/Utils/formateoInputs";
import InputTexto from "@/Components/Shared/inputs/InputTexto.vue";
import Modal from "@/Components/Shared/Modales/BaseModalSteps.vue";

const props = defineProps({
  isOpen: { type: Boolean, required: true },
  proveedor: { type: Object, default: null },
  aplicacion: String,
  rol: String,
});
const emit = defineEmits(["close"]);

// --- FORMULARIO ---
const form = useForm({
  nombre: "",
  nit: "",
  dv: "",
  regimen: "",
  contacto_nombre: "",
  telefono: "",
  email: "",
  direccion: "",
  estado_id: 1,
  indicativo_id: 1,
  tipo_documento_id: 1,
});

// --- WATCHER ---
watch(
  () => props.proveedor,
  (newProveedor) => {
    if (newProveedor) {
      form.nombre = newProveedor.nombre ?? "";
      form.nit = newProveedor.nit ?? "";
      form.dv = newProveedor.dv ?? "";
      form.regimen = newProveedor.regimen ?? "";
      form.contacto_nombre = newProveedor.contacto_nombre ?? "";
      form.telefono = newProveedor.telefono ?? "";
      form.email = newProveedor.email ?? "";
      form.direccion = newProveedor.direccion ?? "";
      form.clearErrors();
    }
  },
  { deep: true, immediate: true }
);

// Computed para iniciales (Estética)
const inicialesEmpresa = computed(() => {
  const name = form.nombre || props.proveedor?.nombre || "";
  return name.substring(0, 2).toUpperCase();
});

function submit() {
  if (!props.proveedor) return;

  form.put(
    route("repos.proveedores.update", {
      aplicacion: props.aplicacion,
      rol: props.rol,
      id: props.proveedor.id,
    }),
    {
      preserveScroll: true,
       onSuccess: () => {
        activeTab.value = 0;
        form.reset();
      },
      onError: (errors) => {
        console.error("Error:", errors);
      },
    }
  );
}

function closeModal(force = false) {
  if (form.isDirty && !force) {
    if (confirm("¿Descartar cambios en el proveedor?")) {
      emit("close");
      setTimeout(() => form.reset(), 300);




    }
  } else {
    emit("close");
    setTimeout(() => form.reset(), 300);
  }
}
</script>

<template>
  <Modal
    :isOpen="isOpen"
    :currentStep="1"
    :totalSteps="1"
    :isSubmitting="form.processing"
    title="Gestión de Proveedor"
    description="Actualiza la información de contacto y facturación."
    finalButtonText="Actualizar Datos"
    @close="closeModal"
    @submit="submit"
  >
    <div class="py-4 min-h-[550px]">
      <Transition name="fade-slide" mode="out-in">
        <div v-if="activeTab === 0" key="directory" class="space-y-4">
          <div
            class="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-[500px] overflow-y-auto pr-2 scrollbar-custom"
          >
            <div
              v-for="prov in proveedores.data"
              :key="prov.id"
              class="group bg-slate-50/50 dark:bg-slate-900/40 border border-slate-200/60 dark:border-slate-800 rounded-2xl p-4 transition-all hover:shadow-md hover:border-emerald-500/30"
            >
              <div class="flex items-start justify-between">
                <div class="flex items-center gap-3">
                  <div
                    class="w-10 h-10 rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 flex items-center justify-center font-bold text-slate-600 dark:text-slate-300 shadow-sm"
                  >
                    {{ prov.nombre.substring(0, 2).toUpperCase() }}
                  </div>
                  <div class="min-w-0">
                    <h4 class="font-bold text-slate-900 dark:text-white truncate w-40">
                      {{ prov.nombre }}
                    </h4>
                    <p class="text-[13px] text-slate-500 font-medium">
                      NIT: {{ prov.nit }}
                    </p>
                  </div>
                </div>
                <div class="flex gap-1">
                  <button
                    @click="emit('edit', prov)"
                    class="p-1.5 rounded-md hover:bg-blue-50 dark:hover:bg-blue-900/30 text-slate-400 hover:text-blue-500 transition-colors"
                  >
                    <span class="material-symbols-rounded text-xl">edit_square</span>
                  </button>
                  <button
                    @click="deleteProveedor(prov)"
                    class="p-1.5 rounded-md hover:bg-red-50 dark:hover:bg-red-900/30 text-slate-400 hover:text-red-500 transition-colors"
                  >
                    <span class="material-symbols-rounded text-xl">delete</span>
                  </button>
                </div>
              </div>
              <div
                class="mt-4 grid grid-cols-2 gap-2 border-t border-slate-200 dark:border-slate-800 pt-3"
              >
                <div
                  class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-400"
                >
                  <span class="material-symbols-rounded text-base text-emerald-500"
                    >call</span
                  >
                  {{ prov.telefono || "N/A" }}
                </div>
                <div
                  class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-400 truncate"
                >
                  <span class="material-symbols-rounded text-base text-blue-500"
                    >mail</span
                  >
                  <span class="truncate">{{ prov.email || "N/A" }}</span>
                </div>
              </div>
            </div>
          </div>
          <div
            class="pt-4 border-t border-slate-100 dark:border-slate-800 flex justify-center"
            @click.prevent="handlePaginationClick"
          >
            <Pagination
              :links="proveedores.links"
              :from="proveedores.from"
              :to="proveedores.to"
              :total="proveedores.total"
            />
          </div>
        </div>

        <div v-else key="create" class="flex flex-col lg:flex-row gap-12 h-full">
          <div class="flex-1 space-y-2">
            <div class="space-y-6">
              <div class="flex items-center gap-4">
                <div class="h-px flex-grow bg-slate-100 dark:bg-slate-800"></div>

                <h5 class="text-[13px] font-medium text-emerald-500">Identidad Fiscal</h5>

                <div class="h-px w-10 bg-slate-100 dark:bg-slate-800"></div>
              </div>

              <div class="grid grid-cols-1 gap-6">
                <InputTexto
                  v-model="form.nombre"
                  label="Razón Social / Nombre Comercial"
                  icon="business"
                  placeholder="Ej: Insumos Globales SAS"
                  :error="form.errors.nombre"
                />

                <div class="grid grid-cols-12 gap-5">
                  <div class="col-span-12 sm:col-span-9">
                    <InputTexto
                      v-model="form.nit"
                      label="NIT / RUT"
                      icon="fingerprint"
                      placeholder="900123456"
                      :error="form.errors.nit"
                    />
                  </div>

                  <div class="col-span-12 sm:col-span-3">
                    <InputTexto
                      v-model="form.dv"
                      label="DV"
                      placeholder="0"
                      :maxLength="1"
                      :error="form.errors.dv"
                      class="text-center"
                    />
                  </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                  <div class="space-y-1.5">
                    <label
                      class="text-[13px] font-medium text-slate-400 ml-2 tracking-widest"
                      >Régimen Tributario</label
                    >

                    <div class="relative group">
                      <span
                        class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-xl group-focus-within:text-emerald-500 transition-colors"
                      >
                        gavel
                      </span>

                      <select
                        v-model="form.regimen"
                        class="w-full bg-slate-50 dark:bg-slate-900/50 border-2 border-transparent focus:border-emerald-500/20 text-slate-700 dark:text-slate-200 rounded-2xl text-sm font-bold pl-12 p-4 appearance-none focus:ring-4 focus:ring-emerald-500/5 transition-all outline-none"
                      >
                        <option value="Simplificado">
                          Simplificado (No Responsable IVA)
                        </option>

                        <option value="Común">Común (Responsable IVA)</option>

                        <option value="Gran Contribuyente">Gran Contribuyente</option>
                      </select>

                      <span
                        class="material-symbols-rounded absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"
                      >
                        expand_more
                      </span>
                    </div>
                  </div>

                  <InputTexto
                    v-model="form.direccion"
                    label="Ubicación Principal"
                    icon="map"
                    placeholder="Av. Principal #12-34"
                    :error="form.errors.direccion"
                  />
                </div>
              </div>
            </div>


            <div class="space-y-6">
              <div class="flex items-center gap-4">
                <div class="h-px flex-grow bg-slate-100 dark:bg-slate-800"></div>

                <h5 class="text-[13px] font-medium text-sky-500">Canales de Contacto</h5>

                <div class="h-px w-10 bg-slate-100 dark:bg-slate-800"></div>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <InputTexto
                  v-model="form.contacto_nombre"
                  label="Representante / Asesor"
                  icon="person"
                  placeholder="Nombre completo"
                />

                <InputTexto
                  v-model="form.telefono"
                  label="Teléfono Móvil"
                  icon="call"
                  type="number"
                  placeholder="300 000 0000"
                  :maxLength="limitesCaracteres.telefono"
                  @input="(e) => handleInput(e, form, 'telefono')"
                />
              </div>

              <InputTexto
                v-model="form.email"
                label="Correo Corporativo"
                icon="alternate_email"
                placeholder="pedidos@empresa.com"
                :error="form.errors.email"
              />
            </div>
          </div>

          <div class="w-full lg:w-[400px] flex flex-col items-center">
            <div class="sticky top-0 w-full flex flex-col items-center">
              <div class="flex items-center gap-3 mb-8">
                <div class="h-px w-8 bg-slate-200 dark:bg-slate-800"></div>

                <p class="text-[11px] font-medium text-slate-400 dark:text-slate-500">
                  Preview
                </p>

                <div class="h-px w-8 bg-slate-200 dark:bg-slate-800"></div>
              </div>

              <div
                class="w-full bg-white dark:bg-[#0d111a] rounded-[2.5rem] shadow-[0_40px_80px_-15px_rgba(0,0,0,0.1)] dark:shadow-[0_40px_80px_-15px_rgba(0,0,0,0.6)] border border-slate-100 dark:border-white/5 overflow-hidden group transition-all duration-700 hover:translate-y-[-6px] hover:shadow-[0_50px_100px_-20px_rgba(16,185,129,0.15)]"
              >
                <div
                  class="h-28 bg-gradient-to-tr from-emerald-600 via-emerald-500 to-teal-400 p-8 flex justify-end items-start relative overflow-hidden"
                >
                  <div
                    class="absolute inset-0 opacity-20 bg-[url('https://www.transparenttextures.com/patterns/carbon-fibre.png')]"
                  ></div>

                  <div
                    class="absolute -right-4 -top-4 w-32 h-32 bg-white/20 rounded-full blur-2xl animate-pulse"
                  ></div>

                  <div
                    class="relative z-10 bg-medium/20 backdrop-blur-md border border-white/20 px-4 py-1.5 rounded-2xl shadow-xl"
                  >
                    <span class="text-[13px] font-medium text-white tracking-widest">
                      ID: {{ form.nit || "---"
                      }}<span v-if="form.dv" class="text-white/60">-{{ form.dv }}</span>
                    </span>
                  </div>
                </div>













                <div class="p-10 pt-5 space-y-8">
                  <div class="space-y-2">
                    <h4
                      class="text-2xl font-medium text-slate-900 dark:text-white leading-tight tracking-tighter truncate transition-colors group-hover:text-emerald-500"
                    >
                      {{ form.nombre || "Nombre de la Empresa" }}
                    </h4>

                    <div class="flex items-center gap-3">
                      <div
                        class="flex h-5 items-center gap-1.5 bg-emerald-500/10 dark:bg-emerald-500/5 px-3 rounded-full border border-emerald-500/20"
                      >
                        <span
                          class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-ping"
                        ></span>

                        <p
                          class="text-[9px] font-medium text-emerald-600 dark:text-emerald-400 tracking-widest"
                        >
                          {{ form.regimen }}
                        </p>
                      </div>

                      <span
                        v-if="form.direccion"
                        class="text-[13px] font-bold text-slate-400 truncate"
                      >
                        • {{ form.direccion }}
                      </span>
                    </div>
                  </div>

                  <div
                    class="space-y-1 pt-6 border-t border-slate-50 dark:border-white/5"
                  >
                    <div
                      v-for="(item, index) in [
                        {
                          icon: 'person',

                          label: form.contacto_nombre || 'Representante',

                          color: 'text-sky-500',
                        },

                        {
                          icon: 'call',

                          label: form.telefono || 'Sin teléfono',

                          color: 'text-emerald-500',
                        },

                        {
                          icon: 'alternate_email',

                          label: form.email || 'Sin correo',

                          color: 'text-rose-500',
                        },
                      ]"
                      :key="index"
                      class="flex items-center gap-4 p-3 rounded-2xl transition-all duration-300 hover:bg-slate-50 dark:hover:bg-white/5 group/item"
                    >
                      <div
                        class="w-10 h-10 rounded-xl bg-slate-50 dark:bg-slate-800 flex items-center justify-center text-slate-400 group-hover/item:scale-110 transition-transform"
                        :class="`group-hover/item:${item.color}`"
                      >
                        <span class="material-symbols-rounded text-xl">{{
                          item.icon
                        }}</span>
                      </div>

                      <span
                        class="text-xs font-medium text-slate-600 dark:text-slate-400 tracking-tight truncate"
                      >
                        {{ item.label }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>












              <div class="mt-8 group cursor-help relative">
                <div
                  class="flex items-center gap-4 p-5 bg-emerald-500/5 dark:bg-emerald-500/10 rounded-[2rem] border border-emerald-500/10 transition-all hover:bg-emerald-500/10 dark:hover:bg-emerald-500/20"
                >
                  <div
                    class="w-10 h-10 rounded-full bg-emerald-500 flex items-center justify-center shadow-lg shadow-emerald-500/20"
                  >
                    <span
                      class="material-symbols-rounded text-white text-xl animate-pulse"
                      >verified</span
                    >
                  </div>

                  <div class="flex-1">
                    <p
                      class="text-[13px] text-emerald-700 dark:text-emerald-400 font-medium tracking-widest mb-0.5"
                    >
                      Certificación Fiscal
                    </p>

                    <p
                      class="text-[9px] text-emerald-600/60 dark:text-emerald-500/60 font-bold leading-none"
                    >
                      Datos sujetos a validación DIAN 2026.
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </div>
  </Modal>
</template>