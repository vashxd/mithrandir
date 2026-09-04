<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RF-9.2: o aceite do termo, com a clausula de conferencia obrigatoria de
 * prazos, e pre-requisito para usar o app. Nao e disclaimer decorativo -
 * e desenho de risco (principio de produto n.2).
 */
class ExigirAceiteDoTermo
{
    public function handle(Request $request, Closure $next): Response
    {
        $advogado = $request->user();

        if ($advogado && ! $advogado->aceitouTermo()) {
            return redirect()->route('termo.mostrar');
        }

        return $next($request);
    }
}
