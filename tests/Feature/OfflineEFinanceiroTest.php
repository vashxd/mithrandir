<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Honorario;
use App\Models\OutboxItem;
use App\Models\Parcela;
use App\Models\Prazo;
use App\Models\Processo;
use App\Models\User;
use App\Support\NumeroCnj;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sincronizacao offline (secao 10) e financeiro (M7).
 */
class OfflineEFinanceiroTest extends TestCase
{
    use RefreshDatabase;

    private User $advogado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->advogado = User::create([
            'name' => 'Ana', 'email' => 'ana@exemplo.test', 'password' => 'x',
            'oab' => '12345', 'uf' => 'AM', 'aceite_termo_em' => now(),
            'percentual_imposto' => 10,
        ]);
    }

    /* ---------------- Outbox ---------------- */

    private function item(array $sobrescreve = []): array
    {
        return array_merge([
            'client_id' => (string) Str::uuid(),
            'entidade' => 'clientes',
            'operacao' => 'create',
            'payload' => ['nome' => 'Maria Silva'],
            'criado_em' => now()->toIso8601String(),
        ], $sobrescreve);
    }

    public function test_outbox_aplica_criacao_feita_offline(): void
    {
        $item = $this->item();

        $this->actingAs($this->advogado)
            ->postJson('/sync/outbox', ['itens' => [$item]])
            ->assertOk()
            ->assertJsonPath('resultados.0.status', 'aplicado');

        $cliente = Cliente::first();
        $this->assertSame('Maria Silva', $cliente->nome);
        $this->assertSame($this->advogado->id, $cliente->advogado_id);
    }

    public function test_reenviar_o_mesmo_item_nao_duplica(): void
    {
        $item = $this->item();

        $this->actingAs($this->advogado)->postJson('/sync/outbox', ['itens' => [$item]]);

        // O celular reconectou e mandou o lote de novo.
        $this->actingAs($this->advogado)
            ->postJson('/sync/outbox', ['itens' => [$item]])
            ->assertOk()
            ->assertJsonPath('resultados.0.status', 'aplicado');

        $this->assertSame(1, Cliente::count());
        $this->assertSame(1, OutboxItem::count());
    }

    /**
     * Regra da secao 10: prazo e financeiro nunca resolvem sozinhos.
     */
    public function test_entidade_critica_vira_item_de_revisao_em_vez_de_aplicar(): void
    {
        $this->actingAs($this->advogado)
            ->postJson('/sync/outbox', [
                'itens' => [$this->item([
                    'entidade' => 'prazos',
                    'payload' => ['tipo' => 'Contestacao', 'data_fatal' => '2026-03-26'],
                ])],
            ])
            ->assertOk()
            ->assertJsonPath('resultados.0.status', 'conflito');

        $this->assertSame(0, Prazo::count());
        $this->assertSame('conflito', OutboxItem::first()->status);
    }

    public function test_outbox_nao_aplica_alteracao_em_registro_de_outro_advogado(): void
    {
        $outro = User::create([
            'name' => 'Bruno', 'email' => 'b@b.test', 'password' => 'x',
            'oab' => '9', 'uf' => 'AM', 'aceite_termo_em' => now(),
        ]);

        $alheio = Cliente::create(['advogado_id' => $outro->id, 'nome' => 'Cliente do Bruno']);

        $this->actingAs($this->advogado)
            ->postJson('/sync/outbox', [
                'itens' => [$this->item([
                    'operacao' => 'update',
                    'payload' => ['id' => $alheio->id, 'nome' => 'Sequestrado'],
                ])],
            ])
            ->assertOk()
            ->assertJsonPath('resultados.0.status', 'erro');

        $this->assertSame('Cliente do Bruno', $alheio->fresh()->nome);
    }

    public function test_snapshot_traz_o_espelho_de_leitura(): void
    {
        $cliente = Cliente::create(['advogado_id' => $this->advogado->id, 'nome' => 'Maria']);
        Processo::create([
            'advogado_id' => $this->advogado->id,
            'cliente_id' => $cliente->id,
            'titulo' => 'Aposentadoria',
        ]);

        $this->actingAs($this->advogado)
            ->getJson('/sync/snapshot')
            ->assertOk()
            ->assertJsonStructure(['gerado_em', 'eventos', 'prazos', 'processos', 'clientes', 'publicacoes_nao_triadas'])
            ->assertJsonPath('clientes.0.nome', 'Maria')
            ->assertJsonPath('processos.0.rotulo', 'Aposentadoria');
    }

    /* ---------------- Financeiro ---------------- */

    public function test_honorario_parcelado_gera_as_parcelas_com_vencimento_mensal(): void
    {
        $processo = Processo::create([
            'advogado_id' => $this->advogado->id, 'titulo' => 'Caso',
        ]);

        $this->actingAs($this->advogado)->post('/financeiro/honorarios', [
            'processo_id' => $processo->id,
            'tipo' => 'parcelado',
            'valor' => 1000,
            'qtd_parcelas' => 3,
            'primeiro_vencimento' => '2026-04-10',
        ])->assertRedirect();

        $parcelas = Parcela::orderBy('numero')->get();

        $this->assertCount(3, $parcelas);
        $this->assertSame('2026-04-10', $parcelas[0]->vencimento->toDateString());
        $this->assertSame('2026-05-10', $parcelas[1]->vencimento->toDateString());
        $this->assertSame('2026-06-10', $parcelas[2]->vencimento->toDateString());

        // A soma tem de fechar exatamente com o contratado.
        $this->assertSame(1000.0, (float) $parcelas->sum('valor'));
    }

    public function test_divisao_com_dizima_fecha_no_centavo(): void
    {
        $this->actingAs($this->advogado)->post('/financeiro/honorarios', [
            'tipo' => 'parcelado',
            'valor' => 1000,
            'qtd_parcelas' => 3,
            'primeiro_vencimento' => '2026-04-10',
        ]);

        $parcelas = Parcela::orderBy('numero')->get();

        $this->assertSame(333.33, (float) $parcelas[0]->valor);
        $this->assertSame(333.34, (float) $parcelas[2]->valor);
        $this->assertSame(1000.0, (float) $parcelas->sum('valor'));
    }

    public function test_honorario_so_de_exito_nao_gera_parcela(): void
    {
        $this->actingAs($this->advogado)->post('/financeiro/honorarios', [
            'tipo' => 'exito',
            'valor' => 0,
            'percentual_exito' => 30,
            'qtd_parcelas' => 1,
        ])->assertRedirect();

        $this->assertSame(1, Honorario::count());
        $this->assertSame(0, Parcela::count());
    }

    public function test_baixa_e_estorno_de_parcela(): void
    {
        $this->actingAs($this->advogado)->post('/financeiro/honorarios', [
            'tipo' => 'fixo', 'valor' => 500, 'qtd_parcelas' => 1, 'primeiro_vencimento' => '2026-04-10',
        ]);

        $parcela = Parcela::first();

        $this->actingAs($this->advogado)->post("/financeiro/parcelas/{$parcela->id}/baixar");
        $this->assertNotNull($parcela->fresh()->pago_em);
        $this->assertSame(500.0, (float) $parcela->fresh()->valor_pago);
        $this->assertSame('pago', $parcela->fresh()->situacao);

        $this->actingAs($this->advogado)->post("/financeiro/parcelas/{$parcela->id}/baixar", ['estornar' => true]);
        $this->assertNull($parcela->fresh()->pago_em);
    }

    public function test_painel_soma_a_receber_vencido_e_provisao_de_imposto(): void
    {
        $honorario = Honorario::create([
            'advogado_id' => $this->advogado->id, 'tipo' => 'parcelado', 'valor' => 900, 'qtd_parcelas' => 3,
        ]);

        $hoje = now();

        // Uma vencida, uma a vencer no mes, uma ja paga no mes.
        Parcela::create([
            'advogado_id' => $this->advogado->id, 'honorario_id' => $honorario->id,
            'numero' => 1, 'valor' => 300, 'vencimento' => $hoje->copy()->subDays(10),
        ]);
        Parcela::create([
            'advogado_id' => $this->advogado->id, 'honorario_id' => $honorario->id,
            'numero' => 2, 'valor' => 300, 'vencimento' => $hoje->copy()->startOfMonth()->addDays(20),
        ]);
        Parcela::create([
            'advogado_id' => $this->advogado->id, 'honorario_id' => $honorario->id,
            'numero' => 3, 'valor' => 300, 'vencimento' => $hoje->copy()->startOfMonth(),
            'pago_em' => $hoje->copy()->startOfMonth(), 'valor_pago' => 300,
        ]);

        $resposta = $this->actingAs($this->advogado)->get('/financeiro');
        $resposta->assertOk();

        $painel = $resposta->viewData('page')['props']['painel'];

        $this->assertSame(300.0, $painel['vencido']);
        $this->assertSame(1, $painel['vencido_qtd']);
        $this->assertSame(300.0, $painel['recebido_mes']);
        // RF-7.5: 10% sobre o que entrou.
        $this->assertSame(30.0, $painel['provisao_imposto']);
    }

    /* ---------------- Numero CNJ (RF-4.1) ---------------- */

    public function test_numero_cnj_invalido_e_recusado(): void
    {
        $this->actingAs($this->advogado)
            ->post('/casos', ['numero_cnj' => '0801234-56.2026.8.04.0001', 'titulo' => 'Caso'])
            ->assertSessionHasErrors('numero_cnj');

        $this->assertSame(0, Processo::count());
    }

    public function test_numero_cnj_valido_e_aceito_e_normalizado(): void
    {
        // Numero montado com o DV correto pelo proprio algoritmo.
        $semDv = '0801234'.'2026'.'8'.'04'.'0001';
        $dv = NumeroCnj::dvEsperado($semDv);
        $completo = '0801234'.$dv.'2026'.'8'.'04'.'0001';

        $this->assertTrue(NumeroCnj::valido($completo));

        $this->actingAs($this->advogado)
            ->post('/casos', [
                'numero_cnj' => NumeroCnj::formatar($completo),
                'titulo' => 'Caso valido',
            ])
            ->assertRedirect();

        $this->assertSame($completo, Processo::first()->numero_cnj);
    }
}
