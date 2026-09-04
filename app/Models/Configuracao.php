<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Config editavel em runtime. Existe por causa da secao 7.2: a chave publica do
 * DataJud pode ser trocada pelo CNJ a qualquer momento e nao pode exigir deploy.
 */
class Configuracao extends Model
{
    protected $table = 'configuracoes';

    protected $primaryKey = 'chave';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function valor(string $chave, ?string $padrao = null): ?string
    {
        return Cache::remember("config:{$chave}", 300, function () use ($chave, $padrao) {
            return self::find($chave)?->valor ?? $padrao;
        });
    }

    public static function definir(string $chave, ?string $valor, ?string $descricao = null): void
    {
        self::updateOrCreate(['chave' => $chave], array_filter([
            'valor' => $valor,
            'descricao' => $descricao,
        ], fn ($v) => $v !== null));

        Cache::forget("config:{$chave}");
    }
}
