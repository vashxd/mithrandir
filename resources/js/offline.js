import Dexie from 'dexie';

/**
 * Espelho local (IndexedDB) + outbox de escritas offline.
 *
 * Secao 10:
 *  - leitura (agenda, casos, clientes) espelhada aqui;
 *  - escrita offline entra no outbox e sobe no replay;
 *  - anexos vão em fila separada, com indicador de "N fotos aguardando envio".
 */
const db = new Dexie('mithrandir');

db.version(1).stores({
    snapshot: 'chave',
    outbox: '++id, client_id, entidade, status, criado_em',
    anexos: '++id, status, criado_em',
});

export function online() {
    return navigator.onLine;
}

/* ------------------------------------------------------------------ */
/* Espelho de leitura                                                  */
/* ------------------------------------------------------------------ */

export async function guardarSnapshot(dados) {
    await db.snapshot.put({ chave: 'atual', dados, em: new Date().toISOString() });
}

export async function lerSnapshot() {
    return (await db.snapshot.get('atual'))?.dados ?? null;
}

export async function baixarSnapshot() {
    if (!online()) return lerSnapshot();

    try {
        const resposta = await fetch('/sync/snapshot', {
            headers: { Accept: 'application/json' },
        });

        if (!resposta.ok) return lerSnapshot();

        const dados = await resposta.json();
        await guardarSnapshot(dados);

        return dados;
    } catch {
        return lerSnapshot();
    }
}

/* ------------------------------------------------------------------ */
/* Outbox de escritas                                                  */
/* ------------------------------------------------------------------ */

export async function enfileirar(entidade, operacao, payload) {
    const item = {
        client_id: crypto.randomUUID(),
        entidade,
        operacao,
        payload,
        criado_em: new Date().toISOString(),
        status: 'pendente',
    };

    await db.outbox.add(item);

    if (online()) {
        // Não bloqueia a UI: a tela já mostrou o resultado otimista.
        replay().catch(() => {});
    }

    return item.client_id;
}

export async function pendentes() {
    return db.outbox.where('status').equals('pendente').toArray();
}

export async function replay() {
    const itens = await pendentes();

    if (itens.length === 0 || !online()) {
        return { enviados: 0 };
    }

    const resposta = await fetch('/sync/outbox', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({
            itens: itens.map(({ client_id, entidade, operacao, payload, criado_em }) => ({
                client_id,
                entidade,
                operacao,
                payload,
                criado_em,
            })),
        }),
    });

    if (!resposta.ok) {
        return { enviados: 0 };
    }

    const { resultados } = await resposta.json();

    for (const resultado of resultados) {
        const local = await db.outbox.where('client_id').equals(resultado.client_id).first();
        if (!local) continue;

        await db.outbox.update(local.id, {
            status: resultado.status,
            server_id: resultado.server_id ?? null,
        });
    }

    return { enviados: resultados.filter((r) => r.status === 'aplicado').length };
}

/**
 * Itens que precisam de olho humano: prazo e financeiro nunca resolvem sozinhos.
 */
export async function conflitos() {
    return db.outbox.where('status').anyOf('conflito', 'erro').toArray();
}

export async function descartarConflito(id) {
    await db.outbox.delete(id);
}

/* ------------------------------------------------------------------ */
/* Fila de anexos (foto tirada sem sinal)                              */
/* ------------------------------------------------------------------ */

export async function enfileirarAnexo({ blob, nome, tipo, processo_id, cliente_id, checklist_item_id }) {
    await db.anexos.add({
        blob,
        nome,
        tipo,
        processo_id: processo_id ?? null,
        cliente_id: cliente_id ?? null,
        checklist_item_id: checklist_item_id ?? null,
        status: 'pendente',
        criado_em: new Date().toISOString(),
    });

    if (online()) {
        enviarAnexos().catch(() => {});
    }
}

export async function anexosPendentes() {
    return db.anexos.where('status').equals('pendente').count();
}

/**
 * RF-6.4: a foto tirada sem sinal entra na fila e sobe depois.
 */
export async function enviarAnexos() {
    if (!online()) return { enviados: 0 };

    const fila = await db.anexos.where('status').equals('pendente').toArray();
    let enviados = 0;

    for (const anexo of fila) {
        const formulario = new FormData();
        formulario.append('arquivo', anexo.blob, anexo.nome);
        formulario.append('origem', 'camera');
        if (anexo.tipo) formulario.append('tipo', anexo.tipo);
        if (anexo.processo_id) formulario.append('processo_id', anexo.processo_id);
        if (anexo.cliente_id) formulario.append('cliente_id', anexo.cliente_id);
        if (anexo.checklist_item_id) formulario.append('checklist_item_id', anexo.checklist_item_id);

        try {
            const resposta = await fetch('/documentos', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formulario,
            });

            if (resposta.ok || resposta.status === 302) {
                await db.anexos.delete(anexo.id);
                enviados++;
            }
        } catch {
            // Sem sinal de novo: fica na fila para a próxima tentativa.
            break;
        }
    }

    return { enviados };
}

/* ------------------------------------------------------------------ */
/* Reconexão                                                           */
/* ------------------------------------------------------------------ */

export function observarConexao(aoMudar) {
    const notificar = () => aoMudar(navigator.onLine);

    window.addEventListener('online', async () => {
        notificar();
        await replay().catch(() => {});
        await enviarAnexos().catch(() => {});
        await baixarSnapshot().catch(() => {});
    });

    window.addEventListener('offline', notificar);

    return () => {
        window.removeEventListener('online', notificar);
        window.removeEventListener('offline', notificar);
    };
}

export default db;
