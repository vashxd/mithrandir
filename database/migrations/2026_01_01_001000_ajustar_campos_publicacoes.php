<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes vindos do teste de contrato contra a API real do DJEN:
 *  - `meio` guarda a descricao legivel ("Diario de Justica Eletronico Nacional"),
 *    que nao cabia em 20 caracteres;
 *  - `advogados_intimados` separa os advogados das partes, para a triagem
 *    conferir homonimo sem abrir o payload bruto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publicacoes', function (Blueprint $table) {
            $table->string('meio', 120)->nullable()->change();
            $table->json('advogados_intimados')->nullable()->after('destinatarios');
        });
    }

    public function down(): void
    {
        Schema::table('publicacoes', function (Blueprint $table) {
            $table->dropColumn('advogados_intimados');
            $table->string('meio', 20)->nullable()->change();
        });
    }
};
