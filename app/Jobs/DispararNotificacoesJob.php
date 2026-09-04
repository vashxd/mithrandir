<?php

namespace App\Jobs;

use App\Mail\NotificacaoFallbackMail;
use App\Models\Notificacao;
use App\Services\Push\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Roda de hora em hora: entrega o que ja venceu a hora agendada.
 *
 * RF-8.5: apos 3 tentativas de push sem sucesso, cai para e-mail. Notificacao de
 * prazo que nao chega e o mesmo que nao existir.
 */
class DispararNotificacoesJob implements ShouldQueue
{
    use Queueable;

    private const MAX_TENTATIVAS_PUSH = 3;

    public function handle(WebPushService $push): void
    {
        Notificacao::with('advogado')
            ->pendentes()
            ->orderBy('agendada_para')
            ->limit(200)
            ->get()
            ->each(function (Notificacao $notificacao) use ($push) {
                $entregues = $push->enviar($notificacao);

                if ($entregues > 0) {
                    $notificacao->forceFill([
                        'enviada_em' => now(),
                        'canal' => 'push',
                    ])->save();

                    return;
                }

                $notificacao->increment('tentativas');
                $notificacao->refresh();

                if ($notificacao->tentativas >= self::MAX_TENTATIVAS_PUSH) {
                    $this->porEmail($notificacao);
                }
            });
    }

    private function porEmail(Notificacao $notificacao): void
    {
        $email = $notificacao->advogado?->email;

        if (! $email) {
            $notificacao->forceFill(['enviada_em' => now(), 'canal' => 'descartada'])->save();

            return;
        }

        Mail::to($email)->send(new NotificacaoFallbackMail($notificacao));

        $notificacao->forceFill(['enviada_em' => now(), 'canal' => 'email'])->save();
    }
}
