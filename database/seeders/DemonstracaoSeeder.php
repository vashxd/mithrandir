<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Despesa;
use App\Models\Evento;
use App\Models\Honorario;
use App\Models\OabWatch;
use App\Models\Parcela;
use App\Models\Processo;
use App\Models\Publicacao;
use App\Models\User;
use App\Services\Prazo\PrazoRepository;
use App\Support\NumeroCnj;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Conta de demonstracao com a carteira tipica da persona primaria:
 * previdenciario, consumidor e familia, entre 15 e 80 processos ativos.
 *
 * As datas sao relativas a hoje, para a tela "Hoje" nunca aparecer vazia.
 */
class DemonstracaoSeeder extends Seeder
{
    public function run(): void
    {
        $hoje = CarbonImmutable::today(config('mithrandir.timezone'));

        $advogada = User::updateOrCreate(
            ['email' => 'ana@mithrandir.test'],
            [
                'name' => 'Ana Beatriz Souza',
                'password' => Hash::make('mithrandir'),
                'oab' => '14523',
                'uf' => 'AM',
                'timezone' => 'America/Manaus',
                'buffer_padrao' => 3,
                'digest_horario' => '08:00:00',
                'percentual_imposto' => 11,
                'aceite_termo_em' => now(),
                'aceite_termo_versao' => config('mithrandir.termo_versao'),
                'onboarding_concluido_em' => now(),
            ]
        );

        foreach (['14523', '14523-O', 'Ana Beatriz Souza'] as $indice => $termo) {
            OabWatch::updateOrCreate(
                ['advogado_id' => $advogada->id, 'termo' => $termo, 'tipo' => $indice === 2 ? 'nome' : 'oab'],
                [
                    'uf' => 'AM',
                    'ativo' => $indice !== 2,
                    'ultima_sync_em' => now()->subHours(3),
                ]
            );
        }

        $prazos = app(PrazoRepository::class);

        $carteira = [
            ['Maria das Gracas Ferreira', '(92) 99182-3344', 'previdenciario', 'BPC/LOAS negado por renda', 'Aposentadoria', -20, 'Contestacao', 15],
            ['Joao Batista Alves', '(92) 98871-2210', 'previdenciario', 'Auxilio-doenca cessado', 'Recurso administrativo', -14, 'Recurso inominado', 10],
            ['Raimunda Nonata Lima', '(92) 99433-8876', 'previdenciario', 'Salario-maternidade rural', 'Replica', -9, 'Replica', 15],
            ['Cleiton Moraes da Silva', '(92) 98120-5567', 'consumidor', 'Negativacao indevida', 'Contestacao', -6, 'Contestacao', 15],
            ['Patricia Nogueira Reis', '(92) 99655-1120', 'familia', 'Divorcio consensual', 'Manifestacao', -3, 'Manifestacao', 5],
            ['Edvaldo Pereira Castro', '(92) 98344-7781', 'consumidor', 'Voo cancelado sem assistencia', 'Embargos de declaracao', -2, 'Embargos de declaracao', 5],
        ];

        foreach ($carteira as $indice => [$nome, $telefone, $area, $assunto, $rotuloPrazo, $diasAtras, $tipoPrazo, $dias]) {
            $cliente = Cliente::updateOrCreate(
                ['advogado_id' => $advogada->id, 'nome' => $nome],
                [
                    'documento' => str_pad((string) (11122233300 + $indice), 11, '0', STR_PAD_LEFT),
                    'contatos' => [['tipo' => 'whatsapp', 'valor' => $telefone, 'principal' => true]],
                    'endereco' => ['cidade' => 'Manaus', 'uf' => 'AM'],
                    'origem' => $indice % 2 === 0 ? 'Indicacao' : 'Instagram',
                ]
            );

            $numero = $this->numeroCnj(801000 + $indice);

            $processo = Processo::updateOrCreate(
                ['advogado_id' => $advogada->id, 'numero_cnj' => $numero],
                [
                    'cliente_id' => $cliente->id,
                    'titulo' => $assunto,
                    'tribunal' => $area === 'previdenciario' ? 'TRF1' : 'TJAM',
                    'vara' => $area === 'previdenciario'
                        ? '1a Vara Federal de Juizado Especial de Manaus'
                        : '2a Vara Civel de Manaus',
                    'classe' => 'Procedimento Comum',
                    'assunto' => $assunto,
                    'area' => $area,
                    'fase' => 'conhecimento',
                    'valor_causa' => 15000 + $indice * 3000,
                    'proxima_acao' => $indice === 0
                        ? 'Juntar CNIS atualizado antes da pericia social.'
                        : null,
                ]
            );

            $disponibilizacao = $hoje->addDays($diasAtras);

            $publicacao = Publicacao::updateOrCreate(
                ['hash' => hash('sha256', "demo:{$advogada->id}:{$numero}")],
                [
                    'advogado_id' => $advogada->id,
                    'numero_comunicacao' => 'DEMO-'.(1000 + $indice),
                    'tribunal' => $processo->tribunal,
                    'orgao' => $processo->vara,
                    'numero_processo' => $numero,
                    'tipo_comunicacao' => 'Intimacao',
                    'teor' => "Fica a parte autora intimada para, no prazo de {$dias} dias, "
                        ."manifestar-se nos autos do processo {$processo->numero_formatado}, "
                        .'sob pena de preclusao.',
                    'data_disponibilizacao' => $disponibilizacao,
                    'status_triagem' => 'prazo',
                    'processo_id' => $processo->id,
                    'triada_em' => now(),
                ]
            );

            if ($processo->prazos()->count() === 0) {
                $prazos->criar($advogada, [
                    'processo_id' => $processo->id,
                    'publicacao_id' => $publicacao->id,
                    'tipo' => $tipoPrazo,
                    'dias' => $dias,
                    'em_dias_uteis' => true,
                    'data_disponibilizacao' => $disponibilizacao,
                    'buffer_dias' => 3,
                ]);
            }

            // Honorario parcelado, com uma parcela ja vencida no primeiro caso.
            if ($processo->honorarios()->count() === 0) {
                $honorario = Honorario::create([
                    'advogado_id' => $advogada->id,
                    'processo_id' => $processo->id,
                    'cliente_id' => $cliente->id,
                    'tipo' => 'parcelado',
                    'valor' => 2400,
                    'qtd_parcelas' => 3,
                    'primeiro_vencimento' => $hoje->subMonths(1)->addDays($indice),
                ]);

                for ($numeroParcela = 1; $numeroParcela <= 3; $numeroParcela++) {
                    $vencimento = CarbonImmutable::parse($honorario->primeiro_vencimento)
                        ->addMonths($numeroParcela - 1);

                    Parcela::create([
                        'advogado_id' => $advogada->id,
                        'honorario_id' => $honorario->id,
                        'numero' => $numeroParcela,
                        'valor' => 800,
                        'vencimento' => $vencimento,
                        'pago_em' => $numeroParcela === 1 && $indice > 1 ? $vencimento : null,
                        'valor_pago' => $numeroParcela === 1 && $indice > 1 ? 800 : null,
                    ]);
                }
            }
        }

        // Publicacoes ainda por triar: a tela Hoje precisa de inbox.
        foreach (range(1, 3) as $indice) {
            Publicacao::updateOrCreate(
                ['hash' => hash('sha256', "demo-nova:{$advogada->id}:{$indice}")],
                [
                    'advogado_id' => $advogada->id,
                    'numero_comunicacao' => 'DEMO-NOVA-'.$indice,
                    'tribunal' => $indice === 1 ? 'TJAM' : 'TRF1',
                    'orgao' => $indice === 1 ? '3a Vara Civel de Manaus' : 'JEF Manaus',
                    'numero_processo' => $this->numeroCnj(802000 + $indice),
                    'tipo_comunicacao' => 'Intimacao',
                    'teor' => 'Intimacao da parte autora para ciencia da decisao de fls. e, '
                        .'querendo, manifestacao no prazo legal. Publique-se. Intime-se.',
                    'data_disponibilizacao' => $hoje->subDay(),
                    'status_triagem' => 'nova',
                ]
            );
        }

        // Audiencia real na agenda.
        Evento::updateOrCreate(
            ['advogado_id' => $advogada->id, 'titulo' => 'Audiencia de instrucao - Cleiton Moraes'],
            [
                'tipo' => 'audiencia',
                'inicio' => $hoje->addDays(2)->setTime(9, 30),
                'fim' => $hoje->addDays(2)->setTime(10, 30),
                'local' => 'Forum Henoch Reis, sala 304',
                'link' => 'https://meet.example.test/audiencia-demo',
                'lembrete_deslocamento_min' => 60,
                'processo_id' => Processo::where('advogado_id', $advogada->id)->skip(3)->first()?->id,
            ]
        );

        Despesa::updateOrCreate(
            ['advogado_id' => $advogada->id, 'descricao' => 'Custas iniciais'],
            [
                'processo_id' => Processo::where('advogado_id', $advogada->id)->first()?->id,
                'categoria' => 'custas',
                'valor' => 187.50,
                'data' => $hoje->subDays(12),
                'reembolsavel' => true,
            ]
        );

        $this->command?->info('Conta de demonstracao: ana@mithrandir.test / mithrandir');
    }

    /**
     * Monta um numero CNJ valido (com digito verificador correto) para a demo.
     */
    private function numeroCnj(int $sequencial): string
    {
        $semDv = str_pad((string) $sequencial, 7, '0', STR_PAD_LEFT).'2026'.'8'.'04'.'0001';

        return substr($semDv, 0, 7).NumeroCnj::dvEsperado($semDv).substr($semDv, 7);
    }
}
