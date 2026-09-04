<?php

namespace App\Http\Controllers;

use App\Models\Atendimento;
use App\Models\Cliente;
use App\Models\OabWatch;
use App\Models\Prazo;
use App\Models\Processo;
use App\Models\Publicacao;
use App\Services\Djen\DjenClient;
use App\Services\Djen\DjenIndisponivelException;
use App\Support\Documento;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tela 5. "Quem e essa pessoa e o que temos com ela?"
 */
class ClienteController extends Controller
{
    public function index(Request $request): Response
    {
        $busca = $request->string('busca')->toString();

        $clientes = Cliente::withCount(['processos as processos_ativos' => fn ($q) => $q->whereNull('arquivado_em')])
            ->visivel()
            ->when(! $request->boolean('arquivados'), fn ($q) => $q->whereNull('arquivado_em'))
            ->when($busca, fn ($q) => $q->where(function ($sub) use ($busca) {
                $sub->where('nome', 'like', "%{$busca}%")
                    ->orWhere('documento', 'like', '%'.preg_replace('/\D/', '', $busca).'%');
            }))
            ->orderBy('nome')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Cliente $c) => [
                'id' => $c->id,
                'nome' => $c->nome,
                'documento' => $c->documento,
                'telefone' => $c->telefonePrincipal(),
                'origem' => $c->origem,
                'processos_ativos' => $c->processos_ativos,
            ]);

        return Inertia::render('Clientes/Index', [
            'clientes' => $clientes,
            'filtros' => ['busca' => $busca, 'arquivados' => $request->boolean('arquivados')],
        ]);
    }

    public function show(Request $request, Cliente $cliente): Response
    {
        $this->autorizar($cliente);

        $cliente->load('atendimentos.processo');

        return Inertia::render('Clientes/Show', [
            'cliente' => [
                'id' => $cliente->id,
                'nome' => $cliente->nome,
                'documento' => $cliente->documento,
                'nascimento' => $cliente->nascimento ? CarbonImmutable::parse($cliente->nascimento)->toDateString() : null,
                'contatos' => $cliente->contatos ?? [],
                'endereco' => $cliente->endereco ?? [],
                'origem' => $cliente->origem,
                'documento_formatado' => Documento::formatar($cliente->documento),
                'documento_tipo' => Documento::tipo($cliente->documento),
                'observacoes' => $cliente->observacoes,
            ],
            'vigilancia' => $this->estadoDaVigilancia($request, $cliente),
            'publicacoes' => Publicacao::visivel()
                ->where('cliente_id', $cliente->id)
                ->orderByDesc('data_disponibilizacao')
                ->limit(20)
                ->get()
                ->map(fn (Publicacao $p) => [
                    'id' => $p->id,
                    'tribunal' => $p->tribunal,
                    'data_disponibilizacao' => CarbonImmutable::parse($p->data_disponibilizacao)->toDateString(),
                    'status_triagem' => $p->status_triagem,
                    'resumo' => $p->resumo(160),
                ]),
            'processos' => $cliente->processos()->orderByDesc('updated_at')->get()->map(fn (Processo $p) => [
                'id' => $p->id,
                'rotulo' => $p->rotulo,
                'fase' => $p->fase,
                'proxima_acao' => $p->proxima_acao,
                'arquivado' => $p->arquivado_em !== null,
            ]),
            'atendimentos' => $cliente->atendimentos->map(fn (Atendimento $a) => [
                'id' => $a->id,
                'data' => CarbonImmutable::parse($a->data)->toDateString(),
                'canal' => $a->canal,
                'resumo' => $a->resumo,
                'processo' => $a->processo?->rotulo,
            ]),
        ]);
    }

    /**
     * O cliente e do espaco, e o colaborador so o ve se acompanha um caso dele.
     */
    private function autorizar(Cliente $cliente): void
    {
        abort_unless($cliente->advogado_id === contexto()->advogadoId(), 403);

        if (! contexto()->temRecortePorCaso()) {
            return;
        }

        $permitidos = contexto()->processosVisiveis() ?? [];

        abort_unless(
            $cliente->processos()->whereIn('processos.id', $permitidos)->exists(),
            403
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function estadoDaVigilancia(Request $request, Cliente $cliente): array
    {
        $watch = OabWatch::withCount('publicacoes')
            ->where('advogado_id', contexto()->advogadoId())
            ->where('cliente_id', $cliente->id)
            ->where('tipo', 'cliente')
            ->first();

        return [
            'ativa' => (bool) $watch?->ativo,
            'existe' => $watch !== null,
            'termo' => $watch?->termo,
            'ultima_sync_em' => $watch?->ultima_sync_em?->toIso8601String(),
            'publicacoes' => $watch?->publicacoes_count ?? 0,
            'cego' => (bool) $watch?->estaCego(),
            // O DJEN nao filtra por CPF/CNPJ; a vigilancia so sabe buscar nome.
            'busca_por' => 'nome',
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $cliente = Cliente::create($this->validar($request) + ['advogado_id' => contexto()->advogadoId()]);

        return redirect()->route('clientes.show', $cliente)->with('sucesso', 'Cliente cadastrado.');
    }

    public function update(Request $request, Cliente $cliente): RedirectResponse
    {
        $this->autorizar($cliente);

        $cliente->update($this->validar($request));

        return back()->with('sucesso', 'Cliente atualizado.');
    }

    /**
     * RF-5.2: registro de atendimentos com data e resumo.
     */
    public function registrarAtendimento(Request $request, Cliente $cliente): RedirectResponse
    {
        $this->autorizar($cliente);

        $dados = $request->validate([
            'data' => ['required', 'date'],
            'canal' => ['required', 'in:presencial,telefone,whatsapp,email,video'],
            'resumo' => ['required', 'string', 'max:4000'],
            'processo_id' => ['nullable', 'integer', 'exists:processos,id'],
        ]);

        Atendimento::create($dados + [
            'advogado_id' => contexto()->advogadoId(),
            'cliente_id' => $cliente->id,
        ]);

        return back()->with('sucesso', 'Atendimento registrado.');
    }

    /**
     * RF-5.3: texto pronto para o WhatsApp com a situacao atual do caso.
     *
     * Resolve a D6 (cliente perguntando status o tempo todo) sem abrir portal
     * nem integrar API paga: a advogada copia e cola.
     */
    public function textoStatus(Request $request, Cliente $cliente, Processo $processo): JsonResponse
    {
        $this->autorizar($cliente);
        abort_unless($processo->advogado_id === contexto()->advogadoId(), 403);

        $primeiroNome = explode(' ', trim($cliente->nome))[0];
        $linhas = ["Ola, {$primeiroNome}! Atualizacao do seu processo:"];

        if ($processo->numero_formatado) {
            $linhas[] = '';
            $linhas[] = "Processo: {$processo->numero_formatado}";
        }

        if ($processo->vara) {
            $linhas[] = "Vara: {$processo->vara}";
        }

        $ultima = $processo->movimentacoes()->first();

        if ($ultima) {
            $linhas[] = '';
            $linhas[] = 'Ultima movimentacao ('.CarbonImmutable::parse($ultima->data)->format('d/m/Y').'): '
                .$ultima->descricao;
        }

        $prazo = Prazo::where('processo_id', $processo->id)->abertos()->orderBy('data_fatal')->first();

        if ($prazo) {
            $linhas[] = '';
            $linhas[] = 'Estamos dentro do prazo para '.mb_strtolower($prazo->tipo)
                .', que vai ate '.CarbonImmutable::parse($prazo->data_fatal)->format('d/m/Y').'.';
        }

        if ($processo->proxima_acao) {
            $linhas[] = '';
            $linhas[] = 'Proximo passo: '.$processo->proxima_acao;
        }

        $linhas[] = '';
        $linhas[] = 'Qualquer duvida, e so me chamar por aqui.';

        return response()->json(['texto' => implode("\n", $linhas)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request): array
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'documento' => ['nullable', 'string', 'max:20'],
            'nascimento' => ['nullable', 'date'],
            'contatos' => ['nullable', 'array'],
            'contatos.*.tipo' => ['required_with:contatos', 'in:telefone,whatsapp,email,outro'],
            'contatos.*.valor' => ['required_with:contatos', 'string', 'max:255'],
            'endereco' => ['nullable', 'array'],
            'endereco.logradouro' => ['nullable', 'string', 'max:255'],
            'endereco.numero' => ['nullable', 'string', 'max:20'],
            'endereco.bairro' => ['nullable', 'string', 'max:120'],
            'endereco.cidade' => ['nullable', 'string', 'max:120'],
            'endereco.uf' => ['nullable', 'string', 'size:2'],
            'endereco.cep' => ['nullable', 'string', 'max:12'],
            'origem' => ['nullable', 'string', 'max:120'],
            'observacoes' => ['nullable', 'string', 'max:4000'],
        ]);

        // "123.456.789-00" e "12345678900" viram o mesmo registro.
        if (! empty($dados['documento'])) {
            $dados['documento'] = Documento::limpar($dados['documento']);
        }

        return $dados;
    }

    /* ---------------- Vigilancia do cliente no DJEN ---------------- */

    /**
     * Quantas publicacoes o nome deste cliente traria, antes de ligar a
     * vigilancia. Nome comum entope a triagem, e e melhor a pessoa ver o
     * numero e decidir do que descobrir depois com o inbox afogado.
     */
    public function previaVigilancia(Request $request, Cliente $cliente, DjenClient $djen): JsonResponse
    {
        $this->autorizar($cliente);

        $teto = (int) config('mithrandir.djen.teto_por_varredura', 300);

        try {
            $quantidade = $djen->contarPorNomeParte($cliente->nome, 30);
        } catch (DjenIndisponivelException $e) {
            return response()->json(['erro' => $e->getMessage()], 503);
        }

        return response()->json([
            'nome' => $cliente->nome,
            'quantidade' => $quantidade,
            'teto' => $teto,
            'recomendado' => $quantidade > 0 && $quantidade <= $teto,
            'mensagem' => match (true) {
                $quantidade === 0 => 'Nenhuma publicacao com este nome nos ultimos 30 dias. '
                    .'Pode ligar mesmo assim: a vigilancia passa a valer de agora em diante.',
                $quantidade > $teto => sprintf(
                    'Este nome traz %d publicacoes em 30 dias, acima do teto de %d. '
                    .'E nome comum demais: a vigilancia traria mais ruido que sinal.',
                    $quantidade,
                    $teto
                ),
                default => sprintf(
                    '%d publicacao(oes) em 30 dias. Volume saudavel para vigiar. '
                    .'Lembre que homonimo existe: tudo passa pela sua triagem.',
                    $quantidade
                ),
            },
        ]);
    }

    /**
     * Liga ou desliga a vigilancia do nome do cliente no DJEN.
     *
     * Nasce desligada de proposito. O DJEN nao permite filtrar por CPF/CNPJ
     * (parametro ignorado, devolve o diario inteiro), entao a unica chave e o
     * nome - e nome nao desambigua homonimo.
     */
    public function alternarVigilancia(Request $request, Cliente $cliente): RedirectResponse
    {
        $this->autorizar($cliente);

        $watch = OabWatch::where('advogado_id', $request->user()->id)
            ->where('cliente_id', $cliente->id)
            ->where('tipo', 'cliente')
            ->first();

        if ($watch) {
            $watch->forceFill(['ativo' => ! $watch->ativo, 'falhas_consecutivas' => 0])->save();

            return back()->with('sucesso', $watch->ativo
                ? "Vigilancia ligada para {$cliente->nome}."
                : 'Vigilancia desligada. As publicacoes ja capturadas foram mantidas.');
        }

        OabWatch::create([
            'advogado_id' => contexto()->advogadoId(),
            'cliente_id' => $cliente->id,
            'termo' => $cliente->nome,
            'tipo' => 'cliente',
            'uf' => $request->user()->uf,
            'ativo' => true,
        ]);

        return back()->with(
            'sucesso',
            "Vigilancia ligada para {$cliente->nome}. A proxima varredura busca os ultimos 30 dias."
        );
    }
}
