<script setup>
import Icone from './Icone.vue';

/**
 * Estado vazio.
 *
 * Numa tela chamada "Hoje", vazio costuma ser o melhor resultado que o produto
 * entrega — e duas linhas de texto cinza são indistinguíveis de um
 * carregamento que falhou. Quando a tela vazia é uma vitória, quem chama passa
 * um ícone e o tom; nos demais casos o bloco continua sendo só texto.
 */
defineProps({
    titulo: { type: String, required: true },
    texto: { type: String, default: null },
    icone: { type: String, default: null },
    tom: { type: String, default: 'neutro' },
});

const TONS = {
    ok: 'bg-ok-fundo text-ok',
    atencao: 'bg-atencao-fundo text-atencao-tinta',
    neutro: 'bg-superficie-3 text-tinta-3',
};
</script>

<template>
    <div class="px-6 py-14 text-center">
        <span
            v-if="icone"
            class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full"
            :class="TONS[tom] ?? TONS.neutro"
        >
            <Icone :nome="icone" class="h-7 w-7" />
        </span>

        <p class="text-base font-semibold text-tinta">{{ titulo }}</p>
        <p v-if="texto" class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-tinta-3">
            {{ texto }}
        </p>
        <div class="mt-5">
            <slot />
        </div>
    </div>
</template>
