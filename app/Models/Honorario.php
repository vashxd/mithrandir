<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Honorario extends Model
{
    use Concerns\PertenceAoAdvogado;

    protected $table = 'honorarios';

    protected $guarded = ['id'];

    public const TIPOS = ['fixo', 'parcelado', 'exito', 'misto'];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'percentual_exito' => 'decimal:2',
            'primeiro_vencimento' => 'date',
        ];
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function parcelas(): HasMany
    {
        return $this->hasMany(Parcela::class)->orderBy('numero');
    }
}
