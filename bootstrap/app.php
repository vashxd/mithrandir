<?php

use App\Http\Middleware\ExigirAceiteDoTermo;
use App\Http\Middleware\ExigirPermissao;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolverContexto;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Atras de tunel/proxy (Cloudflare), o esquema real vem no cabecalho.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            // Resolve o espaco de trabalho ativo antes de qualquer consulta.
            ResolverContexto::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'termo.aceito' => ExigirAceiteDoTermo::class,
            'pode' => ExigirPermissao::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
