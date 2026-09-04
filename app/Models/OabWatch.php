<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OabWatch extends Model
{
    use Concerns\PertenceAoAdvogado;

    protected $table = 'oab_watches';

    protected $guarded = ['id'];

    /** oab e nome vigiam o advogado; cliente vigia a parte no processo. */
    public const TIPOS = ['oab', 'nome', 'cliente'];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'ultima_sync_em' => 'datetime',
        ];
    }

    /** Vigilancia e fila de sincronizacao pertencem ao titular. */
    protected function colunaDeRecorte(): ?string
    {
        return null;
    }

    public function advogado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advogado_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function vigiaCliente(): bool
    {
        return $this->tipo === 'cliente';
    }

    public function rotulo(): string
    {
        return match ($this->tipo) {
            'oab' => "OAB {$this->termo}",
            'nome' => "Nome do advogado: {$this->termo}",
            'cliente' => "Cliente: {$this->termo}",
            default => $this->termo,
        };
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SyncLog::class)->orderByDesc('executado_em');
    }

    public function publicacoes(): HasMany
    {
        return $this->hasMany(Publicacao::class);
    }

    /**
     * RF-1.10: duas falhas seguidas significam radar cego.
     */
    public function estaCego(): bool
    {
        return $this->falhas_consecutivas >= 2;
    }
}
