<?php

namespace App\Support;

/**
 * Numeracao unica do CNJ (Resolucao 65/2008): NNNNNNN-DD.AAAA.J.TR.OOOO
 *
 * O digito verificador e o resto de modulo 97 (base 10, ISO 7064) calculado
 * sobre o numero com o DD zerado e reposicionado no fim.
 */
final class NumeroCnj
{
    public static function limpar(string $numero): string
    {
        return preg_replace('/\D/', '', $numero) ?? '';
    }

    public static function formatar(string $numero): string
    {
        $n = self::limpar($numero);

        if (strlen($n) !== 20) {
            return $numero;
        }

        return sprintf(
            '%s-%s.%s.%s.%s.%s',
            substr($n, 0, 7),
            substr($n, 7, 2),
            substr($n, 9, 4),
            substr($n, 13, 1),
            substr($n, 14, 2),
            substr($n, 16, 4),
        );
    }

    /**
     * RF-4.1: validacao do digito verificador.
     */
    public static function valido(string $numero): bool
    {
        $n = self::limpar($numero);

        if (strlen($n) !== 20) {
            return false;
        }

        $sequencial = substr($n, 0, 7);
        $dv = substr($n, 7, 2);
        $resto = substr($n, 9, 11); // AAAA J TR OOOO

        // ISO 7064 MOD 97-10: com o DV no fim, o resto tem de ser 1.
        return self::modulo97($sequencial.$resto.$dv) === 1;
    }

    public static function dvEsperado(string $semDv): string
    {
        $base = $semDv.'00';

        return str_pad((string) (98 - self::modulo97($base)), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Modulo 97 em blocos, para nao estourar o inteiro nativo.
     */
    private static function modulo97(string $numero): int
    {
        $resto = 0;

        foreach (str_split($numero, 7) as $bloco) {
            $resto = (int) (($resto.$bloco) % 97);
        }

        return $resto;
    }

    /**
     * Extrai as partes do numero, quando valido em formato.
     *
     * @return array{sequencial: string, dv: string, ano: string, segmento: string, tribunal: string, origem: string}|null
     */
    public static function partes(string $numero): ?array
    {
        $n = self::limpar($numero);

        if (strlen($n) !== 20) {
            return null;
        }

        return [
            'sequencial' => substr($n, 0, 7),
            'dv' => substr($n, 7, 2),
            'ano' => substr($n, 9, 4),
            'segmento' => substr($n, 13, 1),
            'tribunal' => substr($n, 14, 2),
            'origem' => substr($n, 16, 4),
        ];
    }

    /**
     * Segmento do Judiciario (posicao J da numeracao).
     */
    public static function segmento(string $numero): ?string
    {
        $partes = self::partes($numero);

        return $partes === null ? null : match ($partes['segmento']) {
            '1' => 'Supremo Tribunal Federal',
            '2' => 'Conselho Nacional de Justica',
            '3' => 'Superior Tribunal de Justica',
            '4' => 'Justica Federal',
            '5' => 'Justica do Trabalho',
            '6' => 'Justica Eleitoral',
            '7' => 'Justica Militar da Uniao',
            '8' => 'Justica Estadual',
            '9' => 'Justica Militar Estadual',
            default => null,
        };
    }
}
