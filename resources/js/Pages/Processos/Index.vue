<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Cabecalho from '../../Components/Cabecalho.vue';
import Icone from '../../Components/Icone.vue';
import Vazio from '../../Components/Vazio.vue';

const props = defineProps({
    processos: { type: Object, required: true },
    filtros: { type: Object, required: true },
});

const busca = ref(props.filtros.busca ?? '');
let temporizador = null;

watch(busca, (valor) => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get(
            '/casos',
            { busca: valor || undefined, arquivados: props.filtros.arquivados || undefined },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    }, 350);
});

function alternarArquivados() {
    router.get(
        '/casos',
        { busca: busca.value || undefined, arquivados: props.filtros.arquivados ? undefined : 1 },
        { preserveState: true, preserveScroll: true }
    );
}
</script>

<template>
    <Head title="Casos" />

    <Cabecalho titulo="Casos" :subtitulo="`${processos.total} ${processos.total === 1 ? 'caso' : 'casos'}`">
        <template #acoes>
            <Link
                href="/casos/novo"
                class="flex h-11 w-11 items-center justify-center rounded-full bg-slate-900 text-white"
                aria-label="Novo caso"
            >
                <Icone nome="mais_circulo" class="h-5 w-5" />
            </Link>
        </template>
    </Cabecalho>

    <div class="pagina">
        <div class="px-4 py-3">
            <div class="relative lg:max-w-md">
                <Icone nome="busca" class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
                <input
                    v-model="busca"
                    type="search"
                    class="campo pl-10"
                    placeholder="Cliente, número CNJ ou assunto"
                    aria-label="Buscar casos"
                >
            </div>

            <button
                type="button"
                class="mt-2 text-sm font-medium text-sky-700"
                @click="alternarArquivados"
            >
                {{ filtros.arquivados ? 'Ver só os ativos' : 'Incluir arquivados' }}
            </button>
        </div>

        <Vazio
            v-if="!processos.data.length"
            :titulo="filtros.busca ? 'Nenhum caso encontrado' : 'Nenhum caso cadastrado'"
            :texto="filtros.busca
                ? 'Tente outro nome ou número.'
                : 'Casos também nascem sozinhos quando você tria uma publicação com número de processo.'"
        >
            <Link href="/casos/novo" class="btn-primario">Cadastrar caso</Link>
        </Vazio>

        <ul v-else class="space-y-2 px-4 pb-8 lg:grid lg:grid-cols-2 lg:gap-3 lg:space-y-0 xl:grid-cols-3">
            <li v-for="processo in processos.data" :key="processo.id">
                <Link :href="`/casos/${processo.id}`" class="block cartao p-4" :class="processo.arquivado ? 'opacity-60' : ''">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-slate-900">
                                {{ processo.cliente ?? 'Sem cliente' }}
                            </p>
                            <p class="truncate text-sm text-slate-600">{{ processo.rotulo }}</p>
                        </div>

                        <span
                            v-if="processo.prazos_abertos"
                            class="shrink-0 rounded-full bg-slate-900 px-2 py-0.5 text-xs font-bold text-white"
                        >
                            {{ processo.prazos_abertos }}
                        </span>
                    </div>

                    <p v-if="processo.proxima_acao" class="mt-2 line-clamp-2 rounded-lg bg-amber-50 px-2.5 py-1.5 text-sm text-amber-900">
                        {{ processo.proxima_acao }}
                    </p>

                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <span v-if="processo.tribunal" class="etiqueta bg-slate-100 text-slate-600">{{ processo.tribunal }}</span>
                        <span v-if="processo.fase" class="etiqueta bg-slate-100 text-slate-600">{{ processo.fase }}</span>
                        <span v-if="processo.area" class="etiqueta bg-slate-100 text-slate-600">{{ processo.area }}</span>
                        <span v-if="processo.arquivado" class="etiqueta bg-slate-200 text-slate-700">arquivado</span>
                    </div>
                </Link>
            </li>
        </ul>

        <div v-if="processos.links?.length > 3" class="flex justify-center gap-1 px-4 pb-8">
            <Link
                v-for="link in processos.links"
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
