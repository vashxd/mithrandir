<?php

namespace App\Jobs;

use App\Models\OabWatch;
use App\Services\Djen\IngestaoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Varredura diaria por termo de vigilancia (RF-1.2), agendada para 06:00
 * America/Sao_Paulo. Um job por watch: a falha de um termo nao cega os outros.
 */
class SyncPublicacoesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(public int $watchId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->watchId))->expireAfter(600)];
    }

    public function handle(IngestaoService $ingestao): void
    {
        $watch = OabWatch::with('advogado')->find($this->watchId);

        if (! $watch || ! $watch->ativo) {
            return;
        }

        $ingestao->sincronizar($watch);
    }
}
