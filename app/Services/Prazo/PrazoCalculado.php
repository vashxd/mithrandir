<?php

namespace App\Services\Prazo;

use Carbon\CarbonImmutable;

/**
 * Resultado do calculo, com a cadeia de origem completa (RF-2.7).
 *
 * O principio de produto n.2 diz que o app nunca e fonte unica de prazo:
 * por isso todo passo do calculo viaja junto com a data, para ser exibido.
 */
final class PrazoCalculado
{
    /**
     * @param  array<int, array{etapa: string, data: string, regra: string, base_legal: ?string}>  $passos
     * @param  array<int, array{data: string, motivo: string}>  $diasNaoContados
     */
    public function __construct(
        public readonly CarbonImmutable $dataDisponibilizacao,
        public readonly CarbonImmutable $dataPublicacao,
        public readonly CarbonImmutable $dataInicio,
        public readonly CarbonImmutable $dataFatal,
        public readonly CarbonImmutable $dataAlvo,
        public readonly int $diasEfetivos,
        public readonly bool $emDiasUteis,
        public readonly int $multiplicador,
        public readonly int $bufferDias,
        public readonly array $passos,
        public readonly array $diasNaoContados,
        public readonly bool $prorrogado,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function cadeiaOrigem(): array
    {
        return [
            'passos' => $this->passos,
            'dias_nao_contados' => $this->diasNaoContados,
            'dias_efetivos' => $this->diasEfetivos,
            'em_dias_uteis' => $this->emDiasUteis,
            'multiplicador' => $this->multiplicador,
            'buffer_dias' => $this->bufferDias,
            'prorrogado' => $this->prorrogado,
            'calculado_em' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data_disponibilizacao' => $this->dataDisponibilizacao->toDateString(),
            'data_publicacao' => $this->dataPublicacao->toDateString(),
            'data_inicio' => $this->dataInicio->toDateString(),
            'data_fatal' => $this->dataFatal->toDateString(),
            'data_alvo' => $this->dataAlvo->toDateString(),
            'cadeia_origem' => $this->cadeiaOrigem(),
        ];
    }
}
