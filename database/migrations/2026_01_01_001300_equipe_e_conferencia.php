<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipe (secao 16, decisao 4 - era v3).
 *
 * O tenant continua sendo o advogado titular: `advogado_id` nao muda de
 * significado em lugar nenhum. O que entra e a nocao de que OUTRA pessoa pode
 * trabalhar dentro do espaco de um titular, com acesso limitado a casos
 * especificos.
 *
 * A regra que justifica a conferencia: quem responde pela perda do prazo na
 * OAB e o titular, nao o estagiario. Por isso "cumpri" vindo de colaborador
 * nao fecha o prazo - marca para conferencia e o prazo segue aberto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membros', function (Blueprint $table) {
            $table->id();
            // Dono do espaco de trabalho.
            $table->foreignId('titular_id')->constrained('users')->cascadeOnDelete();
            // Nulo enquanto o convite nao foi aceito.
            $table->foreignId('usuario_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->string('email');
            $table->string('nome')->nullable();
            // titular | advogado | estagiario
            $table->string('papel', 20)->default('estagiario');
            $table->boolean('ativo')->default(true);

            // Acesso a carteira inteira, ou so aos casos liberados um a um.
            $table->boolean('acesso_total')->default(false);

            $table->string('token_convite', 64)->nullable()->unique();
            $table->timestamp('convidado_em')->nullable();
            $table->timestamp('aceito_em')->nullable();
            $table->timestamp('ultimo_acesso_em')->nullable();
            $table->timestamps();

            $table->unique(['titular_id', 'email']);
            $table->index(['usuario_id', 'ativo']);
        });

        Schema::create('acessos_processo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membro_id')->constrained('membros')->cascadeOnDelete();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['membro_id', 'processo_id']);
        });

        Schema::table('prazos', function (Blueprint $table) {
            // Quem vai fazer. Nulo = ninguem assumiu ainda.
            $table->foreignId('responsavel_id')->nullable()->after('advogado_id')
                ->constrained('users')->nullOnDelete();

            // Conferencia: ortogonal ao status, de proposito. O prazo continua
            // "em andamento" (e vermelho) ate o titular confirmar.
            $table->boolean('aguardando_conferencia')->default(false)->after('status');
            $table->foreignId('conferencia_solicitada_por')->nullable()->after('aguardando_conferencia')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('conferencia_solicitada_em')->nullable()->after('conferencia_solicitada_por');
            $table->text('conferencia_observacao')->nullable()->after('conferencia_solicitada_em');

            $table->index(['advogado_id', 'aguardando_conferencia']);
            $table->index(['responsavel_id', 'status']);
        });

        // Quem agiu, separado de quem e o dono do dado.
        Schema::table('auditoria', function (Blueprint $table) {
            $table->foreignId('autor_id')->nullable()->after('advogado_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('auditoria', function (Blueprint $table) {
            $table->dropForeign(['autor_id']);
            $table->dropColumn('autor_id');
        });

        Schema::table('prazos', function (Blueprint $table) {
            $table->dropForeign(['responsavel_id']);
            $table->dropForeign(['conferencia_solicitada_por']);
            $table->dropColumn([
                'responsavel_id', 'aguardando_conferencia',
                'conferencia_solicitada_por', 'conferencia_solicitada_em', 'conferencia_observacao',
            ]);
        });

        Schema::dropIfExists('acessos_processo');
        Schema::dropIfExists('membros');
    }
};
