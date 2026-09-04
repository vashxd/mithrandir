<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboxItem extends Model
{
    use Concerns\PertenceAoAdvogado;

    protected $table = 'outbox';

    protected $guarded = ['id'];

    /** Vigilancia e fila de sincronizacao pertencem ao titular. */
    protected function colunaDeRecorte(): ?string
    {
        return null;
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'criado_em' => 'datetime',
            'sincronizado_em' => 'datetime',
        ];
    }
}
