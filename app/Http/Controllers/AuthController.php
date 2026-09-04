<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\OabWatch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function mostrarLogin(): Response
    {
        return Inertia::render('Auth/Entrar');
    }

    public function entrar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ], [], ['email' => 'e-mail', 'password' => 'senha']);

        if (! Auth::attempt($dados, $request->boolean('lembrar'))) {
            return back()->withErrors(['email' => 'E-mail ou senha invalidos.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('hoje'));
    }

    public function mostrarCadastro(): Response
    {
        return Inertia::render('Auth/Cadastrar', [
            'ufs' => $this->ufs(),
        ]);
    }

    /**
     * RF-9.1: cadastro com OAB/UF.
     * RF-1.1: o primeiro termo de vigilancia nasce junto com a conta - sem ele
     * o radar nao busca nada e o app nao serve para nada.
     */
    public function cadastrar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'oab' => ['required', 'string', 'max:20'],
            'uf' => ['required', 'string', 'size:2'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'aceite_termo' => ['accepted'],
        ], [
            'aceite_termo.accepted' => 'E preciso aceitar o termo de uso para criar a conta.',
        ], [
            'name' => 'nome',
            'password' => 'senha',
        ]);

        $advogado = DB::transaction(function () use ($dados) {
            $advogado = User::create([
                'name' => $dados['name'],
                'email' => $dados['email'],
                'password' => $dados['password'],
                'oab' => preg_replace('/\s+/', '', $dados['oab']),
                'uf' => strtoupper($dados['uf']),
                'timezone' => config('mithrandir.timezone'),
                'buffer_padrao' => config('mithrandir.prazos.buffer_padrao'),
                'aceite_termo_em' => now(),
                'aceite_termo_versao' => config('mithrandir.termo_versao'),
            ]);

            $this->criarWatchesIniciais($advogado);

            Auditoria::registrar($advogado->id, 'users', $advogado->id, 'cadastro');

            return $advogado;
        });

        Auth::login($advogado);
        $request->session()->regenerate();

        return redirect()->route('onboarding');
    }

    /**
     * Armadilha conhecida da secao 7.1: tribunais gravam o numero da OAB ora
     * como "123456", ora como "123456-O". Cadastrar as variacoes desde o
     * inicio evita publicacao invisivel.
     */
    private function criarWatchesIniciais(User $advogado): void
    {
        $numero = preg_replace('/\D/', '', $advogado->oab);

        $variacoes = array_unique(array_filter([
            $advogado->oab,
            $numero,
            $numero.'-O',
            ltrim($numero, '0') ?: null,
        ]));

        foreach ($variacoes as $termo) {
            OabWatch::create([
                'advogado_id' => $advogado->id,
                'termo' => $termo,
                'tipo' => 'oab',
                'uf' => $advogado->uf,
                'ativo' => true,
            ]);
        }

        OabWatch::create([
            'advogado_id' => $advogado->id,
            'termo' => $advogado->name,
            'tipo' => 'nome',
            'uf' => $advogado->uf,
            // Busca por nome gera muito falso positivo; nasce desligada.
            'ativo' => false,
        ]);
    }

    public function sair(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function mostrarTermo(Request $request): Response
    {
        return Inertia::render('Auth/Termo', [
            'ja_aceitou' => $request->user()->aceitouTermo(),
            'versao' => config('mithrandir.termo_versao'),
        ]);
    }

    /**
     * RF-9.2: aceite explicito, com a clausula de conferencia obrigatoria.
     */
    public function aceitarTermo(Request $request): RedirectResponse
    {
        $request->validate([
            'aceite' => ['accepted'],
        ], [
            'aceite.accepted' => 'E preciso aceitar o termo para continuar.',
        ]);

        $request->user()->forceFill([
            'aceite_termo_em' => now(),
            'aceite_termo_versao' => config('mithrandir.termo_versao'),
        ])->save();

        Auditoria::registrar($request->user()->id, 'users', $request->user()->id, 'aceite_termo', null, [
            'versao' => config('mithrandir.termo_versao'),
        ]);

        return redirect()->route('hoje');
    }

    /**
     * @return array<int, string>
     */
    private function ufs(): array
    {
        return [
            'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS',
            'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
            'SP', 'SE', 'TO',
        ];
    }
}
