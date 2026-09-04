<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoPrazo extends Model
{
    protected $table = 'tipos_prazo';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'em_dias_uteis' => 'boolean',
            'ativo' => 'boolean',
        ];
    }
}
