<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Despesa;
use App\Models\Honorario;
use App\Models\Parcela;
use App\Models\Processo;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tela 6. "Quanto tenho a receber?"
 */
class FinanceiroController extends Controller
{
    public function index(Request $request): Response
    {
        $advogado = contexto()->advogado();
        $tz = $advogado->timezone ?: config('mithrandir.timezone');

        $referencia = $request->filled('mes')
            ? CarbonImmutable::parse($request->string('mes')->toString().'-01', $tz)
            : CarbonImmutable::today($tz);

        $inicio = $referencia->startOfMonth();
        $fim = $referencia->endOfMonth();

        $doMes = Parcela::with('honorario.cliente', 'honorario.processo')
            ->doAdvogado(contexto()->advogadoId())
            ->whereBetween('vencimento', [$inicio->toDateString(), $fim->toDateString()])
            ->orderBy('vencimento')
            ->get();

        $recebidasNoMes = Parcela::doAdvogado(contexto()->advogadoId())
            ->whereBetween('pago_em', [$inicio->toDateString(), $fim->toDateString()])
            ->get();

        $vencidas = Parcela::with('honorario.cliente', 'honorario.processo')
            ->doAdvogado(contexto()->advogadoId())
            ->vencidas()
            ->orderBy('vencimento')
            ->get();

        $recebidoMes = (float) $recebidasNoMes->sum(fn (Parcela $p) => $p->valor_pago ?? $p->valor);

        return Inertia::render('Financeiro/Index', [
            'mes' => $referencia->format('Y-m'),
            'painel' => [
                // RF-7.3
                'a_receber_mes' => (float) $doMes->whereNull('pago_em')->sum('valor'),
                'vencido' => (float) $vencidas->sum('valor'),
                'vencido_qtd' => $vencidas->count(),
                'recebido_mes' => $recebidoMes,
                'total_contratado' => (float) Honorario::doAdvogado(contexto()->advogadoId())->sum('valor'),
                // RF-7.5: provisao sugerida sobre o que entrou.
                'provisao_imposto' => round($recebidoMes * ((float) $advogado->percentual_imposto) / 100, 2),
                'percentual_imposto' => (float) $advogado->percentual_imposto,
                'despesas_mes' => (float) Despesa::doAdvogado(contexto()->advogadoId())
                    ->whereBetween('data', [$inicio->toDateString(), $fim->toDateString()])
                    ->sum('valor'),
            ],
            'parcelas_mes' => $doMes->map(fn (Parcela $p) => $this->paraLista($p)),
            'vencidas' => $vencidas->map(fn (Parcela $p) => $this->paraLista($p)),
            'despesas' => Despesa::with('processo')
                ->doAdvogado(contexto()->advogadoId())
                ->whereBetween('data', [$inicio->toDateString(), $fim->toDateString()])
                ->orderByDesc('data')
                ->get()
                ->map(fn (Despesa $d) => [
                    'id' => $d->id,
                    'descricao' => $d->descricao,
                    'categoria' => $d->categoria,
                    'valor' => (float) $d->valor,
                    'data' => CarbonImmutable::parse($d->data)->toDateString(),
                    'reembolsavel' => (bool) $d->reembolsavel,
                    'reembolsada' => $d->reembolsada_em !== null,
                    'processo' => $d->processo?->rotulo,
                ]),
            'processos' => Processo::doAdvogado(contexto()->advogadoId())
                ->whereNull('arquivado_em')
                ->orderBy('titulo')
                ->get(['id', 'titulo', 'numero_cnj', 'cliente_id'])
                ->map(fn (Processo $p) => [
                    'id' => $p->id, 'rotulo' => $p->rotulo, 'cliente_id' => $p->cliente_id,
                ]),
            'clientes' => Cliente::doAdvogado(contexto()->advogadoId())->orderBy('nome')->get(['id', 'nome']),
        ]);
    }

    /**
     * RF-7.1 e RF-7.2: contrato e geracao das parcelas.
     */
    public function storeHonorario(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'processo_id' => ['nullable', 'integer', 'exists:processos,id'],
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
            'tipo' => ['required', 'in:fixo,parcelado,exito,misto'],
            'valor' => ['required', 'numeric', 'min:0'],
            'percentual_exito' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'qtd_parcelas' => ['required', 'integer', 'min:1', 'max:60'],
            'primeiro_vencimento' => ['nullable', 'date', 'required_unless:tipo,exito'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
        ], [], ['qtd_parcelas' => 'quantidade de parcelas']);

        DB::transaction(function () use ($dados) {
            $honorario = Honorario::create($dados + ['advogado_id' => contexto()->advogadoId()]);

            // Honorario so de exito nao gera parcela: nao ha data nem valor certo.
            if ($honorario->tipo === 'exito' || ! $dados['primeiro_vencimento']) {
                return;
            }

            $this->gerarParcelas($honorario);
        });

        return back()->with('sucesso', 'Honorario registrado.');
    }

    private function gerarParcelas(Honorario $honorario): void
    {
        $quantidade = max(1, (int) $honorario->qtd_parcelas);
        $total = (float) $honorario->valor;
        $base = round($total / $quantidade, 2);
        $primeiro = CarbonImmutable::parse($honorario->primeiro_vencimento);

        for ($numero = 1; $numero <= $quantidade; $numero++) {
            // A ultima parcela absorve o centavo da divisao.
            $valor = $numero === $quantidade
                ? round($total - $base * ($quantidade - 1), 2)
                : $base;

            Parcela::create([
                'advogado_id' => $honorario->advogado_id,
                'honorario_id' => $honorario->id,
                'numero' => $numero,
                'valor' => $valor,
                'vencimento' => $primeiro->addMonths($numero - 1),
            ]);
        }
    }

    /**
     * RF-7.2: baixa manual.
     */
    public function baixarParcela(Request $request, Parcela $parcela): RedirectResponse
    {
        abort_unless($parcela->advogado_id === contexto()->advogadoId(), 403);

        $dados = $request->validate([
            'pago_em' => ['nullable', 'date'],
            'valor_pago' => ['nullable', 'numeric', 'min:0'],
            'forma_pagamento' => ['nullable', 'string', 'max:30'],
            'estornar' => ['boolean'],
        ]);

        if ($dados['estornar'] ?? false) {
            $parcela->forceFill([
                'pago_em' => null, 'valor_pago' => null, 'forma_pagamento' => null,
            ])->save();

            return back()->with('sucesso', 'Baixa estornada.');
        }

        $parcela->forceFill([
            'pago_em' => $dados['pago_em'] ?? now()->toDateString(),
            'valor_pago' => $dados['valor_pago'] ?? $parcela->valor,
            'forma_pagamento' => $dados['forma_pagamento'] ?? null,
        ])->save();

        return back()->with('sucesso', 'Parcela baixada.');
    }

    /**
     * RF-7.4: despesa por caso, marcada como reembolsavel.
     */
    public function storeDespesa(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'processo_id' => ['nullable', 'integer', 'exists:processos,id'],
            'descricao' => ['required', 'string', 'max:255'],
            'categoria' => ['nullable', 'in:custas,copias,deslocamento,pericia,correio,outro'],
            'valor' => ['required', 'numeric', 'min:0'],
            'data' => ['required', 'date'],
            'reembolsavel' => ['boolean'],
        ]);

        Despesa::create($dados + ['advogado_id' => contexto()->advogadoId()]);

        return back()->with('sucesso', 'Despesa lancada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function paraLista(Parcela $parcela): array
    {
        return [
            'id' => $parcela->id,
            'numero' => $parcela->numero,
            'valor' => (float) $parcela->valor,
            'valor_pago' => $parcela->valor_pago ? (float) $parcela->valor_pago : null,
            'vencimento' => CarbonImmutable::parse($parcela->vencimento)->toDateString(),
            'pago_em' => $parcela->pago_em ? CarbonImmutable::parse($parcela->pago_em)->toDateString() : null,
            'situacao' => $parcela->situacao,
            'cliente' => $parcela->honorario?->cliente?->nome,
            'processo' => $parcela->honorario?->processo?->rotulo,
            'processo_id' => $parcela->honorario?->processo_id,
        ];
    }
}
