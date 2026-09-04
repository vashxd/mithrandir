<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Multi-tenant desde a primeira migration (secao 16, decisao 2).
 * Todo escopo de leitura passa por aqui.
 */
trait PertenceAoAdvogado
{
    public function scopeDoAdvogado(Builder $query, int $advogadoId): Builder
    {
        return $query->where($query->getModel()->getTable().'.advogado_id', $advogadoId);
    }

    /**
     * Escopo do espaco de trabalho ativo, ja com o recorte por caso.
     *
     * Um colaborador convidado para tres casos so enxerga esses tres - em
     * prazos, documentos, agenda e financeiro. Registro sem processo vinculado
     * fica com o titular: e anotacao pessoal dele, nao trabalho de equipe.
     */
    public function scopeVisivel(Builder $query): Builder
    {
        $contexto = contexto();

        $query->doAdvogado($contexto->advogadoId());

        $permitidos = $contexto->processosVisiveis();

        if ($permitidos === null) {
            return $query;
        }

        return $this->aplicarRecorte($query, $permitidos);
    }

    /**
     * Como este modelo se liga a um processo, para efeito de recorte.
     *
     * Devolve o nome da coluna, ou null quando o modelo nao tem vinculo direto
     * e precisa sobrescrever `aplicarRecorte`.
     */
    protected function colunaDeRecorte(): ?string
    {
        return 'processo_id';
    }

    /**
     * @param  array<int, int>  $permitidos
     */
    protected function aplicarRecorte(Builder $query, array $permitidos): Builder
    {
        $coluna = $this->colunaDeRecorte();

        if ($coluna === null) {
            // Sem vinculo e sem regra propria: por seguranca, nao mostra nada.
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($query->getModel()->getTable().'.'.$coluna, $permitidos);
    }
}
