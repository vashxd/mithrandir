<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { moeda, dataCurta, dataLonga, hora, contagem, CORES_CRITICIDADE, BARRA_CRITICIDADE } from '../formato';
import Icone from '../Components/Icone.vue';
import Vazio from '../Components/Vazio.vue';

/**
 * Tela 1 — "O que não posso deixar passar?"
 *
 * A tela Hoje é o produto (princípio nº 1). A ordem dos blocos é a ordem da
 * ansiedade: alerta, prazo fatal, agenda do dia, publicação por triar, dinheiro
 * vencido. Nada de dashboard decorativo.
 */
const props = defineProps({
    painel: { type: Object, required: true },
});

const usuario = computed(() => usePage().props.auth.user);

const primeiroNome = computed(() => (usuario.value?.nome ?? '').split(' ')[0]);

const saudacao = computed(() => {
    const h = new Date().getHours();
    if (h < 12) return 'Bom dia';
    if (h < 18) return 'Boa tarde';
    return 'Boa noite';
});

const fatais = computed(() => props.painel.fatais ?? []);

const criticos = computed(() =>
    fatais.value.filter((p) => ['vencido', 'critico'].includes(p.criticidade))
);

const tudoLimpo = computed(
    () =>
        fatais.value.length === 0 &&
        (props.painel.agenda ?? []).length === 0 &&
        props.painel.publicacoes_novas.total === 0 &&
        props.painel.financeiro.vencido_qtd === 0
);

function sincronizar() {
    router.post('/publicacoes/sincronizar', {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Hoje" />

    <div class="pagina">
        <header class="px-4 pb-2 pt-6 lg:pb-4 lg:pt-10">
            <p class="text-sm text-slate-500">{{ saudacao }}, {{ primeiroNome }}</p>
            <h1 class="text-2xl font-bold text-slate-900 first-letter:uppercase lg:text-3xl">
                {{ dataLonga(painel.data) }}
            </h1>
        </header>

        <!-- Alertas nunca ficam escondidos atrás de um menu. -->
        <section v-if="painel.alertas.length" class="space-y-2 px-4 pt-3">
            <Link
                v-for="alerta in painel.alertas"
                :key="alerta.titulo"
                :href="alerta.url"
                class="flex gap-3 rounded-2xl p-4 ring-1"
                :class="alerta.nivel === 'critico'
                    ? 'bg-red-50 text-red-900 ring-red-200'
                    : 'bg-amber-50 text-amber-900 ring-amber-200'"
            >
                <Icone nome="alerta" class="mt-0.5 h-5 w-5 shrink-0" />
                <span class="min-w-0">
                    <span class="block font-semibold">{{ alerta.titulo }}</span>
                    <span class="block text-sm leading-relaxed opacity-90">{{ alerta.texto }}</span>
                </span>
            </Link>
        </section>

        <Vazio
            v-if="tudoLimpo"
            titulo="Nada vencendo, nada pendente."
            texto="Sua agenda de hoje está limpa e não há publicação por triar. Continue conferindo o diário do seu tribunal."
        >
            <button type="button" class="btn-secundario" @click="sincronizar">
                <Icone nome="sincronizar" class="h-4 w-4" />
                Buscar publicações agora
            </button>
        </Vazio>

        <!--
            No desktop a tela vira duas colunas: a esquerda e a ordem da
            ansiedade (o que vence, o que tenho que fazer), a direita e o que
            chegou de fora (Judiciario e dinheiro). No celular tudo volta a
            ser uma coluna so, na mesma ordem de leitura.
            Sem gap na grade: o px-4 de cada secao ja forma a calha.
        -->
        <div class="lg:grid lg:grid-cols-5 lg:items-start">
        <div class="lg:col-span-3">

        <!-- Bloco 1: o que vence. -->
        <section v-if="fatais.length" class="px-4 pt-5">
            <div class="mb-2 flex items-baseline justify-between">
                <h2 class="secao-titulo">
                    Prazos até 7 dias
                </h2>
                <Link href="/prazos" class="text-sm font-medium text-sky-700">ver todos</Link>
            </div>

            <p v-if="criticos.length" class="mb-2 text-sm font-semibold text-red-700">
                {{ criticos.length }} em zona vermelha
            </p>

            <ul class="space-y-2">
                <li v-for="prazo in fatais" :key="prazo.id">
                    <Link
                        :href="`/prazos/${prazo.id}`"
                        class="flex overflow-hidden rounded-2xl bg-white ring-1"
                        :class="CORES_CRITICIDADE[prazo.criticidade]"
                    >
                        <span class="w-1.5 shrink-0" :class="BARRA_CRITICIDADE[prazo.criticidade]" />

                        <span class="min-w-0 flex-1 p-3.5">
                            <span class="flex items-start justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold">{{ prazo.tipo }}</span>
                                    <span v-if="prazo.processo" class="mt-0.5 block truncate text-sm opacity-80">
                                        {{ prazo.processo.cliente ?? 'sem cliente' }} ·
                                        {{ prazo.processo.rotulo }}
                                    </span>
                                </span>

                                <span class="shrink-0 text-right">
                                    <span class="block font-mono text-sm font-bold">
                                        {{ dataCurta(prazo.data_fatal) }}
                                    </span>
                                    <span class="block text-xs font-medium">
                                        {{ contagem(prazo.dias_restantes) }}
                                    </span>
                                </span>
                            </span>

                            <span class="mt-2 flex flex-wrap gap-1.5">
                                <span class="etiqueta bg-white/70 text-[11px]">
                                    alvo {{ dataCurta(prazo.data_alvo) }}
                                </span>
                                <span v-if="prazo.ajustado_manualmente" class="etiqueta bg-white/70 text-[11px]">
                                    ajustado à mão
                                </span>
                                <span v-if="prazo.precisa_revisao" class="etiqueta bg-amber-200 text-[11px] text-amber-900">
                                    revisar
                                </span>
                            </span>
                        </span>
                    </Link>
                </li>
            </ul>
        </section>

        <!-- Bloco 2: o que tenho que fazer. -->
        <section v-if="painel.agenda.length" class="px-4 pt-6">
            <div class="mb-2 flex items-baseline justify-between">
                <h2 class="secao-titulo">Hoje na agenda</h2>
                <Link href="/agenda" class="text-sm font-medium text-sky-700">ver semana</Link>
            </div>

            <ul class="cartao divide-y divide-slate-100">
                <li v-for="evento in painel.agenda" :key="evento.id" class="flex gap-3 p-3.5">
                    <span class="w-14 shrink-0 font-mono text-sm font-semibold text-slate-700">
                        {{ evento.dia_inteiro ? '—' : hora(evento.inicio) }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium text-slate-900">{{ evento.titulo }}</span>
                        <span v-if="evento.local || evento.processo" class="block truncate text-sm text-slate-500">
                            {{ [evento.local, evento.processo].filter(Boolean).join(' · ') }}
                        </span>
                        <a
                            v-if="evento.link"
                            :href="evento.link"
                            target="_blank"
                            rel="noopener"
                            class="mt-1 inline-block text-sm font-medium text-sky-700"
                        >
                            entrar na videoconferência
                        </a>
                    </span>
                </li>
            </ul>
        </section>

        </div>
        <div class="lg:col-span-2">

        <!-- Bloco 3: o que o Judiciário mandou. -->
        <section v-if="painel.publicacoes_novas.total > 0" class="px-4 pt-6 lg:pt-5">
            <div class="mb-2 flex items-baseline justify-between">
                <h2 class="secao-titulo">
                    Publicações por triar
                </h2>
                <button type="button" class="text-sm font-medium text-sky-700" @click="sincronizar">
                    sincronizar
                </button>
            </div>

            <Link href="/publicacoes" class="block cartao overflow-hidden">
                <span class="flex items-center gap-3 border-b border-slate-100 bg-sky-50 px-4 py-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-sky-600 text-sm font-bold text-white">
                        {{ painel.publicacoes_novas.total }}
                    </span>
                    <span class="text-sm font-semibold text-sky-900">
                        {{ painel.publicacoes_novas.total === 1 ? 'publicação aguardando' : 'publicações aguardando' }}
                    </span>
                    <Icone nome="seta" class="ml-auto h-5 w-5 text-sky-700" />
                </span>

                <ul class="divide-y divide-slate-100">
                    <li v-for="pub in painel.publicacoes_novas.itens" :key="pub.id" class="px-4 py-3">
                        <span class="flex items-baseline justify-between gap-2">
                            <span class="truncate text-sm font-medium text-slate-800">
                                {{ pub.tribunal ?? 'DJEN' }}
                            </span>
                            <span class="shrink-0 font-mono text-xs text-slate-500">
                                {{ dataCurta(pub.data_disponibilizacao) }}
                            </span>
                        </span>
                        <span class="mt-0.5 line-clamp-2 block text-sm leading-snug text-slate-500">
                            {{ pub.resumo }}
                        </span>
                    </li>
                </ul>
            </Link>
        </section>

        <!-- Bloco 4: quem me deve. -->
        <section v-if="painel.financeiro.vencido_qtd > 0 || painel.financeiro.a_receber_mes > 0" class="px-4 pb-2 pt-6">
            <div class="mb-2 flex items-baseline justify-between">
                <h2 class="secao-titulo">Dinheiro</h2>
                <Link href="/financeiro" class="text-sm font-medium text-sky-700">ver painel</Link>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div class="cartao p-3.5" :class="painel.financeiro.vencido_qtd ? 'ring-1 ring-red-200' : ''">
                    <p class="text-xs font-medium text-slate-500">Vencido</p>
                    <p
                        class="mt-1 text-lg font-bold"
                        :class="painel.financeiro.vencido_qtd ? 'text-red-700' : 'text-slate-900'"
                    >
                        {{ moeda(painel.financeiro.vencido_total) }}
                    </p>
                    <p class="text-xs text-slate-500">
                        {{ painel.financeiro.vencido_qtd }}
                        {{ painel.financeiro.vencido_qtd === 1 ? 'parcela' : 'parcelas' }}
                    </p>
                </div>

                <div class="cartao p-3.5">
                    <p class="text-xs font-medium text-slate-500">A receber no mês</p>
                    <p class="mt-1 text-lg font-bold text-slate-900">
                        {{ moeda(painel.financeiro.a_receber_mes) }}
                    </p>
                </div>
            </div>

            <ul v-if="painel.financeiro.itens.length" class="cartao mt-2 divide-y divide-slate-100">
                <li v-for="parcela in painel.financeiro.itens" :key="parcela.id" class="flex items-baseline justify-between gap-3 px-4 py-3">
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium text-slate-800">
                            {{ parcela.cliente ?? 'Sem cliente' }}
                        </span>
                        <span class="block font-mono text-xs text-red-600">
                            venceu {{ dataCurta(parcela.vencimento) }}
                        </span>
                    </span>
                    <span class="shrink-0 font-semibold text-slate-900">{{ moeda(parcela.valor) }}</span>
                </li>
            </ul>
        </section>

        </div>
        </div>

        <p class="px-6 pb-6 pt-2 text-center text-xs leading-relaxed text-slate-400 lg:pt-6">
            O Mithrandir não substitui a conferência do diário oficial.
            A responsabilidade pelo prazo continua sendo do advogado.
        </p>
    </div>
</template>
