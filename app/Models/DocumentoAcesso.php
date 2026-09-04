<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentoAcesso extends Model
{
    protected $table = 'documento_acessos';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['em' => 'datetime'];
    }
}
