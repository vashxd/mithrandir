<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Movimentacao extends Model
{
    protected $table = 'movimentacoes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'date'];
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }
}
