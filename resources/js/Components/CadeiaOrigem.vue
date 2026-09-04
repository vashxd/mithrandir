<script setup>
import { computed, ref } from 'vue';
import { dataCurta, diaDaSemana } from '../formato';

/**
 * RF-2.7 / princípio de produto nº 2.
 *
 * Toda data de prazo mostra, ao toque, a cadeia de origem completa. Isto não é
 * enfeite: é o desenho de risco do produto. A advogada precisa poder auditar de
 * onde veio a data antes de confiar nela — e a conferência no diário continua
 * sendo obrigação dela.
 */
const props = defineProps({
    cadeia: { type: Object, default: null },
    sempreAberta: { type: Boolean, default: false },
});

const aberta = ref(props.sempreAberta);

const passos = computed(() => props.cadeia?.passos ?? []);
const naoContados = computed(() => props.cadeia?.dias_nao_contados ?? []);
const ajuste = computed(() => props.cadeia?.ajuste_manual ?? null);

const ROTULOS = {
    disponibilizacao: 'Disponibilização no diário',
    publicacao: 'Publicação',
    inicio_contagem: 'Início da contagem',
    multiplicador: 'Prazo em dobro',
    vencimento: 'Vencimento calculado',
    prorrogacao: 'Prorrogação',
    data_fatal: 'Data fatal',
    data_alvo: 'Data-alvo interna',
};

const DESTAQUE = ['data_fatal', 'data_alvo'];
</script>

<template>
    <div v-if="cadeia" class="rounded-2xl border border-slate-200 bg-white">
        <button
            v-if="!sempreAberta"
            type="button"
            class="flex w-full items-center justify-between px-4 py-3 text-left"
            @click="aberta = !aberta"
        >
            <span class="text-sm font-semibold text-slate-800">Como esta data foi calculada</span>
            <span class="text-xs font-medium text-sky-700">{{ aberta ? 'ocultar' : 'ver cadeia' }}</span>
        </button>

        <div v-show="aberta" class="px-4 pb-4" :class="sempreAberta ? 'pt-4' : ''">
            <ol class="relative border-l-2 border-slate-200 pl-5">
                <li v-for="(passo, indice) in passos" :key="indice" class="relative pb-4 last:pb-0">
                    <span
                        class="absolute -left-[27px] top-1 flex h-3.5 w-3.5 items-center justify-center rounded-full ring-4 ring-white"
                        :class="DESTAQUE.includes(passo.etapa) ? 'bg-slate-900' : 'bg-slate-300'"
                    />

                    <p class="flex flex-wrap items-baseline gap-x-2">
                        <span
                            class="text-sm font-semibold"
                            :class="DESTAQUE.includes(passo.etapa) ? 'text-slate-900' : 'text-slate-700'"
                        >
                            {{ ROTULOS[passo.etapa] ?? passo.etapa }}
                        </span>
                        <span class="font-mono text-sm text-slate-900">{{ dataCurta(passo.data) }}</span>
                        <span class="text-xs text-slate-400">{{ diaDaSemana(passo.data) }}</span>
                    </p>

                    <p class="mt-0.5 text-xs leading-relaxed text-slate-500">{{ passo.regra }}</p>

                    <p v-if="passo.base_legal" class="mt-1 inline-block rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600">
                        {{ passo.base_legal }}
                    </p>
                </li>
            </ol>

            <div v-if="naoContados.length" class="mt-4 rounded-xl bg-slate-50 p-3">
                <p class="text-xs font-semibold text-slate-700">Dias em que o prazo não correu</p>
                <ul class="mt-1.5 space-y-1">
                    <li v-for="dia in naoContados" :key="dia.data" class="flex gap-2 text-xs text-slate-600">
                        <span class="font-mono">{{ dataCurta(dia.data) }}</span>
                        <span class="text-slate-400">·</span>
                        <span>{{ dia.motivo }}</span>
                    </li>
                </ul>
            </div>

            <div v-if="ajuste" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3">
                <p class="text-xs font-semibold text-amber-900">Ajustado manualmente</p>
                <p class="mt-1 text-xs text-amber-900">
                    De <span class="font-mono">{{ dataCurta(ajuste.de) }}</span>
                    para <span class="font-mono font-semibold">{{ dataCurta(ajuste.para) }}</span>
                </p>
                <p class="mt-1 text-xs leading-relaxed text-amber-800">“{{ ajuste.justificativa }}”</p>
            </div>

            <p class="mt-4 border-t border-slate-100 pt-3 text-xs leading-relaxed text-slate-500">
                O Mithrandir não substitui a conferência no diário oficial. A contagem depende do
                calendário de feriados cadastrado, que pode estar incompleto.
            </p>
        </div>
    </div>
</template>
