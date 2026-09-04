<?php

namespace App\Mail;

use App\Models\Notificacao;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * RF-8.5: fallback por e-mail quando o push falha 3 vezes.
 */
class NotificacaoFallbackMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Notificacao $notificacao) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Mithrandir] '.$this->notificacao->titulo);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.notificacao',
            with: [
                'titulo' => $this->notificacao->titulo,
                'corpo' => $this->notificacao->corpo,
                'url' => url($this->notificacao->url ?? '/'),
            ],
        );
    }
}
