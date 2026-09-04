<?php

namespace Tests\Feature;

use App\Jobs\AgendarAlertasPrazoJob;
use App\Jobs\RecalcularPrazosJob;
use App\Models\Evento;
use App\Models\Feriado;
use App\Models\Notificacao;
use App\Models\Prazo;
use App\Models\Processo;
use App\Models\Publicacao;
use App\Models\TipoPrazo;
use App\Models\User;
use App\Services\Prazo\CalendarioService;
use App\Services\Prazo\PrazoRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Da triagem da publicacao ate o prazo na agenda — o caminho que o produto
 * inteiro existe para servir.
 */
class TriagemEPrazoTest extends TestCase
{
    use RefreshDatabase;

    private User $advogado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->advogado = User::create([
            'name' => 'Ana Souza',
            'email' => 'ana@exemplo.test',
            'password' => 'segredo123',
            'oab' => '12345',
            'uf' => 'AM',
            'buffer_padrao' => 3,
            'aceite_termo_em' => now(),
        ]);
    }

    private function publicacao(array $sobrescreve = []): Publicacao
    {
        return Publicacao::create(array_merge([
            'advogado_id' => $this->advogado->id,
            'hash' => 'hash-'.uniqid(),
            'numero_comunicacao' => 'COM-'.uniqid(),
            'tribunal' => 'TJAM',
            'numero_processo' => '08012345620268040001',
            'teor' => 'Intimacao para replica no prazo de 15 dias.',
            'data_disponibilizacao' => '2026-03-04',
            'status_triagem' => 'nova',
        ], $sobrescreve));
    }

    public function test_triagem_como_prazo_cria_prazo_com_a_cadeia_completa(): void
    {
        $publicacao = $this->publicacao();
        $tipo = TipoPrazo::create([
            'nome' => 'Replica', 'slug' => 'replica-teste', 'dias' => 15, 'em_dias_uteis' => true,
        ]);

        $this->actingAs($this->advogado)
            ->post("/publicacoes/{$publicacao->id}/triar", [
                'decisao' => 'prazo',
                'criar_processo' => true,
                'tipo_prazo_id' => $tipo->id,
                'tipo' => 'Replica',
                'dias' => 15,
                'em_dias_uteis' => true,
            ])
            ->assertRedirect();

        $prazo = Prazo::first();
        $this->assertNotNull($prazo);

        // Mesma cadeia validada na suite unitaria da secao 8.3.
        $this->assertSame('2026-03-05', $prazo->data_publicacao->toDateString());
        $this->assertSame('2026-03-06', $prazo->data_inicio->toDateString());
        $this->assertSame('2026-03-26', $prazo->data_fatal->toDateString());
        $this->assertSame('2026-03-23', $prazo->data_alvo->toDateString());

        $this->assertNotEmpty($prazo->cadeia_origem['passos']);
        $this->assertSame($publicacao->id, $prazo->publicacao_id);

        // RF-1.6: o caso foi criado a partir da publicacao e vinculado.
        $processo = Processo::first();
        $this->assertNotNull($processo);
        $this->assertSame($processo->id, $prazo->processo_id);
        $this->assertSame($processo->id, $publicacao->fresh()->processo_id);

        // RF-3.1: o prazo aparece na timeline pela data-alvo.
        $evento = Evento::where('eventable_type', Prazo::class)->first();
        $this->assertNotNull($evento);
        $this->assertSame('2026-03-23', $evento->inicio->toDateString());
    }

    public function test_triagem_como_ciencia_nao_cria_prazo(): void
    {
        $publicacao = $this->publicacao();

        $this->actingAs($this->advogado)
            ->post("/publicacoes/{$publicacao->id}/triar", ['decisao' => 'ciencia'])
            ->assertRedirect();

        $this->assertSame(0, Prazo::count());
        $this->assertSame('ciencia', $publicacao->fresh()->status_triagem);
        $this->assertNotNull($publicacao->fresh()->triada_em);
    }

    public function test_descarte_arquiva_mas_nunca_apaga(): void
    {
        $publicacao = $this->publicacao();

        $this->actingAs($this->advogado)
            ->post("/publicacoes/{$publicacao->id}/triar", ['decisao' => 'descartada']);

        // Regra de ouro do M1: publicacao e prova, nao se apaga.
        $this->assertDatabaseHas('publicacoes', [
            'id' => $publicacao->id,
            'status_triagem' => 'descartada',
        ]);
        $this->assertNotNull($publicacao->fresh()->arquivada_em);
        $this->assertNotNull($publicacao->fresh()->teor);
    }

    public function test_advogado_nao_tria_publicacao_de_outro(): void
    {
        $outro = User::create([
            'name' => 'Bruno', 'email' => 'bruno@exemplo.test', 'password' => 'x',
            'oab' => '999', 'uf' => 'AM', 'aceite_termo_em' => now(),
        ]);

        $publicacao = $this->publicacao(['advogado_id' => $outro->id]);

        $this->actingAs($this->advogado)
            ->post("/publicacoes/{$publicacao->id}/triar", ['decisao' => 'ciencia'])
            ->assertForbidden();
    }

    public function test_prazo_em_dobro_dobra_a_contagem(): void
    {
        $publicacao = $this->publicacao();

        $this->actingAs($this->advogado)->post("/publicacoes/{$publicacao->id}/triar", [
            'decisao' => 'prazo',
            'tipo' => 'Contestacao',
            'dias' => 15,
            'em_dias_uteis' => true,
            'multiplicador' => 2,
            'multiplicador_motivo' => 'Reu e o INSS',
        ]);

        $prazo = Prazo::first();
        $this->assertSame(2, $prazo->multiplicador);
        $this->assertSame('2026-04-16', $prazo->data_fatal->toDateString());
        $this->assertSame('Reu e o INSS', $prazo->multiplicador_motivo);
    }

    /* ---------------- Ajuste manual (RF-2.8) ---------------- */

    private function criarPrazo(array $sobrescreve = []): Prazo
    {
        $publicacao = $this->publicacao();

        $this->actingAs($this->advogado)->post("/publicacoes/{$publicacao->id}/triar", array_merge([
            'decisao' => 'prazo',
            'tipo' => 'Contestacao',
            'dias' => 15,
            'em_dias_uteis' => true,
            'criar_processo' => true,
        ], $sobrescreve));

        return Prazo::latest('id')->first();
    }

    public function test_ajuste_manual_exige_justificativa(): void
    {
        $prazo = $this->criarPrazo();

        $this->actingAs($this->advogado)
            ->from("/prazos/{$prazo->id}")
            ->post("/prazos/{$prazo->id}/ajustar", [
                'data_fatal' => '2026-03-20',
                'justificativa' => 'curto',
            ])
            ->assertSessionHasErrors('justificativa');

        $this->assertSame('2026-03-26', $prazo->fresh()->data_fatal->toDateString());
    }

    public function test_ajuste_manual_guarda_os_dois_valores(): void
    {
        $prazo = $this->criarPrazo();

        $this->actingAs($this->advogado)->post("/prazos/{$prazo->id}/ajustar", [
            'data_fatal' => '2026-03-20',
            'justificativa' => 'Intimacao pessoal em cartorio antecipou o inicio da contagem.',
        ]);

        $prazo->refresh();

        $this->assertTrue((bool) $prazo->ajustado_manualmente);
        $this->assertSame('2026-03-20', $prazo->data_fatal->toDateString());
        $this->assertSame('2026-03-26', $prazo->data_fatal_calculada->toDateString());
        $this->assertStringContainsString('Intimacao pessoal', $prazo->justificativa);
        $this->assertSame('2026-03-26', $prazo->cadeia_origem['ajuste_manual']['de']);
        $this->assertSame('2026-03-20', $prazo->cadeia_origem['ajuste_manual']['para']);

        $this->assertDatabaseHas('auditoria', [
            'entidade' => 'prazos',
            'entidade_id' => $prazo->id,
            'acao' => 'ajuste_manual',
        ]);
    }

    /* ---------------- Feriado retroativo (RF-2.11) ---------------- */

    public function test_feriado_retroativo_recalcula_prazo_e_avisa(): void
    {
        $prazo = $this->criarPrazo();
        $this->assertSame('2026-03-26', $prazo->data_fatal->toDateString());

        // Feriado no meio da contagem, cadastrado depois do calculo original.
        $this->actingAs($this->advogado)->post('/configuracoes/feriados', [
            'data' => '2026-03-16',
            'descricao' => 'Suspensao de expediente — Portaria TJAM',
            'abrangencia' => 'nacional',
            'suspensao_expediente' => true,
        ])->assertRedirect();

        $prazo->refresh();

        $this->assertSame('2026-03-27', $prazo->data_fatal->toDateString());
        $this->assertTrue((bool) $prazo->precisa_revisao);
        $this->assertStringContainsString('26/03/2026', $prazo->revisao_motivo);

        $this->assertDatabaseHas('notificacoes', [
            'advogado_id' => $this->advogado->id,
            'tipo' => 'recalculo_prazos',
        ]);
    }

    public function test_recalculo_nao_sobrescreve_prazo_ajustado_a_mao(): void
    {
        $prazo = $this->criarPrazo();

        $this->actingAs($this->advogado)->post("/prazos/{$prazo->id}/ajustar", [
            'data_fatal' => '2026-03-20',
            'justificativa' => 'Intimacao pessoal antecipou a contagem.',
        ]);

        Feriado::create([
            'data' => '2026-03-16',
            'descricao' => 'Suspensao de expediente',
            'abrangencia' => 'nacional',
        ]);

        (new RecalcularPrazosJob('2026-03-16', $this->advogado->id))->handle(
            app(PrazoRepository::class),
            app(CalendarioService::class)
        );

        $prazo->refresh();

        // A data escolhida pela pessoa fica de pe; o app so pede revisao.
        $this->assertSame('2026-03-20', $prazo->data_fatal->toDateString());
        $this->assertTrue((bool) $prazo->precisa_revisao);
        $this->assertStringContainsString('ajustado a mao', $prazo->revisao_motivo);
    }

    public function test_prazo_cumprido_nao_e_recalculado(): void
    {
        $prazo = $this->criarPrazo();

        $this->actingAs($this->advogado)->post("/prazos/{$prazo->id}/status", ['status' => 'cumprido']);

        Feriado::create(['data' => '2026-03-16', 'descricao' => 'Feriado', 'abrangencia' => 'nacional']);

        (new RecalcularPrazosJob('2026-03-16', $this->advogado->id))->handle(
            app(PrazoRepository::class),
            app(CalendarioService::class)
        );

        $this->assertSame('2026-03-26', $prazo->fresh()->data_fatal->toDateString());
    }

    /* ---------------- Alertas (RF-8.1) ---------------- */

    public function test_alertas_sao_agendados_nas_antecedencias_previstas(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-23 06:30:00'));

        $prazo = $this->criarPrazo();
        $this->assertSame('2026-03-26', $prazo->data_fatal->toDateString());

        (new AgendarAlertasPrazoJob)->handle();

        // 23/03 -> 26/03 sao 3 dias: o alerta D-3 tem de existir.
        $this->assertDatabaseHas('notificacoes', [
            'advogado_id' => $this->advogado->id,
            'tipo' => 'prazo_d3',
            'chave_dedup' => "prazo:{$prazo->id}:d3",
        ]);

        // Rodar de novo nao duplica.
        (new AgendarAlertasPrazoJob)->handle();
        $this->assertSame(1, Notificacao::where('tipo', 'prazo_d3')->count());

        $this->travelBack();
    }

    public function test_mudanca_de_status_marca_o_evento_como_concluido(): void
    {
        $prazo = $this->criarPrazo();

        $this->actingAs($this->advogado)->post("/prazos/{$prazo->id}/status", ['status' => 'cumprido']);

        $this->assertSame('cumprido', $prazo->fresh()->status);
        $this->assertNotNull($prazo->fresh()->cumprido_em);
        $this->assertTrue((bool) Evento::where('eventable_id', $prazo->id)->first()->concluido);
    }

    public function test_simulacao_devolve_a_cadeia_sem_gravar(): void
    {
        $resposta = $this->actingAs($this->advogado)->getJson('/prazos/simular?'.http_build_query([
            'data_disponibilizacao' => '2026-03-04',
            'dias' => 15,
            'em_dias_uteis' => 1,
        ]));

        $resposta->assertOk()
            ->assertJsonPath('data_fatal', '2026-03-26')
            ->assertJsonPath('data_alvo', '2026-03-23');

        $this->assertSame(0, Prazo::count());
    }
}
