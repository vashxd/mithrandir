<?php

namespace App\Jobs;

use App\Models\Notificacao;
use App\Models\Prazo;
use App\Services\Prazo\CalendarioService;
use App\Services\Prazo\PrazoRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * RF-2.11: feriado cadastrado retroativamente obriga recalculo em lote dos
 * prazos afetados, com aviso ao usuario.
 *
 * "Afetado" = prazo aberto cuja janela (inicio..fatal) contem a data do feriado.
 */
class RecalcularPrazosJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $dataFeriado  data que entrou/saiu do calendario
     */
    public function __construct(
        public string $dataFeriado,
        public ?int $advogadoId = null,
    ) {}

    public function handle(PrazoRepository $repositorio, CalendarioService $calendario): void
    {
        $calendario->limparCache();

        $query = Prazo::with(['processo', 'advogado'])
            ->abertos()
            ->where('data_inicio', '<=', $this->dataFeriado)
            ->where('data_fatal', '>=', $this->dataFeriado);

        if ($this->advogadoId) {
            $query->doAdvogado($this->advogadoId);
        }

        $afetadosPorAdvogado = [];

        foreach ($query->cursor() as $prazo) {
            if ($repositorio->recalcular($prazo)) {
                $afetadosPorAdvogado[$prazo->advogado_id] =
                    ($afetadosPorAdvogado[$prazo->advogado_id] ?? 0) + 1;
            }
        }

        foreach ($afetadosPorAdvogado as $advogadoId => $quantidade) {
            Notificacao::firstOrCreate(
                ['chave_dedup' => "recalculo:{$advogadoId}:{$this->dataFeriado}"],
                [
                    'advogado_id' => $advogadoId,
                    'tipo' => 'recalculo_prazos',
                    'titulo' => $quantidade === 1
                        ? '1 prazo mudou de data'
                        : "{$quantidade} prazos mudaram de data",
                    'corpo' => 'Um feriado foi cadastrado depois do calculo original. Confira as datas.',
                    'url' => '/prazos?revisao=1',
                    'payload' => ['data_feriado' => $this->dataFeriado, 'quantidade' => $quantidade],
                    'agendada_para' => now(),
                ]
            );
        }
    }
}
