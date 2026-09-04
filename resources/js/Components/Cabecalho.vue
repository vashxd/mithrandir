<script setup>
import { Link } from '@inertiajs/vue3';
import Icone from './Icone.vue';

defineProps({
    titulo: { type: String, required: true },
    subtitulo: { type: String, default: null },
    voltarPara: { type: String, default: null },
    // Telas de leitura e formulario usam a coluna estreita; o cabecalho
    // precisa acompanhar, senao o titulo fica solto a esquerda do conteudo.
    estreito: { type: Boolean, default: false },
});
</script>

<template>
    <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/85 py-3 backdrop-blur-md lg:py-5">
        <div class="flex items-center gap-3 px-4 lg:px-8" :class="estreito ? 'pagina-estreita' : 'pagina'">
            <Link
                v-if="voltarPara"
                :href="voltarPara"
                class="-ml-2 flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-slate-600 transition active:bg-slate-100 lg:h-9 lg:w-9 lg:hover:bg-slate-100"
                aria-label="Voltar"
            >
                <Icone nome="voltar" class="h-6 w-6 lg:h-5 lg:w-5" />
            </Link>

            <div class="min-w-0 flex-1">
                <h1 class="truncate text-lg font-bold text-slate-900 lg:text-2xl">{{ titulo }}</h1>
                <p v-if="subtitulo" class="truncate text-sm text-slate-500 lg:mt-0.5">{{ subtitulo }}</p>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <slot name="acoes" />
            </div>
        </div>
    </header>
</template>
