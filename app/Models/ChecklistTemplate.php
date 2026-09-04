<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistTemplate extends Model
{
    protected $table = 'checklist_templates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['itens' => 'array'];
    }
}
