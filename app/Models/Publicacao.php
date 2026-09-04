<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regra de ouro (secao 6, M1): publicacao nunca e apagada, so arquivada.
 * E prova de que o app viu (ou nao viu) o ato.
 */
class Publicacao extends Model
{
    use Concerns\PertenceAoAdvogado;

    protected $table = 'publicacoes';

    protected $guarded = ['id'];

    public const STATUS = ['nova', 'prazo', 'ciencia', 'descartada'];

    protected function casts(): array
    {
        return [
            'data_disponibilizacao' => 'date',
            'destinatarios' => 'array',
            'advogados_intimados' => 'array',
            'payload_bruto' => 'array',
            'triada_em' => 'datetime',
            'arquivada_em' => 'datetime',
        ];
    }

    public function advogado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advogado_id');
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function veioDeCliente(): bool
    {
        return $this->origem_vigilancia === 'cliente';
    }

    public function watch(): BelongsTo
    {
        return $this->belongsTo(OabWatch::class, 'oab_watch_id');
    }

    /**
     * Publicacao sem processo vinculado ainda nao foi triada: e do titular
     * decidir de quem ela e antes de virar trabalho de alguem.
     */
    protected function colunaDeRecorte(): ?string
    {
        return 'processo_id';
    }

    public function scopeNaoTriadas($query)
    {
        return $query->where('status_triagem', 'nova');
    }

    public function resumo(int $limite = 220): string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', $this->teorLegivel()));

        return mb_strlen($texto) > $limite ? mb_substr($texto, 0, $limite).'...' : $texto;
    }

    /**
     * O teor gravado nunca e alterado - ele e prova do que o tribunal publicou,
     * inclusive quando vem com HTML ou com acentuacao corrompida na origem.
     * O que se ajusta aqui e so a leitura: tags viram quebra de linha, entidades
     * viram texto. Nada e reescrito ou "consertado" por adivinhacao.
     */
    public function teorLegivel(): string
    {
        $texto = (string) $this->teor;

        if (! str_contains($texto, '<')) {
            return $texto;
        }

        $texto = preg_replace('#<br\s*/?>#i', '
', $texto) ?? $texto;
        $texto = preg_replace('#</(p|div|li|tr)>#i', '
', $texto) ?? $texto;
        $texto = strip_tags($texto);
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Sobra de tag costuma deixar linhas em branco em sequencia.
        return trim(preg_replace('/
{3,}/', '

', $texto) ?? $texto);
    }
}
