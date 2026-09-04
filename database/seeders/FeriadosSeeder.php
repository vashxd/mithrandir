<?php

namespace Database\Seeders;

use App\Models\Feriado;
use Illuminate\Database\Seeder;

/**
 * Carga inicial do calendario (secao 8.4).
 *
 * ATENCAO: esta tabela e o passivo do projeto. Nao existe fonte publica
 * unificada de feriado forense; o que esta aqui foi transcrito das portarias e
 * PRECISA de revisao trimestral. Feriado que falta aqui vira prazo errado.
 *
 * Escopo da carga: nacional + AM (TJAM, TRF1/JEF-AM, TRT11).
 */
class FeriadosSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->nacionais() as $feriado) {
            $this->gravar($feriado + ['abrangencia' => 'nacional', 'fonte' => 'Lei 662/1949, Lei 6.802/1980, Lei 10.607/2002']);
        }

        foreach ($this->estaduaisAm() as $feriado) {
            $this->gravar($feriado + ['abrangencia' => 'estadual', 'uf' => 'AM', 'fonte' => 'Legislacao estadual AM']);
        }

        foreach ($this->municipaisManaus() as $feriado) {
            $this->gravar($feriado + [
                'abrangencia' => 'municipal',
                'uf' => 'AM',
                'municipio' => 'Manaus',
                'fonte' => 'Legislacao municipal de Manaus',
            ]);
        }

        foreach ($this->forensesNacionais() as $feriado) {
            $this->gravar($feriado + [
                'abrangencia' => 'nacional',
                'suspensao_expediente' => true,
                'fonte' => 'Lei 5.010/1966 art. 62; CPC art. 220',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function gravar(array $dados): void
    {
        Feriado::updateOrCreate(
            [
                'data' => $dados['data'],
                'abrangencia' => $dados['abrangencia'],
                'uf' => $dados['uf'] ?? null,
                'municipio' => $dados['municipio'] ?? null,
                'tribunal_sigla' => $dados['tribunal_sigla'] ?? null,
            ],
            $dados
        );
    }

    /**
     * Feriados nacionais fixos + moveis calculados a partir da Pascoa.
     *
     * @return array<int, array{data: string, descricao: string}>
     */
    private function nacionais(): array
    {
        $lista = [];

        foreach ([2026, 2027, 2028] as $ano) {
            foreach ([
                "{$ano}-01-01" => 'Confraternizacao Universal',
                "{$ano}-04-21" => 'Tiradentes',
                "{$ano}-05-01" => 'Dia do Trabalho',
                "{$ano}-09-07" => 'Independencia',
                "{$ano}-10-12" => 'Nossa Senhora Aparecida',
                "{$ano}-11-02" => 'Finados',
                "{$ano}-11-15" => 'Proclamacao da Republica',
                "{$ano}-11-20" => 'Dia da Consciencia Negra',
                "{$ano}-12-25" => 'Natal',
            ] as $data => $descricao) {
                $lista[] = ['data' => $data, 'descricao' => $descricao];
            }

            foreach ($this->moveis($ano) as $data => $descricao) {
                $lista[] = ['data' => $data, 'descricao' => $descricao];
            }
        }

        return $lista;
    }

    /**
     * Carnaval, Sexta-feira Santa e Corpus Christi derivam da Pascoa.
     * Carnaval e Corpus Christi nao sao feriado nacional por lei, mas ha
     * suspensao de expediente forense em praticamente todo tribunal.
     *
     * @return array<string, string>
     */
    private function moveis(int $ano): array
    {
        // easter_date() exige a extensao calendar; o fallback usa Gauss.
        $pascoa = function_exists('easter_date')
            ? (new \DateTimeImmutable('@'.easter_date($ano)))->setTimezone(new \DateTimeZone('UTC'))
            : $this->pascoaGauss($ano);

        return [
            $pascoa->modify('-48 days')->format('Y-m-d') => 'Carnaval (segunda)',
            $pascoa->modify('-47 days')->format('Y-m-d') => 'Carnaval (terca)',
            $pascoa->modify('-46 days')->format('Y-m-d') => 'Quarta-feira de Cinzas (expediente ate 12h)',
            $pascoa->modify('-2 days')->format('Y-m-d') => 'Sexta-feira Santa',
            $pascoa->modify('+60 days')->format('Y-m-d') => 'Corpus Christi',
        ];
    }

    private function pascoaGauss(int $ano): \DateTimeImmutable
    {
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $ano, $mes, $dia), new \DateTimeZone('UTC'));
    }

    /**
     * @return array<int, array{data: string, descricao: string}>
     */
    private function estaduaisAm(): array
    {
        $lista = [];

        foreach ([2026, 2027, 2028] as $ano) {
            $lista[] = ['data' => "{$ano}-09-05", 'descricao' => 'Elevacao do Amazonas a categoria de Provincia'];
        }

        return $lista;
    }

    /**
     * @return array<int, array{data: string, descricao: string}>
     */
    private function municipaisManaus(): array
    {
        $lista = [];

        foreach ([2026, 2027, 2028] as $ano) {
            $lista[] = ['data' => "{$ano}-10-24", 'descricao' => 'Aniversario de Manaus'];
            $lista[] = ['data' => "{$ano}-12-08", 'descricao' => 'Nossa Senhora da Conceicao (padroeira de Manaus)'];
        }

        return $lista;
    }

    /**
     * Feriados forenses e suspensao de expediente que valem em todo tribunal.
     * O recesso de 20/12 a 20/01 e tratado no proprio PrazoService (CPC art. 220),
     * mas os dias sem expediente entram aqui para prorrogar vencimento.
     *
     * @return array<int, array{data: string, descricao: string}>
     */
    private function forensesNacionais(): array
    {
        $lista = [];

        foreach ([2025, 2026, 2027, 2028] as $ano) {
            $lista[] = ['data' => "{$ano}-08-11", 'descricao' => 'Dia do Advogado / criacao dos cursos juridicos'];
            $lista[] = ['data' => "{$ano}-10-31", 'descricao' => 'Dia do Servidor Publico (suspensao de expediente)'];
            $lista[] = ['data' => "{$ano}-12-24", 'descricao' => 'Vespera de Natal (sem expediente)'];
            $lista[] = ['data' => "{$ano}-12-31", 'descricao' => 'Vespera de Ano Novo (sem expediente)'];
        }

        return $lista;
    }
}
