<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Membro;
use App\Models\Processo;
use App\Models\User;
use App\Support\Contexto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Equipe: quem mais trabalha dentro do espaco do titular, e em quais casos.
 *
 * Duas travas de desenho:
 *  - so o titular gere a equipe (colaborador nao convida colaborador);
 *  - o acesso nasce por caso, nao pela carteira inteira (EOAB art. 34: o
 *    sigilo e do cliente, e um estagiario nao precisa ver todos eles).
 */
class EquipeController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(contexto()->pode('equipe.gerir'), 403);

        $titular = $request->user();

        $membros = Membro::with(['usuario', 'processos'])
            ->where('titular_id', $titular->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Membro $m) => [
                'id' => $m->id,
                'nome' => $m->usuario?->name ?? $m->nome,
                'email' => $m->email,
                'papel' => $m->papel,
                'papel_rotulo' => $m->rotuloPapel(),
                'ativo' => (bool) $m->ativo,
                'acesso_total' => (bool) $m->acesso_total,
                'pendente' => $m->pendente(),
                'link_convite' => $m->pendente() && $m->token_convite
                    ? url("/convite/{$m->token_convite}")
                    : null,
                'aceito_em' => $m->aceito_em?->toIso8601String(),
                'ultimo_acesso_em' => $m->ultimo_acesso_em?->toIso8601String(),
                'processos' => $m->processos->map(fn (Processo $p) => [
                    'id' => $p->id,
                    'rotulo' => $p->rotulo,
                ]),
            ]);

        return Inertia::render('Equipe/Index', [
            'membros' => $membros,
            'processos' => Processo::doAdvogado($titular->id)
                ->whereNull('arquivado_em')
                ->orderByDesc('updated_at')
                ->get(['id', 'titulo', 'numero_cnj', 'cliente_id'])
                ->map(fn (Processo $p) => ['id' => $p->id, 'rotulo' => $p->rotulo]),
        ]);
    }

    public function convidar(Request $request): RedirectResponse
    {
        abort_unless(contexto()->pode('equipe.gerir'), 403);

        $titular = $request->user();

        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('membros')->where('titular_id', $titular->id),
                Rule::notIn([$titular->email]),
            ],
            'papel' => ['required', Rule::in(Membro::PAPEIS)],
            'acesso_total' => ['boolean'],
            'processos' => ['array'],
            'processos.*' => ['integer', Rule::exists('processos', 'id')->where('advogado_id', $titular->id)],
        ], [
            'email.unique' => 'Esta pessoa ja foi convidada.',
            'email.not_in' => 'Voce nao precisa convidar a si mesmo.',
        ]);

        $membro = Membro::create([
            'titular_id' => $titular->id,
            // Se a pessoa ja tem conta, o vinculo ja nasce apontando para ela.
            'usuario_id' => User::where('email', $dados['email'])->value('id'),
            'email' => $dados['email'],
            'nome' => $dados['nome'],
            'papel' => $dados['papel'],
            'acesso_total' => $dados['acesso_total'] ?? false,
            'ativo' => true,
            'token_convite' => Membro::novoToken(),
            'convidado_em' => now(),
        ]);

        if (! ($dados['acesso_total'] ?? false)) {
            $membro->processos()->sync($dados['processos'] ?? []);
        }

        Auditoria::registrar($titular->id, 'membros', $membro->id, 'convidado', null, [
            'email' => $membro->email,
            'papel' => $membro->papel,
        ]);

        return back()->with('sucesso', "Convite criado para {$membro->email}. Copie o link e envie.");
    }

    public function atualizar(Request $request, Membro $membro): RedirectResponse
    {
        abort_unless(contexto()->pode('equipe.gerir'), 403);
        abort_unless($membro->titular_id === $request->user()->id, 403);

        $dados = $request->validate([
            'papel' => ['sometimes', Rule::in(Membro::PAPEIS)],
            'ativo' => ['sometimes', 'boolean'],
            'acesso_total' => ['sometimes', 'boolean'],
            'processos' => ['sometimes', 'array'],
            'processos.*' => ['integer', Rule::exists('processos', 'id')->where('advogado_id', $request->user()->id)],
        ]);

        $antes = $membro->toArray();
        $membro->update(collect($dados)->except('processos')->all());

        if (array_key_exists('processos', $dados)) {
            $membro->processos()->sync($membro->acesso_total ? [] : $dados['processos']);
        }

        Auditoria::registrar($request->user()->id, 'membros', $membro->id, 'atualizado', $antes, $membro->toArray());

        return back()->with('sucesso', 'Acesso atualizado.');
    }

    public function remover(Request $request, Membro $membro): RedirectResponse
    {
        abort_unless(contexto()->pode('equipe.gerir'), 403);
        abort_unless($membro->titular_id === $request->user()->id, 403);

        Auditoria::registrar($request->user()->id, 'membros', $membro->id, 'removido', $membro->toArray(), null);

        $membro->delete();

        return back()->with('sucesso', 'Acesso encerrado. O historico do que a pessoa fez fica registrado.');
    }

    /* ---------------- Aceite do convite ---------------- */

    public function mostrarConvite(Request $request, string $token): Response|RedirectResponse
    {
        $membro = Membro::with('titular')->where('token_convite', $token)->first();

        if (! $membro || ! $membro->ativo) {
            return redirect()->route('login')->with('erro', 'Convite invalido ou ja encerrado.');
        }

        return Inertia::render('Equipe/Convite', [
            'convite' => [
                'token' => $token,
                'titular' => $membro->titular->name,
                'oab' => $membro->titular->oab_formatada,
                'email' => $membro->email,
                'papel' => $membro->papel,
                'papel_rotulo' => $membro->rotuloPapel(),
                'acesso_total' => (bool) $membro->acesso_total,
                'qtd_processos' => $membro->processos()->count(),
                'ja_aceito' => $membro->aceito(),
            ],
            'logado_como' => $request->user()?->email,
        ]);
    }

    public function aceitarConvite(Request $request, string $token): RedirectResponse
    {
        $membro = Membro::where('token_convite', $token)->where('ativo', true)->firstOrFail();
        $usuario = $request->user();

        if (! $usuario) {
            return redirect()->route('login')
                ->with('erro', 'Entre na sua conta (ou crie uma) com o e-mail do convite para aceitar.');
        }

        if (! hash_equals(mb_strtolower($membro->email), mb_strtolower($usuario->email))) {
            return back()->with(
                'erro',
                "Este convite foi enviado para {$membro->email}. Entre com essa conta para aceitar."
            );
        }

        $membro->forceFill([
            'usuario_id' => $usuario->id,
            'aceito_em' => now(),
            'token_convite' => null,
        ])->save();

        Auditoria::registrar($membro->titular_id, 'membros', $membro->id, 'convite_aceito', null, [
            'usuario_id' => $usuario->id,
        ], $usuario->id);

        $request->session()->put(Contexto::CHAVE_SESSAO, $membro->titular_id);

        return redirect()->route('hoje')
            ->with('sucesso', "Voce entrou no espaco de {$membro->titular->name}.");
    }

    /* ---------------- Troca de espaco de trabalho ---------------- */

    public function trocarContexto(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'advogado_id' => ['required', 'integer'],
        ]);

        $usuario = $request->user();
        $alvo = (int) $dados['advogado_id'];

        // O proprio espaco, ou um em que a pessoa tenha vinculo ativo.
        $permitido = $alvo === $usuario->id
            || Membro::where('usuario_id', $usuario->id)
                ->where('titular_id', $alvo)
                ->where('ativo', true)
                ->whereNotNull('aceito_em')
                ->exists();

        abort_unless($permitido, 403);

        $request->session()->put(Contexto::CHAVE_SESSAO, $alvo);

        Membro::where('usuario_id', $usuario->id)
            ->where('titular_id', $alvo)
            ->update(['ultimo_acesso_em' => now()]);

        return redirect()->route('hoje');
    }
}
