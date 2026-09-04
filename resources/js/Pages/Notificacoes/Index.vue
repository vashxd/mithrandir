<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { dataHora } from '../../formato';
import Cabecalho from '../../Components/Cabecalho.vue';
import Vazio from '../../Components/Vazio.vue';

defineProps({
    notificacoes: { type: Object, required: true },
});

const CORES = {
    radar_cego: 'bg-red-100 text-red-800',
    prazo_d0: 'bg-red-100 text-red-800',
    prazo_d1: 'bg-red-100 text-red-800',
    prazo_d3: 'bg-amber-100 text-amber-800',
    prazo_d5: 'bg-amber-100 text-amber-800',
    prazo_d10: 'bg-sky-100 text-sky-800',
    publicacao_nova: 'bg-sky-100 text-sky-800',
    recalculo_prazos: 'bg-amber-100 text-amber-800',
    revisao_calendario: 'bg-slate-100 text-slate-700',
    digest: 'bg-slate-100 text-slate-700',
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

        <ul v-else class="cartao divide-y divide-slate-100">
            <li v-for="notificacao in notificacoes.data" :key="notificacao.id">
                <component
                    :is="notificacao.url ? 'a' : 'div'"
                    :href="notificacao.url ?? undefined"
                    class="block p-4"
                    :class="notificacao.lida ? 'opacity-60' : ''"
                >
                    <span class="flex items-baseline justify-between gap-2">
                        <span class="etiqueta" :class="CORES[notificacao.tipo] ?? 'bg-slate-100 text-slate-700'">
                            {{ notificacao.tipo.replace(/_/g, ' ') }}
                        </span>
                        <span class="shrink-0 font-mono text-xs text-slate-400">
                            {{ dataHora(notificacao.agendada_para) }}
                        </span>
                    </span>

                    <span class="mt-1.5 block font-medium text-slate-900">{{ notificacao.titulo }}</span>
                    <span v-if="notificacao.corpo" class="mt-0.5 block text-sm leading-relaxed text-slate-600">
                        {{ notificacao.corpo }}
                    </span>

                    <span class="mt-1 block text-xs text-slate-400">
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
                    link.active ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200',
                    !link.url ? 'pointer-events-none opacity-40' : '',
                ]"
                v-html="link.label"
            />
        </div>
    </div>
</template>
