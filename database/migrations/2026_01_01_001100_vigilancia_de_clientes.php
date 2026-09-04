<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vigilancia por nome de cliente (parte do processo).
 *
 * A busca e sempre por NOME: o DJEN nao filtra por CPF/CNPJ - testado com sete
 * variacoes de parametro (numeroDocumento, cpfCnpj, documentoParte...) e todas
 * sao ignoradas, devolvendo o diario inteiro. E a mesma restricao de LGPD que a
 * especificacao registra para o DataJud na secao 7.2. O documento fica guardado
 * normalizado para conferencia humana na triagem, nao para consulta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oab_watches', function (Blueprint $table) {
            $table->foreignId('cliente_id')->nullable()->after('advogado_id')
                ->constrained('clientes')->cascadeOnDelete();
        });

        Schema::table('publicacoes', function (Blueprint $table) {
            // Desnormalizado de proposito: a publicacao precisa dizer de quem
            // ela veio mesmo que o termo de vigilancia seja removido depois.
            $table->foreignId('cliente_id')->nullable()->after('oab_watch_id')
                ->constrained('clientes')->nullOnDelete();
            $table->string('origem_vigilancia', 20)->default('advogado')->after('cliente_id');

            $table->index(['advogado_id', 'origem_vigilancia']);
        });
    }

    public function down(): void
    {
        Schema::table('publicacoes', function (Blueprint $table) {
            $table->dropForeign(['cliente_id']);
            $table->dropColumn(['cliente_id', 'origem_vigilancia']);
        });

        Schema::table('oab_watches', function (Blueprint $table) {
            $table->dropForeign(['cliente_id']);
            $table->dropColumn('cliente_id');
        });
    }
};
