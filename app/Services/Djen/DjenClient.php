<?php

namespace App\Services\Djen;

use App\Models\Configuracao;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente da API de comunicacoes do DJEN/Comunica (CNJ) - secao 7.1.
 *
 * Gratuito, sem chave, sem cadastro. E o gatilho formal de contagem de prazo,
 * entao aqui vale a decisao arquitetural n.2: circuit breaker proprio, isolado
 * do DataJud. Se o DataJud cair, a ingestao de prazo continua de pe.
 */
class DjenClient
{
    private const CACHE_BREAKER = 'djen:circuito';

    private const FALHAS_PARA_ABRIR = 5;

    private const TEMPO_ABERTO_SEGUNDOS = 300;

    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly int $timeout = 30,
    ) {}

    private function url(): string
    {
        return rtrim(
            $this->baseUrl
                ?? Configuracao::valor('djen.base_url')
                ?? config('mithrandir.djen.base_url'),
            '/'
        );
    }

    /**
     * Consulta por OAB. A janela nunca e so hoje (RF-1.3): as datas da API sao
     * America/Sao_Paulo e, as 22h de Brasilia, o UTC ja virou o dia seguinte.
     * O tamanho vem de `djen.janela_dias` e cobre ontem por construcao.
     *
     * @return array<int, ComunicacaoDto>
     */
    public function porOab(
        string $numeroOab,
        string $ufOab,
        ?CarbonImmutable $inicio = null,
        ?CarbonImmutable $fim = null,
    ): array {
        [$inicio, $fim] = $this->janela($inicio, $fim);

        return $this->buscarPaginado([
            'numeroOab' => $numeroOab,
            'ufOab' => strtoupper($ufOab),
            'dataDisponibilizacaoInicio' => $inicio->toDateString(),
            'dataDisponibilizacaoFim' => $fim->toDateString(),
        ]);
    }

    /**
     * Consulta por nome da parte/advogado. Exige revisao humana na triagem:
     * grafia divergente, abreviacao e homonimo sao a regra, nao a excecao.
     *
     * @return array<int, ComunicacaoDto>
     */
    public function porNome(
        string $nome,
        ?string $uf = null,
        ?CarbonImmutable $inicio = null,
        ?CarbonImmutable $fim = null,
    ): array {
        [$inicio, $fim] = $this->janela($inicio, $fim);

        return $this->buscarPaginado(array_filter([
            'nomeAdvogado' => $nome,
            'ufOab' => $uf ? strtoupper($uf) : null,
            'dataDisponibilizacaoInicio' => $inicio->toDateString(),
            'dataDisponibilizacaoFim' => $fim->toDateString(),
        ]));
    }

    /**
     * Consulta por nome da PARTE - e assim que se acha processo de cliente.
     *
     * O DJEN nao aceita CPF/CNPJ como filtro (parametro e ignorado e a API
     * devolve tudo), entao o nome e o unico caminho. Homonimo e inevitavel:
     * toda publicacao capturada por aqui passa pela triagem humana.
     *
     * @return array<int, ComunicacaoDto>
     */
    public function porNomeParte(
        string $nome,
        ?string $uf = null,
        ?CarbonImmutable $inicio = null,
        ?CarbonImmutable $fim = null,
    ): array {
        [$inicio, $fim] = $this->janela($inicio, $fim);

        return $this->buscarPaginado(array_filter([
            'nomeParte' => $nome,
            'ufOab' => $uf ? strtoupper($uf) : null,
            'dataDisponibilizacaoInicio' => $inicio->toDateString(),
            'dataDisponibilizacaoFim' => $fim->toDateString(),
        ]));
    }

    /**
     * Quantas publicacoes um nome retornaria, sem baixar nada.
     *
     * Serve para a tela avisar "este nome traz 10.000 resultados" ANTES de
     * ligar a vigilancia - nome comum demais entope a triagem e esconde o
     * prazo de verdade no meio do ruido.
     */
    public function contarPorNomeParte(string $nome, int $dias = 30): int
    {
        $tz = config('mithrandir.timezone');
        $hoje = CarbonImmutable::now($tz)->startOfDay();

        $resposta = $this->requisitar([
            'nomeParte' => $nome,
            'dataDisponibilizacaoInicio' => $hoje->subDays($dias)->toDateString(),
            'dataDisponibilizacaoFim' => $hoje->toDateString(),
            'pagina' => 1,
            'itensPorPagina' => 1,
        ]);

        return (int) ($resposta->json()['count'] ?? 0);
    }

    /**
     * @return array<int, ComunicacaoDto>
     */
    public function porProcesso(string $numeroProcesso): array
    {
        return $this->buscarPaginado([
            'numeroProcesso' => preg_replace('/\D/', '', $numeroProcesso),
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function janela(?CarbonImmutable $inicio, ?CarbonImmutable $fim): array
    {
        $tz = config('mithrandir.timezone');
        $hoje = CarbonImmutable::now($tz)->startOfDay();

        $dias = max(2, (int) config('mithrandir.djen.janela_dias', 7));

        return [
            $inicio ?? $hoje->subDays($dias - 1),
            $fim ?? $hoje,
        ];
    }

    /**
     * @param  array<string, mixed>  $parametros
     * @return array<int, ComunicacaoDto>
     */
    private function buscarPaginado(array $parametros): array
    {
        $this->garantirCircuitoFechado();

        $itensPorPagina = config('mithrandir.djen.itens_por_pagina', 100);
        $maxPaginas = config('mithrandir.djen.max_paginas', 20);

        $todos = [];
        $pagina = 1;

        do {
            $resposta = $this->requisitar($parametros + [
                'pagina' => $pagina,
                'itensPorPagina' => $itensPorPagina,
            ]);

            $corpo = $resposta->json() ?? [];
            $itens = $corpo['items'] ?? [];
            $total = (int) ($corpo['count'] ?? count($itens));

            foreach ($itens as $item) {
                $todos[] = ComunicacaoDto::deArray($item);
            }

            $pagina++;
            $temMais = count($itens) === $itensPorPagina && count($todos) < $total;
        } while ($temMais && $pagina <= $maxPaginas);

        return $todos;
    }

    /**
     * @param  array<string, mixed>  $parametros
     */
    private function requisitar(array $parametros): Response
    {
        try {
            $resposta = Http::acceptJson()
                ->timeout($this->timeout)
                ->retry(3, 1500, throw: false)
                ->withUserAgent(config('mithrandir.user_agent'))
                ->get($this->url().'/api/v1/comunicacao', $parametros);
        } catch (ConnectionException $e) {
            $this->registrarFalha();

            throw new DjenIndisponivelException(
                'Nao foi possivel falar com o DJEN: '.$e->getMessage(),
                previous: $e
            );
        }

        if ($resposta->failed()) {
            $this->registrarFalha();

            Log::warning('DJEN respondeu com erro', [
                'status' => $resposta->status(),
                'parametros' => $parametros,
            ]);

            throw new DjenIndisponivelException(
                "DJEN respondeu {$resposta->status()}."
            );
        }

        Cache::forget(self::CACHE_BREAKER);

        return $resposta;
    }

    private function garantirCircuitoFechado(): void
    {
        $estado = Cache::get(self::CACHE_BREAKER);

        if (is_array($estado) && ($estado['aberto_ate'] ?? 0) > time()) {
            throw new DjenIndisponivelException(
                'Circuito do DJEN aberto apos falhas seguidas. Nova tentativa em '
                .max(1, (int) ceil(($estado['aberto_ate'] - time()) / 60)).' min.'
            );
        }
    }

    private function registrarFalha(): void
    {
        $estado = Cache::get(self::CACHE_BREAKER, ['falhas' => 0, 'aberto_ate' => 0]);
        $estado['falhas']++;

        if ($estado['falhas'] >= self::FALHAS_PARA_ABRIR) {
            $estado['aberto_ate'] = time() + self::TEMPO_ABERTO_SEGUNDOS;
            $estado['falhas'] = 0;
        }

        Cache::put(self::CACHE_BREAKER, $estado, 3600);
    }
}
