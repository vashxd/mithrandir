<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { observarConexao, replay, enviarAnexos, anexosPendentes, pendentes } from '../offline';
import Icone from '../Components/Icone.vue';

const pagina = usePage();

const badges = computed(() => pagina.props.badges ?? {});
const flash = computed(() => pagina.props.flash ?? {});
const caminho = computed(() => pagina.url.split('?')[0]);

const contexto = computed(() => pagina.props.contexto ?? {});
const espacos = computed(() => pagina.props.espacos ?? []);
const podeTrocarEspaco = computed(() => espacos.value.length > 1);

function trocarEspaco(advogadoId) {
    if (advogadoId === contexto.value.advogado_id) {
        menuAberto.value = false;
        return;
    }

    router.post('/contexto', { advogado_id: advogadoId });
}

const conectado = ref(navigator.onLine);
const naFila = ref(0);
const atualizacaoDisponivel = ref(false);
const menuAberto = ref(false);

let pararDeObservar = null;

async function contarFila() {
    const [anexos, escritas] = await Promise.all([anexosPendentes(), pendentes()]);
    naFila.value = anexos + escritas.length;
}

onMounted(async () => {
    pararDeObservar = observarConexao(async (estaOnline) => {
        conectado.value = estaOnline;
        await contarFila();
    });

    window.addEventListener('mithrandir:atualizacao-disponivel', () => {
        atualizacaoDisponivel.value = true;
    });

    await contarFila();

    if (navigator.onLine) {
        await Promise.allSettled([replay(), enviarAnexos()]);
        await contarFila();
    }
});

onUnmounted(() => pararDeObservar?.());

const abas = computed(() => [
    {
        rotulo: 'Hoje',
        href: '/',
        icone: 'hoje',
        ativo: caminho.value === '/',
        badge: (badges.value.publicacoes ?? 0) + (badges.value.conferencias ?? 0),
    },
    { rotulo: 'Agenda', href: '/agenda', icone: 'agenda', ativo: caminho.value.startsWith('/agenda') },
    { rotulo: 'Casos', href: '/casos', icone: 'casos', ativo: caminho.value.startsWith('/casos') },
    { rotulo: 'Mais', href: '#mais', icone: 'mais', ativo: menuAberto.value },
]);

const itensDoMenu = computed(() => {
    const itens = [
        { rotulo: 'Publicações', href: '/publicacoes', descricao: 'Inbox de triagem do DJEN' },
        { rotulo: 'Prazos', href: '/prazos', descricao: 'Todos os prazos e a cadeia de origem' },
        { rotulo: 'Clientes', href: '/clientes', descricao: 'Ficha, atendimentos e status' },
    ];

    if (contexto.value.permissoes?.ver_financeiro !== false) {
        itens.push({ rotulo: 'Financeiro', href: '/financeiro', descricao: 'A receber, vencido, recebido' });
    }

    itens.push({ rotulo: 'Notificações', href: '/notificacoes', descricao: 'Histórico de alertas' });

    if (contexto.value.eh_titular) {
        itens.push({ rotulo: 'Equipe', href: '/equipe', descricao: 'Quem acompanha quais casos' });
    }

    itens.push({ rotulo: 'Configurações', href: '/configuracoes', descricao: 'Radar, feriados, conta e dados' });

    return itens;
});

function aoTocarAba(aba, evento) {
    if (aba.href === '#mais') {
        evento.preventDefault();
        menuAberto.value = !menuAberto.value;
        return;
    }

    menuAberto.value = false;
}

function recarregar() {
    window.location.reload();
}
</script>

<template>
    <div class="flex min-h-full flex-col bg-slate-100">
        <!-- Faixa de estado: offline e fila pendente nunca ficam escondidos. -->
        <div v-if="!conectado || naFila > 0 || atualizacaoDisponivel" class="sticky top-0 z-30">
            <div v-if="!conectado" class="bg-slate-800 px-4 py-2 text-center text-sm text-white">
                Sem conexão — você continua trabalhando; tudo sobe depois.
            </div>
            <div v-else-if="naFila > 0" class="bg-sky-700 px-4 py-2 text-center text-sm text-white">
                {{ naFila }} {{ naFila === 1 ? 'item aguardando envio' : 'itens aguardando envio' }}
            </div>
            <button
                v-if="atualizacaoDisponivel"
                class="w-full bg-emerald-700 px-4 py-2 text-center text-sm font-medium text-white"
                @click="recarregar"
            >
                Nova versão disponível — tocar para atualizar
            </button>
        </div>

        <!-- Trabalhando no espaco de outra pessoa: nunca escondido. -->
        <div
            v-if="contexto.advogado_id && !contexto.eh_titular"
            class="sticky top-0 z-30 flex items-center justify-between gap-2 bg-violet-700 px-4 py-2 text-sm text-white"
        >
            <span class="min-w-0 truncate">
                Você está em <strong>{{ contexto.advogado_nome }}</strong>
                <span class="opacity-80">· {{ contexto.papel }}</span>
            </span>
            <button
                type="button"
                class="shrink-0 rounded-lg bg-white/20 px-2.5 py-1 text-xs font-semibold"
                @click="menuAberto = true"
            >
                trocar
            </button>
        </div>

        <main class="flex-1 pb-28">
            <div
                v-if="flash.sucesso || flash.erro"
                class="px-4 pt-4"
            >
                <p
                    class="rounded-xl px-4 py-3 text-sm font-medium"
                    :class="flash.erro
                        ? 'bg-red-50 text-red-800 ring-1 ring-red-200'
                        : 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200'"
                >
                    {{ flash.erro || flash.sucesso }}
                </p>
            </div>

            <slot />
        </main>

        <!-- Menu "Mais" em folha, nunca modal apertado no mobile. -->
        <Transition
            enter-active-class="transition duration-200"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-150"
            leave-to-class="opacity-0"
        >
            <div v-if="menuAberto" class="fixed inset-0 z-40 bg-slate-900/40" @click="menuAberto = false" />
        </Transition>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-full"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="translate-y-full"
        >
            <div v-if="menuAberto" class="fixed inset-x-0 bottom-0 z-50 rounded-t-3xl bg-white pb-safe shadow-2xl">
                <div class="mx-auto mt-3 h-1.5 w-10 rounded-full bg-slate-300" />
                <nav class="p-3">
                    <div v-if="podeTrocarEspaco" class="mb-2 border-b border-slate-200 pb-2">
                        <p class="px-4 pb-1 text-xs font-bold uppercase tracking-wide text-slate-500">
                            Espaço de trabalho
                        </p>
                        <button
                            v-for="espaco in espacos"
                            :key="espaco.advogado_id"
                            type="button"
                            class="flex w-full items-center justify-between rounded-xl px-4 py-3 text-left active:bg-slate-100"
                            @click="trocarEspaco(espaco.advogado_id)"
                        >
                            <span class="min-w-0">
                                <span class="block truncate font-medium text-slate-900">
                                    {{ espaco.proprio ? 'Meu espaço' : espaco.nome }}
                                </span>
                                <span class="block text-xs capitalize text-slate-500">{{ espaco.papel }}</span>
                            </span>
                            <Icone
                                v-if="espaco.advogado_id === contexto.advogado_id"
                                nome="check"
                                class="h-5 w-5 shrink-0 text-emerald-600"
                            />
                        </button>
                    </div>

                    <Link
                        v-for="item in itensDoMenu"
                        :key="item.href"
                        :href="item.href"
                        class="flex items-center justify-between rounded-xl px-4 py-3.5 active:bg-slate-100"
                        @click="menuAberto = false"
                    >
                        <span>
                            <span class="block font-semibold text-slate-900">{{ item.rotulo }}</span>
                            <span class="block text-sm text-slate-500">{{ item.descricao }}</span>
                        </span>
                        <Icone nome="seta" class="h-5 w-5 text-slate-400" />
                    </Link>

                    <form class="mt-2 border-t border-slate-200 pt-2" @submit.prevent="router.post('/sair')">
                        <button type="submit" class="w-full rounded-xl px-4 py-3.5 text-left font-semibold text-red-600 active:bg-red-50">
                            Sair
                        </button>
                    </form>
                </nav>
            </div>
        </Transition>

        <!-- Bottom tab bar de 4 itens (seção 11). -->
        <nav class="fixed inset-x-0 bottom-0 z-50 border-t border-slate-200 bg-white/95 pb-safe backdrop-blur">
            <div class="mx-auto flex max-w-2xl">
                <Link
                    v-for="aba in abas"
                    :key="aba.rotulo"
                    :href="aba.href"
                    class="relative flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs font-medium"
                    :class="aba.ativo ? 'text-sky-700' : 'text-slate-500'"
                    @click="aoTocarAba(aba, $event)"
                >
                    <span class="relative">
                        <Icone :nome="aba.icone" class="h-6 w-6" />
                        <span
                            v-if="aba.badge"
                            class="absolute -right-2 -top-1 min-w-4 rounded-full bg-red-600 px-1 text-[10px] font-bold leading-4 text-white"
                        >
                            {{ aba.badge > 99 ? '99+' : aba.badge }}
                        </span>
                    </span>
                    {{ aba.rotulo }}
                </Link>
            </div>
        </nav>
    </div>
</template>
