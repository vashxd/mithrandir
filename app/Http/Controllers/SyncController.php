<?php

namespace App\Http\Controllers;

use App\Models\Atendimento;
use App\Models\Cliente;
use App\Models\Despesa;
use App\Models\Evento;
use App\Models\OutboxItem;
use App\Models\Prazo;
use App\Models\Processo;
use App\Models\Publicacao;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sincronizacao offline (secao 10).
 *
 * Escritas feitas sem sinal ficam no outbox local (IndexedDB) e sao repostas
 * aqui ao reconectar. Cada item carrega um client_id: reenviar o mesmo item
 * duas vezes nao duplica nada.
 *
 * Regra de conflito: last-write-wins em campo nao critico. Prazo e financeiro
 * NUNCA resolvem sozinhos - viram item de revisao.
 */
class SyncController extends Controller
{
    /** Entidades que o cliente pode criar/alterar offline. */
    private const ENTIDADES = [
        'clientes' => Cliente::class,
        'processos' => Processo::class,
        'eventos' => Evento::class,
        'atendimentos' => Atendimento::class,
        'despesas' => Despesa::class,
    ];

    /** Entidades que jamais se resolvem automaticamente. */
    private const CRITICAS = ['prazos', 'honorarios', 'parcelas'];

    public function receber(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'itens' => ['required', 'array', 'max:200'],
            'itens.*.client_id' => ['required', 'uuid'],
            'itens.*.entidade' => ['required', 'string', 'max:40'],
            'itens.*.operacao' => ['required', 'in:create,update,delete'],
            'itens.*.payload' => ['required', 'array'],
            'itens.*.criado_em' => ['required', 'date'],
        ]);

        $advogado = $request->user();
        $resultados = [];

        foreach ($dados['itens'] as $item) {
            $registro = OutboxItem::firstOrCreate(
                ['client_id' => $item['client_id']],
                [
                    'advogado_id' => $advogado->id,
                    'entidade' => $item['entidade'],
                    'operacao' => $item['operacao'],
                    'payload' => $item['payload'],
                    'criado_em' => CarbonImmutable::parse($item['criado_em']),
                    'status' => 'pendente',
                ]
            );

            // Idempotencia: item ja processado nao roda de novo.
            if (! $registro->wasRecentlyCreated && $registro->status !== 'pendente') {
                $resultados[] = [
                    'client_id' => $item['client_id'],
                    'status' => $registro->status,
                    'server_id' => $registro->payload['server_id'] ?? null,
                ];

                continue;
            }

            $resultados[] = $this->aplicar($registro, $advogado->id);
        }

        return response()->json([
            'resultados' => $resultados,
            'sincronizado_em' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function aplicar(OutboxItem $item, int $advogadoId): array
    {
        if (in_array($item->entidade, self::CRITICAS, true)) {
            $item->forceFill([
                'status' => 'conflito',
                'erro' => 'Prazo e financeiro nao sincronizam automaticamente: revise no aparelho.',
            ])->save();

            return ['client_id' => $item->client_id, 'status' => 'conflito', 'server_id' => null];
        }

        $classe = self::ENTIDADES[$item->entidade] ?? null;

        if ($classe === null) {
            $item->forceFill(['status' => 'erro', 'erro' => 'Entidade desconhecida.'])->save();

            return ['client_id' => $item->client_id, 'status' => 'erro', 'server_id' => null];
        }

        try {
            $serverId = DB::transaction(function () use ($item, $classe, $advogadoId) {
                $payload = $item->payload;
                unset($payload['id'], $payload['client_id'], $payload['advogado_id']);

                if ($item->operacao === 'create') {
                    return $classe::create($payload + ['advogado_id' => $advogadoId])->id;
                }

                $modelo = $classe::where('advogado_id', $advogadoId)->find($item->payload['id'] ?? null);

                if (! $modelo) {
                    throw new \RuntimeException('Registro nao encontrado no servidor.');
                }

                if ($item->operacao === 'delete') {
                    $modelo->delete();

                    return $modelo->id;
                }

                // Last-write-wins: quem gravou por ultimo vence, em campo nao critico.
                $modelo->update($payload);

                return $modelo->id;
            });

            $item->forceFill([
                'status' => 'aplicado',
                'sincronizado_em' => now(),
                'payload' => $item->payload + ['server_id' => $serverId],
            ])->save();

            return ['client_id' => $item->client_id, 'status' => 'aplicado', 'server_id' => $serverId];
        } catch (Throwable $e) {
            $item->forceFill([
                'status' => 'erro',
                'erro' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            return ['client_id' => $item->client_id, 'status' => 'erro', 'server_id' => null];
        }
    }

    /**
     * Espelho de leitura para o IndexedDB: agenda, casos e clientes.
     * Serve a premissa da secao 10 - offline nao e feature, e premissa.
     */
    public function snapshot(Request $request): JsonResponse
    {
        $advogado = $request->user();
        $tz = $advogado->timezone ?: config('mithrandir.timezone');
        $hoje = CarbonImmutable::today($tz);

        return response()->json([
            'gerado_em' => now()->toIso8601String(),
            'eventos' => Evento::with('processo')
                ->doAdvogado(contexto()->advogadoId())
                ->entre($hoje->subDays(7)->startOfDay(), $hoje->addDays(60)->endOfDay())
                ->orderBy('inicio')
                ->get()
                ->map(fn (Evento $e) => [
                    'id' => $e->id,
                    'tipo' => $e->tipo,
                    'titulo' => $e->titulo,
                    'inicio' => $e->inicio?->toIso8601String(),
                    'dia' => $e->inicio?->toDateString(),
                    'local' => $e->local,
                    'concluido' => (bool) $e->concluido,
                    'processo' => $e->processo?->rotulo,
                ]),
            'prazos' => Prazo::with('processo')
                ->doAdvogado(contexto()->advogadoId())
                ->abertos()
                ->orderBy('data_fatal')
                ->get()
                ->map(fn (Prazo $p) => [
                    'id' => $p->id,
                    'tipo' => $p->tipo,
                    'data_fatal' => CarbonImmutable::parse($p->data_fatal)->toDateString(),
                    'data_alvo' => CarbonImmutable::parse($p->data_alvo)->toDateString(),
                    'criticidade' => $p->criticidade,
                    'processo' => $p->processo?->rotulo,
                ]),
            'processos' => Processo::with('cliente')
                ->doAdvogado(contexto()->advogadoId())
                ->whereNull('arquivado_em')
                ->orderBy('titulo')
                ->get()
                ->map(fn (Processo $p) => [
                    'id' => $p->id,
                    'rotulo' => $p->rotulo,
                    'numero_formatado' => $p->numero_formatado,
                    'fase' => $p->fase,
                    'proxima_acao' => $p->proxima_acao,
                    'cliente' => $p->cliente?->nome,
                ]),
            'clientes' => Cliente::doAdvogado(contexto()->advogadoId())
                ->whereNull('arquivado_em')
                ->orderBy('nome')
                ->get()
                ->map(fn (Cliente $c) => [
                    'id' => $c->id,
                    'nome' => $c->nome,
                    'documento' => $c->documento,
                    'telefone' => $c->telefonePrincipal(),
                ]),
            'publicacoes_nao_triadas' => Publicacao::doAdvogado(contexto()->advogadoId())
                ->naoTriadas()
                ->orderByDesc('data_disponibilizacao')
                ->limit(50)
                ->get()
                ->map(fn (Publicacao $p) => [
                    'id' => $p->id,
                    'tribunal' => $p->tribunal,
                    'numero_processo' => $p->numero_processo,
                    'data_disponibilizacao' => CarbonImmutable::parse($p->data_disponibilizacao)->toDateString(),
                    'resumo' => $p->resumo(),
                ]),
        ]);
    }
}
