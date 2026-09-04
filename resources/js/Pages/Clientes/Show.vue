<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { dataCurta, dataHora, hoje, iniciais } from '../../formato';
import Cabecalho from '../../Components/Cabecalho.vue';
import Folha from '../../Components/Folha.vue';
import Icone from '../../Components/Icone.vue';

const props = defineProps({
    cliente: { type: Object, required: true },
    processos: { type: Array, default: () => [] },
    atendimentos: { type: Array, default: () => [] },
    vigilancia: { type: Object, required: true },
    publicacoes: { type: Array, default: () => [] },
});

/* ---------------- Editar cadastro ---------------- */

const folhaEdicao = ref(false);

const edicao = useForm({
    nome: props.cliente.nome,
    documento: props.cliente.documento_formatado ?? props.cliente.documento ?? '',
    nascimento: props.cliente.nascimento ?? '',
    contatos: props.cliente.contatos?.length
        ? props.cliente.contatos.map((c) => ({ ...c }))
        : [{ tipo: 'whatsapp', valor: '' }],
    endereco: {
        logradouro: props.cliente.endereco?.logradouro ?? '',
        numero: props.cliente.endereco?.numero ?? '',
        bairro: props.cliente.endereco?.bairro ?? '',
        cidade: props.cliente.endereco?.cidade ?? '',
        uf: props.cliente.endereco?.uf ?? '',
        cep: props.cliente.endereco?.cep ?? '',
    },
    origem: props.cliente.origem ?? '',
    observacoes: props.cliente.observacoes ?? '',
});

function adicionarContato() {
    edicao.contatos.push({ tipo: 'telefone', valor: '' });
}

function removerContato(indice) {
    edicao.contatos.splice(indice, 1);
}

function salvarEdicao() {
    edicao
        .transform((dados) => ({
            ...dados,
            // O servidor normaliza o documento; mandar com ou sem ponto da no mesmo.
            contatos: dados.contatos.filter((c) => c.valor.trim()),
            nascimento: dados.nascimento || null,
        }))
        .patch(`/clientes/${props.cliente.id}`, {
            preserveScroll: true,
            onSuccess: () => (folhaEdicao.value = false),
        });
}

/* ---------------- Vigilancia no DJEN ---------------- */

const folhaVigilancia = ref(false);
const previa = ref(null);
const carregandoPrevia = ref(false);

async function abrirVigilancia() {
    folhaVigilancia.value = true;
    previa.value = null;

    if (props.vigilancia.ativa) return;

    carregandoPrevia.value = true;

    try {
        const resposta = await fetch(`/clientes/${props.cliente.id}/vigilancia/previa`, {
            headers: { Accept: 'application/json' },
        });
        previa.value = await resposta.json();
    } catch {
        previa.value = { erro: 'Nao deu para consultar o DJEN agora.' };
    } finally {
        carregandoPrevia.value = false;
    }
}

function alternarVigilancia() {
    router.post(`/clientes/${props.cliente.id}/vigilancia`, {}, {
        preserveScroll: true,
        onSuccess: () => (folhaVigilancia.value = false),
    });
}

/* ---------------- Atendimento (RF-5.2) ---------------- */

const folhaAtendimento = ref(false);

const atendimento = useForm({
    data: hoje(),
    canal: 'whatsapp',
    resumo: '',
    processo_id: null,
});

function salvarAtendimento() {
    atendimento.post(`/clientes/${props.cliente.id}/atendimentos`, {
        preserveScroll: true,
        onSuccess: () => {
            folhaAtendimento.value = false;
            atendimento.reset();
            atendimento.data = hoje();
        },
    });
}

/* ---------------- Status para o WhatsApp (RF-5.3) ---------------- */

const folhaStatus = ref(false);
const textoStatus = ref('');
const copiado = ref(false);
const carregandoStatus = ref(false);

async function gerarStatus(processoId) {
    carregandoStatus.value = true;
    folhaStatus.value = true;
    copiado.value = false;

    try {
        const resposta = await fetch(`/clientes/${props.cliente.id}/status/${processoId}`, {
            headers: { Accept: 'application/json' },
        });
        const dados = await resposta.json();
        textoStatus.value = dados.texto;
    } catch {
        textoStatus.value = 'Não foi possível montar o texto agora.';
    } finally {
        carregandoStatus.value = false;
    }
}

async function copiar() {
    try {
        await navigator.clipboard.writeText(textoStatus.value);
        copiado.value = true;
        setTimeout(() => (copiado.value = false), 2500);
    } catch {
        // Contexto sem permissão de área de transferência: a pessoa seleciona à mão.
        copiado.value = false;
    }
}

const telefone = props.cliente.contatos?.find((c) => ['whatsapp', 'telefone'].includes(c.tipo))?.valor;

function linkWhatsapp() {
    const numero = (telefone ?? '').replace(/\D/g, '');
    const texto = encodeURIComponent(textoStatus.value);
    return `https://wa.me/${numero.length > 11 ? numero : '55' + numero}?text=${texto}`;
}

const ROTULO_CANAL = {
    presencial: 'Presencial',
    telefone: 'Telefone',
    whatsapp: 'WhatsApp',
    email: 'E-mail',
    video: 'Videochamada',
};
</script>

<template>
    <Head :title="cliente.nome" />

    <Cabecalho :titulo="cliente.nome" :subtitulo="cliente.documento_formatado" voltar-para="/clientes" />

    <div class="pagina space-y-3 px-4 lg:px-8 py-4 pb-8">
        <div class="cartao p-4">
            <div class="flex items-center gap-3">
                <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-slate-200 text-lg font-bold text-slate-600">
                    {{ iniciais(cliente.nome) }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-semibold text-slate-900">{{ cliente.nome }}</p>
                    <p v-if="cliente.documento_formatado" class="truncate font-mono text-xs text-slate-500">
                        {{ cliente.documento_formatado }}
                    </p>
                    <p v-if="cliente.origem" class="truncate text-sm text-slate-500">via {{ cliente.origem }}</p>
                </div>

                <button
                    type="button"
                    class="shrink-0 rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-700"
                    @click="folhaEdicao = true"
                >
                    editar
                </button>
            </div>

            <ul v-if="cliente.contatos?.length" class="mt-4 space-y-2">
                <li v-for="(contato, indice) in cliente.contatos" :key="indice" class="flex items-center justify-between gap-2 text-sm">
                    <span class="capitalize text-slate-500">{{ contato.tipo }}</span>
                    <a
                        :href="contato.tipo === 'email' ? `mailto:${contato.valor}` : `tel:${contato.valor}`"
                        class="font-medium text-sky-700"
                    >
                        {{ contato.valor }}
                    </a>
                </li>
            </ul>

            <p v-if="cliente.endereco?.logradouro" class="mt-3 border-t border-slate-100 pt-3 text-sm text-slate-600">
                {{ [cliente.endereco.logradouro, cliente.endereco.numero, cliente.endereco.bairro,
                    cliente.endereco.cidade, cliente.endereco.uf].filter(Boolean).join(', ') }}
            </p>

            <p v-if="cliente.observacoes" class="mt-3 whitespace-pre-wrap rounded-xl bg-slate-50 p-3 text-sm text-slate-700">
                {{ cliente.observacoes }}
            </p>
        </div>

        <!-- Vigilancia do nome no DJEN. Nasce desligada: nome nao desambigua homonimo. -->
        <section class="cartao p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="secao-titulo">Vigiar no DJEN</h2>
                    <p class="mt-1 text-sm leading-relaxed text-slate-600">
                        Busca publicações em que esta pessoa apareça como <strong>parte</strong> do
                        processo, mesmo em caso que você ainda não cadastrou.
                    </p>
                </div>
                <span
                    class="etiqueta shrink-0"
                    :class="vigilancia.ativa ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'"
                >
                    {{ vigilancia.ativa ? 'ligada' : 'desligada' }}
                </span>
            </div>

            <p v-if="vigilancia.ativa" class="mt-2 text-xs text-slate-500">
                {{ vigilancia.publicacoes }} publicação(ões) capturadas.
                <template v-if="vigilancia.ultima_sync_em">
                    Última busca {{ dataHora(vigilancia.ultima_sync_em) }}.
                </template>
            </p>

            <p v-if="vigilancia.cego" class="mt-2 rounded-xl bg-red-50 px-3 py-2 text-xs text-red-900 ring-1 ring-red-200">
                A varredura deste nome falhou duas vezes seguidas.
            </p>

            <button type="button" class="btn-secundario mt-3 w-full" @click="abrirVigilancia">
                {{ vigilancia.ativa ? 'Desligar vigilância' : 'Ligar vigilância' }}
            </button>

            <p class="mt-2 text-xs leading-relaxed text-slate-400">
                A busca é por nome. O DJEN não permite consultar por CPF ou CNPJ — o documento
                fica no cadastro para você conferir na triagem.
            </p>
        </section>

        <section v-if="publicacoes.length">
            <h2 class="mb-2 px-1 secao-titulo">
                Publicações em nome deste cliente
            </h2>

            <ul class="cartao divide-y divide-slate-100">
                <li v-for="publicacao in publicacoes" :key="publicacao.id">
                    <Link :href="`/publicacoes/${publicacao.id}`" class="block p-3.5">
                        <span class="flex items-baseline justify-between gap-2">
                            <span class="truncate text-sm font-medium text-slate-800">
                                {{ publicacao.tribunal ?? 'DJEN' }}
                            </span>
                            <span class="shrink-0 font-mono text-xs text-slate-500">
                                {{ dataCurta(publicacao.data_disponibilizacao) }}
                            </span>
                        </span>
                        <span class="mt-1 line-clamp-2 block text-sm leading-snug text-slate-600">
                            {{ publicacao.resumo }}
                        </span>
                        <span
                            v-if="publicacao.status_triagem === 'nova'"
                            class="etiqueta mt-1.5 bg-sky-100 text-sky-800"
                        >
                            aguardando triagem
                        </span>
                    </Link>
                </li>
            </ul>
        </section>

        <section>
            <h2 class="mb-2 px-1 secao-titulo">Casos</h2>

            <p v-if="!processos.length" class="cartao p-5 text-center text-sm text-slate-500">
                Nenhum caso vinculado ainda.
            </p>

            <ul v-else class="cartao divide-y divide-slate-100">
                <li v-for="processo in processos" :key="processo.id" class="p-3.5">
                    <div class="flex items-start justify-between gap-3">
                        <Link :href="`/casos/${processo.id}`" class="min-w-0 flex-1">
                            <span class="block truncate font-medium text-slate-900">{{ processo.rotulo }}</span>
                            <span class="block text-sm capitalize text-slate-500">
                                {{ processo.fase }}
                                <template v-if="processo.arquivado"> · arquivado</template>
                            </span>
                        </Link>

                        <button
                            type="button"
                            class="shrink-0 rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-semibold text-slate-700"
                            @click="gerarStatus(processo.id)"
                        >
                            copiar status
                        </button>
                    </div>

                    <p v-if="processo.proxima_acao" class="mt-1.5 line-clamp-2 text-sm text-amber-800">
                        {{ processo.proxima_acao }}
                    </p>
                </li>
            </ul>
        </section>

        <section>
            <div class="mb-2 flex items-baseline justify-between px-1">
                <h2 class="secao-titulo">Atendimentos</h2>
                <button type="button" class="text-sm font-medium text-sky-700" @click="folhaAtendimento = true">
                    registrar
                </button>
            </div>

            <p v-if="!atendimentos.length" class="cartao p-5 text-center text-sm text-slate-500">
                Nenhum atendimento registrado.
            </p>

            <ul v-else class="cartao divide-y divide-slate-100">
                <li v-for="item in atendimentos" :key="item.id" class="p-3.5">
                    <p class="flex items-baseline justify-between gap-2">
                        <span class="text-sm font-medium text-slate-800">{{ ROTULO_CANAL[item.canal] }}</span>
                        <span class="font-mono text-xs text-slate-500">{{ dataCurta(item.data) }}</span>
                    </p>
                    <p class="mt-1 whitespace-pre-wrap text-sm leading-relaxed text-slate-600">{{ item.resumo }}</p>
                    <p v-if="item.processo" class="mt-1 truncate text-xs text-slate-400">{{ item.processo }}</p>
                </li>
            </ul>
        </section>
    </div>

    <Folha :aberta="folhaAtendimento" titulo="Registrar atendimento" @fechar="folhaAtendimento = false">
        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="rotulo" for="data">Data</label>
                    <input id="data" v-model="atendimento.data" type="date" class="campo">
                </div>
                <div>
                    <label class="rotulo" for="canal">Canal</label>
                    <select id="canal" v-model="atendimento.canal" class="campo">
                        <option v-for="(rotulo, chave) in ROTULO_CANAL" :key="chave" :value="chave">{{ rotulo }}</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="rotulo" for="processo">Caso</label>
                <select id="processo" v-model="atendimento.processo_id" class="campo">
                    <option :value="null">Sem caso específico</option>
                    <option v-for="processo in processos" :key="processo.id" :value="processo.id">
                        {{ processo.rotulo }}
                    </option>
                </select>
            </div>

            <div>
                <label class="rotulo" for="resumo">O que foi conversado</label>
                <textarea id="resumo" v-model="atendimento.resumo" rows="5" class="campo" />
                <p v-if="atendimento.errors.resumo" class="mt-1 text-sm text-red-600">{{ atendimento.errors.resumo }}</p>
            </div>
        </div>

        <template #rodape>
            <button type="button" class="btn-primario w-full" :disabled="atendimento.processing" @click="salvarAtendimento">
                Salvar
            </button>
        </template>
    </Folha>

    <Folha :aberta="folhaEdicao" titulo="Editar cliente" @fechar="folhaEdicao = false">
        <div class="space-y-4">
            <div>
                <label class="rotulo" for="ed-nome">Nome completo</label>
                <input id="ed-nome" v-model="edicao.nome" type="text" class="campo">
                <p v-if="edicao.errors.nome" class="mt-1 text-sm text-red-600">{{ edicao.errors.nome }}</p>
            </div>

            <div>
                <label class="rotulo" for="ed-doc">CPF ou CNPJ</label>
                <input id="ed-doc" v-model="edicao.documento" type="text" inputmode="numeric" class="campo">
                <p class="mt-1 text-xs text-slate-500">
                    Pode digitar com ou sem pontuação — o app guarda dos dois jeitos.
                </p>
                <p v-if="edicao.errors.documento" class="mt-1 text-sm text-red-600">{{ edicao.errors.documento }}</p>
            </div>

            <div>
                <label class="rotulo" for="ed-nasc">Nascimento</label>
                <input id="ed-nasc" v-model="edicao.nascimento" type="date" class="campo">
            </div>

            <div>
                <span class="rotulo">Contatos</span>
                <div v-for="(contato, indice) in edicao.contatos" :key="indice" class="mb-2 flex gap-2">
                    <select v-model="contato.tipo" class="campo w-32 shrink-0">
                        <option value="whatsapp">WhatsApp</option>
                        <option value="telefone">Telefone</option>
                        <option value="email">E-mail</option>
                        <option value="outro">Outro</option>
                    </select>
                    <input v-model="contato.valor" type="text" class="campo" placeholder="(92) 90000-0000">
                    <button
                        v-if="edicao.contatos.length > 1"
                        type="button"
                        class="shrink-0 rounded-xl px-3 text-slate-400"
                        :aria-label="`Remover contato ${indice + 1}`"
                        @click="removerContato(indice)"
                    >
                        <Icone nome="x" class="h-5 w-5" />
                    </button>
                </div>
                <button type="button" class="text-sm font-medium text-sky-700" @click="adicionarContato">
                    + adicionar contato
                </button>
            </div>

            <details class="rounded-2xl bg-white p-4 ring-1 ring-slate-200" open>
                <summary class="cursor-pointer text-sm font-medium text-slate-700">Endereço</summary>
                <div class="mt-3 space-y-3">
                    <input v-model="edicao.endereco.logradouro" type="text" class="campo" placeholder="Rua">
                    <div class="grid grid-cols-2 gap-3">
                        <input v-model="edicao.endereco.numero" type="text" class="campo" placeholder="Número">
                        <input v-model="edicao.endereco.bairro" type="text" class="campo" placeholder="Bairro">
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <input v-model="edicao.endereco.cidade" type="text" class="campo col-span-2" placeholder="Cidade">
                        <input v-model="edicao.endereco.uf" type="text" maxlength="2" class="campo uppercase" placeholder="UF">
                    </div>
                    <input v-model="edicao.endereco.cep" type="text" class="campo" placeholder="CEP">
                </div>
            </details>

            <div>
                <label class="rotulo" for="ed-origem">Como chegou até você</label>
                <input id="ed-origem" v-model="edicao.origem" type="text" class="campo">
            </div>

            <div>
                <label class="rotulo" for="ed-obs">Observações</label>
                <textarea id="ed-obs" v-model="edicao.observacoes" rows="4" class="campo" />
            </div>

            <p class="text-xs leading-relaxed text-slate-500">
                Mudar o nome aqui não renomeia o termo de vigilância no DJEN. Se o nome estava
                errado, desligue e ligue a vigilância de novo para ela passar a buscar o nome novo.
            </p>
        </div>

        <template #rodape>
            <button type="button" class="btn-primario w-full" :disabled="edicao.processing" @click="salvarEdicao">
                {{ edicao.processing ? 'Salvando…' : 'Salvar alterações' }}
            </button>
        </template>
    </Folha>

    <Folha
        :aberta="folhaVigilancia"
        :titulo="vigilancia.ativa ? 'Desligar vigilância' : 'Ligar vigilância'"
        @fechar="folhaVigilancia = false"
    >
        <div class="space-y-4">
            <template v-if="vigilancia.ativa">
                <p class="text-sm leading-relaxed text-slate-600">
                    Ao desligar, o app para de buscar publicações no nome de
                    <strong>{{ cliente.nome }}</strong>. As {{ vigilancia.publicacoes }} já
                    capturadas continuam no inbox — publicação não se apaga.
                </p>
            </template>

            <template v-else>
                <p class="text-sm leading-relaxed text-slate-600">
                    O app vai buscar, todo dia, publicações em que
                    <strong>{{ cliente.nome }}</strong> apareça como parte.
                </p>

                <p v-if="carregandoPrevia" class="cartao p-4 text-center text-sm text-slate-500">
                    Consultando o DJEN para ver quantas viriam…
                </p>

                <div
                    v-else-if="previa && !previa.erro"
                    class="rounded-2xl p-4 ring-1"
                    :class="previa.recomendado
                        ? 'bg-emerald-50 text-emerald-900 ring-emerald-200'
                        : 'bg-amber-50 text-amber-900 ring-amber-200'"
                >
                    <p class="text-2xl font-bold">{{ previa.quantidade }}</p>
                    <p class="text-xs font-medium opacity-80">publicações nos últimos 30 dias</p>
                    <p class="mt-2 text-sm leading-relaxed">{{ previa.mensagem }}</p>
                </div>

                <p v-else-if="previa?.erro" class="rounded-xl bg-red-50 p-3 text-sm text-red-800 ring-1 ring-red-200">
                    {{ previa.erro }}
                </p>

                <p class="text-xs leading-relaxed text-slate-500">
                    Homônimo é inevitável numa busca por nome: pessoas diferentes com o mesmo nome
                    caem no mesmo resultado. Por isso tudo entra na triagem e nada vira prazo sozinho.
                </p>
            </template>
        </div>

        <template #rodape>
            <button
                type="button"
                class="w-full"
                :class="vigilancia.ativa ? 'btn-secundario' : 'btn-primario'"
                @click="alternarVigilancia"
            >
                {{ vigilancia.ativa ? 'Desligar' : 'Ligar vigilância' }}
            </button>
        </template>
    </Folha>

    <Folha :aberta="folhaStatus" titulo="Status para o cliente" @fechar="folhaStatus = false">
        <p class="mb-3 text-sm leading-relaxed text-slate-600">
            Texto pronto com a situação atual do caso. Confira antes de enviar — é você quem assina.
        </p>

        <p v-if="carregandoStatus" class="cartao p-5 text-center text-sm text-slate-500">Montando…</p>

        <textarea v-else v-model="textoStatus" rows="14" class="campo font-normal leading-relaxed" />

        <template #rodape>
            <div class="flex gap-2">
                <button type="button" class="btn-secundario flex-1" @click="copiar">
                    <Icone :nome="copiado ? 'check' : 'documento'" class="h-4 w-4" />
                    {{ copiado ? 'Copiado' : 'Copiar' }}
                </button>
                <a v-if="telefone" :href="linkWhatsapp()" target="_blank" rel="noopener" class="btn-primario flex-1">
                    Abrir no WhatsApp
                </a>
            </div>
        </template>
    </Folha>
</template>
