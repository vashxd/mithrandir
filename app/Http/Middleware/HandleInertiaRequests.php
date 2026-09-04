<?php

namespace App\Http\Middleware;

use App\Models\Membro;
use App\Models\Notificacao;
use App\Models\Prazo;
use App\Models\Publicacao;
use App\Models\User;
use App\Services\Push\WebPushService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Espacos de trabalho que esta pessoa pode abrir: o proprio e os de quem
     * a convidou.
     *
     * @return array<int, array<string, mixed>>
     */
    private function espacos(User $usuario): array
    {
        $proprio = [[
            'advogado_id' => $usuario->id,
            'nome' => $usuario->name,
            'papel' => 'titular',
            'proprio' => true,
        ]];

        $convidados = Membro::with('titular')
            ->where('usuario_id', $usuario->id)
            ->where('ativo', true)
            ->whereNotNull('aceito_em')
            ->get()
            ->map(fn (Membro $m) => [
                'advogado_id' => $m->titular_id,
                'nome' => $m->titular->name,
                'papel' => $m->papel,
                'proprio' => false,
            ])
            ->all();

        return array_merge($proprio, $convidados);
    }

    /**
     * Estado global do shell: quem esta logado, os badges da bottom bar e o
     * necessario para o push funcionar. Tudo o que a navegacao precisa saber
     * sem pedir de novo ao servidor.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $advogado = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $advogado ? [
                    'id' => $advogado->id,
                    'nome' => $advogado->name,
                    'email' => $advogado->email,
                    'oab' => $advogado->oab_formatada,
                    'timezone' => $advogado->timezone,
                    'buffer_padrao' => $advogado->buffer_padrao,
                    'aceitou_termo' => $advogado->aceitouTermo(),
                    'onboarding_concluido' => $advogado->onboarding_concluido_em !== null,
                ] : null,
            ],
            // Espaco de trabalho ativo e os espacos que a pessoa pode acessar.
            'contexto' => $advogado ? contexto()->paraTela() : null,
            'espacos' => fn () => $advogado ? $this->espacos($advogado) : [],
            'badges' => fn () => $advogado ? [
                'publicacoes' => Publicacao::visivel()->naoTriadas()->count(),
                'fatais' => Prazo::visivel()->abertos()
                    ->whereDate('data_fatal', '<=', now()->addDays(3))->count(),
                'conferencias' => contexto()->pode('prazo.cumprir')
                    ? Prazo::visivel()->aguardandoConferencia()->count()
                    : 0,
                'notificacoes' => Notificacao::doAdvogado(contexto()->advogadoId())
                    ->whereNull('lida_em')->count(),
            ] : null,
            'push' => [
                'chave_publica' => app(WebPushService::class)->chavePublica(),
            ],
            'flash' => [
                'sucesso' => fn () => $request->session()->get('sucesso'),
                'erro' => fn () => $request->session()->get('erro'),
            ],
            'app' => [
                'nome' => config('mithrandir.nome'),
                'termo_versao' => config('mithrandir.termo_versao'),
            ],
        ]);
    }
}
