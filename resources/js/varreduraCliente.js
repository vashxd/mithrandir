/**
 * Varredura do DJEN feita pelo navegador.
 *
 * Por que existe: a API de comunicações do CNJ responde 403 para IP
 * estrangeiro. Onde o servidor está hospedado fora do Brasil, ele não alcança
 * o DJEN — mas o navegador de quem usa o app alcança, e a API libera CORS
 * (`Access-Control-Allow-Origin: *`) para qualquer origem.
 *
 * Limites conscientes deste caminho:
 *  - só roda quando alguém abre o app. Não substitui o scheduler diário, e é
 *    por isso que o shell mostra há quanto tempo o radar não é varrido;
 *  - o cliente não decide nada: o servidor manda os parâmetros e a janela, e
 *    valida tudo o que volta. Daqui sai payload cru, nunca campo interpretado.
 */

import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const TEMPO_LIMITE_MS = 30_000;

/**
 * Resultado da última varredura manual.
 *
 * Quando quem busca é o navegador, não há round-trip ao servidor para trazer
 * um flash de sessão — então o aviso mora aqui e o shell o renderiza no mesmo
 * lugar dos outros. Um `ref` no módulo em vez de repetir a UI em três telas.
 */
export const avisoVarredura = ref(null);

/** Uma varredura por vez nesta aba, mesmo que duas telas peçam junto. */
let emAndamento = null;

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * A API combina `Access-Control-Allow-Origin: *` com
 * `Access-Control-Allow-Credentials: true`, que é combinação inválida pelo
 * spec — o navegador recusa a resposta se a requisição levar credencial.
 * `credentials: 'omit'` é o que mantém isto funcionando.
 */
async function buscarPagina(url, parametros, pagina, itensPorPagina) {
    const query = new URLSearchParams({
        ...parametros,
        pagina: String(pagina),
        itensPorPagina: String(itensPorPagina),
    });

    const resposta = await fetch(`${url}?${query}`, {
        method: 'GET',
        credentials: 'omit',
        headers: { Accept: 'application/json' },
        signal: AbortSignal.timeout(TEMPO_LIMITE_MS),
    });

    if (!resposta.ok) {
        throw new Error(`DJEN respondeu ${resposta.status}.`);
    }

    return resposta.json();
}

/** Mesma paginação do DjenClient no servidor, para os dois cobrirem o mesmo. */
async function buscarTudo(url, parametros, itensPorPagina, maxPaginas) {
    const todos = [];
    let pagina = 1;

    while (pagina <= maxPaginas) {
        const corpo = await buscarPagina(url, parametros, pagina, itensPorPagina);
        const itens = Array.isArray(corpo?.items) ? corpo.items : [];
        const total = Number(corpo?.count ?? itens.length);

        todos.push(...itens);

        if (itens.length < itensPorPagina || todos.length >= total) {
            break;
        }

        pagina += 1;
    }

    return todos;
}

async function pedirPlano(forcar) {
    const resposta = await fetch(`/publicacoes/varredura/plano${forcar ? '?forcar=1' : ''}`, {
        headers: { Accept: 'application/json' },
    });

    if (!resposta.ok) {
        return null;
    }

    return resposta.json();
}

async function entregar(corpo) {
    const resposta = await fetch('/publicacoes/varredura', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify(corpo),
    });

    // Recusa explicada (422): o servidor ja gravou a falha no historico do
    // radar. Devolver o corpo em vez de estourar evita registrar a mesma
    // falha duas vezes, e preserva a mensagem real em lugar de
    // "o servidor recusou o lote".
    if (resposta.status === 422) {
        return resposta.json().catch(() => ({ falhou: true, novas: 0 }));
    }

    if (!resposta.ok) {
        throw new Error(`O servidor recusou o lote (${resposta.status}).`);
    }

    return resposta.json();
}

async function executar(forcar) {
    if (!navigator.onLine) {
        return { executou: false, novas: 0, falhas: 0 };
    }

    const plano = await pedirPlano(forcar);

    if (!plano?.ativa || !plano.consultas?.length) {
        return { executou: false, novas: 0, falhas: 0 };
    }

    let novas = 0;
    let falhas = 0;

    for (const consulta of plano.consultas) {
        const janela = {
            watch_id: consulta.watch_id,
            janela_inicio: consulta.janela_inicio,
            janela_fim: consulta.janela_fim,
        };

        try {
            const itens = await buscarTudo(
                plano.url,
                consulta.parametros,
                plano.itens_por_pagina,
                plano.max_paginas
            );

            const resultado = await entregar({ ...janela, itens });
            novas += resultado?.novas ?? 0;

            if (resultado?.falhou) {
                falhas += 1;
            }
        } catch (erro) {
            falhas += 1;

            // A falha precisa chegar ao servidor: é ela que alimenta o
            // contador de "radar cego". Varredura que fracassa em silêncio é
            // pior do que varredura que não aconteceu.
            await entregar({
                ...janela,
                erro: String(erro?.message ?? erro).slice(0, 500),
            }).catch(() => {});
        }
    }

    return { executou: true, novas, falhas };
}

/**
 * @param {{forcar?: boolean}} opcoes  `forcar` ignora o intervalo mínimo entre
 *   varreduras — é o que o botão "sincronizar agora" usa.
 * @returns {Promise<{executou: boolean, novas: number, falhas: number}>}
 */
export function varrerPeloCliente({ forcar = false } = {}) {
    if (emAndamento) {
        return emAndamento;
    }

    emAndamento = executar(forcar)
        .catch(() => ({ executou: false, novas: 0, falhas: 0 }))
        .finally(() => {
            emAndamento = null;
        });

    return emAndamento;
}

/**
 * O botão "sincronizar agora". Tenta pelo navegador e, se esse caminho estiver
 * desligado ou não houver o que buscar, deixa o servidor tentar.
 */
export async function sincronizarAgora() {
    avisoVarredura.value = null;

    const resultado = await varrerPeloCliente({ forcar: true });

    if (!resultado.executou) {
        router.post('/publicacoes/sincronizar', {}, { preserveScroll: true });

        return resultado;
    }

    avisoVarredura.value = resultado.novas === 0 && resultado.falhas > 0
        ? { tom: 'erro', texto: 'Não foi possível falar com o DJEN agora. Confira o diário manualmente.' }
        : {
            tom: 'sucesso',
            texto: resultado.novas === 0
                ? 'Varredura concluída: nenhuma publicação nova.'
                : `Varredura concluída: ${resultado.novas} publicação(ões) nova(s).`,
        };

    router.reload({ preserveScroll: true });

    return resultado;
}

/**
 * Prévia de vigilância de um cliente, com o mesmo desvio da varredura.
 *
 * O servidor tenta contar sozinho; se o DJEN recusar o IP dele, devolve a
 * consulta para este navegador fazer e recebe de volta só o número. O texto e
 * o teto continuam saindo do servidor — julgamento não deve existir em duas
 * versões.
 */
export async function previaVigilancia(clienteId) {
    const resposta = await fetch(`/clientes/${clienteId}/vigilancia/previa`, {
        headers: { Accept: 'application/json' },
    });

    const previa = await resposta.json();

    if (!resposta.ok || !previa?.delegar) {
        return previa;
    }

    const corpo = await buscarPagina(previa.url, previa.parametros, 1, 1);
    const quantidade = Number(corpo?.count ?? 0);

    const interpretada = await fetch(`/clientes/${clienteId}/vigilancia/previa`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({ quantidade }),
    });

    return interpretada.json();
}
