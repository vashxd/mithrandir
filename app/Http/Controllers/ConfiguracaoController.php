<?php

namespace App\Http\Controllers;

use App\Jobs\RecalcularPrazosJob;
use App\Models\Auditoria;
use App\Models\Configuracao;
use App\Models\Feriado;
use App\Models\OabWatch;
use App\Services\DataJud\DataJudClient;
use App\Services\Prazo\CalendarioService;
use App\Services\Push\WebPushService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class ConfiguracaoController extends Controller
{
    public function index(Request $request): Response
    {
        $advogado = contexto()->advogado();

        return Inertia::render('Configuracoes/Index', [
            'perfil' => [
                'nome' => $advogado->name,
                'email' => $advogado->email,
                'oab' => $advogado->oab,
                'uf' => $advogado->uf,
                'timezone' => $advogado->timezone,
                'buffer_padrao' => $advogado->buffer_padrao,
                'janela_djen_dias' => $advogado->janela_djen_dias,
                'janela_djen_padrao' => (int) config('mithrandir.djen.janela_dias', 7),
                'digest_horario' => substr((string) $advogado->digest_horario, 0, 5),
                'percentual_imposto' => (float) $advogado->percentual_imposto,
                'preferencias_notificacao' => $advogado->preferencias_notificacao ?? [],
                'aceite_termo_em' => $advogado->aceite_termo_em?->toIso8601String(),
                'exclusao_solicitada_em' => $advogado->exclusao_solicitada_em?->toIso8601String(),
            ],
            'push_configurado' => app(WebPushService::class)->configurado(),
            'datajud_configurado' => app(DataJudClient::class)->configurado(),
            'datajud_alerta_401' => Configuracao::valor('datajud.alerta_401_em'),
            'dispositivos' => $advogado->pushSubscriptions()->get()->map(fn ($s) => [
                'id' => $s->id,
                'user_agent' => $s->user_agent,
                'ultima_entrega_em' => $s->ultima_entrega_em?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * RF-9.3: buffer padrao, horario do digest e fuso.
     */
    public function update(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'oab' => ['required', 'string', 'max:20'],
            'uf' => ['required', 'string', 'size:2'],
            'timezone' => ['required', 'string', 'max:64'],
            'buffer_padrao' => ['required', 'integer', 'min:0', 'max:15'],
            // Piso de 2 = ontem + hoje (RF-1.3). Teto de 90 para nao pedir
            // o diario inteiro a cada varredura.
            'janela_djen_dias' => ['nullable', 'integer', 'min:2', 'max:90'],
            'digest_horario' => ['required', 'date_format:H:i'],
            'percentual_imposto' => ['required', 'numeric', 'min:0', 'max:100'],
            'preferencias_notificacao' => ['nullable', 'array'],
        ], [], ['name' => 'nome']);

        $request->user()->update($dados);

        return back()->with('sucesso', 'Configuracoes salvas.');
    }

    /**
     * RF-8.3: o onboarding existe para forcar "Adicionar a Tela de Inicio".
     * Sem isso o push nao funciona no iOS - e sem push o produto nao entrega
     * a promessa de nao perder prazo.
     */
    public function onboarding(Request $request): Response
    {
        $advogado = contexto()->advogado();

        return Inertia::render('Onboarding', [
            'watches' => $advogado->watches()->get(['id', 'termo', 'tipo', 'uf', 'ativo']),
            'push_configurado' => app(WebPushService::class)->configurado(),
            'concluido' => $advogado->onboarding_concluido_em !== null,
        ]);
    }

    public function concluirOnboarding(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['onboarding_concluido_em' => now()])->save();

        return redirect()->route('hoje')->with('sucesso', 'Tudo pronto. Bem-vindo ao Mithrandir.');
    }

    public function radar(Request $request): Response
    {
        $advogado = contexto()->advogado();

        return Inertia::render('Configuracoes/Radar', [
            'watches' => $advogado->watches()->withCount('publicacoes')->get()->map(fn (OabWatch $w) => [
                'id' => $w->id,
                'termo' => $w->termo,
                'tipo' => $w->tipo,
                'uf' => $w->uf,
                'ativo' => (bool) $w->ativo,
                'ultima_sync_em' => $w->ultima_sync_em?->toIso8601String(),
                'falhas_consecutivas' => $w->falhas_consecutivas,
                'cego' => $w->estaCego(),
                'publicacoes' => $w->publicacoes_count,
                'ultimos_logs' => $w->logs()->limit(5)->get()->map(fn ($l) => [
                    'executado_em' => $l->executado_em?->toIso8601String(),
                    'status' => $l->status,
                    'qtd_itens' => $l->qtd_itens,
                    'qtd_novas' => $l->qtd_novas,
                    'erro' => $l->erro,
                ]),
            ]),
        ]);
    }

    /**
     * RF-1.1: multiplos termos de vigilancia, incluindo variacoes de grafia.
     */
    public function storeWatch(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'termo' => ['required', 'string', 'max:255'],
            'tipo' => ['required', 'in:oab,nome'],
            'uf' => ['nullable', 'string', 'size:2'],
            'ativo' => ['boolean'],
        ]);

        OabWatch::updateOrCreate(
            [
                'advogado_id' => contexto()->advogadoId(),
                'termo' => $dados['termo'],
                'tipo' => $dados['tipo'],
            ],
            [
                'uf' => $dados['uf'] ? strtoupper($dados['uf']) : null,
                'ativo' => $dados['ativo'] ?? true,
                'falhas_consecutivas' => 0,
            ]
        );

        return back()->with('sucesso', 'Termo de vigilancia salvo.');
    }

    public function destroyWatch(Request $request, OabWatch $watch): RedirectResponse
    {
        abort_unless($watch->advogado_id === contexto()->advogadoId(), 403);

        // Publicacoes ja capturadas nao somem junto: sao prova (regra de ouro M1).
        $watch->publicacoes()->update(['oab_watch_id' => null]);
        $watch->delete();

        return back()->with('sucesso', 'Termo removido. As publicacoes ja capturadas foram mantidas.');
    }

    public function feriados(Request $request): Response
    {
        $ano = (int) ($request->integer('ano') ?: now()->year);

        return Inertia::render('Configuracoes/Feriados', [
            'ano' => $ano,
            'feriados' => Feriado::whereYear('data', $ano)
                ->orderBy('data')
                ->get()
                ->map(fn (Feriado $f) => [
                    'id' => $f->id,
                    'data' => CarbonImmutable::parse($f->data)->toDateString(),
                    'descricao' => $f->descricao,
                    'abrangencia' => $f->abrangencia,
                    'uf' => $f->uf,
                    'municipio' => $f->municipio,
                    'tribunal_sigla' => $f->tribunal_sigla,
                    'proprio' => $f->advogado_id !== null,
                ]),
            // O aviso permanente da secao 13: este calendario e responsabilidade
            // compartilhada, nunca garantia.
            'aviso' => 'Nao existe fonte publica unificada de feriado forense. '
                .'Confira as portarias do seu tribunal a cada trimestre.',
        ]);
    }

    /**
     * RF-2.11: cadastrar feriado dispara recalculo em lote dos prazos afetados.
     */
    public function storeFeriado(Request $request, CalendarioService $calendario): RedirectResponse
    {
        $dados = $request->validate([
            'data' => ['required', 'date'],
            'descricao' => ['required', 'string', 'max:255'],
            'abrangencia' => ['required', 'in:nacional,estadual,municipal,tribunal'],
            'uf' => ['nullable', 'string', 'size:2'],
            'municipio' => ['nullable', 'string', 'max:120'],
            'tribunal_sigla' => ['nullable', 'string', 'max:20'],
            'suspensao_expediente' => ['boolean'],
        ]);

        $feriado = Feriado::create($dados + [
            'advogado_id' => contexto()->advogadoId(),
            'fonte' => 'Cadastrado pelo advogado',
        ]);

        $calendario->limparCache();

        RecalcularPrazosJob::dispatch(
            CarbonImmutable::parse($feriado->data)->toDateString(),
            $request->user()->id
        );

        return back()->with(
            'sucesso',
            'Feriado cadastrado. Os prazos afetados estao sendo recalculados e voce sera avisado.'
        );
    }

    /**
     * Secao 7.2: a chave publica do DataJud pode mudar a qualquer momento;
     * trocar a chave nao pode exigir deploy.
     */
    public function salvarChaveDataJud(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'api_key' => ['required', 'string', 'max:500'],
        ]);

        Configuracao::definir('datajud.api_key', $dados['api_key'], 'Chave publica da API do DataJud (CNJ)');
        Configuracao::definir('datajud.alerta_401_em', null);

        return back()->with('sucesso', 'Chave do DataJud atualizada.');
    }

    /**
     * RF-9.4 (LGPD - portabilidade): JSON com todos os dados + ZIP dos arquivos.
     */
    public function exportar(Request $request): StreamedResponse
    {
        $advogado = $request->user()->load([
            'watches', 'publicacoes', 'clientes.atendimentos', 'processos.partes',
            'processos.movimentacoes', 'processos.honorarios.parcelas', 'processos.despesas',
            'prazos', 'eventos', 'documentos',
        ]);

        Auditoria::registrar($advogado->id, 'users', $advogado->id, 'exportacao_lgpd');

        $nome = 'mithrandir-'.$advogado->id.'-'.now()->format('Ymd-His').'.zip';
        $temporario = tempnam(sys_get_temp_dir(), 'mith');

        $zip = new ZipArchive;
        $zip->open($temporario, ZipArchive::OVERWRITE | ZipArchive::CREATE);

        $zip->addFromString('dados.json', json_encode([
            'exportado_em' => now()->toIso8601String(),
            'advogado' => $advogado->only([
                'id', 'name', 'email', 'oab', 'uf', 'timezone', 'buffer_padrao', 'aceite_termo_em',
            ]),
            'termos_vigilancia' => $advogado->watches->toArray(),
            'publicacoes' => $advogado->publicacoes->toArray(),
            'clientes' => $advogado->clientes->toArray(),
            'processos' => $advogado->processos->toArray(),
            'prazos' => $advogado->prazos->toArray(),
            'eventos' => $advogado->eventos->toArray(),
            'documentos' => $advogado->documentos->makeHidden('path')->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $zip->addFromString('LEIA-ME.txt', implode("\n", [
            'Exportacao de dados do Mithrandir (LGPD, art. 18, V - portabilidade).',
            '',
            'dados.json  - todos os registros da sua conta.',
            'arquivos/   - documentos anexados, com o nome original.',
            '',
            'O advogado e o controlador dos dados dos seus clientes; o Mithrandir e operador.',
        ]));

        $disco = Storage::disk(config('mithrandir.uploads.disk'));

        foreach ($advogado->documentos as $documento) {
            if ($disco->exists($documento->path)) {
                $zip->addFromString(
                    'arquivos/'.$documento->id.'-'.$documento->nome,
                    $disco->get($documento->path)
                );
            }
        }

        $zip->close();

        return response()->streamDownload(function () use ($temporario) {
            readfile($temporario);
            @unlink($temporario);
        }, $nome, ['Content-Type' => 'application/zip']);
    }

    /**
     * RF-9.5: exclusao com carencia de 30 dias.
     */
    public function solicitarExclusao(Request $request): RedirectResponse
    {
        $request->validate([
            'confirmacao' => ['required', 'in:EXCLUIR'],
        ], [
            'confirmacao.in' => 'Digite EXCLUIR para confirmar.',
        ]);

        $advogado = contexto()->advogado();
        $advogado->forceFill(['exclusao_solicitada_em' => now()])->save();
        $advogado->watches()->update(['ativo' => false]);

        Auditoria::registrar($advogado->id, 'users', $advogado->id, 'exclusao_solicitada');

        $prazo = now()->addDays(config('mithrandir.exclusao_carencia_dias'));

        return back()->with('sucesso', sprintf(
            'Exclusao agendada para %s. Ate la voce pode cancelar. O radar foi desligado.',
            $prazo->format('d/m/Y')
        ));
    }

    public function cancelarExclusao(Request $request): RedirectResponse
    {
        $advogado = contexto()->advogado();
        $advogado->forceFill(['exclusao_solicitada_em' => null])->save();
        $advogado->watches()->where('tipo', 'oab')->update(['ativo' => true]);

        Auditoria::registrar($advogado->id, 'users', $advogado->id, 'exclusao_cancelada');

        return back()->with('sucesso', 'Exclusao cancelada. O radar voltou a funcionar.');
    }
}
