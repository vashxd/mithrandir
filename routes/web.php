<?php

use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ConfiguracaoController;
use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\EquipeController;
use App\Http\Controllers\FinanceiroController;
use App\Http\Controllers\HojeController;
use App\Http\Controllers\NotificacaoController;
use App\Http\Controllers\PrazoController;
use App\Http\Controllers\ProcessoController;
use App\Http\Controllers\PublicacaoController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/entrar', [AuthController::class, 'mostrarLogin'])->name('login');
    Route::post('/entrar', [AuthController::class, 'entrar'])->middleware('throttle:6,1');
    Route::get('/cadastrar', [AuthController::class, 'mostrarCadastro'])->name('cadastro');
    Route::post('/cadastrar', [AuthController::class, 'cadastrar'])->middleware('throttle:6,1');
});

Route::post('/sair', [AuthController::class, 'sair'])->middleware('auth')->name('sair');

// Convite de equipe: o link precisa abrir mesmo para quem ainda nao entrou.
Route::get('/convite/{token}', [EquipeController::class, 'mostrarConvite'])->name('convite');
Route::post('/convite/{token}', [EquipeController::class, 'aceitarConvite'])
    ->middleware('auth')
    ->name('convite.aceitar');

// O termo fica fora do middleware de aceite, senao vira laco de redirecionamento.
Route::middleware('auth')->group(function () {
    Route::get('/termo', [AuthController::class, 'mostrarTermo'])->name('termo.mostrar');
    Route::post('/termo', [AuthController::class, 'aceitarTermo'])->name('termo.aceitar');
});

Route::middleware(['auth', 'termo.aceito'])->group(function () {
    // 1. Hoje - a tela que e o produto.
    Route::get('/', [HojeController::class, 'index'])->name('hoje');
    Route::get('/onboarding', [ConfiguracaoController::class, 'onboarding'])->name('onboarding');
    Route::post('/onboarding/concluir', [ConfiguracaoController::class, 'concluirOnboarding'])
        ->name('onboarding.concluir');

    // 2. Inbox de publicacoes (M1).
    Route::get('/publicacoes', [PublicacaoController::class, 'index'])->name('publicacoes');
    Route::get('/publicacoes/{publicacao}', [PublicacaoController::class, 'show'])->name('publicacoes.show');
    Route::post('/publicacoes/{publicacao}/triar', [PublicacaoController::class, 'triar'])
        ->name('publicacoes.triar');
    Route::post('/publicacoes/sincronizar', [PublicacaoController::class, 'sincronizar'])
        ->middleware('throttle:6,10')
        ->name('publicacoes.sincronizar');

    // Prazos (M2).
    Route::get('/prazos', [PrazoController::class, 'index'])->name('prazos');
    Route::post('/prazos', [PrazoController::class, 'store'])->name('prazos.store');
    Route::get('/prazos/simular', [PrazoController::class, 'simular'])->name('prazos.simular');
    Route::get('/prazos/{prazo}', [PrazoController::class, 'show'])->name('prazos.show');
    Route::post('/prazos/{prazo}/status', [PrazoController::class, 'mudarStatus'])->name('prazos.status');
    Route::post('/prazos/{prazo}/ajustar', [PrazoController::class, 'ajustar'])->name('prazos.ajustar');

    // 3. Agenda (M3).
    Route::get('/agenda', [AgendaController::class, 'index'])->name('agenda');
    Route::post('/agenda', [AgendaController::class, 'store'])->name('agenda.store');
    Route::patch('/agenda/{evento}', [AgendaController::class, 'update'])->name('agenda.update');
    Route::delete('/agenda/{evento}', [AgendaController::class, 'destroy'])->name('agenda.destroy');
    Route::get('/agenda/{evento}/ics', [AgendaController::class, 'ics'])->name('agenda.ics');

    // 4. Casos (M4).
    Route::get('/casos', [ProcessoController::class, 'index'])->name('processos');
    Route::post('/casos', [ProcessoController::class, 'store'])->name('processos.store');
    Route::get('/casos/novo', [ProcessoController::class, 'create'])->name('processos.create');
    Route::get('/casos/{processo}', [ProcessoController::class, 'show'])->name('processos.show');
    Route::patch('/casos/{processo}', [ProcessoController::class, 'update'])->name('processos.update');
    Route::post('/casos/{processo}/arquivar', [ProcessoController::class, 'arquivar'])->name('processos.arquivar');
    Route::post('/casos/{processo}/datajud', [ProcessoController::class, 'importarDataJud'])
        ->middleware('throttle:10,10')
        ->name('processos.datajud');
    Route::post('/casos/{processo}/checklist', [ProcessoController::class, 'aplicarChecklist'])
        ->name('processos.checklist');

    // 5. Clientes (M5).
    Route::get('/clientes', [ClienteController::class, 'index'])->name('clientes');
    Route::post('/clientes', [ClienteController::class, 'store'])->name('clientes.store');
    Route::get('/clientes/{cliente}', [ClienteController::class, 'show'])->name('clientes.show');
    Route::patch('/clientes/{cliente}', [ClienteController::class, 'update'])->name('clientes.update');
    Route::post('/clientes/{cliente}/atendimentos', [ClienteController::class, 'registrarAtendimento'])
        ->name('clientes.atendimentos');
    Route::get('/clientes/{cliente}/status/{processo}', [ClienteController::class, 'textoStatus'])
        ->name('clientes.status');
    Route::get('/clientes/{cliente}/vigilancia/previa', [ClienteController::class, 'previaVigilancia'])
        ->middleware('throttle:20,10')
        ->name('clientes.vigilancia.previa');
    Route::post('/clientes/{cliente}/vigilancia', [ClienteController::class, 'alternarVigilancia'])
        ->name('clientes.vigilancia');

    // Equipe e espaco de trabalho.
    Route::post('/contexto', [EquipeController::class, 'trocarContexto'])->name('contexto.trocar');
    Route::get('/equipe', [EquipeController::class, 'index'])->name('equipe');
    Route::post('/equipe', [EquipeController::class, 'convidar'])->name('equipe.convidar');
    Route::patch('/equipe/{membro}', [EquipeController::class, 'atualizar'])->name('equipe.atualizar');
    Route::delete('/equipe/{membro}', [EquipeController::class, 'remover'])->name('equipe.remover');

    // Conferencia de prazo (equipe).
    Route::post('/prazos/{prazo}/conferencia', [PrazoController::class, 'pedirConferencia'])
        ->name('prazos.conferencia');
    Route::post('/prazos/{prazo}/conferencia/responder', [PrazoController::class, 'responderConferencia'])
        ->name('prazos.conferencia.responder');
    Route::post('/prazos/{prazo}/responsavel', [PrazoController::class, 'definirResponsavel'])
        ->name('prazos.responsavel');

    // 6. Financeiro (M7). Dinheiro do escritorio nao e assunto de estagiario.
    Route::middleware('pode:financeiro.ver')->group(function () {
        Route::get('/financeiro', [FinanceiroController::class, 'index'])->name('financeiro');
        Route::post('/financeiro/honorarios', [FinanceiroController::class, 'storeHonorario'])
            ->name('financeiro.honorarios');
        Route::post('/financeiro/parcelas/{parcela}/baixar', [FinanceiroController::class, 'baixarParcela'])
            ->name('financeiro.parcelas.baixar');
        Route::post('/financeiro/despesas', [FinanceiroController::class, 'storeDespesa'])
            ->name('financeiro.despesas');
    });

    // Documentos (M6).
    Route::post('/documentos', [DocumentoController::class, 'store'])->name('documentos.store');
    Route::get('/documentos/{documento}', [DocumentoController::class, 'download'])->name('documentos.download');
    Route::delete('/documentos/{documento}', [DocumentoController::class, 'destroy'])->name('documentos.destroy');

    // Notificacoes e push (M8).
    Route::get('/notificacoes', [NotificacaoController::class, 'index'])->name('notificacoes');
    Route::post('/notificacoes/{notificacao}/lida', [NotificacaoController::class, 'marcarLida'])
        ->name('notificacoes.lida');
    Route::post('/push/inscrever', [PushController::class, 'inscrever'])->name('push.inscrever');
    Route::post('/push/cancelar', [PushController::class, 'cancelar'])->name('push.cancelar');
    Route::post('/push/testar', [PushController::class, 'testar'])->name('push.testar');

    // Configuracoes e conta (M9).
    Route::get('/configuracoes', [ConfiguracaoController::class, 'index'])->name('configuracoes');
    Route::patch('/configuracoes', [ConfiguracaoController::class, 'update'])->name('configuracoes.update');
    Route::get('/configuracoes/radar', [ConfiguracaoController::class, 'radar'])->name('configuracoes.radar');
    Route::post('/configuracoes/radar', [ConfiguracaoController::class, 'storeWatch'])
        ->name('configuracoes.radar.store');
    Route::delete('/configuracoes/radar/{watch}', [ConfiguracaoController::class, 'destroyWatch'])
        ->name('configuracoes.radar.destroy');
    Route::get('/configuracoes/feriados', [ConfiguracaoController::class, 'feriados'])
        ->name('configuracoes.feriados');
    Route::post('/configuracoes/feriados', [ConfiguracaoController::class, 'storeFeriado'])
        ->name('configuracoes.feriados.store');
    Route::post('/configuracoes/datajud', [ConfiguracaoController::class, 'salvarChaveDataJud'])
        ->name('configuracoes.datajud');
    Route::get('/configuracoes/exportar', [ConfiguracaoController::class, 'exportar'])
        ->name('configuracoes.exportar');
    Route::post('/configuracoes/excluir-conta', [ConfiguracaoController::class, 'solicitarExclusao'])
        ->name('configuracoes.excluir');
    Route::post('/configuracoes/cancelar-exclusao', [ConfiguracaoController::class, 'cancelarExclusao'])
        ->name('configuracoes.cancelar-exclusao');

    // Sincronizacao offline (outbox do cliente).
    Route::post('/sync/outbox', [SyncController::class, 'receber'])->name('sync.outbox');
    Route::get('/sync/snapshot', [SyncController::class, 'snapshot'])->name('sync.snapshot');
});
