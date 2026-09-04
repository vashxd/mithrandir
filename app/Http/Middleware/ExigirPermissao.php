<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trava de permissao na rota, para a restricao ficar visivel em routes/web.php
 * em vez de escondida dentro do controller.
 *
 * Uso: ->middleware('pode:financeiro.ver')
 */
class ExigirPermissao
{
    public function handle(Request $request, Closure $next, string $acao): Response
    {
        abort_unless(contexto()->pode($acao), 403);

        return $next($request);
    }
}
