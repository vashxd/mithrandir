<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Despesa extends Model
{
    use Concerns\PertenceAoAdvogado;

    protected $table = 'despesas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'data' => 'date',
            'reembolsavel' => 'boolean',
            'reembolsada_em' => 'date',
        ];
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }
}
