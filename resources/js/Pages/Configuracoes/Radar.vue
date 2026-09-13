<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { dataHora } from '../../formato';
import Cabecalho from '../../Components/Cabecalho.vue';
import Icone from '../../Components/Icone.vue';
import { sincronizarAgora } from '../../varreduraCliente';

/**
 * RF-1.1 e RF-1.9: termos de vigilância e auditoria de cada varredura.
 *
 * O log fica visível de propósito. Quando o radar falha, a advogada precisa
 * saber exatamente desde quando — para conferir o diário à mão naquele período.
 */
defineProps({
    watches: { type: Array, default: () => [] },
});

const formulario = useForm({ termo: '', tipo: 'oab', uf: '', ativo: true });
const logsAbertos = ref({});

function adicionar() {
    formulario.post('/configuracoes/radar', {
        preserveScroll: true,
        onSuccess: () => formulario.reset(),
    });
}

function alternar(watch) {
    router.post(
        '/configuracoes/radar',
        { termo: watch.termo, tipo: watch.tipo, uf: watch.uf, ativo: !watch.ativo },
        { preserveScroll: true }
    );
}

function remover(watch) {
    router.delete(`/configuracoes/radar/${watch.id}`, { preserveScroll: true });
}

function sincronizar() {
    sincronizarAgora();
}
</script>

<template>
    <Head title="Radar de publicações" />

    <Cabecalho titulo="Radar" subtitulo="termos vigiados no DJEN" voltar-para="/configuracoes" estreito>
        <template #acoes>
            <button
                type="button"
                class="flex h-11 w-11 items-center justify-center rounded-full text-tinta-2 active:bg-superficie-2"
                aria-label="Sincronizar agora"
                @click="sincronizar"
            >
                <Icone nome="sincronizar" class="h-6 w-6" />
            </button>
        </template>
    </Cabecalho>

    <div class="pagina-estreita space-y-3 px-4 lg:px-8 py-4 pb-8">
        <p class="rounded-2xl bg-superficie p-4 text-sm leading-relaxed text-tinta-2 ring-1 ring-borda">
            A varredura roda todo dia às 6h, sempre pela janela <strong>ontem + hoje</strong> —
            nunca só hoje, para não perder publicação na virada do fuso.
            Tribunais gravam a OAB de formas diferentes, então vale manter as variações ligadas.
        </p>

        <p class="rounded-2xl bg-atencao-fundo p-4 text-sm leading-relaxed text-atencao-tinta ring-1 ring-atencao-borda">
            O DJEN recusa requisição de servidor fora do Brasil. Por isso este navegador
            também varre, sozinho, sempre que você abre o app — o histórico abaixo diz quem
            buscou cada vez. <strong>Varredura por navegador só acontece se alguém abrir o
            app</strong>, então o aviso no topo da tela mostra há quanto tempo ninguém abriu.
        </p>

        <ul class="space-y-2">
            <li v-for="watch in watches" :key="watch.id" class="cartao overflow-hidden">
                <div class="flex items-center gap-3 p-4">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-mono font-medium text-tinta">{{ watch.termo }}</span>
                        <span class="block text-xs text-tinta-3">
                            {{ watch.tipo === 'oab' ? 'OAB' : 'nome' }}
                            <template v-if="watch.uf"> · {{ watch.uf }}</template>
                            · {{ watch.publicacoes }} {{ watch.publicacoes === 1 ? 'publicação' : 'publicações' }}
                        </span>
                        <span v-if="watch.ultima_sync_em" class="block text-xs text-tinta-3">
                            última busca {{ dataHora(watch.ultima_sync_em) }}
                        </span>
                    </span>

                    <button
                        type="button"
                        class="shrink-0 rounded-lg px-2.5 py-1.5 text-xs font-semibold"
                        :class="watch.ativo ? 'bg-ok-fundo text-ok-tinta' : 'bg-superficie-3 text-tinta-2'"
                        @click="alternar(watch)"
                    >
                        {{ watch.ativo ? 'ativo' : 'desligado' }}
                    </button>

                    <button
                        type="button"
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-tinta-icone active:bg-superficie-2"
                        :aria-label="`Remover ${watch.termo}`"
                        @click="remover(watch)"
                    >
                        <Icone nome="x" class="h-5 w-5" />
                    </button>
                </div>

                <p v-if="watch.cego" class="border-t border-perigo-borda bg-perigo-fundo px-4 py-3 text-sm text-perigo-tinta">
                    <strong>Radar cego.</strong>
                    {{ watch.falhas_consecutivas }} falhas seguidas. Confira o diário manualmente.
                </p>

                <div v-if="watch.ultimos_logs.length" class="border-t border-borda-sutil">
                    <button
                        type="button"
                        class="w-full px-4 py-2.5 text-left text-xs font-medium text-acento"
                        @click="logsAbertos[watch.id] = !logsAbertos[watch.id]"
                    >
                        {{ logsAbertos[watch.id] ? 'ocultar' : 'ver' }} histórico de varreduras
                    </button>

                    <ul v-show="logsAbertos[watch.id]" class="divide-y divide-borda-sutil px-4 pb-3">
                        <li v-for="(log, indice) in watch.ultimos_logs" :key="indice" class="py-2 text-xs">
                            <span class="flex items-baseline justify-between gap-2">
                                <span
                                    class="font-semibold"
                                    :class="log.status === 'sucesso' ? 'text-ok' : 'text-perigo'"
                                >
                                    {{ log.status }}
                                </span>
                                <span class="font-mono text-tinta-3">{{ dataHora(log.executado_em) }}</span>
                            </span>
                            <span v-if="log.status === 'sucesso'" class="block text-tinta-3">
                                {{ log.qtd_itens }} itens · {{ log.qtd_novas }} novas
                                · {{ log.origem === 'cliente' ? 'por este navegador' : 'pelo servidor' }}
                            </span>
                            <span v-else class="block text-perigo">{{ log.erro }}</span>
                        </li>
                    </ul>
                </div>
            </li>
        </ul>

        <form class="cartao space-y-3 p-4" @submit.prevent="adicionar">
            <h2 class="secao-titulo">Novo termo</h2>

            <div>
                <label class="rotulo" for="termo">Termo</label>
                <input id="termo" v-model="formulario.termo" type="text" class="campo" placeholder="123456-O">
                <p v-if="formulario.errors.termo" class="mt-1 text-sm text-perigo">{{ formulario.errors.termo }}</p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="rotulo" for="tipo">Tipo</label>
                    <select id="tipo" v-model="formulario.tipo" class="campo">
                        <option value="oab">Número da OAB</option>
                        <option value="nome">Nome</option>
                    </select>
                </div>
                <div>
                    <label class="rotulo" for="uf">UF</label>
                    <input id="uf" v-model="formulario.uf" type="text" maxlength="2" class="campo uppercase">
                </div>
            </div>

            <p v-if="formulario.tipo === 'nome'" class="rounded-xl bg-atencao-fundo px-3 py-2.5 text-xs leading-relaxed text-atencao-tinta">
                Busca por nome traz homônimo e grafia divergente. Toda publicação capturada assim
                passa pela sua triagem antes de virar prazo.
            </p>

            <button type="submit" class="btn-primario w-full" :disabled="formulario.processing">
                Adicionar termo
            </button>
        </form>
    </div>
</template>
