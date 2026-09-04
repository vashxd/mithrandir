<?php

namespace App\Services\Prazo;

use App\Models\Auditoria;
use App\Models\Evento;
use App\Models\Notificacao;
use App\Models\Prazo;
use App\Models\Processo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Camada com I/O em volta do PrazoService puro: persiste, agenda o evento na
 * timeline e registra auditoria.
 */
class PrazoRepository
{
    public function __construct(
        private readonly PrazoService $prazos,
        private readonly CalendarioService $calendario,
    ) {}

    /**
     * @param  array<string, mixed>  $dados
     */
    public function criar(User $advogado, array $dados): Prazo
    {
        $processo = isset($dados['processo_id'])
            ? Processo::doAdvogado($advogado->id)->find($dados['processo_id'])
            : null;

        $calendario = $this->calendario->para(
            tribunal: $processo?->tribunal,
            uf: $advogado->uf,
            advogadoId: $advogado->id,
        );

        $entrada = new PrazoInput(
            dataDisponibilizacao: $dados['data_disponibilizacao'],
            dias: (int) $dados['dias'],
            emDiasUteis: (bool) ($dados['em_dias_uteis'] ?? true),
            multiplicador: (int) ($dados['multiplicador'] ?? 1),
            bufferDias: (int) ($dados['buffer_dias'] ?? $advogado->buffer_padrao ?? 3),
            tipo: (string) ($dados['tipo'] ?? 'Prazo'),
            inicioForcado: isset($dados['data_inicio_forcada']) && $dados['data_inicio_forcada']
                ? CarbonImmutable::parse($dados['data_inicio_forcada'])
                : null,
        );

        $calculado = $this->prazos->calcular($entrada, $calendario);

        return DB::transaction(function () use ($advogado, $dados, $calculado, $entrada, $processo) {
            $prazo = Prazo::create([
                'advogado_id' => $advogado->id,
                'responsavel_id' => $dados['responsavel_id'] ?? null,
                'processo_id' => $processo?->id,
                'publicacao_id' => $dados['publicacao_id'] ?? null,
                'tipo_prazo_id' => $dados['tipo_prazo_id'] ?? null,
                'tipo' => $entrada->tipo,
                'dias' => $entrada->dias,
                'em_dias_uteis' => $entrada->emDiasUteis,
                'multiplicador' => $entrada->multiplicador,
                'multiplicador_motivo' => $dados['multiplicador_motivo'] ?? null,
                'data_disponibilizacao' => $calculado->dataDisponibilizacao,
                'data_publicacao' => $calculado->dataPublicacao,
                'data_inicio' => $calculado->dataInicio,
                'data_fatal' => $calculado->dataFatal,
                'data_alvo' => $calculado->dataAlvo,
                'buffer_dias' => $entrada->bufferDias,
                'cadeia_origem' => $calculado->cadeiaOrigem(),
                'status' => 'aberto',
                'observacoes' => $dados['observacoes'] ?? null,
            ]);

            $this->sincronizarEvento($prazo);

            Auditoria::registrar($advogado->id, 'prazos', $prazo->id, 'criado', null, $prazo->toArray());

            return $prazo;
        });
    }

    /**
     * RF-2.8: ajuste manual exige justificativa e guarda os dois valores.
     */
    public function ajustarManualmente(Prazo $prazo, string $novaDataFatal, string $justificativa): Prazo
    {
        $antes = $prazo->toArray();

        return DB::transaction(function () use ($prazo, $novaDataFatal, $justificativa, $antes) {
            $calendario = $this->calendario->para(
                tribunal: $prazo->processo?->tribunal,
                uf: $prazo->advogado?->uf,
                advogadoId: $prazo->advogado_id,
            );

            $fatal = CarbonImmutable::parse($novaDataFatal)->startOfDay();

            $prazo->data_fatal_calculada ??= $prazo->data_fatal;
            $prazo->data_fatal = $fatal;
            $prazo->data_alvo = $this->recuarBuffer($fatal, $prazo->buffer_dias, $calendario);
            $prazo->ajustado_manualmente = true;
            $prazo->justificativa = $justificativa;
            $prazo->precisa_revisao = false;
            $prazo->revisao_motivo = null;

            $cadeia = $prazo->cadeia_origem ?? [];
            $cadeia['ajuste_manual'] = [
                'de' => CarbonImmutable::parse($prazo->data_fatal_calculada)->toDateString(),
                'para' => $fatal->toDateString(),
                'justificativa' => $justificativa,
                'em' => now()->toIso8601String(),
            ];
            $prazo->cadeia_origem = $cadeia;

            $prazo->save();

            $this->sincronizarEvento($prazo);

            Auditoria::registrar(
                $prazo->advogado_id,
                'prazos',
                $prazo->id,
                'ajuste_manual',
                $antes,
                $prazo->toArray()
            );

            return $prazo;
        });
    }

    /**
     * RF-2.11: recalcula um prazo apos mudanca de calendario. Nao sobrescreve
     * prazo ajustado a mao - marca para revisao e deixa a decisao com a pessoa.
     *
     * @return bool true se a data mudou
     */
    public function recalcular(Prazo $prazo): bool
    {
        if (in_array($prazo->status, ['cumprido', 'perdido', 'prejudicado'], true)) {
            return false;
        }

        $calendario = $this->calendario->para(
            tribunal: $prazo->processo?->tribunal,
            uf: $prazo->advogado?->uf,
            advogadoId: $prazo->advogado_id,
        );

        $calculado = $this->prazos->calcular(
            new PrazoInput(
                dataDisponibilizacao: CarbonImmutable::parse($prazo->data_disponibilizacao),
                dias: $prazo->dias,
                emDiasUteis: (bool) $prazo->em_dias_uteis,
                multiplicador: $prazo->multiplicador,
                bufferDias: $prazo->buffer_dias,
                tipo: $prazo->tipo,
            ),
            $calendario
        );

        $fatalAtual = CarbonImmutable::parse($prazo->data_fatal)->toDateString();

        if ($calculado->dataFatal->toDateString() === $fatalAtual) {
            return false;
        }

        $antes = $prazo->toArray();

        if ($prazo->ajustado_manualmente) {
            $prazo->precisa_revisao = true;
            $prazo->revisao_motivo = sprintf(
                'O calendario mudou e o calculo agora aponta %s, mas este prazo foi ajustado a mao para %s.',
                $calculado->dataFatal->format('d/m/Y'),
                CarbonImmutable::parse($prazo->data_fatal)->format('d/m/Y')
            );
            $prazo->save();
        } else {
            $prazo->fill([
                'data_publicacao' => $calculado->dataPublicacao,
                'data_inicio' => $calculado->dataInicio,
                'data_fatal' => $calculado->dataFatal,
                'data_alvo' => $calculado->dataAlvo,
                'cadeia_origem' => $calculado->cadeiaOrigem(),
                'precisa_revisao' => true,
                'revisao_motivo' => sprintf(
                    'Recalculado apos mudanca no calendario de feriados: a data fatal passou de %s para %s.',
                    CarbonImmutable::parse($antes['data_fatal'])->format('d/m/Y'),
                    $calculado->dataFatal->format('d/m/Y')
                ),
            ]);
            $prazo->save();
            $this->sincronizarEvento($prazo);
        }

        Auditoria::registrar(
            $prazo->advogado_id,
            'prazos',
            $prazo->id,
            'recalculo_por_feriado',
            $antes,
            $prazo->toArray()
        );

        return true;
    }

    /**
     * Colaborador terminou a parte dele.
     *
     * Isto NAO fecha o prazo. Quem responde pela perda perante a OAB e o
     * titular, entao o prazo segue aberto e vermelho ate ele conferir. E a
     * regra que a secao 16 anteviu: "ver mas nao cumprir prazo".
     */
    public function pedirConferencia(Prazo $prazo, User $autor, ?string $observacao = null): Prazo
    {
        $antes = $prazo->toArray();

        $prazo->forceFill([
            'aguardando_conferencia' => true,
            'conferencia_solicitada_por' => $autor->id,
            'conferencia_solicitada_em' => now(),
            'conferencia_observacao' => $observacao,
            'status' => $prazo->status === 'aberto' ? 'em_andamento' : $prazo->status,
        ])->save();

        Auditoria::registrar(
            $prazo->advogado_id,
            'prazos',
            $prazo->id,
            'conferencia_solicitada',
            $antes,
            $prazo->toArray(),
            $autor->id
        );

        Notificacao::firstOrCreate(
            ['chave_dedup' => "conferencia:{$prazo->id}:".now()->timestamp],
            [
                'advogado_id' => $prazo->advogado_id,
                'tipo' => 'conferencia_pendente',
                'titulo' => 'Prazo aguardando sua conferencia',
                'corpo' => sprintf(
                    '%s marcou "%s" como feito. O prazo segue aberto ate voce confirmar.',
                    $autor->name,
                    $prazo->tipo
                ),
                'url' => "/prazos/{$prazo->id}",
                'payload' => ['prazo_id' => $prazo->id, 'autor_id' => $autor->id],
                'agendada_para' => now(),
            ]
        );

        return $prazo;
    }

    /**
     * O titular confirma (fecha de vez) ou devolve para ajuste.
     */
    public function responderConferencia(Prazo $prazo, User $titular, bool $aprovado, ?string $observacao = null): Prazo
    {
        $antes = $prazo->toArray();

        if ($aprovado) {
            $prazo->forceFill([
                'aguardando_conferencia' => false,
                'status' => 'cumprido',
                'cumprido_em' => now(),
            ])->save();
        } else {
            $prazo->forceFill([
                'aguardando_conferencia' => false,
                'status' => 'em_andamento',
                'conferencia_observacao' => $observacao,
            ])->save();
        }

        $this->sincronizarEvento($prazo);

        Auditoria::registrar(
            $prazo->advogado_id,
            'prazos',
            $prazo->id,
            $aprovado ? 'conferencia_aprovada' : 'conferencia_devolvida',
            $antes,
            $prazo->toArray(),
            $titular->id
        );

        return $prazo;
    }

    public function mudarStatus(Prazo $prazo, string $status, ?string $observacao = null): Prazo
    {
        $antes = $prazo->toArray();

        $prazo->status = $status;
        $prazo->cumprido_em = $status === 'cumprido' ? now() : null;
        $prazo->aguardando_conferencia = false;

        if ($observacao) {
            $prazo->observacoes = trim(($prazo->observacoes ? $prazo->observacoes."\n" : '').$observacao);
        }

        $prazo->save();

        Evento::where('eventable_type', Prazo::class)
            ->where('eventable_id', $prazo->id)
            ->update(['concluido' => in_array($status, ['cumprido', 'prejudicado'], true)]);

        Auditoria::registrar($prazo->advogado_id, 'prazos', $prazo->id, "status:{$status}", $antes, $prazo->toArray());

        return $prazo;
    }

    /**
     * O prazo aparece na agenda pela data-alvo, nao pela fatal (principio 1).
     */
    private function sincronizarEvento(Prazo $prazo): void
    {
        Evento::updateOrCreate(
            ['eventable_type' => Prazo::class, 'eventable_id' => $prazo->id],
            [
                'advogado_id' => $prazo->advogado_id,
                'tipo' => 'prazo',
                'processo_id' => $prazo->processo_id,
                'titulo' => $prazo->tipo,
                'descricao' => sprintf(
                    'Fatal em %s. Alvo interno %s.',
                    CarbonImmutable::parse($prazo->data_fatal)->format('d/m/Y'),
                    CarbonImmutable::parse($prazo->data_alvo)->format('d/m/Y')
                ),
                'inicio' => CarbonImmutable::parse($prazo->data_alvo)->startOfDay(),
                'fim' => CarbonImmutable::parse($prazo->data_fatal)->endOfDay(),
                'dia_inteiro' => true,
                'concluido' => in_array($prazo->status, ['cumprido', 'prejudicado'], true),
            ]
        );
    }

    private function recuarBuffer(CarbonImmutable $fatal, int $dias, CalendarioFeriados $calendario): CarbonImmutable
    {
        $cursor = $fatal;
        $recuados = 0;

        while ($recuados < $dias) {
            $cursor = $cursor->subDay();

            if ($calendario->ehDiaDeContagem($cursor)) {
                $recuados++;
            }
        }

        return $cursor;
    }
}
