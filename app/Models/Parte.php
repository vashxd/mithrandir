<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Parte extends Model
{
    protected $table = 'partes';

    protected $guarded = ['id'];

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }
}
