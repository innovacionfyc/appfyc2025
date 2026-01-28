<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import { UserPlus, Mail, Lock, CheckCircle } from 'lucide-vue-next';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Crear Cuenta Corporativa" />
    <div class="min-h-screen bg-slate-50 flex items-center justify-center p-6">
        <div class="max-w-md w-full bg-white p-8 rounded-3xl shadow-xl border border-slate-100">
            <div class="text-center mb-6">
                <h2 class="text-2xl font-bold text-slate-900">Únete a la plataforma</h2>
                <p class="text-slate-500">Empieza a gestionar tus eventos hoy</p>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Nombre Completo</label>
                    <input v-model="form.name" type="text" class="input-style" :class="{'border-red-500': form.errors.name}" />
                    <p v-if="form.errors.name" class="error-msg">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Email Corporativo</label>
                    <input v-model="form.email" type="email" class="input-style" :class="{'border-red-500': form.errors.email}" />
                    <p v-if="form.errors.email" class="error-msg">{{ form.errors.email }}</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Contraseña</label>
                        <input v-model="form.password" type="password" class="input-style" />
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Confirmar</label>
                        <input v-model="form.password_confirmation" type="password" class="input-style" />
                    </div>
                </div>
                <p v-if="form.errors.password" class="error-msg">{{ form.errors.password }}</p>

                <button type="submit" :disabled="form.processing" class="btn-primary w-full">
                    {{ form.processing ? 'Registrando...' : 'Crear Cuenta' }}
                </button>
            </form>

            <div class="mt-6 text-center">
                <Link :href="route('login')" class="text-sm text-indigo-600 font-medium">¿Ya tienes cuenta? Inicia sesión</Link>
            </div>
        </div>
    </div>
</template>

<style scoped>
.input-style { @apply w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none transition-all; }
.btn-primary { @apply bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 rounded-xl shadow-lg transition-all disabled:opacity-50; }
.error-msg { @apply mt-1 text-xs text-red-600 font-medium; }
</style>