<?php

namespace App\Jobs;

use App\Models\Notificacao;
use App\Models\Prazo;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * RF-8.1: enfileira os alertas D-10, D-5, D-3, D-1 e a manha do dia fatal.
 *
 * Trabalha por diferenca: so cria a notificacao que ainda nao existe, usando a
 * chave de deduplicacao. Rodar duas vezes no mesmo dia nao gera alerta dobrado.
 */
class AgendarAlertasPrazoJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $hoje = CarbonImmutable::today(config('mithrandir.timezone'));
        $antecedencias = config('mithrandir.prazos.alertas_dias', [10, 5, 3, 1, 0]);
        $maior = max($antecedencias);

        $prazos = Prazo::with(['processo', 'advogado'])
            ->abertos()
            ->whereBetween('data_fatal', [$hoje->toDateString(), $hoje->addDays($maior)->toDateString()])
            ->get();

        foreach ($prazos as $prazo) {
            $fatal = CarbonImmutable::parse($prazo->data_fatal);

            // Compara dia com dia. Misturar um "hoje" em America/Sao_Paulo com
            // uma data materializada em UTC deixa 21h de resto e derruba a
            // contagem em um dia inteiro - o que faria o alerta D-1 virar D-0.
            $faltam = (int) CarbonImmutable::parse($hoje->toDateString())
                ->diffInDays(CarbonImmutable::parse($fatal->toDateString()), false);

            if (! in_array($faltam, $antecedencias, true)) {
                continue;
            }

            if (! $prazo->advogado?->querReceber('prazo')) {
                continue;
            }

            Notificacao::firstOrCreate(
                ['chave_dedup' => "prazo:{$prazo->id}:d{$faltam}"],
                [
                    'advogado_id' => $prazo->advogado_id,
                    'tipo' => "prazo_d{$faltam}",
                    'titulo' => $this->titulo($faltam, $prazo),
                    'corpo' => $this->corpo($prazo, $fatal),
                    'url' => "/prazos/{$prazo->id}",
                    'payload' => ['prazo_id' => $prazo->id, 'dias' => $faltam],
                    'agendada_para' => $faltam === 0
                        ? $hoje->setTime(7, 0)   // manha do dia fatal
                        : now(),
                ]
            );
        }
    }

    private function titulo(int $faltam, Prazo $prazo): string
    {
        return match (true) {
            $faltam === 0 => "HOJE e o prazo fatal: {$prazo->tipo}",
            $faltam === 1 => "Amanha vence: {$prazo->tipo}",
            default => "Faltam {$faltam} dias: {$prazo->tipo}",
        };
    }

    private function corpo(Prazo $prazo, CarbonImmutable $fatal): string
    {
        $processo = $prazo->processo?->rotulo ?? 'sem processo vinculado';

        return sprintf('%s - fatal em %s.', $processo, $fatal->format('d/m/Y'));
    }
}
