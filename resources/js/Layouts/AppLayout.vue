<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { observarConexao, replay, enviarAnexos, anexosPendentes, pendentes } from '../offline';
import { avisoVarredura, varrerPeloCliente } from '../varreduraCliente';
import Icone from '../Components/Icone.vue';

const pagina = usePage();

const badges = computed(() => pagina.props.badges ?? {});
const flash = computed(() => pagina.props.flash ?? {});
const caminho = computed(() => pagina.url.split('?')[0]);
const usuario = computed(() => pagina.props.auth?.user ?? null);

const radar = computed(() => pagina.props.radar ?? null);
const contexto = computed(() => pagina.props.contexto ?? {});

/**
 * Defasagem do radar, sempre a vista.
 *
 * Quando a varredura depende do navegador (o DJEN recusa IP estrangeiro), a
 * inbox vazia e ambigua: pode ser "nao ha publicacao nova" ou "ninguem abriu
 * o app para ir buscar". Este aviso desfaz a ambiguidade. Sem ele, a tela
 * promete uma cobertura que nao existe.
 *
 * O corte e em horas porque a varredura do servidor roda as 6h: em dias, uma
 * varredura saudavel acenderia o aviso toda madrugada.
 */
const avisoRadar = computed(() => {
    const estado = radar.value;

    if (!estado?.tem_termos) {
        return null;
    }

    if (estado.cego) {
        return {
            tom: 'perigo',
            texto: 'Radar cego — a varredura do DJEN falhou seguidas vezes. Confira o diário manualmente.',
        };
    }

    if (estado.horas_sem_varredura === null) {
        return { tom: 'perigo', texto: 'O DJEN ainda não foi varrido nesta conta. Confira o diário manualmente.' };
    }

    const horas = estado.horas_sem_varredura;

    if (horas >= 72) {
        return {
            tom: 'perigo',
            texto: `Sem varredura do DJEN há ${Math.floor(horas / 24)} dias. Confira o diário manualmente.`,
        };
    }

    if (horas >= 30) {
        return { tom: 'atencao', texto: `Última varredura do DJEN há ${horas} h.` };
    }

    return null;
});
const espacos = computed(() => pagina.props.espacos ?? []);
const podeTrocarEspaco = computed(() => espacos.value.length > 1);

const conectado = ref(navigator.onLine);
const naFila = ref(0);
const atualizacaoDisponivel = ref(false);
const menuAberto = ref(false);
const espacosAbertos = ref(false);

function fecharTudo() {
    menuAberto.value = false;
    espacosAbertos.value = false;
}

function trocarEspaco(advogadoId) {
    if (advogadoId === contexto.value.advogado_id) {
        fecharTudo();
        return;
    }

    router.post('/contexto', { advogado_id: advogadoId });
}

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

    await varrerAoAbrir();
});

/**
 * Varredura oportunista a cada abertura do app.
 *
 * Silenciosa de proposito: nao interrompe quem acabou de abrir a tela. O
 * servidor e quem decide se ja passou o intervalo minimo, entao abrir dez
 * vezes na mesma hora nao castiga a API do CNJ.
 */
async function varrerAoAbrir() {
    const { executou } = await varrerPeloCliente();

    // Recarrega mesmo sem publicacao nova: a varredura acabou de mexer no
    // relogio do radar, e a faixa de defasagem no topo ficaria mentindo.
    if (executou) {
        router.reload({ only: ['badges', 'radar'] });
    }
}

onUnmounted(() => pararDeObservar?.());

// Trocar de tela fecha qualquer painel aberto e descarta o aviso da varredura.
watch(caminho, () => {
    fecharTudo();
    avisoVarredura.value = null;
});

function estaEm(prefixo) {
    return prefixo === '/' ? caminho.value === '/' : caminho.value.startsWith(prefixo);
}

/**
 * Navegacao completa. No celular ela vira 3 abas mais a folha "Mais"; no
 * desktop ela abre inteira no trilho lateral, porque ali sobra espaco e
 * esconder item atras de menu so aumenta o numero de cliques.
 */
const grupos = computed(() => {
    const trabalho = [
        {
            rotulo: 'Publicações',
            href: '/publicacoes',
            icone: 'documento',
            descricao: 'Inbox de triagem do DJEN',
            badge: badges.value.publicacoes ?? 0,
        },
        {
            rotulo: 'Prazos',
            href: '/prazos',
            icone: 'relogio',
            descricao: 'Todos os prazos e a cadeia de origem',
            badge: badges.value.conferencias ?? 0,
        },
        {
            rotulo: 'Clientes',
            href: '/clientes',
            icone: 'pessoa',
            descricao: 'Ficha, atendimentos e status',
        },
    ];

    if (contexto.value.permissoes?.ver_financeiro !== false) {
        trabalho.push({
            rotulo: 'Financeiro',
            href: '/financeiro',
            icone: 'dinheiro',
            descricao: 'A receber, vencido, recebido',
        });
    }

    const conta = [
        {
            rotulo: 'Notificações',
            href: '/notificacoes',
            icone: 'sino',
            descricao: 'Histórico de alertas',
            badge: badges.value.notificacoes ?? 0,
        },
    ];

    if (contexto.value.eh_titular) {
        conta.push({
            rotulo: 'Equipe',
            href: '/equipe',
            icone: 'equipe',
            descricao: 'Quem acompanha quais casos',
        });
    }

    conta.push({
        rotulo: 'Configurações',
        href: '/configuracoes',
        icone: 'engrenagem',
        descricao: 'Radar, feriados, conta e dados',
    });

    return [
        {
            titulo: null,
            itens: [
                {
                    rotulo: 'Hoje',
                    href: '/',
                    icone: 'hoje',
                    descricao: 'O que não pode passar',
                    badge: badges.value.fatais ?? 0,
                },
                { rotulo: 'Agenda', href: '/agenda', icone: 'agenda', descricao: 'Semana e compromissos' },
                { rotulo: 'Casos', href: '/casos', icone: 'casos', descricao: 'Processos e pastas' },
            ],
        },
        { titulo: 'Acompanhamento', itens: trabalho },
        { titulo: 'Escritório', itens: conta },
    ];
});

// A folha "Mais" do celular mostra tudo que nao coube nas 3 abas.
const itensDoMenu = computed(() => grupos.value.flatMap((grupo) => (grupo.titulo ? grupo.itens : [])));

const abas = computed(() => [
    {
        rotulo: 'Hoje',
        href: '/',
        icone: 'hoje',
        ativo: caminho.value === '/',
        badge: (badges.value.publicacoes ?? 0) + (badges.value.conferencias ?? 0),
    },
    { rotulo: 'Agenda', href: '/agenda', icone: 'agenda', ativo: estaEm('/agenda') },
    { rotulo: 'Casos', href: '/casos', icone: 'casos', ativo: estaEm('/casos') },
    { rotulo: 'Mais', href: '#mais', icone: 'mais', ativo: menuAberto.value },
]);

function aoTocarAba(aba, evento) {
    if (aba.href === '#mais') {
        evento.preventDefault();
        menuAberto.value = !menuAberto.value;
        return;
    }

    menuAberto.value = false;
}

function abrirTrocaDeEspaco() {
    if (!podeTrocarEspaco.value) {
        return;
    }

    menuAberto.value = true;
    espacosAbertos.value = true;
}

function recarregar() {
    window.location.reload();
}

function sair() {
    router.post('/sair');
}

const espacoAtual = computed(() => {
    const atual = espacos.value.find((espaco) => espaco.advogado_id === contexto.value.advogado_id);

    if (atual) {
        return atual.proprio ? 'Meu espaço' : atual.nome;
    }

    return contexto.value.advogado_nome ?? 'Meu espaço';
});

const iniciais = computed(() =>
    (usuario.value?.nome ?? '')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((parte) => parte[0])
        .join('')
        .toUpperCase()
);
</script>

<template>
    <div class="min-h-screen bg-fundo">
        <!-- ==================== Trilho lateral (desktop) ==================== -->
        <aside class="fixed inset-y-0 left-0 z-40 hidden w-[var(--largura-trilho)] flex-col border-r border-borda bg-superficie lg:flex">
            <div class="flex items-center gap-2.5 px-5 py-5">
                <img src="/icons/icon.svg" alt="" class="h-8 w-8 rounded-lg">
                <span class="text-[15px] font-semibold tracking-tight text-tinta">Mithrandir</span>
            </div>

            <!-- Espaco de trabalho ativo. So aparece para quem tem mais de um. -->
            <div v-if="podeTrocarEspaco" class="relative px-3 pb-3">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 rounded-lg border border-borda px-3 py-2 text-left transition hover:border-borda-forte hover:bg-superficie-2"
                    @click="espacosAbertos = !espacosAbertos"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block text-[11px] font-medium uppercase tracking-wide text-tinta-3">Espaço</span>
                        <span class="block truncate text-sm font-medium text-tinta">{{ espacoAtual }}</span>
                    </span>
                    <Icone
                        nome="seta"
                        class="h-4 w-4 shrink-0 text-tinta-icone transition"
                        :class="espacosAbertos ? 'rotate-90' : 'rotate-0'"
                    />
                </button>

                <div
                    v-if="espacosAbertos"
                    class="absolute inset-x-3 top-full z-50 mt-1 overflow-hidden rounded-xl border border-borda bg-superficie py-1 shadow-lg"
                >
                    <button
                        v-for="espaco in espacos"
                        :key="espaco.advogado_id"
                        type="button"
                        class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left transition hover:bg-superficie-2"
                        @click="trocarEspaco(espaco.advogado_id)"
                    >
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-tinta">
                                {{ espaco.proprio ? 'Meu espaço' : espaco.nome }}
                            </span>
                            <span class="block text-xs capitalize text-tinta-3">{{ espaco.papel }}</span>
                        </span>
                        <Icone
                            v-if="espaco.advogado_id === contexto.advogado_id"
                            nome="check"
                            class="h-4 w-4 shrink-0 text-ok"
                        />
                    </button>
                </div>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 pb-4">
                <div
                    v-for="(grupo, indice) in grupos"
                    :key="grupo.titulo ?? 'principal'"
                    :class="indice ? 'mt-6' : ''"
                >
                    <p v-if="grupo.titulo" class="secao-titulo px-3 pb-1.5">{{ grupo.titulo }}</p>

                    <Link
                        v-for="item in grupo.itens"
                        :key="item.href"
                        :href="item.href"
                        class="group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition"
                        :class="estaEm(item.href)
                            ? 'bg-superficie-2 font-semibold text-tinta'
                            : 'font-medium text-tinta-2 hover:bg-superficie-2 hover:text-tinta'"
                    >
                        <span
                            class="absolute inset-y-1.5 left-0 w-0.5 rounded-full bg-acao transition"
                            :class="estaEm(item.href) ? 'opacity-100' : 'opacity-0'"
                        />
                        <Icone
                            :nome="item.icone"
                            class="h-[18px] w-[18px] shrink-0"
                            :class="estaEm(item.href) ? 'text-tinta' : 'text-tinta-3 group-hover:text-tinta-2'"
                        />
                        <span class="min-w-0 flex-1 truncate">{{ item.rotulo }}</span>
                        <span
                            v-if="item.badge"
                            class="shrink-0 rounded-full px-1.5 py-0.5 text-[11px] font-semibold tabular-nums"
                            :class="estaEm(item.href) ? 'bg-acao text-sobre-acao' : 'bg-superficie-3 text-tinta-2'"
                        >
                            {{ item.badge > 99 ? '99+' : item.badge }}
                        </span>
                    </Link>
                </div>
            </nav>

            <div class="border-t border-borda p-3">
                <div class="flex items-center gap-2.5 rounded-lg px-2 py-1.5">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-acao text-xs font-semibold text-sobre-acao">
                        {{ iniciais || '—' }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-tinta">{{ usuario?.nome ?? 'Conta' }}</span>
                        <span v-if="usuario?.oab" class="block truncate text-xs text-tinta-3">{{ usuario.oab }}</span>
                    </span>
                    <button
                        type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-tinta-3 transition hover:bg-perigo-fundo hover:text-perigo"
                        title="Sair"
                        aria-label="Sair"
                        @click="sair"
                    >
                        <Icone nome="sair" class="h-[18px] w-[18px]" />
                    </button>
                </div>
            </div>
        </aside>

        <!-- ==================== Coluna de conteudo ==================== -->
        <div class="flex min-h-screen flex-col lg:pl-[var(--largura-trilho)]">
            <!-- Faixa de estado: offline e fila pendente nunca ficam escondidos. -->
            <div v-if="!conectado || naFila > 0 || atualizacaoDisponivel || avisoRadar" class="sticky top-0 z-30">
                <div v-if="!conectado" class="bg-neutro-solido px-4 py-2 text-center text-sm text-white">
                    Sem conexão — você continua trabalhando; tudo sobe depois.
                </div>
                <div v-else-if="naFila > 0" class="bg-acento-solido px-4 py-2 text-center text-sm text-white">
                    {{ naFila }} {{ naFila === 1 ? 'item aguardando envio' : 'itens aguardando envio' }}
                </div>
                <button
                    v-if="atualizacaoDisponivel"
                    class="w-full bg-ok-solido px-4 py-2 text-center text-sm font-medium text-white"
                    @click="recarregar"
                >
                    Nova versão disponível — tocar para atualizar
                </button>

                <Link
                    v-if="avisoRadar"
                    href="/configuracoes/radar"
                    class="block px-4 py-2 text-center text-sm font-medium text-white"
                    :class="avisoRadar.tom === 'perigo' ? 'bg-perigo-solido' : 'bg-atencao-solido'"
                >
                    {{ avisoRadar.texto }}
                </Link>
            </div>

            <!-- Trabalhando no espaco de outra pessoa: nunca escondido. -->
            <div
                v-if="contexto.advogado_id && !contexto.eh_titular"
                class="sticky top-0 z-30 flex items-center justify-between gap-2 bg-info-solido px-4 py-2 text-sm text-white"
            >
                <span class="min-w-0 truncate">
                    Você está em <strong>{{ contexto.advogado_nome }}</strong>
                    <span class="opacity-80">· {{ contexto.papel }}</span>
                </span>
                <button
                    type="button"
                    class="shrink-0 rounded-lg bg-superficie/20 px-2.5 py-1 text-xs font-semibold transition hover:bg-superficie/30"
                    @click="abrirTrocaDeEspaco"
                >
                    trocar
                </button>
            </div>

            <main class="flex-1 pb-28 lg:pb-12">
                <div v-if="flash.sucesso || flash.erro || avisoVarredura" class="pagina px-4 pt-4 lg:px-8">
                    <p
                        class="rounded-xl px-4 py-3 text-sm font-medium"
                        :class="flash.erro || avisoVarredura?.tom === 'erro'
                            ? 'bg-perigo-fundo text-perigo-tinta ring-1 ring-perigo-borda'
                            : 'bg-ok-fundo text-ok-tinta ring-1 ring-ok-borda'"
                    >
                        {{ flash.erro || flash.sucesso || avisoVarredura?.texto }}
                    </p>
                </div>

                <slot />
            </main>
        </div>

        <!-- ==================== Folha "Mais" (celular) ==================== -->
        <Transition
            enter-active-class="transition duration-200"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-150"
            leave-to-class="opacity-0"
        >
            <div v-if="menuAberto" class="fixed inset-0 z-40 bg-veu/45 lg:hidden" @click="fecharTudo" />
        </Transition>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-full"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="translate-y-full"
        >
            <div
                v-if="menuAberto"
                class="fixed inset-x-0 bottom-0 z-50 max-h-[85vh] overflow-y-auto rounded-t-3xl bg-superficie pb-safe shadow-2xl lg:hidden"
            >
                <div class="mx-auto mt-3 h-1.5 w-10 rounded-full bg-superficie-3" />
                <nav class="p-3">
                    <div v-if="podeTrocarEspaco" class="mb-2 border-b border-borda pb-2">
                        <p class="secao-titulo px-4 pb-1">Espaço de trabalho</p>
                        <button
                            v-for="espaco in espacos"
                            :key="espaco.advogado_id"
                            type="button"
                            class="flex w-full items-center justify-between rounded-xl px-4 py-3 text-left active:bg-superficie-2"
                            @click="trocarEspaco(espaco.advogado_id)"
                        >
                            <span class="min-w-0">
                                <span class="block truncate font-medium text-tinta">
                                    {{ espaco.proprio ? 'Meu espaço' : espaco.nome }}
                                </span>
                                <span class="block text-xs capitalize text-tinta-3">{{ espaco.papel }}</span>
                            </span>
                            <Icone
                                v-if="espaco.advogado_id === contexto.advogado_id"
                                nome="check"
                                class="h-5 w-5 shrink-0 text-ok"
                            />
                        </button>
                    </div>

                    <Link
                        v-for="item in itensDoMenu"
                        :key="item.href"
                        :href="item.href"
                        class="flex items-center gap-3 rounded-xl px-4 py-3.5 active:bg-superficie-2"
                        @click="fecharTudo"
                    >
                        <Icone :nome="item.icone" class="h-5 w-5 shrink-0 text-tinta-icone" />
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-tinta">{{ item.rotulo }}</span>
                            <span class="block text-sm text-tinta-3">{{ item.descricao }}</span>
                        </span>
                        <span
                            v-if="item.badge"
                            class="shrink-0 rounded-full bg-superficie-3 px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-tinta-2"
                        >
                            {{ item.badge > 99 ? '99+' : item.badge }}
                        </span>
                        <Icone nome="seta" class="h-5 w-5 shrink-0 text-tinta-icone" />
                    </Link>

                    <form class="mt-2 border-t border-borda pt-2" @submit.prevent="sair">
                        <button type="submit" class="w-full rounded-xl px-4 py-3.5 text-left font-semibold text-perigo active:bg-perigo-fundo">
                            Sair
                        </button>
                    </form>
                </nav>
            </div>
        </Transition>

        <!-- ==================== Abas inferiores (celular) ==================== -->
        <nav class="fixed inset-x-0 bottom-0 z-50 border-t border-borda bg-superficie/95 pb-safe backdrop-blur lg:hidden">
            <div class="mx-auto flex max-w-2xl">
                <Link
                    v-for="aba in abas"
                    :key="aba.rotulo"
                    :href="aba.href"
                    class="relative flex flex-1 flex-col items-center gap-0.5 py-2.5 text-xs font-medium"
                    :class="aba.ativo ? 'text-acento' : 'text-tinta-3'"
                    @click="aoTocarAba(aba, $event)"
                >
                    <span class="relative">
                        <Icone :nome="aba.icone" class="h-6 w-6" />
                        <span
                            v-if="aba.badge"
                            class="absolute -right-2 -top-1 min-w-4 rounded-full bg-perigo-solido px-1 text-[10px] font-bold leading-4 text-white"
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
