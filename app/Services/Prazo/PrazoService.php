<?php

namespace App\Services\Prazo;

use Carbon\CarbonImmutable;

/**
 * Motor de prazos (M2).
 *
 * Funcao pura: recebe a entrada e o calendario, devolve o resultado. Nao toca
 * em banco, cache, request nem relogio do sistema (exceto o carimbo de
 * "calculado_em" no resultado). Isso e o que torna a suite da secao 8.3
 * executavel sem nenhuma infraestrutura.
 *
 * Cadeia (secao 8.1):
 *   disponibilizacao
 *     -> proximo dia util .............. publicacao        (CPC art. 224, §2)
 *     -> proximo dia util .............. inicio_contagem   (CPC art. 224, §3)
 *     -> + N dias .......................data_fatal        (CPC art. 219 / 220)
 *     -> - buffer ...................... data_alvo
 */
final class PrazoService
{
    public function calcular(PrazoInput $entrada, CalendarioFeriados $calendario): PrazoCalculado
    {
        // Prazo em dias corridos nao sofre a suspensao do art. 220 (regime do CPC).
        $calendario = $entrada->emDiasUteis ? $calendario : $calendario->semRecessoForense();

        $passos = [];
        $disponibilizacao = $entrada->dataDisponibilizacao;

        $passos[] = [
            'etapa' => 'disponibilizacao',
            'data' => $disponibilizacao->toDateString(),
            'regra' => 'Data de disponibilizacao no Diario (origem: DJEN ou lancamento manual).',
            'base_legal' => null,
        ];

        if ($entrada->inicioForcado instanceof CarbonImmutable) {
            $publicacao = $disponibilizacao;
            $inicio = $entrada->inicioForcado->startOfDay();

            $passos[] = [
                'etapa' => 'inicio_contagem',
                'data' => $inicio->toDateString(),
                'regra' => 'Inicio informado manualmente (intimacao pessoal, carga ou vista dos autos).',
                'base_legal' => 'CPC art. 231',
            ];
        } else {
            $publicacao = $calendario->proximoDiaDeContagem($disponibilizacao);

            $passos[] = [
                'etapa' => 'publicacao',
                'data' => $publicacao->toDateString(),
                'regra' => 'Considera-se publicada no primeiro dia util seguinte ao da disponibilizacao.',
                'base_legal' => 'CPC art. 224, §2',
            ];

            $inicio = $calendario->proximoDiaDeContagem($publicacao);

            $passos[] = [
                'etapa' => 'inicio_contagem',
                'data' => $inicio->toDateString(),
                'regra' => 'Exclui-se o dia da publicacao; a contagem comeca no primeiro dia util seguinte.',
                'base_legal' => 'CPC art. 224, §3',
            ];
        }

        $diasEfetivos = $entrada->diasEfetivos();

        if ($entrada->multiplicador > 1) {
            $passos[] = [
                'etapa' => 'multiplicador',
                'data' => $inicio->toDateString(),
                'regra' => sprintf(
                    'Prazo em dobro aplicado: %d x %d = %d dias. Confirmacao humana obrigatoria.',
                    $entrada->dias,
                    $entrada->multiplicador,
                    $diasEfetivos
                ),
                'base_legal' => 'CPC arts. 180, 183, 186',
            ];
        }

        [$fatalBruto, $diasNaoContados] = $entrada->emDiasUteis
            ? $this->contarDiasUteis($inicio, $diasEfetivos, $calendario)
            : $this->contarDiasCorridos($inicio, $diasEfetivos, $calendario);

        $passos[] = [
            'etapa' => 'vencimento',
            'data' => $fatalBruto->toDateString(),
            'regra' => sprintf(
                '%d dias %s contados a partir do inicio, incluindo o dia do vencimento.',
                $diasEfetivos,
                $entrada->emDiasUteis ? 'uteis' : 'corridos'
            ),
            'base_legal' => $entrada->emDiasUteis ? 'CPC art. 219' : 'Contagem em dias corridos',
        ];

        // Prorrogacao: vencimento em dia sem expediente forense cai no proximo util.
        $fatal = $fatalBruto;
        $prorrogado = false;

        while (! $this->ehDiaDeVencimento($fatal, $calendario, $entrada->emDiasUteis)) {
            $fatal = $fatal->addDay();
            $prorrogado = true;
        }

        if ($prorrogado) {
            $passos[] = [
                'etapa' => 'prorrogacao',
                'data' => $fatal->toDateString(),
                'regra' => sprintf(
                    'Vencimento em %s caiu em dia sem expediente (%s); prorrogado para o primeiro dia util seguinte.',
                    $fatalBruto->format('d/m/Y'),
                    $calendario->motivo($fatalBruto) ?? 'sem expediente'
                ),
                'base_legal' => 'CPC art. 224, §1',
            ];
        }

        $passos[] = [
            'etapa' => 'data_fatal',
            'data' => $fatal->toDateString(),
            'regra' => 'Data fatal do prazo. Conferir sempre no diario oficial.',
            'base_legal' => null,
        ];

        $alvo = $this->recuarDiasUteis($fatal, $entrada->bufferDias, $calendario, $inicio);

        $passos[] = [
            'etapa' => 'data_alvo',
            'data' => $alvo->toDateString(),
            'regra' => sprintf(
                'Data-alvo interna = data fatal menos %d dia(s) util(eis) de folga. E esta a data que aparece na agenda.',
                $entrada->bufferDias
            ),
            'base_legal' => null,
        ];

        return new PrazoCalculado(
            dataDisponibilizacao: $disponibilizacao,
            dataPublicacao: $publicacao,
            dataInicio: $inicio,
            dataFatal: $fatal,
            dataAlvo: $alvo,
            diasEfetivos: $diasEfetivos,
            emDiasUteis: $entrada->emDiasUteis,
            multiplicador: $entrada->multiplicador,
            bufferDias: $entrada->bufferDias,
            passos: $passos,
            diasNaoContados: $diasNaoContados,
            prorrogado: $prorrogado,
        );
    }

    /**
     * O dia de inicio ja e o primeiro dia contado.
     *
     * @return array{0: CarbonImmutable, 1: array<int, array{data: string, motivo: string}>}
     */
    private function contarDiasUteis(CarbonImmutable $inicio, int $dias, CalendarioFeriados $calendario): array
    {
        $cursor = $inicio;

        // O inicio vem de proximoDiaDeContagem, mas um inicio forcado pode cair
        // em dia nao util - nesse caso o primeiro dia contado e o seguinte.
        while (! $calendario->ehDiaDeContagem($cursor)) {
            $cursor = $cursor->addDay();
        }

        $contados = 1;
        $naoContados = [];

        while ($contados < $dias) {
            $cursor = $cursor->addDay();

            if ($calendario->ehDiaDeContagem($cursor)) {
                $contados++;
            } else {
                $naoContados[] = [
                    'data' => $cursor->toDateString(),
                    'motivo' => $calendario->motivo($cursor) ?? 'dia nao util',
                ];
            }
        }

        return [$cursor, $this->resumirNaoContados($naoContados)];
    }

    /**
     * @return array{0: CarbonImmutable, 1: array<int, array{data: string, motivo: string}>}
     */
    private function contarDiasCorridos(CarbonImmutable $inicio, int $dias, CalendarioFeriados $calendario): array
    {
        return [$inicio->addDays($dias - 1), []];
    }

    /**
     * Colapsa sequencias de dias parados com o mesmo motivo em uma linha so.
     * Sem isso o recesso forense sozinho geraria 30 entradas na cadeia de origem.
     *
     * @param  array<int, array{data: string, motivo: string}>  $dias
     * @return array<int, array{data: string, motivo: string}>
     */
    private function resumirNaoContados(array $dias): array
    {
        $resumo = [];

        foreach ($dias as $dia) {
            $ultimo = count($resumo) - 1;

            if ($ultimo >= 0 && $resumo[$ultimo]['motivo'] === $dia['motivo']
                && CarbonImmutable::parse($resumo[$ultimo]['ate'])->addDay()->toDateString() === $dia['data']) {
                $resumo[$ultimo]['ate'] = $dia['data'];

                continue;
            }

            $resumo[] = ['data' => $dia['data'], 'ate' => $dia['data'], 'motivo' => $dia['motivo']];
        }

        return array_map(function (array $bloco) {
            if ($bloco['data'] === $bloco['ate']) {
                return ['data' => $bloco['data'], 'motivo' => $bloco['motivo']];
            }

            return [
                'data' => $bloco['data'],
                'motivo' => sprintf(
                    '%s (ate %s)',
                    $bloco['motivo'],
                    CarbonImmutable::parse($bloco['ate'])->format('d/m/Y')
                ),
            ];
        }, $resumo);
    }

    private function ehDiaDeVencimento(CarbonImmutable $dia, CalendarioFeriados $calendario, bool $emDiasUteis): bool
    {
        return $emDiasUteis
            ? $calendario->ehDiaDeContagem($dia)
            : $calendario->ehDiaUtil($dia);
    }

    /**
     * Recua $dias dias uteis a partir da data fatal, sem nunca passar do inicio
     * da contagem (um buffer maior que o proprio prazo nao pode gerar data no passado).
     */
    private function recuarDiasUteis(
        CarbonImmutable $fatal,
        int $dias,
        CalendarioFeriados $calendario,
        CarbonImmutable $piso,
    ): CarbonImmutable {
        $cursor = $fatal;
        $recuados = 0;

        while ($recuados < $dias) {
            $candidato = $cursor->subDay();

            if ($candidato < $piso) {
                return $cursor;
            }

            $cursor = $candidato;

            if ($calendario->ehDiaDeContagem($cursor)) {
                $recuados++;
            }
        }

        return $cursor;
    }
}
