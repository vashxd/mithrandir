<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { dataHora } from '../../formato';
import Cabecalho from '../../Components/Cabecalho.vue';
import Vazio from '../../Components/Vazio.vue';

defineProps({
    notificacoes: { type: Object, required: true },
});

const CORES = {
    radar_cego: 'bg-perigo-fundo text-perigo-tinta',
    prazo_d0: 'bg-perigo-fundo text-perigo-tinta',
    prazo_d1: 'bg-perigo-fundo text-perigo-tinta',
    prazo_d3: 'bg-atencao-fundo text-atencao-tinta',
    prazo_d5: 'bg-atencao-fundo text-atencao-tinta',
    prazo_d10: 'bg-acento-fundo text-acento-tinta',
    publicacao_nova: 'bg-acento-fundo text-acento-tinta',
    recalculo_prazos: 'bg-atencao-fundo text-atencao-tinta',
    revisao_calendario: 'bg-superficie-2 text-tinta-2',
    digest: 'bg-superficie-2 text-tinta-2',
};
</script>

<template>
    <Head title="Notificações" />

    <Cabecalho titulo="Notificações" estreito />

    <div class="pagina-estreita px-4 lg:px-8 py-4 pb-8">
        <Vazio
            v-if="!notificacoes.data.length"
            titulo="Nenhuma notificação ainda"
            texto="Aqui fica o histórico do que o app te avisou: publicação nova, prazo chegando e falha de varredura."
        />

        <ul v-else class="cartao divide-y divide-borda-sutil">
            <li v-for="notificacao in notificacoes.data" :key="notificacao.id">
                <component
                    :is="notificacao.url ? 'a' : 'div'"
                    :href="notificacao.url ?? undefined"
                    class="block p-4"
                    :class="notificacao.lida ? 'opacity-60' : ''"
                >
                    <span class="flex items-baseline justify-between gap-2">
                        <span class="etiqueta" :class="CORES[notificacao.tipo] ?? 'bg-superficie-2 text-tinta-2'">
                            {{ notificacao.tipo.replace(/_/g, ' ') }}
                        </span>
                        <span class="shrink-0 font-mono text-xs text-tinta-3">
                            {{ dataHora(notificacao.agendada_para) }}
                        </span>
                    </span>

                    <span class="mt-1.5 block font-medium text-tinta">{{ notificacao.titulo }}</span>
                    <span v-if="notificacao.corpo" class="mt-0.5 block text-sm leading-relaxed text-tinta-2">
                        {{ notificacao.corpo }}
                    </span>

                    <span class="mt-1 block text-xs text-tinta-3">
                        <template v-if="notificacao.enviada_em">
                            entregue por {{ notificacao.canal }}
                        </template>
                        <template v-else>aguardando envio</template>
                    </span>
                </component>
            </li>
        </ul>

        <div v-if="notificacoes.links?.length > 3" class="mt-4 flex justify-center gap-1">
            <Link
                v-for="link in notificacoes.links"
                :key="link.label"
                :href="link.url ?? '#'"
                class="min-w-11 rounded-lg px-3 py-2 text-center text-sm"
                :class="[
                    link.active ? 'bg-acao text-sobre-acao' : 'bg-superficie text-tinta-2 ring-1 ring-borda',
                    !link.url ? 'pointer-events-none opacity-40' : '',
                ]"
                v-html="link.label"
            />
        </div>
    </div>
</template>
