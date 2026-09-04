<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prazo extends Model
{
    use Concerns\PertenceAoAdvogado;

    protected $table = 'prazos';

    protected $guarded = ['id'];

    public const STATUS = ['aberto', 'em_andamento', 'cumprido', 'perdido', 'prejudicado'];

    public const ABERTOS = ['aberto', 'em_andamento'];

    protected function casts(): array
    {
        return [
            'data_disponibilizacao' => 'date',
            'data_publicacao' => 'date',
            'data_inicio' => 'date',
            'data_fatal' => 'date',
            'data_alvo' => 'date',
            'data_fatal_calculada' => 'date',
            'em_dias_uteis' => 'boolean',
            'ajustado_manualmente' => 'boolean',
            'precisa_revisao' => 'boolean',
            'aguardando_conferencia' => 'boolean',
            'conferencia_solicitada_em' => 'datetime',
            'cadeia_origem' => 'array',
            'cumprido_em' => 'datetime',
        ];
    }

    protected $appends = ['dias_restantes', 'criticidade'];

    public function advogado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advogado_id');
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function publicacao(): BelongsTo
    {
        return $this->belongsTo(Publicacao::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function solicitanteDaConferencia(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conferencia_solicitada_por');
    }

    public function scopeAguardandoConferencia($query)
    {
        return $query->where('aguardando_conferencia', true);
    }

    public function scopeDoResponsavel($query, int $usuarioId)
    {
        return $query->where('responsavel_id', $usuarioId);
    }

    public function tipoPrazo(): BelongsTo
    {
        return $this->belongsTo(TipoPrazo::class, 'tipo_prazo_id');
    }

    public function scopeAbertos($query)
    {
        return $query->whereIn('status', self::ABERTOS);
    }

    public function getDiasRestantesAttribute(): ?int
    {
        if (! $this->data_fatal) {
            return null;
        }

        // Dia contra dia, sem hora: o fuso do "hoje" e o da data materializada
        // no banco nao coincidem, e a sobra de horas truncaria a contagem.
        $hoje = CarbonImmutable::today(config('mithrandir.timezone'))->toDateString();
        $fatal = CarbonImmutable::parse($this->data_fatal)->toDateString();

        return (int) CarbonImmutable::parse($hoje)->diffInDays(CarbonImmutable::parse($fatal), false);
    }

    /**
     * RF-3.3: fatal em ate 3 dias e vermelho.
     */
    public function getCriticidadeAttribute(): string
    {
        if (in_array($this->status, ['cumprido', 'prejudicado'], true)) {
            return 'concluido';
        }

        // Aguardando conferencia NAO e concluido: o titular ainda responde
        // por ele, entao a cor de risco permanece.

        $dias = $this->dias_restantes;

        if ($dias === null) {
            return 'neutro';
        }

        return match (true) {
            $dias < 0 => 'vencido',
            $dias <= 3 => 'critico',
            $dias <= 7 => 'atencao',
            default => 'normal',
        };
    }
}
