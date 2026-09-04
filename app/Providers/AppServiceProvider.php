<?php

namespace App\Providers;

use App\Support\Contexto;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Um contexto por requisicao: resolvido uma vez, lido em todo lugar.
        $this->app->scoped(Contexto::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
