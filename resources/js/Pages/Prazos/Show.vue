<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { dataCurta, dataLonga, contagem, CORES_CRITICIDADE, BARRA_CRITICIDADE, ROTULO_STATUS_PRAZO } from '../../formato';
import Cabecalho from '../../Components/Cabecalho.vue';
import CadeiaOrigem from '../../Components/CadeiaOrigem.vue';
import Folha from '../../Components/Folha.vue';

const props = defineProps({
    prazo: { type: Object, required: true },
    equipe: { type: Array, default: () => [] },
});

const contexto = computed(() => usePage().props.contexto ?? {});
const podeFechar = computed(() => contexto.value.permissoes?.cumprir_prazo !== false);

/* ---------------- Conferência ---------------- */

const folhaConferencia = ref(false);

const conferencia = useForm({ observacao: '' });

function pedirConferencia() {
    conferencia.post(`/prazos/${props.prazo.id}/conferencia`, {
        preserveScroll: true,
        onSuccess: () => {
            folhaConferencia.value = false;
            conferencia.reset();
        },
    });
}

function responderConferencia(aprovado) {
    router.post(
        `/prazos/${props.prazo.id}/conferencia/responder`,
        { aprovado, observacao: conferencia.observacao || null },
        { preserveScroll: true, onSuccess: () => conferencia.reset() }
    );
}

function definirResponsavel(id) {
    router.post(
        `/prazos/${props.prazo.id}/responsavel`,
        { responsavel_id: id },
        { preserveScroll: true }
    );
}

const folhaAjuste = ref(false);

const ajuste = useForm({
    data_fatal: props.prazo.data_fatal,
    justificativa: '',
});

function mudarStatus(status) {
    router.post(`/prazos/${props.prazo.id}/status`, { status }, { preserveScroll: true });
}

function enviarAjuste() {
    ajuste.post(`/prazos/${props.prazo.id}/ajustar`, {
        preserveScroll: true,
        onSuccess: () => (folhaAjuste.value = false),
    });
}
</script>

<template>
    <Head :title="prazo.tipo" />

    <Cabecalho :titulo="prazo.tipo" :subtitulo="prazo.processo?.rotulo" voltar-para="/prazos" estreito />

    <div class="pagina-estreita space-y-3 px-4 lg:px-8 py-4 pb-8">
        <!-- A data fatal é a informação mais importante da tela. -->
        <div class="overflow-hidden rounded-2xl ring-1" :class="CORES_CRITICIDADE[prazo.criticidade]">
            <div class="h-1.5" :class="BARRA_CRITICIDADE[prazo.criticidade]" />
            <div class="p-5 text-center">
                <p class="text-xs font-semibold uppercase tracking-wide opacity-70">Data fatal</p>
                <p class="mt-1 font-mono text-3xl font-bold">{{ dataCurta(prazo.data_fatal) }}</p>
                <p class="mt-0.5 text-sm opacity-80 first-letter:uppercase">{{ dataLonga(prazo.data_fatal) }}</p>
                <p class="mt-2 text-sm font-semibold">{{ contagem(prazo.dias_restantes) }}</p>

                <div class="mt-4 border-t border-current/10 pt-3">
                    <p class="text-xs font-medium opacity-70">Data-alvo interna (com folga de {{ prazo.buffer_dias }} dias)</p>
                    <p class="font-mono text-lg font-semibold">{{ dataCurta(prazo.data_alvo) }}</p>
                </div>
            </div>
        </div>

        <!-- Conferência pendente: o prazo NÃO fechou, e isso precisa ficar claro. -->
        <div
            v-if="prazo.aguardando_conferencia"
            class="rounded-2xl border border-violet-200 bg-violet-50 p-4"
        >
            <p class="text-sm font-semibold text-violet-900">
                Aguardando conferência
                <template v-if="prazo.conferencia_por"> — {{ prazo.conferencia_por }} marcou como feito</template>
            </p>
            <p v-if="prazo.conferencia_observacao" class="mt-1 text-sm leading-relaxed text-violet-900">
                “{{ prazo.conferencia_observacao }}”
            </p>
            <p class="mt-2 text-xs leading-relaxed text-violet-800">
                O prazo continua <strong>aberto</strong> até você confirmar. Quem responde por
                ele perante a OAB é você.
            </p>

            <div v-if="podeFechar" class="mt-3 space-y-2">
                <textarea
                    v-model="conferencia.observacao"
                    rows="2"
                    class="campo"
                    placeholder="Observação ao devolver (opcional)"
                />
                <div class="flex gap-2">
                    <button type="button" class="btn-secundario flex-1" @click="responderConferencia(false)">
                        Devolver
                    </button>
                    <button type="button" class="btn-primario flex-1" @click="responderConferencia(true)">
                        Confirmar e fechar
                    </button>
                </div>
            </div>
        </div>

        <div
            v-if="prazo.precisa_revisao"
            class="rounded-2xl border border-amber-200 bg-amber-50 p-4"
        >
            <p class="text-sm font-semibold text-amber-900">Este prazo precisa da sua revisão</p>
            <p class="mt-1 text-sm leading-relaxed text-amber-800">{{ prazo.revisao_motivo }}</p>
        </div>

        <div class="cartao p-4">
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div>
                    <dt class="text-xs font-medium text-slate-500">Status</dt>
                    <dd class="font-semibold text-slate-900">{{ ROTULO_STATUS_PRAZO[prazo.status] }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-slate-500">Contagem</dt>
                    <dd class="text-slate-800">
                        {{ prazo.dias }} dias {{ prazo.em_dias_uteis ? 'úteis' : 'corridos' }}
                        <span v-if="prazo.multiplicador > 1" class="font-semibold text-slate-900">× {{ prazo.multiplicador }}</span>
                    </dd>
                </div>
                <div v-if="prazo.multiplicador_motivo" class="col-span-2">
                    <dt class="text-xs font-medium text-slate-500">Motivo do prazo em dobro</dt>
                    <dd class="text-slate-800">{{ prazo.multiplicador_motivo }}</dd>
                </div>
                <div v-if="prazo.processo" class="col-span-2">
                    <dt class="text-xs font-medium text-slate-500">Caso</dt>
                    <dd>
                        <Link :href="`/casos/${prazo.processo.id}`" class="font-medium text-sky-700">
                            {{ prazo.processo.rotulo }}
                        </Link>
                        <span v-if="prazo.processo.cliente" class="text-slate-500"> · {{ prazo.processo.cliente }}</span>
                    </dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-xs font-medium text-slate-500">Responsável</dt>
                    <dd>
                        <select
                            class="campo mt-1"
                            :value="prazo.responsavel?.id ?? ''"
                            @change="definirResponsavel($event.target.value ? Number($event.target.value) : null)"
                        >
                            <option value="">Ninguém assumiu</option>
                            <option v-for="pessoa in equipe" :key="pessoa.id" :value="pessoa.id">
                                {{ pessoa.nome }}<template v-if="pessoa.papel !== 'titular'"> ({{ pessoa.papel }})</template>
                            </option>
                        </select>
                    </dd>
                </div>

                <div v-if="prazo.observacoes" class="col-span-2">
                    <dt class="text-xs font-medium text-slate-500">Observações</dt>
                    <dd class="whitespace-pre-wrap text-slate-800">{{ prazo.observacoes }}</dd>
                </div>
            </dl>
        </div>

        <!-- RF-2.7: a cadeia inteira, sempre aberta na tela do prazo. -->
        <CadeiaOrigem :cadeia="prazo.cadeia_origem" sempre-aberta />

        <div v-if="prazo.publicacao" class="cartao p-4">
            <h2 class="mb-2 secao-titulo">Publicação de origem</h2>
            <p class="line-clamp-4 text-sm leading-relaxed text-slate-700">{{ prazo.publicacao.teor }}</p>
            <Link :href="`/publicacoes/${prazo.publicacao.id}`" class="mt-2 inline-block text-sm font-medium text-sky-700">
                ver publicação completa
            </Link>
        </div>

        <div class="space-y-2 pt-2">
            <div v-if="['aberto', 'em_andamento'].includes(prazo.status)" class="flex gap-2">
                <button
                    v-if="prazo.status === 'aberto'"
                    type="button"
                    class="btn-secundario flex-1"
                    @click="mudarStatus('em_andamento')"
                >
                    Comecei
                </button>

                <button
                    v-if="podeFechar"
                    type="button"
                    class="btn-primario flex-1"
                    @click="mudarStatus('cumprido')"
                >
                    Cumpri
                </button>

                <!-- Sem permissão de fechar: marca como feito e vai para conferência. -->
                <button
                    v-else-if="!prazo.aguardando_conferencia"
                    type="button"
                    class="btn-primario flex-1"
                    @click="folhaConferencia = true"
                >
                    Fiz a minha parte
                </button>
            </div>

            <button
                v-else-if="podeFechar"
                type="button"
                class="btn-secundario w-full"
                @click="mudarStatus('aberto')"
            >
                Reabrir prazo
            </button>

            <button v-if="podeFechar" type="button" class="btn-secundario w-full" @click="folhaAjuste = true">
                Ajustar a data manualmente
            </button>

            <div v-if="podeFechar && ['aberto', 'em_andamento'].includes(prazo.status)" class="flex gap-2">
                <button type="button" class="btn-secundario flex-1 text-slate-500" @click="mudarStatus('prejudicado')">
                    Ficou prejudicado
                </button>
                <button type="button" class="btn-secundario flex-1 text-red-600" @click="mudarStatus('perdido')">
                    Perdi o prazo
                </button>
            </div>
        </div>
    </div>

    <Folha :aberta="folhaConferencia" titulo="Enviar para conferência" @fechar="folhaConferencia = false">
        <div class="space-y-4">
            <p class="rounded-2xl bg-violet-50 p-4 text-sm leading-relaxed text-violet-900 ring-1 ring-violet-200">
                Isto <strong>não fecha o prazo</strong>. Ele continua aberto e vermelho até
                {{ prazo.processo ? 'o titular' : 'o advogado responsável' }} conferir e confirmar —
                é quem responde por ele perante a OAB.
            </p>

            <div>
                <label class="rotulo" for="conf-obs">O que você fez</label>
                <textarea
                    id="conf-obs"
                    v-model="conferencia.observacao"
                    rows="4"
                    class="campo"
                    placeholder="Ex.: minuta da contestação pronta na pasta do caso, falta juntar o CNIS."
                />
            </div>
        </div>

        <template #rodape>
            <button
                type="button"
                class="btn-primario w-full"
                :disabled="conferencia.processing"
                @click="pedirConferencia"
            >
                Enviar para conferência
            </button>
        </template>
    </Folha>

    <Folha :aberta="folhaAjuste" titulo="Ajustar a data fatal" @fechar="folhaAjuste = false">
        <div class="space-y-4">
            <div class="rounded-2xl bg-slate-100 p-4">
                <p class="text-xs font-medium text-slate-500">Data calculada pelo app</p>
                <p class="font-mono text-lg font-semibold text-slate-900">
                    {{ dataCurta(prazo.data_fatal_calculada ?? prazo.data_fatal) }}
                </p>
                <p class="mt-2 text-xs leading-relaxed text-slate-600">
                    O valor calculado nunca é apagado. Ele fica registrado ao lado do seu ajuste,
                    junto com a justificativa e a data da alteração.
                </p>
            </div>

            <div>
                <label class="rotulo" for="nova-data">Nova data fatal</label>
                <input id="nova-data" v-model="ajuste.data_fatal" type="date" class="campo">
                <p v-if="ajuste.errors.data_fatal" class="mt-1 text-sm text-red-600">{{ ajuste.errors.data_fatal }}</p>
            </div>

            <div>
                <label class="rotulo" for="justificativa">Por que está mudando?</label>
                <textarea
                    id="justificativa"
                    v-model="ajuste.justificativa"
                    rows="4"
                    class="campo"
                    placeholder="Ex.: houve intimação pessoal em cartório no dia 12/03, que antecipou o início da contagem."
                />
                <p v-if="ajuste.errors.justificativa" class="mt-1 text-sm text-red-600">
                    {{ ajuste.errors.justificativa }}
                </p>
                <p class="mt-1 text-xs text-slate-500">Obrigatória. Fica no log de auditoria do prazo.</p>
            </div>
        </div>

        <template #rodape>
            <button type="button" class="btn-primario w-full" :disabled="ajuste.processing" @click="enviarAjuste">
                Salvar ajuste
            </button>
        </template>
    </Folha>
</template>
