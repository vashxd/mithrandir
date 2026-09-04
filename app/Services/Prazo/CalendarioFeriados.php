<?php

namespace App\Services\Prazo;

use Carbon\CarbonImmutable;

/**
 * Conjunto imutavel de feriados ja filtrado para um contexto (UF, municipio, tribunal).
 *
 * Objeto puro: nao acessa banco, nao acessa rede, nao le config. Quem monta o
 * contexto e o CalendarioService (com I/O); aqui so mora a regra de calendario.
 */
final class CalendarioFeriados
{
    /** @var array<string, string> 'Y-m-d' => descricao */
    private array $feriados;

    /**
     * @param  array<string, string>|array<int, array{data: string, descricao: string}>  $feriados
     * @param  bool  $aplicarRecessoForense  Suspensao de 20/12 a 20/01 (CPC art. 220).
     */
    public function __construct(array $feriados = [], private readonly bool $aplicarRecessoForense = true)
    {
        $normalizado = [];

        foreach ($feriados as $chave => $valor) {
            if (is_array($valor)) {
                $normalizado[$valor['data']] = $valor['descricao'] ?? 'Feriado';
            } else {
                $normalizado[(string) $chave] = (string) $valor;
            }
        }

        $this->feriados = $normalizado;
    }

    public static function vazio(bool $aplicarRecessoForense = true): self
    {
        return new self([], $aplicarRecessoForense);
    }

    /**
     * @param  array<string, string>|array<int, array{data: string, descricao: string}>  $feriados
     */
    public function com(array $feriados): self
    {
        $novo = new self($feriados, $this->aplicarRecessoForense);

        return new self(array_merge($this->feriados, $novo->feriados), $this->aplicarRecessoForense);
    }

    public function semRecessoForense(): self
    {
        return new self($this->feriados, false);
    }

    public function ehFinalDeSemana(CarbonImmutable $dia): bool
    {
        return $dia->isSaturday() || $dia->isSunday();
    }

    public function ehFeriado(CarbonImmutable $dia): bool
    {
        return isset($this->feriados[$dia->toDateString()]);
    }

    public function descricaoFeriado(CarbonImmutable $dia): ?string
    {
        return $this->feriados[$dia->toDateString()] ?? null;
    }

    /**
     * Recesso forense: 20/12 a 20/01, inclusive (CPC art. 220).
     */
    public function ehRecessoForense(CarbonImmutable $dia): bool
    {
        if (! $this->aplicarRecessoForense) {
            return false;
        }

        $mes = (int) $dia->month;
        $diaDoMes = (int) $dia->day;

        return ($mes === 12 && $diaDoMes >= 20) || ($mes === 1 && $diaDoMes <= 20);
    }

    /**
     * Dia util em sentido estrito: nao e fim de semana nem feriado.
     * O recesso NAO entra aqui - ele suspende a contagem, mas nao muda a
     * natureza do dia (ver ehDiaDeContagem).
     */
    public function ehDiaUtil(CarbonImmutable $dia): bool
    {
        return ! $this->ehFinalDeSemana($dia) && ! $this->ehFeriado($dia);
    }

    /**
     * Dia em que o prazo processual efetivamente corre.
     */
    public function ehDiaDeContagem(CarbonImmutable $dia): bool
    {
        return $this->ehDiaUtil($dia) && ! $this->ehRecessoForense($dia);
    }

    /**
     * Por que este dia nao conta? Alimenta a cadeia de origem exibida na UI.
     */
    public function motivo(CarbonImmutable $dia): ?string
    {
        // Sabado e domingo compartilham o motivo para colapsarem em uma linha
        // so na cadeia de origem.
        if ($this->ehFinalDeSemana($dia)) {
            return 'fim de semana';
        }

        if ($this->ehFeriado($dia)) {
            return $this->descricaoFeriado($dia);
        }

        if ($this->ehRecessoForense($dia)) {
            return 'recesso forense (CPC art. 220)';
        }

        return null;
    }

    /**
     * Proximo dia em que o prazo corre, estritamente depois de $dia.
     */
    public function proximoDiaDeContagem(CarbonImmutable $dia): CarbonImmutable
    {
        $cursor = $dia->addDay();

        while (! $this->ehDiaDeContagem($cursor)) {
            $cursor = $cursor->addDay();
        }

        return $cursor;
    }

    /**
     * @return array<int, array{data: string, motivo: string}>
     */
    public function feriadosNoIntervalo(CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $lista = [];

        foreach ($this->feriados as $data => $descricao) {
            if ($data >= $inicio->toDateString() && $data <= $fim->toDateString()) {
                $lista[] = ['data' => $data, 'motivo' => $descricao];
            }
        }

        usort($lista, fn ($a, $b) => $a['data'] <=> $b['data']);

        return $lista;
    }
}
