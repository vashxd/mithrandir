<?php

namespace App\Http\Middleware;

use App\Support\Contexto;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Descobre, a cada requisicao, em qual espaco de trabalho a pessoa esta.
 *
 * Roda antes de tudo que consulta dados: o `advogado_id` usado nos escopos sai
 * daqui, nao mais direto do usuario logado.
 */
class ResolverContexto
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        app(Contexto::class)->para(
            $usuario,
            $usuario ? $request->session()->get(Contexto::CHAVE_SESSAO) : null
        );

        return $next($request);
    }
}
