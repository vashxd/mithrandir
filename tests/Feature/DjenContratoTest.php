<?php

namespace Tests\Feature;

use App\Models\OabWatch;
use App\Models\Publicacao;
use App\Models\User;
use App\Services\Djen\ComunicacaoDto;
use App\Services\Djen\IngestaoService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Teste de contrato do DJEN (risco mapeado na secao 13).
 *
 * Os payloads abaixo sao COPIA FIEL de respostas reais de
 * https://comunicaapi.pje.jus.br/api/v1/comunicacao, capturadas em 03/09/2026.
 * Se o CNJ mudar o contrato, e aqui que quebra primeiro.
 */
class DjenContratoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Item real, com os nomes de campo que a API devolve de verdade.
     *
     * @param  array<string, mixed>  $sobrescreve
     * @return array<string, mixed>
     */
    private function itemReal(array $sobrescreve = []): array
    {
        return array_merge([
            'id' => 716124018,
            'data_disponibilizacao' => '2026-09-03',
            'siglaTribunal' => 'TJAM',
            'tipoComunicacao' => 'Intimação',
            'nomeOrgao' => '19ª Vara Cível e de Acidentes de Trabalho da Comarca de Manaus - Cível',
            'idOrgao' => 52165,
            'texto' => 'Para advogados/curador/defensor de José Maria Samuel de Andrade com prazo '
                .'de 15 dias úteis - Referente ao evento JUNTADA DE ATO ORDINATÓRIO (01/09/2026).',
            'numero_processo' => '01515323520268041000',
            'meio' => 'D',
            'link' => null,
            'tipoDocumento' => 'Intimação',
            'nomeClasse' => 'PROCEDIMENTO COMUM CíVEL',
            'codigoClasse' => '7',
            // ATENCAO: este campo e um numero de SEQUENCIA, nao um identificador.
            'numeroComunicacao' => 1,
            'ativo' => true,
            'hash' => 'XDVMlJ3NoYGDhAhVTp57BoRLzE4gOP',
            'status' => 'P',
            'datadisponibilizacao' => '03/09/2026',
            'meiocompleto' => 'Diário de Justiça Eletrônico Nacional',
            'numeroprocessocommascara' => '0151532-35.2026.8.04.1000',
            'destinatarios' => [
                ['nome' => 'BANCO BMG', 'comunicacao_id' => 716124018, 'polo' => 'P'],
                ['nome' => 'JOSé MARIA SAMUEL DE ANDRADE', 'comunicacao_id' => 716124018, 'polo' => 'A'],
            ],
            'destinatarioadvogados' => [
                [
                    'id' => 1336851664,
                    'comunicacao_id' => 716124018,
                    'advogado_id' => 3493783,
                    'advogado' => [
                        'id' => 3493783,
                        'nome' => 'LUAN CARLOS BRASIL BARBOSA',
                        'numero_oab' => '14197',
                        'uf_oab' => 'AM',
                    ],
                ],
            ],
        ], $sobrescreve);
    }

    private function advogado(): User
    {
        return User::create([
            'name' => 'Luan Carlos Brasil Barbosa',
            'email' => 'luan@exemplo.test',
            'password' => 'segredo123',
            'oab' => '14197',
            'uf' => 'AM',
            'aceite_termo_em' => now(),
        ]);
    }

    private function watch(User $advogado): OabWatch
    {
        return OabWatch::create([
            'advogado_id' => $advogado->id,
            'termo' => '14197',
            'tipo' => 'oab',
            'uf' => 'AM',
            'ativo' => true,
        ]);
    }

    public function test_le_todos_os_campos_do_payload_real(): void
    {
        $dto = ComunicacaoDto::deArray($this->itemReal());

        $this->assertSame('TJAM', $dto->tribunal);
        $this->assertSame('01515323520268041000', $dto->numeroProcesso);
        $this->assertSame('2026-09-03', $dto->dataDisponibilizacao->toDateString());
        $this->assertSame('Intimação', $dto->tipoComunicacao);
        $this->assertStringContainsString('15 dias úteis', $dto->teor);
        $this->assertStringContainsString('19ª Vara', $dto->orgao);
        // As duas partes do processo, e o advogado intimado em lista separada.
        $this->assertCount(2, $dto->destinatarios);
        $this->assertSame(
            ['LUAN CARLOS BRASIL BARBOSA (OAB 14197/AM)'],
            $dto->advogadosIntimados()
        );
    }

    /**
     * O bug que este teste existe para impedir: `numeroComunicacao` e um numero
     * de sequencia (1, 10, 835...), nao um identificador. Usa-lo como chave de
     * deduplicacao faz duas publicacoes distintas colidirem — e a segunda some
     * calada. Publicacao engolida e prazo perdido.
     */
    public function test_publicacoes_distintas_com_mesmo_numero_de_sequencia_nao_colidem(): void
    {
        $primeira = ComunicacaoDto::deArray($this->itemReal([
            'id' => 716124018,
            'hash' => 'XDVMlJ3NoYGDhAhVTp57BoRLzE4gOP',
            'numeroComunicacao' => 1,
            'numero_processo' => '01515323520268041000',
        ]));

        $segunda = ComunicacaoDto::deArray($this->itemReal([
            'id' => 716999999,
            'hash' => 'ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ',
            'numeroComunicacao' => 1,
            'numero_processo' => '02318198220268041000',
        ]));

        $this->assertNotSame($primeira->hash(1), $segunda->hash(1));
    }

    public function test_ingestao_persiste_as_duas_publicacoes_do_mesmo_dia(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        Http::fake([
            'comunicaapi.pje.jus.br/*' => Http::response([
                'status' => 'success',
                'message' => 'Sucesso',
                'count' => 2,
                'items' => [
                    $this->itemReal([
                        'id' => 716124018,
                        'hash' => 'XDVMlJ3NoYGDhAhVTp57BoRLzE4gOP',
                        'numeroComunicacao' => 1,
                        'numero_processo' => '01515323520268041000',
                    ]),
                    $this->itemReal([
                        'id' => 716124029,
                        'hash' => 'kW5ljVaJnRkahouDTelJj1ZAve9mDO',
                        'numeroComunicacao' => 1,
                        'numero_processo' => '02318198220268041000',
                    ]),
                ],
            ]),
        ]);

        $resultado = app(IngestaoService::class)->sincronizar($watch);

        $this->assertSame(2, $resultado['novas']);
        $this->assertSame(2, Publicacao::count());
        $this->assertSame(
            ['01515323520268041000', '02318198220268041000'],
            Publicacao::orderBy('numero_processo')->pluck('numero_processo')->all()
        );
    }

    /**
     * A mesma comunicacao capturada de novo no dia seguinte (a janela e sempre
     * ontem + hoje) nao pode virar registro novo.
     */
    public function test_mesma_comunicacao_na_janela_sobreposta_nao_duplica(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        Http::fake([
            'comunicaapi.pje.jus.br/*' => Http::response([
                'count' => 1,
                'items' => [$this->itemReal()],
            ]),
        ]);

        app(IngestaoService::class)->sincronizar($watch);
        $segunda = app(IngestaoService::class)->sincronizar($watch);

        $this->assertSame(0, $segunda['novas']);
        $this->assertSame(1, Publicacao::count());
    }

    /**
     * O identificador externo gravado tem de ser o `id` do DJEN, que e estavel
     * e permite rastrear a comunicacao de volta na origem.
     */
    public function test_guarda_o_identificador_externo_do_djen(): void
    {
        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        Http::fake([
            'comunicaapi.pje.jus.br/*' => Http::response([
                'count' => 1,
                'items' => [$this->itemReal()],
            ]),
        ]);

        app(IngestaoService::class)->sincronizar($watch);

        $publicacao = Publicacao::first();

        $this->assertSame('716124018', $publicacao->numero_comunicacao);
        // O payload bruto fica guardado inteiro: e prova do que o app recebeu.
        $this->assertSame('XDVMlJ3NoYGDhAhVTp57BoRLzE4gOP', $publicacao->payload_bruto['hash']);
    }

    public function test_meio_traz_a_descricao_legivel_e_nao_a_sigla(): void
    {
        $dto = ComunicacaoDto::deArray($this->itemReal());

        $this->assertSame('Diário de Justiça Eletrônico Nacional', $dto->meio);
    }

    /* ---------------- Janela de consulta ---------------- */

    /**
     * Uma janela de 2 dias faz conta nova parecer quebrada: o advogado se
     * cadastra, manda sincronizar e nao ve nada, mesmo tendo publicacao da
     * semana passada. A primeira varredura busca mais longe de proposito.
     */
    public function test_primeira_varredura_faz_backfill_amplo(): void
    {
        config(['mithrandir.djen.janela_primeira_sync_dias' => 30]);

        $advogado = $this->advogado();
        $watch = $this->watch($advogado);
        $this->assertNull($watch->ultima_sync_em);

        Http::fake(['comunicaapi.pje.jus.br/*' => Http::response(['count' => 0, 'items' => []])]);

        app(IngestaoService::class)->sincronizar($watch);

        Http::assertSent(function ($request) {
            $inicio = CarbonImmutable::parse($request['dataDisponibilizacaoInicio']);
            $fim = CarbonImmutable::parse($request['dataDisponibilizacaoFim']);

            return (int) $inicio->diffInDays($fim) === 29;
        });
    }

    public function test_varreduras_seguintes_usam_a_janela_padrao_de_7_dias(): void
    {
        config(['mithrandir.djen.janela_dias' => 7]);

        $advogado = $this->advogado();
        $watch = $this->watch($advogado);
        $watch->forceFill(['ultima_sync_em' => now()->subDay()])->save();

        Http::fake(['comunicaapi.pje.jus.br/*' => Http::response(['count' => 0, 'items' => []])]);

        app(IngestaoService::class)->sincronizar($watch->fresh());

        Http::assertSent(function ($request) {
            $inicio = CarbonImmutable::parse($request['dataDisponibilizacaoInicio']);
            $fim = CarbonImmutable::parse($request['dataDisponibilizacaoFim']);

            return (int) $inicio->diffInDays($fim) === 6;
        });
    }

    /**
     * RF-1.3 e invariante: por menor que se configure a janela, ontem e hoje
     * entram sempre.
     */
    public function test_janela_nunca_encolhe_abaixo_de_ontem_mais_hoje(): void
    {
        config(['mithrandir.djen.janela_dias' => 1]);

        $advogado = $this->advogado();
        $watch = $this->watch($advogado);
        $watch->forceFill(['ultima_sync_em' => now()->subDay()])->save();

        Http::fake(['comunicaapi.pje.jus.br/*' => Http::response(['count' => 0, 'items' => []])]);

        app(IngestaoService::class)->sincronizar($watch->fresh());

        $hoje = CarbonImmutable::now(config('mithrandir.timezone'))->startOfDay();

        Http::assertSent(function ($request) use ($hoje) {
            return $request['dataDisponibilizacaoInicio'] === $hoje->subDay()->toDateString()
                && $request['dataDisponibilizacaoFim'] === $hoje->toDateString();
        });
    }

    /**
     * Janela larga so e segura porque a deduplicacao aguenta: os mesmos dias
     * sao relidos todo dia e nada duplica.
     */
    public function test_janela_larga_relida_todo_dia_nao_duplica(): void
    {
        config(['mithrandir.djen.janela_dias' => 7]);

        $advogado = $this->advogado();
        $watch = $this->watch($advogado);

        Http::fake([
            'comunicaapi.pje.jus.br/*' => Http::response([
                'count' => 2,
                'items' => [
                    $this->itemReal(['id' => 1, 'hash' => 'aaa', 'numero_processo' => '01515323520268041000']),
                    $this->itemReal(['id' => 2, 'hash' => 'bbb', 'numero_processo' => '02318198220268041000']),
                ],
            ]),
        ]);

        foreach (range(1, 5) as $dia) {
            app(IngestaoService::class)->sincronizar($watch->fresh());
        }

        $this->assertSame(2, Publicacao::count());
    }
}
