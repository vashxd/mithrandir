<?php

namespace App\Services;

use App\Models\Evento;
use App\Models\Parcela;
use App\Models\Prazo;
use App\Models\Publicacao;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A tela "Hoje" e o produto (principio 1). Este servico responde as tres
 * perguntas: o que vence, o que tenho que fazer, e quem me deve.
 *
 * O digest diario das 08:00 consome exatamente o mesmo payload - assim a
 * notificacao nunca discorda da tela.
 */
class HojeService
{
    /**
     * @return array<string, mixed>
     */
    public function montar(User $advogado): array
    {
        $tz = $advogado->timezone ?: config('mithrandir.timezone');
        $hoje = CarbonImmutable::today($tz);

        return [
            'data' => $hoje->toDateString(),
            'fatais' => $this->fatais($advogado, $hoje),
            'agenda' => $this->agendaDoDia($advogado, $hoje),
            'publicacoes_novas' => $this->publicacoesNaoTriadas($advogado),
            'financeiro' => $this->financeiro($advogado, $hoje),
            'alertas' => $this->alertas($advogado),
        ];
    }

    /**
     * Fatais dos proximos 7 dias, mais o que ja venceu e continua aberto.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fatais(User $advogado, CarbonImmutable $hoje): array
    {
        return Prazo::with('processo.cliente')
            ->doAdvogado(contexto()->advogadoId())
            ->abertos()
            ->where('data_fatal', '<=', $hoje->addDays(7)->toDateString())
            ->orderBy('data_fatal')
            ->limit(50)
            ->get()
            ->map(fn (Prazo $p) => [
                'id' => $p->id,
                'tipo' => $p->tipo,
                'data_fatal' => CarbonImmutable::parse($p->data_fatal)->toDateString(),
                'data_alvo' => CarbonImmutable::parse($p->data_alvo)->toDateString(),
                'dias_restantes' => $p->dias_restantes,
                'criticidade' => $p->criticidade,
                'ajustado_manualmente' => (bool) $p->ajustado_manualmente,
                'precisa_revisao' => (bool) $p->precisa_revisao,
                'processo' => $p->processo ? [
                    'id' => $p->processo->id,
                    'rotulo' => $p->processo->rotulo,
                    'cliente' => $p->processo->cliente?->nome,
                ] : null,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function agendaDoDia(User $advogado, CarbonImmutable $hoje): array
    {
        return Evento::with('processo')
            ->doAdvogado(contexto()->advogadoId())
            ->entre($hoje->startOfDay(), $hoje->endOfDay())
            ->where('concluido', false)
            ->orderBy('inicio')
            ->get()
            ->map(fn (Evento $e) => [
                'id' => $e->id,
                'tipo' => $e->tipo,
                'titulo' => $e->titulo,
                'inicio' => $e->inicio?->toIso8601String(),
                'dia_inteiro' => (bool) $e->dia_inteiro,
                'local' => $e->local,
                'link' => $e->link,
                'processo' => $e->processo?->rotulo,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function publicacoesNaoTriadas(User $advogado): array
    {
        $query = Publicacao::doAdvogado(contexto()->advogadoId())->naoTriadas();

        return [
            'total' => (clone $query)->count(),
            'itens' => $query->orderByDesc('data_disponibilizacao')
                ->limit(5)
                ->get()
                ->map(fn (Publicacao $p) => [
                    'id' => $p->id,
                    'tribunal' => $p->tribunal,
                    'numero_processo' => $p->numero_processo,
                    'data_disponibilizacao' => CarbonImmutable::parse($p->data_disponibilizacao)->toDateString(),
                    'resumo' => $p->resumo(140),
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function financeiro(User $advogado, CarbonImmutable $hoje): array
    {
        $vencidas = Parcela::with('honorario.cliente')
            ->doAdvogado(contexto()->advogadoId())
            ->vencidas()
            ->orderBy('vencimento')
            ->get();

        return [
            'vencido_total' => (float) $vencidas->sum('valor'),
            'vencido_qtd' => $vencidas->count(),
            'a_receber_mes' => (float) Parcela::doAdvogado(contexto()->advogadoId())
                ->emAberto()
                ->whereBetween('vencimento', [
                    $hoje->startOfMonth()->toDateString(),
                    $hoje->endOfMonth()->toDateString(),
                ])
                ->sum('valor'),
            'itens' => $vencidas->take(5)->map(fn (Parcela $p) => [
                'id' => $p->id,
                'valor' => (float) $p->valor,
                'vencimento' => CarbonImmutable::parse($p->vencimento)->toDateString(),
                'cliente' => $p->honorario?->cliente?->nome,
            ])->values()->all(),
        ];
    }

    /**
     * Avisos que precisam aparecer no topo da tela, nunca escondidos.
     *
     * @return array<int, array<string, string>>
     */
    private function alertas(User $advogado): array
    {
        $alertas = [];

        $cegos = $advogado->watches()->where('ativo', true)->where('falhas_consecutivas', '>=', 2)->count();

        if ($cegos > 0) {
            $alertas[] = [
                'nivel' => 'critico',
                'titulo' => 'Seu radar esta cego',
                'texto' => 'A varredura do DJEN falhou duas vezes seguidas. Confira o diario manualmente hoje.',
                'url' => '/configuracoes/radar',
            ];
        }

        if ($advogado->watches()->where('ativo', true)->count() === 0) {
            $alertas[] = [
                'nivel' => 'atencao',
                'titulo' => 'Nenhum termo de vigilancia cadastrado',
                'texto' => 'Sem termo cadastrado o app nao busca publicacao nenhuma.',
                'url' => '/configuracoes/radar',
            ];
        }

        $emRevisao = Prazo::doAdvogado(contexto()->advogadoId())->abertos()->where('precisa_revisao', true)->count();

        if ($emRevisao > 0) {
            $alertas[] = [
                'nivel' => 'atencao',
                'titulo' => $emRevisao === 1
                    ? '1 prazo precisa de revisao'
                    : "{$emRevisao} prazos precisam de revisao",
                'texto' => 'O calendario de feriados mudou depois do calculo original.',
                'url' => '/prazos?revisao=1',
            ];
        }

        return $alertas;
    }
}
