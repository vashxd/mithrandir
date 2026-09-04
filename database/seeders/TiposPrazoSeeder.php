<?php

namespace Database\Seeders;

use App\Models\TipoPrazo;
use Illuminate\Database\Seeder;

/**
 * RF-2.10: catalogo de tipos de prazo com dias pre-preenchidos.
 * Reduz digitacao na triagem, que e onde a pressa mora.
 */
class TiposPrazoSeeder extends Seeder
{
    public function run(): void
    {
        $tipos = [
            ['Contestacao', 'contestacao', 15, true, 'CPC art. 335', 'civel'],
            ['Replica / impugnacao a contestacao', 'replica', 15, true, 'CPC art. 350', 'civel'],
            ['Apelacao', 'apelacao', 15, true, 'CPC art. 1.003, §5', 'civel'],
            ['Contrarrazoes de apelacao', 'contrarrazoes-apelacao', 15, true, 'CPC art. 1.010, §1', 'civel'],
            ['Embargos de declaracao', 'embargos-declaracao', 5, true, 'CPC art. 1.023', 'civel'],
            ['Agravo de instrumento', 'agravo-instrumento', 15, true, 'CPC art. 1.003, §5', 'civel'],
            ['Agravo interno', 'agravo-interno', 15, true, 'CPC art. 1.021, §2', 'civel'],
            ['Recurso especial / extraordinario', 'recurso-especial', 15, true, 'CPC art. 1.003, §5', 'civel'],
            ['Cumprimento de sentenca (pagamento)', 'cumprimento-sentenca', 15, true, 'CPC art. 523', 'civel'],
            ['Impugnacao ao cumprimento de sentenca', 'impugnacao-cumprimento', 15, true, 'CPC art. 525', 'civel'],
            ['Manifestacao sobre documento', 'manifestacao-documento', 15, true, 'CPC art. 437, §1', 'civel'],
            ['Manifestacao geral / intimacao simples', 'manifestacao', 5, true, 'CPC art. 218, §3', 'civel'],
            ['Especificacao de provas', 'especificacao-provas', 15, true, 'CPC art. 357', 'civel'],
            ['Emenda a inicial', 'emenda-inicial', 15, true, 'CPC art. 321', 'civel'],
            ['Recurso inominado (JEC)', 'recurso-inominado', 10, true, 'Lei 9.099/95 art. 42', 'juizado'],
            ['Contrarrazoes de recurso inominado', 'contrarrazoes-inominado', 10, true, 'Lei 9.099/95 art. 42, §2', 'juizado'],
            ['Embargos de declaracao (JEC)', 'embargos-jec', 5, true, 'Lei 9.099/95 art. 49', 'juizado'],
            ['Contestacao trabalhista', 'contestacao-trabalhista', 15, true, 'CLT art. 847 c/c CPC art. 219', 'trabalhista'],
            ['Recurso ordinario', 'recurso-ordinario', 8, true, 'CLT art. 895', 'trabalhista'],
            ['Contrarrazoes de recurso ordinario', 'contrarrazoes-ordinario', 8, true, 'CLT art. 900', 'trabalhista'],
            ['Recurso administrativo (INSS)', 'recurso-inss', 30, false, 'Decreto 3.048/99 art. 305', 'previdenciario'],
            ['Contrarrazoes de recurso (INSS)', 'contrarrazoes-inss', 30, false, 'Decreto 3.048/99', 'previdenciario'],
            ['Cumprimento de exigencia (INSS)', 'exigencia-inss', 30, false, 'IN INSS', 'previdenciario'],
            ['Apelacao criminal', 'apelacao-criminal', 5, false, 'CPP art. 593', 'penal'],
            ['Razoes de apelacao criminal', 'razoes-apelacao-criminal', 8, false, 'CPP art. 600', 'penal'],
            ['Resposta a acusacao', 'resposta-acusacao', 10, false, 'CPP art. 396', 'penal'],
            ['Alegacoes finais (memoriais)', 'alegacoes-finais', 5, false, 'CPP art. 403, §3', 'penal'],
        ];

        foreach ($tipos as [$nome, $slug, $dias, $uteis, $base, $area]) {
            TipoPrazo::updateOrCreate(['slug' => $slug], [
                'nome' => $nome,
                'dias' => $dias,
                'em_dias_uteis' => $uteis,
                'base_legal' => $base,
                'area' => $area,
                'ativo' => true,
            ]);
        }
    }
}
