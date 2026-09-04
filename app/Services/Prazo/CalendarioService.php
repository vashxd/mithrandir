<?php

namespace App\Services\Prazo;

use App\Models\Feriado;
use App\Models\Processo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Ponte com I/O entre a tabela `feriados` e o PrazoService, que e puro.
 *
 * Este e o "passivo do projeto" da secao 8.4: nao existe fonte publica unificada
 * de feriado forense. A tabela e mantida a mao e revisada de tres em tres meses.
 */
class CalendarioService
{
    private const CACHE_TTL = 60 * 30;

    /**
     * Monta o calendario aplicavel a um contexto (tribunal / UF / municipio).
     * Feriado nacional vale sempre; estadual so na UF; municipal so no municipio;
     * de tribunal so naquele tribunal.
     */
    public function para(
        ?string $tribunal = null,
        ?string $uf = null,
        ?string $municipio = null,
        ?int $advogadoId = null,
    ): CalendarioFeriados {
        $chave = 'calendario:'.$this->versao().':'
            .md5(implode('|', [$tribunal, $uf, $municipio, $advogadoId]));

        $feriados = Cache::remember($chave, self::CACHE_TTL, function () use ($tribunal, $uf, $municipio, $advogadoId) {
            return Feriado::query()
                ->where(function ($q) use ($tribunal, $uf, $municipio) {
                    $q->where('abrangencia', 'nacional');

                    if ($uf) {
                        $q->orWhere(fn ($sub) => $sub->where('abrangencia', 'estadual')->where('uf', $uf));
                    }

                    if ($municipio) {
                        $q->orWhere(fn ($sub) => $sub->where('abrangencia', 'municipal')->where('municipio', $municipio));
                    }

                    if ($tribunal) {
                        $q->orWhere(fn ($sub) => $sub->where('abrangencia', 'tribunal')->where('tribunal_sigla', $tribunal));
                    }
                })
                // Feriado cadastrado pelo proprio advogado so vale para ele.
                ->where(fn ($q) => $q->whereNull('advogado_id')->orWhere('advogado_id', $advogadoId))
                ->orderBy('data')
                ->get(['data', 'descricao'])
                ->mapWithKeys(fn (Feriado $f) => [
                    CarbonImmutable::parse($f->data)->toDateString() => $f->descricao,
                ])
                ->all();
        });

        return new CalendarioFeriados($feriados);
    }

    /**
     * Calendario do contexto de um processo.
     */
    public function paraProcesso(?Processo $processo, ?int $advogadoId = null): CalendarioFeriados
    {
        return $this->para(
            tribunal: $processo?->tribunal,
            uf: $processo?->advogado?->uf ?? $processo?->tribunal_uf ?? null,
            municipio: null,
            advogadoId: $advogadoId ?? $processo?->advogado_id,
        );
    }

    /**
     * Versao do calendario embutida na chave de cache. O cache e por contexto e
     * nao e enumeravel; flush por tag exigiria Redis. Bumpar a versao invalida
     * todos os contextos de uma vez, em qualquer driver.
     */
    private function versao(): int
    {
        return (int) Cache::rememberForever('calendario:versao', fn () => 1);
    }

    public function limparCache(): void
    {
        Cache::forever('calendario:versao', $this->versao() + 1);
    }

    /**
     * Quantos dias uteis existem entre duas datas, no calendario dado.
     */
    public function diasUteisEntre(CarbonImmutable $de, CarbonImmutable $ate, CalendarioFeriados $calendario): int
    {
        if ($ate->lessThan($de)) {
            return 0;
        }

        $total = 0;
        $cursor = $de;

        while ($cursor->lessThanOrEqualTo($ate)) {
            if ($calendario->ehDiaDeContagem($cursor)) {
                $total++;
            }

            $cursor = $cursor->addDay();
        }

        return $total;
    }
}
