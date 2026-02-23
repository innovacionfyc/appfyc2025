<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import { Loader2, Mail, Lock, ChevronRight, Building2 } from 'lucide-vue-next'; 

const form = useForm({
    correo_principal: '',
    contrasena: '',
    recordar: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('contrasena'),
    });
};
</script>

<template>
    <Head title="Acceso Corporativo | F&C Consultores" />

    <div class="min-h-screen flex bg-white selection:bg-indigo-500 selection:text-white">
        
        <div class="hidden lg:flex lg:w-1/2 relative bg-slate-900 overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 opacity-90"></div>
            <div class="absolute -bottom-32 -left-32 w-96 h-96 rounded-full bg-indigo-600/20 blur-3xl"></div>
            <div class="absolute top-32 -right-32 w-96 h-96 rounded-full bg-blue-600/20 blur-3xl"></div>

            <div class="relative z-10 flex flex-col justify-between p-16 w-full h-full text-white">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 bg-indigo-600 rounded-xl flex items-center justify-center shadow-lg shadow-indigo-900/50">
                        <Building2 class="w-6 h-6 text-white" />
                    </div>
                    <div>
                        <span class="text-2xl font-bold tracking-tight block leading-none">F&C</span>
                        <span class="text-sm text-indigo-300 font-medium tracking-widest uppercase">Consultores</span>
                    </div>
                </div>
                
                <div>
                    <h2 class="text-4xl font-bold leading-tight mb-6">
                        Plataforma Integral de<br/>
                        <span class="text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-blue-400">
                            Gestión de Eventos
                        </span>
                    </h2>
                    <p class="text-slate-400 text-lg max-w-md leading-relaxed">
                        Acceda a su entorno corporativo para la administración segura de conferencias, formularios de inscripción y control académico.
                    </p>
                </div>

                <div class="text-sm text-slate-500 font-medium">
                    &copy; 2026 F&C Consultores S.A.S. Todos los derechos reservados.
                </div>
            </div>
        </div>

        <div class="w-full lg:w-1/2 flex items-center justify-center p-8 sm:p-12 lg:p-24 relative">
            
            <div class="absolute top-8 left-8 lg:hidden flex items-center gap-2">
                <div class="w-10 h-10 bg-indigo-600 rounded-lg flex items-center justify-center">
                    <Building2 class="w-5 h-5 text-white" />
                </div>
                <div>
                    <span class="font-bold text-slate-900 leading-none block">F&C</span>
                    <span class="text-xs text-indigo-600 font-bold uppercase tracking-wider block">Consultores</span>
                </div>
            </div>

            <div class="w-full max-w-md space-y-10">
                <div>
                    <h1 class="text-3xl font-bold text-slate-900 tracking-tight">Acceso al Sistema</h1>
                    <p class="text-slate-500 mt-2 text-sm">Ingrese sus credenciales corporativas para continuar.</p>
                </div>

                <form @submit.prevent="submit" class="space-y-6">
                    
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">Correo Corporativo</label>
                        <div class="relative group">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400 group-focus-within:text-indigo-600 transition-colors">
                                <Mail class="w-5 h-5" />
                            </span>
                            <input 
                                v-model="form.correo_principal"
                                type="email"
                                placeholder="usuario@fycconsultores.com"
                                class="block w-full pl-11 pr-4 py-3 bg-white border border-slate-300 text-slate-900 rounded-xl focus:ring-2 focus:ring-indigo-600 focus:border-transparent transition-all outline-none placeholder:text-slate-400 shadow-sm"
                                :class="{ 'border-red-500 focus:ring-red-500': form.errors.correo_principal }"
                            />
                        </div>
                        <p v-if="form.errors.correo_principal" class="mt-2 text-xs text-red-600 font-medium flex items-center gap-1">
                            {{ form.errors.correo_principal }}
                        </p>
                    </div>

                    <div>
                        <div class="flex justify-between mb-2 items-center">
                            <label class="text-sm font-semibold text-slate-700">Contraseña</label>
                            <a href="#" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800 transition-colors">¿Olvidó su contraseña?</a>
                        </div>
                        <div class="relative group">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400 group-focus-within:text-indigo-600 transition-colors">
                                <Lock class="w-5 h-5" />
                            </span>
                            <input 
                                v-model="form.contrasena"
                                type="password"
                                placeholder="••••••••"
                                class="block w-full pl-11 pr-4 py-3 bg-white border border-slate-300 text-slate-900 rounded-xl focus:ring-2 focus:ring-indigo-600 focus:border-transparent transition-all outline-none placeholder:text-slate-400 shadow-sm"
                                :class="{ 'border-red-500 focus:ring-red-500': form.errors.contrasena }"
                            />
                        </div>
                        <p v-if="form.errors.contrasena" class="mt-2 text-xs text-red-600 font-medium">
                            {{ form.errors.contrasena }}
                        </p>
                    </div>

                    <div class="flex items-center">
                        <input 
                            type="checkbox" 
                            id="recordar" 
                            v-model="form.recordar"
                            class="w-4.5 h-4.5 text-indigo-600 border-slate-300 rounded focus:ring-indigo-600 transition-colors cursor-pointer"
                        />
                        <label for="recordar" class="ml-2.5 block text-sm font-medium text-slate-600 cursor-pointer select-none">
                            Mantener sesión iniciada
                        </label>
                    </div>

                    <button 
                        type="submit" 
                        :disabled="form.processing"
                        class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3.5 px-4 rounded-xl shadow-md hover:shadow-lg hover:shadow-indigo-200 transition-all flex items-center justify-center group disabled:opacity-70 disabled:cursor-not-allowed"
                    >
                        <template v-if="form.processing">
                            <Loader2 class="animate-spin mr-2 w-5 h-5" />
                            Autenticando al usuario...
                        </template>
                        <template v-else>
                            Ingresar al Panel
                            <ChevronRight class="ml-2 w-5 h-5 group-hover:translate-x-1 transition-transform" />
                        </template>
                    </button>
                </form>
            </div>
            
            <div class="absolute bottom-8 text-center w-full lg:hidden text-xs text-slate-400 font-medium">
                &copy; 2026 F&C Consultores
            </div>
        </div>
    </div>
</template>