<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    protected $table = 'sync_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'executado_em' => 'datetime',
            'janela_inicio' => 'date',
            'janela_fim' => 'date',
        ];
    }

    public function watch(): BelongsTo
    {
        return $this->belongsTo(OabWatch::class, 'oab_watch_id');
    }
}
