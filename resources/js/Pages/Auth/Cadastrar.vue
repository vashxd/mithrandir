<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    ufs: { type: Array, default: () => [] },
});

const formulario = useForm({
    name: '',
    email: '',
    oab: '',
    uf: 'AM',
    password: '',
    password_confirmation: '',
    aceite_termo: false,
});

function enviar() {
    formulario.post('/cadastrar', {
        onFinish: () => formulario.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Criar conta" />

    <div class="min-h-screen bg-fundo px-5 py-10">
        <div class="mx-auto w-full max-w-sm">
            <div class="mb-6 text-center">
                <img src="/icons/icon.svg" alt="" class="mx-auto h-14 w-14 rounded-2xl">
                <h1 class="mt-3 text-2xl font-bold text-slate-900">Criar conta</h1>
            </div>

            <form class="cartao space-y-4 p-6" @submit.prevent="enviar">
                <div>
                    <label class="rotulo" for="nome">Nome completo</label>
                    <input id="nome" v-model="formulario.name" type="text" autocomplete="name" class="campo" required>
                    <p v-if="formulario.errors.name" class="mt-1 text-sm text-red-600">{{ formulario.errors.name }}</p>
                    <p class="mt-1 text-xs text-slate-500">
                        Use exatamente como está na OAB — é assim que o seu nome sai nas publicações.
                    </p>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-2">
                        <label class="rotulo" for="oab">Número da OAB</label>
                        <input id="oab" v-model="formulario.oab" type="text" inputmode="numeric" class="campo" required>
                        <p v-if="formulario.errors.oab" class="mt-1 text-sm text-red-600">{{ formulario.errors.oab }}</p>
                    </div>
                    <div>
                        <label class="rotulo" for="uf">UF</label>
                        <select id="uf" v-model="formulario.uf" class="campo" required>
                            <option v-for="uf in ufs" :key="uf" :value="uf">{{ uf }}</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="rotulo" for="email">E-mail</label>
                    <input id="email" v-model="formulario.email" type="email" autocomplete="username" class="campo" required>
                    <p v-if="formulario.errors.email" class="mt-1 text-sm text-red-600">{{ formulario.errors.email }}</p>
                </div>

                <div>
                    <label class="rotulo" for="senha">Senha</label>
                    <input id="senha" v-model="formulario.password" type="password" autocomplete="new-password" class="campo" required>
                    <p v-if="formulario.errors.password" class="mt-1 text-sm text-red-600">{{ formulario.errors.password }}</p>
                </div>

                <div>
                    <label class="rotulo" for="confirmacao">Repetir a senha</label>
                    <input
                        id="confirmacao"
                        v-model="formulario.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        class="campo"
                        required
                    >
                </div>

                <!-- RF-9.2: o aceite carrega a cláusula de conferência obrigatória. -->
                <div class="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
                    <label class="flex items-start gap-2.5">
                        <input v-model="formulario.aceite_termo" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 rounded border-slate-300">
                        <span class="text-sm leading-relaxed text-slate-700">
                            Li e aceito o termo de uso. Entendo que <strong>o Mithrandir não substitui a
                            conferência do diário oficial</strong> e que a responsabilidade pelo controle
                            dos prazos continua sendo minha.
                        </span>
                    </label>
                    <p v-if="formulario.errors.aceite_termo" class="mt-2 text-sm text-red-600">
                        {{ formulario.errors.aceite_termo }}
                    </p>
                </div>

                <button type="submit" class="btn-primario w-full" :disabled="formulario.processing">
                    {{ formulario.processing ? 'Criando…' : 'Criar conta' }}
                </button>
            </form>

            <p class="mt-6 text-center text-sm text-slate-600">
                Já tem conta?
                <Link href="/entrar" class="font-semibold text-sky-700">Entrar</Link>
            </p>
        </div>
    </div>
</template>
