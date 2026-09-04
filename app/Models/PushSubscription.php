<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    use Concerns\PertenceAoAdvogado;

    protected $table = 'push_subscriptions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ultima_entrega_em' => 'datetime'];
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
}
