<?php

namespace App\Http\Controllers;

use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\Cliente;
use App\Models\Documento;
use App\Models\Movimentacao;
use App\Models\Prazo;
use App\Models\Processo;
use App\Models\Publicacao;
use App\Services\DataJud\ChaveInvalidaException;
use App\Services\DataJud\DataJudClient;
use App\Services\DataJud\DataJudIndisponivelException;
use App\Support\NumeroCnj;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tela 4. "Qual a situacao deste processo?"
 */
class ProcessoController extends Controller
{
    public function index(Request $request): Response
    {
        $advogado = $request->user();
        $busca = $request->string('busca')->toString();

        $processos = Processo::with('cliente')
            ->withCount(['prazos as prazos_abertos' => fn ($q) => $q->abertos()])
            ->visivel()
            ->when(! $request->boolean('arquivados'), fn ($q) => $q->whereNull('arquivado_em'))
            ->when($busca, function ($q) use ($busca) {
                $numero = NumeroCnj::limpar($busca);

                $q->where(function ($sub) use ($busca, $numero) {
                    $sub->where('titulo', op_like(), "%{$busca}%")
                        ->orWhere('assunto', op_like(), "%{$busca}%")
                        ->orWhere('numero_cnj', op_like(), '%'.($numero ?: $busca).'%')
                        ->orWhereHas('cliente', fn ($c) => $c->where('nome', op_like(), "%{$busca}%"));
                });
            })
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Processo $p) => [
                'id' => $p->id,
                'rotulo' => $p->rotulo,
                'numero_formatado' => $p->numero_formatado,
                'tribunal' => $p->tribunal,
                'fase' => $p->fase,
                'area' => $p->area,
                'cliente' => $p->cliente?->nome,
                'proxima_acao' => $p->proxima_acao,
                'prazos_abertos' => $p->prazos_abertos,
                'arquivado' => $p->arquivado_em !== null,
            ]);

        return Inertia::render('Processos/Index', [
            'processos' => $processos,
            'filtros' => ['busca' => $busca, 'arquivados' => $request->boolean('arquivados')],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Processos/Form', [
            'clientes' => $this->clientes($request),
            'templates' => ChecklistTemplate::orderBy('nome')->get(['id', 'nome', 'slug', 'area']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $this->validar($request);

        $processo = Processo::create($dados + ['advogado_id' => contexto()->advogadoId()]);

        // Vincula publicacoes orfas que ja citavam este numero (RF-1.6).
        if ($processo->numero_cnj) {
            $limpo = NumeroCnj::limpar($processo->numero_cnj);

            Publicacao::doAdvogado(contexto()->advogadoId())
                ->whereNull('processo_id')
                ->whereRaw("REPLACE(REPLACE(REPLACE(numero_processo, '.', ''), '-', ''), '/', '') = ?", [$limpo])
                ->update(['processo_id' => $processo->id]);
        }

        return redirect()->route('processos.show', $processo)->with('sucesso', 'Caso criado.');
    }

    public function show(Request $request, Processo $processo): Response
    {
        $this->autorizar($processo);

        $processo->load(['cliente', 'partes', 'checklists.documento']);

        return Inertia::render('Processos/Show', [
            'processo' => [
                'id' => $processo->id,
                'titulo' => $processo->titulo,
                'rotulo' => $processo->rotulo,
                'numero_cnj' => $processo->numero_cnj,
                'numero_formatado' => $processo->numero_formatado,
                'tribunal' => $processo->tribunal,
                'vara' => $processo->vara,
                'classe' => $processo->classe,
                'assunto' => $processo->assunto,
                'fase' => $processo->fase,
                'area' => $processo->area,
                'valor_causa' => $processo->valor_causa ? (float) $processo->valor_causa : null,
                'proxima_acao' => $processo->proxima_acao,
                'segredo_justica' => (bool) $processo->segredo_justica,
                'arquivado' => $processo->arquivado_em !== null,
                'datajud_sincronizado_em' => $processo->datajud_sincronizado_em?->toIso8601String(),
                'cliente' => $processo->cliente ? [
                    'id' => $processo->cliente->id,
                    'nome' => $processo->cliente->nome,
                    'telefone' => $processo->cliente->telefonePrincipal(),
                ] : null,
                'partes' => $processo->partes->map(fn ($p) => [
                    'id' => $p->id, 'nome' => $p->nome, 'tipo' => $p->tipo, 'documento' => $p->documento,
                ]),
            ],
            'prazos' => Prazo::visivel()
                ->where('processo_id', $processo->id)
                ->orderBy('data_fatal')
                ->get()
                ->map(fn (Prazo $p) => [
                    'id' => $p->id,
                    'tipo' => $p->tipo,
                    'status' => $p->status,
                    'data_fatal' => CarbonImmutable::parse($p->data_fatal)->toDateString(),
                    'data_alvo' => CarbonImmutable::parse($p->data_alvo)->toDateString(),
                    'dias_restantes' => $p->dias_restantes,
                    'criticidade' => $p->criticidade,
                ]),
            // RF-4.3: timeline do caso em uma unica lista ordenada.
            'timeline' => $this->timeline($processo),
            'documentos' => $processo->documentos()->orderByDesc('created_at')->get()
                ->map(fn (Documento $d) => [
                    'id' => $d->id,
                    'nome' => $d->nome,
                    'tipo' => $d->tipo,
                    'tipo_rotulo' => $d->tipo_rotulo,
                    'tamanho' => $d->tamanho_legivel,
                    'origem' => $d->origem,
                    'criado_em' => $d->created_at?->toIso8601String(),
                ]),
            'checklist' => $this->checklist($processo),
            'financeiro' => $this->financeiro($processo),
            'tipos_documento' => Documento::TIPOS,
            'templates' => ChecklistTemplate::orderBy('nome')->get(['id', 'nome', 'slug', 'area']),
            'clientes' => $this->clientes($request),
            'datajud_disponivel' => app(DataJudClient::class)->configurado(),
        ]);
    }

    public function update(Request $request, Processo $processo): RedirectResponse
    {
        $this->autorizar($processo);

        $processo->update($this->validar($request, $processo));

        return back()->with('sucesso', 'Caso atualizado.');
    }

    /**
     * RF-4.6: arquivar sem apagar historico.
     */
    public function arquivar(Request $request, Processo $processo): RedirectResponse
    {
        $this->autorizar($processo);

        $abertos = $processo->prazos()->abertos()->count();

        if ($abertos > 0 && ! $request->boolean('confirmar')) {
            return back()->with('erro', "Este caso ainda tem {$abertos} prazo(s) em aberto.");
        }

        $processo->forceFill([
            'arquivado_em' => $processo->arquivado_em ? null : now(),
        ])->save();

        return back()->with('sucesso', $processo->arquivado_em ? 'Caso arquivado.' : 'Caso reaberto.');
    }

    /**
     * RF-4.4: importar capa e movimentacoes do DataJud.
     * Enriquecimento, nunca gatilho de prazo (secao 7.2).
     */
    public function importarDataJud(Request $request, Processo $processo, DataJudClient $datajud): RedirectResponse
    {
        $this->autorizar($processo);

        if (! $processo->numero_cnj) {
            return back()->with('erro', 'Este caso nao tem numero CNJ para consultar.');
        }

        try {
            $resultado = $datajud->consultarProcesso($processo->numero_cnj);
        } catch (ChaveInvalidaException $e) {
            return back()->with('erro', $e->getMessage());
        } catch (DataJudIndisponivelException $e) {
            return back()->with('erro', 'DataJud indisponivel agora. Os prazos nao dependem dele.');
        }

        if ($resultado === null) {
            return back()->with('erro', 'O DataJud nao retornou dados para este numero.');
        }

        // Zero digitacao redundante (principio 4): so preenche o que esta vazio.
        $capa = $resultado['capa'];
        $processo->fill(array_filter([
            'classe' => $processo->classe ?: ($capa['classe'] ?? null),
            'assunto' => $processo->assunto ?: ($capa['assunto'] ?? null),
            'tribunal' => $processo->tribunal ?: ($capa['tribunal'] ?? null),
            'vara' => $processo->vara ?: ($capa['vara'] ?? null),
            'valor_causa' => $processo->valor_causa ?: ($capa['valor_causa'] ?? null),
        ]));
        $processo->datajud_sincronizado_em = now();
        $processo->save();

        $novas = 0;

        foreach ($resultado['movimentacoes'] as $movimento) {
            $criada = Movimentacao::firstOrCreate(
                ['processo_id' => $processo->id, 'hash' => $movimento['hash']],
                $movimento + ['processo_id' => $processo->id]
            );

            $novas += $criada->wasRecentlyCreated ? 1 : 0;
        }

        return back()->with('sucesso', "Capa atualizada e {$novas} movimentacao(oes) importada(s).");
    }

    /**
     * RF-6.3: aplica um template de checklist ao caso.
     */
    public function aplicarChecklist(Request $request, Processo $processo): RedirectResponse
    {
        $this->autorizar($processo);

        $dados = $request->validate([
            'template_id' => ['required', 'integer', 'exists:checklist_templates,id'],
        ]);

        $template = ChecklistTemplate::findOrFail($dados['template_id']);

        foreach ($template->itens as $ordem => $item) {
            ChecklistItem::firstOrCreate(
                ['processo_id' => $processo->id, 'item' => $item['item']],
                [
                    'template_id' => $template->id,
                    'tipo_documento' => $item['tipo_documento'] ?? null,
                    'obrigatorio' => $item['obrigatorio'] ?? true,
                    'ordem' => $ordem,
                ]
            );
        }

        return back()->with('sucesso', "Checklist \"{$template->nome}\" aplicado.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?Processo $processo = null): array
    {
        $dados = $request->validate([
            'cliente_id' => ['nullable', 'integer', Rule::exists('clientes', 'id')
                ->where('advogado_id', contexto()->advogadoId())],
            'numero_cnj' => ['nullable', 'string', 'max:25'],
            'titulo' => ['nullable', 'string', 'max:200'],
            'tribunal' => ['nullable', 'string', 'max:40'],
            'vara' => ['nullable', 'string', 'max:255'],
            'classe' => ['nullable', 'string', 'max:255'],
            'assunto' => ['nullable', 'string', 'max:255'],
            'fase' => ['nullable', 'string', 'max:40'],
            'area' => ['nullable', 'string', 'max:40'],
            'valor_causa' => ['nullable', 'numeric', 'min:0'],
            'proxima_acao' => ['nullable', 'string', 'max:2000'],
            'segredo_justica' => ['boolean'],
        ]);

        // RF-4.1: validacao do digito verificador do numero CNJ.
        if (! empty($dados['numero_cnj'])) {
            $limpo = NumeroCnj::limpar($dados['numero_cnj']);

            if (strlen($limpo) !== 20 || ! NumeroCnj::valido($limpo)) {
                throw ValidationException::withMessages([
                    'numero_cnj' => 'Numero CNJ invalido: o digito verificador nao confere.',
                ]);
            }

            $dados['numero_cnj'] = $limpo;
        }

        return $dados;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function timeline(Processo $processo): array
    {
        $itens = [];

        foreach ($processo->publicacoes()->orderByDesc('data_disponibilizacao')->limit(50)->get() as $publicacao) {
            $itens[] = [
                'tipo' => 'publicacao',
                'data' => CarbonImmutable::parse($publicacao->data_disponibilizacao)->toDateString(),
                'titulo' => 'Publicacao no '.($publicacao->tribunal ?? 'DJEN'),
                'detalhe' => $publicacao->resumo(180),
                'url' => "/publicacoes/{$publicacao->id}",
                'status' => $publicacao->status_triagem,
            ];
        }

        foreach ($processo->movimentacoes()->limit(50)->get() as $movimento) {
            $itens[] = [
                'tipo' => 'movimentacao',
                'data' => CarbonImmutable::parse($movimento->data)->toDateString(),
                'titulo' => $movimento->descricao,
                'detalhe' => $movimento->origem === 'datajud' ? 'Importado do DataJud' : 'Lancamento manual',
                'url' => null,
                'status' => null,
            ];
        }

        foreach ($processo->prazos()->orderByDesc('data_fatal')->get() as $prazo) {
            $itens[] = [
                'tipo' => 'prazo',
                'data' => CarbonImmutable::parse($prazo->data_fatal)->toDateString(),
                'titulo' => $prazo->tipo,
                'detalhe' => 'Fatal em '.CarbonImmutable::parse($prazo->data_fatal)->format('d/m/Y'),
                'url' => "/prazos/{$prazo->id}",
                'status' => $prazo->status,
            ];
        }

        foreach ($processo->cliente?->atendimentos()->where('processo_id', $processo->id)->get() ?? [] as $atendimento) {
            $itens[] = [
                'tipo' => 'atendimento',
                'data' => CarbonImmutable::parse($atendimento->data)->toDateString(),
                'titulo' => 'Atendimento ('.$atendimento->canal.')',
                'detalhe' => $atendimento->resumo,
                'url' => null,
                'status' => null,
            ];
        }

        usort($itens, fn ($a, $b) => $b['data'] <=> $a['data']);

        return array_slice($itens, 0, 120);
    }

    /**
     * @return array<string, mixed>
     */
    private function checklist(Processo $processo): array
    {
        $itens = $processo->checklists;
        $obrigatorios = $itens->where('obrigatorio', true);
        $preenchidos = $obrigatorios->whereNotNull('documento_id');

        return [
            'itens' => $itens->map(fn (ChecklistItem $i) => [
                'id' => $i->id,
                'item' => $i->item,
                'tipo_documento' => $i->tipo_documento,
                'obrigatorio' => (bool) $i->obrigatorio,
                'documento_id' => $i->documento_id,
                'documento_nome' => $i->documento?->nome,
            ])->values(),
            'total' => $itens->count(),
            'obrigatorios' => $obrigatorios->count(),
            'concluidos' => $preenchidos->count(),
            'percentual' => $obrigatorios->count() > 0
                ? (int) round($preenchidos->count() / $obrigatorios->count() * 100)
                : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function financeiro(Processo $processo): array
    {
        $honorarios = $processo->honorarios()->with('parcelas')->get();
        $parcelas = $honorarios->flatMap->parcelas;

        return [
            'honorarios' => $honorarios->map(fn ($h) => [
                'id' => $h->id,
                'tipo' => $h->tipo,
                'valor' => (float) $h->valor,
                'percentual_exito' => $h->percentual_exito ? (float) $h->percentual_exito : null,
                'parcelas' => $h->parcelas->map(fn ($p) => [
                    'id' => $p->id,
                    'numero' => $p->numero,
                    'valor' => (float) $p->valor,
                    'vencimento' => CarbonImmutable::parse($p->vencimento)->toDateString(),
                    'situacao' => $p->situacao,
                    'pago_em' => $p->pago_em ? CarbonImmutable::parse($p->pago_em)->toDateString() : null,
                ]),
            ]),
            'contratado' => (float) $honorarios->sum('valor'),
            'recebido' => (float) $parcelas->whereNotNull('pago_em')->sum('valor_pago'),
            'a_receber' => (float) $parcelas->whereNull('pago_em')->sum('valor'),
            'despesas' => $processo->despesas()->orderByDesc('data')->get()->map(fn ($d) => [
                'id' => $d->id,
                'descricao' => $d->descricao,
                'valor' => (float) $d->valor,
                'data' => CarbonImmutable::parse($d->data)->toDateString(),
                'reembolsavel' => (bool) $d->reembolsavel,
                'reembolsada' => $d->reembolsada_em !== null,
            ]),
        ];
    }

    /**
     * Dono do espaco + recorte por caso.
     */
    private function autorizar(Processo $processo): void
    {
        abort_unless($processo->advogado_id === contexto()->advogadoId(), 403);
        abort_unless(contexto()->podeVerProcesso($processo->id), 403);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function clientes(Request $request): array
    {
        return Cliente::visivel()
            ->whereNull('arquivado_em')
            ->orderBy('nome')
            ->get(['id', 'nome'])
            ->all();
    }
}
