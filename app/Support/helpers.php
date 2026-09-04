<?php

use App\Support\Contexto;

if (! function_exists('contexto')) {
    /**
     * Espaco de trabalho ativo. Ver App\Support\Contexto.
     */
    function contexto(): Contexto
    {
        return app(Contexto::class);
    }
}
