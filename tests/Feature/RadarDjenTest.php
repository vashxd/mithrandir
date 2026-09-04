<?php

namespace Tests\Feature;

use App\Jobs\SyncPublicacoesJob;
use App\Models\OabWatch;
use App\Models\Processo;
use App\Models\Publicacao;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\Djen\IngestaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * M1 — Radar de publicacoes. A ingestao e o gatilho formal do prazo, entao
 * dedup, vinculo e auditoria sao testados de ponta a ponta.
 */
class RadarDjenTest extends TestCase
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

    private function respostaDjen(array $itens): void
    {
        Http::fake([
            'comunicaapi.pje.jus.br/*' => Http::response([
                'count' => count($itens),
                'items' => $itens,
            ]),
        ]);
    }

    private function item(array $sobrescreve = []): array
    {
        return array_merge([
            'numero_comunicacao' => 'COM-001',
            'numeroprocessocommascara' => '0801234-56.2026.8.04.0001',
            'siglaTribunal' => 'TJAM',
            'nomeOrgao' => '1a Vara Civel de Manaus',
            'texto' => 'Fica a parte autora intimada para, no prazo de 15 dias, apresentar replica.',
            'data_disponibilizacao' => '2026-03-04',
            'tipoComunicacao' => 'Intimacao',
        ], $sobrescreve);
    }

    public function test_ingestao_cria_publicacao_e_registra_log(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        $this->respostaDjen([$this->item()]);

        $resultado = app(IngestaoService::class)->sincronizar($watch);

        $this->assertSame(1, $resultado['novas']);

        $publicacao = Publicacao::first();
        $this->assertSame($advogado->id, $publicacao->advogado_id);
        $this->assertSame('TJAM', $publicacao->tribunal);
        $this->assertSame('nova', $publicacao->status_triagem);
        // Numero normalizado para so digitos.
        $this->assertSame('08012345620268040001', $publicacao->numero_processo);

        // RF-1.9: auditoria de cada varredura.
        $log = SyncLog::first();
        $this->assertSame('sucesso', $log->status);
        $this->assertSame(1, $log->qtd_novas);
        $this->assertNotNull($log->janela_inicio);
    }

    public function test_janela_de_consulta_cobre_ontem_e_hoje(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        $this->respostaDjen([]);

        app(IngestaoService::class)->sincronizar($watch);

        // RF-1.3: nunca so hoje. As 22h de Brasilia o UTC ja virou o dia seguinte.
        Http::assertSent(function ($request) {
            $inicio = $request['dataDisponibilizacaoInicio'];
            $fim = $request['dataDisponibilizacaoFim'];

            return $inicio !== null
                && $fim !== null
                && $inicio < $fim;
        });
    }

    public function test_deduplicacao_por_hash_nao_duplica_na_segunda_varredura(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        $this->respostaDjen([$this->item()]);

        app(IngestaoService::class)->sincronizar($watch);
        $segunda = app(IngestaoService::class)->sincronizar($watch);

        $this->assertSame(0, $segunda['novas']);
        $this->assertSame(1, Publicacao::count());
    }

    public function test_vincula_automaticamente_ao_processo_pelo_numero_cnj(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        $processo = Processo::create([
            'advogado_id' => $advogado->id,
            // Cadastrado com mascara; a publicacao chega sem. Tem de casar mesmo assim.
            'numero_cnj' => '08012345620268040001',
            'titulo' => 'Caso da Ana',
        ]);

        $this->respostaDjen([$this->item()]);

        app(IngestaoService::class)->sincronizar($watch);

        $this->assertSame($processo->id, Publicacao::first()->processo_id);
    }

    public function test_publicacao_sem_processo_cadastrado_fica_orfa_para_triagem(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        $this->respostaDjen([$this->item()]);

        app(IngestaoService::class)->sincronizar($watch);

        $this->assertNull(Publicacao::first()->processo_id);
    }

    public function test_duas_falhas_seguidas_avisam_que_o_radar_esta_cego(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        Http::fake(['comunicaapi.pje.jus.br/*' => Http::response('', 500)]);

        foreach ([1, 2] as $tentativa) {
            try {
                app(IngestaoService::class)->sincronizar($watch->fresh());
            } catch (\Throwable) {
                // A falha e esperada; o que importa e o efeito colateral.
            }
        }

        $this->assertSame(2, $watch->fresh()->falhas_consecutivas);
        $this->assertTrue($watch->fresh()->estaCego());

        // RF-1.10
        $this->assertDatabaseHas('notificacoes', [
            'advogado_id' => $advogado->id,
            'tipo' => 'radar_cego',
        ]);

        $this->assertSame(2, SyncLog::where('status', 'falha')->count());
    }

    public function test_sucesso_zera_o_contador_de_falhas(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);
        $watch->forceFill(['falhas_consecutivas' => 2])->save();

        $this->respostaDjen([$this->item()]);

        app(IngestaoService::class)->sincronizar($watch);

        $this->assertSame(0, $watch->fresh()->falhas_consecutivas);
    }

    public function test_publicacao_nova_gera_notificacao(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        $this->respostaDjen([$this->item()]);

        app(IngestaoService::class)->sincronizar($watch);

        $this->assertDatabaseHas('notificacoes', [
            'advogado_id' => $advogado->id,
            'tipo' => 'publicacao_nova',
        ]);
    }

    public function test_job_ignora_watch_desativado(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);
        $watch->forceFill(['ativo' => false])->save();

        Http::fake();

        (new SyncPublicacoesJob($watch->id))->handle(app(IngestaoService::class));

        Http::assertNothingSent();
        $this->assertSame(0, Publicacao::count());
    }

    public function test_publicacoes_de_advogados_diferentes_nao_colidem_no_hash(): void
    {
        $primeira = $this->advogado();
        $segunda = User::create([
            'name' => 'Bruno Lima',
            'email' => 'bruno@exemplo.test',
            'password' => 'segredo123',
            'oab' => '99999',
            'uf' => 'AM',
            'aceite_termo_em' => now(),
        ]);

        $this->respostaDjen([$this->item()]);

        app(IngestaoService::class)->sincronizar($this->watch($primeira));
        app(IngestaoService::class)->sincronizar($this->watch($segunda));

        // Mesma comunicacao, dois advogados: cada um precisa da sua copia.
        $this->assertSame(2, Publicacao::count());
    }
}
