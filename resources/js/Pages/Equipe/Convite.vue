<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    convite: { type: Object, required: true },
    logado_como: { type: String, default: null },
});

const flash = computed(() => usePage().props.flash ?? {});

const emailConfere = computed(
    () => props.logado_como?.toLowerCase() === props.convite.email.toLowerCase()
);

function aceitar() {
    router.post(`/convite/${props.convite.token}`);
}

const DESCRICAO_PAPEL = {
    advogado: 'Você poderá cumprir prazos e ver o financeiro dos casos liberados.',
    estagiario:
        'Você poderá ver e adiantar o trabalho, mas não fecha prazo — o titular confere antes.',
};
</script>

<template>
    <Head title="Convite" />

    <div class="flex min-h-screen flex-col justify-center bg-slate-100 px-5 py-10">
        <div class="mx-auto w-full max-w-sm">
            <div class="mb-6 text-center">
                <img src="/icons/icon.svg" alt="" class="mx-auto h-14 w-14 rounded-2xl">
                <h1 class="mt-3 text-2xl font-bold text-slate-900">Convite para equipe</h1>
            </div>

            <div class="cartao space-y-4 p-6">
                <p class="text-sm leading-relaxed text-slate-700">
                    <strong>{{ convite.titular }}</strong>
                    <template v-if="convite.oab"> (OAB {{ convite.oab }})</template>
                    convidou você para trabalhar nos casos dele no Mithrandir.
                </p>

                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="text-xs font-medium text-slate-500">Seu papel</p>
                    <p class="font-semibold capitalize text-slate-900">{{ convite.papel_rotulo }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-slate-600">
                        {{ DESCRICAO_PAPEL[convite.papel] }}
                    </p>

                    <p class="mt-3 text-xs font-medium text-slate-500">Acesso</p>
                    <p class="text-sm text-slate-800">
                        <template v-if="convite.acesso_total">Todos os casos da carteira</template>
                        <template v-else-if="convite.qtd_processos">
                            {{ convite.qtd_processos }}
                            {{ convite.qtd_processos === 1 ? 'caso específico' : 'casos específicos' }}
                        </template>
                        <template v-else>Nenhum caso liberado ainda</template>
                    </p>
                </div>

                <p v-if="convite.ja_aceito" class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800 ring-1 ring-emerald-200">
                    Este convite já foi aceito.
                </p>

                <p v-if="flash.erro" class="rounded-xl bg-red-50 p-3 text-sm text-red-800 ring-1 ring-red-200">
                    {{ flash.erro }}
                </p>

                <template v-if="!logado_como">
                    <p class="rounded-xl bg-amber-50 p-3 text-sm leading-relaxed text-amber-900 ring-1 ring-amber-200">
                        Entre com a conta de <strong>{{ convite.email }}</strong> para aceitar.
                        Se ainda não tem conta, crie uma com esse mesmo e-mail.
                    </p>
                    <Link href="/entrar" class="btn-primario w-full">Entrar</Link>
                    <Link href="/cadastrar" class="btn-secundario w-full">Criar conta</Link>
                </template>

                <template v-else-if="!emailConfere">
                    <p class="rounded-xl bg-amber-50 p-3 text-sm leading-relaxed text-amber-900 ring-1 ring-amber-200">
                        Você está conectado como <strong>{{ logado_como }}</strong>, mas o convite foi
                        enviado para <strong>{{ convite.email }}</strong>.
                    </p>
                    <button type="button" class="btn-secundario w-full" @click="router.post('/sair')">
                        Sair e entrar com a conta certa
                    </button>
                </template>

                <button v-else type="button" class="btn-primario w-full" @click="aceitar">
                    Aceitar convite
                </button>
            </div>

            <p class="mt-5 text-center text-xs leading-relaxed text-slate-500">
                Você verá dados sigilosos de clientes. O sigilo profissional (EOAB, art. 34)
                vale para você também, e todo acesso a documento fica registrado.
            </p>
        </div>
    </div>
</template>
