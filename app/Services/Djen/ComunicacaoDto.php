<?php

namespace App\Services\Djen;

use Carbon\CarbonImmutable;

/**
 * Normaliza um item da API do DJEN. Os nomes de campo variam entre tribunais e
 * entre versoes da API, entao toda leitura passa por aqui - e este e o unico
 * ponto a mexer quando o contrato mudar (risco mapeado na secao 13).
 *
 * Formato real observado em 03/09/2026 (ver DjenContratoTest):
 *   id                       716124018     <- identificador estavel da comunicacao
 *   hash                     "XDVMlJ3..."  <- token de deduplicacao do proprio DJEN
 *   numeroComunicacao        1             <- SEQUENCIA, nunca identificador
 *   numero_processo          "0151532..."  <- 20 digitos, sem mascara
 *   numeroprocessocommascara "0151532-35.2026.8.04.1000"
 *   data_disponibilizacao    "2026-09-03"
 *   meio / meiocompleto      "D" / "Diario de Justica Eletronico Nacional"
 */
final class ComunicacaoDto
{
    /**
     * @param  array<int, array<string, mixed>>  $destinatarios  partes do processo
     * @param  array<int, array<string, mixed>>  $advogados  advogados intimados
     * @param  array<string, mixed>  $bruto
     */
    public function __construct(
        public readonly ?string $numeroComunicacao,
        public readonly ?string $hashDjen,
        public readonly ?string $numeroProcesso,
        public readonly ?string $tribunal,
        public readonly ?string $orgao,
        public readonly ?string $tipoComunicacao,
        public readonly ?string $meio,
        public readonly string $teor,
        public readonly CarbonImmutable $dataDisponibilizacao,
        public readonly array $destinatarios,
        public readonly array $advogados,
        public readonly array $bruto,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     */
    public static function deArray(array $item): self
    {
        $data = self::primeiro($item, [
            'data_disponibilizacao', 'dataDisponibilizacao', 'datadisponibilizacao',
        ]);

        return new self(
            // `id` primeiro: e o identificador estavel da comunicacao no DJEN.
            // `numeroComunicacao` NAO entra aqui - e sequencia, nao identidade.
            numeroComunicacao: self::texto($item, ['id', 'numero_comunicacao', 'numeroComunicacao']),
            hashDjen: self::texto($item, ['hash']),
            numeroProcesso: self::normalizarProcesso(self::texto($item, [
                'numero_processo', 'numeroProcesso', 'numeroprocessocommascara', 'numero_processo_mascara',
            ])),
            tribunal: self::texto($item, ['siglaTribunal', 'sigla_tribunal', 'tribunal']),
            orgao: self::texto($item, ['nomeOrgao', 'nome_orgao', 'orgao', 'nomeorgao']),
            tipoComunicacao: self::texto($item, ['tipoComunicacao', 'tipo_comunicacao', 'tipoDocumento']),
            // A descricao legivel vale mais que a sigla de uma letra.
            meio: self::texto($item, ['meiocompleto', 'meioCompleto', 'meio']),
            teor: (string) (self::texto($item, ['texto', 'teor', 'conteudo', 'textoComunicacao']) ?? ''),
            dataDisponibilizacao: self::interpretarData($data),
            destinatarios: self::lista($item, ['destinatarios']),
            advogados: self::lista($item, ['destinatarioadvogados', 'destinatarioAdvogados', 'advogados']),
            bruto: $item,
        );
    }

    /**
     * RF-1.4: deduplicacao por hash.
     *
     * A identidade vem, nesta ordem, do hash do proprio DJEN, do id da
     * comunicacao, e so entao de uma composicao do conteudo. Nunca do
     * `numeroComunicacao`: ele se repete entre processos (1, 1, 10, 835...),
     * e usa-lo como chave faria publicacoes distintas colidirem - a segunda
     * sumiria calada, que e exatamente como se perde um prazo.
     */
    public function hash(int $advogadoId): string
    {
        $identidade = $this->hashDjen
            ?: $this->numeroComunicacao
            ?: implode('|', [
                $this->numeroProcesso,
                $this->tribunal,
                $this->dataDisponibilizacao->toDateString(),
                mb_substr($this->teor, 0, 500),
            ]);

        return hash('sha256', $advogadoId.'|'.$identidade);
    }

    /**
     * Nomes e OABs dos advogados intimados, para a triagem conferir homonimo.
     *
     * @return array<int, string>
     */
    public function advogadosIntimados(): array
    {
        $nomes = [];

        foreach ($this->advogados as $entrada) {
            $advogado = $entrada['advogado'] ?? $entrada;
            $nome = $advogado['nome'] ?? null;

            if (! is_string($nome)) {
                continue;
            }

            $oab = $advogado['numero_oab'] ?? null;
            $uf = $advogado['uf_oab'] ?? null;

            $nomes[] = $oab ? sprintf('%s (OAB %s/%s)', $nome, $oab, $uf ?? '') : $nome;
        }

        return $nomes;
    }

    private static function interpretarData(mixed $valor): CarbonImmutable
    {
        $tz = config('mithrandir.timezone');

        if (! is_string($valor) || $valor === '') {
            return CarbonImmutable::now($tz)->startOfDay();
        }

        // A API devolve "2026-09-03" em `data_disponibilizacao` e "03/09/2026"
        // em `datadisponibilizacao`. Parse ingenuo leria 03/09 como 9 de marco.
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $valor, $partes)) {
            $valor = "{$partes[3]}-{$partes[2]}-{$partes[1]}";
        }

        return CarbonImmutable::parse($valor, $tz)->startOfDay();
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $chaves
     */
    private static function texto(array $item, array $chaves): ?string
    {
        $valor = self::primeiro($item, $chaves);

        if ($valor === null || $valor === '') {
            return null;
        }

        return is_scalar($valor) ? trim((string) $valor) : null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $chaves
     */
    private static function primeiro(array $item, array $chaves): mixed
    {
        foreach ($chaves as $chave) {
            if (array_key_exists($chave, $item) && $item[$chave] !== null && $item[$chave] !== '') {
                return $item[$chave];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $chaves
     * @return array<int, array<string, mixed>>
     */
    private static function lista(array $item, array $chaves): array
    {
        $lista = self::primeiro($item, $chaves);

        if (! is_array($lista)) {
            return [];
        }

        return array_values(array_filter($lista, 'is_array'));
    }

    private static function normalizarProcesso(?string $numero): ?string
    {
        if ($numero === null) {
            return null;
        }

        $limpo = preg_replace('/\D/', '', $numero) ?? '';

        return strlen($limpo) === 20 ? $limpo : ($limpo ?: null);
    }
}
