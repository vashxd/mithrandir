<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import Cabecalho from '../../Components/Cabecalho.vue';

defineProps({
    clientes: { type: Array, default: () => [] },
});

const formulario = useForm({
    cliente_id: null,
    numero_cnj: '',
    titulo: '',
    tribunal: '',
    vara: '',
    classe: '',
    assunto: '',
    fase: 'conhecimento',
    area: '',
    valor_causa: null,
    proxima_acao: '',
    segredo_justica: false,
});

/** Máscara visual do número CNJ: NNNNNNN-DD.AAAA.J.TR.OOOO */
const numeroFormatado = computed(() => {
    const n = (formulario.numero_cnj ?? '').replace(/\D/g, '').slice(0, 20);

    if (n.length <= 7) return n;
    if (n.length <= 9) return `${n.slice(0, 7)}-${n.slice(7)}`;
    if (n.length <= 13) return `${n.slice(0, 7)}-${n.slice(7, 9)}.${n.slice(9)}`;
    if (n.length <= 14) return `${n.slice(0, 7)}-${n.slice(7, 9)}.${n.slice(9, 13)}.${n.slice(13)}`;
    if (n.length <= 16) return `${n.slice(0, 7)}-${n.slice(7, 9)}.${n.slice(9, 13)}.${n.slice(13, 14)}.${n.slice(14)}`;
    return `${n.slice(0, 7)}-${n.slice(7, 9)}.${n.slice(9, 13)}.${n.slice(13, 14)}.${n.slice(14, 16)}.${n.slice(16)}`;
});

function aoDigitarNumero(evento) {
    formulario.numero_cnj = evento.target.value.replace(/\D/g, '').slice(0, 20);
}

function enviar() {
    formulario.post('/casos');
}
</script>

<template>
    <Head title="Novo caso" />

    <Cabecalho titulo="Novo caso" voltar-para="/casos" estreito />

    <form class="pagina-estreita space-y-4 px-4 lg:px-8 py-4 pb-24" @submit.prevent="enviar">
        <div>
            <label class="rotulo" for="cliente">Cliente</label>
            <select id="cliente" v-model="formulario.cliente_id" class="campo">
                <option :value="null">Sem cliente por enquanto</option>
                <option v-for="cliente in clientes" :key="cliente.id" :value="cliente.id">{{ cliente.nome }}</option>
            </select>
        </div>

        <div>
            <label class="rotulo" for="numero">Número CNJ</label>
            <input
                id="numero"
                :value="numeroFormatado"
                type="text"
                inputmode="numeric"
                class="campo font-mono"
                placeholder="0000000-00.0000.0.00.0000"
                @input="aoDigitarNumero"
            >
            <p v-if="formulario.errors.numero_cnj" class="mt-1 text-sm text-red-600">{{ formulario.errors.numero_cnj }}</p>
            <p v-else class="mt-1 text-xs text-slate-500">O dígito verificador é conferido ao salvar.</p>
        </div>

        <div>
            <label class="rotulo" for="titulo">Apelido do caso</label>
            <input id="titulo" v-model="formulario.titulo" type="text" class="campo" placeholder="Aposentadoria da dona Maria">
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="rotulo" for="tribunal">Tribunal</label>
                <input id="tribunal" v-model="formulario.tribunal" type="text" class="campo" placeholder="TJAM">
            </div>
            <div>
                <label class="rotulo" for="area">Área</label>
                <select id="area" v-model="formulario.area" class="campo">
                    <option value="">Escolher…</option>
                    <option value="previdenciario">Previdenciário</option>
                    <option value="consumidor">Consumidor</option>
                    <option value="familia">Família</option>
                    <option value="civel">Cível</option>
                    <option value="trabalhista">Trabalhista</option>
                    <option value="penal">Penal</option>
                    <option value="outro">Outro</option>
                </select>
            </div>
        </div>

        <div>
            <label class="rotulo" for="vara">Vara</label>
            <input id="vara" v-model="formulario.vara" type="text" class="campo">
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="rotulo" for="classe">Classe</label>
                <input id="classe" v-model="formulario.classe" type="text" class="campo">
            </div>
            <div>
                <label class="rotulo" for="fase">Fase</label>
                <select id="fase" v-model="formulario.fase" class="campo">
                    <option value="administrativo">Administrativo</option>
                    <option value="conhecimento">Conhecimento</option>
                    <option value="recurso">Recurso</option>
                    <option value="execucao">Execução</option>
                </select>
            </div>
        </div>

        <div>
            <label class="rotulo" for="assunto">Assunto</label>
            <input id="assunto" v-model="formulario.assunto" type="text" class="campo" placeholder="Auxílio-doença">
        </div>

        <div>
            <label class="rotulo" for="valor">Valor da causa</label>
            <input id="valor" v-model.number="formulario.valor_causa" type="number" step="0.01" min="0" class="campo">
        </div>

        <div>
            <label class="rotulo" for="acao">Próxima ação</label>
            <textarea id="acao" v-model="formulario.proxima_acao" rows="3" class="campo" placeholder="O que você precisa lembrar sobre este caso" />
        </div>

        <label class="flex items-center gap-2.5 rounded-2xl bg-white p-4 ring-1 ring-slate-200">
            <input v-model="formulario.segredo_justica" type="checkbox" class="h-5 w-5 rounded border-slate-300">
            <span class="text-sm text-slate-800">Corre em segredo de justiça</span>
        </label>

        <button type="submit" class="btn-primario w-full" :disabled="formulario.processing">
            {{ formulario.processing ? 'Salvando…' : 'Criar caso' }}
        </button>
    </form>
</template>
