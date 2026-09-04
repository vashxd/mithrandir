<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\OabWatch;
use App\Models\Processo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M9 — conta, termo de uso e LGPD.
 */
class ContaEDadosTest extends TestCase
{
    use RefreshDatabase;

    private function dadosCadastro(array $sobrescreve = []): array
    {
        return array_merge([
            'name' => 'Ana Souza',
            'email' => 'ana@exemplo.test',
            'oab' => '12345',
            'uf' => 'AM',
            'password' => 'segredo-forte-123',
            'password_confirmation' => 'segredo-forte-123',
            'aceite_termo' => true,
        ], $sobrescreve);
    }

    public function test_cadastro_cria_conta_e_leva_ao_onboarding(): void
    {
        $this->post('/cadastrar', $this->dadosCadastro())
            ->assertRedirect('/onboarding');

        $advogado = User::first();
        $this->assertSame('12345', $advogado->oab);
        $this->assertSame('AM', $advogado->uf);
        $this->assertNotNull($advogado->aceite_termo_em);
        $this->assertSame(config('mithrandir.termo_versao'), $advogado->aceite_termo_versao);
        $this->assertAuthenticated();
    }

    public function test_cadastro_exige_aceite_do_termo(): void
    {
        $this->post('/cadastrar', $this->dadosCadastro(['aceite_termo' => false]))
            ->assertSessionHasErrors('aceite_termo');

        $this->assertSame(0, User::count());
    }

    /**
     * Armadilha da secao 7.1: tribunais gravam "123456" e "123456-O".
     */
    public function test_cadastro_ja_cria_as_variacoes_de_oab(): void
    {
        $this->post('/cadastrar', $this->dadosCadastro());

        $termos = OabWatch::where('tipo', 'oab')->pluck('termo')->all();

        $this->assertContains('12345', $termos);
        $this->assertContains('12345-O', $termos);

        // Busca por nome nasce desligada: gera falso positivo demais.
        $porNome = OabWatch::where('tipo', 'nome')->first();
        $this->assertNotNull($porNome);
        $this->assertFalse((bool) $porNome->ativo);
    }

    public function test_sem_aceite_do_termo_o_app_redireciona_para_o_termo(): void
    {
        $advogado = User::create([
            'name' => 'Ana', 'email' => 'a@b.test', 'password' => 'x',
            'oab' => '1', 'uf' => 'AM', 'aceite_termo_em' => null,
        ]);

        $this->actingAs($advogado)->get('/')->assertRedirect('/termo');

        $this->actingAs($advogado)->post('/termo', ['aceite' => true])->assertRedirect('/');

        $this->assertNotNull($advogado->fresh()->aceite_termo_em);
    }

    public function test_visitante_nao_acessa_a_tela_hoje(): void
    {
        $this->get('/')->assertRedirect('/entrar');
    }

    /* ---------------- LGPD ---------------- */

    private function advogadoComDados(): User
    {
        $advogado = User::create([
            'name' => 'Ana', 'email' => 'ana@exemplo.test', 'password' => 'x',
            'oab' => '12345', 'uf' => 'AM', 'aceite_termo_em' => now(),
        ]);

        $cliente = Cliente::create(['advogado_id' => $advogado->id, 'nome' => 'Maria Silva']);
        Processo::create([
            'advogado_id' => $advogado->id,
            'cliente_id' => $cliente->id,
            'titulo' => 'Aposentadoria da Maria',
        ]);

        OabWatch::create([
            'advogado_id' => $advogado->id, 'termo' => '12345', 'tipo' => 'oab', 'uf' => 'AM',
        ]);

        return $advogado;
    }

    public function test_exportacao_lgpd_devolve_um_zip(): void
    {
        $advogado = $this->advogadoComDados();

        $resposta = $this->actingAs($advogado)->get('/configuracoes/exportar');

        $resposta->assertOk();
        $resposta->assertHeader('content-type', 'application/zip');

        $conteudo = $resposta->streamedContent();
        $this->assertNotEmpty($conteudo);
        // Assinatura de arquivo ZIP.
        $this->assertSame('PK', substr($conteudo, 0, 2));

        $this->assertDatabaseHas('auditoria', [
            'advogado_id' => $advogado->id,
            'acao' => 'exportacao_lgpd',
        ]);
    }

    public function test_exclusao_de_conta_tem_carencia_e_desliga_o_radar(): void
    {
        $advogado = $this->advogadoComDados();

        $this->actingAs($advogado)
            ->post('/configuracoes/excluir-conta', ['confirmacao' => 'EXCLUIR'])
            ->assertRedirect();

        $this->assertNotNull($advogado->fresh()->exclusao_solicitada_em);

        // Enquanto a conta espera a carencia, o radar nao pode continuar rodando.
        $this->assertSame(0, OabWatch::where('advogado_id', $advogado->id)->where('ativo', true)->count());

        $this->actingAs($advogado)->post('/configuracoes/cancelar-exclusao');

        $this->assertNull($advogado->fresh()->exclusao_solicitada_em);
        $this->assertSame(1, OabWatch::where('advogado_id', $advogado->id)->where('ativo', true)->count());
    }

    public function test_exclusao_exige_a_palavra_de_confirmacao(): void
    {
        $advogado = $this->advogadoComDados();

        $this->actingAs($advogado)
            ->post('/configuracoes/excluir-conta', ['confirmacao' => 'sim'])
            ->assertSessionHasErrors('confirmacao');

        $this->assertNull($advogado->fresh()->exclusao_solicitada_em);
    }

    public function test_um_advogado_nao_ve_os_dados_do_outro(): void
    {
        $primeiro = $this->advogadoComDados();

        $segundo = User::create([
            'name' => 'Bruno', 'email' => 'bruno@exemplo.test', 'password' => 'x',
            'oab' => '999', 'uf' => 'AM', 'aceite_termo_em' => now(),
        ]);

        $processoAlheio = Processo::where('advogado_id', $primeiro->id)->first();

        $this->actingAs($segundo)->get("/casos/{$processoAlheio->id}")->assertForbidden();

        $clienteAlheio = Cliente::where('advogado_id', $primeiro->id)->first();
        $this->actingAs($segundo)->get("/clientes/{$clienteAlheio->id}")->assertForbidden();
    }

    public function test_configuracoes_salvam_buffer_fuso_e_horario_do_digest(): void
    {
        $advogado = $this->advogadoComDados();

        $this->actingAs($advogado)->patch('/configuracoes', [
            'name' => 'Ana Souza',
            'oab' => '12345',
            'uf' => 'AM',
            'timezone' => 'America/Manaus',
            'buffer_padrao' => 5,
            'digest_horario' => '07:30',
            'percentual_imposto' => 11.5,
            'preferencias_notificacao' => ['digest' => false],
        ])->assertRedirect();

        $advogado->refresh();

        $this->assertSame(5, $advogado->buffer_padrao);
        $this->assertSame('America/Manaus', $advogado->timezone);
        $this->assertStringStartsWith('07:30', $advogado->digest_horario);
        $this->assertFalse($advogado->querReceber('digest'));
        $this->assertTrue($advogado->querReceber('prazo'));
    }
}
