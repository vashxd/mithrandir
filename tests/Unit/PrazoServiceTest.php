<?php

namespace Tests\Unit;

use App\Services\Prazo\CalendarioFeriados;
use App\Services\Prazo\PrazoInput;
use App\Services\Prazo\PrazoService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Suite obrigatoria da secao 8.3 da especificacao.
 *
 * Roda sem banco, sem Laravel, sem rede: o PrazoService e funcao pura.
 */
class PrazoServiceTest extends TestCase
{
    private PrazoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PrazoService;
    }

    private function calendario(array $feriados = []): CalendarioFeriados
    {
        return new CalendarioFeriados($feriados);
    }

    // 1. Prazo simples de 15 dias uteis, sem feriado.
    public function test_prazo_simples_de_15_dias_uteis_sem_feriado(): void
    {
        // Quarta-feira 04/03/2026.
        $r = $this->service->calcular(
            new PrazoInput('2026-03-04', dias: 15, bufferDias: 3, tipo: 'Contestacao'),
            $this->calendario()
        );

        $this->assertSame('2026-03-05', $r->dataPublicacao->toDateString());  // quinta
        $this->assertSame('2026-03-06', $r->dataInicio->toDateString());      // sexta = dia 1
        // 15 dias uteis a partir de 06/03 (sexta) -> 26/03 (quinta).
        $this->assertSame('2026-03-26', $r->dataFatal->toDateString());
        $this->assertSame('2026-03-23', $r->dataAlvo->toDateString());
        $this->assertFalse($r->prorrogado);
        $this->assertSame(15, $r->diasEfetivos);
    }

    // 2. Disponibilizacao na sexta -> publicacao na segunda -> inicio na terca.
    public function test_disponibilizacao_na_sexta_publica_na_segunda_e_inicia_na_terca(): void
    {
        // Sexta-feira 06/03/2026.
        $r = $this->service->calcular(
            new PrazoInput('2026-03-06', dias: 5),
            $this->calendario()
        );

        $this->assertSame('Friday', $r->dataDisponibilizacao->format('l'));
        $this->assertSame('2026-03-09', $r->dataPublicacao->toDateString());
        $this->assertSame('Monday', $r->dataPublicacao->format('l'));
        $this->assertSame('2026-03-10', $r->dataInicio->toDateString());
        $this->assertSame('Tuesday', $r->dataInicio->format('l'));
    }

    // 3. Disponibilizacao na vespera de feriado prolongado.
    public function test_disponibilizacao_na_vespera_de_feriado_prolongado(): void
    {
        // Quarta 01/04/2026; 02/04 e 03/04 feriados -> emenda com o fim de semana.
        $cal = $this->calendario([
            '2026-04-02' => 'Quinta-feira Santa (feriado forense)',
            '2026-04-03' => 'Sexta-feira Santa',
        ]);

        $r = $this->service->calcular(new PrazoInput('2026-04-01', dias: 5), $cal);

        // Publicacao pula 02, 03, sabado 04 e domingo 05 -> segunda 06/04.
        $this->assertSame('2026-04-06', $r->dataPublicacao->toDateString());
        $this->assertSame('2026-04-07', $r->dataInicio->toDateString());
        // 5 dias uteis: 07, 08, 09, 10, 13 (11 e 12 fim de semana).
        $this->assertSame('2026-04-13', $r->dataFatal->toDateString());
    }

    // 4. Prazo iniciado em 15/12 que atravessa o recesso (20/12 - 20/01).
    public function test_prazo_que_atravessa_o_recesso_forense(): void
    {
        // 15/12/2025 e segunda-feira. Disponibilizacao na quinta 11/12/2025
        // -> publicacao sexta 12/12 -> inicio segunda 15/12.
        $r = $this->service->calcular(
            new PrazoInput('2025-12-11', dias: 15, bufferDias: 0),
            $this->calendario(['2025-12-25' => 'Natal', '2026-01-01' => 'Confraternizacao Universal'])
        );

        $this->assertSame('2025-12-15', $r->dataInicio->toDateString());

        // Correm 15, 16, 17, 18, 19 (5 dias). O prazo suspende de 20/12 a 20/01
        // e retoma em 21/01/2026 (quarta). Faltam 10 dias uteis:
        // 21, 22, 23, 26, 27, 28, 29, 30, 02/02, 03/02.
        $this->assertSame('2026-02-03', $r->dataFatal->toDateString());

        $motivos = array_column($r->diasNaoContados, 'motivo');
        $this->assertNotEmpty(array_filter($motivos, fn ($m) => str_contains($m, 'recesso forense')));
    }

    // 5. Fatal caindo em feriado municipal de Manaus.
    public function test_fatal_caindo_em_feriado_municipal_de_manaus_prorroga(): void
    {
        // Sem feriado, o fatal de um prazo de 5 dias cairia em 24/09/2026 (quinta).
        $semFeriado = $this->service->calcular(new PrazoInput('2026-09-16', dias: 5), $this->calendario());
        $this->assertSame('2026-09-24', $semFeriado->dataFatal->toDateString());

        // 24/09 e o Dia de Nossa Senhora da Conceicao / aniversario de Manaus:
        // feriado municipal cadastrado -> nao conta e o fatal escorrega para 25/09.
        $comFeriado = $this->service->calcular(
            new PrazoInput('2026-09-16', dias: 5),
            $this->calendario(['2026-09-24' => 'Aniversario de Manaus (municipal)'])
        );

        $this->assertSame('2026-09-25', $comFeriado->dataFatal->toDateString());
    }

    // 6. Prazo em dobro para a Fazenda.
    public function test_prazo_em_dobro_para_a_fazenda_publica(): void
    {
        $simples = $this->service->calcular(new PrazoInput('2026-03-04', dias: 15), $this->calendario());
        $dobro = $this->service->calcular(
            new PrazoInput('2026-03-04', dias: 15, multiplicador: 2),
            $this->calendario()
        );

        $this->assertSame(15, $simples->diasEfetivos);
        $this->assertSame(30, $dobro->diasEfetivos);
        $this->assertTrue($dobro->dataFatal->greaterThan($simples->dataFatal));
        $this->assertSame('2026-04-16', $dobro->dataFatal->toDateString());

        $etapas = array_column($dobro->passos, 'etapa');
        $this->assertContains('multiplicador', $etapas);

        $passoMultiplicador = collect_first($dobro->passos, fn ($p) => $p['etapa'] === 'multiplicador');
        $this->assertSame('CPC arts. 180, 183, 186', $passoMultiplicador['base_legal']);
        $this->assertStringContainsString('Confirmacao humana', $passoMultiplicador['regra']);
    }

    // 7. Prazo em dias corridos (penal).
    public function test_prazo_em_dias_corridos(): void
    {
        // 5 dias corridos a partir de 10/03/2026 (terca) -> 14/03 (sabado) -> prorroga 16/03.
        $r = $this->service->calcular(
            new PrazoInput('2026-03-06', dias: 5, emDiasUteis: false),
            $this->calendario()
        );

        $this->assertSame('2026-03-10', $r->dataInicio->toDateString());
        $this->assertSame('2026-03-16', $r->dataFatal->toDateString());
        $this->assertTrue($r->prorrogado);
    }

    public function test_dias_corridos_nao_sofrem_a_suspensao_do_art_220(): void
    {
        // Inicio 15/12/2025, 10 dias corridos -> 24/12 (quarta), dentro do recesso,
        // mas em dias corridos o art. 220 nao se aplica.
        $r = $this->service->calcular(
            new PrazoInput('2025-12-11', dias: 10, emDiasUteis: false),
            $this->calendario()
        );

        $this->assertSame('2025-12-15', $r->dataInicio->toDateString());
        $this->assertSame('2025-12-24', $r->dataFatal->toDateString());
    }

    // 8. Feriado cadastrado retroativamente -> recalculo em lote.
    public function test_feriado_cadastrado_retroativamente_muda_a_data_fatal(): void
    {
        $entrada = new PrazoInput('2026-03-04', dias: 15);

        $antes = $this->service->calcular($entrada, $this->calendario());
        $depois = $this->service->calcular(
            $entrada,
            $this->calendario(['2026-03-16' => 'Suspensao de expediente (portaria TJAM)'])
        );

        $this->assertSame('2026-03-26', $antes->dataFatal->toDateString());
        $this->assertSame('2026-03-27', $depois->dataFatal->toDateString());
        $this->assertTrue($depois->dataFatal->greaterThan($antes->dataFatal));
    }

    // 9. Prazo de 5 dias inteiramente dentro de uma semana com dois feriados.
    public function test_prazo_de_5_dias_em_semana_com_dois_feriados(): void
    {
        $cal = $this->calendario([
            '2026-05-06' => 'Feriado municipal',
            '2026-05-08' => 'Suspensao de expediente forense',
        ]);

        // Disponibilizacao segunda 04/05 -> publicacao terca 05/05 -> inicio quarta 06/05,
        // mas 06/05 e feriado, entao o inicio escorrega para quinta 07/05.
        $r = $this->service->calcular(new PrazoInput('2026-05-04', dias: 5), $cal);

        $this->assertSame('2026-05-05', $r->dataPublicacao->toDateString());
        $this->assertSame('2026-05-07', $r->dataInicio->toDateString());
        // Dias uteis: 07 (1), 11 (2), 12 (3), 13 (4), 14 (5). 08 feriado, 09/10 fim de semana.
        $this->assertSame('2026-05-14', $r->dataFatal->toDateString());
        $this->assertCount(2, $r->diasNaoContados);
    }

    // 10. Ajuste manual sobrescrevendo o calculo - o log guarda os dois valores.
    public function test_ajuste_manual_preserva_o_valor_calculado(): void
    {
        $calculado = $this->service->calcular(
            new PrazoInput('2026-03-04', dias: 15),
            $this->calendario()
        );

        // Simula o que o PrazoRepository grava ao aceitar um ajuste manual.
        $registro = $calculado->toArray();
        $registro['data_fatal_calculada'] = $registro['data_fatal'];
        $registro['data_fatal'] = '2026-03-20';
        $registro['ajustado_manualmente'] = true;
        $registro['justificativa'] = 'Intimacao pessoal do MP antecipou o inicio da contagem.';

        $this->assertSame('2026-03-26', $registro['data_fatal_calculada']);
        $this->assertSame('2026-03-20', $registro['data_fatal']);
        $this->assertTrue($registro['ajustado_manualmente']);
        $this->assertNotEmpty($registro['justificativa']);
        // A cadeia original continua intacta ao lado do valor ajustado.
        $this->assertNotEmpty($registro['cadeia_origem']['passos']);
    }

    public function test_cadeia_de_origem_expoe_todos_os_passos_na_ordem(): void
    {
        $r = $this->service->calcular(new PrazoInput('2026-03-04', dias: 15), $this->calendario());

        $etapas = array_column($r->passos, 'etapa');

        $this->assertSame([
            'disponibilizacao',
            'publicacao',
            'inicio_contagem',
            'vencimento',
            'data_fatal',
            'data_alvo',
        ], $etapas);
    }

    public function test_buffer_nunca_produz_data_alvo_antes_do_inicio(): void
    {
        $r = $this->service->calcular(
            new PrazoInput('2026-03-04', dias: 2, bufferDias: 10),
            $this->calendario()
        );

        $this->assertTrue($r->dataAlvo->greaterThanOrEqualTo($r->dataInicio));
    }

    public function test_inicio_forcado_pula_os_paragrafos_do_art_224(): void
    {
        $r = $this->service->calcular(
            new PrazoInput(
                '2026-03-04',
                dias: 5,
                inicioForcado: CarbonImmutable::parse('2026-03-04')
            ),
            $this->calendario()
        );

        $this->assertSame('2026-03-04', $r->dataInicio->toDateString());
        $this->assertSame('2026-03-10', $r->dataFatal->toDateString());
    }

    public function test_rejeita_entrada_invalida(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PrazoInput('2026-03-04', dias: 0);
    }
}

/**
 * Helper local: primeiro elemento que satisfaz o predicado.
 */
function collect_first(array $itens, callable $predicado): mixed
{
    foreach ($itens as $item) {
        if ($predicado($item)) {
            return $item;
        }
    }

    return null;
}
