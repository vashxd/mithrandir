<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { dataCurta } from '../../formato';
import Cabecalho from '../../Components/Cabecalho.vue';
import Folha from '../../Components/Folha.vue';

/**
 * RF-1.7: teor completo, tribunal, órgão e data de disponibilização.
 * A publicação é imutável — esta tela é só leitura e decisão.
 */
const props = defineProps({
    publicacao: { type: Object, required: true },
    tipos_prazo: { type: Array, default: () => [] },
    processos: { type: Array, default: () => [] },
});

const folhaAberta = ref(false);

const formulario = useForm({
    decisao: 'prazo',
    processo_id: props.publicacao.processo?.id ?? null,
    criar_processo: !props.publicacao.processo && !!props.publicacao.numero_processo,
    tipo_prazo_id: null,
    tipo: '',
    dias: 15,
    em_dias_uteis: true,
    multiplicador: 1,
    multiplicador_motivo: '',
    observacoes: '',
});

function aoEscolherTipo(id) {
    const tipo = props.tipos_prazo.find((t) => t.id === Number(id));
    if (!tipo) return;
    formulario.tipo = tipo.nome;
    formulario.dias = tipo.dias;
    formulario.em_dias_uteis = tipo.em_dias_uteis;
}

function decidir(decisao) {
    router.post(`/publicacoes/${props.publicacao.id}/triar`, { decisao });
}

function enviar() {
    formulario.post(`/publicacoes/${props.publicacao.id}/triar`, {
        onSuccess: () => (folhaAberta.value = false),
    });
}

const ROTULO_STATUS = {
    nova: 'Não triada',
    prazo: 'Virou prazo',
    ciencia: 'Ciência, sem prazo',
    descartada: 'Descartada (arquivada)',
};
</script>

<template>
    <Head title="Publicação" />

    <Cabecalho titulo="Publicação" :subtitulo="publicacao.tribunal" voltar-para="/publicacoes" estreito />

    <div class="pagina-estreita space-y-3 px-4 lg:px-8 py-4 pb-8">
        <div class="cartao p-4">
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div>
                    <dt class="text-xs font-medium text-slate-500">Disponibilização</dt>
                    <dd class="font-mono font-semibold text-slate-900">
                        {{ dataCurta(publicacao.data_disponibilizacao) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-slate-500">Situação</dt>
                    <dd class="font-semibold text-slate-900">{{ ROTULO_STATUS[publicacao.status_triagem] }}</dd>
                </div>
                <div v-if="publicacao.orgao" class="col-span-2">
                    <dt class="text-xs font-medium text-slate-500">Órgão</dt>
                    <dd class="text-slate-800">{{ publicacao.orgao }}</dd>
                </div>
                <div v-if="publicacao.numero_formatado" class="col-span-2">
                    <dt class="text-xs font-medium text-slate-500">Processo</dt>
                    <dd class="font-mono text-slate-800">{{ publicacao.numero_formatado }}</dd>
                </div>
                <div v-if="publicacao.tipo_comunicacao">
                    <dt class="text-xs font-medium text-slate-500">Tipo</dt>
                    <dd class="text-slate-800">{{ publicacao.tipo_comunicacao }}</dd>
                </div>
                <div v-if="publicacao.termo_origem" class="col-span-2">
                    <dt class="text-xs font-medium text-slate-500">Capturada pela vigilância</dt>
                    <dd class="text-slate-800">{{ publicacao.termo_origem }}</dd>
                </div>
                <div v-if="publicacao.origem_vigilancia === 'cliente'" class="col-span-2">
                    <dt class="text-xs font-medium text-slate-500">Origem</dt>
                    <dd>
                        <span class="etiqueta bg-violet-100 text-violet-800">
                            nome do cliente: {{ publicacao.cliente_vigiado }}
                        </span>
                    </dd>
                </div>
            </dl>

            <Link
                v-if="publicacao.processo"
                :href="`/casos/${publicacao.processo.id}`"
                class="mt-3 block rounded-xl bg-slate-50 px-3 py-2.5 text-sm font-medium text-sky-700"
            >
                Ver o caso: {{ publicacao.processo.rotulo }}
            </Link>
        </div>

        <div class="cartao p-4">
            <h2 class="mb-2 secao-titulo">Teor completo</h2>
            <p class="whitespace-pre-wrap text-sm leading-relaxed text-slate-800">{{ publicacao.teor }}</p>
        </div>

        <div v-if="publicacao.advogados_intimados?.length" class="cartao p-4">
            <h2 class="mb-2 secao-titulo">
                Advogados intimados
            </h2>
            <ul class="space-y-1 text-sm text-slate-700">
                <li v-for="(advogado, indice) in publicacao.advogados_intimados" :key="indice">
                    {{ advogado }}
                </li>
            </ul>
            <p class="mt-2 text-xs leading-relaxed text-slate-400">
                Confira se você está nesta lista. Busca por nome traz homônimo.
            </p>
        </div>

        <div v-if="publicacao.destinatarios?.length" class="cartao p-4">
            <h2 class="mb-2 secao-titulo">Destinatários</h2>
            <ul class="space-y-1 text-sm text-slate-700">
                <li v-for="(destinatario, indice) in publicacao.destinatarios" :key="indice">
                    {{ destinatario.nome ?? destinatario.advogado_nome ?? JSON.stringify(destinatario) }}
                    <span v-if="destinatario.numero_oab" class="text-slate-500">
                        (OAB {{ destinatario.numero_oab }}{{ destinatario.uf_oab ? '/' + destinatario.uf_oab : '' }})
                    </span>
                </li>
            </ul>
        </div>

        <div v-if="publicacao.status_triagem === 'nova'" class="space-y-2 pt-2">
            <button type="button" class="btn-primario w-full" @click="folhaAberta = true">
                Virar prazo
            </button>
            <div class="flex gap-2">
                <button type="button" class="btn-secundario flex-1" @click="decidir('ciencia')">
                    Só ciência
                </button>
                <button type="button" class="btn-secundario flex-1" @click="decidir('descartada')">
                    Descartar
                </button>
            </div>
            <p class="pt-1 text-center text-xs leading-relaxed text-slate-400">
                Publicação descartada não é apagada: fica arquivada como prova de que o app a recebeu.
            </p>
        </div>
    </div>

    <Folha :aberta="folhaAberta" titulo="Virar prazo" @fechar="folhaAberta = false">
        <div class="space-y-4">
            <div v-if="!publicacao.processo">
                <label class="rotulo" for="processo">Vincular a um caso</label>
                <select id="processo" v-model="formulario.processo_id" class="campo">
                    <option :value="null">Nenhum por enquanto</option>
                    <option v-for="processo in processos" :key="processo.id" :value="processo.id">
                        {{ processo.rotulo }}
                    </option>
                </select>

                <label v-if="publicacao.numero_processo" class="mt-3 flex items-center gap-2.5 text-sm text-slate-700">
                    <input v-model="formulario.criar_processo" type="checkbox" class="h-5 w-5 rounded border-slate-300">
                    Ou criar um caso novo com o número desta publicação
                </label>
            </div>

            <div>
                <label class="rotulo" for="tipo-prazo">Tipo de prazo</label>
                <select id="tipo-prazo" v-model="formulario.tipo_prazo_id" class="campo" @change="aoEscolherTipo($event.target.value)">
                    <option :value="null">Escolher do catálogo…</option>
                    <option v-for="tipo in tipos_prazo" :key="tipo.id" :value="tipo.id">
                        {{ tipo.nome }} — {{ tipo.dias }} dias {{ tipo.em_dias_uteis ? 'úteis' : 'corridos' }}
                    </option>
                </select>
            </div>

            <div>
                <label class="rotulo" for="rotulo">Como chamar este prazo</label>
                <input id="rotulo" v-model="formulario.tipo" type="text" class="campo">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="rotulo" for="dias">Dias</label>
                    <input id="dias" v-model.number="formulario.dias" type="number" min="1" class="campo">
                </div>
                <div>
                    <label class="rotulo" for="contagem">Contagem</label>
                    <select id="contagem" v-model="formulario.em_dias_uteis" class="campo">
                        <option :value="true">Dias úteis</option>
                        <option :value="false">Dias corridos</option>
                    </select>
                </div>
            </div>

            <label class="flex items-start gap-2.5 rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                <input
                    type="checkbox"
                    class="mt-0.5 h-5 w-5 rounded border-slate-300"
                    :checked="formulario.multiplicador === 2"
                    @change="formulario.multiplicador = $event.target.checked ? 2 : 1"
                >
                <span>
                    <span class="block text-sm font-medium text-slate-800">Prazo em dobro</span>
                    <span class="block text-xs text-slate-500">Confirmação humana, nunca automático.</span>
                </span>
            </label>
        </div>

        <template #rodape>
            <button type="button" class="btn-primario w-full" :disabled="formulario.processing" @click="enviar">
                Criar prazo
            </button>
        </template>
    </Folha>
</template>
