<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Auditoria extends Model
{
    protected $table = 'auditoria';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'antes' => 'array',
            'depois' => 'array',
            'em' => 'datetime',
        ];
    }

    public static function registrar(
        ?int $advogadoId,
        string $entidade,
        ?int $entidadeId,
        string $acao,
        ?array $antes = null,
        ?array $depois = null,
        ?int $autorId = null,
    ): self {
        return self::create([
            'advogado_id' => $advogadoId,
            // Quem agiu, que pode nao ser o dono do dado.
            'autor_id' => $autorId ?? auth()->id(),
            'entidade' => $entidade,
            'entidade_id' => $entidadeId,
            'acao' => $acao,
            'antes' => $antes,
            'depois' => $depois,
            'ip' => request()?->ip(),
            'em' => now(),
        ]);
    }
}
