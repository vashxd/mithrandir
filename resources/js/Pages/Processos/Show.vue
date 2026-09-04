<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { moeda, dataCurta, dataHora, contagem, CORES_CRITICIDADE, BARRA_CRITICIDADE } from '../../formato';
import Cabecalho from '../../Components/Cabecalho.vue';
import CapturaDocumento from '../../Components/CapturaDocumento.vue';
import Folha from '../../Components/Folha.vue';
import Icone from '../../Components/Icone.vue';

/**
 * Tela 4 — "Qual a situação deste processo?"
 * Abas: Dados / Prazos / Documentos / Financeiro, mais a timeline (RF-4.3).
 */
const props = defineProps({
    processo: { type: Object, required: true },
    prazos: { type: Array, default: () => [] },
    timeline: { type: Array, default: () => [] },
    documentos: { type: Array, default: () => [] },
    checklist: { type: Object, required: true },
    financeiro: { type: Object, required: true },
    tipos_documento: { type: Object, default: () => ({}) },
    templates: { type: Array, default: () => [] },
    clientes: { type: Array, default: () => [] },
    datajud_disponivel: { type: Boolean, default: false },
});

const aba = ref('dados');

const abas = computed(() => [
    { chave: 'dados', rotulo: 'Dados' },
    { chave: 'prazos', rotulo: 'Prazos', total: props.prazos.filter((p) => ['aberto', 'em_andamento'].includes(p.status)).length },
    { chave: 'documentos', rotulo: 'Documentos', total: props.documentos.length },
    { chave: 'financeiro', rotulo: 'Financeiro' },
]);

/* ---------------- Editar o caso ---------------- */

const folhaEdicao = ref(false);

const edicao = useForm({
    cliente_id: props.processo.cliente?.id ?? null,
    numero_cnj: props.processo.numero_cnj ?? '',
    titulo: props.processo.titulo ?? '',
    tribunal: props.processo.tribunal ?? '',
    vara: props.processo.vara ?? '',
    classe: props.processo.classe ?? '',
    assunto: props.processo.assunto ?? '',
    fase: props.processo.fase ?? 'conhecimento',
    area: props.processo.area ?? '',
    valor_causa: props.processo.valor_causa,
    proxima_acao: props.processo.proxima_acao ?? '',
    segredo_justica: props.processo.segredo_justica,
});

function salvarEdicao() {
    edicao.patch(`/casos/${props.processo.id}`, {
        preserveScroll: true,
        onSuccess: () => (folhaEdicao.value = false),
    });
}

/* ---------------- Próxima ação (RF-4.5) ---------------- */

const editandoAcao = ref(false);
const acao = useForm({ proxima_acao: props.processo.proxima_acao ?? '' });

function salvarAcao() {
    acao.patch(`/casos/${props.processo.id}`, {
        preserveScroll: true,
        onSuccess: () => (editandoAcao.value = false),
    });
}

/* ---------------- DataJud (RF-4.4) ---------------- */

const importando = ref(false);

function importarDataJud() {
    importando.value = true;
    router.post(`/casos/${props.processo.id}/datajud`, {}, {
        preserveScroll: true,
        onFinish: () => (importando.value = false),
    });
}

/* ---------------- Checklist ---------------- */

const folhaChecklist = ref(false);
const templateEscolhido = ref(null);

function aplicarChecklist() {
    router.post(
        `/casos/${props.processo.id}/checklist`,
        { template_id: templateEscolhido.value },
        { preserveScroll: true, onSuccess: () => (folhaChecklist.value = false) }
    );
}

/* ---------------- Financeiro ---------------- */

const folhaHonorario = ref(false);

const honorario = useForm({
    processo_id: props.processo.id,
    cliente_id: props.processo.cliente?.id ?? null,
    tipo: 'parcelado',
    valor: null,
    percentual_exito: null,
    qtd_parcelas: 3,
    primeiro_vencimento: '',
    observacoes: '',
});

function salvarHonorario() {
    honorario.post('/financeiro/honorarios', {
        preserveScroll: true,
        onSuccess: () => {
            folhaHonorario.value = false;
            honorario.reset();
        },
    });
}

function arquivar() {
    router.post(`/casos/${props.processo.id}/arquivar`, { confirmar: true }, { preserveScroll: true });
}

const ICONE_TIMELINE = {
    publicacao: 'sino',
    movimentacao: 'documento',
    prazo: 'relogio',
    atendimento: 'pessoa',
};
</script>

<template>
    <Head :title="processo.rotulo" />

    <Cabecalho
        :titulo="processo.cliente?.nome ?? 'Caso sem cliente'"
        :subtitulo="processo.numero_formatado ?? processo.titulo"
        voltar-para="/casos"
    />

    <div class="pagina">
        <!-- Próxima ação: o que a advogada precisa lembrar sobre o caso. -->
        <div class="px-4 pt-4">
            <div class="rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200">
                <div class="flex items-baseline justify-between gap-2">
                    <p class="text-xs font-bold uppercase tracking-wide text-amber-900">Próxima ação</p>
                    <button type="button" class="text-xs font-semibold text-amber-900 underline" @click="editandoAcao = !editandoAcao">
                        {{ editandoAcao ? 'cancelar' : 'editar' }}
                    </button>
                </div>

                <template v-if="editandoAcao">
                    <textarea v-model="acao.proxima_acao" rows="3" class="campo mt-2" placeholder="Ex.: juntar CNIS atualizado antes da perícia" />
                    <button type="button" class="btn-primario mt-2 w-full" @click="salvarAcao">Salvar</button>
                </template>

                <p v-else class="mt-1 whitespace-pre-wrap text-sm leading-relaxed text-amber-900">
                    {{ processo.proxima_acao || 'Nada anotado ainda.' }}
                </p>
            </div>
        </div>

        <div class="flex gap-2 overflow-x-auto px-4 py-3">
            <button
                v-for="item in abas"
                :key="item.chave"
                type="button"
                class="shrink-0 rounded-full px-3.5 py-2 text-sm font-medium"
                :class="aba === item.chave ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200'"
                @click="aba = item.chave"
            >
                {{ item.rotulo }}
                <span v-if="item.total" class="ml-1 opacity-70">{{ item.total }}</span>
            </button>
        </div>

        <!-- ---------------- Dados ---------------- -->
        <section v-show="aba === 'dados'" class="space-y-3 px-4 pb-8">
            <div class="cartao p-4">
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div v-if="processo.numero_formatado" class="col-span-2">
                        <dt class="text-xs font-medium text-slate-500">Número CNJ</dt>
                        <dd class="font-mono text-slate-900">{{ processo.numero_formatado }}</dd>
                    </div>
                    <div v-if="processo.tribunal">
                        <dt class="text-xs font-medium text-slate-500">Tribunal</dt>
                        <dd class="text-slate-800">{{ processo.tribunal }}</dd>
                    </div>
                    <div v-if="processo.fase">
                        <dt class="text-xs font-medium text-slate-500">Fase</dt>
                        <dd class="text-slate-800">{{ processo.fase }}</dd>
                    </div>
                    <div v-if="processo.vara" class="col-span-2">
                        <dt class="text-xs font-medium text-slate-500">Vara</dt>
                        <dd class="text-slate-800">{{ processo.vara }}</dd>
                    </div>
                    <div v-if="processo.classe" class="col-span-2">
                        <dt class="text-xs font-medium text-slate-500">Classe</dt>
                        <dd class="text-slate-800">{{ processo.classe }}</dd>
                    </div>
                    <div v-if="processo.assunto" class="col-span-2">
                        <dt class="text-xs font-medium text-slate-500">Assunto</dt>
                        <dd class="text-slate-800">{{ processo.assunto }}</dd>
                    </div>
                    <div v-if="processo.valor_causa">
                        <dt class="text-xs font-medium text-slate-500">Valor da causa</dt>
                        <dd class="text-slate-800">{{ moeda(processo.valor_causa) }}</dd>
                    </div>
                </dl>

                <div class="mt-3 flex items-center justify-between gap-2 border-t border-slate-100 pt-3">
                    <Link
                        v-if="processo.cliente"
                        :href="`/clientes/${processo.cliente.id}`"
                        class="flex min-w-0 items-center gap-2 text-sm font-medium text-sky-700"
                    >
                        <Icone nome="pessoa" class="h-4 w-4 shrink-0" />
                        <span class="truncate">{{ processo.cliente.nome }}</span>
                    </Link>

                    <span v-else class="flex items-center gap-2 text-sm text-slate-500">
                        <Icone nome="pessoa" class="h-4 w-4 shrink-0" />
                        Sem cliente vinculado
                    </span>

                    <button
                        type="button"
                        class="shrink-0 rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-700"
                        @click="folhaEdicao = true"
                    >
                        {{ processo.cliente ? 'editar' : 'vincular cliente' }}
                    </button>
                </div>
            </div>

            <div v-if="processo.partes.length" class="cartao p-4">
                <h2 class="mb-2 secao-titulo">Partes</h2>
                <ul class="space-y-1.5 text-sm">
                    <li v-for="parte in processo.partes" :key="parte.id" class="flex justify-between gap-2">
                        <span class="text-slate-800">{{ parte.nome }}</span>
                        <span class="shrink-0 capitalize text-slate-500">{{ parte.tipo }}</span>
                    </li>
                </ul>
            </div>

            <button
                v-if="datajud_disponivel && processo.numero_cnj"
                type="button"
                class="btn-secundario w-full"
                :disabled="importando"
                @click="importarDataJud"
            >
                <Icone nome="sincronizar" class="h-4 w-4" :class="importando ? 'animate-spin' : ''" />
                {{ importando ? 'Consultando…' : 'Atualizar pelo DataJud' }}
            </button>

            <p v-if="processo.datajud_sincronizado_em" class="text-center text-xs text-slate-400">
                Última consulta ao DataJud em {{ dataHora(processo.datajud_sincronizado_em) }}.
                O DataJud enriquece o caso, mas nunca dispara prazo.
            </p>

            <!-- Timeline do caso -->
            <div v-if="timeline.length" class="cartao overflow-hidden">
                <h2 class="border-b border-slate-100 px-4 py-3 secao-titulo">
                    Histórico
                </h2>
                <ul class="divide-y divide-slate-100">
                    <li v-for="(item, indice) in timeline" :key="indice" class="flex gap-3 p-3.5">
                        <Icone :nome="ICONE_TIMELINE[item.tipo]" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                        <div class="min-w-0 flex-1">
                            <p class="flex items-baseline justify-between gap-2">
                                <span class="truncate text-sm font-medium text-slate-800">{{ item.titulo }}</span>
                                <span class="shrink-0 font-mono text-xs text-slate-500">{{ dataCurta(item.data) }}</span>
                            </p>
                            <p class="mt-0.5 line-clamp-2 text-sm text-slate-500">{{ item.detalhe }}</p>
                            <Link v-if="item.url" :href="item.url" class="mt-1 inline-block text-xs font-medium text-sky-700">
                                abrir
                            </Link>
                        </div>
                    </li>
                </ul>
            </div>

            <button type="button" class="btn-secundario w-full text-slate-500" @click="arquivar">
                {{ processo.arquivado ? 'Reabrir caso' : 'Arquivar caso' }}
            </button>
            <p class="text-center text-xs text-slate-400">Arquivar não apaga nada do histórico.</p>
        </section>

        <!-- ---------------- Prazos ---------------- -->
        <section v-show="aba === 'prazos'" class="space-y-2 px-4 pb-8">
            <p v-if="!prazos.length" class="cartao p-6 text-center text-sm text-slate-500">
                Nenhum prazo neste caso.
            </p>

            <Link
                v-for="prazo in prazos"
                :key="prazo.id"
                :href="`/prazos/${prazo.id}`"
                class="flex overflow-hidden rounded-2xl bg-white ring-1"
                :class="CORES_CRITICIDADE[prazo.criticidade]"
            >
                <span class="w-1.5 shrink-0" :class="BARRA_CRITICIDADE[prazo.criticidade]" />
                <span class="flex flex-1 items-start justify-between gap-3 p-3.5">
                    <span class="min-w-0">
                        <span class="block truncate font-semibold">{{ prazo.tipo }}</span>
                        <span class="block text-xs opacity-75">alvo {{ dataCurta(prazo.data_alvo) }}</span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span class="block font-mono text-sm font-bold">{{ dataCurta(prazo.data_fatal) }}</span>
                        <span class="block text-xs">{{ contagem(prazo.dias_restantes) }}</span>
                    </span>
                </span>
            </Link>
        </section>

        <!-- ---------------- Documentos ---------------- -->
        <section v-show="aba === 'documentos'" class="space-y-3 px-4 pb-8">
            <CapturaDocumento :processo-id="processo.id" :tipos="tipos_documento" />

            <!-- Checklist com percentual (RF-6.3) -->
            <div class="cartao p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="secao-titulo">Checklist</h2>
                    <button type="button" class="text-xs font-semibold text-sky-700" @click="folhaChecklist = true">
                        {{ checklist.total ? 'trocar modelo' : 'aplicar modelo' }}
                    </button>
                </div>

                <template v-if="checklist.total">
                    <div class="mt-3 flex items-center gap-3">
                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-200">
                            <div class="h-full rounded-full bg-emerald-500 transition-all" :style="{ width: `${checklist.percentual}%` }" />
                        </div>
                        <span class="shrink-0 text-sm font-bold text-slate-700">{{ checklist.percentual }}%</span>
                    </div>

                    <p class="mt-1 text-xs text-slate-500">
                        {{ checklist.concluidos }} de {{ checklist.obrigatorios }} documentos obrigatórios
                    </p>

                    <ul class="mt-3 divide-y divide-slate-100">
                        <li v-for="item in checklist.itens" :key="item.id" class="flex items-center gap-2.5 py-2.5">
                            <span
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                                :class="item.documento_id ? 'bg-emerald-500 text-white' : 'bg-slate-200'"
                            >
                                <Icone v-if="item.documento_id" nome="check" class="h-3.5 w-3.5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm" :class="item.documento_id ? 'text-slate-500 line-through' : 'text-slate-800'">
                                    {{ item.item }}
                                </span>
                                <span v-if="item.documento_nome" class="block truncate text-xs text-slate-400">
                                    {{ item.documento_nome }}
                                </span>
                            </span>
                            <span v-if="!item.obrigatorio" class="etiqueta shrink-0 bg-slate-100 text-slate-500">opcional</span>
                        </li>
                    </ul>
                </template>

                <p v-else class="mt-2 text-sm text-slate-500">
                    Nenhum checklist aplicado. Escolha um modelo para saber o que ainda falta pedir ao cliente.
                </p>
            </div>

            <ul v-if="documentos.length" class="cartao divide-y divide-slate-100">
                <li v-for="documento in documentos" :key="documento.id" class="flex items-center gap-3 p-3.5">
                    <Icone nome="documento" class="h-5 w-5 shrink-0 text-slate-400" />
                    <a :href="`/documentos/${documento.id}`" class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-slate-800">{{ documento.nome }}</span>
                        <span class="block text-xs text-slate-500">
                            {{ documento.tipo_rotulo }} · {{ documento.tamanho }}
                            <template v-if="documento.origem === 'camera'"> · foto</template>
                        </span>
                    </a>
                    <Link
                        :href="`/documentos/${documento.id}`"
                        method="delete"
                        as="button"
                        type="button"
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-slate-400 active:bg-slate-100"
                        aria-label="Remover documento"
                    >
                        <Icone nome="x" class="h-5 w-5" />
                    </Link>
                </li>
            </ul>
        </section>

        <!-- ---------------- Financeiro ---------------- -->
        <section v-show="aba === 'financeiro'" class="space-y-3 px-4 pb-8">
            <div class="grid grid-cols-3 gap-2">
                <div class="cartao p-3">
                    <p class="text-xs text-slate-500">Contratado</p>
                    <p class="mt-0.5 text-sm font-bold text-slate-900">{{ moeda(financeiro.contratado) }}</p>
                </div>
                <div class="cartao p-3">
                    <p class="text-xs text-slate-500">Recebido</p>
                    <p class="mt-0.5 text-sm font-bold text-emerald-700">{{ moeda(financeiro.recebido) }}</p>
                </div>
                <div class="cartao p-3">
                    <p class="text-xs text-slate-500">A receber</p>
                    <p class="mt-0.5 text-sm font-bold text-slate-900">{{ moeda(financeiro.a_receber) }}</p>
                </div>
            </div>

            <button type="button" class="btn-secundario w-full" @click="folhaHonorario = true">
                <Icone nome="dinheiro" class="h-4 w-4" />
                Registrar honorário
            </button>

            <div v-for="h in financeiro.honorarios" :key="h.id" class="cartao p-4">
                <div class="flex items-baseline justify-between">
                    <p class="text-sm font-semibold capitalize text-slate-900">{{ h.tipo }}</p>
                    <p class="font-bold text-slate-900">{{ moeda(h.valor) }}</p>
                </div>
                <p v-if="h.percentual_exito" class="text-xs text-slate-500">{{ h.percentual_exito }}% de êxito</p>

                <ul v-if="h.parcelas.length" class="mt-3 divide-y divide-slate-100">
                    <li v-for="parcela in h.parcelas" :key="parcela.id" class="flex items-center justify-between gap-2 py-2 text-sm">
                        <span class="text-slate-600">
                            {{ parcela.numero }}ª · {{ dataCurta(parcela.vencimento) }}
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="font-medium text-slate-900">{{ moeda(parcela.valor) }}</span>
                            <span
                                class="etiqueta"
                                :class="{
                                    'bg-emerald-100 text-emerald-800': parcela.situacao === 'pago',
                                    'bg-red-100 text-red-800': parcela.situacao === 'vencido',
                                    'bg-amber-100 text-amber-800': parcela.situacao === 'vencendo',
                                    'bg-slate-100 text-slate-600': parcela.situacao === 'a_vencer',
                                }"
                            >
                                {{ parcela.situacao === 'a_vencer' ? 'a vencer' : parcela.situacao }}
                            </span>
                            <Link
                                v-if="!parcela.pago_em"
                                :href="`/financeiro/parcelas/${parcela.id}/baixar`"
                                method="post"
                                as="button"
                                type="button"
                                class="rounded-lg bg-slate-900 px-2.5 py-1 text-xs font-semibold text-white"
                                preserve-scroll
                            >
                                baixar
                            </Link>
                        </span>
                    </li>
                </ul>
            </div>

            <div v-if="financeiro.despesas.length" class="cartao p-4">
                <h2 class="mb-2 secao-titulo">Despesas</h2>
                <ul class="divide-y divide-slate-100">
                    <li v-for="despesa in financeiro.despesas" :key="despesa.id" class="flex items-baseline justify-between gap-2 py-2 text-sm">
                        <span class="min-w-0">
                            <span class="block truncate text-slate-800">{{ despesa.descricao }}</span>
                            <span class="block font-mono text-xs text-slate-500">{{ dataCurta(despesa.data) }}</span>
                        </span>
                        <span class="shrink-0 text-right">
                            <span class="block font-medium text-slate-900">{{ moeda(despesa.valor) }}</span>
                            <span v-if="despesa.reembolsavel" class="block text-xs text-slate-500">
                                {{ despesa.reembolsada ? 'reembolsada' : 'reembolsável' }}
                            </span>
                        </span>
                    </li>
                </ul>
            </div>
        </section>
    </div>

    <Folha :aberta="folhaEdicao" titulo="Editar caso" @fechar="folhaEdicao = false">
        <div class="space-y-4">
            <div>
                <label class="rotulo" for="ec-cliente">Cliente</label>
                <select id="ec-cliente" v-model="edicao.cliente_id" class="campo">
                    <option :value="null">Sem cliente vinculado</option>
                    <option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.nome }}</option>
                </select>
                <p class="mt-1 text-xs text-slate-500">
                    Vincular um cliente aqui faz o caso aparecer na ficha dele.
                </p>
                <p v-if="edicao.errors.cliente_id" class="mt-1 text-sm text-red-600">
                    {{ edicao.errors.cliente_id }}
                </p>
            </div>

            <div>
                <label class="rotulo" for="ec-titulo">Apelido do caso</label>
                <input id="ec-titulo" v-model="edicao.titulo" type="text" class="campo">
            </div>

            <div>
                <label class="rotulo" for="ec-numero">Número CNJ</label>
                <input
                    id="ec-numero"
                    v-model="edicao.numero_cnj"
                    type="text"
                    inputmode="numeric"
                    class="campo font-mono"
                    placeholder="0000000-00.0000.0.00.0000"
                >
                <p v-if="edicao.errors.numero_cnj" class="mt-1 text-sm text-red-600">
                    {{ edicao.errors.numero_cnj }}
                </p>
                <p v-else class="mt-1 text-xs text-slate-500">
                    O dígito verificador é conferido ao salvar.
                </p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="rotulo" for="ec-tribunal">Tribunal</label>
                    <input id="ec-tribunal" v-model="edicao.tribunal" type="text" class="campo">
                </div>
                <div>
                    <label class="rotulo" for="ec-area">Área</label>
                    <select id="ec-area" v-model="edicao.area" class="campo">
                        <option value="">Escolher…</option>
                        <option value="previdenciario">Previdenciário</option>
                        <option value="consumidor">Consumidor</option>
                        <option value="familia">Família</option>
                        <option value="civel">Cível</option>
                        <option value="trabalhista">Trabalhista</option>
                        <option value="penal">Penal</option>
                        <option value="outro">Outro</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="rotulo" for="ec-vara">Vara</label>
                <input id="ec-vara" v-model="edicao.vara" type="text" class="campo">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="rotulo" for="ec-classe">Classe</label>
                    <input id="ec-classe" v-model="edicao.classe" type="text" class="campo">
                </div>
                <div>
                    <label class="rotulo" for="ec-fase">Fase</label>
                    <select id="ec-fase" v-model="edicao.fase" class="campo">
                        <option value="administrativo">Administrativo</option>
                        <option value="conhecimento">Conhecimento</option>
                        <option value="recurso">Recurso</option>
                        <option value="execucao">Execução</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="rotulo" for="ec-assunto">Assunto</label>
                <input id="ec-assunto" v-model="edicao.assunto" type="text" class="campo">
            </div>

            <div>
                <label class="rotulo" for="ec-valor">Valor da causa</label>
                <input id="ec-valor" v-model.number="edicao.valor_causa" type="number" step="0.01" min="0" class="campo">
            </div>

            <label class="flex items-center gap-2.5 rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                <input v-model="edicao.segredo_justica" type="checkbox" class="h-5 w-5 rounded border-slate-300">
                <span class="text-sm text-slate-800">Corre em segredo de justiça</span>
            </label>
        </div>

        <template #rodape>
            <button type="button" class="btn-primario w-full" :disabled="edicao.processing" @click="salvarEdicao">
                {{ edicao.processing ? 'Salvando…' : 'Salvar alterações' }}
            </button>
        </template>
    </Folha>

    <Folha :aberta="folhaChecklist" titulo="Aplicar checklist" @fechar="folhaChecklist = false">
        <div class="space-y-2">
            <label
                v-for="template in templates"
                :key="template.id"
                class="flex items-center gap-3 rounded-2xl bg-white p-4 ring-1"
                :class="templateEscolhido === template.id ? 'ring-slate-900' : 'ring-slate-200'"
            >
                <input v-model="templateEscolhido" type="radio" :value="template.id" class="h-5 w-5">
                <span>
                    <span class="block font-medium text-slate-900">{{ template.nome }}</span>
                    <span class="block text-sm capitalize text-slate-500">{{ template.area }}</span>
                </span>
            </label>
        </div>

        <template #rodape>
            <button type="button" class="btn-primario w-full" :disabled="!templateEscolhido" @click="aplicarChecklist">
                Aplicar
            </button>
        </template>
    </Folha>

    <Folha :aberta="folhaHonorario" titulo="Honorário" @fechar="folhaHonorario = false">
        <div class="space-y-4">
            <div>
                <label class="rotulo" for="tipo-hon">Forma</label>
                <select id="tipo-hon" v-model="honorario.tipo" class="campo">
                    <option value="fixo">Fixo</option>
                    <option value="parcelado">Parcelado</option>
                    <option value="exito">Só êxito</option>
                    <option value="misto">Misto</option>
                </select>
            </div>

            <div>
                <label class="rotulo" for="valor-hon">Valor total</label>
                <input id="valor-hon" v-model.number="honorario.valor" type="number" step="0.01" min="0" class="campo">
            </div>

            <div v-if="['exito', 'misto'].includes(honorario.tipo)">
                <label class="rotulo" for="exito">Percentual de êxito</label>
                <input id="exito" v-model.number="honorario.percentual_exito" type="number" step="0.1" min="0" max="100" class="campo">
            </div>

            <template v-if="honorario.tipo !== 'exito'">
                <div>
                    <label class="rotulo" for="qtd">Parcelas</label>
                    <input id="qtd" v-model.number="honorario.qtd_parcelas" type="number" min="1" max="60" class="campo">
                </div>

                <div>
                    <label class="rotulo" for="venc">Primeiro vencimento</label>
                    <input id="venc" v-model="honorario.primeiro_vencimento" type="date" class="campo">
                    <p v-if="honorario.errors.primeiro_vencimento" class="mt-1 text-sm text-red-600">
                        {{ honorario.errors.primeiro_vencimento }}
                    </p>
                </div>
            </template>
        </div>

        <template #rodape>
            <button type="button" class="btn-primario w-full" :disabled="honorario.processing" @click="salvarHonorario">
                Salvar
            </button>
        </template>
    </Folha>
</template>
