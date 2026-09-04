<?php

namespace App\Jobs;

use App\Models\Notificacao;
use App\Models\User;
use App\Services\HojeService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * RF-8.2: digest diario com o resumo da tela Hoje.
 *
 * Roda de hora em hora e entrega a quem configurou aquele horario, para
 * respeitar o fuso e a preferencia de cada advogado (RF-9.3).
 */
class DigestDiarioJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public ?int $advogadoId = null) {}

    public function handle(HojeService $hoje): void
    {
        $query = User::query()->whereNotNull('aceite_termo_em');

        if ($this->advogadoId) {
            $query->whereKey($this->advogadoId);
        }

        $query->each(function (User $advogado) use ($hoje) {
            if (! $this->estaNaHora($advogado)) {
                return;
            }

            if (! $advogado->querReceber('digest')) {
                return;
            }

            $painel = $hoje->montar($advogado);
            $corpo = $this->resumir($painel);

            if ($corpo === null) {
                return;
            }

            Notificacao::firstOrCreate(
                ['chave_dedup' => 'digest:'.$advogado->id.':'.$painel['data']],
                [
                    'advogado_id' => $advogado->id,
                    'tipo' => 'digest',
                    'titulo' => 'Seu dia no Mithrandir',
                    'corpo' => $corpo,
                    'url' => '/',
                    'payload' => ['data' => $painel['data']],
                    'agendada_para' => now(),
                ]
            );
        });
    }

    /**
     * Sem parametro explicito, so entrega na hora escolhida pelo advogado.
     */
    private function estaNaHora(User $advogado): bool
    {
        if ($this->advogadoId !== null) {
            return true;
        }

        $tz = $advogado->timezone ?: config('mithrandir.timezone');
        $agora = CarbonImmutable::now($tz);
        $preferida = (int) CarbonImmutable::parse($advogado->digest_horario ?? '08:00')->hour;

        return $agora->hour === $preferida;
    }

    /**
     * @param  array<string, mixed>  $painel
     */
    private function resumir(array $painel): ?string
    {
        $partes = [];

        $criticos = array_filter(
            $painel['fatais'],
            fn ($p) => in_array($p['criticidade'], ['critico', 'vencido'], true)
        );

        if ($criticos !== []) {
            $partes[] = count($criticos).' prazo(s) em zona vermelha';
        } elseif ($painel['fatais'] !== []) {
            $partes[] = count($painel['fatais']).' prazo(s) nos proximos 7 dias';
        }

        if ($painel['agenda'] !== []) {
            $partes[] = count($painel['agenda']).' compromisso(s) hoje';
        }

        if ($painel['publicacoes_novas']['total'] > 0) {
            $partes[] = $painel['publicacoes_novas']['total'].' publicacao(oes) para triar';
        }

        if ($painel['financeiro']['vencido_qtd'] > 0) {
            $partes[] = 'R$ '.number_format($painel['financeiro']['vencido_total'], 2, ',', '.').' vencido';
        }

        // Dia limpo nao merece notificacao.
        return $partes === [] ? null : ucfirst(implode(' - ', $partes)).'.';
    }
}
