<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Documento extends Model
{
    use Concerns\PertenceAoAdvogado;

    protected $table = 'documentos';

    protected $guarded = ['id'];

    public const TIPOS = [
        'rg' => 'RG',
        'cpf' => 'CPF',
        'comprovante_residencia' => 'Comprovante de residencia',
        'cnis' => 'CNIS',
        'laudo' => 'Laudo medico',
        'procuracao' => 'Procuracao',
        'contrato' => 'Contrato de honorarios',
        'certidao' => 'Certidao',
        'peticao' => 'Peticao',
        'decisao' => 'Decisao / sentenca',
        'outro' => 'Outro',
    ];

    /**
     * @param  array<int, int>  $permitidos
     */
    protected function aplicarRecorte(Builder $query, array $permitidos): Builder
    {
        return $query->where(function ($q) use ($permitidos) {
            $q->whereIn('processo_id', $permitidos)
                ->orWhereHas('cliente.processos', fn ($p) => $p->whereIn('processos.id', $permitidos));
        });
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function acessos(): HasMany
    {
        return $this->hasMany(DocumentoAcesso::class);
    }

    public function getTipoRotuloAttribute(): string
    {
        return self::TIPOS[$this->tipo] ?? 'Outro';
    }

    public function getTamanhoLegivelAttribute(): string
    {
        $bytes = (int) $this->tamanho;

        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
