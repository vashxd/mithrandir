<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tabela `advogados` da especificacao (secao 9) e materializada sobre `users`,
 * para aproveitar o guard de autenticacao padrao. `advogado_id` == `users.id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('oab', 20)->nullable()->after('email');
            $table->string('uf', 2)->nullable()->after('oab');
            $table->string('nome_social')->nullable()->after('uf');
            $table->string('timezone', 64)->default('America/Sao_Paulo')->after('nome_social');
            $table->unsignedTinyInteger('buffer_padrao')->default(3)->after('timezone');
            $table->time('digest_horario')->default('08:00:00')->after('buffer_padrao');
            $table->timestamp('aceite_termo_em')->nullable()->after('digest_horario');
            $table->string('aceite_termo_versao', 20)->nullable()->after('aceite_termo_em');
            $table->decimal('percentual_imposto', 5, 2)->default(6.00)->after('aceite_termo_versao');
            $table->json('preferencias_notificacao')->nullable()->after('percentual_imposto');
            $table->timestamp('exclusao_solicitada_em')->nullable()->after('preferencias_notificacao');
            $table->timestamp('onboarding_concluido_em')->nullable()->after('exclusao_solicitada_em');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'oab', 'uf', 'nome_social', 'timezone', 'buffer_padrao', 'digest_horario',
                'aceite_termo_em', 'aceite_termo_versao', 'percentual_imposto',
                'preferencias_notificacao', 'exclusao_solicitada_em', 'onboarding_concluido_em',
            ]);
        });
    }
};
