<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Atendimento extends Model
{
    use Concerns\PertenceAoAdvogado;

    protected $table = 'atendimentos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'date'];
    }

    /**
     * @param  array<int, int>  $permitidos
     */
    protected function aplicarRecorte(Builder $query, array $permitidos): Builder
    {
        return $query->where(function ($q) use ($permitidos) {
            $q->whereIn('processo_id', $permitidos)
                ->orWhereHas('cliente.processos', fn ($p) => $p->whereIn('processos.id', $permitidos));
        });
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }
}
