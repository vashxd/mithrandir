<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Vinculo de uma pessoa ao espaco de trabalho de um titular.
 *
 * Papeis:
 *  - advogado    trabalha como o titular, pode cumprir prazo e ver financeiro
 *  - estagiario  ve e adianta, mas nao fecha prazo nem ve dinheiro
 */
class Membro extends Model
{
    protected $table = 'membros';

    protected $guarded = ['id'];

    public const PAPEIS = ['advogado', 'estagiario'];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'acesso_total' => 'boolean',
            'convidado_em' => 'datetime',
            'aceito_em' => 'datetime',
            'ultimo_acesso_em' => 'datetime',
        ];
    }

    public function titular(): BelongsTo
    {
        return $this->belongsTo(User::class, 'titular_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function processos(): BelongsToMany
    {
        return $this->belongsToMany(Processo::class, 'acessos_processo', 'membro_id', 'processo_id')
            ->withTimestamps();
    }

    public function aceito(): bool
    {
        return $this->aceito_em !== null && $this->usuario_id !== null;
    }

    public function pendente(): bool
    {
        return ! $this->aceito();
    }

    public function ehEstagiario(): bool
    {
        return $this->papel === 'estagiario';
    }

    public function rotuloPapel(): string
    {
        return match ($this->papel) {
            'advogado' => 'Advogado',
            'estagiario' => 'Estagiario',
            default => $this->papel,
        };
    }

    public static function novoToken(): string
    {
        return Str::random(48);
    }

    /**
     * Ids dos processos que este membro enxerga. `null` significa a carteira
     * inteira - e a ausencia de filtro, nao uma lista vazia.
     *
     * @return array<int, int>|null
     */
    public function processosVisiveis(): ?array
    {
        if ($this->acesso_total) {
            return null;
        }

        return $this->processos()->pluck('processos.id')->all();
    }
}
