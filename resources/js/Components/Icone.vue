<script setup>
/**
 * Ícones inline em SVG. Sem biblioteca externa: o orçamento é 250 KB gzip
 * para o bundle inteiro (seção 12).
 */
defineProps({
    nome: { type: String, required: true },
});

const caminhos = {
    hoje: 'M4 5h16a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm0 5h17M8 3v4m8-4v4',
    agenda: 'M12 7v5l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    casos: 'M4 7h16v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7Zm5 0V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M9 12h6',
    mais: 'M4 7h16M4 12h16M4 17h16',
    seta: 'm9 5 7 7-7 7',
    voltar: 'm15 5-7 7 7 7',
    alerta: 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z',
    camera: 'M3 9a2 2 0 0 1 2-2h1.6l1.2-2h8.4l1.2 2H19a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9Zm9 9a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
    documento: 'M14 3v5h5M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8l-5-5Z',
    dinheiro: 'M12 2v20m5-16H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
    pessoa: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
    sino: 'M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0',
    check: 'm5 13 4 4L19 7',
    x: 'M6 6l12 12M18 6 6 18',
    mais_circulo: 'M12 5v14m-7-7h14',
    relogio: 'M12 7v5l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    balanca: 'M12 3v18m-8-5 4-9 4 9m-8 0a4 4 0 0 0 8 0M12 6l8 2m-8 8 4-9 4 9m-8 0a4 4 0 0 0 8 0M6 21h12',
    busca: 'm21 21-4.3-4.3M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z',
    filtro: 'M3 5h18l-7 8v6l-4 2v-8L3 5Z',
    sincronizar: 'M20 11A8 8 0 0 0 6.3 6.3L3 9m1 4a8 8 0 0 0 13.7 4.7L21 15M3 4v5h5m8 6h5v5',
    engrenagem: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1v.3a2 2 0 1 1-4 0v-.2a1.6 1.6 0 0 0-2.8-1.1l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0-1.1-2.7H3a2 2 0 1 1 0-4h.2A1.6 1.6 0 0 0 4.3 6l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 2.7-1.1V2a2 2 0 1 1 4 0v.2a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0 1.1 2.7H22a2 2 0 1 1 0 4h-.2a1.6 1.6 0 0 0-1.4 1Z',
    equipe: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8',
    sair: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4m7 14 5-5-5-5m5 5H9',
};
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <path :d="caminhos[nome] ?? caminhos.seta" />
    </svg>
</template>
