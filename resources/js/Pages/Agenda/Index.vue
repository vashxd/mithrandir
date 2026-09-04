<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { dataCurta, dataLonga, diaDaSemana, hora, hoje, BARRA_CRITICIDADE } from '../../formato';
import Cabecalho from '../../Components/Cabecalho.vue';
import Folha from '../../Components/Folha.vue';
import Icone from '../../Components/Icone.vue';
import Vazio from '../../Components/Vazio.vue';

/**
 * Tela 3 — "Como está minha semana?"
 * RF-3.2: Hoje, Semana, Mês e "Fatais em 7 dias".
 */
const props = defineProps({
    eventos: { type: Array, required: true },
    visao: { type: String, required: true },
    referencia: { type: String, required: true },
    janela: { type: Object, required: true },
    processos: { type: Array, default: () => [] },
});

const visoes = [
    { chave: 'hoje', rotulo: 'Hoje' },
    { chave: 'semana', rotulo: 'Semana' },
    { chave: 'mes', rotulo: 'Mês' },
    { chave: 'fatais', rotulo: 'Fatais em 7 dias' },
];

function mudarVisao(chave) {
    router.get('/agenda', { visao: chave, data: props.referencia }, { preserveState: true, preserveScroll: true });
}

function navegar(direcao) {
    const base = new Date(`${props.referencia}T12:00:00`);
    const passo = { hoje: 1, semana: 7, fatais: 7, mes: 30 }[props.visao] ?? 7;

    if (props.visao === 'mes') {
        base.setMonth(base.getMonth() + direcao);
    } else {
        base.setDate(base.getDate() + passo * direcao);
    }

    router.get(
        '/agenda',
        { visao: props.visao, data: base.toLocaleDateString('sv-SE') },
        { preserveState: true, preserveScroll: true }
    );
}

/** Eventos agrupados por dia — a agenda de quem trabalha do celular é uma lista. */
const porDia = computed(() => {
    const mapa = new Map();

    for (const evento of props.eventos) {
        if (!mapa.has(evento.dia)) mapa.set(evento.dia, []);
        mapa.get(evento.dia).push(evento);
    }

    return [...mapa.entries()].sort(([a], [b]) => a.localeCompare(b));
});

const ehHoje = (dia) => dia === hoje();

/* ---------------- Novo compromisso ---------------- */

const folhaAberta = ref(false);

const formulario = useForm({
    tipo: 'audiencia',
    titulo: '',
    descricao: '',
    inicio: '',
    fim: '',
    dia_inteiro: false,
    local: '',
    link: '',
    lembrete_deslocamento_min: 60,
    processo_id: null,
});

function enviar() {
    formulario.post('/agenda', {
        preserveScroll: true,
        onSuccess: () => {
            folhaAberta.value = false;
            formulario.reset();
        },
    });
}

const ICONE_TIPO = {
    prazo: 'relogio',
    audiencia: 'balanca',
    compromisso: 'agenda',
    tarefa: 'check',
};
</script>

<template>
    <Head title="Agenda" />

    <Cabecalho titulo="Agenda">
        <template #acoes>
            <button
                type="button"
                class="flex h-11 w-11 items-center justify-center rounded-full bg-slate-900 text-white"
                aria-label="Novo compromisso"
                @click="folhaAberta = true"
            >
                <Icone nome="mais_circulo" class="h-5 w-5" />
            </button>
        </template>
    </Cabecalho>

    <div class="pagina">
        <div class="flex gap-2 overflow-x-auto px-4 py-3">
            <button
                v-for="opcao in visoes"
                :key="opcao.chave"
                type="button"
                class="shrink-0 rounded-full px-3.5 py-2 text-sm font-medium"
                :class="visao === opcao.chave ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200'"
                @click="mudarVisao(opcao.chave)"
            >
                {{ opcao.rotulo }}
            </button>
        </div>

        <div class="flex items-center justify-between px-4 pb-3">
            <button
                type="button"
                class="flex h-11 w-11 items-center justify-center rounded-full text-slate-600 active:bg-slate-200"
                aria-label="Período anterior"
                @click="navegar(-1)"
            >
                <Icone nome="voltar" class="h-5 w-5" />
            </button>

            <p class="text-sm font-semibold text-slate-700">
                {{ janela.de === janela.ate
                    ? dataLonga(janela.de)
                    : `${dataCurta(janela.de)} – ${dataCurta(janela.ate)}` }}
            </p>

            <button
                type="button"
                class="flex h-11 w-11 items-center justify-center rounded-full text-slate-600 active:bg-slate-200"
                aria-label="Próximo período"
                @click="navegar(1)"
            >
                <Icone nome="seta" class="h-5 w-5" />
            </button>
        </div>

        <Vazio
            v-if="!porDia.length"
            titulo="Nada marcado neste período"
            texto="Prazos aparecem aqui automaticamente pela data-alvo. Audiências e compromissos você lança pelo botão no topo."
        />

        <div v-else class="space-y-5 px-4 pb-8">
            <section v-for="[dia, itens] in porDia" :key="dia">
                <h2 class="mb-2 flex items-baseline gap-2">
                    <span
                        class="font-mono text-sm font-bold"
                        :class="ehHoje(dia) ? 'text-sky-700' : 'text-slate-700'"
                    >
                        {{ dataCurta(dia) }}
                    </span>
                    <span class="text-xs text-slate-500 first-letter:uppercase">{{ diaDaSemana(dia) }}</span>
                    <span v-if="ehHoje(dia)" class="etiqueta bg-sky-100 text-sky-800">hoje</span>
                </h2>

                <ul class="cartao divide-y divide-slate-100">
                    <li v-for="evento in itens" :key="evento.id">
                        <component
                            :is="evento.prazo_id ? 'a' : 'div'"
                            :href="evento.prazo_id ? `/prazos/${evento.prazo_id}` : undefined"
                            class="flex gap-3 p-3.5"
                            :class="evento.concluido ? 'opacity-50' : ''"
                        >
                            <span class="w-1 shrink-0 rounded-full" :class="BARRA_CRITICIDADE[evento.criticidade]" />

                            <span class="w-12 shrink-0 pt-0.5 font-mono text-xs font-semibold text-slate-600">
                                {{ evento.dia_inteiro ? '—' : hora(evento.inicio) }}
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-1.5">
                                    <Icone :nome="ICONE_TIPO[evento.tipo]" class="h-4 w-4 shrink-0 text-slate-400" />
                                    <span class="truncate font-medium text-slate-900">{{ evento.titulo }}</span>
                                </span>

                                <span v-if="evento.descricao" class="mt-0.5 block truncate text-sm text-slate-500">
                                    {{ evento.descricao }}
                                </span>

                                <span v-if="evento.local" class="mt-0.5 block truncate text-sm text-slate-500">
                                    {{ evento.local }}
                                </span>

                                <span v-if="evento.processo" class="mt-1 block truncate text-xs text-slate-400">
                                    {{ evento.processo.rotulo }}
                                    <template v-if="evento.processo.cliente"> · {{ evento.processo.cliente }}</template>
                                </span>
                            </span>

                            <a
                                v-if="evento.link"
                                :href="evento.link"
                                target="_blank"
                                rel="noopener"
                                class="shrink-0 self-center text-xs font-semibold text-sky-700"
                                @click.stop
                            >
                                entrar
                            </a>
                        </component>
                    </li>
                </ul>
            </section>
        </div>
    </div>

    <Folha :aberta="folhaAberta" titulo="Novo compromisso" @fechar="folhaAberta = false">
        <div class="space-y-4">
            <div>
                <label class="rotulo" for="tipo">Tipo</label>
                <select id="tipo" v-model="formulario.tipo" class="campo">
                    <option value="audiencia">Audiência</option>
                    <option value="compromisso">Compromisso</option>
                    <option value="tarefa">Tarefa</option>
                </select>
            </div>

            <div>
                <label class="rotulo" for="titulo">Título</label>
                <input id="titulo" v-model="formulario.titulo" type="text" class="campo" placeholder="Audiência de instrução">
                <p v-if="formulario.errors.titulo" class="mt-1 text-sm text-red-600">{{ formulario.errors.titulo }}</p>
            </div>

            <div>
                <label class="rotulo" for="inicio">Quando</label>
                <input id="inicio" v-model="formulario.inicio" type="datetime-local" class="campo">
                <p v-if="formulario.errors.inicio" class="mt-1 text-sm text-red-600">{{ formulario.errors.inicio }}</p>
            </div>

            <div>
                <label class="rotulo" for="processo">Caso</label>
                <select id="processo" v-model="formulario.processo_id" class="campo">
                    <option :value="null">Sem caso vinculado</option>
                    <option v-for="processo in processos" :key="processo.id" :value="processo.id">
                        {{ processo.rotulo }}
                    </option>
                </select>
            </div>

            <template v-if="formulario.tipo === 'audiencia'">
                <div>
                    <label class="rotulo" for="local">Local</label>
                    <input id="local" v-model="formulario.local" type="text" class="campo" placeholder="Fórum Henoch Reis, sala 3">
                </div>

                <div>
                    <label class="rotulo" for="link">Link da videoconferência</label>
                    <input id="link" v-model="formulario.link" type="url" class="campo" placeholder="https://">
                </div>

                <div>
                    <label class="rotulo" for="deslocamento">Lembrete de deslocamento (minutos antes)</label>
                    <input id="deslocamento" v-model.number="formulario.lembrete_deslocamento_min" type="number" min="0" max="480" class="campo">
                </div>
            </template>

            <div>
                <label class="rotulo" for="descricao">Descrição</label>
                <textarea id="descricao" v-model="formulario.descricao" rows="3" class="campo" />
            </div>
        </div>

        <template #rodape>
            <button type="button" class="btn-primario w-full" :disabled="formulario.processing" @click="enviar">
                Salvar
            </button>
        </template>
    </Folha>
</template>
