<?php

namespace App\Http\Controllers;

use App\Models\Notificacao;
use App\Models\PushSubscription;
use App\Services\Push\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    public function inscrever(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        $inscricao = PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $dados['endpoint'])],
            [
                // O aparelho e de quem esta logado, nao do espaco ativo.
                'advogado_id' => $request->user()->id,
                'endpoint' => $dados['endpoint'],
                'p256dh' => $dados['keys']['p256dh'],
                'auth' => $dados['keys']['auth'],
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'falhas' => 0,
            ]
        );

        return response()->json(['ok' => true, 'id' => $inscricao->id]);
    }

    public function cancelar(Request $request): JsonResponse
    {
        $dados = $request->validate(['endpoint' => ['required', 'string']]);

        PushSubscription::doAdvogado($request->user()->id)
            ->where('endpoint_hash', hash('sha256', $dados['endpoint']))
            ->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Onboarding (RF-8.3): a pessoa precisa VER a notificacao chegar antes de
     * confiar o controle de prazo ao app. No iOS isso so funciona com o PWA
     * instalado na tela de inicio.
     */
    public function testar(Request $request, WebPushService $push): JsonResponse
    {
        $notificacao = Notificacao::create([
            'advogado_id' => $request->user()->id,
            'tipo' => 'teste',
            'titulo' => 'Notificacao de teste',
            'corpo' => 'Se voce esta lendo isto, o push esta funcionando neste aparelho.',
            'url' => '/',
            'chave_dedup' => 'teste:'.$request->user()->id.':'.now()->timestamp,
            'agendada_para' => now(),
        ]);

        $entregues = $push->enviar($notificacao);

        if ($entregues > 0) {
            $notificacao->forceFill(['enviada_em' => now()])->save();
        }

        return response()->json([
            'ok' => $entregues > 0,
            'entregues' => $entregues,
            'mensagem' => $entregues > 0
                ? 'Enviada. Deve chegar em instantes.'
                : 'Nenhum dispositivo inscrito recebeu. No iPhone, instale o app na tela de inicio primeiro.',
        ]);
    }
}
