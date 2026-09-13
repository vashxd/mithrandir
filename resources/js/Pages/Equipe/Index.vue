<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { dataHora } from '../../formato';
import Cabecalho from '../../Components/Cabecalho.vue';
import Folha from '../../Components/Folha.vue';
import Icone from '../../Components/Icone.vue';
import Vazio from '../../Components/Vazio.vue';

/**
 * Equipe: quem trabalha no espaço do titular, e em quais casos.
 *
 * O acesso nasce por caso de propósito — sigilo profissional é do cliente
 * (EOAB art. 34), e um estagiário raramente precisa ver a carteira inteira.
 */
const props = defineProps({
    membros: { type: Array, default: () => [] },
    processos: { type: Array, default: () => [] },
});

const folhaConvite = ref(false);
const folhaAcesso = ref(false);
const membroEmEdicao = ref(null);
const linkCopiado = ref(null);

const convite = useForm({
    nome: '',
    email: '',
    papel: 'estagiario',
    acesso_total: false,
    processos: [],
});

function enviarConvite() {
    convite.post('/equipe', {
        preserveScroll: true,
        onSuccess: () => {
            folhaConvite.value = false;
            convite.reset();
        },
    });
}

const acesso = useForm({
    papel: 'estagiario',
    ativo: true,
    acesso_total: false,
    processos: [],
});

function abrirAcesso(membro) {
    membroEmEdicao.value = membro;
    acesso.papel = membro.papel;
    acesso.ativo = membro.ativo;
    acesso.acesso_total = membro.acesso_total;
    acesso.processos = membro.processos.map((p) => p.id);
    folhaAcesso.value = true;
}

function salvarAcesso() {
    acesso.patch(`/equipe/${membroEmEdicao.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (folhaAcesso.value = false),
    });
}

function remover(membro) {
    router.delete(`/equipe/${membro.id}`, { preserveScroll: true });
}

async function copiarLink(membro) {
    try {
        await navigator.clipboard.writeText(membro.link_convite);
        linkCopiado.value = membro.id;
        setTimeout(() => (linkCopiado.value = null), 2500);
    } catch {
        linkCopiado.value = null;
    }
}

const temMembros = computed(() => props.membros.length > 0);

const DESCRICAO_PAPEL = {
    advogado: 'Trabalha como você: cumpre prazo, vê financeiro. Não mexe na sua conta nem no radar.',
    estagiario: 'Vê, prepara e anota. Não fecha prazo e não vê dinheiro.',
};
</script>

<template>
    <Head title="Equipe" />

    <Cabecalho titulo="Equipe" subtitulo="quem mais trabalha nos seus casos">
        <template #acoes>
            <button
                type="button"
                class="flex h-11 w-11 items-center justify-center rounded-full bg-acao text-sobre-acao"
                aria-label="Convidar pessoa"
                @click="folhaConvite = true"
            >
                <Icone nome="mais_circulo" class="h-5 w-5" />
            </button>
        </template>
    </Cabecalho>

    <div class="pagina space-y-3 px-4 lg:px-8 py-4 pb-8">
        <p class="rounded-2xl bg-superficie p-4 text-sm leading-relaxed text-tinta-2 ring-1 ring-borda">
            Quem você convida entra no <strong>seu</strong> espaço: os casos continuam seus, e
            tudo que a pessoa fizer fica registrado no nome dela.
            <strong>Estagiário não fecha prazo</strong> — ele marca como feito e o prazo
            segue aberto até você conferir.
        </p>

        <Vazio
            v-if="!temMembros"
            titulo="Ninguém na equipe ainda"
            texto="Convide um estagiário ou colega e escolha quais casos ele acompanha."
        >
            <button type="button" class="btn-primario" @click="folhaConvite = true">
                Convidar alguém
            </button>
        </Vazio>

        <ul v-else class="space-y-2">
            <li v-for="membro in membros" :key="membro.id" class="cartao overflow-hidden">
                <div class="flex items-start gap-3 p-4">
                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="truncate font-semibold text-tinta">{{ membro.nome }}</span>
                            <span
                                class="etiqueta"
                                :class="membro.papel === 'advogado'
                                    ? 'bg-acento-fundo text-acento-tinta'
                                    : 'bg-superficie-2 text-tinta-2'"
                            >
                                {{ membro.papel_rotulo }}
                            </span>
                            <span v-if="!membro.ativo" class="etiqueta bg-superficie-3 text-tinta-2">
                                desativado
                            </span>
                            <span v-else-if="membro.pendente" class="etiqueta bg-atencao-fundo text-atencao-tinta">
                                convite pendente
                            </span>
                        </span>

                        <span class="mt-0.5 block truncate text-sm text-tinta-3">{{ membro.email }}</span>

                        <span class="mt-1 block text-xs text-tinta-3">
                            <template v-if="membro.acesso_total">Acesso à carteira inteira</template>
                            <template v-else-if="membro.processos.length">
                                {{ membro.processos.length }}
                                {{ membro.processos.length === 1 ? 'caso liberado' : 'casos liberados' }}
                            </template>
                            <template v-else>Nenhum caso liberado ainda</template>
                            <template v-if="membro.ultimo_acesso_em">
                                · último acesso {{ dataHora(membro.ultimo_acesso_em) }}
                            </template>
                        </span>
                    </span>

                    <button
                        type="button"
                        class="shrink-0 rounded-lg bg-superficie-2 px-3 py-2 text-xs font-semibold text-tinta-2"
                        @click="abrirAcesso(membro)"
                    >
                        acesso
                    </button>
                </div>

                <div v-if="membro.link_convite" class="border-t border-borda-sutil bg-atencao-fundo px-4 py-3">
                    <p class="text-xs leading-relaxed text-atencao-tinta">
                        Envie este link para a pessoa. Ela precisa entrar com o e-mail
                        <strong>{{ membro.email }}</strong> para aceitar.
                    </p>
                    <button type="button" class="btn-secundario mt-2 w-full" @click="copiarLink(membro)">
                        <Icone :nome="linkCopiado === membro.id ? 'check' : 'documento'" class="h-4 w-4" />
                        {{ linkCopiado === membro.id ? 'Link copiado' : 'Copiar link do convite' }}
                    </button>
                </div>

                <ul
                    v-if="!membro.acesso_total && membro.processos.length"
                    class="border-t border-borda-sutil px-4 py-2"
                >
                    <li
                        v-for="processo in membro.processos"
                        :key="processo.id"
                        class="truncate py-1 text-xs text-tinta-3"
                    >
                        {{ processo.rotulo }}
                    </li>
                </ul>
            </li>
        </ul>
    </div>

    <Folha :aberta="folhaConvite" titulo="Convidar para a equipe" @fechar="folhaConvite = false">
        <div class="space-y-4">
            <div>
                <label class="rotulo" for="cv-nome">Nome</label>
                <input id="cv-nome" v-model="convite.nome" type="text" class="campo">
                <p v-if="convite.errors.nome" class="mt-1 text-sm text-perigo">{{ convite.errors.nome }}</p>
            </div>

            <div>
                <label class="rotulo" for="cv-email">E-mail</label>
                <input id="cv-email" v-model="convite.email" type="email" class="campo">
                <p v-if="convite.errors.email" class="mt-1 text-sm text-perigo">{{ convite.errors.email }}</p>
                <p v-else class="mt-1 text-xs text-tinta-3">
                    A pessoa precisa entrar com este e-mail para aceitar o convite.
                </p>
            </div>

            <div>
                <span class="rotulo">Papel</span>
                <label
                    v-for="papel in ['estagiario', 'advogado']"
                    :key="papel"
                    class="mb-2 flex items-start gap-2.5 rounded-2xl bg-superficie p-4 ring-1"
                    :class="convite.papel === papel ? 'ring-acao' : 'ring-borda'"
                >
                    <input v-model="convite.papel" type="radio" :value="papel" class="mt-0.5 h-5 w-5">
                    <span>
                        <span class="block text-sm font-medium capitalize text-tinta">{{ papel }}</span>
                        <span class="block text-xs leading-relaxed text-tinta-3">
                            {{ DESCRICAO_PAPEL[papel] }}
                        </span>
                    </span>
                </label>
            </div>

            <label class="flex items-start gap-2.5 rounded-2xl bg-superficie p-4 ring-1 ring-borda">
                <input v-model="convite.acesso_total" type="checkbox" class="mt-0.5 h-5 w-5 rounded border-borda-forte">
                <span>
                    <span class="block text-sm font-medium text-tinta">Acesso a todos os casos</span>
                    <span class="block text-xs leading-relaxed text-tinta-3">
                        Sem isto, a pessoa só enxerga os casos que você marcar abaixo — e nada dos
                        outros clientes.
                    </span>
                </span>
            </label>

            <div v-if="!convite.acesso_total">
                <span class="rotulo">Casos liberados</span>
                <p v-if="!processos.length" class="text-sm text-tinta-3">
                    Você ainda não tem casos cadastrados.
                </p>
                <label
                    v-for="processo in processos"
                    :key="processo.id"
                    class="mb-1.5 flex items-center gap-2.5 rounded-xl bg-superficie p-3 ring-1 ring-borda"
                >
                    <input
                        v-model="convite.processos"
                        type="checkbox"
                        :value="processo.id"
                        class="h-5 w-5 shrink-0 rounded border-borda-forte"
                    >
                    <span class="min-w-0 truncate text-sm text-tinta">{{ processo.rotulo }}</span>
                </label>
            </div>
        </div>

        <template #rodape>
            <button type="button" class="btn-primario w-full" :disabled="convite.processing" @click="enviarConvite">
                Gerar convite
            </button>
        </template>
    </Folha>

    <Folha
        :aberta="folhaAcesso"
        :titulo="membroEmEdicao ? `Acesso de ${membroEmEdicao.nome}` : 'Acesso'"
        @fechar="folhaAcesso = false"
    >
        <div v-if="membroEmEdicao" class="space-y-4">
            <div>
                <span class="rotulo">Papel</span>
                <label
                    v-for="papel in ['estagiario', 'advogado']"
                    :key="papel"
                    class="mb-2 flex items-start gap-2.5 rounded-2xl bg-superficie p-4 ring-1"
                    :class="acesso.papel === papel ? 'ring-acao' : 'ring-borda'"
                >
                    <input v-model="acesso.papel" type="radio" :value="papel" class="mt-0.5 h-5 w-5">
                    <span>
                        <span class="block text-sm font-medium capitalize text-tinta">{{ papel }}</span>
                        <span class="block text-xs leading-relaxed text-tinta-3">
                            {{ DESCRICAO_PAPEL[papel] }}
                        </span>
                    </span>
                </label>
            </div>

            <label class="flex items-center gap-2.5 rounded-2xl bg-superficie p-4 ring-1 ring-borda">
                <input v-model="acesso.ativo" type="checkbox" class="h-5 w-5 rounded border-borda-forte">
                <span class="text-sm text-tinta">Acesso ativo</span>
            </label>

            <label class="flex items-start gap-2.5 rounded-2xl bg-superficie p-4 ring-1 ring-borda">
                <input v-model="acesso.acesso_total" type="checkbox" class="mt-0.5 h-5 w-5 rounded border-borda-forte">
                <span>
                    <span class="block text-sm font-medium text-tinta">Acesso a todos os casos</span>
                </span>
            </label>

            <div v-if="!acesso.acesso_total">
                <span class="rotulo">Casos liberados</span>
                <label
                    v-for="processo in processos"
                    :key="processo.id"
                    class="mb-1.5 flex items-center gap-2.5 rounded-xl bg-superficie p-3 ring-1 ring-borda"
                >
                    <input
                        v-model="acesso.processos"
                        type="checkbox"
                        :value="processo.id"
                        class="h-5 w-5 shrink-0 rounded border-borda-forte"
                    >
                    <span class="min-w-0 truncate text-sm text-tinta">{{ processo.rotulo }}</span>
                </label>
            </div>

            <button type="button" class="btn-secundario w-full text-perigo" @click="remover(membroEmEdicao)">
                Encerrar acesso desta pessoa
            </button>
            <p class="text-xs leading-relaxed text-tinta-3">
                Encerrar o acesso não apaga o que a pessoa fez: o histórico e a auditoria ficam.
            </p>
        </div>

        <template #rodape>
            <button type="button" class="btn-primario w-full" :disabled="acesso.processing" @click="salvarAcesso">
                Salvar acesso
            </button>
        </template>
    </Folha>
</template>
