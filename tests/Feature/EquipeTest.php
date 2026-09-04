<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Membro;
use App\Models\Prazo;
use App\Models\Processo;
use App\Models\User;
use App\Support\Contexto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Equipe: acesso por caso, papeis e conferencia de prazo.
 *
 * A regra central sob teste: colaborador nao fecha prazo. Quem responde pela
 * perda perante a OAB e o titular, entao "cumpri" vindo do estagiario deixa o
 * prazo aberto ate a conferencia.
 */
class EquipeTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private User $estagiario;

    private Processo $casoLiberado;

    private Processo $casoReservado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->titular = User::create([
            'name' => 'Ana Souza', 'email' => 'ana@exemplo.test', 'password' => 'segredo123',
            'oab' => '12345', 'uf' => 'AM', 'aceite_termo_em' => now(),
        ]);

        $this->estagiario = User::create([
            'name' => 'Pedro Estagiario', 'email' => 'pedro@exemplo.test', 'password' => 'segredo123',
            'oab' => null, 'uf' => 'AM', 'aceite_termo_em' => now(),
        ]);

        $cliente = Cliente::create(['advogado_id' => $this->titular->id, 'nome' => 'Maria Silva']);

        $this->casoLiberado = Processo::create([
            'advogado_id' => $this->titular->id,
            'cliente_id' => $cliente->id,
            'titulo' => 'Caso liberado',
        ]);

        $this->casoReservado = Processo::create([
            'advogado_id' => $this->titular->id,
            'titulo' => 'Caso reservado',
        ]);
    }

    private function vincular(string $papel = 'estagiario', bool $acessoTotal = false): Membro
    {
        $membro = Membro::create([
            'titular_id' => $this->titular->id,
            'usuario_id' => $this->estagiario->id,
            'email' => $this->estagiario->email,
            'nome' => $this->estagiario->name,
            'papel' => $papel,
            'acesso_total' => $acessoTotal,
            'ativo' => true,
            'aceito_em' => now(),
        ]);

        if (! $acessoTotal) {
            $membro->processos()->sync([$this->casoLiberado->id]);
        }

        return $membro;
    }

    /** Entra como o colaborador, dentro do espaco do titular. */
    private function comoColaborador(): self
    {
        $this->actingAs($this->estagiario)
            ->withSession([Contexto::CHAVE_SESSAO => $this->titular->id]);

        return $this;
    }

    private function prazo(Processo $processo): Prazo
    {
        return Prazo::create([
            'advogado_id' => $this->titular->id,
            'processo_id' => $processo->id,
            'tipo' => 'Contestacao',
            'dias' => 15,
            'em_dias_uteis' => true,
            'data_disponibilizacao' => '2026-03-04',
            'data_publicacao' => '2026-03-05',
            'data_inicio' => '2026-03-06',
            'data_fatal' => '2026-03-26',
            'data_alvo' => '2026-03-23',
            'status' => 'aberto',
        ]);
    }

    /* ---------------- Convite ---------------- */

    public function test_titular_convida_e_o_convite_vira_link(): void
    {
        $this->actingAs($this->titular)->post('/equipe', [
            'nome' => 'Pedro',
            'email' => 'pedro@exemplo.test',
            'papel' => 'estagiario',
            'acesso_total' => false,
            'processos' => [$this->casoLiberado->id],
        ])->assertRedirect();

        $membro = Membro::first();

        $this->assertSame($this->titular->id, $membro->titular_id);
        $this->assertSame('estagiario', $membro->papel);
        $this->assertNotNull($membro->token_convite);
        $this->assertTrue($membro->pendente());
        $this->assertSame([$this->casoLiberado->id], $membro->processos->pluck('id')->all());
    }

    public function test_colaborador_nao_gere_a_equipe(): void
    {
        $this->vincular('advogado', acessoTotal: true);

        $this->comoColaborador()->get('/equipe')->assertForbidden();

        $this->comoColaborador()->post('/equipe', [
            'nome' => 'Outro', 'email' => 'outro@exemplo.test', 'papel' => 'estagiario',
        ])->assertForbidden();
    }

    public function test_aceite_exige_o_email_do_convite(): void
    {
        $this->actingAs($this->titular)->post('/equipe', [
            'nome' => 'Pedro', 'email' => 'pedro@exemplo.test', 'papel' => 'estagiario',
            'processos' => [$this->casoLiberado->id],
        ]);

        $token = Membro::first()->token_convite;

        $intruso = User::create([
            'name' => 'Intruso', 'email' => 'intruso@exemplo.test', 'password' => 'x',
            'uf' => 'AM', 'aceite_termo_em' => now(),
        ]);

        $this->actingAs($intruso)->post("/convite/{$token}")->assertRedirect();
        $this->assertTrue(Membro::first()->pendente());

        $this->actingAs($this->estagiario)->post("/convite/{$token}")->assertRedirect('/');
        $this->assertTrue(Membro::first()->fresh()->aceito());
    }

    /* ---------------- Recorte por caso ---------------- */

    public function test_colaborador_so_ve_os_casos_liberados(): void
    {
        $this->vincular();

        $resposta = $this->comoColaborador()->get('/casos');
        $ids = collect($resposta->viewData('page')['props']['processos']['data'])->pluck('id');

        $this->assertContains($this->casoLiberado->id, $ids);
        $this->assertNotContains($this->casoReservado->id, $ids);
    }

    public function test_colaborador_nao_abre_caso_fora_do_recorte(): void
    {
        $this->vincular();

        $this->comoColaborador()->get("/casos/{$this->casoLiberado->id}")->assertOk();
        $this->comoColaborador()->get("/casos/{$this->casoReservado->id}")->assertForbidden();
    }

    public function test_colaborador_so_ve_prazos_dos_casos_liberados(): void
    {
        $this->vincular();
        $liberado = $this->prazo($this->casoLiberado);
        $reservado = $this->prazo($this->casoReservado);

        $resposta = $this->comoColaborador()->get('/prazos');
        $ids = collect($resposta->viewData('page')['props']['prazos']['data'])->pluck('id');

        $this->assertContains($liberado->id, $ids);
        $this->assertNotContains($reservado->id, $ids);

        $this->comoColaborador()->get("/prazos/{$reservado->id}")->assertForbidden();
    }

    public function test_acesso_total_enxerga_a_carteira_inteira(): void
    {
        $this->vincular('advogado', acessoTotal: true);

        $resposta = $this->comoColaborador()->get('/casos');
        $ids = collect($resposta->viewData('page')['props']['processos']['data'])->pluck('id');

        $this->assertContains($this->casoLiberado->id, $ids);
        $this->assertContains($this->casoReservado->id, $ids);
    }

    public function test_cliente_so_aparece_se_o_caso_dele_foi_liberado(): void
    {
        $this->vincular();

        $outroCliente = Cliente::create(['advogado_id' => $this->titular->id, 'nome' => 'Cliente reservado']);
        $this->casoReservado->update(['cliente_id' => $outroCliente->id]);

        $resposta = $this->comoColaborador()->get('/clientes');
        $nomes = collect($resposta->viewData('page')['props']['clientes']['data'])->pluck('nome');

        $this->assertContains('Maria Silva', $nomes);
        $this->assertNotContains('Cliente reservado', $nomes);
    }

    /* ---------------- A trava principal ---------------- */

    public function test_estagiario_nao_fecha_prazo(): void
    {
        $this->vincular();
        $prazo = $this->prazo($this->casoLiberado);

        $this->comoColaborador()
            ->post("/prazos/{$prazo->id}/status", ['status' => 'cumprido'])
            ->assertForbidden();

        $this->assertSame('aberto', $prazo->fresh()->status);
    }

    public function test_estagiario_nao_ajusta_data_fatal(): void
    {
        $this->vincular();
        $prazo = $this->prazo($this->casoLiberado);

        $this->comoColaborador()
            ->post("/prazos/{$prazo->id}/ajustar", [
                'data_fatal' => '2026-03-30',
                'justificativa' => 'Tentando mexer no que nao devo.',
            ])
            ->assertForbidden();

        $this->assertSame('2026-03-26', $prazo->fresh()->data_fatal->toDateString());
    }

    public function test_estagiario_pede_conferencia_e_o_prazo_segue_aberto(): void
    {
        $this->vincular();
        $prazo = $this->prazo($this->casoLiberado);

        $this->comoColaborador()
            ->post("/prazos/{$prazo->id}/conferencia", ['observacao' => 'Minuta pronta na pasta.'])
            ->assertRedirect();

        $prazo->refresh();

        $this->assertTrue((bool) $prazo->aguardando_conferencia);
        $this->assertSame($this->estagiario->id, $prazo->conferencia_solicitada_por);
        $this->assertStringContainsString('Minuta pronta', $prazo->conferencia_observacao);

        // O que mais importa: NAO fechou, e continua contando como risco.
        $this->assertNotSame('cumprido', $prazo->status);
        $this->assertNotSame('concluido', $prazo->criticidade);

        $this->assertDatabaseHas('notificacoes', [
            'advogado_id' => $this->titular->id,
            'tipo' => 'conferencia_pendente',
        ]);
    }

    public function test_titular_confirma_a_conferencia_e_fecha_o_prazo(): void
    {
        $this->vincular();
        $prazo = $this->prazo($this->casoLiberado);

        $this->comoColaborador()->post("/prazos/{$prazo->id}/conferencia");

        $this->actingAs($this->titular)
            ->post("/prazos/{$prazo->id}/conferencia/responder", ['aprovado' => true])
            ->assertRedirect();

        $prazo->refresh();

        $this->assertSame('cumprido', $prazo->status);
        $this->assertFalse((bool) $prazo->aguardando_conferencia);
        $this->assertNotNull($prazo->cumprido_em);
    }

    public function test_titular_devolve_para_ajuste_e_o_prazo_reabre(): void
    {
        $this->vincular();
        $prazo = $this->prazo($this->casoLiberado);

        $this->comoColaborador()->post("/prazos/{$prazo->id}/conferencia");

        $this->actingAs($this->titular)->post("/prazos/{$prazo->id}/conferencia/responder", [
            'aprovado' => false,
            'observacao' => 'Faltou juntar o CNIS.',
        ]);

        $prazo->refresh();

        $this->assertSame('em_andamento', $prazo->status);
        $this->assertFalse((bool) $prazo->aguardando_conferencia);
        $this->assertStringContainsString('CNIS', $prazo->conferencia_observacao);
    }

    public function test_estagiario_nao_responde_a_propria_conferencia(): void
    {
        $this->vincular();
        $prazo = $this->prazo($this->casoLiberado);

        $this->comoColaborador()->post("/prazos/{$prazo->id}/conferencia");

        $this->comoColaborador()
            ->post("/prazos/{$prazo->id}/conferencia/responder", ['aprovado' => true])
            ->assertForbidden();

        $this->assertNotSame('cumprido', $prazo->fresh()->status);
    }

    public function test_advogado_da_equipe_pode_fechar_prazo(): void
    {
        $this->vincular('advogado', acessoTotal: true);
        $prazo = $this->prazo($this->casoLiberado);

        $this->comoColaborador()
            ->post("/prazos/{$prazo->id}/status", ['status' => 'cumprido'])
            ->assertRedirect();

        $this->assertSame('cumprido', $prazo->fresh()->status);
    }

    /* ---------------- Responsavel ---------------- */

    public function test_estagiario_assume_prazo_para_si_mas_nao_distribui(): void
    {
        $this->vincular();
        $prazo = $this->prazo($this->casoLiberado);

        $this->comoColaborador()
            ->post("/prazos/{$prazo->id}/responsavel", ['responsavel_id' => $this->estagiario->id])
            ->assertRedirect();

        $this->assertSame($this->estagiario->id, $prazo->fresh()->responsavel_id);

        // Nao pode empurrar trabalho para o titular.
        $this->comoColaborador()
            ->post("/prazos/{$prazo->id}/responsavel", ['responsavel_id' => $this->titular->id])
            ->assertForbidden();
    }

    public function test_titular_distribui_para_quem_esta_na_equipe(): void
    {
        $this->vincular();
        $prazo = $this->prazo($this->casoLiberado);

        $this->actingAs($this->titular)
            ->post("/prazos/{$prazo->id}/responsavel", ['responsavel_id' => $this->estagiario->id])
            ->assertRedirect();

        $this->assertSame($this->estagiario->id, $prazo->fresh()->responsavel_id);
    }

    public function test_nao_atribui_prazo_a_quem_nao_e_da_equipe(): void
    {
        $this->vincular();
        $prazo = $this->prazo($this->casoLiberado);

        $estranho = User::create([
            'name' => 'Estranho', 'email' => 'estranho@exemplo.test', 'password' => 'x',
            'uf' => 'AM', 'aceite_termo_em' => now(),
        ]);

        $this->actingAs($this->titular)
            ->post("/prazos/{$prazo->id}/responsavel", ['responsavel_id' => $estranho->id])
            ->assertForbidden();
    }

    /* ---------------- Financeiro e radar ---------------- */

    public function test_estagiario_nao_ve_financeiro(): void
    {
        $this->vincular();

        $this->assertFalse(contexto()->para($this->estagiario, $this->titular->id)->pode('financeiro.ver'));
    }

    public function test_estagiario_nao_gere_o_radar_nem_a_conta(): void
    {
        $this->vincular();
        $ctx = contexto()->para($this->estagiario, $this->titular->id);

        $this->assertFalse($ctx->pode('radar.gerir'));
        $this->assertFalse($ctx->pode('conta.editar'));
        $this->assertFalse($ctx->pode('dados.exportar'));
        $this->assertTrue($ctx->pode('publicacao.triar'));
    }

    /* ---------------- Contexto ---------------- */

    public function test_acesso_revogado_devolve_a_pessoa_ao_proprio_espaco(): void
    {
        $membro = $this->vincular();
        $membro->forceFill(['ativo' => false])->save();

        $ctx = contexto()->para($this->estagiario, $this->titular->id);

        $this->assertSame($this->estagiario->id, $ctx->advogadoId());
        $this->assertTrue($ctx->ehTitular());
    }

    public function test_nao_entra_em_espaco_sem_convite(): void
    {
        $this->actingAs($this->estagiario)
            ->post('/contexto', ['advogado_id' => $this->titular->id])
            ->assertForbidden();
    }

    public function test_troca_de_espaco_com_vinculo_ativo(): void
    {
        $this->vincular();

        $this->actingAs($this->estagiario)
            ->post('/contexto', ['advogado_id' => $this->titular->id])
            ->assertRedirect('/');

        $this->assertSame($this->titular->id, session(Contexto::CHAVE_SESSAO));
    }

    public function test_titular_continua_dono_do_que_a_equipe_cria(): void
    {
        $this->vincular('advogado', acessoTotal: true);

        $this->comoColaborador()->post('/clientes', ['nome' => 'Cliente novo'])->assertRedirect();

        $cliente = Cliente::where('nome', 'Cliente novo')->first();

        // O dado nasce no espaco do titular, nao na conta de quem digitou.
        $this->assertSame($this->titular->id, $cliente->advogado_id);
    }
}
