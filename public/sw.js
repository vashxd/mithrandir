/**
 * Service worker do Mithrandir.
 *
 * Estratégias (seção 10):
 *  - shell e assets: stale-while-revalidate;
 *  - navegação: network-first com fallback para o shell offline;
 *  - APIs de leitura: network-first com cache de emergência;
 *  - POST offline: nunca some — a tela enfileira no IndexedDB.
 */
const VERSAO = 'mithrandir-v1';
const CACHE_SHELL = `${VERSAO}-shell`;
const CACHE_ASSETS = `${VERSAO}-assets`;
const CACHE_DADOS = `${VERSAO}-dados`;

const SHELL = ['/offline.html', '/manifest.webmanifest', '/icons/icon.svg'];

self.addEventListener('install', (evento) => {
    evento.waitUntil(
        caches
            .open(CACHE_SHELL)
            .then((cache) => cache.addAll(SHELL))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (evento) => {
    evento.waitUntil(
        caches
            .keys()
            .then((chaves) =>
                Promise.all(
                    chaves
                        .filter((chave) => !chave.startsWith(VERSAO))
                        .map((chave) => caches.delete(chave))
                )
            )
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (evento) => {
    const { request } = evento;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) return;

    // Download de documento nunca entra em cache: é dado sigiloso (EOAB art. 34).
    if (url.pathname.startsWith('/documentos/') || url.pathname.startsWith('/configuracoes/exportar')) {
        return;
    }

    if (request.mode === 'navigate') {
        evento.respondWith(navegacao(request));
        return;
    }

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/')) {
        evento.respondWith(staleWhileRevalidate(request, CACHE_ASSETS));
        return;
    }

    if (url.pathname.startsWith('/sync/snapshot')) {
        evento.respondWith(networkFirst(request, CACHE_DADOS));
    }
});

async function navegacao(request) {
    try {
        const resposta = await fetch(request);
        return resposta;
    } catch {
        const cache = await caches.open(CACHE_SHELL);
        return (await cache.match('/offline.html')) ?? Response.error();
    }
}

async function staleWhileRevalidate(request, nomeCache) {
    const cache = await caches.open(nomeCache);
    const emCache = await cache.match(request);

    const rede = fetch(request)
        .then((resposta) => {
            if (resposta.ok) cache.put(request, resposta.clone());
            return resposta;
        })
        .catch(() => emCache);

    return emCache ?? rede;
}

async function networkFirst(request, nomeCache) {
    const cache = await caches.open(nomeCache);

    try {
        const resposta = await fetch(request);
        if (resposta.ok) cache.put(request, resposta.clone());
        return resposta;
    } catch {
        const emCache = await cache.match(request);
        return emCache ?? Response.error();
    }
}

/* ------------------------------------------------------------------ */
/* Web Push (M8)                                                       */
/* ------------------------------------------------------------------ */

self.addEventListener('push', (evento) => {
    if (!evento.data) return;

    let dados;

    try {
        dados = evento.data.json();
    } catch {
        dados = { title: 'Mithrandir', body: evento.data.text() };
    }

    evento.waitUntil(
        self.registration.showNotification(dados.title ?? 'Mithrandir', {
            body: dados.body ?? '',
            icon: '/icons/icon-192.png',
            badge: '/icons/badge.png',
            // A tag evita empilhar o mesmo alerta de prazo várias vezes.
            tag: dados.tag ?? 'mithrandir',
            renotify: true,
            requireInteraction: dados.tag?.startsWith('prazo_d') ?? false,
            data: { url: dados.url ?? '/', notificacao_id: dados.notificacao_id },
        })
    );
});

self.addEventListener('notificationclick', (evento) => {
    evento.notification.close();

    const destino = new URL(evento.notification.data?.url ?? '/', self.location.origin).href;

    evento.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((janelas) => {
            for (const janela of janelas) {
                if (janela.url === destino && 'focus' in janela) return janela.focus();
            }

            return self.clients.openWindow(destino);
        })
    );
});

/* ------------------------------------------------------------------ */
/* Sincronização em segundo plano                                      */
/* ------------------------------------------------------------------ */

self.addEventListener('sync', (evento) => {
    if (evento.tag === 'mithrandir-outbox') {
        evento.waitUntil(avisarClientes());
    }
});

async function avisarClientes() {
    const janelas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

    for (const janela of janelas) {
        janela.postMessage({ tipo: 'outbox-sincronizado' });
    }
}
