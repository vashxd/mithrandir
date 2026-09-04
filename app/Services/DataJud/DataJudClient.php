<?php

namespace App\Services\DataJud;

use App\Models\Configuracao;
use App\Support\NumeroCnj;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente da API Publica do DataJud (secao 7.2).
 *
 * Papel no produto: ENRIQUECIMENTO do caso (capa + movimentacoes).
 * Nunca gatilho de prazo. Uma queda aqui nao pode derrubar a ingestao do DJEN.
 *
 * O termo de uso do CNJ permite limitar e revogar acesso sem aviso, entao:
 * cache agressivo, backoff, e uso comedido.
 */
class DataJudClient
{
    private const CACHE_TTL = 60 * 60 * 12; // 12h - a capa nao muda de hora em hora

    private const CACHE_BREAKER = 'datajud:circuito';

    public function __construct(private readonly int $timeout = 25) {}

    public function chave(): ?string
    {
        return Configuracao::valor('datajud.api_key') ?: config('mithrandir.datajud.api_key');
    }

    public function configurado(): bool
    {
        return ! empty($this->chave());
    }

    /**
     * Busca a capa e as movimentacoes de um processo pelo numero CNJ.
     *
     * @return array{capa: array<string, mixed>, movimentacoes: array<int, array<string, mixed>>}|null
     */
    public function consultarProcesso(string $numeroCnj, ?string $alias = null): ?array
    {
        $numero = NumeroCnj::limpar($numeroCnj);
        $alias ??= $this->aliasPorNumero($numero);

        if ($alias === null) {
            return null;
        }

        $cacheKey = "datajud:proc:{$alias}:{$numero}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($alias, $numero) {
            $resposta = $this->buscar($alias, [
                'query' => [
                    'match' => ['numeroProcesso' => $numero],
                ],
                'size' => 1,
            ]);

            $hit = $resposta['hits']['hits'][0]['_source'] ?? null;

            if ($hit === null) {
                return null;
            }

            return [
                'capa' => $this->extrairCapa($hit),
                'movimentacoes' => $this->extrairMovimentacoes($hit),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $consulta
     * @return array<string, mixed>
     */
    private function buscar(string $alias, array $consulta): array
    {
        if (! $this->configurado()) {
            throw new DataJudIndisponivelException('Chave do DataJud nao configurada.');
        }

        $estado = Cache::get(self::CACHE_BREAKER);

        if (is_array($estado) && ($estado['aberto_ate'] ?? 0) > time()) {
            throw new DataJudIndisponivelException('Circuito do DataJud aberto.');
        }

        $url = sprintf(
            '%s/api_publica_%s/_search',
            rtrim(config('mithrandir.datajud.base_url'), '/'),
            $alias
        );

        try {
            $resposta = Http::withHeaders([
                'Authorization' => 'APIKey '.$this->chave(),
                'Content-Type' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->retry(2, 2000, throw: false)
                ->post($url, $consulta);
        } catch (ConnectionException $e) {
            $this->abrirCircuito();

            throw new DataJudIndisponivelException('DataJud fora do ar: '.$e->getMessage(), previous: $e);
        }

        if ($resposta->status() === 401 || $resposta->status() === 403) {
            // RF: alerta em caso de 401 - a chave publica mudou.
            Configuracao::definir('datajud.alerta_401_em', now()->toIso8601String());

            Log::warning('DataJud recusou a chave publica', ['status' => $resposta->status()]);

            throw new ChaveInvalidaException(
                'O CNJ recusou a chave do DataJud. Atualize a chave publica em Configuracoes.'
            );
        }

        if ($resposta->failed()) {
            $this->abrirCircuito();

            throw new DataJudIndisponivelException("DataJud respondeu {$resposta->status()}.");
        }

        Cache::forget(self::CACHE_BREAKER);

        return $resposta->json() ?? [];
    }

    private function abrirCircuito(): void
    {
        Cache::put(self::CACHE_BREAKER, ['aberto_ate' => time() + 600], 3600);
    }

    /**
     * @param  array<string, mixed>  $fonte
     * @return array<string, mixed>
     */
    private function extrairCapa(array $fonte): array
    {
        return array_filter([
            'classe' => $fonte['classe']['nome'] ?? null,
            'assunto' => $fonte['assuntos'][0]['nome'] ?? null,
            'tribunal' => $fonte['tribunal'] ?? null,
            'vara' => $fonte['orgaoJulgador']['nome'] ?? null,
            'grau' => $fonte['grau'] ?? null,
            'valor_causa' => $fonte['valorCausa'] ?? null,
            'data_ajuizamento' => isset($fonte['dataAjuizamento'])
                ? CarbonImmutable::parse($fonte['dataAjuizamento'])->toDateString()
                : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $fonte
     * @return array<int, array<string, mixed>>
     */
    private function extrairMovimentacoes(array $fonte): array
    {
        $movimentos = $fonte['movimentos'] ?? [];
        $lista = [];

        foreach ($movimentos as $movimento) {
            if (! isset($movimento['dataHora'])) {
                continue;
            }

            $data = CarbonImmutable::parse($movimento['dataHora'])->toDateString();
            $descricao = (string) ($movimento['nome'] ?? 'Movimento');
            $codigo = isset($movimento['codigo']) ? (string) $movimento['codigo'] : null;

            $lista[] = [
                'data' => $data,
                'codigo' => $codigo,
                'descricao' => $descricao,
                'origem' => 'datajud',
                'hash' => hash('sha256', $data.'|'.$codigo.'|'.$descricao),
            ];
        }

        usort($lista, fn ($a, $b) => $b['data'] <=> $a['data']);

        return $lista;
    }

    /**
     * Deriva o alias do indice a partir do segmento e do tribunal no numero CNJ.
     * Cobre os tribunais do escopo inicial (secao 8.4) e cai fora silenciosamente
     * no resto - DataJud e enriquecimento, nao pode quebrar o fluxo.
     */
    public function aliasPorNumero(string $numeroCnj): ?string
    {
        $partes = NumeroCnj::partes($numeroCnj);

        if ($partes === null) {
            return null;
        }

        $tr = (int) $partes['tribunal'];

        return match ($partes['segmento']) {
            '4' => 'trf'.$tr,                                        // Justica Federal
            '5' => 'trt'.$tr,                                        // Justica do Trabalho
            '8' => 'tj'.strtolower($this->ufPorCodigoEstadual($tr)),  // Justica Estadual
            default => null,
        };
    }

    private function ufPorCodigoEstadual(int $codigo): string
    {
        // Codigo TR da Justica Estadual, na ordem da tabela do CNJ.
        $ufs = [
            1 => 'AC', 2 => 'AL', 3 => 'AP', 4 => 'AM', 5 => 'BA', 6 => 'CE',
            7 => 'DF', 8 => 'ES', 9 => 'GO', 10 => 'MA', 11 => 'MT', 12 => 'MS',
            13 => 'MG', 14 => 'PA', 15 => 'PB', 16 => 'PR', 17 => 'PE', 18 => 'PI',
            19 => 'RJ', 20 => 'RN', 21 => 'RS', 22 => 'RO', 23 => 'RR', 24 => 'SC',
            25 => 'SE', 26 => 'SP', 27 => 'TO',
        ];

        return $ufs[$codigo] ?? '';
    }
}
