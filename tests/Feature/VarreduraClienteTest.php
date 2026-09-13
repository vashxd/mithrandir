<?php

namespace Tests\Feature;

use App\Models\OabWatch;
use App\Models\Publicacao;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\Djen\IngestaoService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Varredura do DJEN executada pelo navegador do advogado.
 *
 * Existe porque a API do CNJ responde 403 a IP estrangeiro. O que se testa
 * aqui nao e a busca (essa acontece no navegador), e sim que o servidor
 * continua sendo o dono das regras: ele dita a janela, recusa janela
 * encolhida, deduplica igual, e conta falha do cliente para o radar cego.
 */
class VarreduraClienteTest extends TestCase
{
    use RefreshDatabase;

    private function advogado(): User
    {
        return User::create([
            'name' => 'Ana Souza',
            'email' => 'ana@exemplo.test',
            'password' => 'segredo123',
            'oab' => '12345',
            'uf' => 'AM',
            'aceite_termo_em' => now(),
        ]);
    }

    private function watch(User $advogado): OabWatch
    {
        return OabWatch::create([
            'advogado_id' => $advogado->id,
            'termo' => '12345',
            'tipo' => 'oab',
            'uf' => 'AM',
            'ativo' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(array $sobrescreve = []): array
    {
        return array_merge([
            'id' => 716124018,
            'hash' => 'XDVMlJ3abc',
            'numero_processo' => '01515323520268041000',
            'siglaTribunal' => 'TJAM',
            'nomeOrgao' => '1a Vara Civel',
            'tipoComunicacao' => 'Intimacao',
            'meiocompleto' => 'Diario de Justica Eletronico Nacional',
            'texto' => 'Fica a parte intimada para manifestar em 15 dias.',
            'data_disponibilizacao' => CarbonImmutable::now(config('mithrandir.timezone'))->toDateString(),
        ], $sobrescreve);
    }

    private function janela(OabWatch $watch): array
    {
        [$inicio, $fim] = app(IngestaoService::class)->janela($watch);

        return [
            'janela_inicio' => $inicio->toDateString(),
            'janela_fim' => $fim->toDateString(),
        ];
    }

    public function test_plano_entrega_os_parametros_que_o_servidor_usaria(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        $resposta = $this->actingAs($advogado)->getJson('/publicacoes/varredura/plano');

        $resposta->assertOk()
            ->assertJsonPath('ativa', true)
            ->assertJsonPath('consultas.0.watch_id', $watch->id)
            ->assertJsonPath('consultas.0.parametros.numeroOab', '12345')
            ->assertJsonPath('consultas.0.parametros.ufOab', 'AM');

        // A janela do plano e a mesma que o servidor aplicaria sozinho.
        $this->assertSame(
            $this->janela($watch)['janela_inicio'],
            $resposta->json('consultas.0.janela_inicio')
        );
    }

    public function test_itens_do_cliente_viram_publicacao_e_log_com_origem(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        // Nenhuma chamada sai daqui: quem buscou foi o navegador.
        Http::fake();

        $this->actingAs($advogado)
            ->postJson('/publicacoes/varredura', $this->janela($watch) + [
                'watch_id' => $watch->id,
                'itens' => [$this->item()],
            ])
            ->assertOk()
            ->assertJsonPath('novas', 1);

        Http::assertNothingSent();

        $this->assertSame(1, Publicacao::where('advogado_id', $advogado->id)->count());
        $this->assertSame('cliente', SyncLog::where('oab_watch_id', $watch->id)->first()->origem);
        $this->assertNotNull($watch->fresh()->ultima_sync_em);
    }

    public function test_o_mesmo_item_pelo_cliente_e_pelo_servidor_nao_duplica(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        $this->actingAs($advogado)
            ->postJson('/publicacoes/varredura', $this->janela($watch) + [
                'watch_id' => $watch->id,
                'itens' => [$this->item()],
            ])
            ->assertOk();

        Http::fake([
            'comunicaapi.pje.jus.br/*' => Http::response(['count' => 1, 'items' => [$this->item()]]),
        ]);

        app(IngestaoService::class)->sincronizar($watch->fresh());

        $this->assertSame(1, Publicacao::where('advogado_id', $advogado->id)->count());
    }

    public function test_janela_encolhida_e_recusada_e_contada_como_falha(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        $hoje = CarbonImmutable::now(config('mithrandir.timezone'))->startOfDay();

        // O cliente afirma ter varrido so hoje, quando a janela exigida e maior.
        $this->actingAs($advogado)
            ->postJson('/publicacoes/varredura', [
                'watch_id' => $watch->id,
                'janela_inicio' => $hoje->toDateString(),
                'janela_fim' => $hoje->toDateString(),
                'itens' => [$this->item()],
            ])
            ->assertStatus(422);

        $this->assertSame(0, Publicacao::where('advogado_id', $advogado->id)->count());
        $this->assertSame('falha', SyncLog::where('oab_watch_id', $watch->id)->first()->status);
    }

    public function test_erro_relatado_pelo_cliente_alimenta_o_radar_cego(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        foreach (range(1, 2) as $ignorado) {
            $this->actingAs($advogado)
                ->postJson('/publicacoes/varredura', $this->janela($watch) + [
                    'watch_id' => $watch->id,
                    'erro' => 'DJEN respondeu 403.',
                ])
                ->assertOk()
                ->assertJsonPath('falhou', true);
        }

        $watch->refresh();

        $this->assertSame(2, (int) $watch->falhas_consecutivas);
        $this->assertTrue($watch->estaCego());
        $this->assertSame(2, SyncLog::where('status', 'falha')->where('origem', 'cliente')->count());
    }

    /**
     * Ingestao que falha nao pode virar 500 com pagina HTML: do outro lado
     * esta um navegador, e o advogado precisa da mensagem real.
     */
    public function test_falha_na_ingestao_volta_como_json_e_nao_como_500(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        // Volume acima do teto e a falha de ingestao mais facil de provocar.
        config(['mithrandir.djen.teto_por_varredura' => 1]);

        $resposta = $this->actingAs($advogado)
            ->postJson('/publicacoes/varredura', $this->janela($watch) + [
                'watch_id' => $watch->id,
                'itens' => [
                    $this->item(['id' => 1, 'hash' => 'um']),
                    $this->item(['id' => 2, 'hash' => 'dois']),
                ],
            ]);

        $resposta->assertStatus(422)
            ->assertJsonPath('falhou', true)
            ->assertJsonPath('novas', 0);

        $this->assertStringContainsString('teto', $resposta->json('erro'));

        // A mensagem tem de estar no historico do radar, nao so na resposta.
        $log = SyncLog::where('oab_watch_id', $watch->id)->latest('id')->first();
        $this->assertSame('falha', $log->status);
        $this->assertSame('cliente', $log->origem);
        $this->assertStringContainsString('teto', $log->erro);
    }

    public function test_nao_se_varre_termo_de_outro_advogado(): void
    {
        $dono = $this->advogado();
        $watch = $this->watch($dono);

        $estranho = User::create([
            'name' => 'Bruno Lima',
            'email' => 'bruno@exemplo.test',
            'password' => 'segredo123',
            'oab' => '99999',
            'uf' => 'AM',
            'aceite_termo_em' => now(),
        ]);

        $this->actingAs($estranho)
            ->postJson('/publicacoes/varredura', $this->janela($watch) + [
                'watch_id' => $watch->id,
                'itens' => [$this->item()],
            ])
            ->assertNotFound();

        $this->assertSame(0, Publicacao::count());
    }

    public function test_desligar_a_varredura_pelo_cliente_fecha_as_duas_pontas(): void
    {
        config(['mithrandir.djen.varredura_cliente' => false]);

        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        $this->actingAs($advogado)
            ->getJson('/publicacoes/varredura/plano')
            ->assertOk()
            ->assertJsonPath('ativa', false);

        $this->actingAs($advogado)
            ->postJson('/publicacoes/varredura', $this->janela($watch) + [
                'watch_id' => $watch->id,
                'itens' => [$this->item()],
            ])
            ->assertNotFound();
    }

    public function test_intervalo_minimo_poupa_a_api_mas_o_botao_forca(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        $watch->forceFill(['ultima_sync_em' => now()->subMinutes(5)])->save();

        $this->actingAs($advogado)
            ->getJson('/publicacoes/varredura/plano')
            ->assertOk()
            ->assertJsonCount(0, 'consultas');

        $this->actingAs($advogado)
            ->getJson('/publicacoes/varredura/plano?forcar=1')
            ->assertOk()
            ->assertJsonCount(1, 'consultas');
    }
}
