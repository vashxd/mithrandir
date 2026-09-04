<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { dataCurta, dataHora } from '../../formato';
import Cabecalho from '../../Components/Cabecalho.vue';
import Folha from '../../Components/Folha.vue';
import Icone from '../../Components/Icone.vue';
import Vazio from '../../Components/Vazio.vue';

/**
 * Tela 2 — "O que o Judiciário me mandou?"
 *
 * RF-1.5: cada publicação vira prazo, ciência ou descarte. O gesto é o mesmo
 * do e-mail: arrastar para o lado resolve; tocar abre o teor completo.
 */
const props = defineProps({
    publicacoes: { type: Object, required: true },
    status: { type: String, required: true },
    contadores: { type: Object, required: true },
    tipos_prazo: { type: Array, default: () => [] },
    ultima_sync: { type: String, default: null },
});

const abas = computed(() => [
    { chave: 'nova', rotulo: 'Novas', total: props.contadores.nova },
    { chave: 'prazo', rotulo: 'Viraram prazo', total: props.contadores.prazo },
    { chave: 'ciencia', rotulo: 'Ciência', total: props.contadores.ciencia },
    { chave: 'descartada', rotulo: 'Descartadas', total: props.contadores.descartada },
]);

const sincronizando = ref(false);

function sincronizar() {
    sincronizando.value = true;
    router.post('/publicacoes/sincronizar', {}, {
        preserveScroll: true,
        onFinish: () => (sincronizando.value = false),
    });
}

function trocarAba(chave) {
    router.get('/publicacoes', { status: chave }, { preserveState: true, preserveScroll: true });
}

/* ---------------- Gesto de arrastar ---------------- */

const arrastando = ref(null);
const deslocamento = ref(0);
const LIMITE = 90;

function iniciarArrasto(evento, publicacao) {
    if (props.status !== 'nova') return;
    arrastando.value = { id: publicacao.id, x: evento.touches[0].clientX };
    deslocamento.value = 0;
}

function moverArrasto(evento) {
    if (!arrastando.value) return;
    deslocamento.value = evento.touches[0].clientX - arrastando.value.x;
}

function soltarArrasto(publicacao) {
    if (!arrastando.value) return;

    const delta = deslocamento.value;
    arrastando.value = null;
    deslocamento.value = 0;

    // Direita = virou prazo (abre o formulário, porque prazo exige decisão).
    if (delta > LIMITE) {
        abrirTriagem(publicacao, 'prazo');
        return;
    }

    // Esquerda = ciência, sem prazo. Ação de um toque, reversível pela aba.
    if (delta < -LIMITE) {
        decidir(publicacao, 'ciencia');
    }
}

function estiloDoCartao(publicacao) {
    if (arrastando.value?.id !== publicacao.id) return {};
    return { transform: `translateX(${deslocamento.value}px)` };
}

/* ---------------- Triagem ---------------- */

const folhaAberta = ref(false);
const publicacaoAtual = ref(null);

const formulario = useForm({
    decisao: 'prazo',
    processo_id: null,
    criar_processo: false,
    tipo_prazo_id: null,
    tipo: '',
    dias: 15,
    em_dias_uteis: true,
    multiplicador: 1,
    multiplicador_motivo: '',
    observacoes: '',
});

function abrirTriagem(publicacao, decisao = 'prazo') {
    publicacaoAtual.value = publicacao;
    formulario.defaults();
    formulario.reset();
    formulario.decisao = decisao;
    formulario.processo_id = publicacao.processo?.id ?? null;
    formulario.criar_processo = !publicacao.processo && !!publicacao.numero_processo;
    folhaAberta.value = true;
}

function aoEscolherTipo(id) {
    const tipo = props.tipos_prazo.find((t) => t.id === Number(id));
    if (!tipo) return;

    formulario.tipo = tipo.nome;
    formulario.dias = tipo.dias;
    formulario.em_dias_uteis = tipo.em_dias_uteis;
}

function decidir(publicacao, decisao) {
    router.post(
        `/publicacoes/${publicacao.id}/triar`,
        { decisao },
        { preserveScroll: true }
    );
}

function enviarTriagem() {
    formulario.post(`/publicacoes/${publicacaoAtual.value.id}/triar`, {
        preserveScroll: true,
        onSuccess: () => {
            folhaAberta.value = false;
            publicacaoAtual.value = null;
        },
    });
}
</script>

<template>
    <Head title="Publicações" />

    <Cabecalho titulo="Publicações" :subtitulo="ultima_sync ? `última busca ${dataHora(ultima_sync)}` : 'nenhuma busca ainda'">
        <template #acoes>
            <button
                type="button"
                class="flex h-11 w-11 items-center justify-center rounded-full text-slate-600 active:bg-slate-100 disabled:opacity-40"
                :disabled="sincronizando"
                aria-label="Sincronizar agora"
                @click="sincronizar"
            >
                <Icone nome="sincronizar" class="h-6 w-6" :class="sincronizando ? 'animate-spin' : ''" />
            </button>
        </template>
    </Cabecalho>

    <div class="pagina">
        <div class="flex gap-2 overflow-x-auto px-4 py-3 lg:flex-wrap lg:overflow-visible">
            <button
                v-for="aba in abas"
                :key="aba.chave"
                type="button"
                class="shrink-0 rounded-full px-3.5 py-2 text-sm font-medium transition"
                :class="status === aba.chave
                    ? 'bg-slate-900 text-white'
                    : 'bg-white text-slate-600 ring-1 ring-slate-200'"
                @click="trocarAba(aba.chave)"
            >
                {{ aba.rotulo }}
                <span v-if="aba.total" class="ml-1 opacity-70">{{ aba.total }}</span>
            </button>
        </div>

        <p v-if="status === 'nova' && publicacoes.data.length" class="px-4 pb-2 text-xs text-slate-500 lg:hidden">
            Arraste para a direita para virar prazo, para a esquerda para dar ciência.
        </p>

        <Vazio
            v-if="!publicacoes.data.length"
            :titulo="status === 'nova' ? 'Nenhuma publicação para triar' : 'Nada por aqui'"
            :texto="status === 'nova'
                ? 'A varredura do DJEN roda todo dia às 6h. Você também pode buscar agora pelo botão no topo.'
                : 'Mude a aba para ver publicações em outro estado.'"
        />

        <ul v-else class="space-y-2 px-4 pb-8 lg:grid lg:grid-cols-2 lg:items-start lg:gap-3 lg:space-y-0 xl:grid-cols-3">
            <li v-for="publicacao in publicacoes.data" :key="publicacao.id" class="relative overflow-hidden rounded-2xl">
                <!-- Pistas do gesto, reveladas conforme o cartão sai do lugar. -->
                <div class="absolute inset-0 flex items-center justify-between bg-slate-100 px-5 text-sm font-semibold">
                    <span class="text-emerald-700">virar prazo</span>
                    <span class="text-slate-600">ciência</span>
                </div>

                <div
                    class="relative cartao transition-transform"
                    :style="estiloDoCartao(publicacao)"
                    @touchstart="iniciarArrasto($event, publicacao)"
                    @touchmove="moverArrasto"
                    @touchend="soltarArrasto(publicacao)"
                >
                    <Link :href="`/publicacoes/${publicacao.id}`" class="block p-4">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="truncate text-sm font-semibold text-slate-800">
                                {{ publicacao.tribunal ?? 'DJEN' }}
                            </span>
                            <span class="shrink-0 font-mono text-xs text-slate-500">
                                {{ dataCurta(publicacao.data_disponibilizacao) }}
                            </span>
                        </div>

                        <p v-if="publicacao.orgao" class="truncate text-xs text-slate-500">
                            {{ publicacao.orgao }}
                        </p>

                        <p v-if="publicacao.numero_formatado" class="mt-1 font-mono text-xs text-slate-600">
                            {{ publicacao.numero_formatado }}
                        </p>

                        <p class="mt-2 line-clamp-3 text-sm leading-relaxed text-slate-600">
                            {{ publicacao.resumo }}
                        </p>

                        <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                            <!-- De quem e esta publicacao: sua OAB ou o nome de um cliente. -->
                            <span
                                v-if="publicacao.origem_vigilancia === 'cliente'"
                                class="etiqueta bg-violet-100 text-violet-800"
                            >
                                cliente: {{ publicacao.cliente_vigiado }}
                            </span>
                            <span v-else class="etiqueta bg-slate-100 text-slate-600">
                                sua OAB
                            </span>

                            <span
                                v-if="publicacao.processo"
                                class="etiqueta bg-slate-100 text-slate-700"
                            >
                                {{ publicacao.processo.cliente ?? publicacao.processo.rotulo }}
                            </span>
                            <span
                                v-else-if="publicacao.numero_processo"
                                class="etiqueta bg-amber-100 text-amber-900"
                            >
                                caso não cadastrado
                            </span>
                        </div>
                    </Link>

                    <div v-if="publicacao.status_triagem === 'nova'" class="flex gap-2 border-t border-slate-100 p-3">
                        <button type="button" class="btn-primario flex-1" @click="abrirTriagem(publicacao, 'prazo')">
                            Virar prazo
                        </button>
                        <button type="button" class="btn-secundario flex-1" @click="decidir(publicacao, 'ciencia')">
                            Ciência
                        </button>
                        <button
                            type="button"
                            class="btn-secundario px-3 text-slate-500"
                            aria-label="Descartar"
                            @click="decidir(publicacao, 'descartada')"
                        >
                            <Icone nome="x" class="h-5 w-5" />
                        </button>
                    </div>
                </div>
            </li>
        </ul>

        <div v-if="publicacoes.links?.length > 3" class="flex justify-center gap-1 px-4 pb-8">
            <Link
                v-for="link in publicacoes.links"
                :key="link.label"
                :href="link.url ?? '#'"
                class="min-w-11 rounded-lg px-3 py-2 text-center text-sm"
                :class="[
                    link.active ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200',
                    !link.url ? 'pointer-events-none opacity-40' : '',
                ]"
                v-html="link.label"
            />
        </div>
    </div>

    <!-- Formulário de triagem em tela cheia: prazo tem mais de dois campos. -->
    <Folha :aberta="folhaAberta" titulo="Virar prazo" @fechar="folhaAberta = false">
        <div v-if="publicacaoAtual" class="space-y-4">
            <div class="cartao p-4">
                <p class="text-xs font-medium text-slate-500">
                    Disponibilizada em {{ dataCurta(publicacaoAtual.data_disponibilizacao) }}
                    · {{ publicacaoAtual.tribunal }}
                </p>
                <p class="mt-2 line-clamp-4 text-sm leading-relaxed text-slate-700">
                    {{ publicacaoAtual.resumo }}
                </p>
                <Link :href="`/publicacoes/${publicacaoAtual.id}`" class="mt-2 inline-block text-sm font-medium text-sky-700">
                    ler o teor completo
                </Link>
            </div>

            <div v-if="!publicacaoAtual.processo && publicacaoAtual.numero_processo" class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm font-semibold text-amber-900">Caso não cadastrado</p>
                <p class="mt-1 font-mono text-xs text-amber-800">{{ publicacaoAtual.numero_formatado }}</p>
                <label class="mt-3 flex items-center gap-2.5 text-sm text-amber-900">
                    <input v-model="formulario.criar_processo" type="checkbox" class="h-5 w-5 rounded border-amber-300">
                    Criar o caso automaticamente com estes dados
                </label>
            </div>

            <div>
                <label class="rotulo" for="tipo-prazo">Tipo de prazo</label>
                <select
                    id="tipo-prazo"
                    v-model="formulario.tipo_prazo_id"
                    class="campo"
                    @change="aoEscolherTipo($event.target.value)"
                >
                    <option :value="null">Escolher do catálogo…</option>
                    <option v-for="tipo in tipos_prazo" :key="tipo.id" :value="tipo.id">
                        {{ tipo.nome }} — {{ tipo.dias }} dias {{ tipo.em_dias_uteis ? 'úteis' : 'corridos' }}
                    </option>
                </select>
            </div>

            <div>
                <label class="rotulo" for="rotulo-prazo">Como chamar este prazo</label>
                <input id="rotulo-prazo" v-model="formulario.tipo" type="text" class="campo" placeholder="Contestação">
                <p v-if="formulario.errors.tipo" class="mt-1 text-sm text-red-600">{{ formulario.errors.tipo }}</p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="rotulo" for="dias">Dias</label>
                    <input id="dias" v-model.number="formulario.dias" type="number" min="1" max="365" class="campo">
                </div>
                <div>
                    <label class="rotulo" for="contagem">Contagem</label>
                    <select id="contagem" v-model="formulario.em_dias_uteis" class="campo">
                        <option :value="true">Dias úteis</option>
                        <option :value="false">Dias corridos</option>
                    </select>
                </div>
            </div>

            <div class="rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                <label class="flex items-start gap-2.5">
                    <input
                        type="checkbox"
                        class="mt-0.5 h-5 w-5 rounded border-slate-300"
                        :checked="formulario.multiplicador === 2"
                        @change="formulario.multiplicador = $event.target.checked ? 2 : 1"
                    >
                    <span>
                        <span class="block text-sm font-medium text-slate-800">Prazo em dobro</span>
                        <span class="block text-xs leading-relaxed text-slate-500">
                            Fazenda Pública, Defensoria ou Ministério Público (CPC arts. 180, 183, 186).
                            Nunca aplicado automaticamente — a confirmação é sua.
                        </span>
                    </span>
                </label>

                <input
                    v-if="formulario.multiplicador === 2"
                    v-model="formulario.multiplicador_motivo"
                    type="text"
                    class="campo mt-3"
                    placeholder="Motivo (ex.: réu é o INSS)"
                >
            </div>

            <div>
                <label class="rotulo" for="obs">Observações</label>
                <textarea id="obs" v-model="formulario.observacoes" rows="3" class="campo" placeholder="O que precisa ser feito neste prazo" />
            </div>
        </div>

        <template #rodape>
            <button
                type="button"
                class="btn-primario w-full"
                :disabled="formulario.processing"
                @click="enviarTriagem"
            >
                {{ formulario.processing ? 'Calculando…' : 'Criar prazo' }}
            </button>
        </template>
    </Folha>
</template>
