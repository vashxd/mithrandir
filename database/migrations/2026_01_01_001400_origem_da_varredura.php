<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quem executou a varredura: o servidor ou o navegador do advogado.
 *
 * O DJEN responde 403 a IP estrangeiro, entao a busca pode sair do cliente.
 * Sem esta coluna, o historico do radar nao distingue "o servidor varreu" de
 * "alguem abriu o app e varreu" - e essa e exatamente a diferenca entre
 * cobertura garantida e cobertura que depende de uso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->string('origem', 10)->default('servidor')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->dropColumn('origem');
        });
    }
};
