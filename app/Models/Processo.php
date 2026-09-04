<?php

namespace App\Models;

use App\Support\NumeroCnj;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Processo extends Model
{
    use Concerns\PertenceAoAdvogado, HasFactory;

    protected $table = 'processos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'valor_causa' => 'decimal:2',
            'segredo_justica' => 'boolean',
            'datajud_sincronizado_em' => 'datetime',
            'arquivado_em' => 'datetime',
        ];
    }

    /** O recorte de um processo e ele mesmo. */
    protected function colunaDeRecorte(): ?string
    {
        return 'id';
    }

    public function advogado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advogado_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function partes(): HasMany
    {
        return $this->hasMany(Parte::class);
    }

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(Movimentacao::class)->orderByDesc('data');
    }

    public function prazos(): HasMany
    {
        return $this->hasMany(Prazo::class);
    }

    public function publicacoes(): HasMany
    {
        return $this->hasMany(Publicacao::class);
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(Documento::class);
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('ordem');
    }

    public function honorarios(): HasMany
    {
        return $this->hasMany(Honorario::class);
    }

    public function despesas(): HasMany
    {
        return $this->hasMany(Despesa::class);
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(Evento::class);
    }

    public function getNumeroFormatadoAttribute(): ?string
    {
        return $this->numero_cnj ? NumeroCnj::formatar($this->numero_cnj) : null;
    }

    public function getRotuloAttribute(): string
    {
        return $this->titulo
            ?: ($this->numero_formatado ?: 'Processo #'.$this->id);
    }
}
