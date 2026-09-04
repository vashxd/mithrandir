<?php

namespace App\Support;

/**
 * CPF e CNPJ.
 *
 * O documento e guardado sempre limpo (so digitos) e exibido formatado, para
 * que "123.456.789-00" e "12345678900" sejam a mesma coisa na busca local e no
 * cadastro - digitado de um jeito, encontrado do outro.
 *
 * Aviso importante: o DJEN NAO permite consultar por CPF/CNPJ. Testado com
 * numeroDocumento, cpfCnpj, documentoParte, cpf, documento, numeroCpfCnpj e
 * numeroDocumentoParte: todos sao ignorados e a API devolve o diario inteiro.
 * O documento serve para identificar o cliente e conferir a triagem, nunca
 * como termo de busca.
 */
final class Documento
{
    public static function limpar(?string $documento): string
    {
        return preg_replace('/\D/', '', (string) $documento) ?? '';
    }

    public static function ehCpf(?string $documento): bool
    {
        return strlen(self::limpar($documento)) === 11;
    }

    public static function ehCnpj(?string $documento): bool
    {
        return strlen(self::limpar($documento)) === 14;
    }

    public static function tipo(?string $documento): ?string
    {
        return match (true) {
            self::ehCpf($documento) => 'cpf',
            self::ehCnpj($documento) => 'cnpj',
            default => null,
        };
    }

    /**
     * 123.456.789-00 ou 12.345.678/0001-90. Devolve o valor original quando
     * nao reconhece o formato - nao inventa mascara em cima de dado incompleto.
     */
    public static function formatar(?string $documento): ?string
    {
        $n = self::limpar($documento);

        if ($n === '') {
            return null;
        }

        if (strlen($n) === 11) {
            return sprintf(
                '%s.%s.%s-%s',
                substr($n, 0, 3), substr($n, 3, 3), substr($n, 6, 3), substr($n, 9, 2)
            );
        }

        if (strlen($n) === 14) {
            return sprintf(
                '%s.%s.%s/%s-%s',
                substr($n, 0, 2), substr($n, 2, 3), substr($n, 5, 3), substr($n, 8, 4), substr($n, 12, 2)
            );
        }

        return $documento;
    }

    /**
     * Todas as grafias do mesmo documento, para busca local casar com o que a
     * pessoa digitou - com ponto, sem ponto, com barra.
     *
     * @return array<int, string>
     */
    public static function variacoes(?string $documento): array
    {
        $limpo = self::limpar($documento);

        if ($limpo === '') {
            return [];
        }

        return array_values(array_unique(array_filter([
            $limpo,
            self::formatar($limpo),
        ])));
    }

    public static function valido(?string $documento): bool
    {
        return self::ehCpf($documento)
            ? self::cpfValido(self::limpar($documento))
            : (self::ehCnpj($documento) && self::cnpjValido(self::limpar($documento)));
    }

    private static function cpfValido(string $cpf): bool
    {
        // Sequencia repetida passa no calculo do digito, mas nao e CPF.
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        foreach ([9, 10] as $posicao) {
            $soma = 0;

            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $cpf[$i] * (($posicao + 1) - $i);
            }

            $resto = ($soma * 10) % 11;
            $digito = $resto === 10 ? 0 : $resto;

            if ($digito !== (int) $cpf[$posicao]) {
                return false;
            }
        }

        return true;
    }

    private static function cnpjValido(string $cnpj): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        foreach ([12, 13] as $posicao) {
            $soma = 0;
            $peso = $posicao - 7;

            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $cnpj[$i] * $peso;
                $peso = $peso === 2 ? 9 : $peso - 1;
            }

            $resto = $soma % 11;
            $digito = $resto < 2 ? 0 : 11 - $resto;

            if ($digito !== (int) $cnpj[$posicao]) {
                return false;
            }
        }

        return true;
    }
}
