<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Evento extends Model
{
    use Concerns\PertenceAoAdvogado;

    protected $table = 'eventos';

    protected $guarded = ['id'];

    public const TIPOS = ['prazo', 'audiencia', 'compromisso', 'tarefa'];

    protected function casts(): array
    {
        return [
            'inicio' => 'datetime',
            'fim' => 'datetime',
            'dia_inteiro' => 'boolean',
            'concluido' => 'boolean',
        ];
    }

    public function eventable(): MorphTo
    {
        return $this->morphTo();
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function scopeEntre($query, $de, $ate)
    {
        return $query->whereBetween('inicio', [$de, $ate]);
    }
}
