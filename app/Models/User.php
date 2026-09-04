<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * O "advogado" da especificacao (secao 9). `advogado_id` em toda tabela
 * aponta para esta chave.
 */
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'aceite_termo_em' => 'datetime',
            'exclusao_solicitada_em' => 'datetime',
            'onboarding_concluido_em' => 'datetime',
            'preferencias_notificacao' => 'array',
            'percentual_imposto' => 'decimal:2',
        ];
    }

    public function watches(): HasMany
    {
        return $this->hasMany(OabWatch::class, 'advogado_id');
    }

    public function publicacoes(): HasMany
    {
        return $this->hasMany(Publicacao::class, 'advogado_id');
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class, 'advogado_id');
    }

    public function processos(): HasMany
    {
        return $this->hasMany(Processo::class, 'advogado_id');
    }

    public function prazos(): HasMany
    {
        return $this->hasMany(Prazo::class, 'advogado_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(Evento::class, 'advogado_id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(Documento::class, 'advogado_id');
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class, 'advogado_id');
    }

    public function notificacoes(): HasMany
    {
        return $this->hasMany(Notificacao::class, 'advogado_id');
    }

    public function getOabFormatadaAttribute(): ?string
    {
        return $this->oab ? "{$this->oab}/{$this->uf}" : null;
    }

    public function aceitouTermo(): bool
    {
        return $this->aceite_termo_em !== null;
    }

    /**
     * Preferencia de notificacao por tipo, com o padrao ligado.
     */
    public function querReceber(string $tipo): bool
    {
        return (bool) ($this->preferencias_notificacao[$tipo] ?? true);
    }
}
