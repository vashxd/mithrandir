<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feriado extends Model
{
    protected $table = 'feriados';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'suspensao_expediente' => 'boolean',
        ];
    }

    public function scopeVigentes($query, string $de, string $ate)
    {
        return $query->whereBetween('data', [$de, $ate]);
    }
}
