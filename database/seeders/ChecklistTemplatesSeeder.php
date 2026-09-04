<?php

namespace Database\Seeders;

use App\Models\ChecklistTemplate;
use Illuminate\Database\Seeder;

/**
 * RF-6.3: checklist de documentos por tipo de caso.
 * Areas da persona primaria: previdenciario, consumidor, familia.
 */
class ChecklistTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'nome' => 'BPC/LOAS',
                'slug' => 'bpc-loas',
                'area' => 'previdenciario',
                'itens' => [
                    ['item' => 'RG e CPF do requerente', 'tipo_documento' => 'rg', 'obrigatorio' => true],
                    ['item' => 'Comprovante de residencia', 'tipo_documento' => 'comprovante_residencia', 'obrigatorio' => true],
                    ['item' => 'Cadastro Unico (CadUnico) atualizado', 'tipo_documento' => 'outro', 'obrigatorio' => true],
                    ['item' => 'Laudo medico e exames', 'tipo_documento' => 'laudo', 'obrigatorio' => true],
                    ['item' => 'Indeferimento administrativo do INSS', 'tipo_documento' => 'outro', 'obrigatorio' => true],
                    ['item' => 'Documentos dos demais membros do grupo familiar', 'tipo_documento' => 'rg', 'obrigatorio' => true],
                    ['item' => 'Comprovantes de renda da familia', 'tipo_documento' => 'outro', 'obrigatorio' => false],
                    ['item' => 'Procuracao assinada', 'tipo_documento' => 'procuracao', 'obrigatorio' => true],
                    ['item' => 'Declaracao de hipossuficiencia', 'tipo_documento' => 'outro', 'obrigatorio' => true],
                ],
            ],
            [
                'nome' => 'Aposentadoria',
                'slug' => 'aposentadoria',
                'area' => 'previdenciario',
                'itens' => [
                    ['item' => 'RG e CPF', 'tipo_documento' => 'rg', 'obrigatorio' => true],
                    ['item' => 'CNIS completo', 'tipo_documento' => 'cnis', 'obrigatorio' => true],
                    ['item' => 'Carteira de trabalho (todas as paginas com registro)', 'tipo_documento' => 'outro', 'obrigatorio' => true],
                    ['item' => 'PPP / LTCAT (se atividade especial)', 'tipo_documento' => 'outro', 'obrigatorio' => false],
                    ['item' => 'Carnes de contribuicao', 'tipo_documento' => 'outro', 'obrigatorio' => false],
                    ['item' => 'Comunicacao de decisao do INSS', 'tipo_documento' => 'outro', 'obrigatorio' => true],
                    ['item' => 'Procuracao assinada', 'tipo_documento' => 'procuracao', 'obrigatorio' => true],
                    ['item' => 'Contrato de honorarios assinado', 'tipo_documento' => 'contrato', 'obrigatorio' => true],
                ],
            ],
            [
                'nome' => 'Auxilio-maternidade / salario-maternidade',
                'slug' => 'salario-maternidade',
                'area' => 'previdenciario',
                'itens' => [
                    ['item' => 'RG e CPF da requerente', 'tipo_documento' => 'rg', 'obrigatorio' => true],
                    ['item' => 'Certidao de nascimento da crianca', 'tipo_documento' => 'certidao', 'obrigatorio' => true],
                    ['item' => 'CNIS', 'tipo_documento' => 'cnis', 'obrigatorio' => true],
                    ['item' => 'Comprovacao de atividade rural ou vinculo', 'tipo_documento' => 'outro', 'obrigatorio' => true],
                    ['item' => 'Indeferimento administrativo', 'tipo_documento' => 'outro', 'obrigatorio' => true],
                    ['item' => 'Procuracao assinada', 'tipo_documento' => 'procuracao', 'obrigatorio' => true],
                ],
            ],
            [
                'nome' => 'Consumidor',
                'slug' => 'consumidor',
                'area' => 'consumidor',
                'itens' => [
                    ['item' => 'RG e CPF', 'tipo_documento' => 'rg', 'obrigatorio' => true],
                    ['item' => 'Comprovante de residencia', 'tipo_documento' => 'comprovante_residencia', 'obrigatorio' => true],
                    ['item' => 'Contrato ou nota fiscal', 'tipo_documento' => 'contrato', 'obrigatorio' => true],
                    ['item' => 'Comprovantes de pagamento', 'tipo_documento' => 'outro', 'obrigatorio' => true],
                    ['item' => 'Print de conversas / protocolo de atendimento', 'tipo_documento' => 'outro', 'obrigatorio' => false],
                    ['item' => 'Reclamacao no Procon / consumidor.gov', 'tipo_documento' => 'outro', 'obrigatorio' => false],
                    ['item' => 'Extrato de negativacao (SPC/Serasa)', 'tipo_documento' => 'outro', 'obrigatorio' => false],
                    ['item' => 'Procuracao assinada', 'tipo_documento' => 'procuracao', 'obrigatorio' => true],
                ],
            ],
            [
                'nome' => 'Familia (divorcio / alimentos)',
                'slug' => 'familia',
                'area' => 'familia',
                'itens' => [
                    ['item' => 'RG e CPF das partes', 'tipo_documento' => 'rg', 'obrigatorio' => true],
                    ['item' => 'Certidao de casamento ou nascimento', 'tipo_documento' => 'certidao', 'obrigatorio' => true],
                    ['item' => 'Certidao de nascimento dos filhos', 'tipo_documento' => 'certidao', 'obrigatorio' => false],
                    ['item' => 'Comprovante de renda do alimentante', 'tipo_documento' => 'outro', 'obrigatorio' => false],
                    ['item' => 'Comprovantes de despesas dos filhos', 'tipo_documento' => 'outro', 'obrigatorio' => false],
                    ['item' => 'Documentos de bens a partilhar', 'tipo_documento' => 'outro', 'obrigatorio' => false],
                    ['item' => 'Procuracao assinada', 'tipo_documento' => 'procuracao', 'obrigatorio' => true],
                ],
            ],
        ];

        foreach ($templates as $template) {
            ChecklistTemplate::updateOrCreate(['slug' => $template['slug']], $template);
        }
    }
}
