<?php

namespace App\Services\Prazo;

use Carbon\CarbonImmutable;

/**
 * Entrada do calculo de prazo. Tudo que o PrazoService precisa saber,
 * sem nenhuma dependencia de banco.
 */
final class PrazoInput
{
    public readonly CarbonImmutable $dataDisponibilizacao;

    public function __construct(
        CarbonImmutable|string $dataDisponibilizacao,
        public readonly int $dias,
        public readonly bool $emDiasUteis = true,
        public readonly int $multiplicador = 1,
        public readonly int $bufferDias = 3,
        public readonly string $tipo = 'Prazo',
        /**
         * Quando o prazo nao nasce de publicacao (ex.: intimacao pessoal, carga),
         * a data de inicio pode ser fixada diretamente, pulando os arts. 224 §2 e §3.
         */
        public readonly ?CarbonImmutable $inicioForcado = null,
    ) {
        $this->dataDisponibilizacao = $dataDisponibilizacao instanceof CarbonImmutable
            ? $dataDisponibilizacao->startOfDay()
            : CarbonImmutable::parse($dataDisponibilizacao)->startOfDay();

        if ($dias < 1) {
            throw new \InvalidArgumentException('O prazo precisa ter ao menos 1 dia.');
        }

        if ($multiplicador < 1) {
            throw new \InvalidArgumentException('O multiplicador precisa ser >= 1.');
        }

        if ($bufferDias < 0) {
            throw new \InvalidArgumentException('O buffer nao pode ser negativo.');
        }
    }

    public function diasEfetivos(): int
    {
        return $this->dias * $this->multiplicador;
    }
}
