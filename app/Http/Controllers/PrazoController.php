<?php

namespace App\Http\Controllers;

use App\Models\Membro;
use App\Models\Prazo;
use App\Models\Processo;
use App\Models\TipoPrazo;
use App\Services\Prazo\CalendarioService;
use App\Services\Prazo\PrazoInput;
use App\Services\Prazo\PrazoRepository;
use App\Services\Prazo\PrazoService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PrazoController extends Controller
{
    public function index(Request $request): Response
    {

        $prazos = Prazo::with('processo.cliente', 'responsavel')
            ->visivel()
            ->when($request->boolean('conferencia'), fn ($q) => $q->aguardandoConferencia())
            ->when($request->boolean('meus'), fn ($q) => $q->doResponsavel($request->user()->id))
            ->when($request->boolean('revisao'), fn ($q) => $q->where('precisa_revisao', true))
            ->when(
                $request->string('status')->toString() ?: 'abertos',
                fn ($q, $status) => $status === 'abertos' ? $q->abertos() : $q->where('status', $status)
            )
            ->orderBy('data_fatal')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Prazo $p) => $this->paraLista($p));

        return Inertia::render('Prazos/Index', [
            'prazos' => $prazos,
            'filtros' => [
                'status' => $request->string('status')->toString() ?: 'abertos',
                'revisao' => $request->boolean('revisao'),
                'conferencia' => $request->boolean('conferencia'),
                'meus' => $request->boolean('meus'),
            ],
            'tipos_prazo' => TipoPrazo::where('ativo', true)->orderBy('nome')->get(),
            'processos' => $this->processosDisponiveis($request),
        ]);
    }

    public function show(Request $request, Prazo $prazo): Response
    {
        $this->autorizar($prazo);

        $prazo->load('processo.cliente', 'publicacao', 'responsavel', 'solicitanteDaConferencia');

        return Inertia::render('Prazos/Show', [
            'prazo' => array_merge($this->paraLista($prazo), [
                'dias' => $prazo->dias,
                'em_dias_uteis' => (bool) $prazo->em_dias_uteis,
                'multiplicador' => $prazo->multiplicador,
                'multiplicador_motivo' => $prazo->multiplicador_motivo,
                'buffer_dias' => $prazo->buffer_dias,
                'data_disponibilizacao' => CarbonImmutable::parse($prazo->data_disponibilizacao)->toDateString(),
                'data_publicacao' => CarbonImmutable::parse($prazo->data_publicacao)->toDateString(),
                'data_inicio' => CarbonImmutable::parse($prazo->data_inicio)->toDateString(),
                'data_fatal_calculada' => $prazo->data_fatal_calculada
                    ? CarbonImmutable::parse($prazo->data_fatal_calculada)->toDateString()
                    : null,
                'justificativa' => $prazo->justificativa,
                'observacoes' => $prazo->observacoes,
                // RF-2.7: a cadeia de origem inteira viaja para a tela.
                'cadeia_origem' => $prazo->cadeia_origem,
                'publicacao' => $prazo->publicacao ? [
                    'id' => $prazo->publicacao->id,
                    'teor' => $prazo->publicacao->teor,
                    'tribunal' => $prazo->publicacao->tribunal,
                ] : null,
            ]),
            'equipe' => $this->equipeDisponivel(),
        ]);
    }

    public function store(Request $request, PrazoRepository $prazos): RedirectResponse
    {
        $dados = $request->validate([
            'processo_id' => ['nullable', 'integer', 'exists:processos,id'],
            'tipo_prazo_id' => ['nullable', 'integer', 'exists:tipos_prazo,id'],
            'tipo' => ['required', 'string', 'max:120'],
            'dias' => ['required', 'integer', 'min:1', 'max:365'],
            'em_dias_uteis' => ['boolean'],
            'multiplicador' => ['nullable', 'integer', 'in:1,2'],
            'multiplicador_motivo' => ['nullable', 'string', 'max:120'],
            'data_disponibilizacao' => ['required', 'date'],
            'data_inicio_forcada' => ['nullable', 'date'],
            'buffer_dias' => ['nullable', 'integer', 'min:0', 'max:15'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
        ]);

        // O prazo nasce no espaco do titular, mesmo criado por colaborador.
        $prazo = $prazos->criar(contexto()->advogado(), $dados + [
            'responsavel_id' => $request->user()->id,
        ]);

        return redirect()->route('prazos.show', $prazo)->with('sucesso', 'Prazo criado.');
    }

    /**
     * Previa do calculo antes de gravar: a advogada ve a cadeia inteira e
     * decide. O app nunca e fonte unica (principio 2).
     */
    public function simular(Request $request, PrazoService $service, CalendarioService $calendarios): JsonResponse
    {
        $dados = $request->validate([
            'data_disponibilizacao' => ['required', 'date'],
            'dias' => ['required', 'integer', 'min:1', 'max:365'],
            'em_dias_uteis' => ['boolean'],
            'multiplicador' => ['nullable', 'integer', 'in:1,2'],
            'buffer_dias' => ['nullable', 'integer', 'min:0', 'max:15'],
            'processo_id' => ['nullable', 'integer', 'exists:processos,id'],
            'data_inicio_forcada' => ['nullable', 'date'],
        ]);

        $advogado = contexto()->advogado();
        $processo = isset($dados['processo_id'])
            ? Processo::doAdvogado(contexto()->advogadoId())->find($dados['processo_id'])
            : null;

        $calculado = $service->calcular(
            new PrazoInput(
                dataDisponibilizacao: $dados['data_disponibilizacao'],
                dias: (int) $dados['dias'],
                emDiasUteis: (bool) ($dados['em_dias_uteis'] ?? true),
                multiplicador: (int) ($dados['multiplicador'] ?? 1),
                bufferDias: (int) ($dados['buffer_dias'] ?? $advogado->buffer_padrao),
                inicioForcado: ! empty($dados['data_inicio_forcada'])
                    ? CarbonImmutable::parse($dados['data_inicio_forcada'])
                    : null,
            ),
            $calendarios->para(
                tribunal: $processo?->tribunal,
                uf: $advogado->uf,
                advogadoId: $advogado->id,
            )
        );

        return response()->json($calculado->toArray());
    }

    /**
     * RF-2.8: ajuste manual so passa com justificativa.
     */
    public function ajustar(Request $request, Prazo $prazo, PrazoRepository $prazos): RedirectResponse
    {
        $this->autorizar($prazo);
        abort_unless(contexto()->pode('prazo.cumprir'), 403);

        $dados = $request->validate([
            'data_fatal' => ['required', 'date'],
            'justificativa' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'justificativa.required' => 'Explique por que a data esta sendo alterada. Isso fica registrado.',
            'justificativa.min' => 'A justificativa precisa ser especifica.',
        ]);

        $prazos->ajustarManualmente($prazo, $dados['data_fatal'], $dados['justificativa']);

        return back()->with('sucesso', 'Data ajustada. O calculo original ficou registrado.');
    }

    public function mudarStatus(Request $request, Prazo $prazo, PrazoRepository $prazos): RedirectResponse
    {
        $this->autorizar($prazo);

        // Estagiario nao fecha prazo: ele pede conferencia.
        abort_unless(contexto()->pode('prazo.cumprir'), 403);

        $dados = $request->validate([
            'status' => ['required', 'in:aberto,em_andamento,cumprido,perdido,prejudicado'],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ]);

        $prazos->mudarStatus($prazo, $dados['status'], $dados['observacao'] ?? null);

        return back()->with('sucesso', 'Status atualizado.');
    }

    /**
     * Colaborador terminou a parte dele. O prazo NAO fecha: quem responde
     * pela perda perante a OAB e o titular, entao ele segue aberto ate a
     * conferencia (secao 16, decisao 4).
     */
    public function pedirConferencia(Request $request, Prazo $prazo, PrazoRepository $prazos): RedirectResponse
    {
        $this->autorizar($prazo);
        abort_unless(contexto()->pode('prazo.pedir_conferencia'), 403);

        $dados = $request->validate([
            'observacao' => ['nullable', 'string', 'max:1000'],
        ]);

        $prazos->pedirConferencia($prazo, $request->user(), $dados['observacao'] ?? null);

        return back()->with(
            'sucesso',
            'Enviado para conferencia. O prazo continua aberto ate o titular confirmar.'
        );
    }

    public function responderConferencia(Request $request, Prazo $prazo, PrazoRepository $prazos): RedirectResponse
    {
        $this->autorizar($prazo);
        abort_unless(contexto()->pode('prazo.cumprir'), 403);

        $dados = $request->validate([
            'aprovado' => ['required', 'boolean'],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ]);

        $prazos->responderConferencia(
            $prazo,
            $request->user(),
            (bool) $dados['aprovado'],
            $dados['observacao'] ?? null
        );

        return back()->with('sucesso', $dados['aprovado']
            ? 'Prazo confirmado e fechado.'
            : 'Devolvido para ajuste. O prazo segue aberto.');
    }

    public function definirResponsavel(Request $request, Prazo $prazo): RedirectResponse
    {
        $this->autorizar($prazo);
        abort_unless(contexto()->pode('prazo.assumir'), 403);

        $dados = $request->validate([
            'responsavel_id' => ['nullable', 'integer'],
        ]);

        $alvo = $dados['responsavel_id'] ?? null;

        // Estagiario so assume para si; nao distribui trabalho para os outros.
        if (contexto()->naoPode('prazo.cumprir') && $alvo !== null && $alvo !== $request->user()->id) {
            abort(403);
        }

        abort_unless($alvo === null || $this->naEquipe($alvo), 403);

        $prazo->forceFill(['responsavel_id' => $alvo])->save();

        return back()->with('sucesso', $alvo ? 'Responsavel definido.' : 'Responsavel removido.');
    }

    /**
     * Dono do espaco + recorte por caso, numa checagem so.
     */
    private function autorizar(Prazo $prazo): void
    {
        abort_unless($prazo->advogado_id === contexto()->advogadoId(), 403);
        abort_unless(contexto()->podeVerProcesso($prazo->processo_id), 403);
    }

    private function naEquipe(int $usuarioId): bool
    {
        if ($usuarioId === contexto()->advogadoId()) {
            return true;
        }

        return Membro::where('titular_id', contexto()->advogadoId())
            ->where('usuario_id', $usuarioId)
            ->where('ativo', true)
            ->exists();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function equipeDisponivel(): array
    {
        $titular = contexto()->advogado();

        $lista = [[
            'id' => $titular->id,
            'nome' => $titular->name,
            'papel' => 'titular',
        ]];

        foreach (Membro::with('usuario')
            ->where('titular_id', $titular->id)
            ->where('ativo', true)
            ->whereNotNull('usuario_id')
            ->get() as $membro) {
            $lista[] = [
                'id' => $membro->usuario_id,
                'nome' => $membro->usuario?->name ?? $membro->nome,
                'papel' => $membro->papel,
            ];
        }

        return $lista;
    }

    /**
     * @return array<string, mixed>
     */
    private function paraLista(Prazo $prazo): array
    {
        return [
            'id' => $prazo->id,
            'tipo' => $prazo->tipo,
            'status' => $prazo->status,
            'data_fatal' => CarbonImmutable::parse($prazo->data_fatal)->toDateString(),
            'data_alvo' => CarbonImmutable::parse($prazo->data_alvo)->toDateString(),
            'dias_restantes' => $prazo->dias_restantes,
            'criticidade' => $prazo->criticidade,
            'ajustado_manualmente' => (bool) $prazo->ajustado_manualmente,
            'precisa_revisao' => (bool) $prazo->precisa_revisao,
            'revisao_motivo' => $prazo->revisao_motivo,
            'aguardando_conferencia' => (bool) $prazo->aguardando_conferencia,
            'conferencia_observacao' => $prazo->conferencia_observacao,
            'conferencia_por' => $prazo->relationLoaded('solicitanteDaConferencia')
                ? $prazo->solicitanteDaConferencia?->name
                : null,
            'responsavel' => $prazo->relationLoaded('responsavel')
                ? ($prazo->responsavel ? ['id' => $prazo->responsavel->id, 'nome' => $prazo->responsavel->name] : null)
                : null,
            'processo' => $prazo->processo ? [
                'id' => $prazo->processo->id,
                'rotulo' => $prazo->processo->rotulo,
                'cliente' => $prazo->processo->cliente?->nome,
            ] : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function processosDisponiveis(Request $request): array
    {
        return Processo::visivel()
            ->whereNull('arquivado_em')
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get(['id', 'titulo', 'numero_cnj'])
            ->map(fn (Processo $p) => ['id' => $p->id, 'rotulo' => $p->rotulo])
            ->all();
    }
}
