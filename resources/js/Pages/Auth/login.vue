<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import { Loader2, Mail, Lock, ChevronRight } from 'lucide-vue-next'; // Asumiendo Lucide

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Acceso Corporativo" />

    <div class="min-h-screen bg-slate-50 flex items-center justify-center p-6 selection:bg-indigo-500 selection:text-white">
        <div class="max-w-md w-full">
            
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-indigo-600 rounded-2xl shadow-lg shadow-indigo-200 mb-4">
                    <Lock class="text-white w-8 h-8" />
                </div>
                <h2 class="text-3xl font-bold text-slate-900 tracking-tight">Bienvenido de nuevo</h2>
                <p class="text-slate-500 mt-2">Gestión de Eventos & Consultoría</p>
            </div>

            <div class="bg-white p-8 rounded-3xl shadow-xl shadow-slate-200/60 border border-slate-100">
                <form @submit.prevent="submit" class="space-y-6">
                    
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">Correo Corporativo</label>
                        <div class="relative group">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 group-focus-within:text-indigo-600 transition-colors">
                                <Mail class="w-5 h-5" />
                            </span>
                            <input 
                                v-model="form.email"
                                type="email"
                                placeholder="usuario@fycconsultores.com"
                                class="block w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 text-slate-900 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all outline-none"
                                :class="{ 'border-red-500 bg-red-50': form.errors.email }"
                            />
                        </div>
                        <p v-if="form.errors.email" class="mt-2 text-xs text-red-600 font-medium">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <div class="flex justify-between mb-2">
                            <label class="text-sm font-semibold text-slate-700">Contraseña</label>
                            <a href="#" class="text-xs font-medium text-indigo-600 hover:text-indigo-500">¿Olvidaste tu clave?</a>
                        </div>
                        <div class="relative group">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 group-focus-within:text-indigo-600 transition-colors">
                                <Lock class="w-5 h-5" />
                            </span>
                            <input 
                                v-model="form.password"
                                type="password"
                                placeholder="••••••••"
                                class="block w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 text-slate-900 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all outline-none"
                                :class="{ 'border-red-500 bg-red-50': form.errors.password }"
                            />
                        </div>
                        <p v-if="form.errors.password" class="mt-2 text-xs text-red-600 font-medium">{{ form.errors.password }}</p>
                    </div>

                    <div class="flex items-center">
                        <input 
                            type="checkbox" 
                            id="remember" 
                            v-model="form.remember"
                            class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500"
                        />
                        <label for="remember" class="ml-2 block text-sm text-slate-600 cursor-pointer">Mantener sesión iniciada</label>
                    </div>

                    <button 
                        type="submit" 
                        :disabled="form.processing"
                        class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 px-4 rounded-xl shadow-lg shadow-indigo-100 transition-all flex items-center justify-center group disabled:opacity-70"
                    >
                        <template v-if="form.processing">
                            <Loader2 class="animate-spin mr-2 w-5 h-5" />
                            Autenticando...
                        </template>
                        <template v-else>
                            Ingresar al Sistema
                            <ChevronRight class="ml-2 w-5 h-5 group-hover:translate-x-1 transition-transform" />
                        </template>
                    </button>
                </form>
            </div>

            <p class="text-center mt-8 text-sm text-slate-500">
                &copy; 2026 F&C Consultores S.A.S.<br>
            </p>
        </div>
    </div>
</template>