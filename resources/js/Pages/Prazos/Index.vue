<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { dataCurta, contagem, CORES_CRITICIDADE, BARRA_CRITICIDADE, ROTULO_STATUS_PRAZO, hoje } from '../../formato';
import Cabecalho from '../../Components/Cabecalho.vue';
import CadeiaOrigem from '../../Components/CadeiaOrigem.vue';
import Folha from '../../Components/Folha.vue';
import Icone from '../../Components/Icone.vue';
import Vazio from '../../Components/Vazio.vue';

const props = defineProps({
    prazos: { type: Object, required: true },
    filtros: { type: Object, required: true },
    tipos_prazo: { type: Array, default: () => [] },
    processos: { type: Array, default: () => [] },
});

const abasFiltro = [
    { chave: 'abertos', rotulo: 'Em aberto' },
    { chave: 'cumprido', rotulo: 'Cumpridos' },
    { chave: 'perdido', rotulo: 'Perdidos' },
    { chave: 'prejudicado', rotulo: 'Prejudicados' },
];

function filtrar(status) {
    router.get('/prazos', { status }, { preserveState: true, preserveScroll: true });
}

/* ---------------- Novo prazo, com prévia do cálculo ---------------- */

const folhaAberta = ref(false);
const previa = ref(null);
const calculando = ref(false);

const formulario = useForm({
    processo_id: null,
    tipo_prazo_id: null,
    tipo: '',
    dias: 15,
    em_dias_uteis: true,
    multiplicador: 1,
    multiplicador_motivo: '',
    data_disponibilizacao: hoje(),
    data_inicio_forcada: '',
    buffer_dias: null,
    observacoes: '',
});

function aoEscolherTipo(id) {
    const tipo = props.tipos_prazo.find((t) => t.id === Number(id));
    if (!tipo) return;
    formulario.tipo = tipo.nome;
    formulario.dias = tipo.dias;
    formulario.em_dias_uteis = tipo.em_dias_uteis;
}

/**
 * A prévia existe para a advogada ver a cadeia ANTES de gravar. O cálculo mora
 * no servidor: uma segunda implementação em JS seria uma segunda fonte de erro.
 */
async function calcularPrevia() {
    if (!formulario.data_disponibilizacao || !formulario.dias) return;

    calculando.value = true;

    const parametros = new URLSearchParams({
        data_disponibilizacao: formulario.data_disponibilizacao,
        dias: formulario.dias,
        em_dias_uteis: formulario.em_dias_uteis ? 1 : 0,
        multiplicador: formulario.multiplicador,
    });

    if (formulario.processo_id) parametros.set('processo_id', formulario.processo_id);
    if (formulario.buffer_dias !== null) parametros.set('buffer_dias', formulario.buffer_dias);
    if (formulario.data_inicio_forcada) parametros.set('data_inicio_forcada', formulario.data_inicio_forcada);

    try {
        const resposta = await fetch(`/prazos/simular?${parametros}`, {
            headers: { Accept: 'application/json' },
        });

        previa.value = resposta.ok ? await resposta.json() : null;
    } catch {
        previa.value = null;
    } finally {
        calculando.value = false;
    }
}

watch(
    () => [
        formulario.data_disponibilizacao,
        formulario.dias,
        formulario.em_dias_uteis,
        formulario.multiplicador,
        formulario.processo_id,
        formulario.data_inicio_forcada,
    ],
    () => {
        if (folhaAberta.value) calcularPrevia();
    }
);

function abrir() {
    folhaAberta.value = true;
    calcularPrevia();
}

function enviar() {
    formulario.post('/prazos', {
        onSuccess: () => {
            folhaAberta.value = false;
            formulario.reset();
            previa.value = null;
        },
    });
}
</script>

<template>
    <Head title="Prazos" />

    <Cabecalho titulo="Prazos" :subtitulo="`${prazos.total} no total`">
        <template #acoes>
            <button
                type="button"
                class="flex h-11 w-11 items-center justify-center rounded-full bg-slate-900 text-white"
                aria-label="Novo prazo"
                @click="abrir"
            >
                <Icone nome="mais_circulo" class="h-5 w-5" />
            </button>
        </template>
    </Cabecalho>

    <div class="pagina">
        <div class="flex gap-2 overflow-x-auto px-4 py-3 lg:flex-wrap lg:overflow-visible">
            <button
                v-for="filtro in abasFiltro"
                :key="filtro.chave"
                type="button"
                class="shrink-0 rounded-full px-3.5 py-2 text-sm font-medium"
                :class="filtros.status === filtro.chave ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200'"
                @click="filtrar(filtro.chave)"
            >
                {{ filtro.rotulo }}
            </button>
        </div>

        <p v-if="filtros.revisao" class="mx-4 mb-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
            Mostrando só os prazos que mudaram de data depois de uma alteração no calendário.
        </p>

        <Vazio
            v-if="!prazos.data.length"
            titulo="Nenhum prazo aqui"
            texto="Prazos nascem da triagem de uma publicação, ou você pode lançar um à mão pelo botão no topo."
        />

        <ul v-else class="space-y-2 px-4 pb-8 lg:grid lg:grid-cols-2 lg:gap-3 lg:space-y-0">
            <li v-for="prazo in prazos.data" :key="prazo.id">
                <Link
                    :href="`/prazos/${prazo.id}`"
                    class="flex overflow-hidden rounded-2xl bg-white ring-1"
                    :class="CORES_CRITICIDADE[prazo.criticidade]"
                >
                    <span class="w-1.5 shrink-0" :class="BARRA_CRITICIDADE[prazo.criticidade]" />

                    <span class="min-w-0 flex-1 p-3.5">
                        <span class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block truncate font-semibold">{{ prazo.tipo }}</span>
                                <span v-if="prazo.processo" class="mt-0.5 block truncate text-sm opacity-80">
                                    {{ prazo.processo.cliente ?? 'sem cliente' }} · {{ prazo.processo.rotulo }}
                                </span>
                            </span>
                            <span class="shrink-0 text-right">
                                <span class="block font-mono text-sm font-bold">{{ dataCurta(prazo.data_fatal) }}</span>
                                <span class="block text-xs font-medium">
                                    {{ ['cumprido', 'perdido', 'prejudicado'].includes(prazo.status)
                                        ? ROTULO_STATUS_PRAZO[prazo.status]
                                        : contagem(prazo.dias_restantes) }}
                                </span>
                            </span>
                        </span>

                        <span v-if="prazo.ajustado_manualmente || prazo.precisa_revisao" class="mt-2 flex flex-wrap gap-1.5">
                            <span v-if="prazo.ajustado_manualmente" class="etiqueta bg-white/70 text-[11px]">ajustado à mão</span>
                            <span v-if="prazo.precisa_revisao" class="etiqueta bg-amber-200 text-[11px] text-amber-900">revisar</span>
                        </span>
                    </span>
                </Link>
            </li>
        </ul>
    </div>

    <Folha :aberta="folhaAberta" titulo="Novo prazo" @fechar="folhaAberta = false">
        <div class="space-y-4">
            <div>
                <label class="rotulo" for="processo">Caso</label>
                <select id="processo" v-model="formulario.processo_id" class="campo">
                    <option :value="null">Sem caso vinculado</option>
                    <option v-for="processo in processos" :key="processo.id" :value="processo.id">
                        {{ processo.rotulo }}
                    </option>
                </select>
                <p class="mt-1 text-xs text-slate-500">O caso define quais feriados de tribunal entram na conta.</p>
            </div>

            <div>
                <label class="rotulo" for="catalogo">Tipo de prazo</label>
                <select id="catalogo" v-model="formulario.tipo_prazo_id" class="campo" @change="aoEscolherTipo($event.target.value)">
                    <option :value="null">Escolher do catálogo…</option>
                    <option v-for="tipo in tipos_prazo" :key="tipo.id" :value="tipo.id">
                        {{ tipo.nome }} — {{ tipo.dias }} dias {{ tipo.em_dias_uteis ? 'úteis' : 'corridos' }}
                    </option>
                </select>
            </div>

            <div>
                <label class="rotulo" for="rotulo">Nome do prazo</label>
                <input id="rotulo" v-model="formulario.tipo" type="text" class="campo" placeholder="Contestação">
                <p v-if="formulario.errors.tipo" class="mt-1 text-sm text-red-600">{{ formulario.errors.tipo }}</p>
            </div>

            <div>
                <label class="rotulo" for="disponibilizacao">Data de disponibilização no diário</label>
                <input id="disponibilizacao" v-model="formulario.data_disponibilizacao" type="date" class="campo">
                <p class="mt-1 text-xs text-slate-500">
                    É a data que sai no DJEN — não a da publicação, nem a do início da contagem.
                </p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="rotulo" for="dias">Dias</label>
                    <input id="dias" v-model.number="formulario.dias" type="number" min="1" max="365" class="campo">
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
                    <span class="block text-xs leading-relaxed text-slate-500">
                        Fazenda, Defensoria ou MP. Sugerido, nunca automático.
                    </span>
                </span>
            </label>

            <details class="rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                <summary class="cursor-pointer text-sm font-medium text-slate-700">Casos especiais</summary>

                <div class="mt-3 space-y-3">
                    <div>
                        <label class="rotulo" for="inicio-forcado">Início informado manualmente</label>
                        <input id="inicio-forcado" v-model="formulario.data_inicio_forcada" type="date" class="campo">
                        <p class="mt-1 text-xs text-slate-500">
                            Para intimação pessoal, carga ou vista dos autos, quando não há disponibilização no diário.
                        </p>
                    </div>

                    <div>
                        <label class="rotulo" for="buffer">Folga antes do fatal (dias úteis)</label>
                        <input id="buffer" v-model.number="formulario.buffer_dias" type="number" min="0" max="15" class="campo" placeholder="usar o padrão da conta">
                    </div>
                </div>
            </details>

            <div>
                <label class="rotulo" for="obs">Observações</label>
                <textarea id="obs" v-model="formulario.observacoes" rows="2" class="campo" />
            </div>

            <!-- Prévia: a cadeia antes de gravar. -->
            <div v-if="calculando" class="rounded-2xl bg-white p-4 text-center text-sm text-slate-500 ring-1 ring-slate-200">
                Calculando…
            </div>

            <div v-else-if="previa" class="space-y-2">
                <div class="rounded-2xl bg-slate-900 p-4 text-center text-white">
                    <p class="text-xs font-medium uppercase tracking-wide opacity-70">Data fatal</p>
                    <p class="font-mono text-2xl font-bold">{{ dataCurta(previa.data_fatal) }}</p>
                    <p class="mt-1 text-xs opacity-70">
                        alvo interno em {{ dataCurta(previa.data_alvo) }}
                    </p>
                </div>

                <CadeiaOrigem :cadeia="previa.cadeia_origem" />
            </div>
        </div>

        <template #rodape>
            <button type="button" class="btn-primario w-full" :disabled="formulario.processing" @click="enviar">
                Criar prazo
            </button>
        </template>
    </Folha>
</template>
