<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Notificacao;
use App\Models\OabWatch;
use App\Models\Publicacao;
use App\Models\User;
use App\Services\Djen\IngestaoService;
use App\Services\Djen\VolumeAnormalException;
use App\Support\Documento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Vigilancia por nome de cliente (parte do processo).
 *
 * O DJEN nao filtra por CPF/CNPJ - verificado contra a API real com sete nomes
 * de parametro diferentes, todos ignorados. A busca e por nome, e por isso tudo
 * que entra por aqui vem marcado como origem "cliente" e passa pela triagem.
 */
class VigilanciaClienteTest extends TestCase
{
    use RefreshDatabase;

    private User $advogado;

    private Cliente $cliente;

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

        $this->cliente = Cliente::create([
            'advogado_id' => $this->advogado->id,
            'nome' => 'Elias da Silva Correa',
            'documento' => '52998224725',
        ]);
    }

    /**
     * @param  array<string, mixed>  $sobrescreve
     * @return array<string, mixed>
     */
    private function item(array $sobrescreve = []): array
    {
        return array_merge([
            'id' => 900001,
            'hash' => 'hashdemo0001',
            'data_disponibilizacao' => '2026-09-03',
            'siglaTribunal' => 'TJAM',
            'nomeOrgao' => '1a Vara da Fazenda Publica',
            'texto' => 'Intimacao da parte Elias da Silva Correa com prazo de 15 dias uteis.',
            'numero_processo' => '01449960820268041000',
            'numeroComunicacao' => 1,
            'destinatarios' => [['nome' => 'ELIAS DA SILVA CORREA', 'polo' => 'A']],
        ], $sobrescreve);
    }

    /* ---------------- Documento: com e sem ponto ---------------- */

    public function test_cpf_e_gravado_limpo_venha_como_vier(): void
    {
        foreach (['529.982.247-25', '52998224725', ' 529.982.247-25 '] as $digitado) {
            $cliente = Cliente::create([
                'advogado_id' => $this->advogado->id,
                'nome' => 'Teste '.$digitado,
            ]);

            $this->actingAs($this->advogado)->patch("/clientes/{$cliente->id}", [
                'nome' => $cliente->nome,
                'documento' => $digitado,
            ])->assertRedirect();

            $this->assertSame('52998224725', $cliente->fresh()->documento);
        }
    }

    public function test_cnpj_com_pontuacao_tambem_normaliza(): void
    {
        $this->actingAs($this->advogado)->patch("/clientes/{$this->cliente->id}", [
            'nome' => $this->cliente->nome,
            'documento' => '11.222.333/0001-81',
        ])->assertRedirect();

        $this->assertSame('11222333000181', $this->cliente->fresh()->documento);
    }

    public function test_documento_e_exibido_formatado(): void
    {
        $this->assertSame('529.982.247-25', Documento::formatar('52998224725'));
        $this->assertSame('11.222.333/0001-81', Documento::formatar('11222333000181'));
        $this->assertSame('cpf', Documento::tipo('529.982.247-25'));
        $this->assertSame('cnpj', Documento::tipo('11.222.333/0001-81'));
    }

    public function test_valida_digito_de_cpf_e_cnpj(): void
    {
        $this->assertTrue(Documento::valido('529.982.247-25'));
        $this->assertFalse(Documento::valido('529.982.247-26'));
        $this->assertFalse(Documento::valido('111.111.111-11'));

        $this->assertTrue(Documento::valido('11.222.333/0001-81'));
        $this->assertFalse(Documento::valido('11.222.333/0001-82'));
    }

    /* ---------------- Vigilancia ---------------- */

    public function test_cliente_nasce_sem_vigilancia(): void
    {
        $resposta = $this->actingAs($this->advogado)->get("/clientes/{$this->cliente->id}");

        $resposta->assertOk();
        $this->assertFalse($resposta->viewData('page')['props']['vigilancia']['ativa']);
        $this->assertSame(0, OabWatch::where('tipo', 'cliente')->count());
    }

    public function test_ligar_vigilancia_cria_termo_do_tipo_cliente(): void
    {
        $this->actingAs($this->advogado)
            ->post("/clientes/{$this->cliente->id}/vigilancia")
            ->assertRedirect();

        $watch = OabWatch::where('tipo', 'cliente')->first();

        $this->assertNotNull($watch);
        $this->assertSame($this->cliente->id, $watch->cliente_id);
        $this->assertSame('Elias da Silva Correa', $watch->termo);
        $this->assertTrue((bool) $watch->ativo);
    }

    public function test_alternar_liga_e_desliga_sem_perder_publicacoes(): void
    {
        $this->actingAs($this->advogado)->post("/clientes/{$this->cliente->id}/vigilancia");
        $this->actingAs($this->advogado)->post("/clientes/{$this->cliente->id}/vigilancia");

        $watch = OabWatch::where('tipo', 'cliente')->first();
        $this->assertFalse((bool) $watch->ativo);
        // O termo continua existindo: desligar nao apaga historico.
        $this->assertSame(1, OabWatch::where('tipo', 'cliente')->count());
    }

    public function test_busca_usa_nome_parte_e_nao_o_documento(): void
    {
        $this->actingAs($this->advogado)->post("/clientes/{$this->cliente->id}/vigilancia");
        $watch = OabWatch::where('tipo', 'cliente')->first();

        Http::fake(['comunicaapi.pje.jus.br/*' => Http::response(['count' => 0, 'items' => []])]);

        app(IngestaoService::class)->sincronizar($watch);

        Http::assertSent(function ($request) {
            // O DJEN ignora parametro de documento; mandar CPF seria pedir o
            // diario inteiro achando que se esta filtrando.
            return $request['nomeParte'] === 'Elias da Silva Correa'
                && ! isset($request['numeroDocumento'])
                && ! isset($request['cpfCnpj'])
                && ! isset($request['numeroOab']);
        });
    }

    public function test_publicacao_do_cliente_e_marcada_com_a_origem(): void
    {
        $this->actingAs($this->advogado)->post("/clientes/{$this->cliente->id}/vigilancia");
        $watch = OabWatch::where('tipo', 'cliente')->first();

        Http::fake([
            'comunicaapi.pje.jus.br/*' => Http::response(['count' => 1, 'items' => [$this->item()]]),
        ]);

        app(IngestaoService::class)->sincronizar($watch);

        $publicacao = Publicacao::first();

        $this->assertSame('cliente', $publicacao->origem_vigilancia);
        $this->assertSame($this->cliente->id, $publicacao->cliente_id);
        $this->assertTrue($publicacao->veioDeCliente());
    }

    public function test_publicacao_da_oab_do_advogado_nao_vira_publicacao_de_cliente(): void
    {
        $watch = OabWatch::create([
            'advogado_id' => $this->advogado->id,
            'termo' => '12345',
            'tipo' => 'oab',
            'uf' => 'AM',
            'ativo' => true,
        ]);

        Http::fake([
            'comunicaapi.pje.jus.br/*' => Http::response(['count' => 1, 'items' => [$this->item()]]),
        ]);

        app(IngestaoService::class)->sincronizar($watch);

        $publicacao = Publicacao::first();

        $this->assertSame('advogado', $publicacao->origem_vigilancia);
        $this->assertNull($publicacao->cliente_id);
    }

    public function test_ficha_do_cliente_lista_as_publicacoes_dele(): void
    {
        $this->actingAs($this->advogado)->post("/clientes/{$this->cliente->id}/vigilancia");
        $watch = OabWatch::where('tipo', 'cliente')->first();

        Http::fake([
            'comunicaapi.pje.jus.br/*' => Http::response(['count' => 1, 'items' => [$this->item()]]),
        ]);

        app(IngestaoService::class)->sincronizar($watch);

        $resposta = $this->actingAs($this->advogado)->get("/clientes/{$this->cliente->id}");
        $props = $resposta->viewData('page')['props'];

        $this->assertCount(1, $props['publicacoes']);
        $this->assertTrue($props['vigilancia']['ativa']);
        $this->assertSame(1, $props['vigilancia']['publicacoes']);
    }

    public function test_previa_avisa_quando_o_nome_e_comum_demais(): void
    {
        config(['mithrandir.djen.teto_por_varredura' => 300]);

        Http::fake(['comunicaapi.pje.jus.br/*' => Http::response(['count' => 10000, 'items' => []])]);

        $this->actingAs($this->advogado)
            ->getJson("/clientes/{$this->cliente->id}/vigilancia/previa")
            ->assertOk()
            ->assertJsonPath('quantidade', 10000)
            ->assertJsonPath('recomendado', false);
    }

    public function test_previa_aprova_volume_saudavel(): void
    {
        Http::fake(['comunicaapi.pje.jus.br/*' => Http::response(['count' => 3, 'items' => []])]);

        $this->actingAs($this->advogado)
            ->getJson("/clientes/{$this->cliente->id}/vigilancia/previa")
            ->assertOk()
            ->assertJsonPath('quantidade', 3)
            ->assertJsonPath('recomendado', true);
    }

    /* ---------------- Guarda de enxurrada ---------------- */

    /**
     * O inverso do radar cego. Se o filtro parar de filtrar - por nome comum
     * demais ou por mudanca de contrato do CNJ - o inbox afoga e o prazo de
     * verdade some no ruido. Melhor recusar o lote inteiro e avisar.
     */
    public function test_volume_acima_do_teto_recusa_o_lote_e_avisa(): void
    {
        config(['mithrandir.djen.teto_por_varredura' => 5]);

        $this->actingAs($this->advogado)->post("/clientes/{$this->cliente->id}/vigilancia");
        $watch = OabWatch::where('tipo', 'cliente')->first();

        $itens = [];

        for ($i = 1; $i <= 10; $i++) {
            $itens[] = $this->item(['id' => 900000 + $i, 'hash' => 'hash'.$i]);
        }

        Http::fake([
            'comunicaapi.pje.jus.br/*' => Http::response(['count' => 10, 'items' => $itens]),
        ]);

        $this->expectException(VolumeAnormalException::class);

        try {
            app(IngestaoService::class)->sincronizar($watch);
        } finally {
            // Nada entrou: o inbox continua limpo.
            $this->assertSame(0, Publicacao::count());

            $this->assertDatabaseHas('notificacoes', [
                'advogado_id' => $this->advogado->id,
                'tipo' => 'volume_anormal',
            ]);
        }
    }

    public function test_volume_dentro_do_teto_passa_normalmente(): void
    {
        config(['mithrandir.djen.teto_por_varredura' => 50]);

        $this->actingAs($this->advogado)->post("/clientes/{$this->cliente->id}/vigilancia");
        $watch = OabWatch::where('tipo', 'cliente')->first();

        Http::fake([
            'comunicaapi.pje.jus.br/*' => Http::response(['count' => 1, 'items' => [$this->item()]]),
        ]);

        $resultado = app(IngestaoService::class)->sincronizar($watch);

        $this->assertSame(1, $resultado['novas']);
        $this->assertSame(0, Notificacao::where('tipo', 'volume_anormal')->count());
    }

    public function test_nao_liga_vigilancia_de_cliente_alheio(): void
    {
        $outro = User::create([
            'name' => 'Bruno', 'email' => 'b@b.test', 'password' => 'x',
            'oab' => '9', 'uf' => 'AM', 'aceite_termo_em' => now(),
        ]);

        $this->actingAs($outro)
            ->post("/clientes/{$this->cliente->id}/vigilancia")
            ->assertForbidden();

        $this->assertSame(0, OabWatch::where('tipo', 'cliente')->count());
    }
}
