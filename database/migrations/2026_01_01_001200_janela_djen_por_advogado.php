<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada advogado escolhe quantos dias para tras a varredura do DJEN cobre.
 *
 * Nulo = usa o padrao do sistema (DJEN_JANELA_DIAS). O piso continua sendo
 * ontem + hoje (RF-1.3), aplicado no IngestaoService independente do valor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('janela_djen_dias')->nullable()->after('buffer_padrao');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('janela_djen_dias');
        });
    }
};
