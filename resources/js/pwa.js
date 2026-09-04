import { router } from '@inertiajs/vue3';

/**
 * Registro do service worker e assinatura do Web Push.
 *
 * Offline nao e feature, e premissa (principio 3): o forum sem sinal e o caso
 * de uso mais comum da persona.
 */
export function registrarServiceWorker() {
    if (!('serviceWorker' in navigator)) return;

    window.addEventListener('load', async () => {
        try {
            const registro = await navigator.serviceWorker.register('/sw.js', { scope: '/' });

            // Uma versao nova do app nao pode ficar presa atrás do SW antigo.
            registro.addEventListener('updatefound', () => {
                const novo = registro.installing;
                if (!novo) return;

                novo.addEventListener('statechange', () => {
                    if (novo.state === 'installed' && navigator.serviceWorker.controller) {
                        window.dispatchEvent(new CustomEvent('mithrandir:atualizacao-disponivel'));
                    }
                });
            });
        } catch (erro) {
            console.warn('[Mithrandir] service worker não registrou:', erro);
        }
    });

    // O SW avisa quando a fila offline sobe, para a tela se atualizar sozinha.
    navigator.serviceWorker.addEventListener('message', (evento) => {
        if (evento.data?.tipo === 'outbox-sincronizado') {
            router.reload({ only: ['painel', 'badges'] });
        }
    });
}

export function pwaInstalado() {
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        window.navigator.standalone === true
    );
}

export function ehIos() {
    return /iphone|ipad|ipod/i.test(window.navigator.userAgent);
}

/**
 * No iOS o push SO funciona com o app instalado na tela de início.
 * Por isso o onboarding checa isso antes de pedir a permissão (RF-8.3).
 */
export function pushDisponivel() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return false;
    if (ehIos() && !pwaInstalado()) return false;
    return true;
}

export async function assinarPush(chavePublica) {
    if (!pushDisponivel()) {
        throw new Error(
            ehIos()
                ? 'No iPhone, instale o app na tela de início antes de ativar as notificações.'
                : 'Este navegador não suporta notificações push.'
        );
    }

    const permissao = await Notification.requestPermission();

    if (permissao !== 'granted') {
        throw new Error('Permissão de notificação negada.');
    }

    const registro = await navigator.serviceWorker.ready;

    let inscricao = await registro.pushManager.getSubscription();

    if (!inscricao) {
        inscricao = await registro.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: base64UrlParaUint8Array(chavePublica),
        });
    }

    const resposta = await fetch('/push/inscrever', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': tokenCsrf(),
        },
        body: JSON.stringify(inscricao.toJSON()),
    });

    if (!resposta.ok) {
        throw new Error('Não foi possível registrar o dispositivo no servidor.');
    }

    return true;
}

export async function testarPush() {
    const resposta = await fetch('/push/testar', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': tokenCsrf() },
    });

    return resposta.json();
}

function tokenCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function base64UrlParaUint8Array(base64Url) {
    const preenchimento = '='.repeat((4 - (base64Url.length % 4)) % 4);
    const base64 = (base64Url + preenchimento).replace(/-/g, '+').replace(/_/g, '/');
    const bruto = window.atob(base64);

    return Uint8Array.from([...bruto].map((c) => c.charCodeAt(0)));
}
