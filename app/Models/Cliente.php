<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    use Concerns\PertenceAoAdvogado, HasFactory;

    protected $table = 'clientes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'contatos' => 'array',
            'endereco' => 'array',
            'nascimento' => 'date',
            'arquivado_em' => 'datetime',
        ];
    }

    /**
     * Colaborador com recorte ve o cliente cujo caso ele acompanha - e so.
     * A carteira de clientes nao vaza junto com o acesso a um processo.
     *
     * @param  array<int, int>  $permitidos
     */
    protected function aplicarRecorte(Builder $query, array $permitidos): Builder
    {
        return $query->whereHas('processos', fn ($p) => $p->whereIn('processos.id', $permitidos));
    }

    public function advogado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advogado_id');
    }

    public function processos(): HasMany
    {
        return $this->hasMany(Processo::class);
    }

    public function atendimentos(): HasMany
    {
        return $this->hasMany(Atendimento::class)->orderByDesc('data');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(Documento::class);
    }

    public function telefonePrincipal(): ?string
    {
        foreach ($this->contatos ?? [] as $contato) {
            if (in_array($contato['tipo'] ?? '', ['telefone', 'whatsapp'], true)) {
                return $contato['valor'] ?? null;
            }
        }

        return null;
    }
}
