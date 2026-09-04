<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { iniciais } from '../../formato';
import Cabecalho from '../../Components/Cabecalho.vue';
import Folha from '../../Components/Folha.vue';
import Icone from '../../Components/Icone.vue';
import Vazio from '../../Components/Vazio.vue';

const props = defineProps({
    clientes: { type: Object, required: true },
    filtros: { type: Object, required: true },
});

const busca = ref(props.filtros.busca ?? '');
let temporizador = null;

watch(busca, (valor) => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get('/clientes', { busca: valor || undefined }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 350);
});

const folhaAberta = ref(false);

const formulario = useForm({
    nome: '',
    documento: '',
    contatos: [{ tipo: 'whatsapp', valor: '' }],
    endereco: { logradouro: '', numero: '', bairro: '', cidade: '', uf: '', cep: '' },
    origem: '',
    observacoes: '',
});

function adicionarContato() {
    formulario.contatos.push({ tipo: 'telefone', valor: '' });
}

function enviar() {
    formulario
        .transform((dados) => ({
            ...dados,
            contatos: dados.contatos.filter((c) => c.valor.trim()),
        }))
        .post('/clientes', {
            onSuccess: () => {
                folhaAberta.value = false;
                formulario.reset();
            },
        });
}
</script>

<template>
    <Head title="Clientes" />

    <Cabecalho titulo="Clientes" :subtitulo="`${clientes.total} ${clientes.total === 1 ? 'pessoa' : 'pessoas'}`">
        <template #acoes>
            <button
                type="button"
                class="flex h-11 w-11 items-center justify-center rounded-full bg-slate-900 text-white"
                aria-label="Novo cliente"
                @click="folhaAberta = true"
            >
                <Icone nome="mais_circulo" class="h-5 w-5" />
            </button>
        </template>
    </Cabecalho>

    <div class="pagina">
        <div class="px-4 py-3">
            <div class="relative lg:max-w-md">
                <Icone nome="busca" class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
                <input v-model="busca" type="search" class="campo pl-10" placeholder="Nome ou CPF" aria-label="Buscar clientes">
            </div>
        </div>

        <Vazio
            v-if="!clientes.data.length"
            :titulo="filtros.busca ? 'Ninguém encontrado' : 'Nenhum cliente cadastrado'"
            texto="Cadastre a pessoa uma vez e vincule os casos dela depois."
        />

        <ul v-else class="cartao mx-4 mb-8 divide-y divide-slate-100 lg:grid lg:grid-cols-2 lg:gap-3 lg:divide-y-0 lg:border-0 lg:bg-transparent">
            <li v-for="cliente in clientes.data" :key="cliente.id" class="lg:rounded-2xl lg:border lg:border-slate-200 lg:bg-white">
                <Link :href="`/clientes/${cliente.id}`" class="flex items-center gap-3 p-3.5">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-slate-200 text-sm font-bold text-slate-600">
                        {{ iniciais(cliente.nome) }}
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium text-slate-900">{{ cliente.nome }}</span>
                        <span class="block truncate text-sm text-slate-500">
                            {{ [cliente.telefone, cliente.documento].filter(Boolean).join(' · ') || 'sem contato' }}
                        </span>
                    </span>

                    <span v-if="cliente.processos_ativos" class="shrink-0 text-xs font-semibold text-slate-500">
                        {{ cliente.processos_ativos }} {{ cliente.processos_ativos === 1 ? 'caso' : 'casos' }}
                    </span>
                </Link>
            </li>
        </ul>
    </div>

    <Folha :aberta="folhaAberta" titulo="Novo cliente" @fechar="folhaAberta = false">
        <div class="space-y-4">
            <div>
                <label class="rotulo" for="nome">Nome completo</label>
                <input id="nome" v-model="formulario.nome" type="text" class="campo">
                <p v-if="formulario.errors.nome" class="mt-1 text-sm text-red-600">{{ formulario.errors.nome }}</p>
            </div>

            <div>
                <label class="rotulo" for="documento">CPF ou CNPJ</label>
                <input id="documento" v-model="formulario.documento" type="text" inputmode="numeric" class="campo">
            </div>

            <div>
                <span class="rotulo">Contatos</span>
                <div v-for="(contato, indice) in formulario.contatos" :key="indice" class="mb-2 flex gap-2">
                    <select v-model="contato.tipo" class="campo w-32 shrink-0">
                        <option value="whatsapp">WhatsApp</option>
                        <option value="telefone">Telefone</option>
                        <option value="email">E-mail</option>
                        <option value="outro">Outro</option>
                    </select>
                    <input v-model="contato.valor" type="text" class="campo" placeholder="(92) 90000-0000">
                </div>
                <button type="button" class="text-sm font-medium text-sky-700" @click="adicionarContato">
                    + adicionar contato
                </button>
            </div>

            <details class="rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                <summary class="cursor-pointer text-sm font-medium text-slate-700">Endereço</summary>
                <div class="mt-3 space-y-3">
                    <input v-model="formulario.endereco.logradouro" type="text" class="campo" placeholder="Rua">
                    <div class="grid grid-cols-2 gap-3">
                        <input v-model="formulario.endereco.numero" type="text" class="campo" placeholder="Número">
                        <input v-model="formulario.endereco.bairro" type="text" class="campo" placeholder="Bairro">
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <input v-model="formulario.endereco.cidade" type="text" class="campo col-span-2" placeholder="Cidade">
                        <input v-model="formulario.endereco.uf" type="text" maxlength="2" class="campo uppercase" placeholder="UF">
                    </div>
                    <input v-model="formulario.endereco.cep" type="text" class="campo" placeholder="CEP">
                </div>
            </details>

            <div>
                <label class="rotulo" for="origem">Como chegou até você</label>
                <input id="origem" v-model="formulario.origem" type="text" class="campo" placeholder="Indicação da dona Ana">
            </div>

            <div>
                <label class="rotulo" for="obs">Observações</label>
                <textarea id="obs" v-model="formulario.observacoes" rows="3" class="campo" />
            </div>
        </div>

        <template #rodape>
            <button type="button" class="btn-primario w-full" :disabled="formulario.processing" @click="enviar">
                Cadastrar
            </button>
        </template>
    </Folha>
</template>
