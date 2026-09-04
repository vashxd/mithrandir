<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { assinarPush, testarPush, pwaInstalado, ehIos, pushDisponivel } from '../pwa';
import Icone from '../Components/Icone.vue';

/**
 * RF-8.3: o onboarding existe para forçar "Adicionar à Tela de Início".
 *
 * No iOS o Web Push só funciona com o PWA instalado. Sem esse passo o produto
 * não entrega a promessa central — por isso ele é um passo do fluxo, não uma
 * dica escondida nas configurações.
 */
const props = defineProps({
    watches: { type: Array, default: () => [] },
    push_configurado: { type: Boolean, default: false },
    concluido: { type: Boolean, default: false },
});

const instalado = ref(false);
const ios = ref(false);
const podePush = ref(false);
const permissao = ref('default');
const statusPush = ref(null);
const trabalhando = ref(false);

onMounted(() => {
    instalado.value = pwaInstalado();
    ios.value = ehIos();
    podePush.value = pushDisponivel();
    permissao.value = typeof Notification !== 'undefined' ? Notification.permission : 'unsupported';
});

const chavePublica = computed(() => usePage().props.push?.chave_publica ?? '');

const termosAtivos = computed(() => props.watches.filter((w) => w.ativo));

const passos = computed(() => [
    {
        chave: 'instalar',
        titulo: 'Instalar na tela de início',
        pronto: instalado.value,
        obrigatorio: ios.value,
    },
    {
        chave: 'notificar',
        titulo: 'Ligar as notificações',
        pronto: permissao.value === 'granted',
        obrigatorio: true,
    },
    {
        chave: 'radar',
        titulo: 'Conferir os termos de vigilância',
        pronto: termosAtivos.value.length > 0,
        obrigatorio: true,
    },
]);

const tudoPronto = computed(() => passos.value.every((p) => p.pronto || !p.obrigatorio));

async function ligarNotificacoes() {
    trabalhando.value = true;
    statusPush.value = null;

    try {
        await assinarPush(chavePublica.value);
        permissao.value = Notification.permission;
        statusPush.value = { ok: true, texto: 'Notificações ligadas neste aparelho.' };
    } catch (erro) {
        statusPush.value = { ok: false, texto: erro.message };
    } finally {
        trabalhando.value = false;
    }
}

async function enviarTeste() {
    trabalhando.value = true;
    const resultado = await testarPush();
    statusPush.value = { ok: resultado.ok, texto: resultado.mensagem };
    trabalhando.value = false;
}

const watchForm = useForm({ termo: '', tipo: 'oab', uf: '', ativo: true });

function adicionarTermo() {
    watchForm.post('/configuracoes/radar', {
        preserveScroll: true,
        onSuccess: () => watchForm.reset(),
    });
}

function alternarTermo(watch) {
    router.post(
        '/configuracoes/radar',
        { termo: watch.termo, tipo: watch.tipo, uf: watch.uf, ativo: !watch.ativo },
        { preserveScroll: true }
    );
}

function concluir() {
    router.post('/onboarding/concluir');
}
</script>

<template>
    <Head title="Primeiros passos" />

    <div class="min-h-screen bg-fundo px-5 py-8 pb-16">
        <div class="mx-auto w-full max-w-lg">
            <header class="text-center">
                <img src="/icons/icon.svg" alt="" class="mx-auto h-14 w-14 rounded-2xl">
                <h1 class="mt-3 text-2xl font-bold text-slate-900">Três passos e você está pronta</h1>
                <p class="mt-1 text-sm leading-relaxed text-slate-500">
                    Sem estes passos o app não avisa nada — e um app de prazo que não avisa não serve.
                </p>
            </header>

            <ol class="mt-6 space-y-3">
                <li v-for="(passo, indice) in passos" :key="passo.chave" class="cartao overflow-hidden">
                    <div class="flex items-center gap-3 p-4">
                        <span
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold"
                            :class="passo.pronto ? 'bg-emerald-500 text-white' : 'bg-slate-200 text-slate-600'"
                        >
                            <Icone v-if="passo.pronto" nome="check" class="h-4 w-4" />
                            <template v-else>{{ indice + 1 }}</template>
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-slate-900">{{ passo.titulo }}</span>
                            <span v-if="!passo.obrigatorio && !passo.pronto" class="block text-xs text-slate-500">
                                opcional no seu aparelho
                            </span>
                        </span>
                    </div>

                    <!-- Passo 1: instalação -->
                    <div v-if="passo.chave === 'instalar' && !instalado" class="border-t border-slate-100 px-4 py-4">
                        <template v-if="ios">
                            <p class="rounded-xl bg-red-50 p-3 text-sm leading-relaxed text-red-900 ring-1 ring-red-200">
                                <strong>No iPhone este passo é obrigatório.</strong>
                                O Safari só entrega notificação para app instalado na tela de início.
                                Sem isso, você não recebe alerta de prazo nenhum.
                            </p>

                            <ol class="mt-3 space-y-2 text-sm text-slate-700">
                                <li>1. Toque no botão <strong>Compartilhar</strong> (o quadrado com a seta para cima).</li>
                                <li>2. Role e escolha <strong>Adicionar à Tela de Início</strong>.</li>
                                <li>3. Confirme e abra o Mithrandir pelo ícone novo.</li>
                            </ol>
                        </template>

                        <template v-else>
                            <p class="text-sm leading-relaxed text-slate-600">
                                No Android, use o menu do navegador e escolha
                                <strong>Instalar app</strong> ou <strong>Adicionar à tela inicial</strong>.
                                As notificações funcionam mesmo sem instalar, mas o app fica mais rápido e
                                abre em tela cheia.
                            </p>
                        </template>
                    </div>

                    <!-- Passo 2: notificações -->
                    <div v-if="passo.chave === 'notificar'" class="border-t border-slate-100 px-4 py-4">
                        <p v-if="!push_configurado" class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">
                            As chaves VAPID ainda não foram configuradas no servidor.
                            Rode <code class="font-mono">php artisan mithrandir:vapid</code> e reinicie a aplicação.
                        </p>

                        <p v-else-if="!podePush" class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">
                            Instale o app na tela de início primeiro — só depois o iPhone libera as notificações.
                        </p>

                        <div v-else class="space-y-2">
                            <button
                                v-if="permissao !== 'granted'"
                                type="button"
                                class="btn-primario w-full"
                                :disabled="trabalhando"
                                @click="ligarNotificacoes"
                            >
                                Permitir notificações
                            </button>

                            <button
                                v-else
                                type="button"
                                class="btn-secundario w-full"
                                :disabled="trabalhando"
                                @click="enviarTeste"
                            >
                                Enviar uma notificação de teste
                            </button>

                            <p
                                v-if="statusPush"
                                class="rounded-xl px-3 py-2.5 text-sm"
                                :class="statusPush.ok
                                    ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200'
                                    : 'bg-red-50 text-red-800 ring-1 ring-red-200'"
                            >
                                {{ statusPush.texto }}
                            </p>

                            <p class="text-xs leading-relaxed text-slate-500">
                                Você recebe alerta de nova publicação e de prazo em D-10, D-5, D-3, D-1
                                e na manhã do dia fatal.
                            </p>
                        </div>
                    </div>

                    <!-- Passo 3: radar -->
                    <div v-if="passo.chave === 'radar'" class="border-t border-slate-100 px-4 py-4">
                        <p class="text-sm leading-relaxed text-slate-600">
                            Tribunais gravam o número da OAB de jeitos diferentes — <code class="font-mono">123456</code>
                            e <code class="font-mono">123456-O</code>, por exemplo. Cadastramos as variações
                            para você; confira se falta alguma.
                        </p>

                        <ul class="mt-3 space-y-1.5">
                            <li
                                v-for="watch in watches"
                                :key="watch.id"
                                class="flex items-center justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2.5"
                            >
                                <span class="min-w-0">
                                    <span class="block truncate font-mono text-sm text-slate-800">{{ watch.termo }}</span>
                                    <span class="block text-xs text-slate-500">
                                        {{ watch.tipo === 'oab' ? 'OAB' : 'nome' }}
                                        <template v-if="watch.uf"> · {{ watch.uf }}</template>
                                    </span>
                                </span>

                                <button
                                    type="button"
                                    class="shrink-0 rounded-lg px-2.5 py-1.5 text-xs font-semibold"
                                    :class="watch.ativo ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'"
                                    @click="alternarTermo(watch)"
                                >
                                    {{ watch.ativo ? 'ativo' : 'desligado' }}
                                </button>
                            </li>
                        </ul>

                        <div class="mt-3 flex gap-2">
                            <input
                                v-model="watchForm.termo"
                                type="text"
                                class="campo"
                                placeholder="outra variação da OAB"
                                aria-label="Novo termo de vigilância"
                            >
                            <button type="button" class="btn-secundario shrink-0" @click="adicionarTermo">
                                Somar
                            </button>
                        </div>
                    </div>
                </li>
            </ol>

            <button
                type="button"
                class="mt-6 w-full"
                :class="tudoPronto ? 'btn-primario' : 'btn-secundario'"
                @click="concluir"
            >
                {{ tudoPronto ? 'Tudo pronto, ir para o app' : 'Continuar mesmo assim' }}
            </button>

            <p v-if="!tudoPronto" class="mt-2 text-center text-xs leading-relaxed text-slate-500">
                Você pode terminar depois em Configurações, mas até lá o app não vai te avisar de nada.
            </p>
        </div>
    </div>
</template>
