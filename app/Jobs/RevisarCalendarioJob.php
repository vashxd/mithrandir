<?php

namespace App\Jobs;

use App\Models\Feriado;
use App\Models\Notificacao;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Secao 8.4: nao existe fonte publica confiavel de feriado forense, entao o
 * calendario e um passivo permanente. Esta rotina existe para admitir isso em
 * voz alta - lembrete trimestral de conferir as portarias do tribunal.
 */
class RevisarCalendarioJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $hoje = CarbonImmutable::today(config('mithrandir.timezone'));
        $trimestre = (int) ceil($hoje->month / 3);
        $horizonte = $hoje->addDays(90);

        $cobertura = Feriado::where('data', '>=', $hoje->toDateString())
            ->where('data', '<=', $horizonte->toDateString())
            ->count();

        User::query()->whereNotNull('aceite_termo_em')->each(function (User $advogado) use ($hoje, $trimestre, $cobertura) {
            Notificacao::firstOrCreate(
                ['chave_dedup' => "calendario:{$advogado->id}:{$hoje->year}T{$trimestre}"],
                [
                    'advogado_id' => $advogado->id,
                    'tipo' => 'revisao_calendario',
                    'titulo' => 'Confira as portarias do seu tribunal',
                    'corpo' => sprintf(
                        'Revisao trimestral do calendario. Ha %d feriado(s) cadastrado(s) para os proximos 90 dias. '
                        .'Feriado que falta na tabela vira prazo errado.',
                        $cobertura
                    ),
                    'url' => '/configuracoes/feriados',
                    'payload' => ['trimestre' => $trimestre, 'cobertura' => $cobertura],
                    'agendada_para' => now(),
                ]
            );
        });
    }
}
