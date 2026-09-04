<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

const formulario = useForm({
    email: '',
    password: '',
    lembrar: true,
});

function enviar() {
    formulario.post('/entrar', {
        onFinish: () => formulario.reset('password'),
    });
}
</script>

<template>
    <Head title="Entrar" />

    <div class="flex min-h-screen flex-col justify-center bg-fundo px-5 py-10">
        <div class="mx-auto w-full max-w-sm">
            <div class="mb-8 text-center">
                <img src="/icons/icon.svg" alt="" class="mx-auto h-16 w-16 rounded-2xl">
                <h1 class="mt-4 text-2xl font-bold text-slate-900">Mithrandir</h1>
                <p class="mt-1 text-sm leading-relaxed text-slate-500">
                    O que vence, o que tenho que fazer, e quem me deve.
                </p>
            </div>

            <form class="cartao space-y-4 p-6" @submit.prevent="enviar">
                <div>
                    <label class="rotulo" for="email">E-mail</label>
                    <input
                        id="email"
                        v-model="formulario.email"
                        type="email"
                        autocomplete="username"
                        class="campo"
                        required
                    >
                    <p v-if="formulario.errors.email" class="mt-1 text-sm text-red-600">{{ formulario.errors.email }}</p>
                </div>

                <div>
                    <label class="rotulo" for="senha">Senha</label>
                    <input
                        id="senha"
                        v-model="formulario.password"
                        type="password"
                        autocomplete="current-password"
                        class="campo"
                        required
                    >
                </div>

                <label class="flex items-center gap-2.5 text-sm text-slate-700">
                    <input v-model="formulario.lembrar" type="checkbox" class="h-5 w-5 rounded border-slate-300">
                    Continuar conectada neste aparelho
                </label>

                <button type="submit" class="btn-primario w-full" :disabled="formulario.processing">
                    {{ formulario.processing ? 'Entrando…' : 'Entrar' }}
                </button>
            </form>

            <p class="mt-6 text-center text-sm text-slate-600">
                Ainda não tem conta?
                <Link href="/cadastrar" class="font-semibold text-sky-700">Criar agora</Link>
            </p>
        </div>
    </div>
</template>
