<?php

use App\Support\Contexto;
use Illuminate\Support\Facades\DB;

if (! function_exists('contexto')) {
    /**
     * Espaco de trabalho ativo. Ver App\Support\Contexto.
     */
    function contexto(): Contexto
    {
        return app(Contexto::class);
    }
}

if (! function_exists('op_like')) {
    /**
     * Operador de busca textual que ignora maiusculas em qualquer banco.
     * No SQLite o LIKE ja e insensivel a caixa; no Postgres precisa de ILIKE.
     */
    function op_like(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }
}
