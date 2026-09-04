<?php

namespace App\Http\Controllers;

use App\Models\Notificacao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificacaoController extends Controller
{
    public function index(Request $request): Response
    {
        $notificacoes = Notificacao::doAdvogado(contexto()->advogadoId())
            ->orderByDesc('agendada_para')
            ->paginate(30)
            ->through(fn (Notificacao $n) => [
                'id' => $n->id,
                'tipo' => $n->tipo,
                'titulo' => $n->titulo,
                'corpo' => $n->corpo,
                'url' => $n->url,
                'canal' => $n->canal,
                'agendada_para' => $n->agendada_para?->toIso8601String(),
                'enviada_em' => $n->enviada_em?->toIso8601String(),
                'lida' => $n->lida_em !== null,
            ]);

        return Inertia::render('Notificacoes/Index', [
            'notificacoes' => $notificacoes,
        ]);
    }

    public function marcarLida(Request $request, Notificacao $notificacao): RedirectResponse
    {
        abort_unless($notificacao->advogado_id === contexto()->advogadoId(), 403);

        $notificacao->forceFill(['lida_em' => now()])->save();

        return back();
    }
}
