<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Processo;
use App\Models\Publicacao;
use App\Models\TipoPrazo;
use App\Services\Djen\IngestaoService;
use App\Services\Prazo\PrazoRepository;
use App\Support\NumeroCnj;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Tela 2. "O que o Judiciario me mandou?"
 *
 * Decisao arquitetural n.3: publicacao e imutavel. A triagem cria registros
 * novos e marca o status; nunca reescreve o teor.
 */
class PublicacaoController extends Controller
{
    public function index(Request $request): Response
    {
        $advogado = contexto()->advogado();
        $status = $request->string('status')->toString() ?: 'nova';

        $publicacoes = Publicacao::with('processo.cliente')
            ->visivel()
            ->when($status !== 'todas', fn ($q) => $q->where('status_triagem', $status))
            ->orderByDesc('data_disponibilizacao')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Publicacao $p) => $this->paraLista($p));

        return Inertia::render('Publicacoes/Index', [
            'publicacoes' => $publicacoes,
            'status' => $status,
            'contadores' => [
                'nova' => Publicacao::visivel()->where('status_triagem', 'nova')->count(),
                'prazo' => Publicacao::visivel()->where('status_triagem', 'prazo')->count(),
                'ciencia' => Publicacao::visivel()->where('status_triagem', 'ciencia')->count(),
                'descartada' => Publicacao::visivel()->where('status_triagem', 'descartada')->count(),
            ],
            'tipos_prazo' => TipoPrazo::where('ativo', true)->orderBy('nome')->get(),
            'ultima_sync' => $advogado->watches()->max('ultima_sync_em'),
        ]);
    }

    public function show(Request $request, Publicacao $publicacao): Response
    {
        $this->autorizar($publicacao);

        $publicacao->load('processo.cliente', 'watch', 'cliente');

        return Inertia::render('Publicacoes/Show', [
            'publicacao' => array_merge($this->paraLista($publicacao), [
                'teor' => $publicacao->teorLegivel(),
                'destinatarios' => $publicacao->destinatarios,
                'tipo_comunicacao' => $publicacao->tipo_comunicacao,
                'meio' => $publicacao->meio,
                'numero_comunicacao' => $publicacao->numero_comunicacao,
                'termo_origem' => $publicacao->watch?->rotulo(),
                'advogados_intimados' => $publicacao->advogados_intimados ?? [],
            ]),
            'tipos_prazo' => TipoPrazo::where('ativo', true)->orderBy('nome')->get(),
            'processos' => Processo::visivel()
                ->whereNull('arquivado_em')
                ->orderBy('titulo')
                ->get(['id', 'titulo', 'numero_cnj'])
                ->map(fn (Processo $p) => ['id' => $p->id, 'rotulo' => $p->rotulo]),
        ]);
    }

    /**
     * RF-1.5: cada publicacao vira prazo, ciencia ou descarte.
     */
    public function triar(Request $request, Publicacao $publicacao, PrazoRepository $prazos): RedirectResponse
    {
        $this->autorizar($publicacao);

        $dados = $request->validate([
            'decisao' => ['required', 'in:prazo,ciencia,descartada'],
            'processo_id' => ['nullable', 'integer', 'exists:processos,id'],
            'criar_processo' => ['nullable', 'boolean'],
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
            // Campos do prazo, exigidos so quando a decisao e "prazo".
            'tipo_prazo_id' => ['nullable', 'integer', 'exists:tipos_prazo,id'],
            'tipo' => ['nullable', 'string', 'max:120'],
            'dias' => ['nullable', 'integer', 'min:1', 'max:365'],
            'em_dias_uteis' => ['nullable', 'boolean'],
            'multiplicador' => ['nullable', 'integer', 'in:1,2'],
            'multiplicador_motivo' => ['nullable', 'string', 'max:120'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
        ]);

        $advogado = contexto()->advogado();
        $autor = $request->user();

        DB::transaction(function () use ($dados, $publicacao, $advogado, $autor, $prazos) {
            $processoId = $dados['processo_id'] ?? $publicacao->processo_id;

            // RF-1.6: se o processo nao existe, a triagem oferece cria-lo.
            if (! $processoId && ($dados['criar_processo'] ?? false)) {
                $processoId = $this->criarProcessoDaPublicacao($publicacao, $dados['cliente_id'] ?? null)->id;
            }

            if ($dados['decisao'] === 'prazo') {
                $tipo = isset($dados['tipo_prazo_id']) ? TipoPrazo::find($dados['tipo_prazo_id']) : null;

                $prazos->criar($advogado, [
                    'processo_id' => $processoId,
                    'publicacao_id' => $publicacao->id,
                    'tipo_prazo_id' => $tipo?->id,
                    'tipo' => $dados['tipo'] ?? $tipo?->nome ?? 'Prazo',
                    'dias' => $dados['dias'] ?? $tipo?->dias ?? 15,
                    'em_dias_uteis' => $dados['em_dias_uteis'] ?? $tipo?->em_dias_uteis ?? true,
                    'multiplicador' => $dados['multiplicador'] ?? 1,
                    'multiplicador_motivo' => $dados['multiplicador_motivo'] ?? null,
                    'data_disponibilizacao' => CarbonImmutable::parse($publicacao->data_disponibilizacao),
                    'buffer_dias' => $advogado->buffer_padrao,
                    'responsavel_id' => $autor->id,
                    'observacoes' => $dados['observacoes'] ?? null,
                ]);
            }

            $publicacao->forceFill([
                'status_triagem' => $dados['decisao'],
                'processo_id' => $processoId,
                'triada_em' => now(),
                // Nunca apagada: descarte e arquivamento, nao delecao.
                'arquivada_em' => $dados['decisao'] === 'descartada' ? now() : null,
            ])->save();

            Auditoria::registrar(
                $advogado->id,
                'publicacoes',
                $publicacao->id,
                'triagem:'.$dados['decisao'],
                null,
                ['processo_id' => $processoId]
            );
        });

        return back()->with('sucesso', match ($dados['decisao']) {
            'prazo' => 'Prazo criado a partir da publicacao.',
            'ciencia' => 'Marcada como ciencia, sem prazo.',
            default => 'Publicacao descartada (segue arquivada).',
        });
    }

    /**
     * RF-1.8: sincronizacao sob demanda, com rate limit na rota.
     */
    public function sincronizar(Request $request, IngestaoService $ingestao): RedirectResponse
    {
        $watches = $request->user()->watches()->where('ativo', true)->get();

        if ($watches->isEmpty()) {
            return back()->with('erro', 'Cadastre ao menos um termo de vigilancia antes de sincronizar.');
        }

        $novas = 0;
        $falhas = 0;

        foreach ($watches as $watch) {
            try {
                $resultado = $ingestao->sincronizar($watch);
                $novas += $resultado['novas'];
            } catch (Throwable $e) {
                $falhas++;

                // Sem isto a falha vira so "confira o diario manualmente" na
                // tela: fora do DJEN indisponivel de fato, a causa costuma ser
                // rede ou bloqueio do lado do servidor, e nada disso aparece.
                Log::warning('Varredura manual do DJEN falhou', [
                    'oab_watch_id' => $watch->id,
                    'excecao' => $e::class,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        if ($falhas === $watches->count()) {
            return back()->with('erro', 'Nao foi possivel falar com o DJEN agora. Confira o diario manualmente.');
        }

        return back()->with('sucesso', $novas === 0
            ? 'Varredura concluida: nenhuma publicacao nova.'
            : "Varredura concluida: {$novas} publicacao(oes) nova(s).");
    }

    private function autorizar(Publicacao $publicacao): void
    {
        abort_unless($publicacao->advogado_id === contexto()->advogadoId(), 403);
        abort_unless(contexto()->podeVerProcesso($publicacao->processo_id), 403);
    }

    private function criarProcessoDaPublicacao(Publicacao $publicacao, ?int $clienteId): Processo
    {
        $cliente = $clienteId
            ? Cliente::doAdvogado($publicacao->advogado_id)->find($clienteId)
            : null;

        return Processo::create([
            'advogado_id' => $publicacao->advogado_id,
            'cliente_id' => $cliente?->id,
            'numero_cnj' => $publicacao->numero_processo,
            'titulo' => $publicacao->numero_processo
                ? NumeroCnj::formatar($publicacao->numero_processo)
                : 'Caso criado da publicacao #'.$publicacao->id,
            'tribunal' => $publicacao->tribunal,
            'vara' => $publicacao->orgao,
            'fase' => 'conhecimento',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paraLista(Publicacao $publicacao): array
    {
        $publicacao->loadMissing('cliente');

        return [
            'id' => $publicacao->id,
            'tribunal' => $publicacao->tribunal,
            'orgao' => $publicacao->orgao,
            'numero_processo' => $publicacao->numero_processo,
            'numero_formatado' => $publicacao->numero_processo
                ? NumeroCnj::formatar($publicacao->numero_processo)
                : null,
            'data_disponibilizacao' => CarbonImmutable::parse($publicacao->data_disponibilizacao)->toDateString(),
            'status_triagem' => $publicacao->status_triagem,
            'resumo' => $publicacao->resumo(),
            // De onde veio: vigilancia da OAB do advogado ou do nome de um cliente.
            'origem_vigilancia' => $publicacao->origem_vigilancia,
            'cliente_vigiado' => $publicacao->cliente?->nome,
            'processo' => $publicacao->processo ? [
                'id' => $publicacao->processo->id,
                'rotulo' => $publicacao->processo->rotulo,
                'cliente' => $publicacao->processo->cliente?->nome,
            ] : null,
        ];
    }
}
