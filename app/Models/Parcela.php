<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Parcela extends Model
{
    use Concerns\PertenceAoAdvogado;

    protected $table = 'parcelas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'valor_pago' => 'decimal:2',
            'vencimento' => 'date',
            'pago_em' => 'date',
            'cobranca_lembrada_em' => 'datetime',
        ];
    }

    protected $appends = ['situacao'];

    public function honorario(): BelongsTo
    {
        return $this->belongsTo(Honorario::class);
    }

    public function scopeEmAberto($query)
    {
        return $query->whereNull('pago_em');
    }

    public function scopeVencidas($query)
    {
        return $query->whereNull('pago_em')->whereDate('vencimento', '<', CarbonImmutable::today());
    }

    public function getSituacaoAttribute(): string
    {
        if ($this->pago_em) {
            return 'pago';
        }

        $vencimento = CarbonImmutable::parse($this->vencimento);

        return match (true) {
            $vencimento->lessThan(CarbonImmutable::today()) => 'vencido',
            $vencimento->lessThanOrEqualTo(CarbonImmutable::today()->addDays(7)) => 'vencendo',
            default => 'a_vencer',
        };
    }
}
