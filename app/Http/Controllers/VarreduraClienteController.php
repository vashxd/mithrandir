<?php

namespace App\Http\Controllers;

use App\Models\OabWatch;
use App\Services\Djen\ConsultaDjen;
use App\Services\Djen\DjenClient;
use App\Services\Djen\IngestaoService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Varredura do DJEN executada pelo navegador do advogado.
 *
 * Motivo: a API de comunicacoes do CNJ responde 403 para IP estrangeiro. Onde
 * o servidor esta fora do Brasil, ele nao alcanca o DJEN - mas o navegador de
 * quem usa o app alcanca, e a API libera CORS para qualquer origem.
 *
 * Divisao de responsabilidade: o servidor decide *o que* buscar (quais termos,
 * qual janela) e valida *tudo* o que volta; o cliente so faz a requisicao HTTP
 * que o servidor nao consegue fazer. O navegador nunca escolhe a janela nem
 * interpreta campo - senao ele viraria a fonte da verdade de contagem de prazo.
 */
class VarreduraClienteController extends Controller
{
    public function __construct(private readonly IngestaoService $ingestao) {}

    /**
     * O que o navegador deve buscar agora.
     */
    public function plano(Request $request, DjenClient $djen): JsonResponse
    {
        abort_unless(contexto()->pode('publicacao.ver'), 403);

        if (! config('mithrandir.djen.varredura_cliente')) {
            return response()->json(['ativa' => false, 'consultas' => []]);
        }

        $intervalo = (int) config('mithrandir.djen.varredura_cliente_intervalo_min', 60);
        // O botao "sincronizar agora" ignora o intervalo; a varredura de
        // abertura de tela, nao.
        $limite = now()->subMinutes($intervalo);
        $forcar = $request->boolean('forcar');

        $consultas = [];

        $watches = contexto()->advogado()
            ->watches()
            ->where('ativo', true)
            ->with('advogado:id,uf')
            ->get();

        foreach ($watches as $watch) {
            if (! $forcar && $watch->ultima_sync_em?->greaterThan($limite)) {
                continue;
            }

            [$inicio, $fim] = $this->ingestao->janela($watch);

            $consultas[] = [
                'watch_id' => $watch->id,
                'rotulo' => $watch->rotulo(),
                'parametros' => ConsultaDjen::paraWatch($watch, $inicio, $fim),
                'janela_inicio' => $inicio->toDateString(),
                'janela_fim' => $fim->toDateString(),
            ];
        }

        return response()->json([
            'ativa' => true,
            'url' => $djen->baseUrl().ConsultaDjen::CAMINHO,
            'itens_por_pagina' => (int) config('mithrandir.djen.itens_por_pagina', 100),
            'max_paginas' => (int) config('mithrandir.djen.max_paginas', 20),
            'consultas' => $consultas,
        ]);
    }

    /**
     * Resultado de uma consulta: ou os itens crus, ou o erro que impediu.
     */
    public function receber(Request $request): JsonResponse
    {
        abort_unless(contexto()->pode('publicacao.ver'), 403);
        abort_unless((bool) config('mithrandir.djen.varredura_cliente'), 404);

        $teto = (int) config('mithrandir.djen.max_paginas', 20)
            * (int) config('mithrandir.djen.itens_por_pagina', 100);

        $dados = $request->validate([
            'watch_id' => ['required', 'integer'],
            'janela_inicio' => ['required', 'date_format:Y-m-d'],
            'janela_fim' => ['required', 'date_format:Y-m-d'],
            'itens' => ['nullable', 'array', 'max:'.$teto],
            'itens.*' => ['array'],
            'erro' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var OabWatch $watch */
        $watch = contexto()->advogado()
            ->watches()
            ->where('ativo', true)
            ->findOrFail($dados['watch_id']);

        $tz = config('mithrandir.timezone');
        $inicio = CarbonImmutable::parse($dados['janela_inicio'], $tz)->startOfDay();
        $fim = CarbonImmutable::parse($dados['janela_fim'], $tz)->startOfDay();

        if ($falta = $this->janelaInsuficiente($watch, $inicio, $fim)) {
            // Registrar como falha, e nao aceitar calado, e o ponto do
            // controle: uma janela encolhida deixaria o log dizendo "varri"
            // sobre dias que ninguem olhou.
            $this->ingestao->registrarFalhaDoCliente($watch, $falta, $inicio, $fim);

            return response()->json(['erro' => $falta], 422);
        }

        if (isset($dados['erro'])) {
            $this->ingestao->registrarFalhaDoCliente(
                $watch,
                'Varredura pelo navegador: '.$dados['erro'],
                $inicio,
                $fim,
            );

            return response()->json(['novas' => 0, 'total' => 0, 'falhou' => true]);
        }

        $resultado = $this->ingestao->ingerirDoCliente($watch, $dados['itens'] ?? [], $inicio, $fim);

        return response()->json($resultado + ['falhou' => false]);
    }

    /**
     * A janela que o cliente diz ter coberto precisa conter a que o servidor
     * teria usado. Mais larga tudo bem; mais estreita, nao.
     */
    private function janelaInsuficiente(
        OabWatch $watch,
        CarbonImmutable $inicio,
        CarbonImmutable $fim,
    ): ?string {
        [$esperadoInicio, $esperadoFim] = $this->ingestao->janela($watch);

        if ($inicio->greaterThan($esperadoInicio) || $fim->lessThan($esperadoFim)) {
            return sprintf(
                'Janela recebida (%s a %s) nao cobre a exigida (%s a %s).',
                $inicio->toDateString(),
                $fim->toDateString(),
                $esperadoInicio->toDateString(),
                $esperadoFim->toDateString(),
            );
        }

        return null;
    }
}
