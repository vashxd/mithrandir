<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { moeda, dataCurta, hoje } from '../../formato';
import Cabecalho from '../../Components/Cabecalho.vue';
import Folha from '../../Components/Folha.vue';
import Icone from '../../Components/Icone.vue';

/**
 * Tela 6 — "Quanto tenho a receber?"
 */
const props = defineProps({
    mes: { type: String, required: true },
    painel: { type: Object, required: true },
    parcelas_mes: { type: Array, default: () => [] },
    vencidas: { type: Array, default: () => [] },
    despesas: { type: Array, default: () => [] },
    processos: { type: Array, default: () => [] },
    clientes: { type: Array, default: () => [] },
});

function mudarMes(direcao) {
    const [ano, mes] = props.mes.split('-').map(Number);
    const data = new Date(ano, mes - 1 + direcao, 1);
    const alvo = `${data.getFullYear()}-${String(data.getMonth() + 1).padStart(2, '0')}`;

    router.get('/financeiro', { mes: alvo }, { preserveState: true, preserveScroll: true });
}

function rotuloMes() {
    const [ano, mes] = props.mes.split('-').map(Number);
    return new Date(ano, mes - 1, 1).toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
}

const folhaDespesa = ref(false);

const despesa = useForm({
    processo_id: null,
    descricao: '',
    categoria: 'custas',
    valor: null,
    data: hoje(),
    reembolsavel: true,
});

function salvarDespesa() {
    despesa.post('/financeiro/despesas', {
        preserveScroll: true,
        onSuccess: () => {
            folhaDespesa.value = false;
            despesa.reset();
            despesa.data = hoje();
        },
    });
}

const CORES_SITUACAO = {
    pago: 'bg-ok-fundo text-ok-tinta',
    vencido: 'bg-perigo-fundo text-perigo-tinta',
    vencendo: 'bg-atencao-fundo text-atencao-tinta',
    a_vencer: 'bg-superficie-2 text-tinta-2',
};
</script>

<template>
    <Head title="Financeiro" />

    <Cabecalho titulo="Financeiro">
        <template #acoes>
            <button
                type="button"
                class="flex h-11 w-11 items-center justify-center rounded-full bg-acao text-sobre-acao"
                aria-label="Nova despesa"
                @click="folhaDespesa = true"
            >
                <Icone nome="mais_circulo" class="h-5 w-5" />
            </button>
        </template>
    </Cabecalho>

    <div class="pagina px-4 lg:px-8 pb-8">
        <div class="flex items-center justify-between py-3 lg:justify-start lg:gap-2">
            <button
                type="button"
                class="flex h-11 w-11 items-center justify-center rounded-full text-tinta-2 active:bg-superficie-3"
                aria-label="Mês anterior"
                @click="mudarMes(-1)"
            >
                <Icone nome="voltar" class="h-5 w-5" />
            </button>
            <p class="text-sm font-semibold text-tinta-2 first-letter:uppercase lg:order-first lg:mr-2 lg:text-base">{{ rotuloMes() }}</p>
            <button
                type="button"
                class="flex h-11 w-11 items-center justify-center rounded-full text-tinta-2 active:bg-superficie-3"
                aria-label="Próximo mês"
                @click="mudarMes(1)"
            >
                <Icone nome="seta" class="h-5 w-5" />
            </button>
        </div>

        <!-- RF-7.3: os quatro números que importam. -->
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="cartao p-4" :class="painel.vencido_qtd ? 'ring-1 ring-perigo-borda' : ''">
                <p class="text-xs font-medium text-tinta-3">Vencido</p>
                <p class="mt-1 text-xl font-bold" :class="painel.vencido_qtd ? 'text-perigo' : 'text-tinta'">
                    {{ moeda(painel.vencido) }}
                </p>
                <p class="text-xs text-tinta-3">
                    {{ painel.vencido_qtd }} {{ painel.vencido_qtd === 1 ? 'parcela' : 'parcelas' }}
                </p>
            </div>

            <div class="cartao p-4">
                <p class="text-xs font-medium text-tinta-3">A receber no mês</p>
                <p class="mt-1 text-xl font-bold text-tinta">{{ moeda(painel.a_receber_mes) }}</p>
            </div>

            <div class="cartao p-4">
                <p class="text-xs font-medium text-tinta-3">Recebido no mês</p>
                <p class="mt-1 text-xl font-bold text-ok">{{ moeda(painel.recebido_mes) }}</p>
            </div>

            <div class="cartao p-4">
                <p class="text-xs font-medium text-tinta-3">Total contratado</p>
                <p class="mt-1 text-xl font-bold text-tinta">{{ moeda(painel.total_contratado) }}</p>
            </div>
        </div>

        <!-- RF-7.5: provisão de imposto sugerida sobre o que entrou. -->
        <div v-if="painel.recebido_mes > 0" class="cartao mt-2 flex items-center justify-between gap-3 p-4">
            <div class="min-w-0">
                <p class="text-sm font-medium text-tinta">Guardar para imposto</p>
                <p class="text-xs leading-relaxed text-tinta-3">
                    {{ painel.percentual_imposto }}% do que entrou este mês. Sugestão, não cálculo fiscal.
                </p>
            </div>
            <p class="shrink-0 text-lg font-bold text-tinta">{{ moeda(painel.provisao_imposto) }}</p>
        </div>

        <section v-if="vencidas.length" class="mt-5">
            <h2 class="mb-2 secao-titulo text-perigo">Vencidas</h2>
            <ul class="cartao divide-y divide-borda-sutil ring-1 ring-perigo-borda">
                <li v-for="parcela in vencidas" :key="parcela.id" class="flex items-center gap-3 p-3.5">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-tinta">
                            {{ parcela.cliente ?? 'Sem cliente' }}
                        </span>
                        <span class="block truncate text-xs text-tinta-3">
                            {{ parcela.numero }}ª parcela · venceu {{ dataCurta(parcela.vencimento) }}
                        </span>
                    </span>
                    <span class="shrink-0 font-semibold text-tinta">{{ moeda(parcela.valor) }}</span>
                    <Link
                        :href="`/financeiro/parcelas/${parcela.id}/baixar`"
                        method="post"
                        as="button"
                        type="button"
                        class="shrink-0 rounded-lg bg-acao px-3 py-2 text-xs font-semibold text-sobre-acao"
                        preserve-scroll
                    >
                        baixar
                    </Link>
                </li>
            </ul>
        </section>

        <section class="mt-5">
            <h2 class="mb-2 secao-titulo">Parcelas do mês</h2>

            <p v-if="!parcelas_mes.length" class="cartao p-5 text-center text-sm text-tinta-3">
                Nenhuma parcela com vencimento neste mês.
            </p>

            <ul v-else class="cartao divide-y divide-borda-sutil">
                <li v-for="parcela in parcelas_mes" :key="parcela.id" class="flex items-center gap-3 p-3.5">
                    <span class="w-12 shrink-0 font-mono text-xs text-tinta-3">
                        {{ dataCurta(parcela.vencimento).slice(0, 5) }}
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-tinta">
                            {{ parcela.cliente ?? 'Sem cliente' }}
                        </span>
                        <span v-if="parcela.processo" class="block truncate text-xs text-tinta-3">
                            {{ parcela.processo }}
                        </span>
                    </span>

                    <span class="shrink-0 text-right">
                        <span class="block font-semibold text-tinta">{{ moeda(parcela.valor) }}</span>
                        <span class="etiqueta" :class="CORES_SITUACAO[parcela.situacao]">
                            {{ parcela.situacao === 'a_vencer' ? 'a vencer' : parcela.situacao }}
                        </span>
                    </span>

                    <Link
                        v-if="!parcela.pago_em"
                        :href="`/financeiro/parcelas/${parcela.id}/baixar`"
                        method="post"
                        as="button"
                        type="button"
                        class="shrink-0 rounded-lg bg-acao px-3 py-2 text-xs font-semibold text-sobre-acao"
                        preserve-scroll
                    >
                        baixar
                    </Link>
                    <Link
                        v-else
                        :href="`/financeiro/parcelas/${parcela.id}/baixar`"
                        method="post"
                        as="button"
                        type="button"
                        :data="{ estornar: true }"
                        class="shrink-0 rounded-lg px-2 py-2 text-xs font-medium text-tinta-3"
                        preserve-scroll
                    >
                        estornar
                    </Link>
                </li>
            </ul>
        </section>

        <section class="mt-5">
            <div class="mb-2 flex items-baseline justify-between">
                <h2 class="secao-titulo">Despesas do mês</h2>
                <span class="text-sm font-semibold text-tinta-2">{{ moeda(painel.despesas_mes) }}</span>
            </div>

            <p v-if="!despesas.length" class="cartao p-5 text-center text-sm text-tinta-3">
                Nenhuma despesa lançada.
            </p>

            <ul v-else class="cartao divide-y divide-borda-sutil">
                <li v-for="item in despesas" :key="item.id" class="flex items-center gap-3 p-3.5">
                    <span class="w-12 shrink-0 font-mono text-xs text-tinta-3">
                        {{ dataCurta(item.data).slice(0, 5) }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm text-tinta">{{ item.descricao }}</span>
                        <span class="block truncate text-xs text-tinta-3">
                            {{ [item.categoria, item.processo].filter(Boolean).join(' · ') }}
                        </span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span class="block font-medium text-tinta">{{ moeda(item.valor) }}</span>
                        <span v-if="item.reembolsavel" class="block text-xs text-tinta-3">
                            {{ item.reembolsada ? 'reembolsada' : 'reembolsável' }}
                        </span>
                    </span>
                </li>
            </ul>
        </section>
    </div>

    <Folha :aberta="folhaDespesa" titulo="Nova despesa" @fechar="folhaDespesa = false">
        <div class="space-y-4">
            <div>
                <label class="rotulo" for="descricao">Descrição</label>
                <input id="descricao" v-model="despesa.descricao" type="text" class="campo" placeholder="Custas iniciais">
                <p v-if="despesa.errors.descricao" class="mt-1 text-sm text-perigo">{{ despesa.errors.descricao }}</p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="rotulo" for="valor">Valor</label>
                    <input id="valor" v-model.number="despesa.valor" type="number" step="0.01" min="0" class="campo">
                </div>
                <div>
                    <label class="rotulo" for="data">Data</label>
                    <input id="data" v-model="despesa.data" type="date" class="campo">
                </div>
            </div>

            <div>
                <label class="rotulo" for="categoria">Categoria</label>
                <select id="categoria" v-model="despesa.categoria" class="campo">
                    <option value="custas">Custas</option>
                    <option value="copias">Cópias</option>
                    <option value="deslocamento">Deslocamento</option>
                    <option value="pericia">Perícia</option>
                    <option value="correio">Correio</option>
                    <option value="outro">Outro</option>
                </select>
            </div>

            <div>
                <label class="rotulo" for="processo">Caso</label>
                <select id="processo" v-model="despesa.processo_id" class="campo">
                    <option :value="null">Sem caso vinculado</option>
                    <option v-for="processo in processos" :key="processo.id" :value="processo.id">
                        {{ processo.rotulo }}
                    </option>
                </select>
            </div>

            <label class="flex items-center gap-2.5 rounded-2xl bg-superficie p-4 ring-1 ring-borda">
                <input v-model="despesa.reembolsavel" type="checkbox" class="h-5 w-5 rounded border-borda-forte">
                <span class="text-sm text-tinta">Cobrar do cliente depois</span>
            </label>
        </div>

        <template #rodape>
            <button type="button" class="btn-primario w-full" :disabled="despesa.processing" @click="salvarDespesa">
                Lançar
            </button>
        </template>
    </Folha>
</template>
