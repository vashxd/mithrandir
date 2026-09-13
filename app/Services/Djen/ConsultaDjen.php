<?php

namespace App\Services\Djen;

use App\Models\OabWatch;
use Carbon\CarbonImmutable;

/**
 * Traduz um termo de vigilancia nos parametros da API do DJEN.
 *
 * Existe separado do DjenClient porque a varredura tem dois executores: o
 * servidor (scheduler/fila) e o navegador do proprio advogado, para quando o
 * IP do servidor esta fora do Brasil e a API responde 403. Os dois PRECISAM
 * montar a mesma consulta - se divergirem, a varredura do cliente cobre uma
 * janela diferente da do servidor, a deduplicacao por hash engole a diferenca
 * e o buraco so aparece quando um prazo ja passou.
 */
final class ConsultaDjen
{
    public const CAMINHO = '/api/v1/comunicacao';

    /**
     * @return array<string, string>
     */
    public static function paraWatch(OabWatch $watch, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $datas = [
            'dataDisponibilizacaoInicio' => $inicio->toDateString(),
            'dataDisponibilizacaoFim' => $fim->toDateString(),
        ];

        return match ($watch->tipo) {
            // A OAB do proprio termo manda; a do cadastro e so o padrao.
            'oab' => [
                'numeroOab' => $watch->termo,
                'ufOab' => strtoupper((string) ($watch->uf ?? $watch->advogado?->uf)),
            ] + $datas,

            'cliente' => array_filter([
                'nomeParte' => $watch->termo,
                'ufOab' => $watch->uf ? strtoupper($watch->uf) : null,
            ]) + $datas,

            default => array_filter([
                'nomeAdvogado' => $watch->termo,
                'ufOab' => $watch->uf ? strtoupper($watch->uf) : null,
            ]) + $datas,
        };
    }
}
