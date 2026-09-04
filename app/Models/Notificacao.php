<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notificacao extends Model
{
    use Concerns\PertenceAoAdvogado;

    protected $table = 'notificacoes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'agendada_para' => 'datetime',
            'enviada_em' => 'datetime',
            'lida_em' => 'datetime',
        ];
    }

    /** Notificacao e aparelho sao do titular, nunca da equipe. */
    protected function colunaDeRecorte(): ?string
    {
        return null;
    }

    public function advogado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advogado_id');
    }

    public function scopePendentes($query)
    {
        return $query->whereNull('enviada_em')->where('agendada_para', '<=', now());
    }
}
