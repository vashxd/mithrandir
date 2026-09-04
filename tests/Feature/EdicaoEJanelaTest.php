<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\OabWatch;
use App\Models\Processo;
use App\Models\User;
use App\Services\Djen\IngestaoService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Edicao de cliente, vinculo tardio de caso e janela do DJEN por advogado.
 */
class EdicaoEJanelaTest extends TestCase
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
            'aceite_termo_em' => now(),
        ]);
    }

    /* ---------------- Edicao de cliente ---------------- */

    public function test_edita_todos_os_campos_do_cliente(): void
    {
        $cliente = Cliente::create([
            'advogado_id' => $this->advogado->id,
            'nome' => 'Maria Silva',
        ]);

        $this->actingAs($this->advogado)->patch("/clientes/{$cliente->id}", [
            'nome' => 'Maria da Silva Santos',
            'documento' => '529.982.247-25',
            'nascimento' => '1980-05-12',
            'contatos' => [
                ['tipo' => 'whatsapp', 'valor' => '(92) 99999-0000'],
                ['tipo' => 'email', 'valor' => 'maria@exemplo.test'],
            ],
            'endereco' => [
                'logradouro' => 'Rua das Flores',
                'numero' => '10',
                'bairro' => 'Centro',
                'cidade' => 'Manaus',
                'uf' => 'AM',
                'cep' => '69000-000',
            ],
            'origem' => 'Indicacao da vizinha',
            'observacoes' => 'Prefere ser chamada de dona Maria.',
        ])->assertRedirect();

        $cliente->refresh();

        $this->assertSame('Maria da Silva Santos', $cliente->nome);
        $this->assertSame('52998224725', $cliente->documento);
        $this->assertSame('1980-05-12', $cliente->nascimento->toDateString());
        $this->assertCount(2, $cliente->contatos);
        $this->assertSame('Manaus', $cliente->endereco['cidade']);
        $this->assertSame('Indicacao da vizinha', $cliente->origem);
        $this->assertStringContainsString('dona Maria', $cliente->observacoes);
    }

    public function test_ficha_do_cliente_entrega_o_documento_formatado_para_o_formulario(): void
    {
        $cliente = Cliente::create([
            'advogado_id' => $this->advogado->id,
            'nome' => 'Aco Dantas',
            'documento' => '11222333000181',
        ]);

        $resposta = $this->actingAs($this->advogado)->get("/clientes/{$cliente->id}");

        $this->assertSame(
            '11.222.333/0001-81',
            $resposta->viewData('page')['props']['cliente']['documento_formatado']
        );
    }

    public function test_nao_edita_cliente_de_outro_advogado(): void
    {
        $outro = User::create([
            'name' => 'Bruno', 'email' => 'b@b.test', 'password' => 'x',
            'oab' => '9', 'uf' => 'AM', 'aceite_termo_em' => now(),
        ]);

        $alheio = Cliente::create(['advogado_id' => $outro->id, 'nome' => 'Cliente do Bruno']);

        $this->actingAs($this->advogado)
            ->patch("/clientes/{$alheio->id}", ['nome' => 'Sequestrado'])
            ->assertForbidden();

        $this->assertSame('Cliente do Bruno', $alheio->fresh()->nome);
    }

    /* ---------------- Vinculo tardio do caso ---------------- */

    public function test_vincula_cliente_a_caso_ja_criado(): void
    {
        $processo = Processo::create([
            'advogado_id' => $this->advogado->id,
            'titulo' => 'Caso sem dono',
        ]);

        $cliente = Cliente::create([
            'advogado_id' => $this->advogado->id,
            'nome' => 'Maria Silva',
        ]);

        $this->assertNull($processo->cliente_id);

        $this->actingAs($this->advogado)->patch("/casos/{$processo->id}", [
            'cliente_id' => $cliente->id,
            'titulo' => 'Caso sem dono',
        ])->assertRedirect();

        $this->assertSame($cliente->id, $processo->fresh()->cliente_id);

        // O caso passa a aparecer na ficha do cliente.
        $resposta = $this->actingAs($this->advogado)->get("/clientes/{$cliente->id}");
        $this->assertCount(1, $resposta->viewData('page')['props']['processos']);
    }

    public function test_desvincula_cliente_do_caso(): void
    {
        $cliente = Cliente::create(['advogado_id' => $this->advogado->id, 'nome' => 'Maria']);
        $processo = Processo::create([
            'advogado_id' => $this->advogado->id,
            'cliente_id' => $cliente->id,
            'titulo' => 'Caso',
        ]);

        $this->actingAs($this->advogado)->patch("/casos/{$processo->id}", [
            'cliente_id' => null,
            'titulo' => 'Caso',
        ])->assertRedirect();

        $this->assertNull($processo->fresh()->cliente_id);
    }

    public function test_nao_vincula_cliente_de_outro_advogado_ao_proprio_caso(): void
    {
        $outro = User::create([
            'name' => 'Bruno', 'email' => 'b@b.test', 'password' => 'x',
            'oab' => '9', 'uf' => 'AM', 'aceite_termo_em' => now(),
        ]);

        $clienteAlheio = Cliente::create(['advogado_id' => $outro->id, 'nome' => 'Cliente do Bruno']);
        $processo = Processo::create(['advogado_id' => $this->advogado->id, 'titulo' => 'Caso']);

        $this->actingAs($this->advogado)
            ->patch("/casos/{$processo->id}", [
                'cliente_id' => $clienteAlheio->id,
                'titulo' => 'Caso',
            ])
            ->assertSessionHasErrors('cliente_id');

        $this->assertNull($processo->fresh()->cliente_id);
    }

    public function test_tela_do_caso_oferece_a_lista_de_clientes(): void
    {
        Cliente::create(['advogado_id' => $this->advogado->id, 'nome' => 'Maria']);
        $processo = Processo::create(['advogado_id' => $this->advogado->id, 'titulo' => 'Caso']);

        $resposta = $this->actingAs($this->advogado)->get("/casos/{$processo->id}");

        $this->assertCount(1, $resposta->viewData('page')['props']['clientes']);
    }

    /* ---------------- Janela do DJEN por advogado ---------------- */

    private function watch(): OabWatch
    {
        return OabWatch::create([
            'advogado_id' => $this->advogado->id,
            'termo' => '12345',
            'tipo' => 'oab',
            'uf' => 'AM',
            'ativo' => true,
            'ultima_sync_em' => now()->subDay(),
        ]);
    }

    private function diasDaUltimaBusca(): int
    {
        $dias = 0;

        Http::assertSent(function ($request) use (&$dias) {
            $inicio = CarbonImmutable::parse($request['dataDisponibilizacaoInicio']);
            $fim = CarbonImmutable::parse($request['dataDisponibilizacaoFim']);
            $dias = (int) $inicio->diffInDays($fim) + 1;

            return true;
        });

        return $dias;
    }

    public function test_advogado_configura_a_propria_janela(): void
    {
        $this->actingAs($this->advogado)->patch('/configuracoes', [
            'name' => 'Ana Souza',
            'oab' => '12345',
            'uf' => 'AM',
            'timezone' => 'America/Manaus',
            'buffer_padrao' => 3,
            'janela_djen_dias' => 15,
            'digest_horario' => '08:00',
            'percentual_imposto' => 11,
        ])->assertRedirect();

        $this->assertSame(15, $this->advogado->fresh()->janela_djen_dias);

        Http::fake(['comunicaapi.pje.jus.br/*' => Http::response(['count' => 0, 'items' => []])]);

        app(IngestaoService::class)->sincronizar($this->watch());

        $this->assertSame(15, $this->diasDaUltimaBusca());
    }

    public function test_sem_escolha_usa_o_padrao_do_sistema(): void
    {
        config(['mithrandir.djen.janela_dias' => 7]);
        $this->assertNull($this->advogado->janela_djen_dias);

        Http::fake(['comunicaapi.pje.jus.br/*' => Http::response(['count' => 0, 'items' => []])]);

        app(IngestaoService::class)->sincronizar($this->watch());

        $this->assertSame(7, $this->diasDaUltimaBusca());
    }

    /**
     * RF-1.3 nao e negociavel: por menor que seja a escolha, ontem entra.
     */
    public function test_janela_do_advogado_nao_fura_o_piso_de_ontem_mais_hoje(): void
    {
        $this->advogado->forceFill(['janela_djen_dias' => 2])->save();

        Http::fake(['comunicaapi.pje.jus.br/*' => Http::response(['count' => 0, 'items' => []])]);

        app(IngestaoService::class)->sincronizar($this->watch());

        $this->assertSame(2, $this->diasDaUltimaBusca());
    }

    public function test_recusa_janela_fora_dos_limites(): void
    {
        $base = [
            'name' => 'Ana Souza',
            'oab' => '12345',
            'uf' => 'AM',
            'timezone' => 'America/Manaus',
            'buffer_padrao' => 3,
            'digest_horario' => '08:00',
            'percentual_imposto' => 11,
        ];

        $this->actingAs($this->advogado)
            ->patch('/configuracoes', $base + ['janela_djen_dias' => 1])
            ->assertSessionHasErrors('janela_djen_dias');

        $this->actingAs($this->advogado)
            ->patch('/configuracoes', $base + ['janela_djen_dias' => 400])
            ->assertSessionHasErrors('janela_djen_dias');

        $this->assertNull($this->advogado->fresh()->janela_djen_dias);
    }

    /**
     * A janela escolhida nao pode encolher o backfill da primeira varredura -
     * conta nova ainda precisa abrir com historico.
     */
    public function test_primeira_varredura_respeita_o_backfill_maior(): void
    {
        config(['mithrandir.djen.janela_primeira_sync_dias' => 30]);
        $this->advogado->forceFill(['janela_djen_dias' => 7])->save();

        $watch = OabWatch::create([
            'advogado_id' => $this->advogado->id,
            'termo' => '12345',
            'tipo' => 'oab',
            'uf' => 'AM',
            'ativo' => true,
            'ultima_sync_em' => null,
        ]);

        Http::fake(['comunicaapi.pje.jus.br/*' => Http::response(['count' => 0, 'items' => []])]);

        app(IngestaoService::class)->sincronizar($watch);

        $this->assertSame(30, $this->diasDaUltimaBusca());
    }

    public function test_configuracoes_expoem_a_janela_e_o_padrao(): void
    {
        config(['mithrandir.djen.janela_dias' => 7]);

        $resposta = $this->actingAs($this->advogado)->get('/configuracoes');
        $perfil = $resposta->viewData('page')['props']['perfil'];

        $this->assertNull($perfil['janela_djen_dias']);
        $this->assertSame(7, $perfil['janela_djen_padrao']);
    }
}
