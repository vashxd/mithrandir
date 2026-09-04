<?php

namespace App\Services\Djen;

use App\Models\Notificacao;
use App\Models\OabWatch;
use App\Models\Processo;
use App\Models\Publicacao;
use App\Models\SyncLog;
use App\Support\NumeroCnj;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ingestao das comunicacoes do DJEN (M1).
 *
 * Invariantes:
 *  - RF-1.3 janela = ontem + hoje, sempre;
 *  - RF-1.4 deduplicacao por hash;
 *  - RF-1.6 vinculo automatico ao processo pelo numero CNJ;
 *  - RF-1.9 toda varredura vira log, inclusive a que falhou;
 *  - RF-1.10 duas falhas seguidas = "seu radar esta cego".
 */
class IngestaoService
{
    public function __construct(private readonly DjenClient $djen) {}

    /**
     * @return array{novas: int, total: int}
     */
    public function sincronizar(OabWatch $watch, ?CarbonImmutable $inicio = null, ?CarbonImmutable $fim = null): array
    {
        $tz = config('mithrandir.timezone');
        $hoje = CarbonImmutable::now($tz)->startOfDay();

        $fim ??= $hoje;
        $inicio ??= $hoje->subDays($this->diasDaJanela($watch) - 1);

        $comecou = microtime(true);

        try {
            $comunicacoes = match ($watch->tipo) {
                'oab' => $this->djen->porOab($watch->termo, $watch->uf ?? $watch->advogado->uf, $inicio, $fim),
                'cliente' => $this->djen->porNomeParte($watch->termo, $watch->uf, $inicio, $fim),
                default => $this->djen->porNome($watch->termo, $watch->uf, $inicio, $fim),
            };

            $this->recusarVolumeAnormal($watch, count($comunicacoes));

            $novas = $this->persistir($watch, $comunicacoes);

            $watch->forceFill([
                'ultima_sync_em' => now(),
                'falhas_consecutivas' => 0,
            ])->save();

            SyncLog::create([
                'oab_watch_id' => $watch->id,
                'executado_em' => now(),
                'status' => 'sucesso',
                'qtd_itens' => count($comunicacoes),
                'qtd_novas' => $novas,
                'janela_inicio' => $inicio,
                'janela_fim' => $fim,
                'duracao_ms' => (int) ((microtime(true) - $comecou) * 1000),
            ]);

            if ($novas > 0) {
                $this->avisarPublicacoesNovas($watch, $novas);
            }

            return ['novas' => $novas, 'total' => count($comunicacoes)];
        } catch (Throwable $e) {
            $watch->increment('falhas_consecutivas');
            $watch->refresh();

            SyncLog::create([
                'oab_watch_id' => $watch->id,
                'executado_em' => now(),
                'status' => 'falha',
                'qtd_itens' => 0,
                'janela_inicio' => $inicio,
                'janela_fim' => $fim,
                'duracao_ms' => (int) ((microtime(true) - $comecou) * 1000),
                'erro' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            Log::error('Falha na varredura do DJEN', [
                'watch' => $watch->id,
                'termo' => $watch->termo,
                'erro' => $e->getMessage(),
            ]);

            if ($watch->estaCego()) {
                $this->avisarRadarCego($watch);
            }

            throw $e;
        }
    }

    /**
     * O inverso do radar cego: uma enxurrada.
     *
     * Parametro desconhecido nao da erro no DJEN - a API ignora e devolve o
     * diario inteiro. Se o CNJ renomear um campo, ou se o nome vigiado for
     * comum demais ("Maria da Silva" traz dez mil), a triagem afoga e o prazo
     * de verdade some no ruido. Melhor recusar o lote e avisar.
     */
    private function recusarVolumeAnormal(OabWatch $watch, int $quantidade): void
    {
        $teto = (int) config('mithrandir.djen.teto_por_varredura', 300);

        if ($quantidade <= $teto) {
            return;
        }

        $this->avisarEnxurrada($watch, $quantidade, $teto);

        throw new VolumeAnormalException(sprintf(
            'A varredura de "%s" retornou %d publicacoes (teto: %d). '
            .'Nada foi importado para nao afogar a triagem.',
            $watch->termo,
            $quantidade,
            $teto
        ));
    }

    private function avisarEnxurrada(OabWatch $watch, int $quantidade, int $teto): void
    {
        Notificacao::updateOrCreate(
            ['chave_dedup' => 'enxurrada:'.$watch->id.':'.now()->toDateString()],
            [
                'advogado_id' => $watch->advogado_id,
                'tipo' => 'volume_anormal',
                'titulo' => 'Volume anormal na varredura',
                'corpo' => sprintf(
                    'O termo "%s" retornou %d publicacoes, acima do teto de %d. '
                    .'Nada foi importado. Se for nome de cliente, ele pode ser comum demais.',
                    $watch->termo,
                    $quantidade,
                    $teto
                ),
                'url' => '/configuracoes/radar',
                'payload' => ['watch_id' => $watch->id, 'quantidade' => $quantidade],
                'agendada_para' => now(),
            ]
        );
    }

    /**
     * Quantos dias corridos a varredura cobre, contando ate hoje.
     *
     * O minimo e 2 (ontem + hoje, RF-1.3): as datas do DJEN sao
     * America/Sao_Paulo e, as 22h de Brasilia, o UTC ja virou o dia seguinte.
     */
    private function diasDaJanela(OabWatch $watch): int
    {
        // O advogado manda; a config do sistema e so o padrao de quem nao mexeu.
        $escolhida = $watch->advogado?->janela_djen_dias
            ?: (int) config('mithrandir.djen.janela_dias', 7);

        if ($watch->ultima_sync_em === null) {
            // Conta nova nao tem historico: busca o maior entre o backfill
            // padrao e a janela escolhida, para nao abrir vazia.
            $escolhida = max($escolhida, (int) config('mithrandir.djen.janela_primeira_sync_dias', 30));
        }

        return max(2, $escolhida);
    }

    /**
     * @param  array<int, ComunicacaoDto>  $comunicacoes
     */
    private function persistir(OabWatch $watch, array $comunicacoes): int
    {
        $novas = 0;

        foreach ($comunicacoes as $comunicacao) {
            $hash = $comunicacao->hash($watch->advogado_id);

            if (Publicacao::where('hash', $hash)->exists()) {
                continue;
            }

            DB::transaction(function () use ($watch, $comunicacao, $hash, &$novas) {
                Publicacao::create([
                    'advogado_id' => $watch->advogado_id,
                    'oab_watch_id' => $watch->id,
                    'cliente_id' => $watch->cliente_id,
                    'origem_vigilancia' => $watch->vigiaCliente() ? 'cliente' : 'advogado',
                    'hash' => $hash,
                    'numero_comunicacao' => $comunicacao->numeroComunicacao,
                    'tribunal' => $comunicacao->tribunal,
                    'orgao' => $comunicacao->orgao,
                    'numero_processo' => $comunicacao->numeroProcesso,
                    'tipo_comunicacao' => $comunicacao->tipoComunicacao,
                    'meio' => $comunicacao->meio,
                    'teor' => $comunicacao->teor,
                    'data_disponibilizacao' => $comunicacao->dataDisponibilizacao,
                    'destinatarios' => $comunicacao->destinatarios,
                    'advogados_intimados' => $comunicacao->advogadosIntimados(),
                    'payload_bruto' => $comunicacao->bruto,
                    'status_triagem' => 'nova',
                    'processo_id' => $this->processoVinculado($watch->advogado_id, $comunicacao->numeroProcesso),
                ]);

                $novas++;
            });
        }

        return $novas;
    }

    /**
     * RF-1.6: vincula pelo numero CNJ ja cadastrado. Se nao existir, fica nulo
     * e a triagem oferece criar o caso.
     */
    private function processoVinculado(int $advogadoId, ?string $numeroProcesso): ?int
    {
        if (! $numeroProcesso) {
            return null;
        }

        $limpo = NumeroCnj::limpar($numeroProcesso);

        return Processo::doAdvogado($advogadoId)
            ->get(['id', 'numero_cnj'])
            ->first(fn (Processo $p) => NumeroCnj::limpar((string) $p->numero_cnj) === $limpo)
            ?->id;
    }

    private function avisarPublicacoesNovas(OabWatch $watch, int $quantidade): void
    {
        Notificacao::create([
            'advogado_id' => $watch->advogado_id,
            'tipo' => 'publicacao_nova',
            'titulo' => $quantidade === 1
                ? 'Nova publicacao no DJEN'
                : "{$quantidade} novas publicacoes no DJEN",
            'corpo' => 'Toque para triar: virar prazo, dar ciencia ou descartar.',
            'url' => '/publicacoes',
            'payload' => ['watch_id' => $watch->id, 'quantidade' => $quantidade],
            'chave_dedup' => 'pub:'.$watch->advogado_id.':'.now()->toDateString().':'.$watch->id,
            'agendada_para' => now(),
        ]);
    }

    /**
     * RF-1.10: falha de varredura e evento de severidade alta.
     */
    private function avisarRadarCego(OabWatch $watch): void
    {
        Notificacao::updateOrCreate(
            ['chave_dedup' => 'radar-cego:'.$watch->id.':'.now()->toDateString()],
            [
                'advogado_id' => $watch->advogado_id,
                'tipo' => 'radar_cego',
                'titulo' => 'Seu radar esta cego',
                'corpo' => sprintf(
                    'A varredura do termo "%s" falhou %d vezes seguidas. Confira o diario manualmente hoje.',
                    $watch->termo,
                    $watch->falhas_consecutivas
                ),
                'url' => '/configuracoes/radar',
                'payload' => ['watch_id' => $watch->id],
                'agendada_para' => now(),
            ]
        );
    }
}
