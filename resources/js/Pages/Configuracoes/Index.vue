<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { assinarPush, testarPush, pwaInstalado, ehIos, pushDisponivel } from '../../pwa';
import { dataHora } from '../../formato';
import Cabecalho from '../../Components/Cabecalho.vue';
import Folha from '../../Components/Folha.vue';
import Icone from '../../Components/Icone.vue';

const props = defineProps({
    perfil: { type: Object, required: true },
    push_configurado: { type: Boolean, default: false },
    datajud_configurado: { type: Boolean, default: false },
    datajud_alerta_401: { type: String, default: null },
    dispositivos: { type: Array, default: () => [] },
});

const chavePublica = computed(() => usePage().props.push?.chave_publica ?? '');

const formPerfil = useForm({
    name: props.perfil.nome,
    oab: props.perfil.oab,
    uf: props.perfil.uf,
    timezone: props.perfil.timezone,
    buffer_padrao: props.perfil.buffer_padrao,
    janela_djen_dias: props.perfil.janela_djen_dias,
    digest_horario: props.perfil.digest_horario,
    percentual_imposto: props.perfil.percentual_imposto,
    preferencias_notificacao: {
        publicacao: props.perfil.preferencias_notificacao?.publicacao ?? true,
        prazo: props.perfil.preferencias_notificacao?.prazo ?? true,
        digest: props.perfil.preferencias_notificacao?.digest ?? true,
        financeiro: props.perfil.preferencias_notificacao?.financeiro ?? true,
    },
});

function salvarPerfil() {
    formPerfil.patch('/configuracoes', { preserveScroll: true });
}

/* ---------------- Push ---------------- */

const statusPush = ref(null);
const permissao = ref('default');
const podePush = ref(false);
const ios = ref(false);
const instalado = ref(false);

onMounted(() => {
    permissao.value = typeof Notification !== 'undefined' ? Notification.permission : 'unsupported';
    podePush.value = pushDisponivel();
    ios.value = ehIos();
    instalado.value = pwaInstalado();
});

async function ligarPush() {
    try {
        await assinarPush(chavePublica.value);
        permissao.value = Notification.permission;
        statusPush.value = { ok: true, texto: 'Aparelho registrado.' };
    } catch (erro) {
        statusPush.value = { ok: false, texto: erro.message };
    }
}

async function testar() {
    const resultado = await testarPush();
    statusPush.value = { ok: resultado.ok, texto: resultado.mensagem };
}

/* ---------------- DataJud ---------------- */

const folhaDataJud = ref(false);
const dataJud = useForm({ api_key: '' });

function salvarChave() {
    dataJud.post('/configuracoes/datajud', {
        preserveScroll: true,
        onSuccess: () => {
            folhaDataJud.value = false;
            dataJud.reset();
        },
    });
}

/* ---------------- Conta ---------------- */

const folhaExclusao = ref(false);
const exclusao = useForm({ confirmacao: '' });

function excluir() {
    exclusao.post('/configuracoes/excluir-conta', {
        preserveScroll: true,
        onSuccess: () => {
            folhaExclusao.value = false;
            exclusao.reset();
        },
    });
}

function cancelarExclusao() {
    router.post('/configuracoes/cancelar-exclusao', {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Configurações" />

    <Cabecalho titulo="Configurações" :subtitulo="perfil.oab ? `OAB ${perfil.oab}/${perfil.uf}` : null" estreito />

    <div class="pagina-estreita space-y-3 px-4 lg:px-8 py-4 pb-8">
        <div
            v-if="perfil.exclusao_solicitada_em"
            class="rounded-2xl bg-red-50 p-4 ring-1 ring-red-200"
        >
            <p class="text-sm font-semibold text-red-900">Exclusão de conta agendada</p>
            <p class="mt-1 text-sm text-red-800">
                Solicitada em {{ dataHora(perfil.exclusao_solicitada_em) }}.
                O radar está desligado até lá.
            </p>
            <button type="button" class="btn-secundario mt-3 w-full" @click="cancelarExclusao">
                Cancelar exclusão
            </button>
        </div>

        <nav class="cartao divide-y divide-slate-100">
            <Link href="/configuracoes/radar" class="flex items-center justify-between p-4">
                <span>
                    <span class="block font-medium text-slate-900">Radar de publicações</span>
                    <span class="block text-sm text-slate-500">Termos vigiados e histórico de varreduras</span>
                </span>
                <Icone nome="seta" class="h-5 w-5 text-slate-400" />
            </Link>

            <Link href="/configuracoes/feriados" class="flex items-center justify-between p-4">
                <span>
                    <span class="block font-medium text-slate-900">Calendário de feriados</span>
                    <span class="block text-sm text-slate-500">O que entra na conta dos prazos</span>
                </span>
                <Icone nome="seta" class="h-5 w-5 text-slate-400" />
            </Link>
        </nav>

        <!-- Notificações -->
        <section class="cartao p-4">
            <h2 class="secao-titulo">Notificações</h2>

            <p v-if="!push_configurado" class="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">
                As chaves VAPID não estão configuradas no servidor. Rode
                <code class="font-mono">php artisan mithrandir:vapid --escrever</code>.
            </p>

            <template v-else>
                <p v-if="ios && !instalado" class="mt-3 rounded-xl bg-amber-50 p-3 text-sm leading-relaxed text-amber-900 ring-1 ring-amber-200">
                    Você está no iPhone sem o app instalado. O Safari só entrega notificação para PWA
                    na tela de início — instale primeiro pelo botão Compartilhar.
                </p>

                <div v-else class="mt-3 space-y-2">
                    <button v-if="permissao !== 'granted'" type="button" class="btn-primario w-full" @click="ligarPush">
                        Ligar notificações neste aparelho
                    </button>
                    <button v-else type="button" class="btn-secundario w-full" @click="testar">
                        Enviar notificação de teste
                    </button>

                    <p
                        v-if="statusPush"
                        class="rounded-xl px-3 py-2.5 text-sm"
                        :class="statusPush.ok
                            ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200'
                            : 'bg-red-50 text-red-800 ring-1 ring-red-200'"
                    >
                        {{ statusPush.texto }}
                    </p>
                </div>
            </template>

            <ul v-if="dispositivos.length" class="mt-3 space-y-1 border-t border-slate-100 pt-3">
                <li v-for="dispositivo in dispositivos" :key="dispositivo.id" class="text-xs text-slate-500">
                    <span class="block truncate">{{ dispositivo.user_agent ?? 'aparelho sem identificação' }}</span>
                    <span v-if="dispositivo.ultima_entrega_em" class="block text-slate-400">
                        última entrega {{ dataHora(dispositivo.ultima_entrega_em) }}
                    </span>
                </li>
            </ul>

            <div class="mt-4 space-y-2 border-t border-slate-100 pt-4">
                <label
                    v-for="(rotulo, chave) in {
                        publicacao: 'Nova publicação no DJEN',
                        prazo: 'Alertas de prazo (D-10, D-5, D-3, D-1 e dia fatal)',
                        digest: 'Resumo diário',
                        financeiro: 'Parcela vencida',
                    }"
                    :key="chave"
                    class="flex items-center gap-2.5 text-sm text-slate-700"
                >
                    <input v-model="formPerfil.preferencias_notificacao[chave]" type="checkbox" class="h-5 w-5 rounded border-slate-300">
                    {{ rotulo }}
                </label>
            </div>
        </section>

        <!-- Perfil e preferências de cálculo -->
        <form class="cartao space-y-4 p-4" @submit.prevent="salvarPerfil">
            <h2 class="secao-titulo">Conta e prazos</h2>

            <div>
                <label class="rotulo" for="nome">Nome</label>
                <input id="nome" v-model="formPerfil.name" type="text" class="campo">
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <label class="rotulo" for="oab">OAB</label>
                    <input id="oab" v-model="formPerfil.oab" type="text" class="campo">
                </div>
                <div>
                    <label class="rotulo" for="uf">UF</label>
                    <input id="uf" v-model="formPerfil.uf" type="text" maxlength="2" class="campo uppercase">
                </div>
            </div>

            <div>
                <label class="rotulo" for="buffer">Folga padrão antes do prazo fatal</label>
                <input id="buffer" v-model.number="formPerfil.buffer_padrao" type="number" min="0" max="15" class="campo">
                <p class="mt-1 text-xs leading-relaxed text-slate-500">
                    Em dias úteis. É essa data — e não a fatal — que aparece na sua agenda.
                </p>
            </div>

            <div>
                <label class="rotulo" for="janela">Janela de busca no DJEN</label>
                <div class="flex items-center gap-2">
                    <input
                        id="janela"
                        v-model.number="formPerfil.janela_djen_dias"
                        type="number"
                        min="2"
                        max="90"
                        class="campo"
                        :placeholder="`padrão: ${perfil.janela_djen_padrao} dias`"
                    >
                    <span class="shrink-0 text-sm text-slate-500">dias</span>
                </div>
                <p v-if="formPerfil.errors.janela_djen_dias" class="mt-1 text-sm text-red-600">
                    {{ formPerfil.errors.janela_djen_dias }}
                </p>
                <p class="mt-1 text-xs leading-relaxed text-slate-500">
                    Quantos dias para trás cada varredura cobre. Deixe em branco para usar o padrão
                    ({{ perfil.janela_djen_padrao }} dias). Mínimo de 2 — ontem e hoje entram sempre,
                    porque as datas do DJEN são de Brasília e a virada do fuso faria perder
                    publicação. Janela maior é mais segura: se a varredura falhar alguns dias, a
                    próxima cobre o buraco. Reler os mesmos dias não duplica nada.
                </p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="rotulo" for="digest">Hora do resumo diário</label>
                    <input id="digest" v-model="formPerfil.digest_horario" type="time" class="campo">
                </div>
                <div>
                    <label class="rotulo" for="imposto">Provisão de imposto (%)</label>
                    <input id="imposto" v-model.number="formPerfil.percentual_imposto" type="number" step="0.1" min="0" max="100" class="campo">
                </div>
            </div>

            <div>
                <label class="rotulo" for="fuso">Fuso horário</label>
                <select id="fuso" v-model="formPerfil.timezone" class="campo">
                    <option value="America/Sao_Paulo">Brasília (America/Sao_Paulo)</option>
                    <option value="America/Manaus">Manaus (America/Manaus)</option>
                    <option value="America/Belem">Belém (America/Belem)</option>
                    <option value="America/Cuiaba">Cuiabá (America/Cuiaba)</option>
                    <option value="America/Rio_Branco">Rio Branco (America/Rio_Branco)</option>
                </select>
            </div>

            <button type="submit" class="btn-primario w-full" :disabled="formPerfil.processing">
                Salvar
            </button>
        </form>

        <!-- DataJud -->
        <section class="cartao p-4">
            <h2 class="secao-titulo">DataJud (CNJ)</h2>
            <p class="mt-2 text-sm leading-relaxed text-slate-600">
                Usado só para enriquecer a capa e as movimentações do caso.
                <strong>Nunca dispara prazo</strong> — se o DataJud cair, o radar continua funcionando.
            </p>

            <p
                v-if="datajud_alerta_401"
                class="mt-3 rounded-xl bg-red-50 p-3 text-sm text-red-900 ring-1 ring-red-200"
            >
                O CNJ recusou a chave em {{ dataHora(datajud_alerta_401) }}.
                A chave pública costuma mudar sem aviso — pegue a atual na wiki do CNJ e cole aqui.
            </p>

            <p class="mt-3 text-sm">
                <span class="etiqueta" :class="datajud_configurado ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'">
                    {{ datajud_configurado ? 'chave configurada' : 'sem chave' }}
                </span>
            </p>

            <button type="button" class="btn-secundario mt-3 w-full" @click="folhaDataJud = true">
                {{ datajud_configurado ? 'Trocar chave' : 'Configurar chave' }}
            </button>
        </section>

        <!-- LGPD -->
        <section class="cartao p-4">
            <h2 class="secao-titulo">Seus dados</h2>
            <p class="mt-2 text-sm leading-relaxed text-slate-600">
                Você é o controlador dos dados dos seus clientes; o Mithrandir é operador.
                A exportação traz tudo em JSON mais um ZIP com os arquivos.
            </p>

            <a href="/configuracoes/exportar" class="btn-secundario mt-3 w-full">
                <Icone nome="documento" class="h-4 w-4" />
                Exportar todos os meus dados
            </a>

            <button type="button" class="btn-secundario mt-2 w-full text-red-600" @click="folhaExclusao = true">
                Excluir minha conta
            </button>
        </section>

        <form method="post" action="/sair" @submit.prevent="router.post('/sair')">
            <button type="submit" class="btn-secundario w-full">Sair</button>
        </form>
    </div>

    <Folha :aberta="folhaDataJud" titulo="Chave do DataJud" @fechar="folhaDataJud = false">
        <div class="space-y-4">
            <p class="text-sm leading-relaxed text-slate-600">
                A chave pública é divulgada pelo CNJ em
                <span class="font-mono text-xs">datajud-wiki.cnj.jus.br/api-publica/acesso</span>.
                Ela pode ser trocada a qualquer momento, por isso fica editável aqui, sem precisar de deploy.
            </p>

            <div>
                <label class="rotulo" for="chave">Chave</label>
                <input id="chave" v-model="dataJud.api_key" type="text" class="campo font-mono text-sm">
                <p v-if="dataJud.errors.api_key" class="mt-1 text-sm text-red-600">{{ dataJud.errors.api_key }}</p>
            </div>
        </div>

        <template #rodape>
            <button type="button" class="btn-primario w-full" :disabled="dataJud.processing" @click="salvarChave">
                Salvar chave
            </button>
        </template>
    </Folha>

    <Folha :aberta="folhaExclusao" titulo="Excluir conta" @fechar="folhaExclusao = false">
        <div class="space-y-4">
            <p class="rounded-2xl bg-red-50 p-4 text-sm leading-relaxed text-red-900 ring-1 ring-red-200">
                A conta entra em carência de 30 dias. Nesse período o radar fica desligado e
                <strong>nenhuma publicação nova é capturada</strong> — controle seus prazos por outro
                meio. Depois dos 30 dias, tudo é apagado em definitivo.
            </p>

            <p class="text-sm text-slate-600">
                Antes de seguir, considere
                <a href="/configuracoes/exportar" class="font-medium text-sky-700">exportar seus dados</a>.
            </p>

            <div>
                <label class="rotulo" for="confirmacao">Digite EXCLUIR para confirmar</label>
                <input id="confirmacao" v-model="exclusao.confirmacao" type="text" class="campo" placeholder="EXCLUIR">
                <p v-if="exclusao.errors.confirmacao" class="mt-1 text-sm text-red-600">{{ exclusao.errors.confirmacao }}</p>
            </div>
        </div>

        <template #rodape>
            <button type="button" class="btn-perigo w-full" :disabled="exclusao.processing" @click="excluir">
                Excluir minha conta
            </button>
        </template>
    </Folha>
</template>
