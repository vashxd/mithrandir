<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { dataCurta, diaDaSemana } from '../../formato';
import Cabecalho from '../../Components/Cabecalho.vue';
import Folha from '../../Components/Folha.vue';
import Icone from '../../Components/Icone.vue';

/**
 * Seção 8.4 — o passivo do projeto.
 *
 * Não existe fonte pública unificada de feriado forense. O aviso fica
 * permanente na tela, não escondido num rodapé: feriado que falta aqui vira
 * prazo errado.
 */
const props = defineProps({
    ano: { type: Number, required: true },
    feriados: { type: Array, default: () => [] },
    aviso: { type: String, required: true },
});

const folhaAberta = ref(false);

const formulario = useForm({
    data: '',
    descricao: '',
    abrangencia: 'tribunal',
    uf: '',
    municipio: '',
    tribunal_sigla: '',
    suspensao_expediente: true,
});

function mudarAno(direcao) {
    router.get('/configuracoes/feriados', { ano: props.ano + direcao }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function salvar() {
    formulario.post('/configuracoes/feriados', {
        preserveScroll: true,
        onSuccess: () => {
            folhaAberta.value = false;
            formulario.reset();
        },
    });
}

const porMes = computed(() => {
    const mapa = new Map();

    for (const feriado of props.feriados) {
        const mes = feriado.data.slice(0, 7);
        if (!mapa.has(mes)) mapa.set(mes, []);
        mapa.get(mes).push(feriado);
    }

    return [...mapa.entries()];
});

function rotuloMes(mes) {
    const [ano, m] = mes.split('-').map(Number);
    return new Date(ano, m - 1, 1).toLocaleDateString('pt-BR', { month: 'long' });
}

const CORES_ABRANGENCIA = {
    nacional: 'bg-slate-100 text-slate-700',
    estadual: 'bg-sky-100 text-sky-800',
    municipal: 'bg-violet-100 text-violet-800',
    tribunal: 'bg-amber-100 text-amber-800',
};
</script>

<template>
    <Head title="Feriados" />

    <Cabecalho titulo="Calendário de feriados" :subtitulo="`${feriados.length} em ${ano}`" voltar-para="/configuracoes" estreito>
        <template #acoes>
            <button
                type="button"
                class="flex h-11 w-11 items-center justify-center rounded-full bg-slate-900 text-white"
                aria-label="Novo feriado"
                @click="folhaAberta = true"
            >
                <Icone nome="mais_circulo" class="h-5 w-5" />
            </button>
        </template>
    </Cabecalho>

    <div class="pagina-estreita px-4 lg:px-8 py-4 pb-8">
        <!-- Aviso permanente: mitigação do risco "calendário desatualizado". -->
        <p class="rounded-2xl bg-amber-50 p-4 text-sm leading-relaxed text-amber-900 ring-1 ring-amber-200">
            <Icone nome="alerta" class="mr-1 inline h-4 w-4 align-text-bottom" />
            {{ aviso }}
            Cadastrar um feriado aqui <strong>recalcula automaticamente</strong> os prazos abertos
            que passam por aquela data, e você recebe um aviso do que mudou.
        </p>

        <div class="flex items-center justify-between py-3">
            <button
                type="button"
                class="flex h-11 w-11 items-center justify-center rounded-full text-slate-600 active:bg-slate-200"
                aria-label="Ano anterior"
                @click="mudarAno(-1)"
            >
                <Icone nome="voltar" class="h-5 w-5" />
            </button>
            <p class="font-mono text-lg font-bold text-slate-800">{{ ano }}</p>
            <button
                type="button"
                class="flex h-11 w-11 items-center justify-center rounded-full text-slate-600 active:bg-slate-200"
                aria-label="Próximo ano"
                @click="mudarAno(1)"
            >
                <Icone nome="seta" class="h-5 w-5" />
            </button>
        </div>

        <p v-if="!feriados.length" class="cartao p-6 text-center text-sm text-slate-500">
            Nenhum feriado cadastrado para {{ ano }}. Sem calendário, todo prazo conta como se
            só houvesse fim de semana — o que quase sempre está errado.
        </p>

        <div v-else class="space-y-5">
            <section v-for="[mes, itens] in porMes" :key="mes">
                <h2 class="mb-1.5 text-sm font-bold text-slate-700 first-letter:uppercase">{{ rotuloMes(mes) }}</h2>

                <ul class="cartao divide-y divide-slate-100">
                    <li v-for="feriado in itens" :key="feriado.id" class="flex items-center gap-3 p-3.5">
                        <span class="w-12 shrink-0 text-center">
                            <span class="block font-mono text-sm font-bold text-slate-800">
                                {{ feriado.data.slice(8, 10) }}
                            </span>
                            <span class="block text-[10px] text-slate-400 first-letter:uppercase">
                                {{ diaDaSemana(feriado.data).slice(0, 3) }}
                            </span>
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-slate-800">{{ feriado.descricao }}</span>
                            <span class="mt-0.5 flex flex-wrap gap-1">
                                <span class="etiqueta" :class="CORES_ABRANGENCIA[feriado.abrangencia]">
                                    {{ feriado.abrangencia }}
                                    <template v-if="feriado.uf"> {{ feriado.uf }}</template>
                                    <template v-if="feriado.municipio"> · {{ feriado.municipio }}</template>
                                    <template v-if="feriado.tribunal_sigla"> · {{ feriado.tribunal_sigla }}</template>
                                </span>
                                <span v-if="feriado.proprio" class="etiqueta bg-emerald-100 text-emerald-800">seu</span>
                            </span>
                        </span>
                    </li>
                </ul>
            </section>
        </div>
    </div>

    <Folha :aberta="folhaAberta" titulo="Novo feriado" @fechar="folhaAberta = false">
        <div class="space-y-4">
            <div>
                <label class="rotulo" for="data">Data</label>
                <input id="data" v-model="formulario.data" type="date" class="campo">
                <p v-if="formulario.errors.data" class="mt-1 text-sm text-red-600">{{ formulario.errors.data }}</p>
            </div>

            <div>
                <label class="rotulo" for="descricao">Descrição</label>
                <input id="descricao" v-model="formulario.descricao" type="text" class="campo" placeholder="Suspensão de expediente — Portaria 123/2026">
                <p v-if="formulario.errors.descricao" class="mt-1 text-sm text-red-600">{{ formulario.errors.descricao }}</p>
            </div>

            <div>
                <label class="rotulo" for="abrangencia">Vale para</label>
                <select id="abrangencia" v-model="formulario.abrangencia" class="campo">
                    <option value="tribunal">Um tribunal específico</option>
                    <option value="municipal">Um município</option>
                    <option value="estadual">Um estado</option>
                    <option value="nacional">O país inteiro</option>
                </select>
            </div>

            <div v-if="formulario.abrangencia === 'tribunal'">
                <label class="rotulo" for="tribunal">Sigla do tribunal</label>
                <input id="tribunal" v-model="formulario.tribunal_sigla" type="text" class="campo uppercase" placeholder="TJAM">
            </div>

            <div v-if="['estadual', 'municipal'].includes(formulario.abrangencia)">
                <label class="rotulo" for="uf">UF</label>
                <input id="uf" v-model="formulario.uf" type="text" maxlength="2" class="campo uppercase">
            </div>

            <div v-if="formulario.abrangencia === 'municipal'">
                <label class="rotulo" for="municipio">Município</label>
                <input id="municipio" v-model="formulario.municipio" type="text" class="campo" placeholder="Manaus">
            </div>

            <label class="flex items-start gap-2.5 rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                <input v-model="formulario.suspensao_expediente" type="checkbox" class="mt-0.5 h-5 w-5 rounded border-slate-300">
                <span>
                    <span class="block text-sm font-medium text-slate-800">É suspensão de expediente forense</span>
                    <span class="block text-xs leading-relaxed text-slate-500">
                        Marque quando vier de portaria do tribunal, e não de feriado civil.
                    </span>
                </span>
            </label>
        </div>

        <template #rodape>
            <button type="button" class="btn-primario w-full" :disabled="formulario.processing" @click="salvar">
                Cadastrar e recalcular prazos
            </button>
        </template>
    </Folha>
</template>
