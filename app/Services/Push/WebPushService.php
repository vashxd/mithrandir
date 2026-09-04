<?php

namespace App\Services\Push;

use App\Models\Notificacao;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Web Push com VAPID auto-hospedado (secao 7.3): custo zero.
 *
 * Restricao critica: no iOS so funciona com o PWA instalado na tela de inicio.
 * O onboarding forca esse passo (RF-8.3) - sem isso a notificacao nunca chega.
 */
class WebPushService
{
    public function configurado(): bool
    {
        return ! empty(config('mithrandir.push.vapid_public_key'))
            && ! empty(config('mithrandir.push.vapid_private_key'));
    }

    public function chavePublica(): ?string
    {
        return config('mithrandir.push.vapid_public_key') ?: null;
    }

    /**
     * Envia uma notificacao para todos os dispositivos do advogado.
     *
     * @return int quantidade de entregas aceitas
     */
    public function enviar(Notificacao $notificacao): int
    {
        if (! $this->configurado()) {
            Log::warning('VAPID nao configurado; push ignorado.', ['notificacao' => $notificacao->id]);

            return 0;
        }

        $inscricoes = PushSubscription::doAdvogado($notificacao->advogado_id)->get();

        if ($inscricoes->isEmpty()) {
            return 0;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('mithrandir.push.vapid_subject'),
                'publicKey' => config('mithrandir.push.vapid_public_key'),
                'privateKey' => config('mithrandir.push.vapid_private_key'),
            ],
        ]);

        $payload = json_encode([
            'title' => $notificacao->titulo,
            'body' => $notificacao->corpo,
            'url' => $notificacao->url ?? '/',
            'tag' => $notificacao->tipo,
            'notificacao_id' => $notificacao->id,
        ], JSON_UNESCAPED_UNICODE);

        foreach ($inscricoes as $inscricao) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $inscricao->endpoint,
                    'publicKey' => $inscricao->p256dh,
                    'authToken' => $inscricao->auth,
                ]),
                $payload
            );
        }

        $aceitas = 0;

        foreach ($webPush->flush() as $relatorio) {
            $endpoint = $relatorio->getRequest()->getUri()->__toString();
            $inscricao = $inscricoes->firstWhere('endpoint_hash', hash('sha256', $endpoint));

            if ($relatorio->isSuccess()) {
                $aceitas++;
                $inscricao?->forceFill(['falhas' => 0, 'ultima_entrega_em' => now()])->save();

                continue;
            }

            // 404/410: o navegador descartou a inscricao. Nao adianta insistir.
            if ($relatorio->isSubscriptionExpired()) {
                $inscricao?->delete();

                continue;
            }

            Log::warning('Falha ao entregar push', [
                'motivo' => $relatorio->getReason(),
                'notificacao' => $notificacao->id,
            ]);

            $inscricao?->increment('falhas');
        }

        return $aceitas;
    }
}
